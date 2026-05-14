<?php

declare(strict_types=1);

namespace EduQR\Tests\Unit;

use EduQR\Contracts\ParticipantRepositoryInterface;
use EduQR\Contracts\SessionRepositoryInterface;
use EduQR\Services\ParticipantService;
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
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('nickname:required');

        $service = $this->makeService();
        $service->join('ABCD23', '', null, '');
    }

    public function testJoinThrowsForNicknameTooLong(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('nickname:too_long');

        $service = $this->makeService();
        $service->join('ABCD23', str_repeat('a', 25), null, '');
    }

    public function testJoinThrowsForInvalidChars(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('nickname:invalid_chars');

        $service = $this->makeService();
        $service->join('ABCD23', 'Ali@Veli!', null, '');
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
        $result  = $service->join('ABCD23', 'Elif', null, '');

        $this->assertArrayHasKey('participant_id', $result);
        $this->assertSame('ABCD23', $result['session_short_code']);
        $this->assertSame('Elif', $result['nickname']);
    }

    // ── Stub factories ─────────────────────────────────────────────────────────

    private function makeService(
        string $status          = 'active',
        bool   $hasSession      = true,
        string $existingNickname = '',
    ): ParticipantService {
        $session = $hasSession ? [
            'id'         => 1,
            'short_code' => 'ABCD23',
            'title'      => 'Test Session',
            'status'     => $status,
            'language'   => 'en',
        ] : null;

        $sessionRepo = new class($session) implements SessionRepositoryInterface {
            public function __construct(private ?array $session) {}

            public function findById(int $id): ?array { return $this->session; }
            public function findByShortCode(string $code): ?array { return $this->session; }
            public function shortCodeExists(string $code): bool { return false; }
            public function create(int $courseId, string $title, string $shortCode, string $language): int { return 1; }
            public function update(int $id, array $fields): void {}
            public function listByCourse(int $courseId): array { return []; }
            public function countParticipants(int $sessionId): int { return 0; }
        };

        $existing = $existingNickname;
        $participantRepo = new class($existing) implements ParticipantRepositoryInterface {
            public int $registeredCount = 0;
            public function __construct(private string $existing) {}

            public function register(int $sessionId, string $nickname, string $nicknameNormalized, ?string $deviceHash): int
            {
                $this->registeredCount++;
                return $this->registeredCount;
            }

            public function existsByNicknameNormalized(int $sessionId, string $nicknameNormalized): bool
            {
                return $this->existing !== '' && $this->existing === $nicknameNormalized;
            }

            public function countBySession(int $sessionId): int { return 0; }
            public function findBySession(int $sessionId): array { return []; }
            public function findById(int $id): ?array { return null; }
        };

        // Point profanity filter at the real config dir
        $_ENV['PROFANITY_DIR']   = dirname(__DIR__, 2) . '/config/profanity';
        putenv('PROFANITY_DIR=' . dirname(__DIR__, 2) . '/config/profanity');

        return new ParticipantService($participantRepo, $sessionRepo);
    }

    private function profanityDir(): string
    {
        return dirname(__DIR__, 2) . '/config/profanity';
    }
}
