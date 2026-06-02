# Öğrenci Mobil Premium Redesign — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Öğrenci akışı ekranlarını (join/wait/play/batch/answered) salt görsel+etkileşim katmanıyla premium mobil app kalitesine çıkarmak; mantık/akış/polling/no-JS fallback değişmeden.

**Architecture:** Tüm premium stil tek `public/assets/css/app.css` dosyasına yeni "Mobile Premium Layer" bölümü olarak eklenir (mevcut kurallar dokunulmaz). Şablonlar minimal değişir: layout'a viewport+font, join.php inline-style'dan sınıflara, play/batch sticky-CTA wrapper, wait/answered tam-yükseklik. Self-host Plus Jakarta Sans (başlıklar).

**Tech Stack:** Plain PHP server-rendered templates, Bootstrap 5, vanilla CSS (token/`clamp()` tabanlı), woff2 self-host font. Dark mode = `[data-theme="dark"]` attribute.

**Spec:** `docs/superpowers/specs/2026-06-02-student-mobile-premium-design.md`

**Verification note (TDD muafiyeti):** Bu salt görsel/CSS işidir; anlamlı birim testi yoktur. Doğrulama her task'ta: (a) `php -l` değişen şablonda, (b) mevcut `vendor/bin/phpunit` yeşil kalır, (c) görsel manuel checklist (Task 11). Sık commit. Mantık satırı değişmez → mevcut testler etkilenmez.

**Ön koşul komutları (bir kez doğrula):**
```bash
cd /home/haytek/projects/eduQR
php -v            # PHP çalışıyor mu
ls vendor/bin/phpunit 2>/dev/null && echo "phpunit var" || echo "phpunit YOK — composer install gerekebilir"
vendor/bin/phpunit --filter ParticipantServiceTest 2>&1 | tail -5   # başlangıç yeşil baseline
```
Beklenen: PHP sürümü basılır; phpunit baseline yeşil (OK). Yeşil değilse DUR, baseline'ı önce düzelt.

---

## Dosya Yapısı

| Dosya | Sorumluluk | Task |
|---|---|---|
| `public/assets/fonts/plus-jakarta-sans-700.woff2` (yeni) | Başlık font ağırlık 700 | 1 |
| `public/assets/fonts/plus-jakarta-sans-800.woff2` (yeni) | Başlık font ağırlık 800 | 1 |
| `public/assets/css/app.css` | Tüm premium stil katmanı (token, font-face, tam-yükseklik, safe-area, tap-kill, :active, sticky-cta, join-hero, reduced-motion, dark hizalama) | 2-9 |
| `templates/layouts/public.php` | viewport-fit, font preload, font değişkenleri | 3 |
| `templates/student/join.php` | Inline-style → sınıf; hibrit hero | 6 |
| `templates/student/play.php` | Sticky-CTA wrapper | 7 |
| `templates/student/batch.php` | Sticky-CTA wrapper + adım göstergesi | 8 |
| `templates/student/wait.php` | Tam-yükseklik sınıfı | 9 |
| `templates/student/answered.php` | Tam-yükseklik sınıfı | 9 |

**CSS bölüm sırası (cascade):** Tüm yeni CSS, `app.css` SONUNA (`@media(max-width:768px)` son bloğundan sonra) `/* ===== MOBILE PREMIUM LAYER ===== */` başlığıyla eklenir. Böylece mevcut kuralları override edebilir, mevcut kurallar dokunulmaz.

---

### Task 1: Self-host font dosyalarını indir

**Files:**
- Create: `public/assets/fonts/plus-jakarta-sans-700.woff2`
- Create: `public/assets/fonts/plus-jakarta-sans-800.woff2`

- [ ] **Step 1: Font dizinini oluştur ve woff2 indir**

Run:
```bash
cd /home/haytek/projects/eduQR
mkdir -p public/assets/fonts
curl -sSL -o public/assets/fonts/plus-jakarta-sans-700.woff2 \
  "https://cdn.jsdelivr.net/npm/@fontsource/plus-jakarta-sans@5/files/plus-jakarta-sans-latin-700-normal.woff2"
curl -sSL -o public/assets/fonts/plus-jakarta-sans-800.woff2 \
  "https://cdn.jsdelivr.net/npm/@fontsource/plus-jakarta-sans@5/files/plus-jakarta-sans-latin-800-normal.woff2"
```

