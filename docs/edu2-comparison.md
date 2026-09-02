# edu2 Comparison — Portable Improvements

**Kaynak:** `C:\projects\edu2` — aynı eduQR spec'inden (AGENTS.md/PRODUCT_REQUIREMENTS.md tabanlı) türetilmiş, farklı bir geliştirici (Dilek Akyol, `github.com/DilekAkyol/eduqr-rebuild`) tarafından yazılmış bağımsız implementasyon. Bu repo'nun forku değil, paralel bir yeniden yazım.

**Amaç:** edu2'de var olup eduQR'de eksik olan özellikleri taramak, mimari uyum ve veri gizliliği açısından değerlendirip seçilenleri eduQR'nin kendi Contracts/Service/Repository mimarisine uyarlayarak taşımak.

**Genel bulgu:** eduQR mimari olarak edu2'den daha olgun (Contracts katmanı, ayrı repository/service ayrımı, question_bank_items yapısı, daha fazla test). edu2 kodu doğrudan kopyalanamaz — sadece fikir kaynağı, yeniden yazılması gerekir.

**Tarandığı tarih:** 2026-09-02

---

## Durum

| # | Özellik | Durum | Commit / Not |
|---|---------|-------|---------------|
| 1 | fill_in_the_blank soru tipi | ✅ Taşındı | `b70eed4` — FR-31 genişletildi, tek doğru cevap + case-insensitive eşleşme, FR-92 quiz puanlamasına dahil |
| 2 | exam_mode toggle (session) | ✅ Taşındı | `a0433ab` — yeni FR-96, öğrenciden canlı sonuç/doğruluk tamamen gizlenir, `ReportService::getStudentResults()` tek uygulama noktası |
| 3 | de.json + fr.json locale (T-1108) | ⏸ Beklemede | Kaynak olarak edu2 dosyaları kullanılabilir; çeviri insan onayı gerektirir (AGENTS.md: LLM çevirisini incelemeden gönderme kuralı) |
| 4 | AI destekli rapor analizi (Gemini) | ⏸ Beklemede — onay gerekli | Öğrenci cevabı 3. parti API'ye gidiyor (AGENTS.md §11 onay şartı). eduQR'nin kendi LLM servis deseni (QuestionGenerationService / OpenTextThemeExtractionService) takip edilmeli, edu2'nin hardcoded Gemini curl çağrısı değil |
| 5 | Ders başlığı/açıklaması otomatik çeviri (Gemini) | ⏸ Beklemede — onay gerekli | 3. parti API çağrısı, PRODUCT_REQUIREMENTS'ta karşılık yok, insan onayı önerilir |
| 6 | Soru bankası: unit_name/stage bazlı filtre | ❌ Değerlendirilmedi | eduQR'nin `QuestionBankRepository::findByCourse()` zaten course_id filtreli; unit/stage filtresi ayrı bir iyileştirme olarak kalabilir |
| 7 | CLI öğrenci simülasyonu (`bin/simulate-session.php`) | ✔ Zaten var | eduQR'de bu iş zaten mevcuttu — taşınacak bir şey yoktu |
| 8 | CSRF fix + i18n hardcode temizliği (edu2 commit 0e3c00c) | ✔ Zaten kapalı | eduQR Faz 10 (T-1004–T-1007) denetiminde zaten yeşil |
| 9 | Sidebar emoji + de/fr fallback kozmetik düzeltmesi | ➖ Önemsiz | edu2'ye özgü UI yapısı, eduQR'ye uygulanabilir değil |

---

## Bekleyen kararlar

- **#3 (de/fr locale):** çeviri kalitesi için insan gözden geçirmesi şart — otomatik commit edilmeyecek.
- **#4, #5 (Gemini entegrasyonları):** üçüncü parti veri paylaşımı nedeniyle Prof. Dr. İsmail Kırbaş onayı olmadan başlanmayacak (AGENTS.md §11).
- **#6 (soru bankası filtre):** küçük-orta iş, istenirse ayrı bir görev olarak TASKS.md'ye eklenip planlanabilir.

---

*Bu dosya edu2 taramasının anlık görüntüsüdür — edu2 repo'su ilerledikçe veya eduQR'de yeni portlar yapıldıkça güncellenmelidir.*
