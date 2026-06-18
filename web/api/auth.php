<?php
define('API_CONTEXT', true);
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/TOTP.php';
require_once __DIR__ . '/../includes/JWT.php';

$action = $_GET['action'] ?? '';

// 1. Rate Limiting check
if ($action === 'login') {
    if (!check_rate_limit($pdo, 'login', 5, 60)) {
        $_SESSION['error'] = "Too many login attempts. Please wait 60 seconds.";
        header("Location: ../index.php");
        exit();
    }
} elseif ($action === 'register') {
    if (!check_rate_limit($pdo, 'register', 3, 60)) {
        $_SESSION['error'] = "Too many registration attempts. Please wait 60 seconds.";
        header("Location: ../register.php");
        exit();
    }
}

// 2. CSRF Check on POST requests
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $clientToken = $_POST['csrf_token'] ?? '';
    if (!verify_csrf_token($clientToken)) {
        log_audit($pdo, 'CSRF_FAILURE', "CSRF token validation failed on action: $action");
        $_SESSION['error'] = "Invalid session token. Please submit the form again.";
        header("Location: ../index.php");
        exit();
    }
}

if ($action !== 'logout' && $action !== 'verify_email' && $_SERVER['REQUEST_METHOD'] !== 'POST') {
    header("Location: ../index.php");
    exit();
}

if ($action === 'login') {
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';

    if (empty($username) || empty($password)) {
        $_SESSION['error'] = "All fields are required.";
        header("Location: ../index.php");
        exit();
    }

    try {
        $stmt = $pdo->prepare("SELECT * FROM users WHERE username = ?");
        $stmt->execute([$username]);
        $user = $stmt->fetch();

        if ($user) {
            // Check Account Lockout
            if ($user['lockout_until'] && strtotime($user['lockout_until']) > time()) {
                $secondsLeft = strtotime($user['lockout_until']) - time();
                $_SESSION['error'] = "Account is temporarily locked. Please try again in " . ceil($secondsLeft / 60) . " minute(s).";
                log_audit($pdo, 'LOGIN_LOCKOUT', "Attempted login on locked account: $username", $user['id'], $user['username']);
                header("Location: ../index.php");
                exit();
            }

            // Verify Password
            if (password_verify($password, $user['password'])) {
                // Reset failed attempts
                $resetStmt = $pdo->prepare("UPDATE users SET failed_login_attempts = 0, lockout_until = NULL WHERE id = ?");
                $resetStmt->execute([$user['id']]);

                // Check Email Verification
                if (intval($user['email_verified']) === 0) {
                    $_SESSION['error'] = "Please verify your email before logging in. An activation link was simulated during registration.";
                    log_audit($pdo, 'LOGIN_UNVERIFIED_EMAIL', "Failed login due to unverified email: $username", $user['id'], $user['username']);
                    header("Location: ../index.php");
                    exit();
                }

                // Check Multi-Factor Authentication
                if (intval($user['mfa_enabled']) === 1 && !empty($user['mfa_secret'])) {
                    // Set temporary session for MFA validation
                    $_SESSION['mfa_pending_user_id'] = $user['id'];
                    $_SESSION['mfa_pending_username'] = $user['username'];
                    $_SESSION['mfa_pending_role'] = $user['role'];
                    $_SESSION['mfa_pending_lab_type'] = $user['lab_type'];
                    
                    log_audit($pdo, 'MFA_PENDING', "MFA challenge initiated: $username", $user['id'], $user['username']);
                    header("Location: ../index.php?mfa=1");
                    exit();
                }

                // Complete Login (No MFA active)
                session_regenerate_id(true);
                log_audit($pdo, 'LOGIN_SUCCESS', "User logged in successfully: $username", $user['id'], $user['username']);

                // Generate per-tab session token
                $tabToken = generate_tab_token();

                if ($user['role'] === 'admin') {
                    $_SESSION['admin_user_id'] = $user['id'];
                    $_SESSION['admin_username'] = $user['username'];
                    $_SESSION['admin_role'] = $user['role'];
                    set_tab_session($tabToken, [
                        'user_id' => $user['id'],
                        'username' => $user['username'],
                        'role' => 'admin'
                    ]);
                    header("Location: ../admin.php?tab_token=" . urlencode($tabToken));
                } else {
                    $_SESSION['student_user_id'] = $user['id'];
                    $_SESSION['student_username'] = $user['username'];
                    $_SESSION['student_role'] = $user['role'];
                    $_SESSION['student_lab_type'] = $user['lab_type'];
                    set_tab_session($tabToken, [
                        'user_id' => $user['id'],
                        'username' => $user['username'],
                        'role' => 'user',
                        'lab_type' => $user['lab_type']
                    ]);
                    header("Location: ../dashboard.php?tab_token=" . urlencode($tabToken));
                }
                exit();
            } else {
                // Password incorrect - increment failed attempts
                $failedAttempts = intval($user['failed_login_attempts']) + 1;
                $lockoutUntil = null;
                
                if ($failedAttempts >= 5) {
                    // Lock account for 15 minutes
                    $lockoutTime = time() + 900;
                    $lockoutUntil = date('Y-m-d H:i:s', $lockoutTime);
                    $_SESSION['error'] = "Invalid username or password. Account locked for 15 minutes due to too many failed attempts.";
                    log_audit($pdo, 'ACCOUNT_LOCKOUT_TRIGGERED', "Account locked for 15 minutes: $username", $user['id'], $user['username']);
                } else {
                    $_SESSION['error'] = "Invalid username or password.";
                    log_audit($pdo, 'LOGIN_FAILED_PASSWORD', "Incorrect password attempt: $username", $user['id'], $user['username']);
                }
                
                $updateLockout = $pdo->prepare("UPDATE users SET failed_login_attempts = ?, lockout_until = ? WHERE id = ?");
                $updateLockout->execute([$failedAttempts, $lockoutUntil, $user['id']]);
                
                header("Location: ../index.php");
                exit();
            }
        } else {
            $_SESSION['error'] = "Invalid username or password.";
            log_audit($pdo, 'LOGIN_FAILED_USERNAME', "Non-existent user attempted login: $username");
            header("Location: ../index.php");
            exit();
        }
    } catch (PDOException $e) {
        throw $e; // Caught by global exception handler in security.php
    }
}

