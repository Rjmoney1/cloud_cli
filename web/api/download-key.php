<?php
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth_check.php';

check_login();

$userId = $_SESSION['student_user_id'];
$username = $_SESSION['student_username'];

try {
    $stmt = $pdo->prepare("SELECT ssh_private_key FROM users WHERE id = ?");
    $stmt->execute([$userId]);
    $privateKey = $stmt->fetchColumn();

    if (empty($privateKey)) {
        http_response_code(404);
        exit("Private key not found.");
    }

    // Force download headers for PEM files
    header('Content-Type: application/x-pem-file');
    header('Content-Disposition: attachment; filename="cloudlab-' . $username . '.pem"');
    header('Content-Length: ' . strlen($privateKey));
    header('Cache-Control: private, max-age=0, must-revalidate');
    header('Pragma: public');
    
    echo $privateKey;
    exit();

} catch (Exception $e) {
    http_response_code(500);
    exit("Server error occurred.");
}
?>
