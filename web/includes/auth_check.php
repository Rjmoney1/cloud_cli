<?php
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

function check_login() {
    if (!isset($_SESSION['student_user_id'])) {
        header("Location: index.php");
        exit();
    }
}

function check_admin() {
    if (!isset($_SESSION['admin_user_id']) || $_SESSION['admin_role'] !== 'admin') {
        header("Location: index.php");
        exit();
    }
}
?>
