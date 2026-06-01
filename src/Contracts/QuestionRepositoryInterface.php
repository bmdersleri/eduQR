<?php

declare(strict_types=1);

namespace EduQR\Contracts;

interface QuestionRepositoryInterface
{
    public function create(
        int    $sessionId,
        string $questionText,
        string $questionType,
        bool   $showResults,
        bool   $allowMultipleAnswers,
        string $stage = 'middle'
    ): int;

    public function findById(int $id): ?array;

    public function findBySession(int $sessionId): array;

    /** Returns the currently active question for the given session short_code, or null. */
    public function findActiveBySessionCode(string $shortCode): ?array;

    public function update(int $id, array $fields): void;

    public function delete(int $id): void;

    /**
     * One-active-question rule (FR-33): in one transaction, close any currently
     * active question for $sessionId, then set $id to active.
     */
    public function activate(int $id, int $sessionId): void;

    public function close(int $id): void;

    /** Update order_no for each id in the array; $orderedIds is the desired order. */
    public function reorder(int $sessionId, array $orderedIds): void;
}
