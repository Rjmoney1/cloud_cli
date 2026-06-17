<?php
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
     
     // Dynamically sync admin password from the environment if changed
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
     die("Database connection failed: " . $e->getMessage());
}
?>
