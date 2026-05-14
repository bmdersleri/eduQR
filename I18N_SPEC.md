# Internationalization (i18n) Specification — eduQR

This document is the contract for how eduQR handles multiple languages. **No user-facing string is ever hardcoded.** Every UI label, button, error message, validation message, and report header is sourced from a locale file. Every AI coding agent working on this project must obey this file.

If you find yourself typing English or Turkish text directly into a template, controller, JS file, or validator that a human will see — stop, and add a translation key instead.

**Locked decisions (do not deviate):**

- Locale files are **JSON**, stored in `/locales`, **file-based** (never DB-stored).
- Translation key naming: `area.screen.element` (dot notation).
- Placeholder syntax: `{name}` — never `%s`, never `{0}`.
- Default and fallback locale: `en`.
- Required at launch: `en`, `tr`.
- The translation helper is `t('key')` / `t('key', ['name' => $value])`.

---

## 1. Goals

1. Ship the MVP fully usable in **English (`en`)** and **Türkçe (`tr`)**.
2. Add further locales (Deutsch, Français, العربية, …) without a single code change — translators drop in a JSON file.
3. Support right-to-left (RTL) languages with a mirrored layout (post-MVP).
4. Localize dates, times, and numbers using the active locale.
5. Keep developer ergonomics simple: one function call to translate.

## 2. Non-Goals

- **Translating user-generated content** — question text, open-ended answers, nicknames, course titles are stored and shown as-is. Only the surrounding UI chrome is translated.
- **Region variants** (`en-US` vs `en-GB`). MVP uses bare language codes.
- **AI auto-translation** shipped without human review.

---

## 3. Supported Locales

| Code | Native name | English name | Direction | Status |
| --- | --- | --- | --- | --- |
| `en` | English | English | LTR | Required — default fallback |
| `tr` | Türkçe | Turkish | LTR | Required at launch |
| `de` | Deutsch | German | LTR | Planned (Phase 11) |
| `fr` | Français | French | LTR | Planned (Phase 11) |
| `ar` | العربية | Arabic | RTL | Planned (later) |

The active set is configured in `.env`:

```ini
APP_LOCALES=en,tr
APP_LOCALE_DEFAULT=en
```

A locale is available to users only if it is listed in `APP_LOCALES` **and** has a matching `locales/<code>.json` file **and** a row in the `locales` table.

---

## 4. File Layout

```text
locales/
├── README.md
├── en.json     # required — the reference set
├── tr.json     # required
├── de.json     # optional
├── fr.json     # optional
└── ar.json     # optional
```

Each file is a **flat-keyed** JSON object. No nesting. Dots in keys group concepts visually but the parser treats each key as one opaque string.

`en.json` is the **reference set** — it defines which keys must exist. Every other locale file is measured against it.

---

## 5. Translation Key Naming Convention

```text
area.screen.element
```

Examples:

```text
app.name
app.tagline
common.save
auth.login.submit
auth.login.error.invalid
session.new.title
student.join.nickname.label
question.type.multiple_choice
report.participant_count
validation.nickname_too_short
error.session_closed
```

Rules:

- Keys are stable identifiers, **not** natural-language phrases. Use `auth.login.submit`, never `t('Sign in')`.
- `area` is a coarse module: `app`, `common`, `auth`, `instructor`, `course`, `session`, `student`, `question`, `report`, `validation`, `error`, `privacy`.
- New features add keys to **both** `en.json` and `tr.json` in the same commit.

---

## 6. Locale Resolution Order

The active locale is decided per request by `I18nMiddleware`, in this order:

1. URL prefix segment, if present and valid: `/tr/admin/courses` → `tr`.
2. `?lang=` query parameter, if present and valid.
3. `eduqr_locale` cookie, if set and valid.
4. `Accept-Language` header — first matching active locale.
5. `APP_LOCALE_DEFAULT` from `.env` (`en`).

Once decided, the middleware:

