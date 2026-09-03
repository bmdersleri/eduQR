<?php

declare(strict_types=1);

namespace EduQR\Tests\Unit\Controllers;

use EduQR\Controllers\ApiController;
use EduQR\Exceptions\ValidationException;
use EduQR\I18n\I18nService;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * Input validation signals failure the same way every other domain failure does
 * — NFR-83.
 *
 * Before this requirement a service reported a bad field by throwing
 * `\InvalidArgumentException('field:reason')`, and four controllers each kept a
 * private `match` translating those strings back into a status, a code, a
 * message and a field. The string was the contract, the table was its only
 * reader, and the tables had already disagreed with one another.
 *
 * Two of the three tests here are text scans rather than behavioural
 * assertions, because what has to stay true is a property of the source: no
 * service throws the untyped exception, and no controller holds the table. A
 * behavioural test can only prove that today's throw sites answer correctly; it
 * cannot stop a new one from reintroducing the pattern.
 *
 * The third pins the four responses API_SPEC.md §7.1 changed, which are the
 * only intended divergences in the whole conversion.
 *
 * @requirement NFR-83
 */
final class ValidationEnvelopeTest extends TestCase
{
    /**
     * `HtmlController` throws it twice for a programmer error — an unknown
     * layout name and a traversing template path. Neither is reachable from a
     * request body, neither is answered to a caller, and both are the textbook
     * use of the type. They stay.
     */
    private const ALLOWED_FILES = ['HtmlController.php'];

    /** `new \InvalidArgumentException` or `catch (\InvalidArgumentException`. */
    private const UNTYPED_VALIDATION = '/(?:\bnew\s+|\bcatch\s*\(\s*)\\\\?InvalidArgumentException\b/';

    /** A single-quoted `field:reason` literal — the string this task retired. */
    private const FIELD_REASON = '/^[a-z][a-z0-9_]*:[a-z][a-z0-9_]*$/';

    protected function setUp(): void
    {
        // Pin the locale: I18nService is process-global, so an earlier test in
        // the run must not get to decide what these messages say.
        I18nService::init(dirname(__DIR__, 3) . '/locales', 'en');
    }

    // ── The boundary holds ────────────────────────────────────────────────────

    /**
     * @requirement NFR-83
     */
    public function testNoServiceThrowsTheUntypedValidationException(): void
    {
        $this->assertNoUntypedValidation(dirname(__DIR__, 3) . '/src/Services');
    }

    /**
     * @requirement NFR-83
     */
    public function testNoControllerThrowsOrCatchesTheUntypedValidationException(): void
    {
        $this->assertNoUntypedValidation(dirname(__DIR__, 3) . '/src/Controllers');
    }

    /** The guard above is vacuous unless it can see a violation, so it is shown two. */
    public function testTheUntypedValidationScanRecognisesBothShapes(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'eduqr-validation-') . '.php';
        file_put_contents(
            $file,
            "<?php\n"
            . "// A docblock naming \\InvalidArgumentException does not count.\n"
            . "throw new \\InvalidArgumentException('title:required');\n"
            . "try { x(); } catch (\\InvalidArgumentException \$e) { y(); }\n",
        );

