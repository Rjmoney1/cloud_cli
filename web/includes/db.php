<?php
require_once __DIR__ . '/security.php';

$host = getenv('DB_HOST') ?: 'mysql-db';
$db   = getenv('DB_NAME') ?: 'linux_lab';
$user = getenv('DB_USER') ?: 'lab_user';
$pass = getenv('DB_PASS') ?: 'lab_password';
$charset = 'utf8mb4';

$dsn = "mysql:host=$host;dbname=$db;charset=$charset";
$options = [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES   => false,
];

try {
     $pdo = new PDO($dsn, $user, $pass, $options);
     
     // 1. Dynamic Database Schema Auto-Migration
     $columnsToAdd = [
         'failed_login_attempts' => "INT DEFAULT 0",
         'lockout_until'         => "TIMESTAMP NULL DEFAULT NULL",
         'email_verified'        => "TINYINT(1) DEFAULT 0",
         'verification_token'    => "VARCHAR(100) DEFAULT NULL",
         'mfa_secret'            => "VARCHAR(100) DEFAULT NULL",
         'mfa_enabled'           => "TINYINT(1) DEFAULT 0"
     ];
     
     foreach ($columnsToAdd as $colName => $colDef) {
         $checkCol = $pdo->query("SHOW COLUMNS FROM `users` LIKE '$colName'")->fetch();
         if (!$checkCol) {
             $pdo->exec("ALTER TABLE `users` ADD COLUMN `$colName` $colDef");
         }
     }
     
     // Create audit logs table
     $pdo->exec("CREATE TABLE IF NOT EXISTS `audit_logs` (
       `id` INT AUTO_INCREMENT PRIMARY KEY,
       `user_id` INT DEFAULT NULL,
       `username` VARCHAR(50) DEFAULT NULL,
       `action` VARCHAR(100) NOT NULL,
       `ip_address` VARCHAR(45) DEFAULT NULL,
       `details` TEXT DEFAULT NULL,
       `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
     ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
     
     // Create refresh tokens table
     $pdo->exec("CREATE TABLE IF NOT EXISTS `refresh_tokens` (
       `id` INT AUTO_INCREMENT PRIMARY KEY,
       `user_id` INT NOT NULL,
       `token` VARCHAR(255) NOT NULL UNIQUE,
       `expires_at` TIMESTAMP NOT NULL,
       `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
       FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
     ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

     // Create rate limits table
     $pdo->exec("CREATE TABLE IF NOT EXISTS `rate_limits` (
       `ip_address` VARCHAR(45) NOT NULL,
       `endpoint` VARCHAR(100) NOT NULL,
       `requests` INT DEFAULT 1,
       `reset_time` TIMESTAMP NOT NULL,
       PRIMARY KEY (`ip_address`, `endpoint`)
     ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
     
     // 2. Dynamically sync admin password from the environment if changed
     $adminPass = getenv('ADMIN_PASSWORD');
     if (!empty($adminPass)) {
         $stmt = $pdo->prepare("SELECT password FROM users WHERE username = 'admin'");
         $stmt->execute();
         $hash = $stmt->fetchColumn();
         if ($hash && !password_verify($adminPass, $hash)) {
             $newHash = password_hash($adminPass, PASSWORD_DEFAULT);
             $updateStmt = $pdo->prepare("UPDATE users SET password = ? WHERE username = 'admin'");
             $updateStmt->execute([$newHash]);
         }
     }
} catch (\PDOException $e) {
     error_log("Database connection failed: " . $e->getMessage());
     die("Database connection failed. Please check the logs or contact the administrator.");
}
?>
