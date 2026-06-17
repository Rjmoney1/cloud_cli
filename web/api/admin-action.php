<?php
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/DockerClient.php';
require_once __DIR__ . '/../includes/auth_check.php';

// Check if user is logged in as admin
check_admin();

$action = $_GET['action'] ?? '';
$docker = new DockerClient();

// Helper to return JSON response
function jsonResponse($success, $message, $extra = []) {
    header('Content-Type: application/json');
    echo json_encode(array_merge(['success' => $success, 'message' => $message], $extra));
    exit();
}

if ($action === 'install_software') {
    $targetUserId = intval($_POST['user_id'] ?? 0);
    $package = trim($_POST['package'] ?? '');

    if ($targetUserId <= 0 || empty($package)) {
        jsonResponse(false, "Invalid parameters provided.");
    }

    // Sanitize package name (only alphanumeric, dashes, and underscores)
    if (!preg_match('/^[a-zA-Z0-9\-_ ]+$/', $package)) {
        jsonResponse(false, "Invalid characters in package name.");
    }

    try {
        $stmt = $pdo->prepare("SELECT container_id, container_status FROM users WHERE id = ?");
        $stmt->execute([$targetUserId]);
        $user = $stmt->fetch();

        if (!$user || empty($user['container_id']) || $user['container_status'] !== 'running') {
            jsonResponse(false, "User container is not running.");
        }

        $containerId = $user['container_id'];

        // Run apt-get update && apt-get install -y <package>
        // We use sudo because apt-get requires root, and although we might run as developer, developer has passwordless sudo
        $cmd = ["sudo", "apt-get", "update"];
        $docker->execCommand($containerId, $cmd);

        $cmdInstall = ["sudo", "apt-get", "install", "-y", $package];
        $output = $docker->execCommand($containerId, $cmdInstall);

        jsonResponse(true, "Installation attempt completed.", ['output' => $output]);

    } catch (Exception $e) {
        jsonResponse(false, "System error: " . $e->getMessage());
    }
}

else if ($action === 'run_script') {
    $targetUserId = intval($_POST['user_id'] ?? 0);
    $script = $_POST['script'] ?? '';

    if ($targetUserId <= 0 || empty($script)) {
        jsonResponse(false, "Invalid parameters provided.");
    }

    try {
        $stmt = $pdo->prepare("SELECT container_id, container_status FROM users WHERE id = ?");
        $stmt->execute([$targetUserId]);
        $user = $stmt->fetch();

        if (!$user || empty($user['container_id']) || $user['container_status'] !== 'running') {
            jsonResponse(false, "User container is not running.");
        }

        $containerId = $user['container_id'];

        // Standard base64 execution to avoid quoting/special character breaking issues
        $base64Script = base64_encode($script);
        
        // Command to write, execute, and cleanup the script
        $cmd = [
            "bash", 
            "-c", 
            "echo '$base64Script' | base64 -d > /tmp/admin_run.sh && chmod +x /tmp/admin_run.sh && sudo /tmp/admin_run.sh; RET=$?; rm -f /tmp/admin_run.sh; exit $RET"
        ];
        
        $output = $docker->execCommand($containerId, $cmd);
        jsonResponse(true, "Script execution completed.", ['output' => $output]);

    } catch (Exception $e) {
        jsonResponse(false, "System error: " . $e->getMessage());
    }
}

