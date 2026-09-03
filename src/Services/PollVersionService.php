<?php

declare(strict_types=1);

namespace EduQR\Services;

use EduQR\Contracts\QuestionRepositoryInterface;
use EduQR\Contracts\SessionRepositoryInterface;
use EduQR\Exceptions\DomainException;
use EduQR\Exceptions\NotFoundException;
use EduQR\Exceptions\ValidationException;

/**
 * What a polled endpoint's answer depends on, cheaply — NFR-76.
 *
 * Five screens re-ask the same five endpoints every two to five seconds, and in
 * a lecture hall most of those answers are byte-for-byte the answer the browser
 * already has. NFR-76 says such a poll is answered `304 Not Modified` *without
 * recomputing aggregates*, which only helps if deciding costs less than
 * answering. So each method here returns an opaque version string built from
 * exactly the columns API_SPEC.md §1.9 names for that endpoint — counts,
 * maximum ids and timestamps — and never assembles the row set the body is
 * made of.
 *
 * Two rules shape the class:
 *
 * 1. **Authorization happens before the version is computed, not after.** Every
 *    method below runs the same guard the service behind the endpoint runs, and
 *    throws the same typed failure. A caller who would be answered 403 or 404
 *    must be answered 403 or 404 whatever `If-None-Match` they send; a `304`
 *    that leaks "this session exists and is unchanged" is still a leak.
 * 2. **No cache.** The version is recomputed per request. A cache would need
 *    invalidation, and invalidation is the problem a version query avoids.
 *
 * The guards duplicate the ones in the services behind the endpoints rather
 * than sharing them, for the reason NFR-82 gives: the split units keep their
 * own copies so that one of them changing its rule does not silently change
 * everyone else's.
 *
 * @requirement NFR-76
 */
final class PollVersionService
{
    public function __construct(
        private readonly SessionRepositoryInterface $sessions,
        private readonly QuestionRepositoryInterface $questions,
    ) {
    }

    // ── GET /api/v1/sessions/{short_code}/active-question ─────────────────────

    /**
     * The session's status and `updated_at`, plus the active question's id,
     * status, `activated_at` and `updated_at` (API_SPEC.md §1.9).
     *
     * No new SQL: both rows are already single-row lookups by short code, and
     * the columns §1.9 names are columns those two lookups return. What the
     * version skips is the third query — the options — and the payload the
     * service assembles from all three.
     *
     * The version is not participant-specific because the body is not. The
     * `already_answered` member is hardcoded `false` today; if it ever becomes
     * the truth, the participant belongs in this string too.
     *
     * @throws DomainException session_not_found | session_closed
     */
    public function activeQuestionVersion(string $shortCode): string
    {
        $session = $this->sessions->findByShortCode($shortCode);

        if ($session === null) {
            throw new NotFoundException('session_not_found');
        }

        if ($session['status'] === 'closed') {
            throw new ValidationException('session_closed', 410);
        }

        $question = $this->questions->findActiveBySessionCode($shortCode);

        return self::join([
            'session',
            (string) $session['status'],
            (string) ($session['updated_at'] ?? ''),
            'question',
            $question === null ? 'none' : (string) (int) $question['id'],
            $question === null ? '' : (string) ($question['status'] ?? ''),
            $question === null ? '' : (string) ($question['activated_at'] ?? ''),
            $question === null ? '' : (string) ($question['updated_at'] ?? ''),
        ]);
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    /**
     * The version string is opaque, so the separator only has to be a character
     * no status or timestamp contains.
     *
     * @param list<string> $parts
     */
    private static function join(array $parts): string
    {
        return implode('|', $parts);
    }
}
