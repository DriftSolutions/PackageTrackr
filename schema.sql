-- Package Tracking Application Database Schema

CREATE DATABASE IF NOT EXISTS tracking CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE tracking;

-- Users table
CREATE TABLE IF NOT EXISTS users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    email VARCHAR(255) NOT NULL UNIQUE,
    password_hash VARCHAR(255) NOT NULL,
    is_verified TINYINT(1) DEFAULT 0,
    verification_token VARCHAR(255) DEFAULT NULL,
    verification_token_expires DATETIME DEFAULT NULL,
    password_reset_token VARCHAR(255) DEFAULT NULL,
    password_reset_token_expires DATETIME DEFAULT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_email (email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Remember tokens table for persistent login
CREATE TABLE IF NOT EXISTS remember_tokens (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    token VARCHAR(255) NOT NULL UNIQUE,
    device_info VARCHAR(255) DEFAULT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    expires_at DATETIME NOT NULL,
    last_used_at DATETIME DEFAULT NULL,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_token (token),
    INDEX idx_user_id (user_id),
    INDEX idx_expires_at (expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- User-specific settings table
CREATE TABLE IF NOT EXISTS user_settings (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    setting_key VARCHAR(100) NOT NULL,
    setting_value TEXT,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    UNIQUE KEY uk_user_setting (user_id, setting_key),
    INDEX idx_user_id (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Main tracking numbers table
CREATE TABLE IF NOT EXISTS tracking_numbers (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT DEFAULT NULL,
    tracking_number VARCHAR(255) NOT NULL,
    carrier VARCHAR(50) NOT NULL,
    package_name VARCHAR(255) DEFAULT NULL,
    status VARCHAR(100) DEFAULT 'Information Received',
    raw_status VARCHAR(100) DEFAULT 'InfoReceived',
    sub_status VARCHAR(255) DEFAULT NULL,
    view_type ENUM('current', 'archive', 'trash') DEFAULT 'current',
    estimated_delivery_date DATE DEFAULT NULL,
    delivered_date DATE DEFAULT NULL,
    last_event_date DATETIME DEFAULT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    last_api_check DATETIME DEFAULT NULL,
    is_permanent_status TINYINT(1) DEFAULT 0,
    is_outgoing TINYINT(1) NOT NULL DEFAULT 0,
    raw_api_response LONGTEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`raw_api_response`)),
    original_package_name VARCHAR(255) DEFAULT NULL,
    CONSTRAINT fk_tracking_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    UNIQUE KEY uk_user_tracking (user_id, tracking_number),
    INDEX idx_user_id (user_id),
    INDEX idx_user_view_type (user_id, view_type),
    INDEX idx_user_status (user_id, status),
    INDEX idx_view_type (view_type),
    INDEX idx_status (status),
    INDEX idx_delivered_date (delivered_date),
    INDEX idx_last_api_check (last_api_check)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Tracking events/history table
CREATE TABLE IF NOT EXISTS tracking_events (
    id INT AUTO_INCREMENT PRIMARY KEY,
    tracking_number_id INT NOT NULL,
    event_date DATETIME NOT NULL,
    status VARCHAR(255) NOT NULL,
    location VARCHAR(255) DEFAULT NULL,
    description TEXT DEFAULT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (tracking_number_id) REFERENCES tracking_numbers(id) ON DELETE CASCADE,
    UNIQUE KEY uk_tracking_event (tracking_number_id, event_date, status),
    INDEX idx_tracking_number_id (tracking_number_id),
    INDEX idx_event_date (event_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Settings table for application configuration
CREATE TABLE IF NOT EXISTS settings (
    id INT AUTO_INCREMENT PRIMARY KEY,
    setting_key VARCHAR(100) NOT NULL UNIQUE,
    setting_value TEXT,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Pending Claude AI analysis queue
CREATE TABLE IF NOT EXISTS pending_claude_analysis (
    id INT AUTO_INCREMENT PRIMARY KEY,
    tracking_number_id INT NOT NULL,
    user_id INT NOT NULL,
    email_subject VARCHAR(500) NOT NULL,
    email_body LONGTEXT NOT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    processed_at DATETIME DEFAULT NULL,
    processing_attempts INT DEFAULT 0,
    last_error TEXT DEFAULT NULL,
    FOREIGN KEY (tracking_number_id) REFERENCES tracking_numbers(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_user_id (user_id),
    INDEX idx_processed_at (processed_at),
    INDEX idx_created_at (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Insert default global settings
INSERT INTO settings (setting_key, setting_value) VALUES
('imap_server', 'imap.gmail.com'),
('imap_port', '993'),
('imap_email', 'your-email@gmail.com'),
('imap_password', 'your-app-password'),
('imap_folder', 'INBOX'),
('auto_trash_days', '30'),
('trash_retention_days', '90')
ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value);
