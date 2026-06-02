# Öğrenci Arayüzü — Premium Mobil App Redesign (Tasarım Spec)

Tarih: 2026-06-02
Kapsam: Yalnızca öğrenci akışı ekranları (`templates/student/*`) + paylaşılan public layout + CSS.
Tür: Salt görsel + etkileşim katmanı. Mantık/akış/polling/no-JS fallback değişmez.
İlgili: FR-45 (active-question polling), FR-54 (results polling), FR-80/81/88 (i18n), NFR-21 (responsive UX), NFR-52 (test). Yeni fonksiyonel gereksinim yok.
Önceki bağlam: `docs/superpowers/plans/2026-06-02-ui-premium-redesign-plan.md` (genel premium pass) üstüne oturur; bu spec onun öğrenci-mobil derinleştirmesidir.

## 1. Amaç

Mevcut öğrenci deneyimini masaüstü-premium seviyesinden **mobil app kalitesine** çıkarmak. Referans yön: Dribbble "Login and Sign up Screens" tarzı (büyük yuvarlatılmış yüzeyler, yumuşak pastel, pill butonlar, dostça/app-gibi his). Sınıf-içi hızlı araç doğası (polling, no-JS fallback) korunur.

## 2. Sabit Tasarım Kararları (kullanıcı onaylı)

