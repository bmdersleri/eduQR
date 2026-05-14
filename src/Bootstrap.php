<?php

declare(strict_types=1);

namespace EduQR;

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
        if (!file_exists($autoload)) {
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
        $uri    = $_SERVER['REQUEST_URI']    ?? '/';
        $router->dispatch($method, $uri);
    }

    // ── Security headers ───────────────────────────────────────────────────────

    private static function sendSecurityHeaders(): void
    {
        $appUrl = Config::get('APP_URL', '');

        header("X-Frame-Options: DENY");
        header("X-Content-Type-Options: nosniff");
        header("X-XSS-Protection: 1; mode=block");
        header("Referrer-Policy: strict-origin-when-cross-origin");
        header("Permissions-Policy: geolocation=(), microphone=(), camera=()");

        // Content-Security-Policy — tightened in Phase 10
        $csp  = "default-src 'self'; ";
        $csp .= "script-src 'self'; ";
        $csp .= "style-src 'self' 'unsafe-inline'; ";
        $csp .= "img-src 'self' data:; ";
        $csp .= "font-src 'self'; ";
        $csp .= "connect-src 'self'; ";
        $csp .= "frame-ancestors 'none'";
        header("Content-Security-Policy: " . $csp);

        if (Config::bool('COOKIE_SECURE', true)) {
            header("Strict-Transport-Security: max-age=31536000; includeSubDomains");
        }
    }

    // ── Error handlers ─────────────────────────────────────────────────────────

    private static function registerErrorHandlers(string $projectRoot): void
    {
        $logPath  = Config::get('LOG_PATH', $projectRoot . '/logs');
        $debug    = Config::bool('APP_DEBUG', false);

        set_error_handler(function (int $errno, string $errstr, string $errfile, int $errline) use ($logPath, $debug): bool {
            if (!($errno & error_reporting())) {
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

            if (!headers_sent()) {
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
        $router->get('/', function (array $p) use ($projectRoot): void {
            header('Content-Type: text/html; charset=utf-8');
            include __DIR__ . '/../templates/home.php';
        });

        // ── Auth ──────────────────────────────────────────────────────────────
        $router->get('/login', function (array $p): void {
            http_response_code(200);
            header('Content-Type: text/html; charset=utf-8');
            include __DIR__ . '/../templates/auth/login.php';
        });

        // ── Admin ─────────────────────────────────────────────────────────────
        $router->get('/admin', function (array $p): void {
            http_response_code(302);
            header('Location: /admin/dashboard');
        });

        $router->get('/admin/dashboard', function (array $p): void {
            header('Content-Type: text/html; charset=utf-8');
            include __DIR__ . '/../templates/admin/dashboard.php';
        });

        // ── Public / Student ──────────────────────────────────────────────────
        // Registered in Phase 4+

        // ── API v1 ────────────────────────────────────────────────────────────
        $router->get('/api/v1/locales', function (array $p): void {
            (new Controllers\Api\LocaleController())->index();
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
    }
}
