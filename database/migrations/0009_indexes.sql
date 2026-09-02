-- Migration: 0009_indexes.sql
-- Phase 8 — Live Results (T-800)
-- Adds secondary / compound indexes per DATA_MODEL.md §4 (hot-path queries).
--
-- All CREATE TABLE statements already include their own inline indexes.
-- MySQL does not support CREATE INDEX IF NOT EXISTS, so guard each statement
-- against information_schema so it is safe to re-run.

SET @db = DATABASE();

-- answers.question_id — results aggregation hot path
SET @sql = IF(
    (SELECT COUNT(*) FROM information_schema.statistics
        WHERE table_schema = @db AND table_name = 'answers'
        AND index_name = 'idx_answers_question') = 0,
    'CREATE INDEX idx_answers_question ON answers (question_id)',
    'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- answers.participant_id — per-participant history
SET @sql = IF(
    (SELECT COUNT(*) FROM information_schema.statistics
        WHERE table_schema = @db AND table_name = 'answers'
        AND index_name = 'idx_answers_participant') = 0,
    'CREATE INDEX idx_answers_participant ON answers (participant_id)',
    'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- questions.(session_id, status) — find active question hot path
SET @sql = IF(
    (SELECT COUNT(*) FROM information_schema.statistics
        WHERE table_schema = @db AND table_name = 'questions'
        AND index_name = 'idx_questions_session_status') = 0,
    'CREATE INDEX idx_questions_session_status ON questions (session_id, status)',
    'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- answers.is_hidden — moderation filter
SET @sql = IF(
    (SELECT COUNT(*) FROM information_schema.statistics
        WHERE table_schema = @db AND table_name = 'answers'
        AND index_name = 'idx_answers_hidden') = 0,
    'CREATE INDEX idx_answers_hidden ON answers (question_id, is_hidden)',
    'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
