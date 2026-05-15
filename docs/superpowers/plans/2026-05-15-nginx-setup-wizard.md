# Nginx Kurulum Sihirbazı Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Bir sistem yöneticisini `php bin/wizard.php` komutuyla interaktif olarak Nginx tabanlı bir sunucuda eduQR'yi sıfırdan çalışır hale getiren adım adım CLI kurulum sihirbazı oluşturmak.

**Architecture:** Sihirbaz `bin/wizard.php` giriş noktasından başlar; her adım (`Step` subclass) bağımsız bir sınıftır. `Console` sınıfı enjekte edilebilir `$stdin`/`$stdout` stream'leriyle I/O'yu soyutlar — bu sayede unit test yazılabilir. Adımlar sırayla çalışır; herhangi biri `false` döndürürse wizard durur ve kullanıcı `--skip-<adım>` bayraklarıyla tamamlanan adımları atlayarak yeniden başlatabilir. Nginx config'i template (`deploy/nginx.conf.template`) üzerinden `{{PLACEHOLDER}}` ikamesiyle üretilir.

**Tech Stack:** PHP 8.2+ CLI, PDO/MySQL, Composer PSR-4, PHPUnit 11, mevcut bin/ scriptlerindeki pattern'ler.

---

## File Structure

```
bin/
└── wizard.php                         ← Giriş noktası; adımları sıraya dizer

src/Support/Wizard/
├── Console.php                        ← ANSI renkli I/O; enjekte edilebilir streams
├── Step.php                           ← Abstract base class
└── Steps/
    ├── RequirementsStep.php           ← PHP 8.2+, ext, vendor/ kontrolü
    ├── EnvStep.php                    ← .env interaktif oluşturma
    ├── DatabaseStep.php               ← DB bağlantı testi + migration
    ├── NginxStep.php                  ← nginx.conf template rendering
    ├── AdminStep.php                  ← İlk yönetici hesabı oluşturma
    └── VerifyStep.php                 ← Smoke testleri (5 HTTP check)

deploy/
├── nginx.conf.example                 ← Zaten mevcut (statik örnek — dokunma)
└── nginx.conf.template                ← YENİ: {{PLACEHOLDER}} sözdizimi ile şablon

tests/Unit/Wizard/
├── ConsoleTest.php                    ← prompt() / confirm() stream enjeksiyonu
├── RequirementsStepTest.php           ← checkPhpVersion, checkExtension, checkVendor
└── NginxStepTest.php                  ← render() pure-function testi
```

**Çalıştırıldığında görünen akış:**
```
$ php bin/wizard.php

╔════════════════════════════════════════════════╗
║      eduQR Kurulum Sihirbazı — Nginx           ║
╚════════════════════════════════════════════════╝

[1/6] Sistem Gereksinimleri
  ✓ PHP 8.2.10
  ✓ ext-pdo_mysql, ext-mbstring, ext-gd, ext-intl, ext-json
  ! ext-apcu yüklü değil (opsiyonel — rate limiting devre dışı)
  ✓ vendor/ mevcut

[2/6] Uygulama Yapılandırması
  Uygulama URL'i [https://example.com]: https://eduqr.bau.edu.tr
  Varsayılan dil [tr]:
  Log dizini [...]: /home/eduqr/logs
  ✓ .env oluşturuldu (APP_SECRET otomatik üretildi)

[3/6] Veritabanı Yapılandırması
  Host [localhost]:
  Veritabanı adı: eduqr_prod
  Kullanıcı adı: eduqr_app
  Şifre: ****
  ✓ Bağlantı başarılı!
  ✓ 12 migrasyon uygulandı.

[4/6] Nginx Yapılandırması
  Alan adı: eduqr.bau.edu.tr
  PHP-FPM socket [unix:/run/php/php8.2-fpm.sock]:
  TLS sertifika: /etc/letsencrypt/live/eduqr.bau.edu.tr/fullchain.pem
  ✓ deploy/nginx.conf oluşturuldu.
  → sudo cp deploy/nginx.conf /etc/nginx/sites-available/eduqr
  → sudo ln -s ... sites-enabled/
  → sudo nginx -t && sudo systemctl reload nginx

[5/6] İlk Yönetici Hesabı
  Ad Soyad: İsmail Kırbaş
  E-posta: ismailkirbas@bau.edu.tr
  ✓ Yönetici hesabı oluşturuldu (ID: 1)

[6/6] Doğrulama
  ✓ /                    200
  ✓ /login               200
  ✓ /api/v1/locales      200
  ✓ /api/v1/auth/me      401
  ✓ /api/v1/courses      401

╔════════════════════════════════════════════════╗
║        Kurulum tamamlandı!                     ║
╚════════════════════════════════════════════════╝
  → Giriş: https://eduqr.bau.edu.tr/login
```

---

## Task 1: Console I/O Sınıfı + Unit Testler

**Files:**
- Create: `src/Support/Wizard/Console.php`
- Create: `tests/Unit/Wizard/ConsoleTest.php`

- [ ] **Step 1: Failing testi yaz**

`tests/Unit/Wizard/ConsoleTest.php`:
```php
<?php

declare(strict_types=1);

namespace EduQR\Tests\Unit\Wizard;

use EduQR\Support\Wizard\Console;
use PHPUnit\Framework\TestCase;

class ConsoleTest extends TestCase
{
    /** @return array{Console, resource} */
    private function makeConsole(string $input): array
    {
        $stdin  = fopen('php://memory', 'r+');
        $stdout = fopen('php://memory', 'r+');
        fwrite($stdin, $input);
        rewind($stdin);
        $console = new Console($stdin, $stdout);
        return [$console, $stdout];
    }

    private function readOutput(mixed $stdout): string
    {
        rewind($stdout);
        return stream_get_contents($stdout);
    }

    public function testPromptReturnsInputWhenProvided(): void
    {
        [$console] = $this->makeConsole("merhaba\n");
        $result = $console->prompt('Bir şey girin', 'varsayılan');
        $this->assertSame('merhaba', $result);
    }

    public function testPromptReturnsDefaultWhenInputIsEmpty(): void
    {
        [$console] = $this->makeConsole("\n");
        $result = $console->prompt('Bir şey girin', 'varsayılan');
        $this->assertSame('varsayılan', $result);
    }

    public function testPromptReturnsEmptyStringWhenNoDefault(): void
    {
        [$console] = $this->makeConsole("\n");
        $result = $console->prompt('Bir şey girin');
        $this->assertSame('', $result);
    }

    public function testConfirmTrueOnE(): void
    {
        [$console] = $this->makeConsole("e\n");
        $this->assertTrue($console->confirm('Devam?'));
    }

    public function testConfirmTrueOnEvet(): void
    {
        [$console] = $this->makeConsole("evet\n");
        $this->assertTrue($console->confirm('Devam?'));
    }

    public function testConfirmTrueOnY(): void
    {
        [$console] = $this->makeConsole("y\n");
        $this->assertTrue($console->confirm('Devam?'));
    }

    public function testConfirmFalseOnH(): void
    {
        [$console] = $this->makeConsole("h\n");
        $this->assertFalse($console->confirm('Devam?'));
    }

    public function testConfirmReturnsDefaultTrueOnEmptyInput(): void
    {
        [$console] = $this->makeConsole("\n");
        $this->assertTrue($console->confirm('Devam?', true));
    }

    public function testConfirmReturnsDefaultFalseOnEmptyInput(): void
    {
        [$console] = $this->makeConsole("\n");
        $this->assertFalse($console->confirm('Devam?', false));
    }

    public function testSuccessWritesToStdout(): void
    {
        [$console, $stdout] = $this->makeConsole('');
        $console->success('işlem tamam');
        $output = $this->readOutput($stdout);
        $this->assertStringContainsString('işlem tamam', $output);
    }

    public function testErrorWritesToStdout(): void
    {
        [$console, $stdout] = $this->makeConsole('');
        $console->error('bir hata oluştu');
        $output = $this->readOutput($stdout);
        $this->assertStringContainsString('bir hata oluştu', $output);
    }
}
```