- [ ] **Step 2: Dosyaları doğrula (woff2 magic header + boyut)**

Run:
```bash
cd /home/haytek/projects/eduQR
for f in 700 800; do
  p="public/assets/fonts/plus-jakarta-sans-$f.woff2"
  sz=$(stat -c%s "$p" 2>/dev/null || echo 0)
  hdr=$(head -c4 "$p" | xxd -p)
  echo "$p size=$sz header=$hdr"
done
```
Expected: Her dosya `size` > 20000, `header=774f4632` (= "wOF2" woff2 magic). Boyut ~0 veya header farklıysa indirme başarısız → DUR, alternatif kaynak dene (Google Fonts woff2).

- [ ] **Step 3: Commit**

```bash
cd /home/haytek/projects/eduQR
git add public/assets/fonts/plus-jakarta-sans-700.woff2 public/assets/fonts/plus-jakarta-sans-800.woff2
git commit -m "feat(student): self-host Plus Jakarta Sans woff2 for premium headings [FR-64]"
```

---

### Task 2: CSS — token & @font-face temeli

**Files:**
- Modify: `public/assets/css/app.css` (dosya SONUNA ekle, satır ~832 sonrası)

- [ ] **Step 1: Mobile Premium Layer başlığı + token + font-face ekle**

`app.css` dosyasının en SONUNA ekle:

```css
/* ============================================================
   MOBILE PREMIUM LAYER  (student flow — visual/interaction only)
   Spec: docs/superpowers/specs/2026-06-02-student-mobile-premium-design.md
   ============================================================ */

@font-face {
  font-family: "Plus Jakarta Sans";
  font-style: normal;
  font-weight: 700;
  font-display: swap;
  src: url("/assets/fonts/plus-jakarta-sans-700.woff2") format("woff2");
}
@font-face {
  font-family: "Plus Jakarta Sans";
  font-style: normal;
  font-weight: 800;
  font-display: swap;
  src: url("/assets/fonts/plus-jakarta-sans-800.woff2") format("woff2");
}

:root {
  --font-ui: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif;
  --font-display: "Plus Jakarta Sans", var(--font-ui);
  --radius-screen: 1.6rem;
  --sticky-blur: saturate(180%) blur(14px);
}
```

- [ ] **Step 2: CSS sözdizimi doğrula (brace dengesi)**

Run:
```bash
cd /home/haytek/projects/eduQR
awk '{o+=gsub(/{/,"{"); c+=gsub(/}/,"}")} END{print "open="o" close="c" diff="o-c}' public/assets/css/app.css
```
Expected: `diff=0` (açılan = kapanan brace). Diff ≠ 0 ise eklenen blokta brace hatası var → düzelt.

- [ ] **Step 3: Commit**

```bash
cd /home/haytek/projects/eduQR
git add public/assets/css/app.css
git commit -m "feat(student): add font tokens and @font-face for premium layer [FR-64]"
```

---

### Task 3: Layout — viewport-fit, font preload, font ailesi

**Files:**
- Modify: `templates/layouts/public.php:6` (viewport meta), `:9-10` (head linkleri), `:12` (body)

- [ ] **Step 1: viewport meta'yı viewport-fit=cover yap**

`templates/layouts/public.php` satır 6:
```php
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
```
→ değiştir:
```php
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
```

