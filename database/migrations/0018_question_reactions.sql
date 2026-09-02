-- Migration 0018: add question_reactions table (FR-48)

CREATE TABLE question_reactions (
    id              BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    session_id      BIGINT UNSIGNED NOT NULL,
    question_id     BIGINT UNSIGNED NOT NULL,
    participant_id  BIGINT UNSIGNED NOT NULL,
    reaction        ENUM('got_it','lost') NOT NULL,
    created_at      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP
                                                 ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uk_question_reactions_question_participant (question_id, participant_id),
    INDEX idx_question_reactions_session (session_id),
    INDEX idx_question_reactions_question (question_id),
    CONSTRAINT fk_question_reactions_session
        FOREIGN KEY (session_id) REFERENCES sessions(id) ON DELETE CASCADE,
    CONSTRAINT fk_question_reactions_question
        FOREIGN KEY (question_id) REFERENCES questions(id) ON DELETE CASCADE,
    CONSTRAINT fk_question_reactions_participant
        FOREIGN KEY (participant_id) REFERENCES participants(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
