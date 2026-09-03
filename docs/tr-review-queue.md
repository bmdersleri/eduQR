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
| **Gizlilik bildirimi sayfası (T-1125)** | — | FR-75, NFR-31 | 27 |
| **Toplam** | | | **67** |

> **Öncelik uyarısı.** Aşağıdaki `privacy.page.*` maddeleri arayüz etiketi değil; öğrencilere
> kendi verilerinin nasıl işlendiğini anlatan bir gizlilik metnidir. Yanlış ya da fazla
> iddialı bir çeviri, hatalı bir buton yazısından çok daha ağır sonuç doğurur. Bu bölüm
> kuyruğun geri kalanından **önce** incelenmelidir.

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

## Gizlilik bildirimi sayfası — ÖNCELİKLİ

_T-1125 — FR-75, NFR-31_

> Bu bölümdeki metinler öğrenciye kendi verisinin ne olduğunu anlatıyor ve `/privacy`
> sayfasında yayımlanıyor. İncelerken iki şeye ayrı ayrı bakın: (1) Türkçesi doğru mu,
> (2) **söylenen şey doğru mu** — yani sistemin gerçek davranışını abartmıyor ya da
> eksik anlatmıyor mu. İkincisi daha önemlidir; koruma vaat eden ama karşılığı olmayan
> bir cümle, kötü çeviriden daha zararlıdır.
>
> Kaynak: `SECURITY_PRIVACY.md` §2–§4 ve §15, `DATA_MODEL.md` §2.8–§2.10 ve §7.
> Metinde bilerek yer almayanlar: uydurma saklama süresi, uydurma hukuki dayanak,
> KVKK/GDPR madde numarası ve kuruma ait iletişim adresi.
>
> Üslup: sayfa öğrenciye hitap ettiği için 2. tekil şahıs (`sen`) kullanıldı; mevcut
> `student.*` anahtarlarıyla uyumlu. "instructor" karşılığı **"Eğitmen"**.

- [ ] `privacy.page.title`
  - **EN:** Privacy notice
  - **TR:** Gizlilik Bildirimi
- [ ] `privacy.page.collected.title`
  - **EN:** What is stored
  - **TR:** Neler saklanıyor
- [ ] `privacy.page.collected.nickname`
  - **EN:** The nickname you choose when you join. It has to be unique inside one session so that participants can be told apart.
  - **TR:** Katılırken seçtiğin takma ad. Katılımcıların birbirinden ayırt edilebilmesi için bir oturum içinde benzersiz olmak zorunda.
- [ ] `privacy.page.collected.answers`
  - **EN:** Your answers, and the time each one was submitted.
  - **TR:** Cevapların ve her cevabın gönderildiği zaman.
- [ ] `privacy.page.collected.reactions`
  - **EN:** Your comprehension signal for a question — I got it or I am lost — if you send one.
  - **TR:** Gönderdiysen, bir soruya verdiğin anlama tepkisi: Anladım ya da Kayboldum.
  - **Not:** "Anladım" / "Kayboldum" karşılıkları `student.reaction.got_it` ve `student.reaction.lost` ile birebir aynı tutuldu; o anahtarlar değişirse burası da değişmeli.
- [ ] `privacy.page.collected.device`
  - **EN:** A one-way device code, derived from a random identifier stored in your browser, your browser's user-agent string, and a secret held on the server. It lets a session notice a duplicate join and put you back into your existing participant record if you reload the page. It is only ever compared within a single session, it is never shown to anyone, and it never appears in a report or an export.
  - **TR:** Tek yönlü bir cihaz kodu. Tarayıcında saklanan rastgele bir tanımlayıcıdan, tarayıcının user-agent bilgisinden ve sunucuda tutulan bir gizli anahtardan türetilir. Bir oturumun aynı cihazdan ikinci kez katılımı fark etmesini ve sayfayı yenilediğinde seni mevcut katılımcı kaydına geri döndürmesini sağlar. Yalnızca tek bir oturum içinde karşılaştırılır, kimseye gösterilmez ve hiçbir rapora veya dışa aktarıma girmez.
  - **Not:** "user-agent" teknik terim olarak bırakıldı. Türkçe bir karşılık ("tarayıcı kimlik bilgisi") tercih edilecekse burada karara bağlanmalı. Metin bilerek "seni tanır" demiyor: `SECURITY_PRIVACY.md` §4 bu kodun bir güvenlik denetimi değil, yalnızca sürtünme azaltıcı olduğunu söylüyor.
