-- Migration 0016: add fill_in_the_blank question type (FR-31)

ALTER TABLE questions
    MODIFY COLUMN question_type ENUM('multiple_choice','open_text','yes_no','likert_5','fill_in_the_blank') NOT NULL;
