<?php

declare(strict_types=1);

namespace EduQR\Tests\Unit;

use EduQR\Contracts\ParticipantRepositoryInterface;
use EduQR\Contracts\SessionRepositoryInterface;
use EduQR\Exceptions\ValidationException;
use EduQR\Services\ParticipantService;
use EduQR\Support\DeviceHash;
use EduQR\Support\ProfanityFilter;
use PHPUnit\Framework\TestCase;

class ParticipantServiceTest extends TestCase
{
    // ── normalize() ────────────────────────────────────────────────────────────

    public function testNormalizeTrimsWhitespace(): void
    {
        $this->assertSame('ali', ParticipantService::normalize('  Ali  '));
    }

    public function testNormalizeLowercases(): void
    {
        $this->assertSame('elif', ParticipantService::normalize('ELIF'));
    }

    public function testNormalizeCollapsesInternalSpaces(): void
    {
        $this->assertSame('ali can', ParticipantService::normalize('Ali  Can'));
    }

    public function testNormalizeHandlesUnicode(): void
    {
        $this->assertSame('şeyma', ParticipantService::normalize('ŞEYMA'));
    }

    /**
     * NFR-77: mb_strtolower('İ') emits "i" + U+0307 COMBINING DOT ABOVE, so
     * "İsmail" and "ismail" used to normalize to two different strings and the
     * same student could join twice under what reads as one nickname.
     */
    public function testNormalizeFoldsTurkishDottedCapitalI_NFR77(): void
    {
        $this->assertSame('ismail', ParticipantService::normalize('İsmail'));
        $this->assertSame(
            ParticipantService::normalize('ismail'),
            ParticipantService::normalize('İsmail')
        );
        $this->assertStringNotContainsString("\u{0307}", ParticipantService::normalize('İsmail'));
    }

    /** NFR-77: the dotless I family folds onto the same key. */
    public function testNormalizeFoldsTurkishDotlessI_NFR77(): void
    {
        $this->assertSame('irmak', ParticipantService::normalize('Irmak'));
        $this->assertSame('irmak', ParticipantService::normalize('ırmak'));
        $this->assertSame('irmak', ParticipantService::normalize('IRMAK'));
    }

    /** NFR-77: a duplicate that differs only in i-casing must be rejected. */
    public function testJoinRejectsTurkishICasingDuplicate_NFR77(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('duplicate_nickname');

        $service = $this->makeService(existingNickname: ParticipantService::normalize('İsmail'));
        $service->join('ABCD23', 'ismail', null, '');
    }

    // ── ProfanityFilter ────────────────────────────────────────────────────────

    public function testProfanityFilterDetectsExactMatch(): void
    {
        $dir = $this->profanityDir();
        $this->assertTrue(ProfanityFilter::isProfane('shit', 'en', $dir));
    }

    public function testProfanityFilterIsCaseInsensitive(): void
    {
        $dir = $this->profanityDir();
        $this->assertTrue(ProfanityFilter::isProfane('SHIT', 'en', $dir));
    }

    public function testProfanityFilterDetectsSubstring(): void
    {
        $dir = $this->profanityDir();
        // "bullshitter" contains "bullshit" which contains "shit"
        $this->assertTrue(ProfanityFilter::isProfane('shithead', 'en', $dir));
    }

    public function testProfanityFilterAllowsCleanWord(): void
    {
        $dir = $this->profanityDir();
        $this->assertFalse(ProfanityFilter::isProfane('sunshine', 'en', $dir));
    }

    public function testProfanityFilterReturnsFalseForMissingLocaleFile(): void
    {
        $dir = $this->profanityDir();
        // 'zz' locale file does not exist — should not crash, return false
        $this->assertFalse(ProfanityFilter::isProfane('anything', 'zz', $dir));
    }

    public function testProfanityFilterTurkish(): void
    {
        $dir = $this->profanityDir();
        $this->assertTrue(ProfanityFilter::isProfane('sik', 'tr', $dir));
    }

    // ── join() validation via stub ─────────────────────────────────────────────

    public function testJoinThrowsForEmptyNickname(): void
    {
        $service = $this->makeService();

        try {
            $service->join('ABCD23', '', null, '');
            $this->fail('Expected ValidationException');
        } catch (ValidationException $e) {
            $this->assertSame('nickname_required', $e->getErrorCode());
            $this->assertSame(400, $e->getStatus());
            $this->assertSame('nickname_required', $e->getPublicCode());
            $this->assertSame('nickname', $e->getField());
        }
    }

    public function testJoinThrowsForNicknameTooLong(): void
    {
        $service = $this->makeService();

        try {
            $service->join('ABCD23', str_repeat('a', 25), null, '');
            $this->fail('Expected ValidationException');
        } catch (ValidationException $e) {
            $this->assertSame('nickname_too_long', $e->getErrorCode());
            $this->assertSame(400, $e->getStatus());
            $this->assertSame('nickname_too_long', $e->getPublicCode());
            $this->assertSame('nickname', $e->getField());
        }
    }

