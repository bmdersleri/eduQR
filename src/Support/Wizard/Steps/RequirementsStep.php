<?php

declare(strict_types=1);

namespace EduQR\Support\Wizard\Steps;

use EduQR\Support\Wizard\Console;
use EduQR\Support\Wizard\Step;

/**
 * Wizard step 1: Verify system requirements.
 *
 * Checks PHP 8.2+, required extensions (pdo_mysql, mbstring, gd, intl, json),
 * optional extensions (apcu), and the presence of vendor/.
 *
 * Designed for Ubuntu + Nginx + MariaDB environments.
 * MariaDB is MySQL-compatible; pdo_mysql works with both.
 */
class RequirementsStep extends Step
{
    private const MIN_PHP             = '8.2.0';
    private const REQUIRED_EXTENSIONS = ['pdo_mysql', 'mbstring', 'gd', 'intl', 'json'];
    private const OPTIONAL_EXTENSIONS = ['apcu'];

    public function __construct(
        private readonly string $projectRoot,
    ) {}

    public function title(): string
    {
        return 'Sistem Gereksinimleri';
    }

    public function checkPhpVersion(): bool
    {
        return version_compare(PHP_VERSION, self::MIN_PHP, '>=');
    }

    public function checkExtension(string $ext): bool
    {
        return extension_loaded($ext);
    }

    public function checkVendor(): bool
    {
        return is_dir($this->projectRoot . '/vendor');
    }

    public function run(Console $console): bool
    {
        $allOk = true;

        // PHP sürümü
        if ($this->checkPhpVersion()) {
            $console->success('PHP ' . PHP_VERSION);
        } else {
            $console->error('PHP 8.2+ gerekli. Mevcut: ' . PHP_VERSION);
            $console->info('Ubuntu: sudo apt install php8.2-fpm php8.2-cli');
            $allOk = false;
        }

        // Zorunlu eklentiler
        foreach (self::REQUIRED_EXTENSIONS as $ext) {
            if ($this->checkExtension($ext)) {
                $console->success("ext-{$ext}");
            } else {
                $pkg = $this->ubuntuPackage($ext);
                $console->error("Eksik zorunlu eklenti: {$ext}");
                $console->info("  Ubuntu: sudo apt install {$pkg}");
                $allOk = false;
            }
        }

        // Opsiyonel eklentiler
        foreach (self::OPTIONAL_EXTENSIONS as $ext) {
            if ($this->checkExtension($ext)) {
                $console->success("ext-{$ext} (opsiyonel)");
            } else {
                $console->warn("ext-{$ext} yüklü değil (opsiyonel — rate limiting, DB bazlı fallback kullanır)");
                $console->info("  Ubuntu: sudo apt install php8.2-apcu");
            }
        }

        // Composer vendor/
        if ($this->checkVendor()) {
            $console->success('vendor/ mevcut');
        } else {
            $console->error('vendor/ bulunamadı — önce çalıştırın: composer install --no-dev -o');
            $allOk = false;
        }

        if (!$allOk) {
            $console->writeln();
            $console->error('Gereksinimler karşılanmıyor. Sorunları düzeltin ve sihirbazı tekrar başlatın.');
        }

        return $allOk;
    }

    /** Map a PHP extension name to its Ubuntu apt package name. */
    private function ubuntuPackage(string $ext): string
    {
        return match ($ext) {
            'pdo_mysql' => 'php8.2-mysql',
            'mbstring'  => 'php8.2-mbstring',
            'gd'        => 'php8.2-gd',
            'intl'      => 'php8.2-intl',
            'json'      => 'php8.2-common',
            default     => "php8.2-{$ext}",
        };
    }
}
