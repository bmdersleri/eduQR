<?php

declare(strict_types=1);

namespace EduQR\Support\Wizard\Steps;

use EduQR\Support\Wizard\Console;
use EduQR\Support\Wizard\Step;
use PDO;
use PDOException;

/**
 * Wizard step 3: Collect MariaDB/MySQL credentials, test the connection,
 * update .env, and run pending SQL migrations inline.
 *
 * MariaDB 10.6+ is fully compatible with the pdo_mysql driver and the
 * mysql: DSN prefix — no driver changes required.
 *
 * Ubuntu: sudo systemctl status mariadb
 */
class DatabaseStep extends Step
{
    public function __construct(
        private readonly string $projectRoot,
    ) {}

    public function title(): string
    {
        return 'Veritabanı Yapılandırması (MariaDB)';
    }

    public function run(Console $console): bool
    {
        $console->info('MariaDB, MySQL-uyumlu pdo_mysql sürücüsü kullanır.');
        $console->info('Ubuntu: sudo systemctl status mariadb');
        $console->writeln();

        $host = $console->prompt('Host', 'localhost');
        $port = $console->prompt('Port', '3306');
        $name = $console->prompt('Veritabanı adı', 'eduqr');
        $user = $console->prompt('Kullanıcı adı', 'eduqr_app');
        $pass = $console->secret('Şifre');

        // Bağlantı testi
        try {
            $pdo = new PDO(
                "mysql:host={$host};port={$port};dbname={$name};charset=utf8mb4",
                $user,
                $pass,
                [
                    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES   => false,
                ],
            );
            $console->success('MariaDB bağlantısı başarılı!');
        } catch (PDOException $e) {
            $console->error('Bağlantı başarısız: ' . $e->getMessage());
            $console->info('Ubuntu: sudo mysql -u root -p → GRANT ALL ON eduqr.* TO \'eduqr_app\'@\'localhost\';');
            return false;
        }

        // .env'e DB bilgilerini yaz
        $this->updateEnv([
            'DB_HOST' => $host,
            'DB_PORT' => $port,
            'DB_NAME' => $name,
            'DB_USER' => $user,
            'DB_PASS' => $pass,
        ]);
        $console->success('.env veritabanı bilgileri kaydedildi.');

        // Migrasyonlar
        if (!$console->confirm('Migrasyonlar çalıştırılsın mı?', true)) {
            $console->warn('Migrasyonlar atlandı. Daha sonra: php bin/migrate.php');
            return true;
        }

        return $this->runMigrations($pdo, $console);
    }

    /**
     * Update or append KEY=value lines in .env.
     * @param array<string,string> $values
     */
    private function updateEnv(array $values): void
    {
        $envPath = $this->projectRoot . '/.env';
        $content = file_exists($envPath) ? (string) file_get_contents($envPath) : '';

        foreach ($values as $key => $value) {
            $pattern = '/^' . preg_quote($key, '/') . '=.*/m';
            if (preg_match($pattern, $content)) {
                $content = (string) preg_replace($pattern, "{$key}={$value}", $content);
            } else {
                $content .= "\n{$key}={$value}";
            }
        }

        file_put_contents($envPath, $content);
    }

    /**
     * Run pending SQL migration files using the already-open PDO connection.
     * Mirrors the logic in bin/migrate.php to avoid a subprocess call.
     */
    private function runMigrations(PDO $pdo, Console $console): bool
    {
        try {
            $pdo->exec("
                CREATE TABLE IF NOT EXISTS schema_migrations (
                    filename   VARCHAR(120) PRIMARY KEY,
                    applied_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            ");

            /** @var string[] $applied */
            $applied = $pdo->query('SELECT filename FROM schema_migrations')
                           ->fetchAll(PDO::FETCH_COLUMN);
            $applied = array_flip($applied);

            $dir   = $this->projectRoot . '/database/migrations';
            $files = glob($dir . '/*.sql') ?: [];
            sort($files);

            if (empty($files)) {
                $console->warn("Migrasyon dosyası bulunamadı: {$dir}");
                return true;
            }

            $ran     = 0;
            $skipped = 0;
            foreach ($files as $file) {
                $filename = basename($file);
                if (isset($applied[$filename])) {
                    $skipped++;
                    continue;
                }
                $sql = trim((string) file_get_contents($file));
                if ($sql === '') {
                    continue;
                }
                $pdo->exec($sql);
                $pdo->prepare('INSERT INTO schema_migrations (filename) VALUES (?)')
                    ->execute([$filename]);
                $console->success("  migrasyon: {$filename}");
                $ran++;
            }

            $summary = "{$ran} migrasyon uygulandı";
            if ($skipped > 0) {
                $summary .= ", {$skipped} zaten uygulanmış";
            }
            $console->success($summary . '.');
        } catch (PDOException $e) {
            $console->error('Migrasyon hatası: ' . $e->getMessage());
            return false;
        }

        return true;
    }
}
