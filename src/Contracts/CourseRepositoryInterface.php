<?php

declare(strict_types=1);

namespace EduQR\Contracts;

interface CourseRepositoryInterface
{
    public function findById(int $id): ?array;

    /** Returns rows $instructorId owns or co-instructs, newest first (FR-97). */
    public function listByInstructor(int $instructorId, int $page, int $perPage): array;

    /** Counts rows $instructorId owns or co-instructs (FR-97). */
    public function countByInstructor(int $instructorId): int;

    /** Creates the course and its owner row in one transaction (FR-97). */
    public function create(
        int     $instructorId,
        string  $title,
        ?string $code,
        ?string $semester,
        ?string $description,
        string  $defaultLanguage
    ): int;

    /** $fields is a subset of the updatable columns. */
    public function update(int $id, array $fields): void;

    public function archive(int $id): void;

    public function restore(int $id): void;

    // ── Course instructors (FR-97) ─────────────────────────────────────────────

    /**
     * The user's role on the course: 'owner', 'co_instructor', or null when the
     * user has no access. This is the single authorization primitive; every
     * course-derived permission check in the application goes through it.
     *
     * A user whose users.role is 'admin' resolves to 'co_instructor' on every
     * course they are not listed on, so an admin can read and author everywhere
     * but never passes an owner-only check (FR-99).
     */
    public function roleFor(int $courseId, int $userId): ?string;

    /**
     * Everyone with access to the course, owner first.
     *
     * @return list<array{user_id:int,email:string,display_name:string,role:string,created_at:string}>
     */
    public function listInstructors(int $courseId): array;

    /** Grants $userId the given role on the course. */
    public function addInstructor(int $courseId, int $userId, string $role): void;

    /** Revokes access; returns false when the user held none. */
    public function removeInstructor(int $courseId, int $userId): bool;
}
