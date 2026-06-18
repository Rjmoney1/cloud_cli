<?php
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

function check_login() {
    // 1. Try tab-based session
    $tabData = get_tab_session();
    if ($tabData && isset($tabData['user_id']) && ($tabData['role'] ?? '') === 'user') {
        return;
    }
    
    // 2. Fallback to legacy session
    if (isset($_SESSION['student_user_id'])) {
        return;
    }
    
    header("Location: index.php");
    exit();
}

function check_admin() {
    // 1. Try tab-based session
    $tabData = get_tab_session();
    if ($tabData && isset($tabData['user_id']) && ($tabData['role'] ?? '') === 'admin') {
        return;
    }
    
    // 2. Fallback to legacy session
    if (isset($_SESSION['admin_user_id']) && $_SESSION['admin_role'] === 'admin') {
        return;
    }
    
    header("Location: index.php");
    exit();
}

/**
 * Resolve current user identity from tab session or legacy session.
 * Returns associative array with: user_id, username, role, lab_type
 */
function resolve_user_identity() {
    $tabData = get_tab_session();
    if ($tabData && isset($tabData['user_id'])) {
        return $tabData;
    }
    
    // Legacy fallback
    if (isset($_SESSION['student_user_id'])) {
        return [
            'user_id' => $_SESSION['student_user_id'],
            'username' => $_SESSION['student_username'] ?? '',
            'role' => 'user',
            'lab_type' => $_SESSION['student_lab_type'] ?? 'Ubuntu 22.04 LTS'
        ];
    }
    
    if (isset($_SESSION['admin_user_id'])) {
        return [
            'user_id' => $_SESSION['admin_user_id'],
            'username' => $_SESSION['admin_username'] ?? '',
            'role' => 'admin',
            'lab_type' => ''
        ];
    }
    
    return null;
}
?>