else if ($action === 'upload_iso') {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        header("Location: ../admin.php");
        exit();
    }

    if (!isset($_FILES['iso_file']) || $_FILES['iso_file']['error'] !== UPLOAD_ERR_OK) {
        $_SESSION['error'] = "File upload failed or no file selected.";
        header("Location: ../admin.php");
        exit();
    }

    $file = $_FILES['iso_file'];
    $filename = basename($file['name']);
    $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));

    if ($ext !== 'iso') {
        $_SESSION['error'] = "Invalid file type. Only ISO files are allowed.";
        header("Location: ../admin.php");
        exit();
    }

    $uploadDir = '/var/www/html/uploads/isos/';
    if (!is_dir($uploadDir)) {
        mkdir($uploadDir, 0777, true);
    }

    // Sanitize filename to avoid folder traversal or shell issues
    $sanitizedFilename = preg_replace('/[^a-zA-Z0-9\-_.]/', '_', $filename);
    $destPath = $uploadDir . $sanitizedFilename;

    if (move_uploaded_file($file['tmp_name'], $destPath)) {
        try {
            $stmt = $pdo->prepare("INSERT INTO isos (filename, filepath) VALUES (?, ?)");
            $stmt->execute([$sanitizedFilename, $destPath]);
            $_SESSION['success'] = "ISO file '$sanitizedFilename' uploaded and registered successfully.";
        } catch (PDOException $e) {
            $_SESSION['error'] = "Database registration error: " . $e->getMessage();
        }
    } else {
        $_SESSION['error'] = "Failed to move uploaded ISO file to destination folder.";
    }

    header("Location: ../admin.php");
    exit();
}

else if ($action === 'mount_iso') {
    $targetUserId = intval($_POST['user_id'] ?? 0);
    $isoId = intval($_POST['iso_id'] ?? 0);
    $mountPath = trim($_POST['mount_path'] ?? '/mnt/iso');

    if ($targetUserId <= 0 || $isoId <= 0 || empty($mountPath)) {
        jsonResponse(false, "Invalid parameters.");
    }

    try {
        // Fetch user container info
        $stmt = $pdo->prepare("SELECT container_id, container_status FROM users WHERE id = ?");
        $stmt->execute([$targetUserId]);
        $user = $stmt->fetch();

        if (!$user || empty($user['container_id']) || $user['container_status'] !== 'running') {
            jsonResponse(false, "User container is not running.");
        }

        // Fetch ISO file info
        $stmt = $pdo->prepare("SELECT filename FROM isos WHERE id = ?");
        $stmt->execute([$isoId]);
        $iso = $stmt->fetch();

        if (!$iso) {
            jsonResponse(false, "ISO file not found.");
        }

        $containerId = $user['container_id'];
        $isoFilename = $iso['filename'];
        $isoContainerPath = "/media/isos/" . $isoFilename;

        // Perform mount inside container
        // We run a command to create mount folder, and mount -o loop the ISO
        $cmdCreateDir = ["sudo", "mkdir", "-p", $mountPath];
        $docker->execCommand($containerId, $cmdCreateDir);

        $cmdMount = ["sudo", "mount", "-o", "loop,ro", $isoContainerPath, $mountPath];
        $output = $docker->execCommand($containerId, $cmdMount);

        // Record mount in DB
        $stmt = $pdo->prepare("INSERT INTO mounts (user_id, iso_id, mount_path) VALUES (?, ?, ?)");
        $stmt->execute([$targetUserId, $isoId, $mountPath]);

        jsonResponse(true, "ISO file mounted successfully inside the container at " . $mountPath, ['output' => $output]);

    } catch (Exception $e) {
        jsonResponse(false, "System error: " . $e->getMessage());
    }
}

else if ($action === 'unmount_iso') {
    $mountId = intval($_POST['mount_id'] ?? 0);

    if ($mountId <= 0) {
        jsonResponse(false, "Invalid mount ID.");
    }

    try {
        // Fetch mount details
        $stmt = $pdo->prepare("SELECT m.*, u.container_id, u.container_status FROM mounts m JOIN users u ON m.user_id = u.id WHERE m.id = ?");
        $stmt->execute([$mountId]);
        $mount = $stmt->fetch();

        if (!$mount) {
            jsonResponse(false, "Mount record not found.");
        }

        $containerId = $mount['container_id'];
        $mountPath = $mount['mount_path'];

        if (!empty($containerId) && $mount['container_status'] === 'running') {
            // Run unmount inside container
            $cmdUnmount = ["sudo", "umount", "-f", $mountPath];
            $docker->execCommand($containerId, $cmdUnmount);
        }

        // Delete mount record
        $stmt = $pdo->prepare("DELETE FROM mounts WHERE id = ?");
        $stmt->execute([$mountId]);

        jsonResponse(true, "ISO unmounted and record deleted successfully.");

    } catch (Exception $e) {
        jsonResponse(false, "System error: " . $e->getMessage());
    }
}

