<?php
header('Content-Type: application/json');
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/DockerClient.php';
require_once __DIR__ . '/../includes/auth_check.php';

// Check if user is logged in (either student or admin)
$studentUserId = $_SESSION['student_user_id'] ?? null;
$adminUserId = $_SESSION['admin_user_id'] ?? null;
$isAdmin = isset($_SESSION['admin_role']) && $_SESSION['admin_role'] === 'admin';

if (!$studentUserId && !$adminUserId) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized access.']);
    exit();
}

// 1. Validate CSRF Token
$csrfToken = $_POST['csrf_token'] ?? $_GET['csrf_token'] ?? '';
if (!verify_csrf_token($csrfToken)) {
    echo json_encode(['success' => false, 'message' => 'CSRF validation failed.']);
    exit();
}

// 2. Enforce Rate Limiting
if (!check_rate_limit($pdo, 'container_control', 15, 60)) {
    echo json_encode(['success' => false, 'message' => 'Rate limit exceeded. Please wait a minute.']);
    exit();
}

$action = $_GET['action'] ?? '';
$targetUserId = isset($_GET['user_id']) ? intval($_GET['user_id']) : $studentUserId;

// If trying to manage another user, must be an admin
if ($targetUserId !== $studentUserId && !$isAdmin) {
    echo json_encode(['success' => false, 'message' => 'Forbidden. Admin privileges required.']);
    exit();
}

$docker = new DockerClient();

try {
    // Get user details
    $stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
    $stmt->execute([$targetUserId]);
    $user = $stmt->fetch();

    if (!$user) {
        echo json_encode(['success' => false, 'message' => 'User not found.']);
        exit();
    }

    $username = $user['username'];
    $labType = $user['lab_type'];
    $containerId = $user['container_id'];
    $containerName = "lab-" . $username;
    $volumeName = "lab-home-" . $username;
    $basePath = "/workspace/" . $username . "/";

    // Fetch image dynamically from the database services table
    $serviceStmt = $pdo->prepare("SELECT image_name FROM services WHERE name = ?");
    $serviceStmt->execute([$labType]);
    $image = $serviceStmt->fetchColumn();

    if (!$image) {
        $image = 'lab-ubuntu'; // Default fallback
    }

    if ($action === 'start') {
        $started = false;
        
        // 1. Check if container ID exists in DB
        $containerExists = false;
        if (!empty($containerId)) {
            $inspect = $docker->inspectContainer($containerId);
            if ($inspect !== null) {
                $containerExists = true;
            }
        }

        // 2. If container doesn't exist, create it
        if (!$containerExists) {
            // Check if container with that name already exists in Docker (orphan container from DB reset)
            $containers = $docker->listContainers(true);
            foreach ($containers as $c) {
                if (in_array("/" . $containerName, $c['Names'])) {
                    // Remove the orphan container
                    $docker->removeContainer($c['Id']);
                    break;
                }
            }

            // Create persistent home volume
            $docker->createVolume($volumeName);

            $sshPort = 20000 + intval($targetUserId);
            // Create container
            $containerId = $docker->createContainer(
                $containerName, 
                $image, 
                $basePath, 
                $volumeName, 
                $username, 
                $user['password'],
                floatval($user['cpu_limit'] ?? 1.0),
                intval($user['memory_limit'] ?? 1024),
                intval($user['gpu_limit'] ?? 0),
                $sshPort,
                $user['ssh_public_key'] ?? ''
            );
            if (!$containerId) {
                echo json_encode(['success' => false, 'message' => 'Failed to create container.']);
                exit();
            }

            // Update container ID in DB
            $updateStmt = $pdo->prepare("UPDATE users SET container_id = ? WHERE id = ?");
            $updateStmt->execute([$containerId, $targetUserId]);
        }

        // 3. Start container
        $started = $docker->startContainer($containerId);

        if ($started) {
            // Update container status in DB
            $updateStmt = $pdo->prepare("UPDATE users SET container_status = 'running' WHERE id = ?");
            $updateStmt->execute([$targetUserId]);

            // Push SSH public key to container immediately on start
            if (!empty($user['ssh_public_key'])) {
                $userSshDir = "/home/" . $username . "/.ssh";
                $cmd = [
                    "bash",
                    "-c",
                    "mkdir -p $userSshDir && echo " . escapeshellarg($user['ssh_public_key']) . " > $userSshDir/authorized_keys && chmod 700 $userSshDir && chmod 600 $userSshDir/authorized_keys && chown -R " . $username . ":" . $username . " $userSshDir"
                ];
                $docker->execCommand($containerId, $cmd);
            }

            log_audit($pdo, 'CONTAINER_START', "Container started for user: $username (ID: $targetUserId)", $targetUserId, $username);

            echo json_encode([
                'success' => true, 
                'message' => 'Container started successfully.',
                'status' => 'running',
                'container_id' => $containerId
            ]);
        } else {
            echo json_encode(['success' => false, 'message' => 'Failed to start container.']);
        }
        exit();
    }

    else if ($action === 'stop') {
        if (empty($containerId)) {
            echo json_encode(['success' => false, 'message' => 'No active container for this user.']);
            exit();
        }

        $stopped = $docker->stopContainer($containerId);
        
        // Update database (even if container is already stopped, to align states)
        $updateStmt = $pdo->prepare("UPDATE users SET container_status = 'stopped' WHERE id = ?");
        $updateStmt->execute([$targetUserId]);

        log_audit($pdo, 'CONTAINER_STOP', "Container stopped for user: $username (ID: $targetUserId)", $targetUserId, $username);

        echo json_encode([
            'success' => true,
            'message' => 'Container stopped successfully.',
            'status' => 'stopped'
        ]);
        exit();
    }

    else if ($action === 'restart') {
        if (empty($containerId)) {
            echo json_encode(['success' => false, 'message' => 'No container created to restart.']);
            exit();
        }

        $restarted = $docker->restartContainer($containerId);

        if ($restarted) {
            $updateStmt = $pdo->prepare("UPDATE users SET container_status = 'running' WHERE id = ?");
            $updateStmt->execute([$targetUserId]);

            // Push SSH public key to container immediately on restart
            if (!empty($user['ssh_public_key'])) {
                $userSshDir = "/home/" . $username . "/.ssh";
                $cmd = [
                    "bash",
                    "-c",
                    "mkdir -p $userSshDir && echo " . escapeshellarg($user['ssh_public_key']) . " > $userSshDir/authorized_keys && chmod 700 $userSshDir && chmod 600 $userSshDir/authorized_keys && chown -R " . $username . ":" . $username . " $userSshDir"
                ];
                $docker->execCommand($containerId, $cmd);
            }

            log_audit($pdo, 'CONTAINER_RESTART', "Container restarted for user: $username (ID: $targetUserId)", $targetUserId, $username);

            echo json_encode([
                'success' => true,
                'message' => 'Container restarted successfully.',
                'status' => 'running'
            ]);
        } else {
            echo json_encode(['success' => false, 'message' => 'Failed to restart container.']);
        }
        exit();
    }

    else {
        echo json_encode(['success' => false, 'message' => 'Invalid container action.']);
        exit();
    }

} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => 'System error: ' . $e->getMessage()]);
    exit();
}
?>
