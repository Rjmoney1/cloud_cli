<?php
session_start();
require_once __DIR__ . '/../includes/db.php';

$action = $_GET['action'] ?? '';

if ($action !== 'logout' && $_SERVER['REQUEST_METHOD'] !== 'POST') {
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

        if ($user && password_verify($password, $user['password'])) {
            // Regenerate session ID to prevent session fixation
            session_regenerate_id(true);
            
            if ($user['role'] === 'admin') {
                $_SESSION['admin_user_id'] = $user['id'];
                $_SESSION['admin_username'] = $user['username'];
                $_SESSION['admin_role'] = $user['role'];
                header("Location: ../admin.php");
            } else {
                $_SESSION['student_user_id'] = $user['id'];
                $_SESSION['student_username'] = $user['username'];
                $_SESSION['student_role'] = $user['role'];
                $_SESSION['student_lab_type'] = $user['lab_type'];
                header("Location: ../dashboard.php");
            }
            exit();
        } else {
            $_SESSION['error'] = "Invalid username or password.";
            header("Location: ../index.php");
            exit();
        }
    } catch (PDOException $e) {
        $_SESSION['error'] = "Database error: " . $e->getMessage();
        header("Location: ../index.php");
        exit();
    }
}

else if ($action === 'register') {
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

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $_SESSION['error'] = "Invalid email format.";
        header("Location: ../register.php");
        exit();
    }

    // Check if username contains only valid characters (alphanumeric and underscores)
    // This is important because username will be used for container names!
    if (!preg_match('/^[a-zA-Z0-9_]+$/', $username)) {
        $_SESSION['error'] = "Username must contain only letters, numbers, and underscores.";
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

        // Insert new user
        $hashed_password = password_hash($password, PASSWORD_DEFAULT);
        $stmt = $pdo->prepare("INSERT INTO users (username, email, password, role, lab_type, container_status) VALUES (?, ?, ?, 'user', ?, 'stopped')");
        $stmt->execute([$username, $email, $hashed_password, $lab_type]);

        $_SESSION['success'] = "Registration successful! You can now log in.";
        header("Location: ../index.php");
        exit();

    } catch (PDOException $e) {
        $_SESSION['error'] = "Database error: " . $e->getMessage();
        header("Location: ../register.php");
        exit();
    }
}

else if ($action === 'logout') {
    $role = $_GET['role'] ?? 'student';
    
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
