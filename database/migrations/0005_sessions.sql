CREATE TABLE IF NOT EXISTS sessions (
    id                        BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    course_id                 BIGINT UNSIGNED NOT NULL,
    title                     VARCHAR(200) NOT NULL,
    short_code                VARCHAR(8)   NOT NULL UNIQUE,
    status                    ENUM('draft','active','paused','closed') NOT NULL DEFAULT 'active',
    language                  VARCHAR(8)   NOT NULL DEFAULT 'en',
    allow_anonymous           TINYINT(1)   NOT NULL DEFAULT 1,
    show_results_to_students  TINYINT(1)   NOT NULL DEFAULT 0,
    moderation_mode           TINYINT(1)   NOT NULL DEFAULT 0,
    started_at                DATETIME     NULL,
    paused_at                 DATETIME     NULL,
    closed_at                 DATETIME     NULL,
    delete_requested_at       DATETIME     NULL,
    anonymized                TINYINT(1)   NOT NULL DEFAULT 0,
    created_at                DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at                DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP
                                                    ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_sessions_course (course_id),
    INDEX idx_sessions_status (status),
    INDEX idx_sessions_short_code (short_code),
    INDEX idx_sessions_delete_requested (delete_requested_at),
    CONSTRAINT fk_sessions_course
        FOREIGN KEY (course_id) REFERENCES courses(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