- [ ] **Step 2: Testin fail ettiğini doğrula**

```
C:\xampp8\php\php.exe vendor\bin\phpunit tests/Unit/Wizard/ConsoleTest.php
```
Beklenen: `Error: Class "EduQR\Support\Wizard\Console" not found`

- [ ] **Step 3: Console.php implementasyonunu yaz**

`src/Support/Wizard/Console.php`:
```php
<?php

declare(strict_types=1);

namespace EduQR\Support\Wizard;

/**
 * ANSI-colored CLI I/O helper for the setup wizard.
 *
 * $stdin/$stdout are injectable for unit testing:
 *   $console = new Console(fopen('php://memory','r+'), fopen('php://memory','r+'));
 */
class Console
{
    private const RESET  = "\033[0m";
    private const GREEN  = "\033[32m";
    private const RED    = "\033[31m";
    private const YELLOW = "\033[33m";
    private const CYAN   = "\033[36m";
    private const BOLD   = "\033[1m";

    /** @param resource $stdin  @param resource $stdout */
    public function __construct(
        private mixed $stdin  = STDIN,
        private mixed $stdout = STDOUT,
    ) {}

    public function writeln(string $text = ''): void
    {
        fwrite($this->stdout, $text . "\n");
    }

    public function success(string $text): void
    {
        fwrite($this->stdout, self::GREEN . "  ✓ " . self::RESET . $text . "\n");
    }

    public function error(string $text): void
    {
        fwrite($this->stdout, self::RED . "  ✗ " . self::RESET . $text . "\n");
    }

    public function warn(string $text): void
    {
        fwrite($this->stdout, self::YELLOW . "  ! " . self::RESET . $text . "\n");
    }

    public function info(string $text): void
    {
        fwrite($this->stdout, self::CYAN . "  → " . self::RESET . $text . "\n");
    }

    public function banner(string $title): void
    {
        $width = 48;
        $line  = str_repeat('═', $width);
        $pad   = str_pad($title, $width, ' ', STR_PAD_BOTH);
        $this->writeln();
        $this->writeln(self::BOLD . "╔{$line}╗" . self::RESET);
        $this->writeln(self::BOLD . "║{$pad}║" . self::RESET);
        $this->writeln(self::BOLD . "╚{$line}╝" . self::RESET);
        $this->writeln();
    }

    public function section(int $step, int $total, string $title): void
    {
        $this->writeln();
        $this->writeln(self::BOLD . "[{$step}/{$total}] {$title}" . self::RESET);
    }

    /**
     * Prompt the user for input. Returns $default on empty or EOF.
     */
    public function prompt(string $question, string $default = ''): string
    {
        $hint = $default !== '' ? " [{$default}]" : '';
        fwrite($this->stdout, "  {$question}{$hint}: ");
        $line = fgets($this->stdin);
        if ($line === false) {
            return $default;
        }
        $input = trim($line);
        return $input !== '' ? $input : $default;
    }

    /**
     * Prompt for a password (disables echo on Unix, falls back on Windows).
     */
    public function secret(string $question): string
    {
        fwrite($this->stdout, "  {$question}: ");
        if (PHP_OS_FAMILY !== 'Windows' && $this->stdin === STDIN) {
            system('stty -echo');
        }
        $line = fgets($this->stdin);
        if (PHP_OS_FAMILY !== 'Windows' && $this->stdin === STDIN) {
            system('stty echo');
            fwrite($this->stdout, "\n");
        }
        return $line !== false ? trim($line) : '';
    }

    /**
     * Ask a yes/no question. Accepts: e/evet/y/yes/1 (true) or h/hayir/n/no/0 (false).
     * Returns $default on empty input or EOF.
     */
    public function confirm(string $question, bool $default = true): bool
    {
        $hint = $default ? 'E/h' : 'e/H';
        fwrite($this->stdout, "  {$question} [{$hint}]: ");
        $line = fgets($this->stdin);
        if ($line === false) {
            return $default;
        }
        $input = strtolower(trim($line));
        if ($input === '') {
            return $default;
        }
        return in_array($input, ['e', 'evet', 'y', 'yes', '1'], true);
    }
}
```

- [ ] **Step 4: Testlerin geçtiğini doğrula**

```
C:\xampp8\php\php.exe vendor\bin\phpunit tests/Unit/Wizard/ConsoleTest.php
```
Beklenen: `OK (11 tests, 11 assertions)`

- [ ] **Step 5: Commit**

```bash
git add src/Support/Wizard/Console.php tests/Unit/Wizard/ConsoleTest.php
git commit -m "feat: add wizard Console I/O helper with unit tests"
```

---

## Task 2: Step Abstract Base Class

**Files:**
- Create: `src/Support/Wizard/Step.php`

*Bu sınıf abstract olduğundan doğrudan unit testi yazılmaz; subclass testleri kapsar.*

- [ ] **Step 1: Step.php oluştur**

`src/Support/Wizard/Step.php`:
```php
<?php

declare(strict_types=1);

namespace EduQR\Support\Wizard;

/**
 * Abstract base for each wizard step.
 *
 * Implement title() for the section header.
 * Implement run() to perform the step logic.
 * Return true to continue, false to abort the wizard.
 */
abstract class Step
{
    abstract public function title(): string;

    abstract public function run(Console $console): bool;
}
```

- [ ] **Step 2: Autoloader'ın sınıfı tanıdığını doğrula**

