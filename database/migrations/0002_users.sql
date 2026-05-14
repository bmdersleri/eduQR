-- Migration 0002: users table
-- Phase 2 — Instructor Authentication [DATA_MODEL §2.1]

CREATE TABLE IF NOT EXISTS users (
    id                  BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    email               VARCHAR(190) NOT NULL UNIQUE,
    password_hash       VARCHAR(255) NOT NULL,
    display_name        VARCHAR(150) NOT NULL,
    role                ENUM('admin','instructor') NOT NULL DEFAULT 'instructor',
    preferred_language  VARCHAR(8)   NOT NULL DEFAULT 'en',
    is_active           TINYINT(1)   NOT NULL DEFAULT 1,
    last_login_at       DATETIME     NULL,
    created_at          DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at          DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP
                                              ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
