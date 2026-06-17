<?php
// security.php - Core Security Library for CloudLab

// 1. Secure Session Management Configuration
function secure_session_start() {
    if (session_status() === PHP_SESSION_NONE) {
        $cookieParams = session_get_cookie_params();
        $secure = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on';
        
        session_set_cookie_params([
            'lifetime' => 3600, // 1 hour session lifetime
            'path' => '/',
            'domain' => '',
            'secure' => $secure,
            'httponly' => true,
            'samesite' => 'Lax'
        ]);
        
        session_start();
    }

    // Initialize CSRF token if not set
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }

    // JWT Authorization header check for stateless API/CLI authentication
    $authHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '';
    if (empty($authHeader) && function_exists('getallheaders')) {
        $headers = getallheaders();
        $authHeader = $headers['Authorization'] ?? $headers['authorization'] ?? '';
    }
    if (preg_match('/Bearer\s(\S+)/i', $authHeader, $matches)) {
        require_once __DIR__ . '/JWT.php';
        $payload = JWT::decode($matches[1]);
        if ($payload) {
            if ($payload['role'] === 'admin') {
                $_SESSION['admin_user_id'] = $payload['user_id'];
                $_SESSION['admin_username'] = $payload['username'];
                $_SESSION['admin_role'] = $payload['role'];
            } else {
                $_SESSION['student_user_id'] = $payload['user_id'];
                $_SESSION['student_username'] = $payload['username'];
                $_SESSION['student_role'] = $payload['role'];
                $_SESSION['student_lab_type'] = $payload['lab_type'] ?? 'Ubuntu 22.04 LTS';
            }
        }
    }

    // Inactivity timeout check (30 minutes)
    if (isset($_SESSION['last_activity']) && (time() - $_SESSION['last_activity'] > 1800)) {
        session_unset();
        session_destroy();
        header("Location: " . (defined('API_CONTEXT') ? '../index.php' : 'index.php'));
        exit();
    }
    $_SESSION['last_activity'] = time();

    // Periodically regenerate session ID (every 15 minutes)
    if (!isset($_SESSION['created_time'])) {
        $_SESSION['created_time'] = time();
    } elseif (time() - $_SESSION['created_time'] > 900) {
        session_regenerate_id(true);
        $_SESSION['created_time'] = time();
    }
}

// 2. Security Headers
function set_security_headers() {
    if (headers_sent()) return;

    // Content Security Policy
    header("Content-Security-Policy: default-src 'self' https:; " .
           "script-src 'self' 'unsafe-inline' 'unsafe-eval' https://cdn.tailwindcss.com https://cdn.jsdelivr.net https://cdnjs.cloudflare.com; " .
           "style-src 'self' 'unsafe-inline' https://fonts.googleapis.com https://cdnjs.cloudflare.com; " .
           "font-src 'self' https://fonts.gstatic.com https://cdnjs.cloudflare.com; " .
           "connect-src 'self' ws: wss:; " .
           "img-src 'self' data: https:; " .
           "frame-ancestors 'none';");

    header("Strict-Transport-Security: max-age=31536000; includeSubDomains; preload");
    header("X-Frame-Options: DENY");
    header("X-Content-Type-Options: nosniff");
    header("Referrer-Policy: strict-origin-when-cross-origin");
    header("Permissions-Policy: geolocation=(), camera=(), microphone=()");
}

// 3. CSRF Helpers
function csrf_token() {
    if (session_status() === PHP_SESSION_NONE) {
        secure_session_start();
    }
    return $_SESSION['csrf_token'] ?? '';
}

function verify_csrf_token($token) {
    if (session_status() === PHP_SESSION_NONE) {
        secure_session_start();
    }
    if (empty($token) || empty($_SESSION['csrf_token'])) {
        return false;
    }
    return hash_equals($_SESSION['csrf_token'], $token);
}

// 4. Client IP Resolver (checks proxies safely)
function get_client_ip() {
    $ip = $_SERVER['REMOTE_ADDR'] ?? '';
    if (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
        $parts = explode(',', $_SERVER['HTTP_X_FORWARDED_FOR']);
        $clientIp = trim($parts[0]);
        if (filter_var($clientIp, FILTER_VALIDATE_IP)) {
            $ip = $clientIp;
        }
    }
    return $ip;
}