- [ ] `privacy.page.collected.cookies`
  - **EN:** Three small cookies in your browser: the random device identifier, a marker for the session you joined, and your language choice.
  - **TR:** Tarayıcında üç küçük çerez: rastgele cihaz tanımlayıcısı, katıldığın oturumun işareti ve dil tercihin.
  - **Not:** Sayı (üç) `eduqr_device`, `eduqr_participant`, `eduqr_locale` çerezlerine karşılık geliyor. Öğrenci akışına yeni bir çerez eklenirse bu cümle güncellenmeli.
- [ ] `privacy.page.not_collected.title`
  - **EN:** What is not asked for
  - **TR:** Neler istenmiyor
- [ ] `privacy.page.not_collected.account`
  - **EN:** No account and no password. You are never asked for an email address.
  - **TR:** Hesap ve parola yok. Senden hiçbir zaman e-posta adresi istenmez.
- [ ] `privacy.page.not_collected.identity`
  - **EN:** Your real name, student number, phone number, national identity number, location, and photograph are never asked for and never stored.
  - **TR:** Gerçek adın, öğrenci numaran, telefon numaran, kimlik numaran, konumun ve fotoğrafın hiçbir zaman istenmez ve saklanmaz.
  - **Not:** "national identity number" için "T.C. kimlik numarası" yerine yalın "kimlik numaran" seçildi; eduQR tek bir ülkeye bağlı değil.
- [ ] `privacy.page.not_collected.ip`
  - **EN:** No IP address is stored in the application database. The web server hosting eduQR may hold one briefly in its own access log, which sits outside the application.
  - **TR:** Uygulama veritabanında hiçbir IP adresi saklanmaz. eduQR'ı barındıran web sunucusu kendi erişim kaydında bir IP adresini kısa süreliğine tutabilir; bu kayıt uygulamanın dışındadır.
  - **Not:** `SECURITY_PRIVACY.md` §2.2'deki "30 gün sonra maskelenir" ifadesi bilerek yazılmadı: NFR-33 bir `SHOULD` ve web sunucusu yapılandırmasına bağlı; söz verilemez.
- [ ] `privacy.page.who.title`
  - **EN:** Who can see it
  - **TR:** Kimler görebilir
- [ ] `privacy.page.who.instructor`
  - **EN:** The instructor who runs your course, and any co-instructor they have added to it.
  - **TR:** Dersini yürüten eğitmen ve eğitmenin derse eklediği ortak eğitmenler.
  - **Not:** "Ortak eğitmen" terimi bu kuyruğun üst bölümündeki 2 numaralı kararla uyumlu.
- [ ] `privacy.page.who.reports`
  - **EN:** Reports sit behind an instructor login. There is no public report link.
  - **TR:** Raporlar eğitmen girişinin arkasındadır. Herkese açık bir rapor bağlantısı yoktur.
- [ ] `privacy.page.who.reactions`
  - **EN:** Comprehension reactions reach the instructor only as totals per question: how many chose I got it and how many chose I am lost. Nobody is told which one you sent.
  - **TR:** Anlama tepkileri eğitmene yalnızca soru başına toplam olarak ulaşır: kaç kişi Anladım, kaç kişi Kayboldum demiş. Senin hangisini gönderdiğin kimseye bildirilmez.
- [ ] `privacy.page.who.class`
  - **EN:** If your instructor turns on live results for the class, answers to open-text questions can appear on the classroom screen next to the nickname that sent them. Choice questions are shown as counts only. Pick a nickname you are happy to see on the screen.
  - **TR:** Eğitmenin canlı sonuçları sınıfa açarsa, açık uçlu sorulara verilen cevaplar gönderen takma adla birlikte sınıf ekranında görünebilir. Seçmeli sorular yalnızca sayı olarak gösterilir. Ekranda görmekten rahatsız olmayacağın bir takma ad seç.
  - **Not:** Bu madde öğrenci için en önemli olanı. FR-52/FR-53/FR-54 ve `ReportService::openTextAnswers()` doğruluyor: açık uçlu cevaplar takma adla birlikte dönüyor. Cümle yumuşatılmamalı.
- [ ] `privacy.page.who.never`
  - **EN:** Device codes and IP addresses never appear in any report or export.
  - **TR:** Cihaz kodları ve IP adresleri hiçbir raporda veya dışa aktarımda yer almaz.
- [ ] `privacy.page.retention.title`
  - **EN:** How long it is kept
  - **TR:** Ne kadar süre saklanıyor
- [ ] `privacy.page.retention.active`
  - **EN:** Session data is kept until the session closes, and for 365 days after that.
  - **TR:** Oturum verileri, oturum kapanana kadar ve kapanışından sonra 365 gün boyunca saklanır.
