<?php

declare(strict_types=1);

namespace EduQR\Contracts;

interface AnswerRepositoryInterface
{
    /**
     * Insert a new answer row.
     *
     * For option-based types: $selectedOptionId is non-null, $answerText is null.
     * For open_text:          $selectedOptionId is null,     $answerText is non-null.
     *
     * Throws a PDOException whose SQLSTATE is '23000' on duplicate (question_id, participant_id).
     *
     * @return int  New answer ID
     */
    public function insert(
        int     $questionId,
        int     $participantId,
        ?int    $selectedOptionId,
        ?string $answerText
    ): int;

    /** Count accepted answers for a question. */
    public function countByQuestion(int $questionId): int;

    /**
     * Fetch all (non-hidden) answers for a question.
     * Each row: id, participant_id, selected_option_id, answer_text, is_hidden, created_at
     */
    public function fetchByQuestion(int $questionId): array;

    /**
     * Check whether a specific participant has already answered a question.
     */
    public function existsByParticipantAndQuestion(int $participantId, int $questionId): bool;
}
