CREATE DATABASE IF NOT EXISTS `linux_lab`;
USE `linux_lab`;

-- Services Table
CREATE TABLE IF NOT EXISTS `services` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `name` VARCHAR(50) NOT NULL UNIQUE,
  `image_name` VARCHAR(255) NOT NULL,
  `description` TEXT,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Seed default services
INSERT INTO `services` (`name`, `image_name`, `description`) VALUES
('Ubuntu 22.04 LTS', 'lab-ubuntu', 'Clean Ubuntu 22.04 environment with SSH and VS Code support.'),
('Kali Linux', 'lab-kali', 'Kali Linux environment loaded with security and penetration testing tools.'),
('Ubuntu with Docker', 'lab-docker', 'Ubuntu environment with full privileged access and Docker-in-Docker support.'),
('Ubuntu with Java Dev Server', 'lab-java', 'Ubuntu environment pre-installed with OpenJDK and Java tools.'),
('Ubuntu with MySQL', 'lab-mysql', 'Ubuntu environment pre-installed and configured with MySQL Server.'),
('Ubuntu with Nginx', 'lab-nginx', 'Ubuntu environment pre-installed with Nginx Web Server.'),
('n8n Lab', 'lab-n8n', 'Ubuntu environment with Node.js, code-server, and n8n workflow automation service pre-installed.')
ON DUPLICATE KEY UPDATE `image_name`=VALUES(`image_name`);



-- Users Table
CREATE TABLE IF NOT EXISTS `users` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `username` VARCHAR(50) NOT NULL UNIQUE,
  `email` VARCHAR(100) NOT NULL UNIQUE,
  `password` VARCHAR(255) NOT NULL,
  `role` ENUM('user', 'admin') NOT NULL DEFAULT 'user',
  `lab_type` VARCHAR(50) NOT NULL,
  `container_id` VARCHAR(100) DEFAULT NULL,
  `container_status` VARCHAR(20) NOT NULL DEFAULT 'stopped',
  `cpu_limit` DECIMAL(3,1) DEFAULT 1.0,
  `memory_limit` INT DEFAULT 2048,
  `gpu_limit` INT DEFAULT 0,
  `ssh_private_key` TEXT DEFAULT NULL,
  `ssh_public_key` TEXT DEFAULT NULL,
  `failed_login_attempts` INT DEFAULT 0,
  `lockout_until` TIMESTAMP NULL DEFAULT NULL,
  `email_verified` TINYINT(1) DEFAULT 0,
  `verification_token` VARCHAR(100) DEFAULT NULL,
  `mfa_secret` VARCHAR(100) DEFAULT NULL,
  `mfa_enabled` TINYINT(1) DEFAULT 0,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ISO Files Table
CREATE TABLE IF NOT EXISTS `isos` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `filename` VARCHAR(255) NOT NULL,
  `filepath` VARCHAR(255) NOT NULL,
  `uploaded_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Mounts Table (tracks ISOs mounted in user containers)
CREATE TABLE IF NOT EXISTS `mounts` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `user_id` INT NOT NULL,
  `iso_id` INT NOT NULL,
  `mount_path` VARCHAR(255) NOT NULL,
  `mounted_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`iso_id`) REFERENCES `isos`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Audit Logs Table
CREATE TABLE IF NOT EXISTS `audit_logs` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `user_id` INT DEFAULT NULL,
  `username` VARCHAR(50) DEFAULT NULL,
  `action` VARCHAR(100) NOT NULL,
  `ip_address` VARCHAR(45) DEFAULT NULL,
  `details` TEXT DEFAULT NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Refresh Tokens Table
CREATE TABLE IF NOT EXISTS `refresh_tokens` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `user_id` INT NOT NULL,
  `token` VARCHAR(255) NOT NULL UNIQUE,
  `expires_at` TIMESTAMP NOT NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Rate Limits Table
CREATE TABLE IF NOT EXISTS `rate_limits` (
  `ip_address` VARCHAR(45) NOT NULL,
  `endpoint` VARCHAR(100) NOT NULL,
  `requests` INT DEFAULT 1,
  `reset_time` TIMESTAMP NOT NULL,
  PRIMARY KEY (`ip_address`, `endpoint`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Seed default accounts
-- Admin: admin / admin123 -> $2y$10$ZkqTLZR/JOfFR5UdfCx/o.93RxNMYdTfNIAANKXvuXjbFVg6ozJVu
-- User:  testuser / testuser123 -> $2y$10$mAEM4H8g6j7yogdERrTSbe3t8OL7HAhUQS4f7PaDT/FX.kIT/91FC
INSERT INTO `users` (`username`, `email`, `password`, `role`, `lab_type`, `container_status`, `email_verified`) VALUES
('admin', 'admin@linuxlab.local', '$2y$10$ZkqTLZR/JOfFR5UdfCx/o.93RxNMYdTfNIAANKXvuXjbFVg6ozJVu', 'admin', 'Ubuntu 22.04 LTS', 'stopped', 1),
('testuser', 'testuser@linuxlab.local', '$2y$10$mAEM4H8g6j7yogdERrTSbe3t8OL7HAhUQS4f7PaDT/FX.kIT/91FC', 'user', 'Ubuntu 22.04 LTS', 'stopped', 1)
ON DUPLICATE KEY UPDATE `password`=VALUES(`password`);

