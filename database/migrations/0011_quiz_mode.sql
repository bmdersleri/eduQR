-- Migration: 0011_quiz_mode.sql
-- T-1104: Quiz mode with scoring [FR-92]

SET @q = IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='sessions' AND COLUMN_NAME='is_quiz')=0, 'ALTER TABLE sessions ADD COLUMN is_quiz TINYINT(1) NOT NULL DEFAULT 0 AFTER moderation_mode', 'SELECT 1');
PREPARE stmt FROM @q; EXECUTE stmt; DEALLOCATE PREPARE stmt;
