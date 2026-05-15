-- Migration: 0008_answers.sql
-- Phase 7 — Answer Collection (T-700)
-- Creates the answers table per DATA_MODEL.md §2.7

CREATE TABLE IF NOT EXISTS answers (
    id                  BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    question_id         BIGINT UNSIGNED NOT NULL,
    participant_id      BIGINT UNSIGNED NOT NULL,
    selected_option_id  BIGINT UNSIGNED NULL,
    answer_text         TEXT           NULL,
    is_hidden           TINYINT(1)     NOT NULL DEFAULT 0,
    created_at          DATETIME       NOT NULL DEFAULT CURRENT_TIMESTAMP,

    -- One answer per participant per question (FR-44)
    -- Drop this index if allow_multiple_answers is ever enabled (see DATA_MODEL §2.7 note)
    UNIQUE KEY uk_answers_question_participant (question_id, participant_id),

    -- Hot-path: results aggregation
    INDEX idx_answers_question (question_id),

    -- Per-participant history
    INDEX idx_answers_participant (participant_id),

    CONSTRAINT fk_answers_question
        FOREIGN KEY (question_id)    REFERENCES questions(id)  ON DELETE CASCADE,
    CONSTRAINT fk_answers_participant
        FOREIGN KEY (participant_id) REFERENCES participants(id) ON DELETE CASCADE,
    CONSTRAINT fk_answers_option
        FOREIGN KEY (selected_option_id) REFERENCES options(id) ON DELETE SET NULL

) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
