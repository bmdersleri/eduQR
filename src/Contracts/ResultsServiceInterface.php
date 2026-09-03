<?php

declare(strict_types=1);

namespace EduQR\Contracts;

use EduQR\Exceptions\DomainException;

/**
 * Live results while a session is running — T-801 … T-804, FR-65, NFR-82.
 *
 * The instructor view and the student view of the same rows. They differ in
 * two ways and only two: the instructor sees hidden answers, and the student
 * path is gated by exam_mode (FR-96) and the show_results flags (T-804).
 */
interface ResultsServiceInterface
{
    /**
     * Returns aggregated results that an instructor can always see.
     *
     * @return array<int,array<string,mixed>>
     * @throws DomainException session_not_found | course_not_found | forbidden
     *                         | question_not_found
     */
    public function getResults(int $sessionId, int $userId, ?int $questionId): array;

    /**
     * Returns results only when the session AND question both allow it.
     *
     * @return array<int,array<string,mixed>>
     * @throws DomainException session_not_found | results_hidden | question_not_found
     */
    public function getStudentResults(string $shortCode, ?int $questionId): array;

    /**
     * Returns answer counts and percentages for option-based questions,
     * or a list of text answers for open_text questions.
     *
     * @return array<string,mixed>
     * @throws DomainException question_not_found
     */
    public function aggregate(int $questionId): array;

    /**
     * Returns open-text answers for a question. The instructor form
     * ($includeHidden = true) carries the hidden ones with their flag; the
     * student form leaves them out.
     *
     * @return array<int,array<string,mixed>>
     */
    public function openTextAnswers(int $questionId, bool $includeHidden = false): array;

    /**
     * Builds a set of AI-assisted themes from the visible open-text answers of
     * a question.
     *
     * @requirement FR-65
     * @return array<string,mixed>
     * @throws DomainException question_not_found | question_not_open_text | forbidden
     *                         | invalid_llm_response (422) | llm_unavailable (503)
     */
    public function extractThemes(int $questionId, int $userId): array;
}
