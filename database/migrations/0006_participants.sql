-- Phase 5: Participants (anonymous-ish students who joined a session)
-- DATA_MODEL.md §2.6

CREATE TABLE IF NOT EXISTS participants (
    id                  BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    session_id          BIGINT UNSIGNED NOT NULL,
    nickname            VARCHAR(48)     NOT NULL,
    nickname_normalized VARCHAR(48)     NOT NULL,
    device_hash         CHAR(64)        NULL,
    is_approved         TINYINT(1)      NOT NULL DEFAULT 1,
    joined_at           DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,

    INDEX idx_participants_session (session_id),
    UNIQUE KEY uk_participants_nickname (session_id, nickname_normalized),

    CONSTRAINT fk_participants_session
        FOREIGN KEY (session_id) REFERENCES sessions(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
