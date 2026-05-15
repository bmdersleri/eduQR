<?php

declare(strict_types=1);

namespace EduQR\Support\Wizard\Steps;

use EduQR\Support\Wizard\Console;
use EduQR\Support\Wizard\Step;

/**
 * Wizard step 2: Interactively create / update the .env file.
 *
 * Uses .env.example as the template and overwrites values line by line via regex.
 * DB credentials are intentionally skipped here — DatabaseStep fills them in next.
 * APP_SECRET is auto-generated (32 random bytes, URL-safe base64).
 */
class EnvStep extends Step
{
    public function __construct(
        private readonly string $projectRoot,
    ) {}

    public function title(): string
    {
        return 'Uygulama Yapılandırması';
    }

    public function run(Console $console): bool
    {
        $envPath     = $this->projectRoot . '/.env';
        $examplePath = $this->projectRoot . '/.env.example';

        if (!file_exists($examplePath)) {
            $console->error('.env.example bulunamadı — depo eksik veya bozuk.');
            return false;
        }

        if (file_exists($envPath)) {
            if (!$console->confirm('.env zaten mevcut. Yeniden yapılandırılsın mı?', false)) {
                $console->info('Mevcut .env kullanılıyor. (DB bilgileri bir sonraki adımda güncellenecek.)');
                return true;
            }
        }

        // Kullanıcıdan bilgi al
        $appUrl  = $console->prompt('Uygulama URL\'i (https://, trailing slash olmadan)', 'https://example.com');
        $appEnv  = $console->prompt('Ortam (production / development)', 'production');
        $debug   = ($appEnv === 'development') ? 'true' : 'false';
        $locale  = $console->prompt('Varsayılan dil (en / tr)', 'tr');

        // Ubuntu: log ve backup dizinleri web kökü dışında olmalı
        $parent    = dirname($this->projectRoot);
        $logPath   = $console->prompt('Log dizini (mutlak yol, web kökü dışında)', "{$parent}/eduqr-logs");
        $backupDir = $console->prompt('Yedekleme dizini (mutlak yol)', "{$parent}/eduqr-backups");

        // APP_SECRET: 32 kriptografik rastgele byte, URL-safe base64
        $secret = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');

        // .env.example'ı şablon olarak kullan; değerleri satır bazlı güncelle
        $content = (string) file_get_contents($examplePath);

        $subs = [
            '/^APP_URL=.*/m'            => "APP_URL={$appUrl}",
            '/^APP_ENV=.*/m'            => "APP_ENV={$appEnv}",
            '/^APP_DEBUG=.*/m'          => "APP_DEBUG={$debug}",
            '/^APP_SECRET=.*/m'         => "APP_SECRET={$secret}",
            '/^APP_LOCALE_DEFAULT=.*/m' => "APP_LOCALE_DEFAULT={$locale}",
            '/^LOG_PATH=.*/m'           => "LOG_PATH={$logPath}",
            '/^BACKUP_DIR=.*/m'         => "BACKUP_DIR={$backupDir}",
        ];

        foreach ($subs as $pattern => $replacement) {
            $content = (string) preg_replace($pattern, $replacement, $content);
        }

        if (file_put_contents($envPath, $content) === false) {
            $console->error('.env yazılamadı — dizin izinlerini kontrol edin.');
            return false;
        }

        $console->success('.env oluşturuldu.');
        $console->success('APP_SECRET otomatik oluşturuldu (32 byte, URL-safe base64).');
        $console->warn('DB bilgileri (host, port, db adı, kullanıcı, şifre) bir sonraki adımda girilecek.');

        return true;
    }
}
