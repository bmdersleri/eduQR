<?php

declare(strict_types=1);

namespace EduQR;

use EduQR\Support\Url;

/**
 * Application bootstrap. Called once from public/index.php.
 *
 * Responsibilities:
 * - Load composer autoloader
 * - Parse .env
 * - Register global error / exception handlers
 * - Build and dispatch the router
 */
final class Bootstrap
{
    public static function run(string $projectRoot): void
    {
        // 1. Autoloader
        $autoload = $projectRoot . '/vendor/autoload.php';
        if (! file_exists($autoload)) {
            http_response_code(500);
            error_log('[eduQR] vendor/autoload.php not found — run composer install');
            exit('Application not installed. Run: composer install');
        }
        require_once $autoload;

        // 2. Environment
        Config::load($projectRoot . '/.env');

        // 3. Error handling (before any output)
        self::registerErrorHandlers($projectRoot);

        // 4. Security headers on every response
        self::sendSecurityHeaders();

        // 5. Resolve locale (reads URI / query / cookie / Accept-Language, sets cookie)
        I18n\I18nMiddleware::resolve($projectRoot . '/locales');

        // 6. Build router and dispatch
        $router = new Router();
        self::registerRoutes($router, $projectRoot);

        $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
        $uri = $_SERVER['REQUEST_URI'] ?? '/';
        $router->dispatch($method, $uri);
    }

    // ── Security headers ───────────────────────────────────────────────────────

    private static function sendSecurityHeaders(): void
    {
        header('X-Frame-Options: DENY');
        header('X-Content-Type-Options: nosniff');
        header('X-XSS-Protection: 1; mode=block');
        header('Referrer-Policy: strict-origin-when-cross-origin');
        header('Permissions-Policy: geolocation=(), microphone=(), camera=()');

        // Content-Security-Policy
        $csp = "default-src 'self'; ";
        $csp .= "script-src 'self' 'unsafe-inline'; ";
        $csp .= "style-src 'self' 'unsafe-inline'; ";
        $csp .= "img-src 'self' data:; ";
        $csp .= "font-src 'self'; ";
        $csp .= "connect-src 'self'; ";
        $csp .= "frame-ancestors 'none'";
        header('Content-Security-Policy: ' . $csp);

        if (Config::bool('COOKIE_SECURE', true)) {
            header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
        }
    }

    // ── Error handlers ─────────────────────────────────────────────────────────

    private static function registerErrorHandlers(string $projectRoot): void
    {
        $logPath = Config::get('LOG_PATH', $projectRoot . '/logs');
        $debug = Config::bool('APP_DEBUG', false);

        set_error_handler(function (int $errno, string $errstr, string $errfile, int $errline) use ($logPath, $debug): bool {
            if (! ($errno & error_reporting())) {
                return false;
            }
            $msg = "[eduQR][error] {$errstr} in {$errfile}:{$errline}";
            error_log($msg, 3, rtrim($logPath, '/') . '/app.log');
            if ($debug) {
                throw new \ErrorException($errstr, 0, $errno, $errfile, $errline);
            }

            return true;
        });

        set_exception_handler(function (\Throwable $e) use ($logPath, $debug): void {
            $msg = sprintf(
                "[eduQR][exception] %s: %s in %s:%d\n%s",
                get_class($e),
                $e->getMessage(),
                $e->getFile(),
                $e->getLine(),
                $e->getTraceAsString()
            );
            error_log($msg, 3, rtrim($logPath, '/') . '/app.log');

            if (! headers_sent()) {
                http_response_code(500);
                header('Content-Type: text/html; charset=utf-8');
            }

            if ($debug) {
                echo '<pre>' . htmlspecialchars($msg, ENT_QUOTES, 'UTF-8') . '</pre>';
            } else {
                include __DIR__ . '/../templates/errors/500.php';
            }
        });
    }

    // ── Route registration ─────────────────────────────────────────────────────