else if ($action === 'update_limits') {
    $targetUserId = intval($_POST['user_id'] ?? 0);
    $cpuLimit = floatval($_POST['cpu_limit'] ?? 1.0);
    $memoryLimit = intval($_POST['memory_limit'] ?? 1024);
    $gpuLimit = intval($_POST['gpu_limit'] ?? 0);
    $labType = trim($_POST['lab_type'] ?? '');

    if ($targetUserId <= 0 || $cpuLimit < 0 || $memoryLimit < 0 || $gpuLimit < -1 || empty($labType)) {
        jsonResponse(false, "Invalid parameters.");
    }

    try {
        // 1. Fetch user container info
        $stmt = $pdo->prepare("SELECT container_id, container_status FROM users WHERE id = ?");
        $stmt->execute([$targetUserId]);
        $user = $stmt->fetch();

        if (!$user) {
            jsonResponse(false, "User not found.");
        }

        // 2. Update limits and lab type in DB
        $stmt = $pdo->prepare("UPDATE users SET cpu_limit = ?, memory_limit = ?, gpu_limit = ?, lab_type = ? WHERE id = ?");
        $stmt->execute([$cpuLimit, $memoryLimit, $gpuLimit, $labType, $targetUserId]);

        // 3. If container exists, remove it so it gets recreated with new limits/image on next start
        if (!empty($user['container_id'])) {
            // Stop container first if running
            if ($user['container_status'] === 'running') {
                $docker->stopContainer($user['container_id']);
            }
            $docker->removeContainer($user['container_id']);
            
            // Reset container ID and status in DB
            $resetStmt = $pdo->prepare("UPDATE users SET container_id = NULL, container_status = 'stopped' WHERE id = ?");
            $resetStmt->execute([$targetUserId]);
        }

        jsonResponse(true, "Resource limits and workspace environment updated successfully. The container has been reset to apply changes on next start.");

    } catch (Exception $e) {
        jsonResponse(false, "System error: " . $e->getMessage());
    }
}

else if ($action === 'add_service') {
    $name = trim($_POST['name'] ?? '');
    $imageName = trim($_POST['image_name'] ?? '');
    $description = trim($_POST['description'] ?? '');

    if (empty($name) || empty($imageName)) {
        jsonResponse(false, "Service Name and Docker Image Name are required.");
    }

    try {
        // Check if already exists
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM services WHERE name = ?");
        $stmt->execute([$name]);
        if ($stmt->fetchColumn() > 0) {
            jsonResponse(false, "A service with this name already exists.");
        }

        // Trigger Docker pull first
        $docker = new DockerClient();
        $pulled = $docker->pullImage($imageName);

        if (!$pulled) {
            jsonResponse(false, "Failed to pull image '$imageName' from Docker Hub. Please check image name.");
        }

        // Insert into DB
        $stmt = $pdo->prepare("INSERT INTO services (name, image_name, description) VALUES (?, ?, ?)");
        $stmt->execute([$name, $imageName, $description]);

        jsonResponse(true, "Service '$name' registered successfully. Image '$imageName' has been pulled from Docker Hub.");

    } catch (Exception $e) {
        jsonResponse(false, "System error: " . $e->getMessage());
    }
}

else if ($action === 'delete_service') {
    $serviceId = intval($_POST['service_id'] ?? 0);

    if ($serviceId <= 0) {
        jsonResponse(false, "Invalid service ID.");
    }

    try {
        $stmt = $pdo->prepare("SELECT name FROM services WHERE id = ?");
        $stmt->execute([$serviceId]);
        $serviceName = $stmt->fetchColumn();

        if (!$serviceName) {
            jsonResponse(false, "Service not found.");
        }

        // Delete from DB
        $stmt = $pdo->prepare("DELETE FROM services WHERE id = ?");
        $stmt->execute([$serviceId]);

        jsonResponse(true, "Service '$serviceName' deleted successfully.");

    } catch (Exception $e) {
        jsonResponse(false, "System error: " . $e->getMessage());
    }
}

else {
    jsonResponse(false, "Invalid administration action.");
}
?>
