<?php

declare(strict_types=1);

namespace EduQR\Support\Wizard\Steps;

use EduQR\Support\Wizard\Console;
use EduQR\Support\Wizard\Step;
use PDOException;

/**
 * Wizard step 5: Create the first admin user account.
 *
 * Runs after DatabaseStep, so .env is fully configured and the DB is reachable.
 * Re-loads EduQR\Config so that UserRepository can connect via Database::connection().
 *
 * Password policy mirrors SECURITY_PRIVACY.md §4:
 *   - Minimum 10 characters
 *   - At least 3 of 4 character classes: lowercase, uppercase, digit, symbol
 */
class AdminStep extends Step
{
    public function __construct(
        private readonly string $projectRoot,
    ) {}

    public function title(): string
    {
        return 'İlk Yönetici Hesabı';
    }

    public function run(Console $console): bool
    {
        if (!$console->confirm('İlk yönetici hesabı oluşturulsun mu?', true)) {
            $console->warn('Hesap atlandı. Daha sonra: php bin/user-add.php --email=... --name=... --role=admin');
            return true;
        }

        $name  = $console->prompt('Ad Soyad');
        $email = $console->prompt('E-posta');
        $lang  = $console->prompt('Arayüz dili (en / tr)', 'tr');

        if (trim($name) === '' || trim($email) === '') {
            $console->error('Ad ve e-posta zorunludur.');
            return false;
        }

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $console->error("Geçersiz e-posta adresi: {$email}");
            return false;
        }

        if (!in_array($lang, ['en', 'tr'], true)) {
            $console->error("Dil değeri 'en' veya 'tr' olmalıdır.");
            return false;
        }

        $pass1 = $console->secret('Şifre (en az 10 karakter)');
        $pass2 = $console->secret('Şifre (tekrar)');

        if ($pass1 !== $pass2) {
            $console->error('Şifreler eşleşmiyor.');
            return false;
        }

        $error = $this->validatePassword($pass1);
        if ($error !== null) {
            $console->error($error);
            return false;
        }

        // .env artık hazır — Config ve bağımlılıkları yükle
        \EduQR\Config::load($this->projectRoot . '/.env');

        $hash = \EduQR\Services\AuthService::hashPassword($pass1);
        $repo = new \EduQR\Repositories\UserRepository();

        try {
            $id = $repo->create($email, $hash, $name, 'admin', $lang);
            $console->success("Yönetici hesabı oluşturuldu (ID: {$id}).");
            $console->info("  E-posta : {$email}");
            $console->info("  Ad      : {$name}");
            $console->info("  Rol     : admin");
            $console->info("  Dil     : {$lang}");
        } catch (PDOException $e) {
            if ($e->getCode() === '23000') {
                $console->error("'{$email}' adresiyle kayıtlı bir kullanıcı zaten var.");
                $console->info('Farklı bir e-posta kullanın veya mevcut hesabı kullanın.');
            } else {
                $console->error('Veritabanı hatası: ' . $e->getMessage());
            }
            return false;
        }

        return true;
    }

    /**
     * Validate password per SECURITY_PRIVACY.md §4.
     * Returns an error message string, or null if valid.
     */
    private function validatePassword(string $password): ?string
    {
        if (mb_strlen($password) < 10) {
            return 'Şifre en az 10 karakter olmalıdır.';
        }

        $score = 0;
        if (preg_match('/[a-z]/', $password)) { $score++; }
        if (preg_match('/[A-Z]/', $password)) { $score++; }
        if (preg_match('/[0-9]/', $password)) { $score++; }
        if (preg_match('/[^a-zA-Z0-9]/', $password)) { $score++; }

        if ($score < 3) {
            return 'Şifre; küçük harf, büyük harf, rakam ve sembolden en az 3\'ünü içermelidir.';
        }

        return null;
    }
}