1. **Genel his:** Bütünsel "premium mobil app" — tek tek değil, native app hissi.
2. **Hibrit hero:** Sadece `join` ekranı hafif hero (gradient + harf rozeti, illüstrasyon asset'i YOK). Akış ekranları (wait/play/batch/answered) sade + hızlı.
3. **Hibrit tipografi:** Başlıklar self-host **Plus Jakarta Sans** (700/800). Gövde/UI sistem font yığını (`-apple-system`...).
4. **Sticky CTA:** Tüm cevap ekranlarında (`play`, `batch`) "Gönder" butonu her zaman alta yapışık bar, safe-area dolgulu. Salt CSS (`position:sticky`), no-JS bozulmaz.
5. **Canlı dokunma geri bildirimi:** Basışta belirgin küçülme (scale .97) + gölge çökmesi + seçimde mevcut `eduqr-opt-bounce`. Haptic YOK (tarayıcı tutarsızlığı).
6. **Uygulama stratejisi:** Yaklaşım 1 — tek CSS katmanı. Tüm premium iş `app.css`'e yeni bölüm; şablonlar minimal değişir. Ayrı dosya / inline `<style>` YOK.

## 3. Mevcut Durum (inceleme bulguları)

- Stack: Plain PHP + Bootstrap 5, server-rendered, polling tabanlı. Tek `public/assets/css/app.css` (832 satır), 78 `eduqr-*` sınıf, pastel token sistemi (`--brand`, `--surface`, `clamp()` tipografi), cam yüzeyler.
- Öğrenci akışı: `join → wait → play → answered`, + `batch` (toplu cevap).
- Mevcut güçlü: hourglass/confetti/check-SVG/opt-bounce/ripple animasyonları; her ekranda no-JS fallback.
- **Mobil boşluklar (bu spec'in hedefi):**
  - G1 — Safe-area yok (`env(safe-area-inset-*)` hiç yok; viewport `viewport-fit=cover` değil).
  - G2 — Tap-highlight + `touch-action` yok (gri flaş, çift-dokun zoom).
  - G3 — `:active` durumu yok (tüm geri bildirim `:hover` üzerinden; dokunmatik hover yapmaz).
  - G4 — Sticky CTA yok (`play`/`batch` uzun olunca buton ekran dışı).
  - G5 — Tek zayıf media query (`@media(max-width:768px)` sadece 3 padding kuralı; `100dvh` yok).
  - G6 — `prefers-reduced-motion` guard yok.
  - G7 — `join.php` sistemden sapmış (inline-style ağırlıklı, satır ~92-150; diğerleri `eduqr-student-stage` kullanır).
  - G8 — Premium tipografi yok (Bootstrap default font).

## 4. Mimari & Değişen Dosyalar

| Dosya | Değişiklik | Risk |
|---|---|---|
| `public/assets/css/app.css` | Yeni "Mobile Premium Layer" bölümü (~250 satır eklenir). Mevcut kurallar dokunulmaz; birkaç interaktif sınıfa `:active` genişletmesi. | Düşük |
| `templates/layouts/public.php` | `viewport-fit=cover`; Plus Jakarta Sans preload `<link>`; gövdeye font değişkenleri uygulanır. | Düşük |
| `templates/student/join.php` | Inline-style'lar → `eduqr-*` sınıfları. Hibrit hero (`.eduqr-join-hero` + `.eduqr-join-panel`). JS/akış aynı. | Orta (görsel) |
| `templates/student/play.php` | Form alanı sticky-CTA wrapper'a (`.eduqr-sticky-cta`). JS aynı. | Düşük |
| `templates/student/batch.php` | Submit sticky-CTA wrapper'a; adım göstergesi bar üstünde. JS aynı. | Düşük |
| `templates/student/wait.php` | Tam-yükseklik ortalama sınıfı. İçerik/polling aynı. | Düşük |
| `templates/student/answered.php` | Tam-yükseklik ortalama sınıfı. İçerik/polling aynı. | Düşük |
| `public/assets/fonts/` (yeni) | `PlusJakartaSans-Bold.woff2` (700), `PlusJakartaSans-ExtraBold.woff2` (800). | Düşük |

**Değişmeyen sınır:** Hiçbir Controller/Repository/Service/route/migration. Polling, `/api/v1/*`, cookie/participant akışı, soru modeli, oturum durum gate'leri (closed/paused), no-JS fallback. Admin/projector/live ekranları (kapsam dışı).

## 5. Ekran-ekran Değişiklikler

### Ortak katman (5 ekran)
- Tam-yükseklik kabuk: `min-height:100dvh` (fallback `100vh`), dikey ortalama. Yeni sınıf `.eduqr-student-screen`.
- Safe-area: `padding: env(safe-area-inset-top) env(safe-area-inset-right) env(safe-area-inset-bottom) env(safe-area-inset-left)` ilgili konteynerlerde.
- Tap kill: `body.eduqr-public { -webkit-tap-highlight-color: transparent; touch-action: manipulation; }`.
- `:active` geri bildirim: `.eduqr-answer-option`, `.btn`, `.eduqr-icon-badge` vb. → `transform: scale(.97)` + gölge azalır.

### join.php — hibrit hero
- Üst ~%35: `.eduqr-join-hero` — yumuşak gradient (`--brand`→`--brand-3`), ortada büyütülmüş harf rozeti (mevcut, `eduqr-breathe`), altında başlık + kod chip'i.
- Alt: `.eduqr-join-panel` — üst köşeleri büyük yuvarlatılmış yukarı-kıvrık yüzey; nickname input (pill, `form-control-lg`) + tam-genişlik gradient pill buton.
- Bu ekran kısa → sticky CTA gerekmez (buton akış içinde).
- Tüm inline `style="..."` kaldırılır, sınıflara taşınır. JS (join fetch, karakter sayacı, hata) aynen korunur.

### play.php — sticky CTA + canlı seçenekler
- Soru başlığı/resim/seçenekler üstte kayar (`.eduqr-student-screen` içinde).
- `.eduqr-sticky-cta`: `position:sticky; bottom:0; padding-bottom:calc(env(safe-area-inset-bottom) + .75rem); backdrop-filter:blur(...)`; içinde Gönder butonu (birincil) + "Toplu cevapla" linki (ikincil).
- Seçenek butonları: `:active` scale .97 + gölge çökmesi; seçimde mevcut `is-selected`/`eduqr-opt-bounce` güçlenir.
- No-JS: form POST + noscript radio'lar değişmeden çalışır (sticky wrapper salt CSS).

### batch.php — sticky "Tümünü gönder"
- 4 soru kartı dikey kayar. Adım göstergesi (`.eduqr-steps`) sticky bar üst kenarında sabit → ilerleme her zaman görünür.
- `.eduqr-sticky-cta` içinde "Tümünü gönder". JS (updateSteps, bulk submit) aynen korunur.

### wait.php — tam-yükseklik sahne
- Hourglass + içerik dikey ortalı, ekranı doldurur. Polling/içerik aynı.

### answered.php — tam-yükseklik kutlama
- Check-SVG + confetti merkezde, tam-yükseklik. Confetti `prefers-reduced-motion`'da gizli. Polling/içerik aynı.

## 6. Token & Font

Yeni `:root` token'ları:
```
--font-display: "Plus Jakarta Sans", var(--font-ui);
--font-ui: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif;
--radius-screen: 1.6rem;      /* yukarı-kıvrık panel köşeleri */
--sticky-blur: saturate(180%) blur(14px);
```
- `body` → `font-family: var(--font-ui)`. `h1..h3, .display-6, .eduqr-student-hero h1, .eduqr-join-hero h1` → `font-family: var(--font-display); font-weight:800; letter-spacing:-.03em`.
- `@font-face` iki ağırlık, `font-display:swap`, woff2, local fallback. Preload sadece 800 (en görünür başlık).
- Dark mode: mevcut dark token'ları korunur; yeni hero/panel/sticky yüzeyleri `--surface-dark` ile hizalanır (açık/koyu paritesi).

## 7. Erişilebilirlik & Performans

- `@media (prefers-reduced-motion: reduce)`: confetti `display:none`; hourglass/breathe/opt-bounce/ripple/fade-scale → `animation:none`. `:active` scale geri bildirimi KORUNUR (hareket değil, durum).
- Sticky CTA odak sırası: buton DOM'da formun mantıksal sonunda kalır (klavye/screen-reader sırası bozulmaz).
- `touch-action:manipulation` çift-dokun zoom'u kapatır ama pinch-zoom korunur (erişilebilirlik).
- Font: woff2 + swap → FOUT minimal, layout shift yok (metrik-uyumlu fallback).
- Renk kontrastı: hero gradient üstü metin AA (4.5:1) doğrulanır.

## 8. Test & Doğrulama (NFR-52)

- **Otomatik:** Mevcut `tests/Unit/ParticipantServiceTest.php` yeşil kalmalı (mantık değişmediği için etkilenmemeli). `php -l` lint tüm değişen şablonlarda (`join/play/batch/wait/answered`, `layouts/public.php`).
- **No-JS regresyon:** JS kapalı → her cevap ekranı form POST ile çalışır (radio'lar görünür, submit POST'lar). Sticky wrapper salt CSS olduğu için bozulmamalı.
- **Manuel mobil checklist (375px viewport, iOS Safari + Android Chrome):**
  - [ ] Safe-area: çentik/home-indicator altında içerik kesilmiyor.
  - [ ] Tap: butona dokununca gri flaş yok, scale .97 geri bildirim var.
  - [ ] Çift-dokun zoom yok; pinch-zoom çalışıyor.
  - [ ] `play`/`batch`: Gönder butonu uzun içerikte alta yapışık, safe-area dolgulu.
  - [ ] `join`: hero + panel düzgün, harf rozeti ortalı, buton tam-genişlik.
  - [ ] Tüm ekranlar tam-yükseklik, dikey ortalı, kayan boşluk yok.
  - [ ] Dark mode: hero/panel/sticky yüzeyleri açık modla pariteli.
  - [ ] reduced-motion açık → confetti yok, animasyonlar durağan, `:active` hâlâ çalışıyor.
  - [ ] i18n: TR + EN string'leri taşmıyor (uzun TR başlıklar hero'da sığıyor).

## 9. Geri Alınabilirlik

Tüm değişiklik salt görsel/etkileşim. `git revert` ile tamamen geri alınabilir olmalı; hiçbir mantık satırı, route, veri modeli etkilenmez.

## 10. Kapsam Dışı (YAGNI)

- Hero illüstrasyon asset'i (sadece gradient + harf rozeti).
- Haptic/vibrate.
- Admin/projector/live ekranları.
- Sticky CTA için IntersectionObserver (salt CSS sticky yeterli).
- Ayrı mobil kod yolu / ayrı CSS dosyası.
- PWA/offline/service worker (bu spec dışı).
