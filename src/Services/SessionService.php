<?php

declare(strict_types=1);

namespace EduQR\Services;

use EduQR\Config;
use EduQR\Contracts\CourseRepositoryInterface;
use EduQR\Contracts\SessionRepositoryInterface;
use EduQR\Support\ShortCode;

final class SessionService
{
    private const MAX_TITLE_LEN    = 200;
    private const ALLOWED_LANGS    = ['en', 'tr'];
    private const MAX_CODE_RETRIES = 10;

    public function __construct(
        private readonly SessionRepositoryInterface $sessions,
        private readonly CourseRepositoryInterface  $courses,
    ) {}

    // ── Create ─────────────────────────────────────────────────────────────────

    public function createSession(int $courseId, int $instructorId, array $data): array
    {
        $course = $this->courses->findById($courseId);
        if ($course === null) {
            throw new \RuntimeException('course_not_found');
        }
        if ((int) $course['instructor_id'] !== $instructorId) {
            throw new \RuntimeException('forbidden');
        }

        $title    = $this->validateTitle($data['title'] ?? null);
        $language = $this->validateLanguage($data['language'] ?? $course['default_language']);

        $shortCode = $this->generateUniqueCode();

        $id = $this->sessions->create($courseId, $title, $shortCode, $language);

        $appUrl  = rtrim(Config::get('APP_URL', ''), '/');
        $joinUrl = $appUrl . '/join/' . $shortCode;

        return [
            'id'         => $id,
            'short_code' => $shortCode,
            'join_url'   => $joinUrl,
            'qr_url'     => '/api/v1/sessions/' . $id . '/qr.png',
            'status'     => 'active',
        ];
    }

    // ── Read ───────────────────────────────────────────────────────────────────

    public function getSession(int $id, int $instructorId): array
    {
        $session = $this->sessions->findById($id);
        if ($session === null) {
            throw new \RuntimeException('session_not_found');
        }
        $course = $this->courses->findById((int) $session['course_id']);
        if ($course === null || (int) $course['instructor_id'] !== $instructorId) {
            throw new \RuntimeException('forbidden');
        }
        return $session;
    }

    public function resolveByShortCode(string $code): array
    {
        $code    = strtoupper(trim($code));
        $session = $this->sessions->findByShortCode($code);
        if ($session === null) {
            throw new \RuntimeException('session_not_found');
        }
        if ($session['status'] === 'closed') {
            throw new \RuntimeException('session_closed');
        }

        $course  = $this->courses->findById((int) $session['course_id']);
        $appUrl  = rtrim(Config::get('APP_URL', ''), '/');
        $joinUrl = $appUrl . '/join/' . $session['short_code'];

        return [
            'short_code'   => $session['short_code'],
            'title'        => $session['title'],
            'course_title' => $course['title'] ?? '',
            'status'       => $session['status'],
            'language'     => $session['language'],
            'join_url'     => $joinUrl,
        ];
    }

    // ── Update ─────────────────────────────────────────────────────────────────

    public function updateSession(int $id, int $instructorId, array $data): void
    {
        $this->getSession($id, $instructorId);

        $fields = [];

        if (isset($data['title'])) {
            $fields['title'] = $this->validateTitle($data['title']);
        }
        if (array_key_exists('show_results_to_students', $data)) {
            $fields['show_results_to_students'] = $data['show_results_to_students'] ? 1 : 0;
        }
        if (array_key_exists('moderation_mode', $data)) {
            $fields['moderation_mode'] = $data['moderation_mode'] ? 1 : 0;
        }

        if (!empty($fields)) {
            $this->sessions->update($id, $fields);
        }
    }

    // ── State transitions ──────────────────────────────────────────────────────

    public function pauseSession(int $id, int $instructorId): void
    {
        $session = $this->getSession($id, $instructorId);
        if ($session['status'] !== 'active') {
            throw new \RuntimeException('invalid_state_transition');
        }
        $this->sessions->update($id, [
            'status'    => 'paused',
            'paused_at' => (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d H:i:s'),
        ]);
    }

    public function resumeSession(int $id, int $instructorId): void
    {
        $session = $this->getSession($id, $instructorId);
        if ($session['status'] !== 'paused') {
            throw new \RuntimeException('invalid_state_transition');
        }
        $this->sessions->update($id, [
            'status'    => 'active',
            'paused_at' => null,
        ]);
    }

    public function closeSession(int $id, int $instructorId): void
    {
        $session = $this->getSession($id, $instructorId);
        if ($session['status'] === 'closed') {
            throw new \RuntimeException('invalid_state_transition');
        }
        $this->sessions->update($id, [
            'status'    => 'closed',
            'closed_at' => (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d H:i:s'),
        ]);
    }

    // ── Participant count (data available after Phase 5) ───────────────────────

    public function getParticipantCount(int $id, int $instructorId): int
    {
        $this->getSession($id, $instructorId);
        return $this->sessions->countParticipants($id);
    }

    // ── Anonymize (T-906) ─────────────────────────────────────────────────────

    /**
     * Strips nicknames + device_hash from all participants of this session.
     * Replaces nicknames with "Participant 1", "Participant 2", … in join order.
     * Sets sessions.anonymized = 1.
     *
     * @throws \RuntimeException  session_not_found | forbidden | already_anonymized
     */
    public function anonymizeSession(int $id, int $instructorId): void
    {
        $session = $this->getSession($id, $instructorId);
        if ((bool) $session['anonymized']) {
            throw new \RuntimeException('already_anonymized');
        }
        $this->sessions->anonymize($id);
    }

    // ── Soft-delete (T-907) ───────────────────────────────────────────────────

    /**
     * Marks the session for deletion by setting delete_requested_at = NOW().
     * bin/cleanup.php hard-deletes after a 7-day grace period.
     *
     * @throws \RuntimeException  session_not_found | forbidden
     */
    public function requestDeletion(int $id, int $instructorId): void
    {
        $this->getSession($id, $instructorId);
        $this->sessions->update($id, [
            'delete_requested_at' => (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d H:i:s'),
        ]);
    }

    // ── Cleanup (called by bin/cleanup.php) ────────────────────────────────────

    public function closeInactiveSessions(int $maxAgeHours = 12): int
    {
        // Delegates to repository-level raw query; implemented in bin/cleanup.php
        // so SessionService stays free of batch-query concerns.
        return 0;
    }

    // ── Private helpers ────────────────────────────────────────────────────────

    private function validateTitle(mixed $raw): string
    {
        $title = trim((string) $raw);
        if ($title === '') {
            throw new \InvalidArgumentException('title:required');
        }
        if (mb_strlen($title) > self::MAX_TITLE_LEN) {
            throw new \InvalidArgumentException('title:too_long');
        }
        return $title;
    }

    private function validateLanguage(mixed $raw): string
    {
        $lang = (string) $raw;
        if (!in_array($lang, self::ALLOWED_LANGS, true)) {
            throw new \InvalidArgumentException('language:invalid');
        }
        return $lang;
    }

    private function generateUniqueCode(): string
    {
        for ($i = 0; $i < self::MAX_CODE_RETRIES; $i++) {
            $code = ShortCode::generate();
            if (!$this->sessions->shortCodeExists($code)) {
                return $code;
            }
        }
        throw new \RuntimeException('short_code_exhausted');
    }
}
