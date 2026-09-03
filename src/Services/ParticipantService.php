<?php

declare(strict_types=1);

namespace EduQR\Services;

use EduQR\Config;
use EduQR\Contracts\ParticipantRepositoryInterface;
use EduQR\Contracts\SessionRepositoryInterface;
use EduQR\Exceptions\ConflictException;
use EduQR\Exceptions\DomainException;
use EduQR\Exceptions\NotFoundException;
use EduQR\Exceptions\ValidationException;
use EduQR\Support\DeviceHash;
use EduQR\Support\ProfanityFilter;
use EduQR\Support\TextFold;

final class ParticipantService
{
    private const MAX_NICKNAME_LEN = 24;
    private const MIN_NICKNAME_LEN = 1;

    public function __construct(
        private readonly ParticipantRepositoryInterface $participants,
        private readonly SessionRepositoryInterface     $sessions,
    ) {
    }

    /**
     * Join a session by short code.
     *
     * @return array{participant_id: int, session_short_code: string, nickname: string}
     * @throws DomainException  session_not_found | session_closed | session_paused | duplicate_nickname | profane_nickname
     * @throws \InvalidArgumentException  nickname:required | nickname:too_long | nickname:invalid_chars
     */
    public function join(string $shortCode, string $rawNickname, ?string $deviceCookieId, string $userAgent): array
    {
        $session = $this->sessions->findByShortCode($shortCode);
        if ($session === null) {
            throw new NotFoundException('session_not_found');
        }

        // T-508: reject closed or paused sessions
        if ($session['status'] === 'closed') {
            throw new ValidationException('session_closed', 410);
        }
        if ($session['status'] === 'paused') {
            throw new ValidationException('session_paused', 410);
        }

        $nickname = $this->validateNickname($rawNickname, $session['language'] ?? 'en');
        $nicknameNormalized = self::normalize($nickname);

        if ($this->participants->existsByNicknameNormalized((int) $session['id'], $nicknameNormalized)) {
            throw new ConflictException('duplicate_nickname', 409, null, 'nickname');
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
            'participant_id' => $participantId,
            'session_short_code' => $session['short_code'],
            'nickname' => $nickname,
        ];
    }

    /**
     * Try to restore a returning participant by device hash.
     * Returns the participant array or null if not found.
     */
    public function restore(string $shortCode, ?string $deviceCookieId, string $userAgent): ?array
    {
        if ($deviceCookieId === null || $deviceCookieId === '') {
            return null;
        }

        $session = $this->sessions->findByShortCode($shortCode);
        if ($session === null) {
            return null;
        }

        $deviceHash = \EduQR\Support\DeviceHash::compute($deviceCookieId, $userAgent);

        return $this->participants->findBySessionAndDeviceHash((int) $session['id'], $deviceHash);
    }

    /**
     * Normalize a nickname for duplicate detection: Turkish-correct case fold
     * (NFR-77) + trim + collapse internal whitespace. Plain mb_strtolower()
     * left "İsmail" and "ismail" looking like two different students.
     */
    public static function normalize(string $nickname): string
    {
        return TextFold::forComparisonNormalized($nickname);
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
        if (! preg_match('/^[\p{L}\p{N}_\- ]+$/u', $trimmed)) {
            throw new \InvalidArgumentException('nickname:invalid_chars');
        }

        // FR-43: profanity filter
        $configDir = Config::get('PROFANITY_DIR', dirname(__DIR__, 2) . '/config/profanity');
        if (ProfanityFilter::isProfane($trimmed, $locale, $configDir)) {
            throw new ValidationException('profane_nickname', 400, null, 'nickname');
        }

        return $trimmed;
    }
}