elseif ($action === 'mfa_verify') {
    if (session_status() === PHP_SESSION_NONE) {
        secure_session_start();
    }

    $userId = $_SESSION['mfa_pending_user_id'] ?? null;
    $otpCode = trim($_POST['mfa_code'] ?? '');

    if (!$userId || empty($otpCode)) {
        $_SESSION['error'] = "MFA session invalid or expired.";
        header("Location: ../index.php");
        exit();
    }

    try {
        $stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
        $stmt->execute([$userId]);
        $user = $stmt->fetch();

        if ($user && TOTP::verify($user['mfa_secret'], $otpCode)) {
            // Success! Complete login
            session_regenerate_id(true);
            log_audit($pdo, 'LOGIN_MFA_SUCCESS', "MFA verification successful: " . $user['username'], $user['id'], $user['username']);

            // Generate per-tab session token
            $tabToken = generate_tab_token();

            if ($user['role'] === 'admin') {
                $_SESSION['admin_user_id'] = $user['id'];
                $_SESSION['admin_username'] = $user['username'];
                $_SESSION['admin_role'] = $user['role'];
                set_tab_session($tabToken, [
                    'user_id' => $user['id'],
                    'username' => $user['username'],
                    'role' => 'admin'
                ]);
                header("Location: ../admin.php?tab_token=" . urlencode($tabToken));
            } else {
                $_SESSION['student_user_id'] = $user['id'];
                $_SESSION['student_username'] = $user['username'];
                $_SESSION['student_role'] = $user['role'];
                $_SESSION['student_lab_type'] = $user['lab_type'];
                set_tab_session($tabToken, [
                    'user_id' => $user['id'],
                    'username' => $user['username'],
                    'role' => 'user',
                    'lab_type' => $user['lab_type']
                ]);
                header("Location: ../dashboard.php?tab_token=" . urlencode($tabToken));
            }
            
            // Clear MFA state variables
            unset($_SESSION['mfa_pending_user_id']);
            unset($_SESSION['mfa_pending_username']);
            unset($_SESSION['mfa_pending_role']);
            unset($_SESSION['mfa_pending_lab_type']);
            exit();
        } else {
            $_SESSION['error'] = "Invalid verification code. Please try again.";
            log_audit($pdo, 'LOGIN_MFA_FAILED', "Failed MFA verification: " . ($user ? $user['username'] : 'Unknown'), $userId);
            header("Location: ../index.php?mfa=1");
            exit();
        }
    } catch (PDOException $e) {
        throw $e;
    }
}

