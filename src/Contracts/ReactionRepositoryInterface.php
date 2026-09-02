<?php

declare(strict_types=1);

namespace EduQR\Contracts;

interface ReactionRepositoryInterface
{
    /**
     * Insert a comprehension reaction, replacing any reaction the same
     * participant already holds for the same question (FR-48).
     *
     * Backed by UNIQUE (question_id, participant_id) + ON DUPLICATE KEY UPDATE,
     * so it never creates a second row for the same participant and question.
     */
    public function upsert(
        int    $sessionId,
        int    $questionId,
        int    $participantId,
        string $reaction
    ): void;

    /**
     * Per-question reaction counts for one session.
     * Each row: question_id, got_it, lost
     *
     * Questions with no reactions yet are absent from the result.
     *
     * @return list<array<string,mixed>>
     */
    public function aggregateBySession(int $sessionId): array;
}
