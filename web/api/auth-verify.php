<?php
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

$targetWorkspace = $_GET['workspace'] ?? '';

// 1. Check if user is logged in
if (!isset($_SESSION['admin_user_id']) && !isset($_SESSION['student_user_id'])) {
    http_response_code(401); // Unauthorized
    exit("Unauthorized session.");
}

// 2. Allow if user is admin
if (isset($_SESSION['admin_role']) && $_SESSION['admin_role'] === 'admin') {
    http_response_code(200); // OK
    exit();
}

// 3. Allow if user is accessing their own workspace
if (isset($_SESSION['student_username']) && $_SESSION['student_username'] === $targetWorkspace) {
    http_response_code(200); // OK
    exit();
}

// 4. Otherwise, block access
http_response_code(403); // Forbidden
exit("Access denied: You do not own this workspace.");
?>