```bash
C:\xampp8\php\php.exe -r "require 'vendor/autoload.php'; new class extends EduQR\Support\Wizard\Step { public function title(): string { return ''; } public function run(EduQR\Support\Wizard\Console \$c): bool { return true; } }; echo 'OK';"
```
Beklenen: `OK`

- [ ] **Step 3: Commit**

```bash
git add src/Support/Wizard/Step.php
git commit -m "feat: add wizard Step abstract base class"
```

---

## Task 3: Nginx Config Template

**Files:**
- Create: `deploy/nginx.conf.template`

*Mevcut `deploy/nginx.conf.example` dokunulmadan kalır. Template `{{PLACEHOLDER}}` sözdizimini kullanır.*

- [ ] **Step 1: Template dosyasını oluştur**

`deploy/nginx.conf.template`:
```nginx
# ─────────────────────────────────────────────────────────────────────────────
# eduQR — Nginx yapılandırması
# Oluşturma tarihi: {{GENERATED_AT}}
# bin/wizard.php tarafından otomatik oluşturulmuştur.
#
# Bu dosyayı inceledikten sonra etkinleştirin:
#   sudo cp {{OUTPUT_PATH}} /etc/nginx/sites-available/eduqr
#   sudo ln -s /etc/nginx/sites-available/eduqr /etc/nginx/sites-enabled/eduqr
#   sudo nginx -t && sudo systemctl reload nginx
# ─────────────────────────────────────────────────────────────────────────────

# HTTP → HTTPS yönlendirmesi
server {
    listen      80;
    listen      [::]:80;
    server_name {{DOMAIN}};

    # Let's Encrypt ACME doğrulama
    location ^~ /.well-known/acme-challenge/ {
        root /var/www/certbot;
    }

    location / {
        return 301 https://$host$request_uri;
    }
}

# Ana HTTPS sunucusu
server {
    listen      443 ssl;
    listen      [::]:443 ssl;
    http2       on;
    server_name {{DOMAIN}};

    root  {{PROJECT_ROOT}}/public;
    index index.php;

    # ── TLS ───────────────────────────────────────────────────────────────────
    ssl_certificate     {{SSL_CERT}};
    ssl_certificate_key {{SSL_KEY}};
    ssl_protocols       TLSv1.2 TLSv1.3;
    ssl_prefer_server_ciphers off;
    ssl_session_timeout 1d;
    ssl_session_cache   shared:SSL:10m;

    # ── Loglama ───────────────────────────────────────────────────────────────
    access_log /var/log/nginx/eduqr-access.log;
    error_log  /var/log/nginx/eduqr-error.log;

    # ── Güvenlik başlıkları ───────────────────────────────────────────────────
    # Bootstrap.php da bu başlıkları gönderir; nginx başlıkları önceliklidir.
    add_header X-Frame-Options            "DENY"                                    always;
    add_header X-Content-Type-Options     "nosniff"                                 always;
    add_header Referrer-Policy            "strict-origin-when-cross-origin"         always;
    add_header Permissions-Policy         "geolocation=(), microphone=(), camera=()" always;
    add_header Strict-Transport-Security  "max-age=31536000; includeSubDomains"     always;
    # CSP PHP tarafından gönderilir (Bootstrap::sendSecurityHeaders)

    # Parmak izi başlıklarını kaldır (ngx_headers_more gerektirir)
    # more_clear_headers Server X-Powered-By;

    # ── Front controller ──────────────────────────────────────────────────────
    location / {
        try_files $uri $uri/ /index.php$is_args$args;
    }

    # ── PHP-FPM ───────────────────────────────────────────────────────────────
    location ~ \.php$ {
        # Nginx PHP path-info exploit koruması
        try_files $uri =404;

        fastcgi_pass  {{FPM_SOCKET}};
        fastcgi_index index.php;
        fastcgi_param SCRIPT_FILENAME $realpath_root$fastcgi_script_name;
        include       fastcgi_params;

        fastcgi_connect_timeout 10s;
        fastcgi_read_timeout    60s;
    }

    # ── Hassas dosyalar ───────────────────────────────────────────────────────
    location ~* \.(env|log|sql|json|lock|sh|bak|old|pem|key)$ {
        deny all;
    }

    # ── Nokta-dosyaları (ACME hariç) ─────────────────────────────────────────
    location ~ /\.(?!well-known) {
        deny all;
    }

    # ── Statik varlık önbelleği ───────────────────────────────────────────────
    location ~* \.(css|js|png|jpg|jpeg|ico|svg|woff2|webp)$ {
        expires    1y;
        add_header Cache-Control "public, immutable";
        access_log off;
    }
}
```

- [ ] **Step 2: Template'in placeholder'lar içerdiğini doğrula**

```bash
C:\xampp8\php\php.exe -r "
\$tpl = file_get_contents('deploy/nginx.conf.template');
preg_match_all('/\{\{(\w+)\}\}/', \$tpl, \$m);
echo implode(', ', array_unique(\$m[1])) . PHP_EOL;
"
```
Beklenen çıktı:
```
GENERATED_AT, OUTPUT_PATH, DOMAIN, PROJECT_ROOT, SSL_CERT, SSL_KEY, FPM_SOCKET
```

- [ ] **Step 3: Commit**

```bash
git add deploy/nginx.conf.template
git commit -m "feat: add nginx.conf.template with wizard placeholders"
```

---

## Task 4: RequirementsStep + Unit Testler

**Files:**
- Create: `src/Support/Wizard/Steps/RequirementsStep.php`
- Create: `tests/Unit/Wizard/RequirementsStepTest.php`

- [ ] **Step 1: Failing testleri yaz**

`tests/Unit/Wizard/RequirementsStepTest.php`:
```php
<?php

declare(strict_types=1);

namespace EduQR\Tests\Unit\Wizard;

use EduQR\Support\Wizard\Steps\RequirementsStep;
use PHPUnit\Framework\TestCase;

class RequirementsStepTest extends TestCase
{
    public function testPhpVersionPassesFor82Plus(): void
    {
        $step = new RequirementsStep('/tmp');
        // Test ortamı zaten PHP 8.2+ ile çalışıyor
        $this->assertTrue($step->checkPhpVersion());
    }

    public function testJsonExtensionAlwaysAvailable(): void
    {
        $step = new RequirementsStep('/tmp');
        $this->assertTrue($step->checkExtension('json'));
    }

    public function testPdoMysqlExtensionAvailable(): void
    {
        $step = new RequirementsStep('/tmp');
        // XAMPP 8 kurulumunda pdo_mysql mevcut
        $this->assertTrue($step->checkExtension('pdo_mysql'));
    }

    public function testNonExistentExtensionReturnsFalse(): void
    {
        $step = new RequirementsStep('/tmp');
        $this->assertFalse($step->checkExtension('nonexistent_extension_xyz'));
    }

    public function testCheckVendorTrueWhenDirExists(): void
    {
        $tmpDir = sys_get_temp_dir() . '/req_vendor_test_' . uniqid();
        mkdir($tmpDir . '/vendor', 0755, true);

        $step = new RequirementsStep($tmpDir);
        $this->assertTrue($step->checkVendor());

        rmdir($tmpDir . '/vendor');
        rmdir($tmpDir);
    }

    public function testCheckVendorFalseWhenDirMissing(): void
    {
        $step = new RequirementsStep('/tmp/nonexistent_project_' . uniqid());
        $this->assertFalse($step->checkVendor());
    }

    public function testTitleReturnsNonEmptyString(): void
    {
        $step = new RequirementsStep('/tmp');
        $this->assertNotEmpty($step->title());
    }
}
```

