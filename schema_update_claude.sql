-- Migration: Add Claude AI integration support
-- Date: 2026-01-24
-- Purpose: Create table for temporary email storage pending Claude AI analysis

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