// 5. Database-backed Rate Limiter
function check_rate_limit($pdo, $endpoint, $limit = 60, $seconds = 60) {
    $ip = get_client_ip();
    $now = date('Y-m-d H:i:s');
    
    try {
        $stmt = $pdo->prepare("SELECT requests, reset_time FROM rate_limits WHERE ip_address = ? AND endpoint = ?");
        $stmt->execute([$ip, $endpoint]);
        $row = $stmt->fetch();
        
        if ($row) {
            $resetTime = strtotime($row['reset_time']);
            if (time() > $resetTime) {
                // Limit reset time expired, reset bucket
                $newResetTime = date('Y-m-d H:i:s', time() + $seconds);
                $update = $pdo->prepare("UPDATE rate_limits SET requests = 1, reset_time = ? WHERE ip_address = ? AND endpoint = ?");
                $update->execute([$newResetTime, $ip, $endpoint]);
                return true;
            } else {
                if ($row['requests'] >= $limit) {
                    // Limit exceeded!
                    return false;
                } else {
                    $update = $pdo->prepare("UPDATE rate_limits SET requests = requests + 1 WHERE ip_address = ? AND endpoint = ?");
                    $update->execute([$ip, $endpoint]);
                    return true;
                }
            }
        } else {
            // No entry yet, create one
            $newResetTime = date('Y-m-d H:i:s', time() + $seconds);
            $insert = $pdo->prepare("INSERT INTO rate_limits (ip_address, endpoint, requests, reset_time) VALUES (?, ?, 1, ?)");
            $insert->execute([$ip, $endpoint, $newResetTime]);
            return true;
        }
    } catch (Exception $e) {
        // Fallback to true if rate limiting table fails to avoid blocking the site
        error_log("Rate limiting query error: " . $e->getMessage());
        return true;
    }
}

// 6. Input Sanitizers & Allowlists
function validate_username($username) {
    return preg_match('/^[a-zA-Z0-9_]{3,30}$/', $username);
}

function validate_email($email) {
    return filter_var($email, FILTER_VALIDATE_EMAIL);
}

function validate_package_name($package) {
    return preg_match('/^[a-zA-Z0-9\-_ ]+$/', $package);
}

function validate_mount_path($path) {
    // Allows standard Unix paths like /mnt/iso, /media/cdrom
    return preg_match('/^\/[a-zA-Z0-9\-_\/]+$/', $path) && !str_contains($path, '..');
}

// 7. Security Audit Logging Helper
function log_audit($pdo, $action, $details = '', $userId = null, $username = null) {
    $ip = get_client_ip();
    if ($userId === null) {
        $userId = $_SESSION['student_user_id'] ?? $_SESSION['admin_user_id'] ?? null;
    }
    if ($username === null) {
        $username = $_SESSION['student_username'] ?? $_SESSION['admin_username'] ?? null;
    }
    
    try {
        $stmt = $pdo->prepare("INSERT INTO audit_logs (user_id, username, action, ip_address, details) VALUES (?, ?, ?, ?, ?)");
        $stmt->execute([$userId, $username, $action, $ip, $details]);
    } catch (Exception $e) {
        error_log("Audit logger error: " . $e->getMessage() . " | Action: $action | IP: $ip");
    }
}

// 8. Global Safe Error & Exception Handler
function custom_exception_handler($exception) {
    // Log exception details internally
    error_log("Unhandled Exception: " . $exception->getMessage() . " in " . $exception->getFile() . " on line " . $exception->getLine() . "\nStack trace:\n" . $exception->getTraceAsString());
    
    // Clear output buffers and send generic error response
    if (!headers_sent()) {
        if (str_contains($_SERVER['HTTP_ACCEPT'] ?? '', 'application/json') || 
            (isset($_SERVER['CONTENT_TYPE']) && str_contains($_SERVER['CONTENT_TYPE'], 'application/json')) ||
            defined('API_CONTEXT')) {
            header('Content-Type: application/json');
            http_response_code(500);
            echo json_encode(['success' => false, 'message' => 'An internal server error occurred. Please try again later.']);
        } else {
            http_response_code(500);
            echo "<!DOCTYPE html><html><head><title>Internal Server Error</title><link href='https://fonts.googleapis.com/css2?family=Outfit&display=swap' rel='stylesheet'><style>body{background:#09090b;color:#f4f4f5;font-family:'Outfit',sans-serif;display:flex;align-items:center;justify-content:center;height:100vh;margin:0;text-align:center;}h1{color:#f5b027;font-size:2.5rem;margin-bottom:0.5rem;}p{color:#a1a1aa;}</style></head><body><div><h1>System Error</h1><p>An internal error occurred. Our engineers have been notified.</p></div></body></html>";
        }
    }
    exit();
}

function custom_error_handler($errno, $errstr, $errfile, $errline) {
    if (!(error_reporting() & $errno)) {
        return false;
    }
    throw new ErrorException($errstr, 0, $errno, $errfile, $errline);
}

// Set custom handlers
set_exception_handler('custom_exception_handler');
set_error_handler('custom_error_handler');

// Execute security initialization
secure_session_start();
set_security_headers();
?>