- Stores the locale in the request context.
- Sets / refreshes the `eduqr_locale` cookie if it changed.
- Sets `<html lang="..." dir="ltr|rtl">` on the layout.

### 6.1 Page-specific nuances

| Page type | Additional preference |
| --- | --- |
| Instructor pages | After the steps above, `users.preferred_language` is used as a fallback before `APP_LOCALE_DEFAULT`. |
| Student pages | `sessions.language` is used as a fallback before `APP_LOCALE_DEFAULT`. |
| Public join page | Steps 1–5 only (no user / session preference yet known until the code resolves). |

---

## 7. Translation API (PHP)

```php
// src/Services/I18nService.php
final class I18nService
{
    private string $locale;
    private array  $messages;          // current locale's flat map
    private array  $fallbackMessages;  // always 'en'

    public function locale(): string
    {
        return $this->locale;
    }

    public function t(string $key, array $params = []): string
    {
        $template = $this->messages[$key]
            ?? $this->fallbackMessages[$key]
            ?? $key;

        return $this->interpolate($template, $params);
    }

    private function interpolate(string $template, array $params): string
    {
        foreach ($params as $name => $value) {
            $template = str_replace('{' . $name . '}', (string) $value, $template);
        }
        return $template;
    }
}
```

Global helper (registered in `Bootstrap.php`):

```php
function t(string $key, array $params = []): string {
    return Bootstrap::i18n()->t($key, $params);
}
```

Usage in templates:

```php
<button class="btn btn-primary">
    <?= htmlspecialchars(t('auth.login.submit'), ENT_QUOTES, 'UTF-8') ?>
</button>
<p><?= htmlspecialchars(t('report.participation_rate', ['rate' => '83%']), ENT_QUOTES, 'UTF-8') ?></p>
```

Usage in API error responses:

```php
return $this->error('duplicate_nickname', t('error.duplicate_nickname'), 'nickname', 409);
```

### 7.1 Rules

1. On a miss, the key itself is returned (e.g. `"auth.login.submit"`). This makes missing translations obvious in development.
2. Fallback chain: requested locale → English → key.
3. Placeholders are `{name}` style — they survive translator reordering, unlike positional `%s`.
4. Pluralization in MVP uses separate keys: `report.answers.zero`, `report.answers.one`, `report.answers.other`. A `tn(key, count)` helper picks the variant per the active locale's CLDR plural rules.

---

## 8. RTL Support (post-MVP)

For RTL locales (`ar`, `he`, `fa`, `ur`):

- The `<html>` tag gets `dir="rtl"`.
- The Bootstrap RTL stylesheet is loaded conditionally: `bootstrap.rtl.min.css`.
- A `body.is-rtl` class is added for custom-CSS overrides.
- Directional icons (back arrow, "next" chevron) MUST flip — prefer logical CSS properties (`margin-inline-start`) over physical (`margin-left`).

```html
<html lang="ar" dir="rtl">
```

MVP ships LTR only, but no code may assume LTR (e.g. no hardcoded `margin-left` where `margin-inline-start` would do).

---

## 9. Locale-Aware Formatting

Use PHP's `intl` extension. Wrap in helpers registered in `Bootstrap.php`:

```php
function fmt_date(\DateTimeInterface $d): string {
    return (new IntlDateFormatter(
        Bootstrap::i18n()->locale(),
        IntlDateFormatter::MEDIUM,
        IntlDateFormatter::SHORT
    ))->format($d);
}

function fmt_number(float|int $n, int $fractionDigits = 0): string {
    $f = new NumberFormatter(Bootstrap::i18n()->locale(), NumberFormatter::DECIMAL);
    $f->setAttribute(NumberFormatter::MAX_FRACTION_DIGITS, $fractionDigits);
    $f->setAttribute(NumberFormatter::MIN_FRACTION_DIGITS, $fractionDigits);
    return $f->format($n);
}

function fmt_percent(float $ratio, int $fractionDigits = 1): string {
    $f = new NumberFormatter(Bootstrap::i18n()->locale(), NumberFormatter::PERCENT);
    $f->setAttribute(NumberFormatter::MAX_FRACTION_DIGITS, $fractionDigits);
    return $f->format($ratio);
}
```

