# Türkçe Çeviri İnceleme Kuyruğu

> Bu dosya bir **inceleme talebidir**, tamamlanmış bir inceleme değildir.

Aşağıdaki `tr.json` değerleri son beş özellik dalgasıyla birlikte bir dil modeli tarafından
üretildi ve **hiçbir Türkçe konuşan kişi tarafından gözden geçirilmedi**. `AGENTS.md` §10 ve
`I18N_SPEC.md` §14 insan incelemesinden geçmemiş `tr.json` içeriğinin yayına alınmasını yasaklıyor,
dolayısıyla bu maddeler kapatılana kadar Türkçe yayına hazır sayılmaz.

`php bin/locale-check.php tr` komutunun %100 kapsam bildirmesi bu kuyruğu kapatmaz:
o komut yalnızca `tr.json` içinde `en.json` ile aynı anahtarların bulunduğunu doğrular,
değerlerin doğru olduğunu değil.

## Nasıl incelenir

1. Her madde için İngilizce kaynağı ve mevcut Türkçe karşılığı karşılaştırın.
2. Karşılık doğruysa kutuyu işaretleyin: `- [x]`.
3. Yanlışsa kutuyu boş bırakın ve maddenin altına önerdiğiniz metni yazın.
4. Kayıt yeri kuralı: anahtar hem `locales/en.json` hem `locales/tr.json` içinde bulunmalıdır.

## Uyulması gereken üslup kuralları (`AGENTS.md` §13)

- Akademik ama erişilebilir bir dil.
- **Öğrenci arayüzü** 2. tekil şahıs (`sen`): "Cevabını yaz".
- **Eğitmen arayüzü** 2. çoğul/nazik (`siz`): "E-posta adresini girin".
- Hata mesajlarında ünlem yok, emoji yok.
- "eduQR" marka adı çevrilmez. `{placeholder}` adları da çevrilmez, aynen korunur.
- Kullanıcı içeriği (takma ad, soru metni, ders başlığı) hiçbir zaman çevrilmez.

## Özet

| Özellik | Commit | Gereksinim | Anahtar sayısı |
| --- | --- | --- | --- |
| Boşluk doldurma soru tipi | `b70eed4` | FR-31 | 3 |
| Sınav modu (sonuçları gizleme) | `a0433ab` | FR-96 | 1 |
| Öğrenci anlama tepkileri | `99b3e3c` | FR-48 | 8 |
| Yardımcı eğitmen erişimi | `01ddd4c` | FR-97 | 19 |
| LMS dışa aktarımı (Moodle GIFT + not çizelgesi) | `8a1f59a` | FR-98 | 9 |
| **Toplam** | | | **40** |

## Öncelikli maddeler — karara bağlandı

Aşağıdaki üç madde yalnızca üslup meselesi değildi ve proje yöneticisinin kararıyla çözüldü.
Yine de nihai söz insan gözden geçiricide: aşağıdaki seçimlerden biri yanlışsa değiştirin.

1. ~~`session.exam_mode` ile `session.quiz_mode` aynı karşılığa ("Sınav Modu") sahipti~~
   → **Çözüldü.** `session.quiz_mode` artık **"Puanlama Modu"**. İki özellik farklı işler
   yapıyor (quiz modu = `is_correct` üzerinden puanlama, FR-92; sınav modu = sonuçları
   öğrenciden gizleme, FR-96) ve aynı oturum formunda yan yana duruyorlardı. "Puanlama Modu"
   işlevi doğrudan anlatıyor ve "Sınav Modu" ile karışmıyor.
2. ~~`course.instructors.role.co_instructor` = "Yardımcı Eğitmen"~~
   → **Çözüldü.** Artık **"Ortak Eğitmen"**. İngilizce karşılığı "Co-instructor"; "yardımcı"
   ast bir rol çağrıştırıyordu. Not: bu rolün yetkisi ders sahibiyle *tamamen* eşit değil —
   ders arşivleme/geri yükleme ve eğitmen listesi yönetimi yalnızca sahibe ait (FR-97) —
   ama öğretim işlerinde eşit, dolayısıyla "ortak" doğru karşılık.
3. ~~"instructor" için iki terim: "Eğitmen" ve "Öğretim Elemanı"~~
   → **Çözüldü.** **"Eğitmen"** standart. `tr.json` içinde "Eğitmen" 7 yerde, "Öğretim Elemanı"
   yalnızca `instructor.dashboard.title` içinde geçiyordu; o anahtar "Eğitmen Paneli" oldu.

## Boşluk doldurma soru tipi

_Commit `b70eed4` — FR-31_

