-- Migration 0019: course_instructors join table (FR-97)
-- DATA_MODEL §2.3
--
-- Multi-instructor course ownership. `courses.instructor_id` keeps its meaning
-- (the owner / creator) and is neither dropped nor made nullable. This table
-- lists everyone with access to a course and, after the backfill below, is the
-- single source of truth for course authorization.

CREATE TABLE course_instructors (
    id          BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    course_id   BIGINT UNSIGNED NOT NULL,
    user_id     BIGINT UNSIGNED NOT NULL,
    role        ENUM('owner','co_instructor') NOT NULL,
    created_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uk_course_instructors_course_user (course_id, user_id),
    INDEX idx_course_instructors_course (course_id),
    INDEX idx_course_instructors_user (user_id),
    CONSTRAINT fk_course_instructors_course
        FOREIGN KEY (course_id) REFERENCES courses(id) ON DELETE CASCADE,
    CONSTRAINT fk_course_instructors_user
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Backfill: every existing course gets exactly one owner row, so no course
-- loses access when the authorization checks switch over to this table.
INSERT INTO course_instructors (course_id, user_id, role)
SELECT id, instructor_id, 'owner' FROM courses;
