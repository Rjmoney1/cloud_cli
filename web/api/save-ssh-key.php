<?php
header('Content-Type: application/json');
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth_check.php';

// Authenticate user
$identity = resolve_user_identity();
if (!$identity || $identity['role'] !== 'user') {
    echo json_encode(['success' => false, 'message' => 'Unauthorized.']);
    exit();
}

// 1. Enforce Rate Limiting
if (!check_rate_limit($pdo, 'save_ssh_key', 10, 60)) {
    echo json_encode(['success' => false, 'message' => 'Rate limit exceeded. Please wait a minute.']);
    exit();
}

// 2. Validate CSRF (Skip if Bearer Token authentication is used)
$authHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '';
if (empty($authHeader) && function_exists('getallheaders')) {
    $headers = getallheaders();
    $authHeader = $headers['Authorization'] ?? $headers['authorization'] ?? '';
}
$isTokenAuth = !empty($authHeader) && preg_match('/Bearer\s/i', $authHeader);

if (!$isTokenAuth && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrfToken = $_POST['csrf_token'] ?? $_GET['csrf_token'] ?? '';
    if (!verify_csrf_token($csrfToken)) {
        echo json_encode(['success' => false, 'message' => 'CSRF validation failed.']);
        exit();
    }
}

$userId = $identity['user_id'];
$username = $identity['username'];
$action = $_POST['action'] ?? '';

if ($action === 'reset') {
    try {
        $tempFile = tempnam(sys_get_temp_dir(), 'ssh');
        if (file_exists($tempFile)) {
            unlink($tempFile);
        }
        exec("ssh-keygen -t rsa -b 2048 -f " . escapeshellarg($tempFile) . " -N '' -q");
        if (!file_exists($tempFile)) {
            echo json_encode(['success' => false, 'message' => 'Failed to generate default SSH key pair on server.']);
            exit();
        }
        $privateKey = file_get_contents($tempFile);
        $publicKey = trim(file_get_contents($tempFile . '.pub'));
        
        unlink($tempFile);
        unlink($tempFile . '.pub');

        // Update DB
        $updateStmt = $pdo->prepare("UPDATE users SET ssh_private_key = ?, ssh_public_key = ? WHERE id = ?");
        $updateStmt->execute([$privateKey, $publicKey, $userId]);

        log_audit($pdo, 'SSH_KEY_RESET', "Generated and updated default SSH key pair.", $userId, $username);

        // Push to container if running
        $stmt = $pdo->prepare("SELECT container_id, container_status FROM users WHERE id = ?");
        $stmt->execute([$userId]);
        $user = $stmt->fetch();
        if ($user && $user['container_status'] === 'running' && !empty($user['container_id'])) {
            require_once __DIR__ . '/../includes/DockerClient.php';
            $docker = new DockerClient();
            $userSshDir = "/home/" . $username . "/.ssh";
            $cmd = [
                "bash",
                "-c",
                "mkdir -p $userSshDir && echo " . escapeshellarg($publicKey) . " > $userSshDir/authorized_keys && chmod 700 $userSshDir && chmod 600 $userSshDir/authorized_keys && chown -R " . $username . ":" . $username . " $userSshDir"
            ];
            $docker->execCommand($user['container_id'], $cmd);
        }

        echo json_encode(['success' => true, 'message' => 'Default SSH key pair generated and updated successfully.']);
        exit();
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => 'Server error occurred during key generation.']);
        exit();
    }
}

$publicKey = trim($_POST['ssh_public_key'] ?? '');

if (empty($publicKey)) {
    echo json_encode(['success' => false, 'message' => 'SSH Public Key cannot be empty.']);
    exit();
}

// Basic validation: must start with standard SSH key prefixes
$validPrefixes = ['ssh-rsa', 'ssh-dss', 'ecdsa-sha2-nistp256', 'ecdsa-sha2-nistp384', 'ecdsa-sha2-nistp521', 'ssh-ed25519'];
$isValid = false;
foreach ($validPrefixes as $prefix) {
    if (strpos($publicKey, $prefix) === 0) {
        $isValid = true;
        break;
    }
}

if (!$isValid) {
    echo json_encode(['success' => false, 'message' => 'Invalid SSH Public Key format. It should start with ssh-rsa, ssh-ed25519, etc.']);
    exit();
}

try {
    // 1. Fetch user container status
    $stmt = $pdo->prepare("SELECT container_id, container_status FROM users WHERE id = ?");
    $stmt->execute([$userId]);
    $user = $stmt->fetch();

    // 2. Update DB: set ssh_public_key to the user's key, and ssh_private_key to NULL (since it's custom)
    $updateStmt = $pdo->prepare("UPDATE users SET ssh_public_key = ?, ssh_private_key = NULL WHERE id = ?");
    $updateStmt->execute([$publicKey, $userId]);

    log_audit($pdo, 'SSH_KEY_UPLOAD', "Uploaded custom SSH public key.", $userId, $username);

    // 3. Push to container if running
    if ($user && $user['container_status'] === 'running' && !empty($user['container_id'])) {
        require_once __DIR__ . '/../includes/DockerClient.php';
        $docker = new DockerClient();
        $userSshDir = "/home/" . $username . "/.ssh";
        $cmd = [
            "bash",
            "-c",
            "mkdir -p $userSshDir && echo " . escapeshellarg($publicKey) . " > $userSshDir/authorized_keys && chmod 700 $userSshDir && chmod 600 $userSshDir/authorized_keys && chown -R " . $username . ":" . $username . " $userSshDir"
        ];
        $docker->execCommand($user['container_id'], $cmd);
    }

    echo json_encode(['success' => true, 'message' => 'SSH public key saved and updated inside the active container.']);
    exit();

} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => 'Server error occurred during key upload.']);
    exit();
    exit();
}
?>