- [ ] `student.answer.fill_in_the_blank_placeholder`
  - **EN:** Type your answer
  - **TR:** Cevabını yaz
  - **Not:** **Düzeltildi** (NFR-77 çalışmasında): "Cevabınızı yazın" → "Cevabını yaz". Öğrenci arayüzü AGENTS.md §13 gereği 2. tekil şahıs; kardeş anahtar `student.answer.text_placeholder` de "Cevabını buraya yaz" biçiminde. Yine de teyit edilmeli.
- [ ] `question.field.correct_answer`
  - **EN:** Correct Answer
  - **TR:** Doğru Cevap
- [ ] `question.type.fill_in_the_blank`
  - **EN:** Fill in the Blank
  - **TR:** Boşluk Doldurma

## Sınav modu (sonuçları gizleme)

_Commit `a0433ab` — FR-96_

- [ ] `session.exam_mode`
  - **EN:** Exam Mode (hide results from students)
  - **TR:** Sınav Modu (öğrencilerden sonuçları gizle)
  - **Not:** **Terim çakışması.** `session.quiz_mode` de "Sınav Modu" olarak çevrilmiş. Bunlar iki ayrı özellik (quiz modu = puanlama, FR-92; sınav modu = sonuçları gizleme, FR-96) ve aynı oturum formunda yan yana görünüyor. İkisine ayrı ad verilmeli.

## Öğrenci anlama tepkileri

_Commit `99b3e3c` — FR-48_

- [ ] `student.reaction.prompt`
  - **EN:** How are you following this question?
  - **TR:** Bu soruyu ne kadar takip edebiliyorsun?
- [ ] `student.reaction.got_it`
  - **EN:** I got it
  - **TR:** Anladım
- [ ] `student.reaction.lost`
  - **EN:** I am lost
  - **TR:** Kayboldum
- [ ] `student.reaction.recorded`
  - **EN:** Your reaction has been recorded.
  - **TR:** Tepkin kaydedildi.
- [ ] `results.reactions.title`
  - **EN:** Comprehension pulse
  - **TR:** Anlama nabzı
  - **Not:** "Anlama nabzı" birebir çeviri. Türkçede doğal karşılığı tercih edilebilir.
- [ ] `results.reactions.got_it`
  - **EN:** Got it
  - **TR:** Anladı
  - **Not:** Yanında sayı gösterilen bir etiket (ör. "Anladı: 12"). Üçüncü tekil geçmiş zaman yerine ad hâli ("Anlayan") daha uygun olabilir.
- [ ] `results.reactions.lost`
  - **EN:** Lost
  - **TR:** Kayboldu
  - **Not:** Aynı gerekçe: "Kayboldu" yerine "Kaybolan" değerlendirilebilir.
- [ ] `error.invalid_reaction`
  - **EN:** That reaction is not recognized.
  - **TR:** Bu tepki tanınmıyor.

## Yardımcı eğitmen erişimi

_Commit `01ddd4c` — FR-97_

- [ ] `course.instructors.title`
  - **EN:** Course Instructors
  - **TR:** Ders Eğitmenleri
  - **Not:** Terim tutarlılığı: `course.*` anahtarları "Eğitmen", `instructor.dashboard.title` ise "Öğretim Elemanı" diyor. Ürün genelinde tek bir terim seçilmeli.
- [ ] `course.instructors.subtitle`
  - **EN:** Co-instructors share full access to this course's sessions, questions, and reports. Only the owner can archive the course or change this list.
  - **TR:** Yardımcı eğitmenler bu dersin oturumlarına, sorularına ve raporlarına tam erişime sahiptir. Dersi yalnızca ders sahibi arşivleyebilir veya bu listeyi değiştirebilir.
- [ ] `course.instructors.empty`
  - **EN:** This course has no other instructors yet.
  - **TR:** Bu derste henüz başka eğitmen bulunmuyor.
- [ ] `course.instructors.column.name`
  - **EN:** Instructor
  - **TR:** Eğitmen
- [ ] `course.instructors.column.role`
  - **EN:** Role
  - **TR:** Rol
- [ ] `course.instructors.role.owner`
  - **EN:** Owner
  - **TR:** Ders Sahibi
- [ ] `course.instructors.role.co_instructor`
  - **EN:** Co-instructor
  - **TR:** Yardımcı Eğitmen
  - **Not:** "Yardımcı Eğitmen" ast bir rol çağrıştırıyor; oysa alt başlıkta belirtildiği gibi yardımcı eğitmenin erişimi ders sahibiyle aynı. "Eş Eğitmen" daha doğru olabilir.
