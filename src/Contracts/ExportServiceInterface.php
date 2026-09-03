<?php

declare(strict_types=1);

namespace EduQR\Contracts;

use EduQR\Exceptions\DomainException;

/**
 * External-format exports — Moodle GIFT questions and gradebook rows (FR-98,
 * NFR-82).
 *
 * eduQR never talks to an LMS: both methods build a file the instructor
 * uploads themselves. Access is the same course rule every other report export
 * uses (FR-97), and exam_mode (FR-96) gates the student result path only — it
 * never restricts the instructor's own export.
 */
interface ExportServiceInterface
{
    /**
     * Builds a Moodle GIFT question export for one session.
     *
     * The file carries no participant data at all. A question that cannot be
     * expressed as a valid GIFT item — a multiple choice with no correct answer
     * — is downgraded to an essay rather than emitted broken, and counted in
     * downgraded_count.
     *
     * @requirement FR-98
     * @return array{session_id:int,gift:string,question_count:int,downgraded_count:int}
     * @throws DomainException session_not_found | course_not_found | forbidden
     */
    public function buildGiftExport(int $sessionId, int $userId): array;

    /**
     * Builds gradebook rows for one session: one row per participant with the
     * quiz score (FR-92), the attainable maximum and the percentage.
     *
     * Anonymization behaves exactly as the session report does: a session
     * already anonymized in storage (FR-70) is anonymous regardless of the flag.
     *
     * @requirement FR-98
     * @return array{session_id:int,max_score:int,rows:array<int,array{nickname:string,score:int,max_score:int,percentage:float}>}
     * @throws DomainException session_not_found | course_not_found | forbidden
     */
    public function buildGradebook(int $sessionId, int $userId, bool $anonymize = false): array;
}
