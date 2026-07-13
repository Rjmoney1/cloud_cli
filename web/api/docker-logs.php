<?php
header('Content-Type: text/event-stream');
header('Cache-Control: no-cache');
header('Connection: keep-alive');
header('X-Accel-Buffering: no'); // Tell Nginx not to buffer this stream

if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/DockerClient.php';
require_once __DIR__ . '/../includes/auth_check.php';

// Authenticate as Admin
$identity = resolve_user_identity();
if (!$identity || $identity['role'] !== 'admin') {
    echo "data: " . json_encode(['error' => 'Unauthorized']) . "\n\n";
    ob_flush(); flush();
    exit();
}

session_write_close();

$targetUserId = intval($_GET['user_id'] ?? 0);
if ($targetUserId <= 0) {
    echo "data: " . json_encode(['error' => 'Invalid target user ID.']) . "\n\n";
    ob_flush(); flush();
    exit();
}

try {
    $stmt = $pdo->prepare("SELECT container_id, container_status FROM users WHERE id = ?");
    $stmt->execute([$targetUserId]);
    $user = $stmt->fetch();

    if (!$user || empty($user['container_id'])) {
        echo "data: " . json_encode(['error' => 'Container not created.']) . "\n\n";
        ob_flush(); flush();
        exit();
    }

    if ($user['container_status'] !== 'running') {
        echo "data: " . json_encode(['error' => 'Container is stopped.']) . "\n\n";
        ob_flush(); flush();
        exit();
    }

    $containerId = $user['container_id'];
    $docker = new DockerClient();

    // Stream logs
    // The callback will output SSE data and flush it
    $docker->streamContainerLogs($containerId, function($logChunk) {
        if (connection_aborted()) {
            return;
        }
        echo "data: " . json_encode(['log' => $logChunk]) . "\n\n";
        ob_flush();
        flush();
    }, 50);

} catch (Exception $e) {
    echo "data: " . json_encode(['error' => 'Server error occurred while streaming logs.']) . "\n\n";
    ob_flush(); flush();
}
?>
