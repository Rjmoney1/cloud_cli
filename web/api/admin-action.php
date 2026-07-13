<?php
define('API_CONTEXT', true);
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/DockerClient.php';

// 1. Check if user is logged in as admin
$identity = resolve_user_identity();
if (!$identity || $identity['role'] !== 'admin') {
    if (($_GET['action'] ?? '') === 'upload_iso') {
        $_SESSION['error'] = "Unauthorized access.";
        header("Location: ../admin.php");
    } else {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'message' => 'Unauthorized access.']);
    }
    exit();
}

$action = $_GET['action'] ?? '';
$docker = new DockerClient();

// Helper to return JSON response
function jsonResponse($success, $message, $extra = []) {
    header('Content-Type: application/json');
    echo json_encode(array_merge(['success' => $success, 'message' => $message], $extra));
    exit();
}

// 2. Validate CSRF Token
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrfToken = $_POST['csrf_token'] ?? $_GET['csrf_token'] ?? '';
    if (!verify_csrf_token($csrfToken)) {
        log_audit($pdo, 'CSRF_FAILURE', "CSRF token validation failed on admin action: $action");
        if ($action === 'upload_iso') {
            $_SESSION['error'] = "Invalid session token. Please try again.";
            header("Location: ../admin.php");
        } else {
            jsonResponse(false, "Invalid session token. Please try again.");
        }
        exit();
    }
}

// 3. Enforce Rate Limiting
if (!check_rate_limit($pdo, 'admin_actions', 30, 60)) {
    if ($action === 'upload_iso') {
        $_SESSION['error'] = "Rate limit exceeded. Please wait a minute.";
        header("Location: ../admin.php");
    } else {
        jsonResponse(false, "Rate limit exceeded. Please wait a minute.");
    }
    exit();
}

if ($action === 'install_software') {
    $targetUserId = intval($_POST['user_id'] ?? 0);
    $package = trim($_POST['package'] ?? '');

    if ($targetUserId <= 0 || empty($package)) {
        jsonResponse(false, "Invalid parameters provided.");
    }

    // Sanitize package name (using core allowlist validator)
    if (!validate_package_name($package)) {
        jsonResponse(false, "Invalid characters in package name. Only alphanumeric, spaces, dashes, and underscores allowed.");
    }

    try {
        $stmt = $pdo->prepare("SELECT username, container_id, container_status FROM users WHERE id = ?");
        $stmt->execute([$targetUserId]);
        $user = $stmt->fetch();

        if (!$user || empty($user['container_id']) || $user['container_status'] !== 'running') {
            jsonResponse(false, "User container is not running.");
        }

        $containerId = $user['container_id'];
        $username = $user['username'];

        // Audit script execution start
        log_audit($pdo, 'REMOTE_INSTALL_START', "Installing package '$package' in container lab-$username", $targetUserId, $username);

        $cmd = ["sudo", "apt-get", "update"];
        $docker->execCommand($containerId, $cmd);

        $cmdInstall = ["sudo", "apt-get", "install", "-y", $package];
        $output = $docker->execCommand($containerId, $cmdInstall);

        log_audit($pdo, 'REMOTE_INSTALL_SUCCESS', "Package '$package' installation attempt completed in container lab-$username", $targetUserId, $username);
        jsonResponse(true, "Installation attempt completed.", ['output' => $output]);

    } catch (Exception $e) {
        throw $e;
    }
}

