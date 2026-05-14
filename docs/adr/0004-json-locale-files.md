# ADR-0004: JSON Locale Files Over gettext (.po/.mo)

**Status:** Accepted
**Date:** 2026-05-14

## Context

eduQR must support English and Turkish at launch with a clear path to additional locales. Two main options were considered: PHP's `gettext` extension (`.po`/`.mo` files) and custom JSON locale files parsed by a simple helper.

## Decision

Use **JSON locale files** (`locales/en.json`, `locales/tr.json`, etc.) parsed by a custom `I18nService` with a `t('key')` helper:

```json
{
  "auth.login.submit": "Log in",
  "auth.login.email": "Email address"
}
```

Key naming convention: `area.screen.element` (dot-separated, snake_case segments).

Placeholder syntax: `{name}` (e.g., `"Welcome, {name}!"`)

## Consequences

**Positive:**
- No PHP extension dependency (`ext-gettext` is not universally available on cPanel)
- Diff-friendly: translators and developers can review locale changes in GitHub PRs
- JSON is trivially readable by JS frontend code (useful for client-side strings)
- A CLI coverage gate (`bin/locale-check.php`) is straightforward to implement
- Easy to add new locales without recompiling `.mo` files

**Negative:**
- No tooling support as mature as POEdit for `.po` files
- Plural forms require a custom `tn()` helper instead of `ngettext()`
- Large locale files are loaded entirely into memory on each request (acceptable at MVP key count ≈ 150)

## Superseded By

If the key count grows beyond ~500 or if professional translation tooling is needed, a migration to `gettext` or a dedicated i18n service (e.g., Crowdin + `.po` export) is possible without changing the `t()` helper interface — only the loader changes.
