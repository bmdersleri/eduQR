<?php

declare(strict_types=1);

namespace EduQR;

use EduQR\Contracts\AnswerRepositoryInterface;
use EduQR\Contracts\AuditLogRepositoryInterface;
use EduQR\Contracts\CourseAnalyticsServiceInterface;
use EduQR\Contracts\CourseRepositoryInterface;
use EduQR\Contracts\ExportServiceInterface;
use EduQR\Contracts\LoginAttemptRepositoryInterface;
use EduQR\Contracts\OpenTextThemeExtractionServiceInterface;
use EduQR\Contracts\OptionRepositoryInterface;
use EduQR\Contracts\ParticipantRepositoryInterface;
use EduQR\Contracts\PasswordResetRepositoryInterface;
use EduQR\Contracts\QuestionBankRepositoryInterface;
use EduQR\Contracts\QuestionGenerationServiceInterface;
use EduQR\Contracts\QuestionRepositoryInterface;
use EduQR\Contracts\ReactionRepositoryInterface;
use EduQR\Contracts\ReportBuilderInterface;
use EduQR\Contracts\ResultsServiceInterface;
use EduQR\Contracts\ScoringServiceInterface;
use EduQR\Contracts\SessionRepositoryInterface;
use EduQR\Contracts\UserRepositoryInterface;
use EduQR\Repositories\AnswerRepository;
use EduQR\Repositories\AuditLogRepository;
use EduQR\Repositories\CourseRepository;
use EduQR\Repositories\LoginAttemptRepository;
use EduQR\Repositories\OptionRepository;
use EduQR\Repositories\ParticipantRepository;
use EduQR\Repositories\PasswordResetRepository;
use EduQR\Repositories\QuestionBankRepository;
use EduQR\Repositories\QuestionRepository;
use EduQR\Repositories\ReactionRepository;
use EduQR\Repositories\SessionRepository;
use EduQR\Repositories\UserRepository;
use EduQR\Services\AnswerService;
use EduQR\Services\AuthService;
use EduQR\Services\CourseAnalyticsService;
use EduQR\Services\CourseService;
use EduQR\Services\ExportService;
use EduQR\Services\OpenTextThemeExtractionService;
use EduQR\Services\ParticipantService;
use EduQR\Services\PasswordResetService;
use EduQR\Services\PollVersionService;
use EduQR\Services\QuestionBankService;
use EduQR\Services\QuestionGenerationService;
use EduQR\Services\QuestionService;
use EduQR\Services\ReactionService;
use EduQR\Services\ReportBuilder;
use EduQR\Services\ResultsService;
use EduQR\Services\ScoringService;
use EduQR\Services\SessionService;
use EduQR\Support\Database;

/**
 * The composition root: the one place a service or a repository is constructed.
 *
 * @requirement NFR-80
 *
 * Every collaborator has a named accessor with a concrete return type. There is
 * deliberately no `get(string $id)` and no reflection-based autowiring — ADR-0007
 * chose a hand-written map because an explicit map is greppable, and grep is the
 * tool that survives a shared host without Xdebug. Adding a dependency to a
 * service therefore changes exactly one line here.
 *
 * Nothing is built at class load. Each accessor resolves on first call and
 * memoizes the result, so one request builds one object graph and a request that
 * never touches the database never opens a connection. Memoizing is safe because
 * services hold no request state and repositories hold only the shared PDO handed
 * out by EduQR\Support\Database.
 *
 * Tests inject fakes through constructors — that is what the interfaces in
 * src/Contracts/ are for. There is no set()/override method, because one would
 * turn this composition root into a service locator.
 */
final class Container
{
    /**
     * Memoized instances, keyed by accessor name.
     *
     * @var array<string,object>
     */
    private static array $instances = [];

    /**
     * Drop every memoized instance.
     *
     * For tests only. Production code resolves collaborators and never needs the
     * graph torn down mid-request.
     */
    public static function reset(): void
    {
        self::$instances = [];
    }

    // ── Repositories ──────────────────────────────────────────────────────────

    public static function answerRepository(): AnswerRepositoryInterface
    {
        /** @var AnswerRepositoryInterface */
        return self::$instances['answerRepository'] ??= new AnswerRepository();
    }

    public static function auditLogRepository(): AuditLogRepositoryInterface
    {
        /** @var AuditLogRepositoryInterface */
        return self::$instances['auditLogRepository'] ??= new AuditLogRepository();
    }

