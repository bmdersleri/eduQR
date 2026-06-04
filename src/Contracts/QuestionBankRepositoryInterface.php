<?php

declare(strict_types=1);

namespace EduQR\Contracts;

interface QuestionBankRepositoryInterface
{
    /**
     * @param array<string,mixed> $payload
     */
    public function create(int $courseId, int $userId, string $sourceKind, array $payload, ?string $sourceTitle = null): int;

    /** @return array<string,mixed>|null */
    public function findById(int $id): ?array;

    /** @return array<int,array<string,mixed>> */
    public function findByCourse(int $courseId): array;

    /** @return array<int,array<string,mixed>> */
    public function findByIds(int $courseId, array $ids): array;
}
