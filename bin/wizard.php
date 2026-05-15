#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * bin/wizard.php — eduQR Nginx Kurulum Sihirbazı
 *
 * Hedef ortam: Ubuntu 22.04/24.04 + Nginx (önceden kurulu) + MariaDB 10.6+
 *
 * 6 adımlı interaktif kurulum:
 *   [1] Sistem gereksinimleri (PHP 8.2+, eklentiler, vendor/)
 *   [2] Uygulama yapılandırması (.env oluşturma, APP_SECRET üretimi)
 *   [3] Veritabanı bağlantısı ve migrasyonlar (MariaDB)
 *   [4] Nginx yapılandırması (deploy/nginx.conf.template → deploy/nginx.conf)
 *   [5] İlk yönetici hesabı
 *   [6] Smoke testleri (5 HTTP endpoint kontrolü)
 *
 * Kullanım:
 *   php bin/wizard.php
 *   php bin/wizard.php --skip-nginx      # Nginx adımını atla
 *   php bin/wizard.php --skip-admin      # Yönetici adımını atla
 *   php bin/wizard.php --skip-verify     # Smoke testlerini atla
 *
 * Çıkış kodları:
 *   0  Başarılı
 *   1  Adım hatası (hata mesajı ekrana yazıldı)
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('Bu script yalnızca komut satırından çalıştırılabilir.' . PHP_EOL);
}

$projectRoot = dirname(__DIR__);
$autoload    = $projectRoot . '/vendor/autoload.php';

if (!file_exists($autoload)) {
    fwrite(STDERR, "Hata: vendor/ dizini bulunamadı.\n");
    fwrite(STDERR, "Önce çalıştırın: composer install --no-dev --optimize-autoloader\n");
    exit(1);
}

require_once $autoload;

use EduQR\Support\Wizard\Console;
use EduQR\Support\Wizard\Steps\AdminStep;
use EduQR\Support\Wizard\Steps\DatabaseStep;
use EduQR\Support\Wizard\Steps\EnvStep;
use EduQR\Support\Wizard\Steps\NginxStep;
use EduQR\Support\Wizard\Steps\RequirementsStep;
use EduQR\Support\Wizard\Steps\VerifyStep;

// ── Atlanacak adımları belirle ────────────────────────────────────────────────
$skip = [];
foreach ($argv as $arg) {
    if (str_starts_with((string) $arg, '--skip-')) {
        $skip[] = substr((string) $arg, 7); // "nginx", "admin", "verify"
    }
}

// ── Adım sırası ───────────────────────────────────────────────────────────────
$steps = [
    'requirements' => new RequirementsStep($projectRoot),
    'env'          => new EnvStep($projectRoot),
    'database'     => new DatabaseStep($projectRoot),
];

if (!in_array('nginx', $skip, true)) {
    $steps['nginx'] = new NginxStep($projectRoot);
}

if (!in_array('admin', $skip, true)) {
    $steps['admin'] = new AdminStep($projectRoot);
}

if (!in_array('verify', $skip, true)) {
    $steps['verify'] = new VerifyStep($projectRoot);
}

// ── Sihirbazı başlat ──────────────────────────────────────────────────────────
$console = new Console();
$console->banner('eduQR Kurulum Sihirbazı — Ubuntu + Nginx + MariaDB');

$total = count($steps);
$i     = 0;

foreach ($steps as $step) {
    $i++;
    $console->section($i, $total, $step->title());

    if (!$step->run($console)) {
        $console->writeln();
        $console->error('Kurulum başarısız oldu.');
        $console->info('Sorunu düzeltin ve sihirbazı yeniden başlatın.');
        $console->info('Tamamlanan adımları atlamak için:');
        $console->info('  php bin/wizard.php --skip-nginx --skip-admin --skip-verify');
        exit(1);
    }
}

// ── Başarı mesajı ─────────────────────────────────────────────────────────────
$appUrl  = '';
$envPath = $projectRoot . '/.env';
if (file_exists($envPath)) {
    foreach ((array) file($envPath) as $line) {
        if (str_starts_with((string) $line, 'APP_URL=')) {
            $appUrl = trim(substr((string) $line, 8));
            break;
        }
    }
}

$console->banner('Kurulum Tamamlandı!');

if ($appUrl) {
    $console->success("Yönetici girişi: {$appUrl}/login");
}

$console->writeln();
$console->info('Önerilen son kontroller:');
$console->info('  sudo systemctl status nginx php8.2-fpm mariadb');
$console->info('  php bin/smoke.php --url=' . ($appUrl ?: 'https://yourdomain') . ' --verbose');
$console->writeln();

exit(0);
