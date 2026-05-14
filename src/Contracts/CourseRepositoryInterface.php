<?php

declare(strict_types=1);

namespace EduQR\Contracts;

interface CourseRepositoryInterface
{
    public function findById(int $id): ?array;

    /** Returns rows belonging to $instructorId, newest first. */
    public function listByInstructor(int $instructorId, int $page, int $perPage): array;

    public function countByInstructor(int $instructorId): int;

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
}
