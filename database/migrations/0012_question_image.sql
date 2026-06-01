-- Phase 11 T-1106: question image attachment (FR-39)

ALTER TABLE questions
    ADD COLUMN image_path VARCHAR(500) NULL DEFAULT NULL
        COMMENT 'Relative path under public/uploads/questions/, e.g. questions/42_abc123.jpg'
    AFTER question_text;
