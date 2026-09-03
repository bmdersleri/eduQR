<?php

declare(strict_types=1);

namespace EduQR\Contracts;

/**
 * Quiz score computation (FR-92, NFR-82).
 *
 * The unit that decides what an answer is worth, and nothing else. It reads
 * participants, answers and options directly and never renders, exports or
 * authorises: a caller has already established that the user may see the
 * session before it asks for a score.
 *
 * No method here throws a domain error. A session that does not exist, or that
 * has no participants, is an empty result rather than a failure — the caller
 * that had to look the session up has already reported a missing one.
 */
interface ScoringServiceInterface
{
    /**
     * Every participant of the session with their score and rank.
     *
     * Highest score first; ties keep participant-id order so the ranking is
     * stable, and tied participants share a rank. Returns an empty list when
     * the session has no participants.
     *
     * @return array<int,array{participant_id:int,nickname:string,score:int,rank:int}>
     */
    public function computeScores(int $sessionId): array;

    /**
     * Attainable score: the number of questions in the session carrying at least
     * one is_correct option, matching how computeScores() awards points (FR-92).
     */
    public function maxScore(int $sessionId): int;

    /**
     * Every is_correct option of every question in the session.
     *
     * @return array<int,array<int,array{id:int,text:string}>> keyed by question id
     */
    public function correctOptions(int $sessionId): array;
}