- [ ] `privacy.page.retention.auto`
  - **EN:** After 365 days a closed session is anonymized automatically.
  - **TR:** 365 günün sonunda kapalı bir oturum otomatik olarak anonimleştirilir.
- [ ] `privacy.page.retention.anonymize`
  - **EN:** Anonymizing replaces every nickname in that session with a numbered label such as Participant 3, and clears the device code. The answers and the totals stay, but they are no longer tied to a nickname. Anonymized data is kept indefinitely, because it no longer identifies anyone.
  - **TR:** Anonimleştirme, o oturumdaki her takma adı Participant 3 gibi numaralı bir etiketle değiştirir ve cihaz kodunu siler. Cevaplar ve toplamlar kalır ama artık bir takma adla ilişkili değildir. Anonimleştirilmiş veriler kimseyi tanımlamadığı için süresiz saklanır.
  - **Not:** "Participant 3" çevrilmedi çünkü veritabanına gerçekten bu İngilizce etiket yazılıyor (`SessionRepository::anonymize()`). Etiket ileride yerelleştirilirse bu cümle de değişmeli.
- [ ] `privacy.page.retention.instructor`
  - **EN:** An instructor can anonymize a closed session at any time, and can request that a whole session be deleted. A deletion request has a 7-day grace period, after which the session and everything in it is removed permanently.
  - **TR:** Eğitmen kapalı bir oturumu istediği zaman anonimleştirebilir ve oturumun tümüyle silinmesini isteyebilir. Silme talebinin 7 günlük bir bekleme süresi vardır; sürenin sonunda oturum ve içindeki her şey kalıcı olarak kaldırılır.
- [ ] `privacy.page.options.title`
  - **EN:** Your options
  - **TR:** Senin seçeneklerin
- [ ] `privacy.page.options.nickname`
  - **EN:** You choose your nickname, and it does not have to be your real name.
  - **TR:** Takma adı sen seçersin; gerçek adın olmak zorunda değil.
- [ ] `privacy.page.options.contact`
  - **EN:** You have no account here, so there is nothing to log into in order to change or delete your data. Requests are handled per session by the instructor who owns it, so ask the instructor of your course.
  - **TR:** Burada bir hesabın yok, dolayısıyla verilerini değiştirmek veya silmek için giriş yapabileceğin bir yer de yok. Talepler, oturumun sahibi olan eğitmen tarafından oturum bazında ele alınır; dersinin eğitmenine başvur.
  - **Not:** Yapılandırmada bir iletişim adresi ayarı yok, bu yüzden `SECURITY_PRIVACY.md` §15.3'ün ifadesi ("öğrencinin hesabı olmadığı için talepler oturum sahibi eğitmen tarafından karşılanır") kullanıldı. Kurum bir veri sorumlusu adresi belirlerse bu madde güncellenmeli.
- [ ] `privacy.page.review.title`
  - **EN:** Status of this notice
  - **TR:** Bu bildirimin durumu
- [ ] `privacy.page.review.body`
  - **EN:** This page is a factual description of how the software behaves. It is not an institutional legal notice and it has not been reviewed by the institution yet. Your institution may replace it or add to it.
  - **TR:** Bu sayfa, yazılımın gerçekte nasıl davrandığının olgusal bir açıklamasıdır. Kurumsal bir hukuki bildirim değildir ve henüz kurum tarafından incelenmemiştir. Kurumun bu metni değiştirebilir veya metne ekleme yapabilir.
  - **Not:** Bu uyarı sayfadan çıkarılmamalı; metnin kurum incelemesinden geçmediğini söyleyen tek yer burası.

### Ayrıca dikkat

`privacy.notice.body` bu sayfada yeniden kullanılıyor ama `siz` kipinde yazılmış
("takma adınızı", "girmeniz gerekmez") ve "Öğretim Elemanı" terimini içeriyor. Sayfanın
geri kalanı `sen` kipinde ve "Eğitmen" diyor. Bu anahtar `SECURITY_PRIVACY.md` §15.4'te
zorunlu metin olarak geçtiği için bu değişiklikte **bilerek dokunulmadı**; kip ve terim
birliği ayrı bir kararla sağlanmalı.

## Kuyruk kapandığında

Tüm kutular işaretlendiğinde bu dosyayı silmeyin; en üste inceleyenin adını ve tarihi ekleyin.
Böylece `tr.json`'ın hangi noktaya kadar insan onayından geçtiği kayıtta kalır.
