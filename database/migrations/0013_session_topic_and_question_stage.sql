-- FR-33, FR-45, FR-80
-- Add session topic metadata and staged question flow metadata.

ALTER TABLE sessions
    ADD COLUMN topic_name VARCHAR(200) NULL AFTER title;

ALTER TABLE questions
    ADD COLUMN stage ENUM('opening','middle','closing') NOT NULL DEFAULT 'middle' AFTER question_type,
    ADD INDEX idx_questions_session_stage_order (session_id, stage, order_no);