    private static function registerRoutes(Router $router, string $projectRoot): void
    {
        $router->setNotFound(function (array $params): void {
            http_response_code(404);
            header('Content-Type: text/html; charset=utf-8');
            include __DIR__ . '/../templates/errors/404.php';
        });

        $router->setErrorHandler(function (\Throwable $e): void {
            // Re-throw to let the global exception handler deal with it
            throw $e;
        });

        // ── Home ──────────────────────────────────────────────────────────────
        $router->get('/', function (array $p): void {
            (new Controllers\Public\PageController())->home();
        });

        // ── Privacy notice (public, no auth, no session) ──────────────────────
        // Linked from templates/partials/privacy-notice.php on every student page.
        $router->get('/privacy', function (array $p): void {
            (new Controllers\Public\PageController())->privacy();
        });

        // ── Auth ──────────────────────────────────────────────────────────────
        $router->get('/login', function (array $p): void {
            (new Controllers\Public\AuthPageController())->login();
        });

        $router->get('/forgot-password', function (array $p): void {
            (new Controllers\Public\AuthPageController())->forgotPassword();
        });

        $router->get('/reset-password/{token}', function (array $p): void {
            (new Controllers\Public\AuthPageController())->resetPassword((string) $p['token']);
        });

        // ── Admin ─────────────────────────────────────────────────────────────
        $router->get('/admin', function (array $p): void {
            http_response_code(302);
            header('Location: ' . Url::path('/admin/dashboard'));
        });

        $router->get('/admin/dashboard', function (array $p): void {
            (new Controllers\Admin\DashboardController())->index();
        });

        // ── Admin: Courses ────────────────────────────────────────────────────
        $router->get('/admin/courses', function (array $p): void {
            (new Controllers\Admin\CourseController())->index();
        });

        // /new must come before /{id} so it is matched first
        $router->get('/admin/courses/new', function (array $p): void {
            (new Controllers\Admin\CourseController())->create();
        });

        $router->get('/admin/courses/{id}', function (array $p): void {
            (new Controllers\Admin\CourseController())->show((int) $p['id']);
        });

        $router->get('/admin/courses/{id}/analytics', function (array $p): void {
            (new Controllers\Admin\CourseController())->analytics((int) $p['id']);
        });

        $router->get('/admin/courses/{id}/edit', function (array $p): void {
            (new Controllers\Admin\CourseController())->edit((int) $p['id']);
        });

        // /new must come before /{id} so it is matched first
        $router->get('/admin/courses/{id}/sessions/new', function (array $p): void {
            (new Controllers\Admin\SessionController())->create((int) $p['id']);
        });

        $router->get('/admin/sessions/{id}', function (array $p): void {
            (new Controllers\Admin\SessionController())->show((int) $p['id']);
        });

        // ── Public: Projector view ────────────────────────────────────────────
        $router->get('/live/{short_code}', function (array $p): void {
            header('Content-Type: text/html; charset=utf-8');
            include __DIR__ . '/../templates/live/session.php';
        });

        // ── Public / Student ──────────────────────────────────────────────────
        // /wait must come before /{short_code} so it is matched first
        $router->get('/join/{short_code}/wait', function (array $p): void {
            header('Content-Type: text/html; charset=utf-8');
            include __DIR__ . '/../templates/student/wait.php';
        });

        $router->get('/join/{short_code}', function (array $p): void {
            header('Content-Type: text/html; charset=utf-8');
            include __DIR__ . '/../templates/student/join.php';
        });

        // ── Student answer page (T-709) ───────────────────────────────────────
        // answered must come before /{short_code} so it is matched first
        $router->get('/play/{short_code}/answered', function (array $p): void {
            header('Content-Type: text/html; charset=utf-8');
            include __DIR__ . '/../templates/student/answered.php';
        });

        $router->get('/play/{short_code}', function (array $p): void {
            header('Content-Type: text/html; charset=utf-8');
            include __DIR__ . '/../templates/student/play.php';
        });

        // ── Student batch answer page ─────────────────────────────────────────
        $router->get('/play/{short_code}/batch', function (array $p): void {
            header('Content-Type: text/html; charset=utf-8');
            include __DIR__ . '/../templates/student/batch.php';
        });

        // No-JS fallback: form POST to /play/{short_code} (T-710)
        $router->post('/play/{short_code}', function (array $p): void {
            include __DIR__ . '/../templates/student/play.php';
        });

        // ── API v1 ────────────────────────────────────────────────────────────

        // ── API: Health-check (T-1111) ────────────────────────────────────────────
        $router->get('/api/v1/health', function (array $p): void {
            (new Controllers\Api\HealthController())->check();
        });

        $router->get('/api/v1/locales', function (array $p): void {
            (new Controllers\Api\LocaleController())->index();
        });

        // ── API: Courses ──────────────────────────────────────────────────────
        $router->get('/api/v1/courses', function (array $p): void {
            (new Controllers\Api\CourseController())->index();
        });

        $router->post('/api/v1/courses', function (array $p): void {
            (new Controllers\Api\CourseController())->create();
        });

        $router->get('/api/v1/courses/{id}', function (array $p): void {
            (new Controllers\Api\CourseController())->show((int) $p['id']);
        });

        $router->get('/api/v1/courses/{id}/analytics', function (array $p): void {
            (new Controllers\Api\ReportController())->courseAnalytics((int) $p['id']);
        });

        $router->patch('/api/v1/courses/{id}', function (array $p): void {
            (new Controllers\Api\CourseController())->update((int) $p['id']);
        });

        $router->delete('/api/v1/courses/{id}', function (array $p): void {
            (new Controllers\Api\CourseController())->archive((int) $p['id']);
        });

        $router->post('/api/v1/courses/{id}/restore', function (array $p): void {
            (new Controllers\Api\CourseController())->restore((int) $p['id']);
        });

        // Course instructors (FR-97)
        $router->get('/api/v1/courses/{id}/instructors', function (array $p): void {
            (new Controllers\Api\CourseController())->listInstructors((int) $p['id']);
        });

        $router->post('/api/v1/courses/{id}/instructors', function (array $p): void {
            (new Controllers\Api\CourseController())->addInstructor((int) $p['id']);
        });

        $router->delete('/api/v1/courses/{id}/instructors/{userId}', function (array $p): void {
            (new Controllers\Api\CourseController())->removeInstructor((int) $p['id'], (int) $p['userId']);
        });

        // ── API: Sessions ─────────────────────────────────────────────────────
        $router->post('/api/v1/courses/{id}/sessions', function (array $p): void {
            (new Controllers\Api\SessionController())->create((int) $p['id']);
        });

        $router->get('/api/v1/sessions/{id}', function (array $p): void {
            (new Controllers\Api\SessionController())->show((int) $p['id']);
        });

        $router->patch('/api/v1/sessions/{id}', function (array $p): void {
            (new Controllers\Api\SessionController())->update((int) $p['id']);
        });

        $router->post('/api/v1/sessions/{id}/pause', function (array $p): void {
            (new Controllers\Api\SessionController())->pause((int) $p['id']);
        });

        $router->post('/api/v1/sessions/{id}/resume', function (array $p): void {
            (new Controllers\Api\SessionController())->resume((int) $p['id']);
        });

        $router->post('/api/v1/sessions/{id}/close', function (array $p): void {
            (new Controllers\Api\SessionController())->close((int) $p['id']);
        });

        $router->get('/api/v1/sessions/{id}/qr.png', function (array $p): void {
            (new Controllers\Api\SessionController())->qrPng((int) $p['id']);
        });

        $router->get('/api/v1/sessions/{id}/participants/count', function (array $p): void {
            (new Controllers\Api\SessionController())->participantCount((int) $p['id']);
        });

        // ── API: Questions ────────────────────────────────────────────────────
        // reorder must come before /{id} sub-routes to avoid ambiguity
        $router->post('/api/v1/sessions/{id}/questions/reorder', function (array $p): void {
            (new Controllers\Api\QuestionController())->reorder((int) $p['id']);
        });

        $router->post('/api/v1/sessions/{id}/questions/import', function (array $p): void {
            (new Controllers\Api\QuestionController())->import((int) $p['id']);
        });

        $router->get('/api/v1/courses/{id}/question-bank', function (array $p): void {
            (new Controllers\Api\QuestionBankController())->index((int) $p['id']);
        });

        $router->post('/api/v1/courses/{id}/question-bank/generate', function (array $p): void {
            (new Controllers\Api\QuestionBankController())->generate((int) $p['id']);
        });

        $router->post('/api/v1/questions/{id}/bank', function (array $p): void {
            (new Controllers\Api\QuestionBankController())->saveQuestion((int) $p['id']);
        });

        $router->post('/api/v1/sessions/{id}/questions/from-bank', function (array $p): void {
            (new Controllers\Api\QuestionBankController())->importToSession((int) $p['id']);
        });

        $router->get('/api/v1/sessions/{id}/questions', function (array $p): void {
            (new Controllers\Api\QuestionController())->index((int) $p['id']);
        });

        $router->post('/api/v1/sessions/{id}/questions', function (array $p): void {
            (new Controllers\Api\QuestionController())->create((int) $p['id']);
        });

        $router->patch('/api/v1/questions/{id}', function (array $p): void {
            (new Controllers\Api\QuestionController())->update((int) $p['id']);
        });

        $router->delete('/api/v1/questions/{id}', function (array $p): void {
            (new Controllers\Api\QuestionController())->destroy((int) $p['id']);
        });

        // activate / close must come before /{id} to avoid conflict
        $router->post('/api/v1/questions/{id}/activate', function (array $p): void {
            (new Controllers\Api\QuestionController())->activate((int) $p['id']);
        });

        $router->post('/api/v1/questions/{id}/close', function (array $p): void {
            (new Controllers\Api\QuestionController())->close((int) $p['id']);
        });

        $router->get('/api/v1/questions/{id}/themes', function (array $p): void {
            (new Controllers\Api\ReportController())->themes((int) $p['id']);
        });

        // image upload / delete (FR-39)
        $router->post('/api/v1/questions/{id}/image', function (array $p): void {
            (new Controllers\Api\QuestionImageController())->upload((int) $p['id']);
        });

        $router->delete('/api/v1/questions/{id}/image', function (array $p): void {
            (new Controllers\Api\QuestionImageController())->delete((int) $p['id']);
        });

        // ── API: Public active-question (student polling) ──────────────────────
        $router->get('/api/v1/sessions/{short_code}/active-question', function (array $p): void {
            (new Controllers\Api\PublicQuestionController())->activeQuestion($p['short_code']);
        });

        // ── API: Public session resolve ────────────────────────────────────────
        $router->get('/api/v1/public/sessions/{short_code}', function (array $p): void {
            (new Controllers\Api\PublicSessionController())->resolve($p['short_code']);
        });

        // ── API: Student join ─────────────────────────────────────────────────
        $router->post('/api/v1/sessions/{short_code}/join', function (array $p): void {
            (new Controllers\Api\JoinController())->join($p['short_code']);
        });

        // ── API: Answer submission (T-703) ────────────────────────────────────
        $router->post('/api/v1/answers', function (array $p): void {
            (new Controllers\Api\AnswerController())->submit();
        });

        // ── API: Comprehension reaction — student (T-1105) ────────────────────
        $router->post('/api/v1/reactions', function (array $p): void {
            (new Controllers\Api\ReactionController())->submit();
        });

        // ── API: Comprehension reactions — instructor (T-1105) ────────────────
        $router->get('/api/v1/sessions/{id}/reactions', function (array $p): void {
            (new Controllers\Api\ReactionController())->aggregates((int) $p['id']);
        });

        // ── API: Answer hide / unhide (T-810) ─────────────────────────────────
        // hide must come before unhide to avoid mis-matching
        $router->post('/api/v1/answers/{id}/hide', function (array $p): void {
            (new Controllers\Api\AnswerModerationController())->hide((int) $p['id']);
        });

        $router->post('/api/v1/answers/{id}/unhide', function (array $p): void {
            (new Controllers\Api\AnswerModerationController())->unhide((int) $p['id']);
        });

        // ── API: Reports (T-901, T-902, T-904) ────────────────────────────────
        // .pdf, .csv and .html must come before the plain /report route
        $router->get('/api/v1/sessions/{id}/report.pdf', function (array $p): void {
            (new Controllers\Api\ReportController())->pdf((int) $p['id']);
        });

        $router->get('/api/v1/sessions/{id}/report.csv', function (array $p): void {
            (new Controllers\Api\ReportController())->csv((int) $p['id']);
        });

        $router->get('/api/v1/sessions/{id}/report.html', function (array $p): void {
            (new Controllers\Api\ReportController())->html((int) $p['id']);
        });

        $router->get('/api/v1/sessions/{id}/report', function (array $p): void {
            (new Controllers\Api\ReportController())->reportJson((int) $p['id']);
        });

        // ── API: LMS exports — Moodle GIFT + gradebook CSV (T-1113) ───────────
        $router->get('/api/v1/sessions/{id}/questions.gift.txt', function (array $p): void {
            (new Controllers\Api\ReportController())->gift((int) $p['id']);
        });

        $router->get('/api/v1/sessions/{id}/gradebook.csv', function (array $p): void {
            (new Controllers\Api\ReportController())->gradebook((int) $p['id']);
        });

        // ── API: Session anonymize + delete (T-906, T-907) ────────────────────
        $router->post('/api/v1/sessions/{id}/anonymize', function (array $p): void {
            (new Controllers\Api\SessionController())->anonymize((int) $p['id']);
        });

        $router->delete('/api/v1/sessions/{id}', function (array $p): void {
            (new Controllers\Api\SessionController())->delete((int) $p['id']);
        });

        // ── API: Results — instructor (T-803) ─────────────────────────────────
        $router->get('/api/v1/sessions/{id}/results', function (array $p): void {
            (new Controllers\Api\ResultsController())->instructorResults((int) $p['id']);
        });

        // ── API: Results — student, gated (T-804) ─────────────────────────────
        $router->get('/api/v1/sessions/{short_code}/student-results', function (array $p): void {
            (new Controllers\Api\ResultsController())->studentResults($p['short_code']);
        });

        // ── Admin: Live results page (T-805) ──────────────────────────────────
        $router->get('/admin/sessions/{id}/results', function (array $p): void {
            (new Controllers\Admin\SessionController())->results((int) $p['id']);
        });

        // ── Admin: Report page (T-905) ────────────────────────────────────────
        $router->get('/admin/sessions/{id}/report', function (array $p): void {
            (new Controllers\Admin\SessionController())->report((int) $p['id']);
        });

        // ── Admin: Audit log viewer (T-1112) ──────────────────────────────────
        $router->get('/admin/audit-logs', function (array $p): void {
            (new Controllers\Admin\AuditLogController())->index();
        });

        // ── API: Audit logs JSON (T-1112) ─────────────────────────────────────
        $router->get('/api/v1/audit-logs', function (array $p): void {
            (new Controllers\Api\AuditLogController())->index();
        });

        // ── Projector: Live results (T-807) ──────────────────────────────────
        $router->get('/live/{short_code}/results', function (array $p): void {
            header('Content-Type: text/html; charset=utf-8');
            include __DIR__ . '/../templates/live/results.php';
        });

        $router->post('/api/v1/auth/login', function (array $p): void {
            (new Controllers\Api\AuthController())->login();
        });

        $router->post('/api/v1/auth/logout', function (array $p): void {
            (new Controllers\Api\AuthController())->logout();
        });

        $router->get('/api/v1/auth/me', function (array $p): void {
            (new Controllers\Api\AuthController())->me();
        });

        $router->post('/api/v1/auth/password-reset/request', function (array $p): void {
            (new Controllers\Api\AuthController())->requestPasswordReset();
        });

        $router->post('/api/v1/auth/password-reset/confirm', function (array $p): void {
            (new Controllers\Api\AuthController())->confirmPasswordReset();
        });
    }
}
