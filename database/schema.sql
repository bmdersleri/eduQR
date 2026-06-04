-- ════════════════════════════════════════════════════════════════════
-- eduQR — Reference Schema (canonical, cumulative)
-- ════════════════════════════════════════════════════════════════════
-- This file reflects the cumulative result of ALL applied migrations.
-- It is for reference and fresh installs. For an existing database,
-- use bin/migrate.php with the files in database/migrations/.
--
-- Engine:    InnoDB
-- Charset:   utf8mb4
-- Collation: utf8mb4_unicode_ci
-- Times:     DATETIME, stored in UTC
-- IDs:       BIGINT UNSIGNED AUTO_INCREMENT
--
-- Keep this file in sync with DATA_MODEL.md and database/migrations/.
-- ════════════════════════════════════════════════════════════════════

SET NAMES utf8mb4;
SET time_zone = '+00:00';

-- ─────────────────────────────────────────────────────────────
-- users  —  instructor and admin accounts (NOT students)
-- ─────────────────────────────────────────────────────────────
CREATE TABLE users (
    id                  BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    email               VARCHAR(190) NOT NULL UNIQUE,
    password_hash       VARCHAR(255) NOT NULL,
    display_name        VARCHAR(150) NOT NULL,
    role                ENUM('admin','instructor') NOT NULL DEFAULT 'instructor',
    preferred_language  VARCHAR(8)   NOT NULL DEFAULT 'en',
    is_active           TINYINT(1)   NOT NULL DEFAULT 1,
    last_login_at       DATETIME     NULL,
    created_at          DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at          DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP
                                              ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─────────────────────────────────────────────────────────────
-- courses  —  a course owned by one instructor
-- ─────────────────────────────────────────────────────────────
CREATE TABLE courses (
    id                BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    instructor_id     BIGINT UNSIGNED NOT NULL,
    title             VARCHAR(200) NOT NULL,
    code              VARCHAR(40)  NULL,
    semester          VARCHAR(40)  NULL,
    description       TEXT         NULL,
    default_language  VARCHAR(8)   NOT NULL DEFAULT 'en',
    status            ENUM('active','archived') NOT NULL DEFAULT 'active',
    created_at        DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at        DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP
                                            ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_courses_instructor (instructor_id),
    INDEX idx_courses_status (status),
    CONSTRAINT fk_courses_instructor
        FOREIGN KEY (instructor_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─────────────────────────────────────────────────────────────
-- sessions  —  a live classroom session (NOT a PHP HTTP session)
-- ─────────────────────────────────────────────────────────────
CREATE TABLE sessions (
    id                        BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    course_id                 BIGINT UNSIGNED NOT NULL,
    title                     VARCHAR(200) NOT NULL,
    topic_name                VARCHAR(200) NULL,
    short_code                VARCHAR(8)   NOT NULL UNIQUE,
    status                    ENUM('draft','active','paused','closed') NOT NULL DEFAULT 'active',
    language                  VARCHAR(8)   NOT NULL DEFAULT 'en',
    allow_anonymous           TINYINT(1)   NOT NULL DEFAULT 1,
    show_results_to_students  TINYINT(1)   NOT NULL DEFAULT 0,
    moderation_mode           TINYINT(1)   NOT NULL DEFAULT 0,
    is_quiz                   TINYINT(1)   NOT NULL DEFAULT 0,
    started_at                DATETIME     NULL,
    paused_at                 DATETIME     NULL,
    closed_at                 DATETIME     NULL,
    delete_requested_at       DATETIME     NULL,
    anonymized                TINYINT(1)   NOT NULL DEFAULT 0,
    created_at                DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at                DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP
                                                    ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_sessions_course (course_id),
    INDEX idx_sessions_status (status),
    INDEX idx_sessions_delete_requested (delete_requested_at),
    CONSTRAINT fk_sessions_course
        FOREIGN KEY (course_id) REFERENCES courses(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─────────────────────────────────────────────────────────────
-- questions  —  a poll item within a session
-- ─────────────────────────────────────────────────────────────
CREATE TABLE questions (
    id                      BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    session_id              BIGINT UNSIGNED NOT NULL,
    question_text           TEXT         NOT NULL,
    image_path              VARCHAR(500) NULL DEFAULT NULL,
    question_type           ENUM('multiple_choice','open_text','yes_no','likert_5') NOT NULL,
    stage                   ENUM('opening','middle','closing') NOT NULL DEFAULT 'middle',
    status                  ENUM('draft','active','closed') NOT NULL DEFAULT 'draft',
    order_no                INT          NOT NULL DEFAULT 0,
    allow_multiple_answers  TINYINT(1)   NOT NULL DEFAULT 0,
    show_results            TINYINT(1)   NOT NULL DEFAULT 0,
    activated_at            DATETIME     NULL,
    closed_at               DATETIME     NULL,
    created_at              DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at              DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP
                                                  ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_questions_session (session_id),
    INDEX idx_questions_session_status (session_id, status),
    INDEX idx_questions_session_stage_order (session_id, stage, order_no),
    CONSTRAINT fk_questions_session
        FOREIGN KEY (session_id) REFERENCES sessions(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
-- NOTE: the "one active question per session" rule (FR-33) is enforced
-- at the application layer in QuestionService::activate().

-- ─────────────────────────────────────────────────────────────
-- question_bank_items  —  reusable course-scoped question payloads
-- ─────────────────────────────────────────────────────────────
CREATE TABLE question_bank_items (
    id                 BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    course_id          BIGINT UNSIGNED NOT NULL,
    created_by_user_id BIGINT UNSIGNED NOT NULL,
    source_kind        ENUM('session_question','lecture_notes') NOT NULL DEFAULT 'session_question',
    source_title       VARCHAR(200) NULL,
    payload_json       JSON NOT NULL,
    created_at         DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at         DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_question_bank_course (course_id),
    INDEX idx_question_bank_creator (created_by_user_id),
    INDEX idx_question_bank_course_source (course_id, source_kind),
    CONSTRAINT fk_question_bank_course
        FOREIGN KEY (course_id) REFERENCES courses(id) ON DELETE CASCADE,
    CONSTRAINT fk_question_bank_creator
        FOREIGN KEY (created_by_user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─────────────────────────────────────────────────────────────
-- options  —  choices for option-based question types
-- ─────────────────────────────────────────────────────────────
CREATE TABLE options (
    id            BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    question_id   BIGINT UNSIGNED NOT NULL,
    option_text   VARCHAR(200) NOT NULL,
    option_value  VARCHAR(100) NULL,
    is_correct    TINYINT(1)   NOT NULL DEFAULT 0,
    order_no      INT          NOT NULL DEFAULT 0,
    INDEX idx_options_question (question_id),
    CONSTRAINT fk_options_question
        FOREIGN KEY (question_id) REFERENCES questions(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─────────────────────────────────────────────────────────────
-- participants  —  anonymous nickname-based students
-- ─────────────────────────────────────────────────────────────
CREATE TABLE participants (
    id                    BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    session_id            BIGINT UNSIGNED NOT NULL,
    nickname              VARCHAR(48)  NOT NULL,
    nickname_normalized   VARCHAR(48)  NOT NULL,
    device_hash           CHAR(64)     NULL,
    is_approved           TINYINT(1)   NOT NULL DEFAULT 1,
    joined_at             DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_participants_session (session_id),
    UNIQUE KEY uk_participants_nickname (session_id, nickname_normalized),
    CONSTRAINT fk_participants_session
        FOREIGN KEY (session_id) REFERENCES sessions(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─────────────────────────────────────────────────────────────
-- answers  —  one participant's submission for one question
-- ─────────────────────────────────────────────────────────────
CREATE TABLE answers (
    id                  BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    question_id         BIGINT UNSIGNED NOT NULL,
    participant_id      BIGINT UNSIGNED NOT NULL,
    selected_option_id  BIGINT UNSIGNED NULL,
    answer_text         TEXT         NULL,
    is_hidden           TINYINT(1)   NOT NULL DEFAULT 0,
    created_at          DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uk_answers_question_participant (question_id, participant_id),
    INDEX idx_answers_question (question_id),
    INDEX idx_answers_participant (participant_id),
    CONSTRAINT fk_answers_question
        FOREIGN KEY (question_id) REFERENCES questions(id) ON DELETE CASCADE,
    CONSTRAINT fk_answers_participant
        FOREIGN KEY (participant_id) REFERENCES participants(id) ON DELETE CASCADE,
    CONSTRAINT fk_answers_option
        FOREIGN KEY (selected_option_id) REFERENCES options(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
-- NOTE: exactly one of (selected_option_id, answer_text) is populated,
-- enforced at the application layer in AnswerService::validateAnswerShape().
-- The UNIQUE (question_id, participant_id) index enforces FR-44 while
-- questions.allow_multiple_answers = 0 (the MVP default).

-- ─────────────────────────────────────────────────────────────
-- audit_logs  —  record of important system actions (FR-90)
-- ─────────────────────────────────────────────────────────────
CREATE TABLE audit_logs (
    id            BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    actor_type    ENUM('instructor','admin','system') NOT NULL,
    actor_id      BIGINT UNSIGNED NULL,
    action        VARCHAR(80)  NOT NULL,
    entity_type   VARCHAR(40)  NULL,
    entity_id     BIGINT UNSIGNED NULL,
    metadata_json JSON         NULL,
    created_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_audit_actor (actor_type, actor_id),
    INDEX idx_audit_action (action, created_at),
    INDEX idx_audit_entity (entity_type, entity_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─────────────────────────────────────────────────────────────
-- login_attempts  —  failed-login rate limiting (FR-05)
-- ─────────────────────────────────────────────────────────────
CREATE TABLE login_attempts (
    id          BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    email       VARCHAR(190) NOT NULL,
    ip_hash     CHAR(64)     NULL,
    succeeded   TINYINT(1)   NOT NULL DEFAULT 0,
    created_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_attempts_email_time (email, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─────────────────────────────────────────────────────────────
-- password_resets  —  email-based instructor password reset (FR-06)
-- ─────────────────────────────────────────────────────────────
CREATE TABLE password_resets (
    id          BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id     BIGINT UNSIGNED NOT NULL,
    email       VARCHAR(190) NOT NULL,
    token_hash  CHAR(64) NOT NULL UNIQUE,
    expires_at  DATETIME NOT NULL,
    used_at     DATETIME NULL,
    created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_password_resets_user (user_id),
    INDEX idx_password_resets_email (email),
    INDEX idx_password_resets_expires_at (expires_at),
    CONSTRAINT fk_password_resets_user
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─────────────────────────────────────────────────────────────
-- locales  —  metadata for the language switcher
-- (translation strings live in /locales/<code>.json, NOT here)
-- ─────────────────────────────────────────────────────────────
CREATE TABLE locales (
    code           VARCHAR(8)  PRIMARY KEY,
    label_native   VARCHAR(40) NOT NULL,
    label_english  VARCHAR(40) NOT NULL,
    is_rtl         TINYINT(1)  NOT NULL DEFAULT 0,
    is_active      TINYINT(1)  NOT NULL DEFAULT 1,
    sort_order     INT         NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─────────────────────────────────────────────────────────────
-- schema_migrations  —  tracks applied migration files
-- ─────────────────────────────────────────────────────────────
CREATE TABLE schema_migrations (
    filename    VARCHAR(120) PRIMARY KEY,
    applied_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─────────────────────────────────────────────────────────────
-- Baseline locale rows (en + tr ship with the MVP)
-- ─────────────────────────────────────────────────────────────
INSERT INTO locales (code, label_native, label_english, is_rtl, is_active, sort_order) VALUES
('en', 'English', 'English', 0, 1, 1),
('tr', 'Türkçe',  'Turkish', 0, 1, 2);
