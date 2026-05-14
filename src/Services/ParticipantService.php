<?php

declare(strict_types=1);

namespace EduQR\Services;

use EduQR\Config;
use EduQR\Contracts\ParticipantRepositoryInterface;
use EduQR\Contracts\SessionRepositoryInterface;
use EduQR\Support\DeviceHash;
use EduQR\Support\ProfanityFilter;

final class ParticipantService
{
    private const MAX_NICKNAME_LEN = 24;
    private const MIN_NICKNAME_LEN = 1;

    public function __construct(
        private readonly ParticipantRepositoryInterface $participants,
        private readonly SessionRepositoryInterface     $sessions,
    ) {}

    /**
     * Join a session by short code.
     *
     * @return array{participant_id: int, session_short_code: string, nickname: string}
     * @throws \RuntimeException  session_not_found | session_closed | session_paused | duplicate_nickname | profane_nickname
     * @throws \InvalidArgumentException  nickname:required | nickname:too_long | nickname:invalid_chars
     */
    public function join(string $shortCode, string $rawNickname, ?string $deviceCookieId, string $userAgent): array
    {
        $session = $this->sessions->findByShortCode($shortCode);
        if ($session === null) {
            throw new \RuntimeException('session_not_found');
        }

        // T-508: reject closed or paused sessions
        if ($session['status'] === 'closed') {
            throw new \RuntimeException('session_closed');
        }
        if ($session['status'] === 'paused') {
            throw new \RuntimeException('session_paused');
        }

        $nickname           = $this->validateNickname($rawNickname, $session['language'] ?? 'en');
        $nicknameNormalized = self::normalize($nickname);

        if ($this->participants->existsByNicknameNormalized((int) $session['id'], $nicknameNormalized)) {
            throw new \RuntimeException('duplicate_nickname');
        }

        $deviceHash = null;
        if ($deviceCookieId !== null && $deviceCookieId !== '') {
            $deviceHash = DeviceHash::compute($deviceCookieId, $userAgent);
        }

        $participantId = $this->participants->register(
            (int) $session['id'],
            $nickname,
            $nicknameNormalized,
            $deviceHash,
        );

        return [
            'participant_id'     => $participantId,
            'session_short_code' => $session['short_code'],
            'nickname'           => $nickname,
        ];
    }

    /** Normalize a nickname: lowercase + trim + collapse internal whitespace. */
    public static function normalize(string $nickname): string
    {
        $normalized = mb_strtolower(trim($nickname), 'UTF-8');
        return (string) preg_replace('/\s+/u', ' ', $normalized);
    }

    /** Validate a raw nickname string. Throws InvalidArgumentException on failure. */
    private function validateNickname(string $raw, string $locale): string
    {
        $trimmed = trim($raw);

        if (mb_strlen($trimmed, 'UTF-8') < self::MIN_NICKNAME_LEN) {
            throw new \InvalidArgumentException('nickname:required');
        }

        if (mb_strlen($trimmed, 'UTF-8') > self::MAX_NICKNAME_LEN) {
            throw new \InvalidArgumentException('nickname:too_long');
        }

        // FR-41: allowed charset
        if (!preg_match('/^[\p{L}\p{N}_\- ]+$/u', $trimmed)) {
            throw new \InvalidArgumentException('nickname:invalid_chars');
        }

        // FR-43: profanity filter
        $configDir = Config::get('PROFANITY_DIR', dirname(__DIR__, 2) . '/config/profanity');
        if (ProfanityFilter::isProfane($trimmed, $locale, $configDir)) {
            throw new \RuntimeException('profane_nickname');
        }

        return $trimmed;
    }
}
