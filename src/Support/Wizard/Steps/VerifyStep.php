<?php

declare(strict_types=1);

namespace EduQR\Support\Wizard\Steps;

use EduQR\Support\Wizard\Console;
use EduQR\Support\Wizard\Step;

/**
 * Wizard step 6: Smoke-test the live installation via HTTP.
 *
 * Mirrors the checks in bin/smoke.php but reads APP_URL from .env as the
 * default base URL and outputs coloured pass/fail per endpoint.
 *
 * SSL peer verification is disabled so self-signed / staging certificates
 * do not block the wizard. Production sites should re-run bin/smoke.php
 * with full TLS verification enabled.
 */
class VerifyStep extends Step
{
    /** [path, expected_status] — auth-gated routes accept 401 / 302 / 403 */
    private const CHECKS = [
        ['/',                200],
        ['/login',           200],
        ['/api/v1/locales',  200],
        ['/api/v1/auth/me',  401],
        ['/api/v1/courses',  401],
    ];

    public function __construct(
        private readonly string $projectRoot,
    ) {}

    public function title(): string
    {
        return 'Doğrulama';
    }

    public function run(Console $console): bool
    {
        $console->info('Nginx yeniden yüklendikten sonra devam edin.');

        if (!$console->confirm('Smoke testleri çalıştırılsın mı?', true)) {
            $console->warn('Doğrulama atlandı. Daha sonra: php bin/smoke.php --url=https://... --verbose');
            return true;
        }

        $base = $this->readAppUrl();
        $base = $console->prompt('Test base URL\'i', $base);
        $base = rtrim($base, '/');

        if ($base === '') {
            $console->error('Base URL boş olamaz.');
            return false;
        }

        $ctx = stream_context_create([
            'http' => [
                'method'          => 'GET',
                'follow_location' => 0,        // redirect'leri takip etme — statüyü doğrudan oku
                'timeout'         => 10,
                'ignore_errors'   => true,
                'header'          => "Accept: application/json, text/html\r\n",
            ],
            'ssl' => [
                'verify_peer'      => false,   // Staging / self-signed sertifikalara izin ver
                'verify_peer_name' => false,
            ],
        ]);

        $failed = 0;
        foreach (self::CHECKS as [$path, $expected]) {
            $url    = $base . $path;
            @file_get_contents($url, false, $ctx);
            $status = $this->parseStatus($http_response_header ?? []);

            $ok = $expected === 401
                ? in_array($status, [401, 302, 403], true)
                : $status === $expected;

            $label = sprintf('%-42s %s', $path, $status > 0 ? $status : '(bağlantı hatası)');
            if ($ok) {
                $console->success($label);
            } else {
                $console->error("{$label}  (beklenen: {$expected})");
                $failed++;
            }
        }

        if ($failed > 0) {
            $console->writeln();
            $console->warn("{$failed} kontrol başarısız.");
            $console->info('Kontrol listesi:');
            $console->info('  sudo nginx -t && sudo systemctl status nginx');
            $console->info('  sudo systemctl status php8.2-fpm');
            $console->info('  sudo systemctl status mariadb');
            $console->info('  tail -30 /var/log/nginx/eduqr-error.log');
            return false;
        }

        return true;
    }

    private function readAppUrl(): string
    {
        $envPath = $this->projectRoot . '/.env';
        if (!file_exists($envPath)) {
            return 'https://example.com';
        }
        foreach ((array) file($envPath) as $line) {
            if (str_starts_with((string) $line, 'APP_URL=')) {
                return rtrim(trim(substr((string) $line, 8)), '/');
            }
        }
        return 'https://example.com';
    }

    /** @param string[] $headers */
    private function parseStatus(array $headers): int
    {
        $status = 0;
        foreach ($headers as $h) {
            if (preg_match('#HTTP/\S+ (\d+)#', $h, $m)) {
                $status = (int) $m[1];
            }
        }
        return $status;
    }
}