    public function testJoinThrowsForInvalidChars(): void
    {
        $service = $this->makeService();

        try {
            $service->join('ABCD23', 'Ali@Veli!', null, '');
            $this->fail('Expected ValidationException');
        } catch (ValidationException $e) {
            $this->assertSame('nickname_invalid_chars', $e->getErrorCode());
            $this->assertSame(400, $e->getStatus());
            $this->assertSame('nickname_invalid_chars', $e->getPublicCode());
            $this->assertSame('nickname', $e->getField());
        }
    }

    public function testJoinThrowsForClosedSession(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('session_closed');

        $service = $this->makeService(status: 'closed');
        $service->join('ABCD23', 'Ali', null, '');
    }

    public function testJoinThrowsForPausedSession(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('session_paused');

        $service = $this->makeService(status: 'paused');
        $service->join('ABCD23', 'Ali', null, '');
    }

    public function testJoinThrowsForSessionNotFound(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('session_not_found');

        $service = $this->makeService(hasSession: false);
        $service->join('XXXXXX', 'Ali', null, '');
    }

    public function testJoinThrowsForDuplicateNickname(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('duplicate_nickname');

        $service = $this->makeService(existingNickname: 'ali');
        $service->join('ABCD23', 'Ali', null, '');
    }

    public function testJoinSuccessReturnsData(): void
    {
        $service = $this->makeService();
        $result = $service->join('ABCD23', 'Elif', null, '');

        $this->assertArrayHasKey('participant_id', $result);
        $this->assertSame('ABCD23', $result['session_short_code']);
        $this->assertSame('Elif', $result['nickname']);
    }

    public function testJoinStoresDeviceHashWhenDeviceCookieExists_FR46_FR49(): void
    {
        $capturedHash = null;
        $service = $this->makeJoinServiceWithCapturedDeviceHash($capturedHash);

        $deviceCookieId = 'device-cookie-123';
        $userAgent = 'Mozilla/5.0';

        $service->join('ABCD23', 'Elif', $deviceCookieId, $userAgent);

        $this->assertSame(DeviceHash::compute($deviceCookieId, $userAgent), $capturedHash);
    }

    public function testRestoreReturnsParticipantForMatchingDeviceHash_FR49(): void
    {
        $deviceCookieId = 'device-cookie-123';
        $userAgent = 'Mozilla/5.0';
        $expectedHash = DeviceHash::compute($deviceCookieId, $userAgent);

        $service = $this->makeRestoreService($expectedHash);
        $participant = $service->restore('ABCD23', $deviceCookieId, $userAgent);

        $this->assertIsArray($participant);
        $this->assertSame(44, (int) $participant['id']);
        $this->assertSame(1, (int) $participant['session_id']);
    }

    // ── Stub factories ─────────────────────────────────────────────────────────

    private function makeService(
        string $status = 'active',
        bool   $hasSession = true,
        string $existingNickname = '',
    ): ParticipantService {
        $session = $hasSession ? [
            'id' => 1,
            'short_code' => 'ABCD23',
            'title' => 'Test Session',
            'status' => $status,
            'language' => 'en',
        ] : null;

        $sessionRepo = new class ($session) implements SessionRepositoryInterface {
            public function __construct(private ?array $session)
            {
            }

            public function findById(int $id): ?array
            {
                return $this->session;
            }
            public function findByShortCode(string $code): ?array
            {
                return $this->session;
            }
            public function shortCodeExists(string $code): bool
            {
                return false;
            }
            public function create(int $courseId, string $title, string $shortCode, string $language, int $isQuiz = 0): int
            {
                return 1;
            }
            public function update(int $id, array $fields): void
            {
            }
            public function listByCourse(int $courseId): array
            {
                return [];
            }
            public function countParticipants(int $sessionId): int
            {
                return 0;
            }
            public function anonymize(int $sessionId): void
            {
            }
        };

        $existing = $existingNickname;
        $participantRepo = new class ($existing) implements ParticipantRepositoryInterface {
            public int $registeredCount = 0;
            public function __construct(private string $existing)
            {
            }

            public function register(int $sessionId, string $nickname, string $nicknameNormalized, ?string $deviceHash): int
            {
                $this->registeredCount++;

                return $this->registeredCount;
            }

            public function existsByNicknameNormalized(int $sessionId, string $nicknameNormalized): bool
            {
                return $this->existing !== '' && $this->existing === $nicknameNormalized;
            }

            public function countBySession(int $sessionId): int
            {
                return 0;
            }
            public function findBySession(int $sessionId): array
            {
                return [];
            }
            public function findById(int $id): ?array
            {
                return null;
            }
            public function findBySessionAndDeviceHash(int $sessionId, string $deviceHash): ?array
            {
                return null;
            }
        };

        // Point profanity filter at the real config dir
        $_ENV['PROFANITY_DIR'] = dirname(__DIR__, 2) . '/config/profanity';
        putenv('PROFANITY_DIR=' . dirname(__DIR__, 2) . '/config/profanity');

        return new ParticipantService($participantRepo, $sessionRepo);
    }

