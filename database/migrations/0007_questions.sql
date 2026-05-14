-- Phase 6 T-600: questions and options tables (DATA_MODEL §2.4-2.5)

CREATE TABLE IF NOT EXISTS questions (
    id                      BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    session_id              BIGINT UNSIGNED NOT NULL,
    question_text           TEXT         NOT NULL,
    question_type           ENUM('multiple_choice','open_text','yes_no','likert_5') NOT NULL,
    status                  ENUM('draft','active','closed') NOT NULL DEFAULT 'draft',
    order_no                INT          NOT NULL DEFAULT 0,
    allow_multiple_answers  TINYINT(1)   NOT NULL DEFAULT 0,
    show_results            TINYINT(1)   NOT NULL DEFAULT 0,
    activated_at            DATETIME     NULL,
    closed_at               DATETIME     NULL,
    created_at              DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at              DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP
                                                  ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_questions_session (session_id),
    INDEX idx_questions_session_status (session_id, status),
    CONSTRAINT fk_questions_session
        FOREIGN KEY (session_id) REFERENCES sessions(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS options (
    id            BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    question_id   BIGINT UNSIGNED NOT NULL,
    option_text   VARCHAR(200) NOT NULL,
    option_value  VARCHAR(100) NULL,
    is_correct    TINYINT(1)   NOT NULL DEFAULT 0,
    order_no      INT          NOT NULL DEFAULT 0,
    INDEX idx_options_question (question_id),
    CONSTRAINT fk_options_question
        FOREIGN KEY (question_id) REFERENCES questions(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
