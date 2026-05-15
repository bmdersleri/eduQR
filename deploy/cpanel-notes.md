# eduQR — cPanel Shared Hosting Install Guide (T-1013)

Step-by-step instructions for deploying eduQR on a cPanel shared hosting account.
Tested on cPanel/WHM with PHP 8.2 and MySQL 8 / MariaDB 10.6.

---

## Prerequisites

| Requirement | Notes |
|---|---|
| PHP ≥ 8.2 | Switch via "Select PHP Version" in cPanel |
| PHP extensions | `pdo_mysql`, `json`, `mbstring`, `intl`, `gd` or `imagick`, `opcache` |
| MySQL / MariaDB | ≥ MySQL 8.0 or MariaDB 10.6 (JSON column support required) |
| Composer | Installed globally or downloaded as `composer.phar` |
| SSH access | Strongly recommended; most steps can also be done via File Manager |

---

## Step 1 — Upload the application

1. In cPanel → **File Manager**, navigate to your home directory (e.g. `/home/yourusername/`).
2. Create a folder **outside** the document root, e.g. `/home/yourusername/eduqr-app/`.
3. Upload the project ZIP and extract it there.  
   **The document root of your domain must point to `eduqr-app/public/`** — not the project root.

### Setting the document root

In cPanel → **Domains** → click the domain → change **Document Root** to:
```
/home/yourusername/eduqr-app/public
```
If your host does not allow changing the document root, symlink `public_html` to `eduqr-app/public/` via SSH:
```bash
rm -rf ~/public_html          # backup first!
ln -s ~/eduqr-app/public ~/public_html
```

---

## Step 2 — Install Composer dependencies

Via SSH:
```bash
cd ~/eduqr-app
php8.2 $(which composer || echo composer.phar) install --no-dev --optimize-autoloader
```

If Composer is not available globally, download it first:
```bash
php8.2 -r "copy('https://getcomposer.org/installer', 'composer-setup.php');"
php8.2 composer-setup.php
php8.2 -r "unlink('composer-setup.php');"
# Now use: php8.2 composer.phar install ...
```

---

## Step 3 — Create the database

1. cPanel → **MySQL Databases** → create a database (e.g. `yourusername_eduqr`).
2. Create a database user with a strong password.
3. Grant the user **all privileges** on the new database.
4. Note the host (usually `localhost`), database name, username, and password.

---

## Step 4 — Configure the environment

```bash
cd ~/eduqr-app
cp .env.example .env
chmod 600 .env
```

Edit `.env` and fill in at minimum:

```dotenv
APP_ENV=production
APP_DEBUG=false
APP_URL=https://yourdomain.example.org
APP_SECRET=                        # generate with: php8.2 bin/rotate-secret.php --apply

DB_HOST=localhost
DB_NAME=yourusername_eduqr
DB_USER=yourusername_eduqr_app
DB_PASS=<strong-password>

LOG_PATH=/home/yourusername/eduqr-logs   # OUTSIDE the web root
BACKUP_DIR=/home/yourusername/eduqr-backups
```

Generate APP_SECRET:
```bash
php8.2 bin/rotate-secret.php --apply
```

---

## Step 5 — Create the log directory

```bash
mkdir -p ~/eduqr-logs
chmod 700 ~/eduqr-logs
```

---

## Step 6 — Run database migrations

```bash
cd ~/eduqr-app
php8.2 bin/migrate.php
```

Expected output: each migration file logged as "applied".

---

## Step 7 — Create the first instructor account

```bash
php8.2 bin/user-add.php --email=you@example.org --name="Your Name" --password=<strong>
```

---

## Step 8 — Copy Apache .htaccess

```bash
cp ~/eduqr-app/deploy/apache.htaccess.example ~/eduqr-app/public/.htaccess
```

Then edit `public/.htaccess` and uncomment the **Force HTTPS** block once you have confirmed HTTPS is working:
```apache
RewriteCond %{HTTPS} off
RewriteRule ^ https://%{HTTP_HOST}%{REQUEST_URI} [R=301,L]
```

---

## Step 9 — Configure PHP version

In cPanel → **Select PHP Version** → choose **PHP 8.2** for this domain.  
Enable extensions: `pdo_mysql`, `json`, `mbstring`, `intl`, `gd`, `opcache`.

In "PHP Options" (php.ini editor):
```
display_errors = Off
log_errors = On
error_log = /home/yourusername/eduqr-logs/php-errors.log
opcache.enable = 1
opcache.memory_consumption = 128
```

---

## Step 10 — Set up cron jobs

In cPanel → **Cron Jobs**, add:

| Schedule | Command |
|---|---|
| Daily at 02:00 | `php8.2 /home/yourusername/eduqr-app/bin/cleanup.php >> /home/yourusername/eduqr-logs/cleanup.log 2>&1` |
| Daily at 03:00 | `php8.2 /home/yourusername/eduqr-app/bin/backup.php >> /home/yourusername/eduqr-logs/backup.log 2>&1` |

---

## Step 11 — Smoke test

```bash
php8.2 ~/eduqr-app/bin/smoke.php --url=https://yourdomain.example.org --verbose
```

Expected: all checks pass (or 401 for auth-gated routes).

---

## Step 12 — Final security checks

Run through the deployment hardening checklist in `SECURITY_PRIVACY.md §21`.

Key cPanel-specific items:
- Verify directory listing is disabled: visit `https://yourdomain.example.org/` — should show the app, not a directory index.
- Verify `.env` is not web-accessible: `curl -I https://yourdomain.example.org/.env` → should return 403.
- Check error pages do not reveal stack traces: trigger a 404 and confirm the custom template shows.

---

## Troubleshooting

| Symptom | Likely Cause |
|---|---|
| 500 error on first load | `APP_DEBUG=true` temporarily, check `eduqr-logs/app.log` |
| "vendor/autoload.php not found" | Run `composer install` |
| DB connection error | Check DB_HOST/DB_NAME/DB_USER/DB_PASS in .env |
| Sessions not persisting | Check `session.save_path` is writable; set via cPanel PHP options |
| QR codes broken | Ensure `gd` or `imagick` extension is enabled |
| Rate limiting inactive | APCu extension not enabled; non-critical for MVP |