- [ ] **Step 2: Testin fail ettiğini doğrula**

```
C:\xampp8\php\php.exe vendor\bin\phpunit tests/Unit/Wizard/RequirementsStepTest.php
```
Beklenen: `Error: Class "EduQR\Support\Wizard\Steps\RequirementsStep" not found`

- [ ] **Step 3: RequirementsStep implementasyonu**

`src/Support/Wizard/Steps/RequirementsStep.php`:
```php
<?php

declare(strict_types=1);

namespace EduQR\Support\Wizard\Steps;

use EduQR\Support\Wizard\Console;
use EduQR\Support\Wizard\Step;

/**
 * Wizard step 1: Verify system requirements.
 * Checks PHP version, required/optional extensions, and vendor/.
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

        // PHP version
        if ($this->checkPhpVersion()) {
            $console->success('PHP ' . PHP_VERSION);
        } else {
            $console->error('PHP 8.2+ gerekli. Mevcut: ' . PHP_VERSION);
            $allOk = false;
        }

        // Required extensions
        foreach (self::REQUIRED_EXTENSIONS as $ext) {
            if ($this->checkExtension($ext)) {
                $console->success("ext-{$ext}");
            } else {
                $console->error("Eksik zorunlu eklenti: {$ext}");
                $allOk = false;
            }
        }

        // Optional extensions
        foreach (self::OPTIONAL_EXTENSIONS as $ext) {
            if ($this->checkExtension($ext)) {
                $console->success("ext-{$ext} (opsiyonel)");
            } else {
                $console->warn("ext-{$ext} yüklü değil (opsiyonel — rate limiting DB bazlı çalışır)");
            }
        }

        // Composer vendor/
        if ($this->checkVendor()) {
            $console->success('vendor/ mevcut');
        } else {
            $console->error('vendor/ bulunamadı — önce: composer install --no-dev -o');
            $allOk = false;
        }

        if (!$allOk) {
            $console->writeln();
            $console->error('Gereksinimler karşılanmıyor. Sorunları düzeltin ve tekrar çalıştırın.');
        }

        return $allOk;
    }
}
```

- [ ] **Step 4: Testlerin geçtiğini doğrula**

```
C:\xampp8\php\php.exe vendor\bin\phpunit tests/Unit/Wizard/RequirementsStepTest.php
```
Beklenen: `OK (7 tests, 7 assertions)`

- [ ] **Step 5: Commit**

```bash
git add src/Support/Wizard/Steps/RequirementsStep.php tests/Unit/Wizard/RequirementsStepTest.php
git commit -m "feat: add RequirementsStep with unit tests"
```

---

## Task 5: NginxStep + Unit Testler

**Files:**
- Create: `src/Support/Wizard/Steps/NginxStep.php`
- Create: `tests/Unit/Wizard/NginxStepTest.php`

*`render()` metodu I/O bağımsız, saf bir fonksiyondur — bu yüzden kapsamlı unit test yazılabilir.*

- [ ] **Step 1: Failing testleri yaz**

`tests/Unit/Wizard/NginxStepTest.php`:
```php
<?php

declare(strict_types=1);

namespace EduQR\Tests\Unit\Wizard;

use EduQR\Support\Wizard\Steps\NginxStep;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class NginxStepTest extends TestCase
{
    private string $tmpDir;

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . '/nginx_step_test_' . uniqid();
        mkdir($this->tmpDir, 0755, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->tmpDir . '/*') ?: [] as $f) {
            unlink($f);
        }
        rmdir($this->tmpDir);
    }

    private function writeTpl(string $content): string
    {
        $path = $this->tmpDir . '/test.conf.template';
        file_put_contents($path, $content);
        return $path;
    }

    public function testRenderReplacesDomainsPlaceholder(): void
    {
        $tpl  = $this->writeTpl('server_name {{DOMAIN}};');
        $step = new NginxStep('/fake/root', $tpl);

        $output = $step->render(['DOMAIN' => 'eduqr.example.com']);

        $this->assertStringContainsString('server_name eduqr.example.com;', $output);
    }

    public function testRenderReplacesFpmSocket(): void
    {
        $tpl  = $this->writeTpl('fastcgi_pass {{FPM_SOCKET}};');
        $step = new NginxStep('/fake/root', $tpl);

        $output = $step->render(['FPM_SOCKET' => 'unix:/run/php/php8.2-fpm.sock']);

        $this->assertStringContainsString('fastcgi_pass unix:/run/php/php8.2-fpm.sock;', $output);
    }

    public function testRenderReplacesAllPlaceholders(): void
    {
        $tpl  = $this->writeTpl('{{DOMAIN}} {{PROJECT_ROOT}} {{FPM_SOCKET}} {{SSL_CERT}} {{SSL_KEY}}');
        $step = new NginxStep('/fake/root', $tpl);

        $output = $step->render([
            'DOMAIN'       => 'test.com',
            'PROJECT_ROOT' => '/var/www/eduqr',
            'FPM_SOCKET'   => 'unix:/run/php/php8.2-fpm.sock',
            'SSL_CERT'     => '/etc/ssl/fullchain.pem',
            'SSL_KEY'      => '/etc/ssl/privkey.pem',
        ]);

        $this->assertSame(
            'test.com /var/www/eduqr unix:/run/php/php8.2-fpm.sock /etc/ssl/fullchain.pem /etc/ssl/privkey.pem',
            $output,
        );
    }

    public function testRenderLeavesUnknownPlaceholdersIntact(): void
    {
        $tpl  = $this->writeTpl('{{KNOWN}} {{UNKNOWN}}');
        $step = new NginxStep('/fake/root', $tpl);

        $output = $step->render(['KNOWN' => 'replaced']);

        $this->assertStringContainsString('replaced', $output);
        $this->assertStringContainsString('{{UNKNOWN}}', $output);
    }

    public function testRenderThrowsRuntimeExceptionIfTemplateNotFound(): void
    {
        $step = new NginxStep('/fake/root', '/nonexistent/path/template.conf');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/Template okunamadı/');

        $step->render([]);
    }

    public function testRenderUsesDefaultTemplatePathWhenNotGiven(): void
    {
        // Proje kökü olarak tmpDir kullan, şablonu oraya koy
        $tplPath = $this->tmpDir . '/deploy/nginx.conf.template';
        mkdir(dirname($tplPath), 0755, true);
        file_put_contents($tplPath, 'server_name {{DOMAIN}};');

        $step   = new NginxStep($this->tmpDir); // templatePath verilmedi
        $output = $step->render(['DOMAIN' => 'auto.com']);

        $this->assertStringContainsString('server_name auto.com;', $output);
    }

    public function testTitleReturnsNonEmptyString(): void
    {
        $step = new NginxStep('/tmp');
        $this->assertNotEmpty($step->title());
    }
}
```

