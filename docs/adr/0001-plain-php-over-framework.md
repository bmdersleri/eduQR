# ADR-0001: Plain PHP Over a Framework for MVP

**Status:** Accepted
**Date:** 2026-05-14

## Context

eduQR targets shared cPanel hosting as its primary deployment environment. Framework-heavy stacks (Laravel, Symfony) require specific PHP versions, CLI access, writable filesystem paths, and Composer post-install hooks that are often unavailable or restricted on shared hosting. They also add significant dependency surface area and opinionated conventions that differ from institution to institution.

## Decision

Use plain PHP 8.2+ with:
- A thin custom router (`src/Router.php`)
- PSR-4 autoloading via Composer
- Service and repository classes for business logic separation
- No framework, no ORM, no template engine beyond server-rendered PHP partials

## Consequences

**Positive:**
- Deployable as a folder of files on any cPanel account with PHP 8.2+
- Zero framework lock-in; upgrade path is open
- Readable by any PHP developer without framework-specific knowledge
- Minimal attack surface — no framework middleware chain to audit

**Negative:**
- No scaffolding for common patterns (migrations, auth, validation) — must implement
- Slightly more boilerplate in controllers and repositories than a framework provides
- No built-in ORM; raw SQL in repositories requires careful discipline

## Superseded By

If/when containerized deployment (Phase 11+) is adopted, a lightweight framework (Slim 4, Laravel) may be introduced via a new ADR.