        try {
            self::assertSame(
                [3, 4],
                array_column($this->untypedValidationIn($file), 0),
            );
        } finally {
            unlink($file);
        }
    }

    // ── No controller holds a translation table ───────────────────────────────

    /**
     * The table is gone, and so is the string it was keyed by. Scanning for the
     * literal rather than for the `match` is the tighter guard: a table written
     * as an `if` chain or an array constant would evade a search for `match`,
     * but not one for the shape of the key.
     *
     * @requirement NFR-83
     */
    public function testNoControllerHoldsAFieldReasonTable(): void
    {
        $offenders = [];
        $scanned = 0;

        foreach ($this->phpFilesIn(dirname(__DIR__, 3) . '/src/Controllers') as $file) {
            ++$scanned;

            foreach ($this->fieldReasonLiteralsIn($file->getPathname()) as [$line, $literal]) {
                $offenders[] = $file->getFilename() . ':' . $line . " — '" . $literal . "'";
            }
        }

        self::assertGreaterThan(0, $scanned, 'Nothing scanned — src/Controllers is missing.');
        self::assertSame(
            [],
            $offenders,
            'These controllers still speak the retired `field:reason` protocol:'
            . PHP_EOL . implode(PHP_EOL, $offenders),
        );
    }

    /** And this one is shown a table, so that its silence means something. */
    public function testTheFieldReasonScanRecognisesATableArm(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'eduqr-validation-') . '.php';
        file_put_contents(
            $file,
            "<?php\n"
            . "\$x = match (\$e->getMessage()) {\n"
            . "    'question_text:required' => 1,\n"
            . "    \"double_quoted:ignored\" => 2,\n"
            . "    'error.server_error' => 3,\n"
            . "    'Content-Type: application/json' => 4,\n"
            . "};\n",
        );

        try {
            self::assertSame(
                [[3, 'question_text:required']],
                $this->fieldReasonLiteralsIn($file),
            );
        } finally {
            unlink($file);
        }
    }

    // ── The four published divergences (API_SPEC.md §7.1) ─────────────────────

    /**
     * Unknown `question_type`. `QuestionController`'s arm answered 422
     * `invalid_question_type` with no field; `QuestionBankController`'s sent
     * `question_type`. §7.1 chose the field.
     *
     * @requirement NFR-83
     */
    public function testUnknownQuestionTypeAnswers422WithTheField(): void
    {
        $this->assertEnvelope(
            new ValidationException('invalid_question_type', 422, 'invalid_question_type', 'question_type'),
            422,
            'invalid_question_type',
            'question_type',
            t('common.error'),
        );
    }

    /**
     * Unknown import `stage`. Same disagreement, same resolution: the question
     * endpoint answered without a field and the bank endpoint fell through to
     * its generic arm.
     *
     * @requirement NFR-83
     */
    public function testUnknownStageAnswers422WithTheField(): void
    {
        $this->assertEnvelope(
            new ValidationException('invalid_stage', 422, 'invalid_stage', 'stage'),
            422,
            'invalid_stage',
            'stage',
            t('common.error'),
        );
    }

    /**
     * A missing `correct_answer` keeps the 400 `validation_error` the question
     * endpoint always gave it. The divergence is on the bank endpoint, which
     * had no arm for it and answered generically.
     *
     * @requirement NFR-83
     */
    public function testMissingCorrectAnswerAnswers400WithTheField(): void
    {
        $this->assertEnvelope(
            new ValidationException('required', 400, 'validation_error', 'correct_answer'),
            400,
            'validation_error',
            'correct_answer',
            t('validation.required'),
        );
    }

    /**
     * And an over-long `correct_answer`, for the same reason.
     *
     * @requirement NFR-83
     */
    public function testOverlongCorrectAnswerAnswers400WithTheField(): void
    {
        $this->assertEnvelope(
            new ValidationException('text_too_long', 400, 'validation_error', 'correct_answer'),
            400,
            'validation_error',
            'correct_answer',
            t('validation.text_too_long'),
        );
    }

    /**
     * The `field` member is what a client tests for, so a failure that points
     * at no single input must not carry an empty one.
     *
     * @requirement NFR-83
     */
    public function testAFailureWithNoNamedInputOmitsTheFieldMember(): void
    {
        $mapped = ApiController::domainEnvelope(
            new ValidationException('invalid_import_payload', 400, 'invalid_import_payload'),
        );

        self::assertSame(400, $mapped['status']);
        self::assertSame('invalid_import_payload', $mapped['body']['error']['code']);
        self::assertArrayNotHasKey('field', $mapped['body']['error']);
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function assertEnvelope(
        ValidationException $e,
        int $status,
        string $code,
        string $field,
        string $message,
    ): void {
        $mapped = ApiController::domainEnvelope($e);

        self::assertSame($status, $mapped['status']);
        self::assertSame(
            ['success' => false, 'error' => ['code' => $code, 'message' => $message, 'field' => $field]],
            $mapped['body'],
        );
    }

    private function assertNoUntypedValidation(string $directory): void
    {
        $offenders = [];
        $scanned = 0;

        foreach ($this->phpFilesIn($directory) as $file) {
            ++$scanned;

            if (in_array($file->getFilename(), self::ALLOWED_FILES, true)) {
                continue;
            }

            foreach ($this->untypedValidationIn($file->getPathname()) as [$line, $text]) {
                $offenders[] = $file->getFilename() . ':' . $line . ' — ' . $text;
            }
        }

        self::assertGreaterThan(0, $scanned, 'Nothing scanned — the path is wrong: ' . $directory);
        self::assertSame(
            [],
            $offenders,
            'A validation failure must be a ValidationException carrying its status, code and field:'
            . PHP_EOL . implode(PHP_EOL, $offenders),
        );
    }

    /**
     * @return list<array{int, string}> line number and trimmed source line
     */
    private function untypedValidationIn(string $path): array
    {
        $lines = file($path, FILE_IGNORE_NEW_LINES);
        self::assertNotFalse($lines, 'Could not read ' . $path);

        $hits = [];

        foreach ($lines as $index => $line) {
            if (preg_match(self::UNTYPED_VALIDATION, $line) === 1) {
                $hits[] = [$index + 1, trim($line)];
            }
        }

        return $hits;
    }

    /**
     * Single-quoted `field:reason` literals, read from tokens so that a comment
     * describing the retired protocol does not count as speaking it.
     *
     * @return list<array{int, string}> line number and literal value
     */
    private function fieldReasonLiteralsIn(string $path): array
    {
        $source = file_get_contents($path);
        self::assertNotFalse($source, 'Could not read ' . $path);

        $hits = [];

        foreach (token_get_all($source) as $token) {
            if (! is_array($token) || $token[0] !== T_CONSTANT_ENCAPSED_STRING) {
                continue;
            }
            if (! str_starts_with($token[1], "'")) {
                continue;
            }

            $value = substr($token[1], 1, -1);

            if (preg_match(self::FIELD_REASON, $value) === 1) {
                $hits[] = [$token[2], $value];
            }
        }

        return $hits;
    }

    /**
     * @return list<SplFileInfo>
     */
    private function phpFilesIn(string $directory): array
    {
        $files = [];
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($directory, RecursiveDirectoryIterator::SKIP_DOTS),
        );

        foreach ($iterator as $file) {
            if ($file instanceof SplFileInfo && $file->isFile() && $file->getExtension() === 'php') {
                $files[] = $file;
            }
        }

        return $files;
    }
}
