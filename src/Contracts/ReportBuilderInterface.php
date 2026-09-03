<?php

declare(strict_types=1);

namespace EduQR\Contracts;

use EduQR\Exceptions\DomainException;

/**
 * Post-session report assembly (T-900, NFR-82).
 *
 * One method, one document: everything an instructor sees after a session has
 * closed — the session header, the participation summary, one entry per
 * question, and, for a quiz session, the score table.
 *
 * This is the read model behind both the report screen and the course
 * analytics roll-up; it is not the live-results surface, which answers per
 * question while a session is still running.
 */
interface ReportBuilderInterface
{
    /**
     * Builds a complete post-session report.
     *
     * Anonymization replaces every nickname with "Participant N" (FR-70),
     * numbered in the order the report first meets them, so the same person
     * carries the same label across the open-text answers and the score table.
     *
     * @param  bool $anonymize Replace nicknames with "Participant N" (FR-70)
     * @return array<string,mixed>
     * @throws DomainException session_not_found | course_not_found | forbidden
     */
    public function buildReport(int $sessionId, int $userId, bool $anonymize = false): array;
}
