-- FR-93, FR-94, FR-95
-- Add a course-scoped reusable question bank with JSON payload storage.

CREATE TABLE question_bank_items (
    id                 BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    course_id          BIGINT UNSIGNED NOT NULL,
    created_by_user_id BIGINT UNSIGNED NOT NULL,
    source_kind        ENUM('session_question','lecture_notes') NOT NULL DEFAULT 'session_question',
    source_title       VARCHAR(200) NULL,
    payload_json       JSON NOT NULL,
    created_at         DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at         DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_question_bank_course (course_id),
    INDEX idx_question_bank_creator (created_by_user_id),
    INDEX idx_question_bank_course_source (course_id, source_kind),
    CONSTRAINT fk_question_bank_course
        FOREIGN KEY (course_id) REFERENCES courses(id) ON DELETE CASCADE,
    CONSTRAINT fk_question_bank_creator
        FOREIGN KEY (created_by_user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