    public static function courseRepository(): CourseRepositoryInterface
    {
        /** @var CourseRepositoryInterface */
        return self::$instances['courseRepository'] ??= new CourseRepository();
    }

    public static function loginAttemptRepository(): LoginAttemptRepositoryInterface
    {
        /** @var LoginAttemptRepositoryInterface */
        return self::$instances['loginAttemptRepository'] ??= new LoginAttemptRepository();
    }

    public static function optionRepository(): OptionRepositoryInterface
    {
        /** @var OptionRepositoryInterface */
        return self::$instances['optionRepository'] ??= new OptionRepository();
    }

    public static function participantRepository(): ParticipantRepositoryInterface
    {
        /** @var ParticipantRepositoryInterface */
        return self::$instances['participantRepository'] ??= new ParticipantRepository();
    }

    public static function passwordResetRepository(): PasswordResetRepositoryInterface
    {
        /** @var PasswordResetRepositoryInterface */
        return self::$instances['passwordResetRepository'] ??= new PasswordResetRepository();
    }

    public static function questionBankRepository(): QuestionBankRepositoryInterface
    {
        /** @var QuestionBankRepositoryInterface */
        return self::$instances['questionBankRepository'] ??= new QuestionBankRepository();
    }

    public static function questionRepository(): QuestionRepositoryInterface
    {
        /** @var QuestionRepositoryInterface */
        return self::$instances['questionRepository'] ??= new QuestionRepository();
    }

    public static function reactionRepository(): ReactionRepositoryInterface
    {
        /** @var ReactionRepositoryInterface */
        return self::$instances['reactionRepository'] ??= new ReactionRepository();
    }

    public static function sessionRepository(): SessionRepositoryInterface
    {
        /** @var SessionRepositoryInterface */
        return self::$instances['sessionRepository'] ??= new SessionRepository();
    }

    public static function userRepository(): UserRepositoryInterface
    {
        /** @var UserRepositoryInterface */
        return self::$instances['userRepository'] ??= new UserRepository();
    }

    // ── Services ──────────────────────────────────────────────────────────────

    public static function answerService(): AnswerService
    {
        /** @var AnswerService */
        return self::$instances['answerService'] ??= new AnswerService(
            self::answerRepository(),
            self::questionRepository(),
            self::sessionRepository(),
            self::participantRepository(),
            self::optionRepository(),
        );
    }

    public static function authService(): AuthService
    {
        /** @var AuthService */
        return self::$instances['authService'] ??= new AuthService(
            self::userRepository(),
            self::loginAttemptRepository(),
        );
    }

    /**
     * Course-level analytics (NFR-82). The only reporting unit built without a
     * connection: it reads through the session repository and the report unit,
     * so resolving it opens nothing on its own.
     */
    public static function courseAnalyticsService(): CourseAnalyticsServiceInterface
    {
        /** @var CourseAnalyticsServiceInterface */
        return self::$instances['courseAnalyticsService'] ??= new CourseAnalyticsService(
            self::sessionRepository(),
            self::courseRepository(),
            self::reportBuilder(),
        );
    }

    public static function courseService(): CourseService
    {
        /** @var CourseService */
        return self::$instances['courseService'] ??= new CourseService(
            self::courseRepository(),
            self::userRepository(),
        );
    }

    /**
     * The LMS file exports (NFR-82). Like the scoring unit it holds the shared
     * connection rather than reaching for one per query, so resolving it opens
     * that connection — every export it can build reads rows.
     */
    public static function exportService(): ExportServiceInterface
    {
        /** @var ExportServiceInterface */
        return self::$instances['exportService'] ??= new ExportService(
            self::sessionRepository(),
            self::questionRepository(),
            self::courseRepository(),
            Database::connection(),
            self::scoringService(),
        );
    }

    /**
     * The LLM-backed theme extractor. Its credentials come from the environment,
     * so it is built through its own ::fromConfig() factory.
     */
    public static function openTextThemeExtractionService(): OpenTextThemeExtractionServiceInterface
    {
        /** @var OpenTextThemeExtractionServiceInterface */
        return self::$instances['openTextThemeExtractionService'] ??= OpenTextThemeExtractionService::fromConfig();
    }

    public static function participantService(): ParticipantService
    {
        /** @var ParticipantService */
        return self::$instances['participantService'] ??= new ParticipantService(
            self::participantRepository(),
            self::sessionRepository(),
        );
    }