- [ ] **Step 2: Testin fail ettiğini doğrula**

```
C:\xampp8\php\php.exe vendor\bin\phpunit tests/Unit/Wizard/NginxStepTest.php
```
Beklenen: `Error: Class "EduQR\Support\Wizard\Steps\NginxStep" not found`

- [ ] **Step 3: NginxStep implementasyonu**

`src/Support/Wizard/Steps/NginxStep.php`:
```php
<?php

declare(strict_types=1);

namespace EduQR\Support\Wizard\Steps;

use EduQR\Support\Wizard\Console;
use EduQR\Support\Wizard\Step;
use RuntimeException;

/**
 * Wizard step 4: Generate a customized nginx.conf from a template.
 *
 * The render() method is intentionally pure (no I/O side-effects) so it
 * can be unit-tested without mocking the filesystem.
 */
class NginxStep extends Step
{
    private string $templatePath;

    public function __construct(
        private readonly string $projectRoot,
        string $templatePath = '',
    ) {
        $this->templatePath = $templatePath !== ''
            ? $templatePath
            : $projectRoot . '/deploy/nginx.conf.template';
    }

    public function title(): string
    {
        return 'Nginx Yapılandırması';
    }

    /**
     * Replace {{KEY}} placeholders in the template.
     * Throws RuntimeException if the template file cannot be read.
     *
     * @param array<string,string> $vars
     */
    public function render(array $vars): string
    {
        $template = @file_get_contents($this->templatePath);
        if ($template === false) {
            throw new RuntimeException("Template okunamadı: {$this->templatePath}");
        }
        foreach ($vars as $key => $value) {
            $template = str_replace('{{' . $key . '}}', $value, $template);
        }
        return $template;
    }

    public function run(Console $console): bool
    {
        if (!file_exists($this->templatePath)) {
            $console->error("Şablon bulunamadı: {$this->templatePath}");
            return false;
        }

        $domain      = $console->prompt('Alan adı (server_name)', 'example.com');
        $projectRoot = $console->prompt('Proje kök dizini (mutlak yol)', $this->projectRoot);
        $fpmSocket   = $console->prompt('PHP-FPM socket', 'unix:/run/php/php8.2-fpm.sock');
        $sslCert     = $console->prompt(
            'TLS sertifika yolu',
            "/etc/letsencrypt/live/{$domain}/fullchain.pem",
        );
        $sslKey      = $console->prompt(
            'TLS anahtar yolu',
            "/etc/letsencrypt/live/{$domain}/privkey.pem",
        );
        $outputPath  = $console->prompt(
            'Çıktı dosyası',
            $this->projectRoot . '/deploy/nginx.conf',
        );

        try {
            $config = $this->render([
                'DOMAIN'       => $domain,
                'PROJECT_ROOT' => $projectRoot,
                'FPM_SOCKET'   => $fpmSocket,
                'SSL_CERT'     => $sslCert,
                'SSL_KEY'      => $sslKey,
                'GENERATED_AT' => date('Y-m-d H:i:s T'),
                'OUTPUT_PATH'  => $outputPath,
            ]);
        } catch (RuntimeException $e) {
            $console->error($e->getMessage());
            return false;
        }

        if (file_put_contents($outputPath, $config) === false) {
            $console->error("Yapılandırma yazılamadı: {$outputPath}");
            return false;
        }

        $console->success("Nginx yapılandırması oluşturuldu: {$outputPath}");
        $console->writeln();
        $console->info('Etkinleştirmek için:');
        $console->info("  sudo nginx -t");
        $console->info("  sudo cp {$outputPath} /etc/nginx/sites-available/eduqr");
        $console->info("  sudo ln -s /etc/nginx/sites-available/eduqr /etc/nginx/sites-enabled/eduqr");
        $console->info("  sudo nginx -t && sudo systemctl reload nginx");

        return true;
    }
}
```

- [ ] **Step 4: Testlerin geçtiğini doğrula**

```
C:\xampp8\php\php.exe vendor\bin\phpunit tests/Unit/Wizard/NginxStepTest.php
```
Beklenen: `OK (7 tests, 7 assertions)`

- [ ] **Step 5: Commit**

```bash
git add src/Support/Wizard/Steps/NginxStep.php tests/Unit/Wizard/NginxStepTest.php
git commit -m "feat: add NginxStep with template rendering and unit tests"
```

---

## Task 6: EnvStep

**Files:**
- Create: `src/Support/Wizard/Steps/EnvStep.php`

*Yoğun dosya I/O içerdiğinden unit test yerine wizard E2E akışıyla test edilir.*

- [ ] **Step 1: EnvStep implementasyonu**

