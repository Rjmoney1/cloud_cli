<?php
define('API_CONTEXT', true);
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/TOTP.php';

header('Content-Type: application/json');

// Check authentication
$userId = $_SESSION['student_user_id'] ?? $_SESSION['admin_user_id'] ?? null;
if (!$userId) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized.']);
    exit();
}

$action = $_GET['action'] ?? '';

// Check rate limit
if (!check_rate_limit($pdo, 'mfa_config', 10, 60)) {
    echo json_encode(['success' => false, 'message' => 'Too many requests. Please wait 60 seconds.']);
    exit();
}

// Check CSRF
$csrfToken = $_POST['csrf_token'] ?? $_GET['csrf_token'] ?? '';
if (!verify_csrf_token($csrfToken)) {
    echo json_encode(['success' => false, 'message' => 'CSRF validation failed.']);
    exit();
}

try {
    $stmt = $pdo->prepare("SELECT username, mfa_secret, mfa_enabled FROM users WHERE id = ?");
    $stmt->execute([$userId]);
    $user = $stmt->fetch();

    if (!$user) {
        echo json_encode(['success' => false, 'message' => 'User not found.']);
        exit();
    }

    if ($action === 'enable') {
        $code = trim($_POST['mfa_code'] ?? '');
        if (empty($code)) {
            echo json_encode(['success' => false, 'message' => 'Verification code is required.']);
            exit();
        }

        if (TOTP::verify($user['mfa_secret'], $code)) {
            $update = $pdo->prepare("UPDATE users SET mfa_enabled = 1 WHERE id = ?");
            $update->execute([$userId]);
            
            log_audit($pdo, 'MFA_ENABLED', "TOTP Multi-Factor Authentication enabled.", $userId, $user['username']);
            echo json_encode(['success' => true, 'message' => 'MFA enabled successfully.']);
        } else {
            echo json_encode(['success' => false, 'message' => 'Invalid verification code.']);
        }
        exit();
    }

    elseif ($action === 'disable') {
        $code = trim($_POST['mfa_code'] ?? '');
        if (empty($code)) {
            echo json_encode(['success' => false, 'message' => 'Verification code is required to disable MFA.']);
            exit();
        }

        if (TOTP::verify($user['mfa_secret'], $code)) {
            // Reset and generate a new secret for next time
            $newSecret = TOTP::generateSecret();
            $update = $pdo->prepare("UPDATE users SET mfa_enabled = 0, mfa_secret = ? WHERE id = ?");
            $update->execute([$newSecret, $userId]);
            
            log_audit($pdo, 'MFA_DISABLED', "TOTP Multi-Factor Authentication disabled.", $userId, $user['username']);
            echo json_encode(['success' => true, 'message' => 'MFA disabled successfully.']);
        } else {
            echo json_encode(['success' => false, 'message' => 'Invalid verification code.']);
        }
        exit();
    }
    
    else {
        echo json_encode(['success' => false, 'message' => 'Invalid action.']);
        exit();
    }

} catch (Exception $e) {
    throw $e;
}
?>
