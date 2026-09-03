<?php

declare(strict_types=1);

namespace EduQR\Tests\Unit;

use EduQR\Contracts\CourseRepositoryInterface;
use EduQR\Contracts\OptionRepositoryInterface;
use EduQR\Contracts\QuestionRepositoryInterface;
use EduQR\Contracts\SessionRepositoryInterface;
use EduQR\I18n\I18nService;
use EduQR\Services\ReportService;
use EduQR\Services\ScoringService;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the LMS file exports — T-1113.
 *
 * Moodle GIFT question export and gradebook CSV rows. Both are plain file
 * builds: no LMS API is contacted anywhere in this feature.
 *
 * @requirement FR-98
 */
class ReportLmsExportTest extends TestCase
{
    private const OWNER_ID = 99;
    private const CO_INSTRUCTOR_ID = 77;
    private const STRANGER_ID = 55;
    private const SESSION_ID = 10;

    private PDO $pdo;

    protected function setUp(): void
    {
        I18nService::init(dirname(__DIR__, 2) . '/locales', 'en');

        $this->pdo = new PDO('sqlite::memory:', options: [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);

        $this->pdo->exec('
            CREATE TABLE options (
                id           INTEGER PRIMARY KEY AUTOINCREMENT,
                question_id  INTEGER NOT NULL,
                option_text  TEXT    NOT NULL,
                option_value TEXT    NULL,
                is_correct   INTEGER NOT NULL DEFAULT 0,
                order_no     INTEGER NOT NULL DEFAULT 0
            )
        ');

        $this->pdo->exec('
            CREATE TABLE answers (
                id                  INTEGER PRIMARY KEY AUTOINCREMENT,
                question_id         INTEGER NOT NULL,
                participant_id      INTEGER NOT NULL,
                selected_option_id  INTEGER NULL,
                answer_text         TEXT    NULL,
                is_hidden           INTEGER NOT NULL DEFAULT 0,
                created_at          TEXT    NOT NULL DEFAULT (datetime(\'now\')),
                UNIQUE (question_id, participant_id)
            )
        ');

        $this->pdo->exec('
            CREATE TABLE participants (
                id         INTEGER PRIMARY KEY AUTOINCREMENT,
                session_id INTEGER NOT NULL,
                nickname   TEXT    NOT NULL
            )
        ');
    }

    // ── Helpers ────────────────────────────────────────────────────────────────

    /** @param array<int,array<string,mixed>> $questionRows */
    private function makeService(array $questionRows): ReportService
    {
        $questions = $this->createMock(QuestionRepositoryInterface::class);
        $questions->method('findBySession')->willReturn($questionRows);
        $questions->method('findById')->willReturn($questionRows[0] ?? null);

        $sessions = $this->createMock(SessionRepositoryInterface::class);
        $sessions->method('findById')->willReturn([
            'id' => self::SESSION_ID,
            'status' => 'closed',
            'course_id' => 5,
            'language' => 'en',
            'exam_mode' => 1, // FR-96 gates students only; the export must ignore it.
        ]);

        $courses = $this->createMock(CourseRepositoryInterface::class);
        $courses->method('findById')->willReturn(['id' => 5, 'instructor_id' => self::OWNER_ID]);
        // FR-97: owner and co-instructor pass, anyone else does not.
        $courses->method('roleFor')->willReturnCallback(
            static fn (int $courseId, int $userId): ?string => match ($userId) {
                self::OWNER_ID => 'owner',
                self::CO_INSTRUCTOR_ID => 'co_instructor',
                default => null,
            }
        );

        return new ReportService(
            $sessions,
            $questions,
            $this->createMock(OptionRepositoryInterface::class),
            $courses,
            new ScoringService($questions, $this->pdo),
            $this->pdo,
        );
    }

    /** @return array<string,mixed> */
    private function question(int $id, string $type, string $text): array
    {
        return [
            'id' => $id,
            'session_id' => self::SESSION_ID,
            'question_type' => $type,
            'question_text' => $text,
            'status' => 'closed',
            'show_results' => 1,
        ];
    }

    /** @param array<int,array{0:string,1:int,2:string|null}> $options text, is_correct, value */
    private function seedOptions(int $questionId, array $options): void
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO options (question_id, option_text, option_value, is_correct, order_no) VALUES (?,?,?,?,?)'
        );
        foreach ($options as $i => [$text, $isCorrect, $value]) {
            $stmt->execute([$questionId, $text, $value, $isCorrect, $i + 1]);
        }
    }

    private function seedParticipant(int $id, string $nickname): void
    {
        $this->pdo->prepare('INSERT INTO participants (id, session_id, nickname) VALUES (?,?,?)')
            ->execute([$id, self::SESSION_ID, $nickname]);
    }

    private function seedAnswer(int $participantId, int $questionId, ?int $optionId, ?string $text = null): void
    {
        $this->pdo->prepare(
            'INSERT INTO answers (question_id, participant_id, selected_option_id, answer_text) VALUES (?,?,?,?)'
        )->execute([$questionId, $participantId, $optionId, $text]);
    }

    /** @return array<int,string> The GIFT question blocks, without the file header comment. */
    private function blocks(string $gift): array
    {
        $chunks = explode("\n\n", trim($gift, "\n"));
        array_shift($chunks); // file header comment

        return $chunks;
    }

    // ── GIFT: one form per question type (FR-98) ──────────────────────────────

    public function testMultipleChoiceExportsAsGiftMultipleChoice_FR98(): void
    {
        $this->seedOptions(1, [['Push', 1, '1'], ['Append', 0, '2']]);
        $service = $this->makeService([$this->question(1, 'multiple_choice', 'Which is a stack operation?')]);

        $blocks = $this->blocks($service->buildGiftExport(self::SESSION_ID, self::OWNER_ID)['gift']);

        $this->assertSame(
            "::Question 1:: Which is a stack operation? {\n=Push\n~Append\n}",
            $blocks[0]
        );
    }

    public function testYesNoWithCorrectOptionExportsAsTrueFalse_FR98(): void
    {
        $this->seedOptions(1, [['Yes', 1, 'yes'], ['No', 0, 'no']]);
        $service = $this->makeService([$this->question(1, 'yes_no', 'Is a stack LIFO?')]);

        $blocks = $this->blocks($service->buildGiftExport(self::SESSION_ID, self::OWNER_ID)['gift']);

        $this->assertSame('::Question 1:: Is a stack LIFO? {T}', $blocks[0]);
    }

    public function testYesNoWithCorrectNoOptionExportsAsFalse_FR98(): void
    {
        $this->seedOptions(1, [['Yes', 0, 'yes'], ['No', 1, 'no']]);
        $service = $this->makeService([$this->question(1, 'yes_no', 'Is a stack FIFO?')]);

        $blocks = $this->blocks($service->buildGiftExport(self::SESSION_ID, self::OWNER_ID)['gift']);

        $this->assertSame('::Question 1:: Is a stack FIFO? {F}', $blocks[0]);
    }

    public function testFillInTheBlankExportsAsShortAnswer_FR98(): void
    {
        $this->seedOptions(1, [['Ankara', 1, null]]);
        $service = $this->makeService([$this->question(1, 'fill_in_the_blank', 'Capital of Turkey?')]);

        $blocks = $this->blocks($service->buildGiftExport(self::SESSION_ID, self::OWNER_ID)['gift']);

        $this->assertSame('::Question 1:: Capital of Turkey? {=Ankara}', $blocks[0]);
    }

    public function testOpenTextExportsAsEssay_FR98(): void
    {
        $service = $this->makeService([$this->question(1, 'open_text', 'What was hardest?')]);

        $blocks = $this->blocks($service->buildGiftExport(self::SESSION_ID, self::OWNER_ID)['gift']);

        $this->assertSame('::Question 1:: What was hardest? {}', $blocks[0]);
    }

    public function testLikertExportsAsEssayKeepingTheScaleAsVisibleText_FR98(): void
    {
        $this->seedOptions(1, [
            ['Strongly disagree', 0, '1'],
            ['Disagree', 0, '2'],
            ['Neutral', 0, '3'],
            ['Agree', 0, '4'],
            ['Strongly agree', 0, '5'],
        ]);
        $service = $this->makeService([$this->question(1, 'likert_5', 'Pointers are clear to me.')]);

        $result = $service->buildGiftExport(self::SESSION_ID, self::OWNER_ID);
        $blocks = $this->blocks($result['gift']);

        $this->assertSame(
            '::Question 1:: Pointers are clear to me.\n- Strongly disagree\n- Disagree\n- Neutral\n- Agree\n- Strongly agree {}',
            $blocks[0]
        );
        // A scale item is essay by design, not a fallback: nothing is downgraded.
        $this->assertSame(0, $result['downgraded_count']);
    }

    public function testSeveralCorrectOptionsUseTheWeightedGiftForm_FR98(): void
    {
        $this->seedOptions(1, [['Push', 1, '1'], ['Pop', 1, '2'], ['Append', 0, '3']]);
        $service = $this->makeService([$this->question(1, 'multiple_choice', 'Pick the stack operations')]);

        $blocks = $this->blocks($service->buildGiftExport(self::SESSION_ID, self::OWNER_ID)['gift']);

        // Plain GIFT allows a single "=" per question, so credit is split instead.
        $this->assertSame(
            "::Question 1:: Pick the stack operations {\n~%50%Push\n~%50%Pop\n~%-100%Append\n}",
            $blocks[0]
        );
    }

    // ── GIFT: escaping (FR-98) ────────────────────────────────────────────────

    public function testGiftEscapesEverySpecialCharacterInQuestionAndAnswerText_FR98(): void
    {
        $this->seedOptions(1, [['a=b~c#d{e}f:g\\h', 1, '1'], ['plain', 0, '2']]);
        $service = $this->makeService([
            $this->question(1, 'multiple_choice', 'Cost = 5 {x} ~ #tag : end \\ done'),
        ]);

        $blocks = $this->blocks($service->buildGiftExport(self::SESSION_ID, self::OWNER_ID)['gift']);

        $this->assertSame(
            "::Question 1:: Cost \\= 5 \\{x\\} \\~ \\#tag \\: end \\\\ done {\n"
            . "=a\\=b\\~c\\#d\\{e\\}f\\:g\\\\h\n"
            . "~plain\n}",
            $blocks[0]
        );
    }

    public function testQuestionTextStartingWithDoubleSlashIsNeverReadAsAComment_FR98(): void
    {
        $service = $this->makeService([$this->question(1, 'open_text', '// TODO: explain big-O')]);

        $blocks = $this->blocks($service->buildGiftExport(self::SESSION_ID, self::OWNER_ID)['gift']);

        // The mandatory ::title:: prefix keeps the text off the start of the line.
        $this->assertStringStartsWith('::Question 1::', $blocks[0]);
        $this->assertStringNotContainsString("\n//", "\n" . $blocks[0]);
        $this->assertStringContainsString('// TODO\: explain big-O', $blocks[0]);
    }

    public function testMultiLineQuestionTextDoesNotSplitTheGiftBlock_FR98(): void
    {
        $service = $this->makeService([
            $this->question(1, 'open_text', "First line\n\nSecond line\r\nThird line"),
            $this->question(2, 'open_text', 'Plain question'),
        ]);

        $gift = $service->buildGiftExport(self::SESSION_ID, self::OWNER_ID)['gift'];
        $blocks = $this->blocks($gift);

        $this->assertCount(2, $blocks);
        $this->assertSame('::Question 1:: First line\n\nSecond line\nThird line {}', $blocks[0]);
        $this->assertSame('::Question 2:: Plain question {}', $blocks[1]);
    }

    public function testTurkishTextSurvivesTheGiftExportIntact_FR98(): void
    {
        $this->seedOptions(1, [['Çığır açıcı', 1, '1'], ['Şüpheli', 0, '2']]);
        $service = $this->makeService([
            $this->question(1, 'multiple_choice', 'Öğrenciler için: yığın nedir?'),
        ]);

        $gift = $service->buildGiftExport(self::SESSION_ID, self::OWNER_ID)['gift'];

        $this->assertTrue(mb_check_encoding($gift, 'UTF-8'));
        $this->assertStringContainsString('Öğrenciler için\: yığın nedir?', $gift);
        $this->assertStringContainsString('=Çığır açıcı', $gift);
        $this->assertStringContainsString('~Şüpheli', $gift);
    }

    // ── GIFT: no correct answer, and the empty session (FR-98) ────────────────

    public function testQuestionWithNoCorrectAnswerIsDowngradedToAValidEssay_FR98(): void
    {
        $this->seedOptions(1, [['Coffee', 0, '1'], ['Tea', 0, '2']]);
        $service = $this->makeService([$this->question(1, 'multiple_choice', 'What do you prefer?')]);

        $result = $service->buildGiftExport(self::SESSION_ID, self::OWNER_ID);
        $blocks = $this->blocks($result['gift']);

        $this->assertSame(1, $result['downgraded_count']);
        $this->assertSame(
            '// ' . t('report.gift.no_correct_answer') . "\n"
            . '::Question 1:: What do you prefer?\n- Coffee\n- Tea {}',
            $blocks[0]
        );
        // Never a multiple choice block without a correct answer.
        $this->assertStringNotContainsString('~Coffee', $result['gift']);
    }

    public function testEmptySessionProducesAWellFormedGiftFile_FR98(): void
    {
        $service = $this->makeService([]);

        $result = $service->buildGiftExport(self::SESSION_ID, self::OWNER_ID);

        $this->assertSame(0, $result['question_count']);
        $this->assertSame(0, $result['downgraded_count']);
        $this->assertSame('// ' . t('report.gift.file_header', ['session' => 10]) . "\n", $result['gift']);
        $this->assertStringEndsWith("\n", $result['gift']);
    }

    // ── Gradebook rows (FR-92, FR-98) ─────────────────────────────────────────

    public function testGradebookHasOneRowPerParticipantWithScoreAndPercentage_FR98(): void
    {
        // q1 and q2 are scorable, q3 has no correct option: max score is 2.
        $this->seedOptions(1, [['Right', 1, '1'], ['Wrong', 0, '2']]);
        $this->seedOptions(2, [['Also right', 1, '1'], ['Nope', 0, '2']]);
        $this->seedOptions(3, [['Coffee', 0, '1'], ['Tea', 0, '2']]);

        $this->seedParticipant(1, 'Elif');
        $this->seedParticipant(2, 'İsmail');
        $this->seedAnswer(1, 1, 1);
        $this->seedAnswer(1, 2, 3);
        $this->seedAnswer(2, 1, 2);

        $service = $this->makeService([
            $this->question(1, 'multiple_choice', 'Q1'),
            $this->question(2, 'multiple_choice', 'Q2'),
            $this->question(3, 'multiple_choice', 'Q3'),
        ]);

        $gradebook = $service->buildGradebook(self::SESSION_ID, self::OWNER_ID);

        $this->assertSame(2, $gradebook['max_score']);
        $this->assertCount(2, $gradebook['rows']);
        $this->assertSame(
            ['nickname' => 'Elif', 'score' => 2, 'max_score' => 2, 'percentage' => 100.0],
            $gradebook['rows'][0]
        );
        $this->assertSame(
            ['nickname' => 'İsmail', 'score' => 0, 'max_score' => 2, 'percentage' => 0.0],
            $gradebook['rows'][1]
        );
    }

    public function testGradebookAnonymizesNicknamesLikeTheReportExport_FR98(): void
    {
        $this->seedOptions(1, [['Right', 1, '1'], ['Wrong', 0, '2']]);
        $this->seedParticipant(1, 'Elif');
        $this->seedAnswer(1, 1, 1);

        $service = $this->makeService([$this->question(1, 'multiple_choice', 'Q1')]);

        $gradebook = $service->buildGradebook(self::SESSION_ID, self::OWNER_ID, true);

        $this->assertSame(
            t('report.anonymized_participant', ['number' => 1]),
            $gradebook['rows'][0]['nickname']
        );
        $this->assertStringNotContainsString('Elif', json_encode($gradebook, JSON_THROW_ON_ERROR));
    }

    public function testGradebookIsEmptyButWellFormedForASessionWithoutParticipants_FR98(): void
    {
        $service = $this->makeService([]);

        $gradebook = $service->buildGradebook(self::SESSION_ID, self::OWNER_ID);

        $this->assertSame(0, $gradebook['max_score']);
        $this->assertSame([], $gradebook['rows']);
    }

    // ── Ownership (FR-97, FR-98) ──────────────────────────────────────────────

    public function testCoInstructorCanRunBothExports_FR98(): void
    {
        $service = $this->makeService([$this->question(1, 'open_text', 'Q1')]);

        $this->assertSame(1, $service->buildGiftExport(self::SESSION_ID, self::CO_INSTRUCTOR_ID)['question_count']);
        $this->assertSame([], $service->buildGradebook(self::SESSION_ID, self::CO_INSTRUCTOR_ID)['rows']);
    }

    public function testUnrelatedInstructorIsForbiddenFromTheGiftExport_FR98(): void
    {
        $service = $this->makeService([$this->question(1, 'open_text', 'Q1')]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('forbidden');

        $service->buildGiftExport(self::SESSION_ID, self::STRANGER_ID);
    }

    public function testUnrelatedInstructorIsForbiddenFromTheGradebook_FR98(): void
    {
        $service = $this->makeService([$this->question(1, 'open_text', 'Q1')]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('forbidden');

        $service->buildGradebook(self::SESSION_ID, self::STRANGER_ID);
    }
}