`src/Support/Wizard/Steps/EnvStep.php`:
```php
<?php

declare(strict_types=1);

namespace EduQR\Support\Wizard\Steps;

use EduQR\Support\Wizard\Console;
use EduQR\Support\Wizard\Step;

/**
 * Wizard step 2: Interactively create the .env file from .env.example.
 *
 * If .env already exists, the user is asked whether to overwrite it.
 * DB credentials are intentionally left blank here; DatabaseStep fills them in.
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
            $console->error('.env.example bulunamadı — deponuz eksik.');
            return false;
        }

        if (file_exists($envPath)) {
            if (!$console->confirm('.env zaten mevcut. Yeniden yapılandırılsın mı?', false)) {
                $console->info('Mevcut .env kullanılıyor.');
                return true;
            }
        }

        // Kullanıcıdan bilgileri al
        $appUrl   = $console->prompt('Uygulama URL\'i (trailing slash olmadan)', 'https://example.com');
        $appEnv   = $console->prompt('Ortam', 'production');
        $appDebug = ($appEnv === 'development') ? 'true' : 'false';
        $locale   = $console->prompt('Varsayılan dil (en/tr)', 'tr');

        $defaultLogPath    = dirname($this->projectRoot) . '/eduqr-logs';
        $defaultBackupDir  = dirname($this->projectRoot) . '/eduqr-backups';
        $logPath   = $console->prompt('Log dizini (mutlak yol, web kökü dışında)', $defaultLogPath);
        $backupDir = $console->prompt('Yedekleme dizini (mutlak yol)', $defaultBackupDir);

        // APP_SECRET üret
        $secret = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');

        // .env.example'ı şablon olarak kullan; değerleri satır satır güncelle
        $content = (string) file_get_contents($examplePath);

        $replacements = [
            '/^APP_URL=.*/m'              => "APP_URL={$appUrl}",
            '/^APP_ENV=.*/m'              => "APP_ENV={$appEnv}",
            '/^APP_DEBUG=.*/m'            => "APP_DEBUG={$appDebug}",
            '/^APP_SECRET=.*/m'           => "APP_SECRET={$secret}",
            '/^APP_LOCALE_DEFAULT=.*/m'   => "APP_LOCALE_DEFAULT={$locale}",
            '/^LOG_PATH=.*/m'             => "LOG_PATH={$logPath}",
            '/^BACKUP_DIR=.*/m'           => "BACKUP_DIR={$backupDir}",
        ];

        foreach ($replacements as $pattern => $replacement) {
            $content = (string) preg_replace($pattern, $replacement, $content);
        }

        if (file_put_contents($envPath, $content) === false) {
            $console->error('.env dosyası yazılamadı — izinleri kontrol edin.');
            return false;
        }

        $console->success('.env oluşturuldu.');
        $console->success('APP_SECRET otomatik oluşturuldu.');
        $console->warn('Veritabanı bilgileri bir sonraki adımda girilecek.');

        return true;
    }
}
```

- [ ] **Step 2: Sınıfın autoload edildiğini doğrula**

```bash
C:\xampp8\php\php.exe -r "require 'vendor/autoload.php'; \$s = new EduQR\Support\Wizard\Steps\EnvStep('/tmp'); echo \$s->title() . PHP_EOL;"
```
Beklenen: `Uygulama Yapılandırması`

- [ ] **Step 3: Commit**

```bash
git add src/Support/Wizard/Steps/EnvStep.php
git commit -m "feat: add EnvStep for interactive .env creation"
```

---

## Task 7: DatabaseStep

**Files:**
- Create: `src/Support/Wizard/Steps/DatabaseStep.php`

- [ ] **Step 1: DatabaseStep implementasyonu**

`src/Support/Wizard/Steps/DatabaseStep.php`:
```php
<?php

declare(strict_types=1);

namespace EduQR\Support\Wizard\Steps;

use EduQR\Support\Wizard\Console;
use EduQR\Support\Wizard\Step;
use PDO;
use PDOException;

/**
 * Wizard step 3: Collect DB credentials, test the connection, run migrations.
 *
 * After a successful connection the credentials are written to .env so that
 * subsequent steps can bootstrap EduQR\Config normally.
 */
class DatabaseStep extends Step
{
    public function __construct(
        private readonly string $projectRoot,
    ) {}

    public function title(): string
    {
        return 'Veritabanı Yapılandırması';
    }

    public function run(Console $console): bool
    {
        $host = $console->prompt('Host', 'localhost');
        $port = $console->prompt('Port', '3306');
        $name = $console->prompt('Veritabanı adı', 'eduqr');
        $user = $console->prompt('Kullanıcı adı', 'eduqr_app');
        $pass = $console->secret('Şifre');

        // Bağlantıyı test et
        try {
            $pdo = new PDO(
                "mysql:host={$host};port={$port};dbname={$name};charset=utf8mb4",
                $user,
                $pass,
                [
                    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE  => PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES    => false,
                ],
            );
            $console->success('Veritabanı bağlantısı başarılı!');
        } catch (PDOException $e) {
            $console->error('Bağlantı başarısız: ' . $e->getMessage());
            $console->info('Bilgileri kontrol edip tekrar çalıştırın.');
            return false;
        }

        // .env'e DB bilgilerini yaz (EnvStep'ten sonra çalışıyor)
        $this->updateEnv([
            'DB_HOST' => $host,
            'DB_PORT' => $port,
            'DB_NAME' => $name,
            'DB_USER' => $user,
            'DB_PASS' => $pass,
        ]);
        $console->success('.env veritabanı bilgileri kaydedildi.');

        // Migrasyonları çalıştır
        if (!$console->confirm('Migrasyonlar çalıştırılsın mı?', true)) {
            $console->warn('Migrasyonlar atlandı. Daha sonra: php bin/migrate.php');
            return true;
        }

        return $this->runMigrations($pdo, $console);
    }

    /** Update or append KEY=value lines in .env. */
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

    /** Run pending SQL migration files using the already-open PDO connection. */
    private function runMigrations(PDO $pdo, Console $console): bool
    {
        try {
            $pdo->exec("
                CREATE TABLE IF NOT EXISTS schema_migrations (
                    filename   VARCHAR(120) PRIMARY KEY,
                    applied_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            ");

            $applied = $pdo->query("SELECT filename FROM schema_migrations")
                           ->fetchAll(PDO::FETCH_COLUMN);
            $applied = array_flip($applied);

            $dir   = $this->projectRoot . '/database/migrations';
            $files = glob($dir . '/*.sql') ?: [];
            sort($files);

            $ran = 0;
            foreach ($files as $file) {
                $filename = basename($file);
                if (isset($applied[$filename])) {
                    continue;
                }
                $sql = (string) file_get_contents($file);
                if (trim($sql) === '') {
                    continue;
                }
                $pdo->exec($sql);
                $pdo->prepare("INSERT INTO schema_migrations (filename) VALUES (?)")
                    ->execute([$filename]);
                $ran++;
            }

            $total = count($files);
            $skipped = $total - $ran;
            $console->success("{$ran} migrasyon uygulandı" . ($skipped > 0 ? ", {$skipped} zaten uygulanmış." : '.'));
        } catch (PDOException $e) {
            $console->error('Migrasyon hatası: ' . $e->getMessage());
            return false;
        }

        return true;
    }
}
```

- [ ] **Step 2: Sınıfın autoload edildiğini doğrula**

```bash
C:\xampp8\php\php.exe -r "require 'vendor/autoload.php'; \$s = new EduQR\Support\Wizard\Steps\DatabaseStep('/tmp'); echo \$s->title() . PHP_EOL;"
```
Beklenen: `Veritabanı Yapılandırması`

- [ ] **Step 3: Commit**

```bash
git add src/Support/Wizard/Steps/DatabaseStep.php
git commit -m "feat: add DatabaseStep with connection test and inline migration runner"
```

---

## Task 8: AdminStep

**Files:**
- Create: `src/Support/Wizard/Steps/AdminStep.php`

- [ ] **Step 1: AdminStep implementasyonu**