- [ ] **Step 2: Font preload ekle (app.css link'inden ÖNCE)**

Aynı dosyada `<link rel="stylesheet" href="/assets/css/bootstrap.min.css">` satırından hemen önce ekle:
```php
    <link rel="preload" href="/assets/fonts/plus-jakarta-sans-800.woff2" as="font" type="font/woff2" crossorigin>
```

- [ ] **Step 3: php -l ile doğrula**

Run:
```bash
cd /home/haytek/projects/eduQR
php -l templates/layouts/public.php
```
Expected: `No syntax errors detected in templates/layouts/public.php`

- [ ] **Step 4: Commit**

```bash
cd /home/haytek/projects/eduQR
git add templates/layouts/public.php
git commit -m "feat(student): viewport-fit cover + font preload in public layout [FR-64]"
```

---

### Task 4: CSS — gövde font + tam-yükseklik kabuk + safe-area + tap-kill

**Files:**
- Modify: `public/assets/css/app.css` (Mobile Premium Layer bölümüne ekle)

- [ ] **Step 1: Temel app-his kurallarını ekle**

`app.css` sonundaki Mobile Premium Layer bölümüne ekle:

```css
/* --- Base app feel --- */
body.eduqr-public {
  font-family: var(--font-ui);
  -webkit-tap-highlight-color: transparent;
  touch-action: manipulation;
  text-rendering: optimizeLegibility;
  -webkit-font-smoothing: antialiased;
}

body.eduqr-public h1,
body.eduqr-public h2,
body.eduqr-public h3,
body.eduqr-public .display-6,
body.eduqr-public .eduqr-student-hero h1,
body.eduqr-public .eduqr-join-hero h1 {
  font-family: var(--font-display);
  font-weight: 800;
  letter-spacing: -.03em;
}

/* Full-height app screen: vertically centered, safe-area padded */
.eduqr-student-screen {
  min-height: 100vh;            /* fallback */
  min-height: 100dvh;
  display: flex;
  flex-direction: column;
  justify-content: center;
  padding-top: max(1rem, env(safe-area-inset-top));
  padding-bottom: max(1rem, env(safe-area-inset-bottom));
  padding-left: env(safe-area-inset-left);
  padding-right: env(safe-area-inset-right);
}
```

- [ ] **Step 2: Brace dengesi doğrula**

Run:
```bash
cd /home/haytek/projects/eduQR
awk '{o+=gsub(/{/,"{"); c+=gsub(/}/,"}")} END{print "diff="o-c}' public/assets/css/app.css
```
Expected: `diff=0`

- [ ] **Step 3: Commit**

```bash
cd /home/haytek/projects/eduQR
git add public/assets/css/app.css
git commit -m "feat(student): full-height screen, safe-area, tap-highlight kill [FR-64, NFR-21]"
```

---

### Task 5: CSS — :active dokunma geri bildirimi + sticky CTA + join hero sınıfları

**Files:**
- Modify: `public/assets/css/app.css` (Mobile Premium Layer bölümüne ekle)

- [ ] **Step 1: :active geri bildirim + sticky-cta + join-hero/panel sınıflarını ekle**

```css
/* --- Live touch feedback (press states) --- */
body.eduqr-public .btn:active,
body.eduqr-public .eduqr-answer-option:active,
body.eduqr-public .eduqr-icon-badge:active {
  transform: scale(.97);
  box-shadow: var(--shadow-soft);
}
body.eduqr-public .eduqr-answer-option.is-selected {
  border-color: color-mix(in oklab, var(--brand) 60%, var(--line));
  background: color-mix(in oklab, var(--brand) 14%, var(--surface-strong));
}

/* --- Sticky bottom CTA bar --- */
.eduqr-sticky-cta {
  position: sticky;
  bottom: 0;
  z-index: 5;
  margin-top: 1.25rem;
  padding: .85rem 1rem calc(.85rem + env(safe-area-inset-bottom));
  display: grid;
  gap: .5rem;
  background: color-mix(in oklab, var(--surface-strong) 80%, transparent);
  backdrop-filter: var(--sticky-blur);
  -webkit-backdrop-filter: var(--sticky-blur);
  border-top: 1px solid var(--line);
  border-radius: 1.2rem 1.2rem 0 0;
}

/* --- join.php hybrid hero --- */
.eduqr-join-hero {
  text-align: center;
  padding: clamp(1.75rem, 6vw, 2.75rem) 1.25rem clamp(1.5rem, 5vw, 2.25rem);
  border-radius: var(--radius-screen);
  background: linear-gradient(150deg,
    color-mix(in oklab, var(--brand) 22%, var(--surface-strong)),
    color-mix(in oklab, var(--brand-3) 18%, var(--surface-strong)));
  border: 1px solid var(--line);
  box-shadow: var(--shadow-soft);
}
.eduqr-join-avatar {
  width: 4.5rem;
  height: 4.5rem;
  border-radius: 1.5rem;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  font-size: 2rem;
  font-weight: 800;
  font-family: var(--font-display);
  color: #fff;
  background: linear-gradient(135deg, var(--brand), var(--brand-2));
  box-shadow: 0 16px 36px color-mix(in oklab, var(--brand) 28%, transparent);
}
.eduqr-join-panel {
  margin-top: -1.1rem;
  position: relative;
  z-index: 2;
  background: var(--surface-strong);
  border: 1px solid var(--line);
  border-radius: var(--radius-screen);
  box-shadow: var(--shadow);
  padding: clamp(1.5rem, 4vw, 2rem);
}
.eduqr-join-panel .form-control-lg {
  border-radius: 1rem;
  padding: .85rem 1rem;
}
.eduqr-join-panel .btn-primary {
  border-radius: 1rem;
  font-weight: 700;
}
```

- [ ] **Step 2: Brace dengesi doğrula**

Run:
```bash
cd /home/haytek/projects/eduQR
awk '{o+=gsub(/{/,"{"); c+=gsub(/}/,"}")} END{print "diff="o-c}' public/assets/css/app.css
```
Expected: `diff=0`

- [ ] **Step 3: Commit**

```bash
cd /home/haytek/projects/eduQR
git add public/assets/css/app.css
git commit -m "feat(student): active press feedback, sticky CTA, join hero styles [FR-64]"
```

---

### Task 6: join.php — inline-style'ları sınıflara çek, hibrit hero

**Files:**
- Modify: `templates/student/join.php:90-150` (render bloğu — closed/paused gate'leri ve TÜM JS DOKUNULMAZ)

- [ ] **Step 1: Render markup'ını değiştir (satır 90-150 arası `ob_start();` sonrası HTML)**

`templates/student/join.php` içinde, satır 90 `ob_start();` ve satır 152 `<script>` arasındaki HTML bloğunu (satır 91-150) şununla değiştir:

```php
ob_start();
?>
<div class="eduqr-student-screen">
  <div class="row justify-content-center w-100 g-0">
    <div class="col-12 col-sm-10 col-md-7 col-lg-5 col-xl-4">

      <div class="eduqr-join-hero mb-0">
        <div class="eduqr-join-avatar mx-auto mb-3">
          <?= htmlspecialchars($session['short_code'][0] ?? '?', ENT_QUOTES, 'UTF-8') ?>
        </div>
        <h1 class="h3 fw-bold mb-1"><?= htmlspecialchars($session['title'], ENT_QUOTES, 'UTF-8') ?></h1>
        <div class="d-flex justify-content-center">
          <span class="eduqr-chip"><?= eduqr_icon('qr') ?> <code><?= htmlspecialchars($session['short_code'], ENT_QUOTES, 'UTF-8') ?></code></span>
        </div>
      </div>

      <div class="eduqr-join-panel">
        <div id="join-error" class="alert alert-danger d-none" role="alert"></div>

        <form id="join-form" novalidate>
          <div class="mb-3">
            <label for="nickname" class="form-label fw-semibold">
              <?= htmlspecialchars(t('student.join.nickname.label'), ENT_QUOTES, 'UTF-8') ?>
            </label>
            <input
              type="text"
              id="nickname"
              name="nickname"
              class="form-control form-control-lg"
              placeholder="<?= htmlspecialchars(t('student.join.nickname.placeholder'), ENT_QUOTES, 'UTF-8') ?>"
              maxlength="24"
              autocomplete="nickname"
              required
              autofocus
            >
            <div class="d-flex justify-content-between mt-1">
              <div class="invalid-feedback d-block" id="nickname-feedback" style="display:none"></div>
              <div class="form-text text-end ms-auto" id="nick-char">0 / 24</div>
            </div>
          </div>

          <button type="submit" id="join-btn" class="btn btn-primary btn-lg w-100">
            <?= htmlspecialchars(t('student.join.submit'), ENT_QUOTES, 'UTF-8') ?>
          </button>
        </form>

        <div class="mt-3 text-center">
          <small class="text-muted">
            <?= htmlspecialchars(t('student.join.return_desc'), ENT_QUOTES, 'UTF-8') ?>
          </small>
        </div>
      </div>

      <div class="mt-3 text-center">
        <?php include __DIR__ . '/../partials/privacy-notice.php'; ?>
      </div>
    </div>
  </div>
</div>
```

**Not:** `<script>` bloğu (satır 152+) ve altındaki `$content = ob_get_clean();` aynen kalır. JS'teki element ID'leri (`join-form`, `join-error`, `nickname`, `nickname-feedback`, `join-btn`, `nick-char`) yukarıda korundu — JS bozulmaz.

- [ ] **Step 2: php -l doğrula**

Run:
```bash
cd /home/haytek/projects/eduQR
php -l templates/student/join.php
```
Expected: `No syntax errors detected in templates/student/join.php`

- [ ] **Step 3: JS element ID parite kontrolü**

Run:
```bash
cd /home/haytek/projects/eduQR
for id in join-form join-error nickname nickname-feedback join-btn nick-char; do
  echo -n "$id: "; grep -c "id=\"$id\"\|getElementById('$id')" templates/student/join.php
done
```
Expected: Her ID için sayı ≥ 2 (biri markup, biri JS). 0 veya 1 olan varsa ID kopması → düzelt.

- [ ] **Step 4: Commit**

```bash
cd /home/haytek/projects/eduQR
git add templates/student/join.php
git commit -m "feat(student): join screen hybrid hero, inline-style to classes [FR-64]"
```

---

### Task 7: play.php — sticky CTA wrapper

**Files:**
- Modify: `templates/student/play.php:156-275` (render bloğu — gate'ler/JS DOKUNULMAZ)

- [ ] **Step 1: En dış `<div class="row...">`'u tam-yükseklik ekrana sar**

`templates/student/play.php` satır 156:
```php
<div class="row justify-content-center py-4 py-lg-5">
```
→ değiştir (aç):
```php
<div class="eduqr-student-screen">
<div class="row justify-content-center w-100 g-0">
```

- [ ] **Step 2: Submit + batch-link'i sticky-cta bar'a taşı**

Aynı dosyada, mevcut submit butonu + batch linki bloğunu (satır 257-269):
```php
                    <button
                        type="submit"
                        id="submit-btn"
                        class="btn btn-primary btn-lg w-100 mt-4 eduqr-ripple"
                        <?= $qType !== 'open_text' ? 'disabled' : '' ?>
                    >
                        <?= htmlspecialchars(t('student.answer.submit'), ENT_QUOTES, 'UTF-8') ?>
                    </button>

                    <a href="/play/<?= rawurlencode($shortCode) ?>/batch"
                       class="btn btn-outline-secondary w-100 mt-2">
                        <?= htmlspecialchars(t('student.batch.open'), ENT_QUOTES, 'UTF-8') ?>
                    </a>
```
→ değiştir:
```php
                    <div class="eduqr-sticky-cta">
                    <button
                        type="submit"
                        id="submit-btn"
                        class="btn btn-primary btn-lg w-100 eduqr-ripple"
                        <?= $qType !== 'open_text' ? 'disabled' : '' ?>
                    >
                        <?= htmlspecialchars(t('student.answer.submit'), ENT_QUOTES, 'UTF-8') ?>
                    </button>

                    <a href="/play/<?= rawurlencode($shortCode) ?>/batch"
                       class="btn btn-outline-secondary w-100">
                        <?= htmlspecialchars(t('student.batch.open'), ENT_QUOTES, 'UTF-8') ?>
                    </a>
                    </div>
```

- [ ] **Step 3: Açtığın ekstra `<div>`'u kapat**

Step 1'de bir `<div>` fazladan açtın (`eduqr-student-screen`). Render bloğunun sonunda, mevcut kapanış (satır 275 civarı):
```php
        </div>
    </div>
</div>

<script>
```
→ bir `</div>` ekle:
```php
        </div>
    </div>
</div>
</div>

<script>
```

- [ ] **Step 4: php -l + div dengesi doğrula**

Run:
```bash
cd /home/haytek/projects/eduQR
php -l templates/student/play.php
echo "open <div: $(grep -o '<div' templates/student/play.php | wc -l)  close </div: $(grep -o '</div>' templates/student/play.php | wc -l)"
```
Expected: `No syntax errors detected`; `<div` ve `</div>` sayıları EŞİT.

- [ ] **Step 5: Commit**

```bash
cd /home/haytek/projects/eduQR
git add templates/student/play.php
git commit -m "feat(student): play screen sticky CTA + full-height shell [FR-64]"
```

---

### Task 8: batch.php — sticky CTA wrapper

**Files:**
- Modify: `templates/student/batch.php:43-104` (render bloğu — JS DOKUNULMAZ)

- [ ] **Step 1: En dış row'u tam-yükseklik ekrana sar**

`templates/student/batch.php` satır 43:
```php
<div class="row justify-content-center py-4 py-lg-5">
```
→ değiştir:
```php
<div class="eduqr-student-screen">
<div class="row justify-content-center w-100 g-0">
```

- [ ] **Step 2: Submit butonunu sticky-cta bar'a al**

Mevcut submit bloğu (satır 96-98):
```php
        <div class="d-flex gap-2">
          <button type="submit" id="batch-submit" class="btn btn-primary flex-fill btn-lg eduqr-ripple"><?= htmlspecialchars(t('student.batch.submit_all'), ENT_QUOTES, 'UTF-8') ?></button>
        </div>
```
→ değiştir:
```php
        <div class="eduqr-sticky-cta">
          <button type="submit" id="batch-submit" class="btn btn-primary w-100 btn-lg eduqr-ripple"><?= htmlspecialchars(t('student.batch.submit_all'), ENT_QUOTES, 'UTF-8') ?></button>
        </div>
```

- [ ] **Step 3: Açılan ekstra `<div>`'u kapat**

Render bloğu sonunda (satır 102-104):
```php
    </div>
    </div>
  </div>
</div>

<script>
```
→ bir `</div>` ekle:
```php
    </div>
    </div>
  </div>
</div>
</div>

<script>
```

- [ ] **Step 4: php -l + div dengesi doğrula**

Run:
```bash
cd /home/haytek/projects/eduQR
php -l templates/student/batch.php
echo "open <div: $(grep -o '<div' templates/student/batch.php | wc -l)  close </div: $(grep -o '</div>' templates/student/batch.php | wc -l)"
```
Expected: `No syntax errors detected`; `<div`/`</div>` sayıları EŞİT.

- [ ] **Step 5: Commit**

```bash
cd /home/haytek/projects/eduQR
git add templates/student/batch.php
git commit -m "feat(student): batch screen sticky CTA + full-height shell [FR-64]"
```

---

### Task 9: wait.php + answered.php — tam-yükseklik kabuk

**Files:**
- Modify: `templates/student/wait.php:18` + kapanış
- Modify: `templates/student/answered.php:32` + kapanış

- [ ] **Step 1: wait.php en dış row'u sar**

`templates/student/wait.php` satır 18:
```php
<div class="row justify-content-center py-4 py-lg-5">
```
→ değiştir:
```php
<div class="eduqr-student-screen">
<div class="row justify-content-center w-100 g-0">
```
Ve render bloğu kapanışı (satır 57-59):
```php
        </div>
    </div>
</div>

<script>
```
→ bir `</div>` ekle:
```php
        </div>
    </div>
</div>
</div>

<script>
```

- [ ] **Step 2: answered.php en dış row'u sar**

`templates/student/answered.php` satır 32:
```php
<div class="row justify-content-center py-4 py-lg-5">
```
→ değiştir:
```php
<div class="eduqr-student-screen">
<div class="row justify-content-center w-100 g-0">
```
Ve render bloğu kapanışı (satır 57-59):
```php
            </div>
        </div>
    </div>
</div>

<script>
```
→ bir `</div>` ekle:
```php
            </div>
        </div>
    </div>
</div>
</div>

<script>
```

- [ ] **Step 3: php -l + div dengesi doğrula (her iki dosya)**

Run:
```bash
cd /home/haytek/projects/eduQR
for f in wait answered; do
  php -l templates/student/$f.php
  echo "$f: open $(grep -o '<div' templates/student/$f.php | wc -l) close $(grep -o '</div>' templates/student/$f.php | wc -l)"
done
```
Expected: İkisi de `No syntax errors detected`; her dosyada `<div`/`</div>` EŞİT.

- [ ] **Step 4: Commit**

```bash
cd /home/haytek/projects/eduQR
git add templates/student/wait.php templates/student/answered.php
git commit -m "feat(student): wait + answered full-height app shell [FR-64]"
```

---

### Task 10: CSS — reduced-motion guard + dark mode hizalama

**Files:**
- Modify: `public/assets/css/app.css` (Mobile Premium Layer bölümüne ekle)

- [ ] **Step 1: prefers-reduced-motion guard + dark hizalama ekle**

`app.css` Mobile Premium Layer sonuna ekle (mevcut keyframe adları: rise, hourglass-top/bottom/stream, fade-in-up, fade-in-scale, bounce-select, pulse-ring, draw-stroke, shimmer, float, breathing, confetti-fall, ripple-out, slide-down, badge-pulse, spinner-ring):

```css
/* --- Reduced motion: kill decorative animation, KEEP press feedback --- */
@media (prefers-reduced-motion: reduce) {
  body.eduqr-public .eduqr-confetti { display: none !important; }
  body.eduqr-public .eduqr-hourglass-sand,
  body.eduqr-public .eduqr-hourglass-stream,
  body.eduqr-public .eduqr-breathe,
  body.eduqr-public .eduqr-opt-bounce,
  body.eduqr-public .eduqr-ripple,
  body.eduqr-public .eduqr-fade-scale,
  body.eduqr-public .eduqr-stagger,
  body.eduqr-public .eduqr-check-svg,
  body.eduqr-public .eduqr-spinner {
    animation: none !important;
  }
  /* :active scale geri bildirimi KORUNUR (durum, hareket değil) */
}

/* --- Dark mode parity for new premium surfaces --- */
[data-theme="dark"] .eduqr-join-hero {
  background: linear-gradient(150deg,
    color-mix(in oklab, var(--brand) 26%, var(--surface-dark)),
    color-mix(in oklab, var(--brand-3) 18%, var(--surface-dark)));
}
[data-theme="dark"] .eduqr-join-panel {
  background: var(--surface-dark);
}
[data-theme="dark"] .eduqr-sticky-cta {
  background: color-mix(in oklab, var(--surface-dark) 86%, transparent);
}
```

- [ ] **Step 2: Brace dengesi + keyframe parite doğrula**

Run:
```bash
cd /home/haytek/projects/eduQR
awk '{o+=gsub(/{/,"{"); c+=gsub(/}/,"}")} END{print "diff="o-c}' public/assets/css/app.css
grep -c "prefers-reduced-motion" public/assets/css/app.css
```
Expected: `diff=0`; `prefers-reduced-motion` sayısı = 1.

- [ ] **Step 3: Commit**

```bash
cd /home/haytek/projects/eduQR
git add public/assets/css/app.css
git commit -m "feat(student): reduced-motion guard + dark mode parity [FR-64, NFR-21]"
```

---

### Task 11: Tam doğrulama — lint, testler, manuel mobil checklist

**Files:** (yalnızca doğrulama, kod değişikliği yok)

- [ ] **Step 1: Tüm değişen şablonlarda php -l**

Run:
```bash
cd /home/haytek/projects/eduQR
for f in layouts/public student/join student/play student/batch student/wait student/answered; do
  php -l templates/$f.php
done
```
Expected: Hepsi `No syntax errors detected`.

- [ ] **Step 2: Mevcut birim testleri yeşil (regresyon yok)**

Run:
```bash
cd /home/haytek/projects/eduQR
vendor/bin/phpunit 2>&1 | tail -8
```
Expected: `OK` (mantık değişmediği için baseline'la aynı). `FAILURES` çıkarsa DUR — değişikliklerin mantığa sızdığını gösterir, incele.

- [ ] **Step 3: CSS global brace dengesi (son kontrol)**

Run:
```bash
cd /home/haytek/projects/eduQR
awk '{o+=gsub(/{/,"{"); c+=gsub(/}/,"}")} END{print "brace diff="o-c}' public/assets/css/app.css
```
Expected: `brace diff=0`.

- [ ] **Step 4: Manuel mobil checklist (geliştirici, 375px viewport — Chrome DevTools device mode + gerçek telefon varsa)**

Sunucuyu başlat (örn. `php -S localhost:8080 -t public` veya proje justfile'ı), bir oturum oluştur, `/join/{kod}` aç. Doğrula:
- [ ] Safe-area: (cihaz çentikliyse) içerik çentik/home-indicator altında kesilmiyor.
- [ ] Tap: cevap seçeneğine/butona dokununca gri flaş YOK, basışta hafif küçülme (scale .97) VAR.
- [ ] Çift-dokun zoom YOK; iki-parmak pinch-zoom çalışıyor.
- [ ] `play` (uzun/resimli soru): Gönder butonu alta yapışık, safe-area dolgulu, kaydırınca görünür kalıyor.
- [ ] `batch` (4 soru): "Tümünü gönder" alta yapışık.
- [ ] `join`: hero gradient + harf rozeti ortalı, panel yukarı-kıvrık, buton tam-genişlik pill.
- [ ] Tüm 5 ekran tam-yükseklik, dikey ortalı, üstte kayan boş bant yok.
- [ ] Başlıklar Plus Jakarta Sans render oluyor (DevTools Computed → font-family).
- [ ] Dark mode toggle: hero/panel/sticky yüzeyleri açık modla pariteli, okunur.
- [ ] DevTools → Rendering → "Emulate prefers-reduced-motion: reduce": confetti yok, hourglass/breathe durağan, ama buton :active geri bildirimi hâlâ var.
- [ ] No-JS: DevTools'ta JS'i kapat, `play` ekranında radio butonlar görünüyor + form POST cevabı kaydediyor → `/answered`'a yönleniyor.
- [ ] i18n: dili TR yap, uzun başlık hero'da taşmıyor; EN'de de düzgün.

- [ ] **Step 5: Manuel checklist sonucunu kaydet (commit gerekmez, rapor)**

Checklist'te kırık madde varsa: ilgili Task'a dön, düzelt, yeniden commit. Hepsi yeşilse implementasyon tamam.

---

## Self-Review (plan yazarı tarafından yapıldı)

**Spec coverage:**
- G1 safe-area → Task 3 (viewport-fit) + Task 4 (env() padding). ✓
- G2 tap-kill → Task 4. ✓
- G3 :active → Task 5. ✓
- G4 sticky CTA → Task 5 (CSS) + Task 7/8 (play/batch markup). ✓
- G5 100dvh tam-yükseklik → Task 4 (.eduqr-student-screen) + Task 7/8/9 (uygulama). ✓
- G6 reduced-motion → Task 10. ✓
- G7 join.php sapması → Task 6. ✓
- G8 tipografi → Task 1 (font), 2 (@font-face), 3 (preload), 4 (font-family). ✓
- 5 ekran (join/play/batch/wait/answered) → Task 6/7/8/9. ✓
- Hibrit hero (sadece join) → Task 5 (sınıf) + Task 6 (markup). ✓
- Dark mode hizalama → Task 10. ✓
- Test/no-JS/i18n → Task 11. ✓

**Placeholder scan:** TBD/TODO yok; her CSS/markup adımı tam kod içerir.

**Type/isim consistency:** Sınıf adları tutarlı — `.eduqr-student-screen`, `.eduqr-sticky-cta`, `.eduqr-join-hero`, `.eduqr-join-avatar`, `.eduqr-join-panel`, `--font-display`, `--font-ui`, `--radius-screen`, `--sticky-blur` her referansta aynı yazımla. JS element ID'leri Task 6'da korundu (parite kontrolü Step 3).

**Risk notu:** En kırılgan adımlar div dengesi (Task 7/8/9) — her birinde `<div`/`</div>` sayım doğrulaması var. Font indirme (Task 1) ağ bağımlı — magic-header doğrulaması + alternatif kaynak notu var.
