# ADR-0006: Local Container Stack on Apache with the Project Migration Runner

**Status:** Accepted
**Date:** 2026-09-03

## Context

Installing the project locally required a host PHP 8.2+ with `gd`/`intl`/`pdo_mysql` and a
MySQL server, which made onboarding slow and machine-dependent. NFR-75 asks for a
reproducible local stack (PHP + MySQL) started with one command and free of baked-in secrets.

Three choices were open: which web server the image runs, how the schema reaches a fresh
database, and where container configuration comes from.

## Decision

**Apache, not PHP-FPM.** The app already ships `public/.htaccess` with the front-controller
rewrite and the security headers it relies on, and `deploy/apache.htaccess.example` is the
documented shared-hosting target. `php:8.3-apache-bookworm` honours that file as-is; an FPM
image would have needed a second Nginx service plus a translation of the rewrite rules, which
would then drift from the `.htaccess` used in production.

**Schema via `bin/migrate.php`, not `docker-entrypoint-initdb.d`.** The migration runner is
the canonical path (NFR-53, NFR-54) and is idempotent. Mounting `database/schema.sql` into
MySQL's init directory would apply only on first boot of an empty volume and would let the
reference schema and the migration files drift apart. The container entrypoint therefore runs
the project's own runner before Apache starts.

**Configuration from an ignored env file.** `.env.docker` (git-ignored, templated by
`.env.docker.example`) feeds both services through `env_file` and is mounted read-only as the
container's `.env`, so container behaviour does not depend on whatever `.env` the host holds
and no credential is ever baked into the image.

## Consequences

**Positive:**
- `cp .env.docker.example .env.docker && docker compose up -d` yields a working app with the schema applied.
- The container serves the same `.htaccess` rules as the shared-hosting deployment.
- Migrations cannot drift from `schema.sql`, because only one of them is ever applied.
- No secret exists in the image, the compose file, or the repository.

**Negative:**
- The image is larger than an FPM image and runs one process more.
- `vendor/` lives in a named volume, so a dependency change needs `docker compose down -v && docker compose up -d --build`.
- The stack serves plain HTTP, so `COOKIE_SECURE=true` in `.env.docker.example` relies on browsers treating `http://localhost` as a trustworthy origin.

## Notes

The Node tooling mentioned in T-1115 is explicitly out of scope; nothing Node-related is part
of this stack. Production deployment remains the cPanel and Nginx paths documented in `README.md`.