elseif ($action === 'register') {
    $username = trim($_POST['username'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';
    $confirm_password = $_POST['confirm_password'] ?? '';
    $lab_type = $_POST['lab_type'] ?? '';

    // Basic validation
    if (empty($username) || empty($email) || empty($password) || empty($confirm_password) || empty($lab_type)) {
        $_SESSION['error'] = "All fields are required.";
        header("Location: ../register.php");
        exit();
    }

    if ($password !== $confirm_password) {
        $_SESSION['error'] = "Passwords do not match.";
        header("Location: ../register.php");
        exit();
    }

    if (!validate_email($email)) {
        $_SESSION['error'] = "Invalid email format.";
        header("Location: ../register.php");
        exit();
    }

    if (!validate_username($username)) {
        $_SESSION['error'] = "Username must be 3-30 characters and contain only letters, numbers, and underscores.";
        header("Location: ../register.php");
        exit();
    }

    // Enforce Password Complexity (min 8 chars, 1 uppercase, 1 lowercase, 1 digit, 1 special character)
    if (strlen($password) < 8 ||
        !preg_match('/[a-z]/', $password) ||
        !preg_match('/[A-Z]/', $password) ||
        !preg_match('/[0-9]/', $password) ||
        !preg_match('#[!@#$%^&*()_+\-=\[\]{};\':",.\\/<>?|`~]#', $password)) {
        $_SESSION['error'] = "Password must be at least 8 characters long and include at least one uppercase letter, one lowercase letter, one number, and one special character.";
        header("Location: ../register.php");
        exit();
    }

    try {
        // Check duplicate username or email
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM users WHERE username = ? OR email = ?");
        $stmt->execute([$username, $email]);
        if ($stmt->fetchColumn() > 0) {
            $_SESSION['error'] = "Username or email is already registered.";
            header("Location: ../register.php");
            exit();
        }

        // Generate email verification token and TOTP MFA Secret
        $verificationToken = bin2hex(random_bytes(32));
        $mfaSecret = TOTP::generateSecret();

        // Insert new user (unverified by default)
        $hashed_password = password_hash($password, PASSWORD_ARGON2ID); // Using enterprise grade Argon2id hashing
        $stmt = $pdo->prepare("INSERT INTO users (username, email, password, role, lab_type, container_status, email_verified, verification_token, mfa_secret, mfa_enabled) VALUES (?, ?, ?, 'user', ?, 'stopped', 0, ?, ?, 0)");
        $stmt->execute([$username, $email, $hashed_password, $lab_type, $verificationToken, $mfaSecret]);

        $newUserId = $pdo->lastInsertId();
        log_audit($pdo, 'REGISTRATION_SUCCESS', "New user registered: $username. Email verification token issued.", $newUserId, $username);

        // Simulate sending verification email by outputting instructions (we'll display a notification to the user)
        $_SESSION['success'] = "Registration successful! [SIMULATION] Please verify your email to activate your account: <a href='api/auth.php?action=verify_email&token=$verificationToken' class='underline text-yellow-400 font-bold'>Verify Email Now</a>";
        header("Location: ../index.php");
        exit();

    } catch (PDOException $e) {
        throw $e;
    }
}

elseif ($action === 'verify_email') {
    $token = trim($_GET['token'] ?? '');
    
    if (empty($token)) {
        $_SESSION['error'] = "Invalid activation link.";
        header("Location: ../index.php");
        exit();
    }

    try {
        $stmt = $pdo->prepare("SELECT id, username FROM users WHERE verification_token = ? AND email_verified = 0");
        $stmt->execute([$token]);
        $user = $stmt->fetch();

        if ($user) {
            $activate = $pdo->prepare("UPDATE users SET email_verified = 1, verification_token = NULL WHERE id = ?");
            $activate->execute([$user['id']]);

            log_audit($pdo, 'EMAIL_VERIFICATION_SUCCESS', "Email verified successfully.", $user['id'], $user['username']);
            $_SESSION['success'] = "Your email has been successfully verified! You can now log in.";
        } else {
            $_SESSION['error'] = "Invalid or expired activation link.";
        }
        header("Location: ../index.php");
        exit();
    } catch (PDOException $e) {
        throw $e;
    }
}

elseif ($action === 'logout') {
    $role = $_GET['role'] ?? 'student';
    
    $userId = $_SESSION['student_user_id'] ?? $_SESSION['admin_user_id'] ?? null;
    $username = $_SESSION['student_username'] ?? $_SESSION['admin_username'] ?? null;
    log_audit($pdo, 'LOGOUT', "User logged out.", $userId, $username);

    if ($role === 'admin') {
        unset($_SESSION['admin_user_id']);
        unset($_SESSION['admin_username']);
        unset($_SESSION['admin_role']);
    } else {
        unset($_SESSION['student_user_id']);
        unset($_SESSION['student_username']);
        unset($_SESSION['student_role']);
        unset($_SESSION['student_lab_type']);
    }
    
    // If both sessions are now empty, destroy the PHP session completely
    if (empty($_SESSION['admin_user_id']) && empty($_SESSION['student_user_id'])) {
        $_SESSION = [];
        if (ini_get("session.use_cookies")) {
            $params = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000,
                $params["path"], $params["domain"],
                $params["secure"], $params["httponly"]
            );
        }
        session_destroy();
    }
    
    header("Location: ../index.php");
    exit();
}

else {
    header("Location: ../index.php");
    exit();
}
?>