Examples:

- English: `May 13, 2026`
- Turkish: `13 May 2026`

The `intl` extension is required (`NFR-10`). `bin/install.php` checks for it.

---

## 10. Language Switcher

Every page with user-facing UI MUST show a language switcher (`FR-88`):

- In the footer of public / student pages.
- In the header of the instructor area.

It is rendered from `GET /api/v1/locales`, so adding a locale needs **no template change**.

```php
<!-- templates/partials/language-switcher.php -->
<select id="locale-switcher" name="lang" aria-label="<?= htmlspecialchars(t('common.language'), ENT_QUOTES, 'UTF-8') ?>">
    <!-- options injected from /api/v1/locales, current locale marked selected -->
</select>
```

The attached JS sets `?lang=` on the current URL, which triggers the middleware to persist the choice in the `eduqr_locale` cookie.

---

## 11. Validation & Error Messages

Validation messages and error-code messages MUST use translation keys (`FR-87`).

| Internal code (logs, API) | Translation key (user-facing) |
| --- | --- |
| `session_closed` | `error.session_closed` |
| `duplicate_nickname` | `error.duplicate_nickname` |
| `invalid_credentials` | `error.invalid_credentials` |
| (validation) `required` | `validation.required` |
| (validation) `invalid_email` | `validation.invalid_email` |
| (validation) `nickname_too_short` | `validation.nickname_too_short` |
| (validation) `nickname_too_long` | `validation.nickname_too_long` |

Internal logs use the stable English `snake_case` code; the user sees the localized message.

---

## 12. Adding a New Locale (Checklist)

```text
[ ] Copy locales/en.json to locales/<code>.json
[ ] Translate every value (not the keys)
[ ] Run: php bin/locale-check.php <code>      → coverage must be ≥ 95%
[ ] Run unit tests: composer test
[ ] Add <code> to APP_LOCALES in .env
[ ] Insert a row into the locales table (code, label_native, label_english, is_rtl, sort_order)
[ ] Smoke-test: visit /<code>/login and /<code>/join/<demo-code>
[ ] If RTL, verify layout in all 3 main views (admin, student, projector)
[ ] Add a screenshot to docs/screenshots/<code>/
```

---

## 13. Quality Checks

`bin/locale-check.php <code>` verifies:

- Every key in `en.json` exists in `<code>.json`.
- No empty translation values.
- No extra keys in `<code>.json` that are absent from `en.json` (warns).
- Reports coverage as a percentage.

A locale must reach **≥ 95 %** key coverage of `en.json` to ship to production. Missing keys log a warning at request time and fall back to English.

CI rejects a PR that adds a key to `en.json` without adding it to `tr.json`.

---

## 14. Anti-Patterns (do not do)

- ❌ `<button>Sign in</button>` — hardcoded string.
- ❌ `t('Sign in')` — natural-language key. Use a stable identifier: `t('auth.login.submit')`.
- ❌ Building a sentence by concatenating two translation keys. Build one key with `{placeholders}`.
- ❌ Storing translations in the database "for convenience". File-based stays.
- ❌ Auto-translating `tr.json` with an LLM and shipping without human review.
- ❌ Translating user-generated content (question text, nicknames, course titles).
- ❌ Hardcoding `margin-left` where `margin-inline-start` keeps RTL working.

---

## 15. Things Never Translated

- Brand: "eduQR" stays "eduQR" in every locale.
- User-supplied content: nicknames, instructor-written question text, open-ended answers, course titles.
- Database identifiers, error codes (`duplicate_nickname`), URL paths.
- Email addresses, timestamps, numeric IDs.
