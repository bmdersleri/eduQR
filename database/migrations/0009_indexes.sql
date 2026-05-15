-- Migration: 0009_indexes.sql
-- Phase 8 — Live Results (T-800)
-- Adds secondary / compound indexes per DATA_MODEL.md §4 (hot-path queries).
--
-- All CREATE TABLE statements already include their own inline indexes.
-- This migration uses IF NOT EXISTS guards so it is safe to re-run.

-- answers.question_id — results aggregation hot path
CREATE INDEX IF NOT EXISTS idx_answers_question
    ON answers (question_id);

-- answers.participant_id — per-participant history
CREATE INDEX IF NOT EXISTS idx_answers_participant
    ON answers (participant_id);

-- questions.(session_id, status) — find active question hot path
CREATE INDEX IF NOT EXISTS idx_questions_session_status
    ON questions (session_id, status);

-- answers.is_hidden — moderation filter
CREATE INDEX IF NOT EXISTS idx_answers_hidden
    ON answers (question_id, is_hidden);