- [ ] `course.instructors.add.email`
  - **EN:** Instructor email
  - **TR:** Eğitmen e-postası
- [ ] `course.instructors.add.hint`
  - **EN:** Enter the email address of an existing instructor account.
  - **TR:** Mevcut bir eğitmen hesabının e-posta adresini girin.
- [ ] `course.instructors.add.submit`
  - **EN:** Add Instructor
  - **TR:** Eğitmen Ekle
- [ ] `course.instructors.remove`
  - **EN:** Remove
  - **TR:** Kaldır
- [ ] `course.instructors.remove_confirm`
  - **EN:** Remove this co-instructor from the course?
  - **TR:** Bu yardımcı eğitmen dersten kaldırılsın mı?
- [ ] `course.instructor.added`
  - **EN:** Instructor added to the course.
  - **TR:** Eğitmen derse eklendi.
- [ ] `course.instructor.removed`
  - **EN:** Instructor removed from the course.
  - **TR:** Eğitmen dersten kaldırıldı.
- [ ] `error.instructor_not_found`
  - **EN:** No instructor account exists for that email address.
  - **TR:** Bu e-posta adresine ait bir eğitmen hesabı bulunamadı.
- [ ] `error.course_instructor_not_found`
  - **EN:** That instructor is not on this course.
  - **TR:** Bu eğitmen bu derste bulunmuyor.
- [ ] `error.already_course_instructor`
  - **EN:** That instructor already has access to this course.
  - **TR:** Bu eğitmenin bu derse erişimi zaten var.
- [ ] `error.cannot_remove_course_owner`
  - **EN:** The course owner cannot be removed from the course.
  - **TR:** Ders sahibi dersten kaldırılamaz.
- [ ] `error.course_owner_only`
  - **EN:** Only the course owner can do that.
  - **TR:** Bunu yalnızca ders sahibi yapabilir.

## LMS dışa aktarımı (Moodle GIFT + not çizelgesi)

_Commit `8a1f59a` — FR-98_

- [ ] `report.quiz_score`
  - **EN:** Score
  - **TR:** Puan
- [ ] `report.export_gift`
  - **EN:** Export GIFT (Moodle)
  - **TR:** GIFT Dışa Aktar (Moodle)
  - **Not:** "GIFT Dışa Aktar" sözcük dizilişi Türkçede biraz eğreti. "GIFT Olarak Dışa Aktar" düşünülebilir.
- [ ] `report.export_gradebook`
  - **EN:** Export Gradebook CSV
  - **TR:** Not Çizelgesi CSV Dışa Aktar
  - **Not:** Aynı gerekçe: "Not Çizelgesi CSV Dışa Aktar".
- [ ] `report.gift.file_header`
  - **EN:** eduQR question export for session {session}, Moodle GIFT format. Import this file into your LMS question bank.
  - **TR:** eduQR soru dışa aktarımı, oturum {session}, Moodle GIFT biçimi. Bu dosyayı öğrenme yönetim sisteminizin soru bankasına aktarın.
- [ ] `report.gift.question_title`
  - **EN:** Question {number}
  - **TR:** Soru {number}
- [ ] `report.gift.no_correct_answer`
  - **EN:** No correct answer is marked for this question, so it is exported as an essay question.
  - **TR:** Bu soru için doğru cevap işaretlenmediğinden açık uçlu soru olarak dışa aktarıldı.
  - **Not:** "essay question" burada "açık uçlu soru" olarak çevrilmiş. Moodle Türkçe arayüzünde karşılığın "kompozisyon" olup olmadığı teyit edilmeli.
- [ ] `report.gradebook.header.score`
  - **EN:** Score
  - **TR:** Puan
- [ ] `report.gradebook.header.max_score`
  - **EN:** Maximum Score
  - **TR:** En Yüksek Puan
- [ ] `report.gradebook.header.percentage`
  - **EN:** Percentage
  - **TR:** Yüzde

## Bu kuyruğun dışında kalan, ayrıca düzeltilen bir madde

- [ ] `student.batch.error_all_required` — "Lütfen göndermeden önce tüm soruları yanıtlayın."
  ifadesi `siz` kipindeydi; öğrenci arayüzü kuralı gereği "…yanıtla." olarak düzeltildi.
  Bu anahtar yukarıdaki beş özellik dalgasından değil, daha eski bir commit'ten geliyor.

## Kuyruk kapandığında

Tüm kutular işaretlendiğinde bu dosyayı silmeyin; en üste inceleyenin adını ve tarihi ekleyin.
Böylece `tr.json`'ın hangi noktaya kadar insan onayından geçtiği kayıtta kalır.
