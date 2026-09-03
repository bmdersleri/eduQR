-- ════════════════════════════════════════════════════════════════════
-- eduQR — Reference Schema (canonical, cumulative)
-- ════════════════════════════════════════════════════════════════════
-- This file reflects the cumulative result of ALL applied migrations.
-- It is for reference. For an existing database, use bin/migrate.php
-- with the files in database/migrations/.
--
-- Engine:    InnoDB
-- Charset:   utf8mb4
-- Collation: utf8mb4_unicode_ci
-- Times:     DATETIME, stored in UTC
-- IDs:       BIGINT UNSIGNED AUTO_INCREMENT
--
-- Table order and definitions below match `mysqldump --no-data` on a database
-- built from database/migrations/*.sql, verified with
-- `bash bin/verify-migrations.sh` (NFR-86). Not shown here, because they are
-- not produced by the migration files: the SET NAMES / time_zone session
-- settings (applied by src/Support/Database.php on connect), the
-- schema_migrations bookkeeping table (created by bin/migrate.php itself),
-- and the 'en'/'tr' locale seed rows (inserted by migration 0001).
--
-- Keep this file in sync with DATA_MODEL.md and database/migrations/.
-- ════════════════════════════════════════════════════════════════════

-- ─────────────────────────────────────────────────────────────
-- answers  —  one participant's submission for one question
-- ─────────────────────────────────────────────────────────────
CREATE TABLE `answers` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `question_id` bigint unsigned NOT NULL,
  `participant_id` bigint unsigned NOT NULL,
  `selected_option_id` bigint unsigned DEFAULT NULL,
  `answer_text` text COLLATE utf8mb4_unicode_ci,
  `is_hidden` tinyint(1) NOT NULL DEFAULT '0',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_answers_question_participant` (`question_id`,`participant_id`),
  KEY `idx_answers_question` (`question_id`),
  KEY `idx_answers_participant` (`participant_id`),
  KEY `fk_answers_option` (`selected_option_id`),
  KEY `idx_answers_hidden` (`question_id`,`is_hidden`),
  CONSTRAINT `fk_answers_option` FOREIGN KEY (`selected_option_id`) REFERENCES `options` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_answers_participant` FOREIGN KEY (`participant_id`) REFERENCES `participants` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_answers_question` FOREIGN KEY (`question_id`) REFERENCES `questions` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
-- NOTE: exactly one of (selected_option_id, answer_text) is populated,
-- enforced at the application layer in AnswerService::validateAnswerShape().
-- The UNIQUE (question_id, participant_id) index enforces FR-44 while
-- questions.allow_multiple_answers = 0 (the MVP default).

-- ─────────────────────────────────────────────────────────────
-- audit_logs  —  record of important system actions (FR-90)
-- ─────────────────────────────────────────────────────────────
CREATE TABLE `audit_logs` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `actor_type` enum('instructor','admin','system') COLLATE utf8mb4_unicode_ci NOT NULL,
  `actor_id` bigint unsigned DEFAULT NULL,
  `action` varchar(80) COLLATE utf8mb4_unicode_ci NOT NULL,
  `entity_type` varchar(40) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `entity_id` bigint unsigned DEFAULT NULL,
  `metadata_json` json DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_audit_actor` (`actor_type`,`actor_id`),
  KEY `idx_audit_action` (`action`,`created_at`),
  KEY `idx_audit_entity` (`entity_type`,`entity_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─────────────────────────────────────────────────────────────
-- course_instructors  —  who may access a course (FR-97)
-- courses.instructor_id stays the owner; this table is the
-- single source of truth for course authorization.
-- ─────────────────────────────────────────────────────────────
CREATE TABLE `course_instructors` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `course_id` bigint unsigned NOT NULL,
  `user_id` bigint unsigned NOT NULL,
  `role` enum('owner','co_instructor') COLLATE utf8mb4_unicode_ci NOT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_course_instructors_course_user` (`course_id`,`user_id`),
  KEY `idx_course_instructors_course` (`course_id`),
  KEY `idx_course_instructors_user` (`user_id`),
  CONSTRAINT `fk_course_instructors_course` FOREIGN KEY (`course_id`) REFERENCES `courses` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_course_instructors_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─────────────────────────────────────────────────────────────
-- courses  —  a course owned by one instructor
-- ─────────────────────────────────────────────────────────────
CREATE TABLE `courses` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `instructor_id` bigint unsigned NOT NULL,
  `title` varchar(200) COLLATE utf8mb4_unicode_ci NOT NULL,
  `code` varchar(40) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `semester` varchar(40) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `description` text COLLATE utf8mb4_unicode_ci,
  `default_language` varchar(8) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'en',
  `status` enum('active','archived') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'active',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_courses_instructor` (`instructor_id`),
  KEY `idx_courses_status` (`status`),
  CONSTRAINT `fk_courses_instructor` FOREIGN KEY (`instructor_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─────────────────────────────────────────────────────────────
-- locales  —  metadata for the language switcher
-- (translation strings live in /locales/<code>.json, NOT here)
-- Baseline 'en' and 'tr' rows are seeded by migration 0001, not here —
-- this file describes structure only (NFR-86).
-- ─────────────────────────────────────────────────────────────
CREATE TABLE `locales` (
  `code` varchar(8) COLLATE utf8mb4_unicode_ci NOT NULL,
  `label_native` varchar(40) COLLATE utf8mb4_unicode_ci NOT NULL,
  `label_english` varchar(40) COLLATE utf8mb4_unicode_ci NOT NULL,
  `is_rtl` tinyint(1) NOT NULL DEFAULT '0',
  `is_active` tinyint(1) NOT NULL DEFAULT '1',
  `sort_order` int NOT NULL DEFAULT '0',
  PRIMARY KEY (`code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─────────────────────────────────────────────────────────────
-- login_attempts  —  failed-login rate limiting (FR-05)
-- ─────────────────────────────────────────────────────────────
CREATE TABLE `login_attempts` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `email` varchar(190) COLLATE utf8mb4_unicode_ci NOT NULL,
  `ip_hash` char(64) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `succeeded` tinyint(1) NOT NULL DEFAULT '0',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_attempts_email_time` (`email`,`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─────────────────────────────────────────────────────────────
-- options  —  choices for option-based question types
-- ─────────────────────────────────────────────────────────────
CREATE TABLE `options` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `question_id` bigint unsigned NOT NULL,
  `option_text` varchar(200) COLLATE utf8mb4_unicode_ci NOT NULL,
  `option_value` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `is_correct` tinyint(1) NOT NULL DEFAULT '0',
  `order_no` int NOT NULL DEFAULT '0',
  PRIMARY KEY (`id`),
  KEY `idx_options_question` (`question_id`),
  CONSTRAINT `fk_options_question` FOREIGN KEY (`question_id`) REFERENCES `questions` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─────────────────────────────────────────────────────────────
-- participants  —  anonymous nickname-based students
-- ─────────────────────────────────────────────────────────────
CREATE TABLE `participants` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `session_id` bigint unsigned NOT NULL,
  `nickname` varchar(48) COLLATE utf8mb4_unicode_ci NOT NULL,
  `nickname_normalized` varchar(48) COLLATE utf8mb4_unicode_ci NOT NULL,
  `device_hash` char(64) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `is_approved` tinyint(1) NOT NULL DEFAULT '1',
  `joined_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_participants_nickname` (`session_id`,`nickname_normalized`),
  KEY `idx_participants_session` (`session_id`),
  CONSTRAINT `fk_participants_session` FOREIGN KEY (`session_id`) REFERENCES `sessions` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─────────────────────────────────────────────────────────────
-- password_resets  —  email-based instructor password reset (FR-06)
-- ─────────────────────────────────────────────────────────────
CREATE TABLE `password_resets` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `user_id` bigint unsigned NOT NULL,
  `email` varchar(190) COLLATE utf8mb4_unicode_ci NOT NULL,
  `token_hash` char(64) COLLATE utf8mb4_unicode_ci NOT NULL,
  `expires_at` datetime NOT NULL,
  `used_at` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `token_hash` (`token_hash`),
  KEY `idx_password_resets_user` (`user_id`),
  KEY `idx_password_resets_email` (`email`),
  KEY `idx_password_resets_expires_at` (`expires_at`),
  CONSTRAINT `fk_password_resets_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─────────────────────────────────────────────────────────────
-- question_bank_items  —  reusable course-scoped question payloads
-- ─────────────────────────────────────────────────────────────
CREATE TABLE `question_bank_items` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `course_id` bigint unsigned NOT NULL,
  `created_by_user_id` bigint unsigned NOT NULL,
  `source_kind` enum('session_question','lecture_notes') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'session_question',
  `source_title` varchar(200) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `payload_json` json NOT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_question_bank_course` (`course_id`),
  KEY `idx_question_bank_creator` (`created_by_user_id`),
  KEY `idx_question_bank_course_source` (`course_id`,`source_kind`),
  CONSTRAINT `fk_question_bank_course` FOREIGN KEY (`course_id`) REFERENCES `courses` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_question_bank_creator` FOREIGN KEY (`created_by_user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─────────────────────────────────────────────────────────────
-- question_reactions  —  one participant's comprehension signal
--                        for one question (FR-48)
-- ─────────────────────────────────────────────────────────────
CREATE TABLE `question_reactions` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `session_id` bigint unsigned NOT NULL,
  `question_id` bigint unsigned NOT NULL,
  `participant_id` bigint unsigned NOT NULL,
  `reaction` enum('got_it','lost') COLLATE utf8mb4_unicode_ci NOT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_question_reactions_question_participant` (`question_id`,`participant_id`),
  KEY `idx_question_reactions_session` (`session_id`),
  KEY `idx_question_reactions_question` (`question_id`),
  KEY `fk_question_reactions_participant` (`participant_id`),
  CONSTRAINT `fk_question_reactions_participant` FOREIGN KEY (`participant_id`) REFERENCES `participants` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_question_reactions_question` FOREIGN KEY (`question_id`) REFERENCES `questions` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_question_reactions_session` FOREIGN KEY (`session_id`) REFERENCES `sessions` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
-- NOTE: the UNIQUE (question_id, participant_id) index enforces FR-48's
-- "at most one reaction per question"; re-reacting is an upsert that
-- replaces the stored value. Aggregates are instructor-only.

-- ─────────────────────────────────────────────────────────────
-- questions  —  a poll item within a session
-- ─────────────────────────────────────────────────────────────
CREATE TABLE `questions` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `session_id` bigint unsigned NOT NULL,
  `question_text` text COLLATE utf8mb4_unicode_ci NOT NULL,
  `image_path` varchar(500) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'Relative path under public/uploads/questions/, e.g. questions/42_abc123.jpg',
  `question_type` enum('multiple_choice','open_text','yes_no','likert_5','fill_in_the_blank') COLLATE utf8mb4_unicode_ci NOT NULL,
  `stage` enum('opening','middle','closing') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'middle',
  `status` enum('draft','active','closed') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'draft',
  `order_no` int NOT NULL DEFAULT '0',
  `allow_multiple_answers` tinyint(1) NOT NULL DEFAULT '0',
  `show_results` tinyint(1) NOT NULL DEFAULT '0',
  `activated_at` datetime DEFAULT NULL,
  `closed_at` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_questions_session` (`session_id`),
  KEY `idx_questions_session_status` (`session_id`,`status`),
  KEY `idx_questions_session_stage_order` (`session_id`,`stage`,`order_no`),
  CONSTRAINT `fk_questions_session` FOREIGN KEY (`session_id`) REFERENCES `sessions` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
-- NOTE: the "one active question per session" rule (FR-33) is enforced
-- at the application layer in QuestionService::activate().

-- ─────────────────────────────────────────────────────────────
-- sessions  —  a live classroom session (NOT a PHP HTTP session)
-- ─────────────────────────────────────────────────────────────
CREATE TABLE `sessions` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `course_id` bigint unsigned NOT NULL,
  `title` varchar(200) COLLATE utf8mb4_unicode_ci NOT NULL,
  `topic_name` varchar(200) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `short_code` varchar(8) COLLATE utf8mb4_unicode_ci NOT NULL,
  `status` enum('draft','active','paused','closed') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'active',
  `language` varchar(8) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'en',
  `allow_anonymous` tinyint(1) NOT NULL DEFAULT '1',
  `show_results_to_students` tinyint(1) NOT NULL DEFAULT '0',
  `moderation_mode` tinyint(1) NOT NULL DEFAULT '0',
  `exam_mode` tinyint(1) NOT NULL DEFAULT '0',
  `is_quiz` tinyint(1) NOT NULL DEFAULT '0',
  `started_at` datetime DEFAULT NULL,
  `paused_at` datetime DEFAULT NULL,
  `closed_at` datetime DEFAULT NULL,
  `delete_requested_at` datetime DEFAULT NULL,
  `anonymized` tinyint(1) NOT NULL DEFAULT '0',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `short_code` (`short_code`),
  KEY `idx_sessions_course` (`course_id`),
  KEY `idx_sessions_status` (`status`),
  KEY `idx_sessions_short_code` (`short_code`),
  KEY `idx_sessions_delete_requested` (`delete_requested_at`),
  CONSTRAINT `fk_sessions_course` FOREIGN KEY (`course_id`) REFERENCES `courses` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─────────────────────────────────────────────────────────────
-- users  —  instructor and admin accounts (NOT students)
-- ─────────────────────────────────────────────────────────────
CREATE TABLE `users` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `email` varchar(190) COLLATE utf8mb4_unicode_ci NOT NULL,
  `password_hash` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `display_name` varchar(150) COLLATE utf8mb4_unicode_ci NOT NULL,
  `role` enum('admin','instructor') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'instructor',
  `preferred_language` varchar(8) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'en',
  `is_active` tinyint(1) NOT NULL DEFAULT '1',
  `last_login_at` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `email` (`email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