`src/Support/Wizard/Steps/AdminStep.php`:
```php
<?php

declare(strict_types=1);

namespace EduQR\Support\Wizard\Steps;

use EduQR\Support\Wizard\Console;
use EduQR\Support\Wizard\Step;
use PDOException;

/**
 * Wizard step 5: Create the first admin user account.
 *
 * Requires .env to be fully configured (EnvStep + DatabaseStep must have run).
 * Delegates to EduQR\Services\AuthService::hashPassword() and
 * EduQR\Repositories\UserRepository (same as bin/user-add.php).
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
            $console->warn('Hesap oluşturulmadı. Daha sonra: php bin/user-add.php --role=admin ...');
            return true;
        }

        $name  = $console->prompt('Ad Soyad');
        $email = $console->prompt('E-posta');
        $lang  = $console->prompt('Arayüz dili (en/tr)', 'tr');

        if (trim($name) === '' || trim($email) === '') {
            $console->error('Ad ve e-posta zorunludur.');
            return false;
        }

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $console->error("Geçersiz e-posta adresi: {$email}");
            return false;
        }

        if (!in_array($lang, ['en', 'tr'], true)) {
            $console->error("Dil 'en' veya 'tr' olmalıdır.");
            return false;
        }

        $pass1 = $console->secret('Şifre (en az 10 karakter)');
        $pass2 = $console->secret('Şifre (tekrar)');

        if ($pass1 !== $pass2) {
            $console->error('Şifreler eşleşmiyor.');
            return false;
        }

        // SECURITY_PRIVACY.md §4 kuralları
        if (mb_strlen($pass1) < 10) {
            $console->error('Şifre en az 10 karakter olmalıdır.');
            return false;
        }

        $strength = 0;
        if (preg_match('/[a-z]/', $pass1)) { $strength++; }
        if (preg_match('/[A-Z]/', $pass1)) { $strength++; }
        if (preg_match('/[0-9]/', $pass1)) { $strength++; }
        if (preg_match('/[^a-zA-Z0-9]/', $pass1)) { $strength++; }

        if ($strength < 3) {
            $console->error('Şifre; küçük harf, büyük harf, rakam ve sembolden en az 3\'ünü içermelidir.');
            return false;
        }

        // Config yükle (DatabaseStep'ten sonra .env hazır)
        \EduQR\Config::load($this->projectRoot . '/.env');

        $hash = \EduQR\Services\AuthService::hashPassword($pass1);
        $repo = new \EduQR\Repositories\UserRepository();

        try {
            $id = $repo->create($email, $hash, $name, 'admin', $lang);
            $console->success("Yönetici hesabı oluşturuldu (ID: {$id})");
            $console->info("  E-posta : {$email}");
            $console->info("  Ad      : {$name}");
            $console->info("  Rol     : admin");
        } catch (PDOException $e) {
            if ($e->getCode() === '23000') {
                $console->error("'{$email}' adresiyle kayıtlı bir kullanıcı zaten var.");
            } else {
                $console->error('Veritabanı hatası: ' . $e->getMessage());
            }
            return false;
        }

        return true;
    }
}
```

- [ ] **Step 2: Sınıfın autoload edildiğini doğrula**

```bash
C:\xampp8\php\php.exe -r "require 'vendor/autoload.php'; \$s = new EduQR\Support\Wizard\Steps\AdminStep('/tmp'); echo \$s->title() . PHP_EOL;"
```
Beklenen: `İlk Yönetici Hesabı`

- [ ] **Step 3: Commit**

```bash
git add src/Support/Wizard/Steps/AdminStep.php
git commit -m "feat: add AdminStep for first admin user creation"
```

---

## Task 9: VerifyStep

**Files:**
- Create: `src/Support/Wizard/Steps/VerifyStep.php`

- [ ] **Step 1: VerifyStep implementasyonu**

`src/Support/Wizard/Steps/VerifyStep.php`:
```php
<?php

declare(strict_types=1);

namespace EduQR\Support\Wizard\Steps;

use EduQR\Support\Wizard\Console;
use EduQR\Support\Wizard\Step;

/**
 * Wizard step 6: Smoke-test the live installation via HTTP.
 *
 * Mirrors the checks in bin/smoke.php but uses the APP_URL from .env
 * as the default base, and outputs coloured results inline.
 */
class VerifyStep extends Step
{
    /** [path, expected_status] */
    private const CHECKS = [
        ['/',                200],
        ['/login',           200],
        ['/api/v1/locales',  200],
        ['/api/v1/auth/me',  401],   // 401 or 302 accepted
        ['/api/v1/courses',  401],   // 401 or 302 accepted
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
        $console->info('Nginx\'in yeniden yüklendiğinden emin olun.');

        if (!$console->confirm('Smoke testleri çalıştırılsın mı?', true)) {
            $console->warn('Doğrulama atlandı. Daha sonra: php bin/smoke.php --verbose');
            return true;
        }

        // APP_URL'i .env'den oku
        $base = $this->readAppUrl();
        $base = $console->prompt('Test base URL\'i', $base);
        $base = rtrim($base, '/');

        if (empty($base)) {
            $console->error('Test URL\'i boş olamaz.');
            return false;
        }

        $ctx = stream_context_create([
            'http' => [
                'method'          => 'GET',
                'follow_location' => 0,
                'timeout'         => 10,
                'ignore_errors'   => true,
                'header'          => "Accept: application/json, text/html\r\n",
            ],
            'ssl' => [
                'verify_peer'      => false,
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

            $label = sprintf('%-40s %s', $path, $status > 0 ? $status : '(bağlantı hatası)');
            if ($ok) {
                $console->success($label);
            } else {
                $console->error("{$label}  (beklenen: {$expected})");
                $failed++;
            }
        }

        if ($failed > 0) {
            $console->writeln();
            $console->warn("{$failed} kontrol başarısız. Nginx yapılandırmasını ve PHP-FPM'i kontrol edin.");
            return false;
        }

        return true;
    }

    private function readAppUrl(): string
    {
        $envPath = $this->projectRoot . '/.env';
        if (!file_exists($envPath)) {
            return 'http://localhost';
        }
        foreach ((array) file($envPath) as $line) {
            if (str_starts_with((string) $line, 'APP_URL=')) {
                return rtrim(trim(substr((string) $line, 8)), '/');
            }
        }
        return 'http://localhost';
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
```

- [ ] **Step 2: Sınıfın autoload edildiğini doğrula**

```bash
C:\xampp8\php\php.exe -r "require 'vendor/autoload.php'; \$s = new EduQR\Support\Wizard\Steps\VerifyStep('/tmp'); echo \$s->title() . PHP_EOL;"
```
Beklenen: `Doğrulama`

- [ ] **Step 3: Commit**

```bash
git add src/Support/Wizard/Steps/VerifyStep.php
git commit -m "feat: add VerifyStep for post-install smoke testing"
```

---

## Task 10: Ana Wizard Giriş Noktası