    private function makeJoinServiceWithCapturedDeviceHash(?string &$capturedHash): ParticipantService
    {
        $session = [
            'id' => 1,
            'short_code' => 'ABCD23',
            'title' => 'Test Session',
            'status' => 'active',
            'language' => 'en',
        ];

        $sessionRepo = new class ($session) implements SessionRepositoryInterface {
            public function __construct(private ?array $session)
            {
            }

            public function findById(int $id): ?array
            {
                return $this->session;
            }

            public function findByShortCode(string $code): ?array
            {
                return $this->session;
            }

            public function shortCodeExists(string $code): bool
            {
                return false;
            }

            public function create(int $courseId, string $title, string $shortCode, string $language, int $isQuiz = 0): int
            {
                return 1;
            }

            public function update(int $id, array $fields): void
            {
            }

            public function listByCourse(int $courseId): array
            {
                return [];
            }

            public function countParticipants(int $sessionId): int
            {
                return 0;
            }

            public function anonymize(int $sessionId): void
            {
            }
        };

        $participantRepo = new class ($capturedHash) implements ParticipantRepositoryInterface {
            public function __construct(private ?string &$capturedHash)
            {
            }

            public function register(int $sessionId, string $nickname, string $nicknameNormalized, ?string $deviceHash): int
            {
                $this->capturedHash = $deviceHash;

                return 1;
            }

            public function existsByNicknameNormalized(int $sessionId, string $nicknameNormalized): bool
            {
                return false;
            }

            public function countBySession(int $sessionId): int
            {
                return 0;
            }

            public function findBySession(int $sessionId): array
            {
                return [];
            }

            public function findById(int $id): ?array
            {
                return null;
            }

            public function findBySessionAndDeviceHash(int $sessionId, string $deviceHash): ?array
            {
                return null;
            }
        };

        $_ENV['PROFANITY_DIR'] = dirname(__DIR__, 2) . '/config/profanity';
        putenv('PROFANITY_DIR=' . dirname(__DIR__, 2) . '/config/profanity');

        return new ParticipantService($participantRepo, $sessionRepo);
    }

    private function makeRestoreService(string $expectedHash): ParticipantService
    {
        $session = [
            'id' => 1,
            'short_code' => 'ABCD23',
            'title' => 'Test Session',
            'status' => 'active',
            'language' => 'en',
        ];

        $sessionRepo = new class ($session) implements SessionRepositoryInterface {
            public function __construct(private ?array $session)
            {
            }

            public function findById(int $id): ?array
            {
                return $this->session;
            }

            public function findByShortCode(string $code): ?array
            {
                return $this->session;
            }

            public function shortCodeExists(string $code): bool
            {
                return false;
            }

            public function create(int $courseId, string $title, string $shortCode, string $language, int $isQuiz = 0): int
            {
                return 1;
            }

            public function update(int $id, array $fields): void
            {
            }

            public function listByCourse(int $courseId): array
            {
                return [];
            }

            public function countParticipants(int $sessionId): int
            {
                return 0;
            }

            public function anonymize(int $sessionId): void
            {
            }
        };

        $participantRepo = new class ($expectedHash) implements ParticipantRepositoryInterface {
            public function __construct(private string $expectedHash)
            {
            }

            public function register(int $sessionId, string $nickname, string $nicknameNormalized, ?string $deviceHash): int
            {
                return 1;
            }

            public function existsByNicknameNormalized(int $sessionId, string $nicknameNormalized): bool
            {
                return false;
            }

            public function countBySession(int $sessionId): int
            {
                return 0;
            }

            public function findBySession(int $sessionId): array
            {
                return [];
            }

            public function findById(int $id): ?array
            {
                return null;
            }

            public function findBySessionAndDeviceHash(int $sessionId, string $deviceHash): ?array
            {
                if ($deviceHash !== $this->expectedHash) {
                    return null;
                }

                return [
                    'id' => 44,
                    'session_id' => $sessionId,
                    'nickname' => 'Elif',
                    'nickname_normalized' => 'elif',
                    'device_hash' => $deviceHash,
                ];
            }
        };

        return new ParticipantService($participantRepo, $sessionRepo);
    }

    private function profanityDir(): string
    {
        return dirname(__DIR__, 2) . '/config/profanity';
    }
}
