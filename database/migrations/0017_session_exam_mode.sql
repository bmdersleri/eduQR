-- Migration 0017: add exam_mode toggle to sessions (FR-96)

ALTER TABLE sessions
    ADD COLUMN exam_mode TINYINT(1) NOT NULL DEFAULT 0 AFTER moderation_mode;
