-- Phase 3: courses table
-- DATA_MODEL §2.2

CREATE TABLE IF NOT EXISTS courses (
    id                BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    instructor_id     BIGINT UNSIGNED NOT NULL,
    title             VARCHAR(200) NOT NULL,
    code              VARCHAR(40)  NULL,
    semester          VARCHAR(40)  NULL,
    description       TEXT         NULL,
    default_language  VARCHAR(8)   NOT NULL DEFAULT 'en',
    status            ENUM('active','archived') NOT NULL DEFAULT 'active',
    created_at        DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at        DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP
                                            ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_courses_instructor (instructor_id),
    INDEX idx_courses_status (status),
    CONSTRAINT fk_courses_instructor
        FOREIGN KEY (instructor_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