    public static function passwordResetService(): PasswordResetService
    {
        /** @var PasswordResetService */
        return self::$instances['passwordResetService'] ??= new PasswordResetService(
            self::userRepository(),
            self::passwordResetRepository(),
        );
    }

    /**
     * The version queries behind the conditional answers to a poll (NFR-76).
     *
     * Holds the shared connection rather than reaching for one per query, like
     * the other units that count rows. That does mean resolving it opens the
     * connection — no new cost, since nothing asks for a version without being
     * about to read the rows the version describes.
     */
    public static function pollVersionService(): PollVersionService
    {
        /** @var PollVersionService */
        return self::$instances['pollVersionService'] ??= new PollVersionService(
            self::sessionRepository(),
            self::questionRepository(),
            self::courseRepository(),
            Database::connection(),
        );
    }

    /**
     * The question bank, optionally wired to a caller-supplied generator.
     *
     * Passing a generator returns a one-off graph rather than the shared one, so
     * a test double never leaks into the instance the rest of the request sees.
     */
    public static function questionBankService(?QuestionGenerationServiceInterface $generator = null): QuestionBankService
    {
        if ($generator !== null) {
            return self::buildQuestionBankService($generator);
        }

        /** @var QuestionBankService */
        return self::$instances['questionBankService'] ??= self::buildQuestionBankService(
            self::questionGenerationService(),
        );
    }

    /**
     * The LLM-backed question generator. Its credentials come from the
     * environment, so it is built through its own ::fromConfig() factory.
     */
    public static function questionGenerationService(): QuestionGenerationServiceInterface
    {
        /** @var QuestionGenerationServiceInterface */
        return self::$instances['questionGenerationService'] ??= QuestionGenerationService::fromConfig();
    }

    public static function questionService(): QuestionService
    {
        /** @var QuestionService */
        return self::$instances['questionService'] ??= new QuestionService(
            self::questionRepository(),
            self::optionRepository(),
            self::sessionRepository(),
            self::courseRepository(),
        );
    }

    public static function reactionService(): ReactionService
    {
        /** @var ReactionService */
        return self::$instances['reactionService'] ??= new ReactionService(
            self::reactionRepository(),
            self::questionRepository(),
            self::sessionRepository(),
            self::participantRepository(),
            self::courseRepository(),
        );
    }

    /**
     * Post-session report assembly (NFR-82). Like the scoring and export units
     * it holds the shared connection rather than reaching for one per query,
     * because every report it can build counts rows.
     */
    public static function reportBuilder(): ReportBuilderInterface
    {
        /** @var ReportBuilderInterface */
        return self::$instances['reportBuilder'] ??= new ReportBuilder(
            self::sessionRepository(),
            self::questionRepository(),
            self::courseRepository(),
            Database::connection(),
            self::scoringService(),
        );
    }

    /**
     * Live results (NFR-82). Both of its non-repository collaborators are
     * required: every read path reaches the answers table, and FR-65 theme
     * extraction goes through the injected extractor rather than a
     * ::fromConfig() call of its own (NFR-80).
     */
    public static function resultsService(): ResultsServiceInterface
    {
        /** @var ResultsServiceInterface */
        return self::$instances['resultsService'] ??= new ResultsService(
            self::sessionRepository(),
            self::questionRepository(),
            self::courseRepository(),
            Database::connection(),
            self::openTextThemeExtractionService(),
        );
    }

    /**
     * Quiz scoring (NFR-82). Unlike the repositories, this one is handed the
     * shared connection rather than reaching for it per query, so resolving it
     * opens the connection. That is not a new cost: nothing asks for a score
     * without also reading the answers it scores.
     */
    public static function scoringService(): ScoringServiceInterface
    {
        /** @var ScoringServiceInterface */
        return self::$instances['scoringService'] ??= new ScoringService(
            self::questionRepository(),
            Database::connection(),
        );
    }

    public static function sessionService(): SessionService
    {
        /** @var SessionService */
        return self::$instances['sessionService'] ??= new SessionService(
            self::sessionRepository(),
            self::courseRepository(),
        );
    }

    // ── Private ───────────────────────────────────────────────────────────────

    private static function buildQuestionBankService(
        QuestionGenerationServiceInterface $generator
    ): QuestionBankService {
        return new QuestionBankService(
            self::questionBankRepository(),
            self::questionRepository(),
            self::optionRepository(),
            self::sessionRepository(),
            self::courseRepository(),
            self::questionService(),
            $generator,
        );
    }
}
