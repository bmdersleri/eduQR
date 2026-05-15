<?php

declare(strict_types=1);

namespace EduQR\Support\Wizard\Steps;

use EduQR\Support\Wizard\Console;
use EduQR\Support\Wizard\Step;
use RuntimeException;

/**
 * Wizard step 4: Generate a customised nginx.conf from deploy/nginx.conf.template.
 *
 * The render() method is pure (no I/O side-effects) so it is fully unit-testable.
 * run() collects values interactively, calls render(), and writes the output file.
 *
 * Defaults are pre-filled for Ubuntu 22.04/24.04 + Let's Encrypt environments.
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
     * Replace {{KEY}} placeholders in the template with the given values.
     * Unknown placeholders are left intact.
     *
     * @param array<string,string> $vars
     * @throws RuntimeException if the template file cannot be read
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

        $console->info('Ubuntu varsayılan FPM socket: unix:/run/php/php8.2-fpm.sock');
        $console->info('Kontrol: sudo systemctl status php8.2-fpm');
        $console->writeln();

        $domain      = $console->prompt('Alan adı (server_name)', 'example.com');
        $projectRoot = $console->prompt('Proje kök dizini (mutlak yol)', $this->projectRoot);
        $fpmSocket   = $console->prompt('PHP-FPM socket', 'unix:/run/php/php8.2-fpm.sock');
        $sslCert     = $console->prompt(
            'TLS sertifika (fullchain.pem)',
            "/etc/letsencrypt/live/{$domain}/fullchain.pem",
        );
        $sslKey      = $console->prompt(
            'TLS anahtar (privkey.pem)',
            "/etc/letsencrypt/live/{$domain}/privkey.pem",
        );
        $outputPath  = $console->prompt(
            'Yapılandırma çıktı dosyası',
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
            $console->error("Dosya yazılamadı: {$outputPath}  (izin hatası?)");
            return false;
        }

        $console->success("nginx.conf oluşturuldu: {$outputPath}");
        $console->writeln();
        $console->info('Etkinleştirmek için aşağıdaki komutları çalıştırın:');
        $console->info("  sudo nginx -t");
        $console->info("  sudo cp {$outputPath} /etc/nginx/sites-available/eduqr");
        $console->info("  sudo ln -s /etc/nginx/sites-available/eduqr /etc/nginx/sites-enabled/eduqr");
        $console->info("  sudo nginx -t && sudo systemctl reload nginx");

        return true;
    }
}