**Files:**
- Create: `bin/wizard.php`

- [ ] **Step 1: bin/wizard.php oluştur**

`bin/wizard.php`:
```php
#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * bin/wizard.php — Nginx Kurulum Sihirbazı
 *
 * Adım adım interaktif kurulum:
 *   1. Sistem gereksinimleri
 *   2. .env yapılandırması
 *   3. Veritabanı bağlantısı + migrasyonlar
 *   4. Nginx yapılandırması üretimi
 *   5. İlk yönetici hesabı
 *   6. Smoke testleri
 *
 * Kullanım:
 *   php bin/wizard.php
 *   php bin/wizard.php --skip-nginx        # nginx adımını atla
 *   php bin/wizard.php --skip-admin        # admin adımını atla
 *   php bin/wizard.php --skip-verify       # smoke testleri atla
 *
 * Çıkış kodları: 0 = başarı, 1 = hata
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
    if (str_starts_with($arg, '--skip-')) {
        $skip[] = substr($arg, 7); // "nginx", "admin", "verify"
    }
}

// ── Adımları tanımla ──────────────────────────────────────────────────────────
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
$console->banner('eduQR Kurulum Sihirbazı — Nginx');

$total = count($steps);
$i     = 0;
foreach ($steps as $step) {
    $i++;
    $console->section($i, $total, $step->title());

    if (!$step->run($console)) {
        $console->writeln();
        $console->error('Kurulum başarısız oldu.');
        $console->info('Sorunu düzeltin ve sihirbazı tekrar çalıştırın.');
        $console->info('Tamamlanan adımları atlamak için --skip-<adım> kullanın:');
        $console->info("  --skip-nginx   --skip-admin   --skip-verify");
        exit(1);
    }
}

// ── Başarı mesajı ─────────────────────────────────────────────────────────────
$appUrl = '';
$envPath = $projectRoot . '/.env';
if (file_exists($envPath)) {
    foreach ((array) file($envPath) as $line) {
        if (str_starts_with((string) $line, 'APP_URL=')) {
            $appUrl = trim(substr((string) $line, 8));
            break;
        }
    }
}

$console->banner('Kurulum tamamlandı! ');

if ($appUrl) {
    $console->info("Giriş yap: {$appUrl}/login");
}
$console->writeln();
exit(0);
```

- [ ] **Step 2: Syntax kontrolü yap**

```bash
C:\xampp8\php\php.exe -l bin/wizard.php
```
Beklenen: `No syntax errors detected in bin/wizard.php`

- [ ] **Step 3: `--help` benzeri çalıştırma testi (vendor yokken)**

```bash
# vendor/ olmadan çalıştırıldığında anlamlı hata mesajı vermeli
C:\xampp8\php\php.exe bin/wizard.php
```
Eğer vendor/ mevcutsa: `[1/6] Sistem Gereksinimleri` adımı başlar.
Eğer vendor/ yoksa: `Hata: vendor/ dizini bulunamadı.` mesajı çıkar.

- [ ] **Step 4: Commit**

```bash
git add bin/wizard.php
git commit -m "feat: add bin/wizard.php nginx setup wizard entry point"
```

---

## Task 11: Tam Test Süiti + Son Doğrulama

**Files:**
- Sadece mevcut test dosyaları; yeni dosya yok.

- [ ] **Step 1: Tüm test süitini çalıştır**

```
C:\xampp8\php\php.exe vendor\bin\phpunit --testdox
```
Beklenen: Tüm wizard testleri dahil tüm testler yeşil. Yeni eklenen:
- `ConsoleTest` — 11 test
- `RequirementsStepTest` — 7 test
- `NginxStepTest` — 7 test

Toplam sayı: mevcut 143 + 25 yeni = en az **168 test**

- [ ] **Step 2: track.md'yi güncelle**

`track.md` dosyasında "Nginx Kurulum Sihirbazı" için bir not ekle (bölüm yok, genel notlar alanına):
```
### 2026-05-15 — Nginx Kurulum Sihirbazı
`bin/wizard.php` eklendi. 6 adımlı interaktif CLI kurulum sihirbazı; 
RequirementsStep, EnvStep, DatabaseStep, NginxStep (template rendering), 
AdminStep, VerifyStep. 25 yeni unit test. deploy/nginx.conf.template oluşturuldu.
```

- [ ] **Step 3: Son commit**

```bash
git add -A
git commit -m "feat: nginx setup wizard — bin/wizard.php with 6 steps and 25 unit tests

Adds an interactive CLI installation wizard for Nginx-based deployments:
- RequirementsStep: PHP 8.2+, extensions, vendor/ check
- EnvStep: interactive .env generation from .env.example
- DatabaseStep: DB connection test + inline migration runner
- NginxStep: customized nginx.conf from deploy/nginx.conf.template
- AdminStep: first admin user via AuthService/UserRepository
- VerifyStep: 5-point smoke test against live URL

25 new unit tests (ConsoleTest, RequirementsStepTest, NginxStepTest).
Supports --skip-nginx/admin/verify flags for resuming failed installs."
```

- [ ] **Step 4: Push**

```bash
git push
```

---

## Self-Review

### 1. Spec Coverage

| Gereksinim | Karşılanıyor mu? |
|---|---|
| Nginx sunucusu için kurulum | ✅ NginxStep + template |
| Projeye entegrasyon | ✅ `bin/wizard.php`, `src/Support/Wizard/` |
| PHP 8.2+ zorunluluğu | ✅ RequirementsStep |
| .env güvenlik kuralları | ✅ APP_SECRET otomatik üretilir, BACKUP_DIR/LOG_PATH dışarda |
| Şifre kuralları | ✅ AdminStep (10+ karakter, 3/4 karakter sınıfı) |
| Migrasyon runner | ✅ DatabaseStep inline — bin/migrate.php ile aynı mantık |
| Smoke testleri | ✅ VerifyStep — bin/smoke.php ile aynı endpoint'ler |
| Atlama desteği | ✅ `--skip-nginx`, `--skip-admin`, `--skip-verify` |
| TDD | ✅ Console, RequirementsStep, NginxStep unit testleri önce yazıldı |

### 2. Placeholder Kontrolü

- ✅ Tüm kod blokları tam ve çalışabilir
- ✅ `{{PLACEHOLDER}}` sözdizimi template ve render() arasında tutarlı
- ✅ Tüm adımlarda `return false` hata durumlarında wizard'ı durdurur
- ✅ Her adım bağımsız olarak test edilebilir

### 3. Tip Tutarlılığı

- `Console::prompt()` → `string` her yerde
- `Console::confirm()` → `bool` her yerde
- `Step::run(Console): bool` → tüm subclass'larda aynı imza
- `NginxStep::render(array<string,string>): string` → testlerde aynı çağrı
