<?php

declare(strict_types=1);

namespace EduQR\Contracts;

interface ParticipantRepositoryInterface
{
    public function register(
        int    $sessionId,
        string $nickname,
        string $nicknameNormalized,
        ?string $deviceHash,
    ): int;

    public function existsByNicknameNormalized(int $sessionId, string $nicknameNormalized): bool;

    public function countBySession(int $sessionId): int;

    public function findBySession(int $sessionId): array;

    public function findById(int $id): ?array;
}