else if ($action === 'run_script') {
    $targetUserId = intval($_POST['user_id'] ?? 0);
    $script = $_POST['script'] ?? '';

    if ($targetUserId <= 0 || empty($script)) {
        jsonResponse(false, "Invalid parameters provided.");
    }

    try {
        $stmt = $pdo->prepare("SELECT username, container_id, container_status FROM users WHERE id = ?");
        $stmt->execute([$targetUserId]);
        $user = $stmt->fetch();

        if (!$user || empty($user['container_id']) || $user['container_status'] !== 'running') {
            jsonResponse(false, "User container is not running.");
        }

        $containerId = $user['container_id'];
        $username = $user['username'];

        log_audit($pdo, 'REMOTE_SCRIPT_EXEC_START', "Executing remote bash script in container lab-$username", $targetUserId, $username);

        // Standard base64 execution to avoid quoting/special character breaking issues
        $base64Script = base64_encode($script);
        
        // Command to write, execute, and cleanup the script
        $cmd = [
            "bash", 
            "-c", 
            "echo '$base64Script' | base64 -d > /tmp/admin_run.sh && chmod +x /tmp/admin_run.sh && sudo /tmp/admin_run.sh; RET=$?; rm -f /tmp/admin_run.sh; exit $RET"
        ];
        
        $output = $docker->execCommand($containerId, $cmd);
        
        log_audit($pdo, 'REMOTE_SCRIPT_EXEC_SUCCESS', "Remote bash script execution completed in container lab-$username", $targetUserId, $username);
        jsonResponse(true, "Script execution completed.", ['output' => $output]);

    } catch (Exception $e) {
        throw $e;
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

    // 1. Validate Extension
    if ($ext !== 'iso') {
        $_SESSION['error'] = "Invalid file type. Only ISO files are allowed.";
        header("Location: ../admin.php");
        exit();
    }

    // 2. Validate MIME type
    $allowedMimes = ['application/x-cd-image', 'application/octet-stream', 'application/x-iso9660-image'];
    $mimeType = mime_content_type($file['tmp_name']);
    if (!in_array($mimeType, $allowedMimes)) {
        $_SESSION['error'] = "Invalid MIME type. Uploaded file does not appear to be an ISO image.";
        header("Location: ../admin.php");
        exit();
    }

    // 3. Validate Size limit (max 2GB)
    if ($file['size'] > 2 * 1024 * 1024 * 1024) {
        $_SESSION['error'] = "File exceeds maximum upload limit of 2GB.";
        header("Location: ../admin.php");
        exit();
    }

    // 4. Verify ISO Signature Magic Bytes (CD001 at offsets 32769, 34817, or 36865)
    $handle = fopen($file['tmp_name'], 'r');
    $hasSignature = false;
    if ($handle) {
        $offsets = [32769, 34817, 36865];
        foreach ($offsets as $offset) {
            if (fseek($handle, $offset) === 0) {
                $sig = fread($handle, 5);
                if ($sig === 'CD001') {
                    $hasSignature = true;
                    break;
                }
            }
        }
        fclose($handle);
    }

    if (!$hasSignature) {
        $_SESSION['error'] = "Invalid file signature. File headers do not match ISO-9660 standard.";
        log_audit($pdo, 'MALWARE_UPLOAD_BLOCKED', "Uploaded file failed ISO signature check: $filename");
        header("Location: ../admin.php");
        exit();
    }

    $uploadDir = '/var/www/html/uploads/isos/';
    if (!is_dir($uploadDir)) {
        mkdir($uploadDir, 0775, true);
    }

    // 5. Build .htaccess file to disable script execution in uploads directory
    $htaccessFile = $uploadDir . '.htaccess';
    if (!file_exists($htaccessFile)) {
        $htaccessContent = "Options -Indexes\n" .
                           "RemoveHandler .php .phtml .php3 .php4 .php5 .php7 .php8 .phps .pl .py .cgi .sh .asp .aspx .shtml\n" .
                           "ForceType application/octet-stream\n" .
                           "Header set Content-Disposition attachment\n";
        file_put_contents($htaccessFile, $htaccessContent);
    }

    // 6. Save under secure randomized hash filename to prevent path traversal or shell execution
    $sanitizedFilename = preg_replace('/[^a-zA-Z0-9\-_.]/', '_', $filename);
    $secureFilename = 'iso_' . bin2hex(random_bytes(16)) . '.iso';
    $destPath = $uploadDir . $secureFilename;

    if (move_uploaded_file($file['tmp_name'], $destPath)) {
        try {
            $stmt = $pdo->prepare("INSERT INTO isos (filename, filepath) VALUES (?, ?)");
            $stmt->execute([$sanitizedFilename, $destPath]);
            
            log_audit($pdo, 'ISO_UPLOAD_SUCCESS', "ISO file uploaded successfully: $sanitizedFilename (stored as $secureFilename)");
            $_SESSION['success'] = "ISO file '$sanitizedFilename' uploaded and registered successfully.";
        } catch (PDOException $e) {
            // Delete file if DB insert fails
            if (file_exists($destPath)) {
                unlink($destPath);
            }
            throw $e;
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

    // Sanitize and validate mount path
    if (!validate_mount_path($mountPath)) {
        jsonResponse(false, "Invalid mount path format.");
    }

    try {
        // Fetch user container info
        $stmt = $pdo->prepare("SELECT username, container_id, container_status FROM users WHERE id = ?");
        $stmt->execute([$targetUserId]);
        $user = $stmt->fetch();

        if (!$user || empty($user['container_id']) || $user['container_status'] !== 'running') {
            jsonResponse(false, "User container is not running.");
        }

        // Fetch ISO file info
        $stmt = $pdo->prepare("SELECT filename, filepath FROM isos WHERE id = ?");
        $stmt->execute([$isoId]);
        $iso = $stmt->fetch();

        if (!$iso) {
            jsonResponse(false, "ISO file not found.");
        }

        $containerId = $user['container_id'];
        $username = $user['username'];
        $isoFilename = basename($iso['filepath']); // Use secure filename from disk
        $isoContainerPath = "/media/isos/" . $isoFilename;

        // Perform mount inside container
        $cmdCreateDir = ["sudo", "mkdir", "-p", $mountPath];
        $docker->execCommand($containerId, $cmdCreateDir);

        $cmdMount = ["sudo", "mount", "-o", "loop,ro", $isoContainerPath, $mountPath];
        $output = $docker->execCommand($containerId, $cmdMount);

        // Record mount in DB
        $stmt = $pdo->prepare("INSERT INTO mounts (user_id, iso_id, mount_path) VALUES (?, ?, ?)");
        $stmt->execute([$targetUserId, $isoId, $mountPath]);

        log_audit($pdo, 'ISO_MOUNT_SUCCESS', "Mounted ISO {$iso['filename']} inside container lab-$username at $mountPath", $targetUserId, $username);
        jsonResponse(true, "ISO file mounted successfully inside the container at " . $mountPath, ['output' => $output]);

    } catch (Exception $e) {
        throw $e;
    }
}

else if ($action === 'unmount_iso') {
    $mountId = intval($_POST['mount_id'] ?? 0);

    if ($mountId <= 0) {
        jsonResponse(false, "Invalid mount ID.");
    }

    try {
        // Fetch mount details
        $stmt = $pdo->prepare("SELECT m.*, u.username, u.container_id, u.container_status, i.filename FROM mounts m JOIN users u ON m.user_id = u.id JOIN isos i ON m.iso_id = i.id WHERE m.id = ?");
        $stmt->execute([$mountId]);
        $mount = $stmt->fetch();

        if (!$mount) {
            jsonResponse(false, "Mount record not found.");
        }

        $containerId = $mount['container_id'];
        $mountPath = $mount['mount_path'];
        $username = $mount['username'];
        $targetUserId = $mount['user_id'];

        if (!empty($containerId) && $mount['container_status'] === 'running') {
            // Run unmount inside container
            $cmdUnmount = ["sudo", "umount", "-f", $mountPath];
            $docker->execCommand($containerId, $cmdUnmount);
        }

        // Delete mount record
        $stmt = $pdo->prepare("DELETE FROM mounts WHERE id = ?");
        $stmt->execute([$mountId]);

        log_audit($pdo, 'ISO_UNMOUNT_SUCCESS', "Unmounted ISO {$mount['filename']} inside container lab-$username from $mountPath", $targetUserId, $username);
        jsonResponse(true, "ISO unmounted and record deleted successfully.");

    } catch (Exception $e) {
        throw $e;
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

    // 1. Enforce safety caps on resources to prevent container Denial of Service (DoS)
    if ($cpuLimit > 4.0 || $memoryLimit > 4096 || $gpuLimit > 2) {
        jsonResponse(false, "Resource limits exceed safety caps (Safety limit: 4.0 CPUs, 4096MB RAM, 2 GPUs).");
    }

    try {
        // Fetch user container info
        $stmt = $pdo->prepare("SELECT username, container_id, container_status FROM users WHERE id = ?");
        $stmt->execute([$targetUserId]);
        $user = $stmt->fetch();

        if (!$user) {
            jsonResponse(false, "User not found.");
        }

        $username = $user['username'];

        // 2. Update limits and lab type in DB
        $stmt = $pdo->prepare("UPDATE users SET cpu_limit = ?, memory_limit = ?, gpu_limit = ?, lab_type = ? WHERE id = ?");
        $stmt->execute([$cpuLimit, $memoryLimit, $gpuLimit, $labType, $targetUserId]);

        // 3. If container exists, remove it so it gets recreated with new limits/image on next start
        if (!empty($user['container_id'])) {
            if ($user['container_status'] === 'running') {
                $docker->stopContainer($user['container_id']);
            }
            $docker->removeContainer($user['container_id']);
            
            $resetStmt = $pdo->prepare("UPDATE users SET container_id = NULL, container_status = 'stopped' WHERE id = ?");
            $resetStmt->execute([$targetUserId]);
        }

        log_audit($pdo, 'RESOURCE_LIMITS_UPDATE', "Limits updated. CPU: $cpuLimit, RAM: $memoryLimit, GPU: $gpuLimit, Lab: $labType", $targetUserId, $username);
        jsonResponse(true, "Resource limits and workspace environment updated successfully. The container has been reset to apply changes on next start.");

    } catch (Exception $e) {
        throw $e;
    }
}

else if ($action === 'add_service') {
    $name = trim($_POST['name'] ?? '');
    $imageName = trim($_POST['image_name'] ?? '');
    $description = trim($_POST['description'] ?? '');

    if (empty($name) || empty($imageName)) {
        jsonResponse(false, "Service Name and Docker Image Name are required.");
    }

    // Sanitize inputs
    if (!validate_package_name($name) || !validate_package_name(str_replace(':', '-', $imageName))) {
        jsonResponse(false, "Invalid characters in service or image name.");
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

        log_audit($pdo, 'SERVICE_REGISTERED', "Custom service registered: $name (Image: $imageName)");
        jsonResponse(true, "Service '$name' registered successfully. Image '$imageName' has been pulled from Docker Hub.");

    } catch (Exception $e) {
        throw $e;
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

        log_audit($pdo, 'SERVICE_DELETED', "Custom service deleted: $serviceName");
        jsonResponse(true, "Service '$serviceName' deleted successfully.");

    } catch (Exception $e) {
        throw $e;
    }
}

else {
    jsonResponse(false, "Invalid administration action.");
}
?>
