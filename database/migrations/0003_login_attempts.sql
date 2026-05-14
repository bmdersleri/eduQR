-- Migration 0003: login_attempts table
-- Phase 2 — Rate limiting for instructor login [FR-05]

CREATE TABLE IF NOT EXISTS login_attempts (
    id          BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    email       VARCHAR(190) NOT NULL,
    ip_hash     CHAR(64)     NULL,
    succeeded   TINYINT(1)   NOT NULL DEFAULT 0,
    created_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_attempts_email_time (email, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
