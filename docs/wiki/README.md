# eduQR Wiki

Bu wiki, eduQR'yi kurmak, sınıfta kullanmak ve geliştirmek isteyenler için kısa ve uygulamaya dönük bir giriş noktasıdır. Bağlayıcı ürün ve teknik hükümler kök dizindeki spesifikasyonlardadır; wiki bu kaynakların yerine geçmez, onlara giden okunabilir bir yol sunar.

## Hızlı yönlendirme

| İhtiyaç | Belge | Tür |
| --- | --- | --- |
| Uygulamayı ilk kez çalıştırmak | [Başlangıç öğreticisi](getting-started.md) | Öğretici |
| Ders, oturum ve soru akışını yönetmek | [Kullanım rehberi](user-guide.md) | Nasıl yapılır |
| Kod tabanında güvenli değişiklik yapmak | [Geliştirici rehberi](developer-guide.md) | Nasıl yapılır + açıklama |
| Rotaları, tabloları, durumları ve yapılandırmayı görmek | [Teknik referans](technical-reference.md) | Referans |

## Ürün özeti

eduQR, eğitmenin bir ders oturumu başlatıp QR kod göstermesini; öğrencilerin hesap açmadan takma adla katılıp soruları yanıtlamasını; sonuçların sınıfta canlı izlenmesini ve oturum sonunda raporlanmasını sağlar.

Başlıca özellikler:

- İngilizce ve Türkçe arayüz; tüm kullanıcı metinleri JSON yerel ayar dosyalarından gelir.
- `multiple_choice`, `open_text`, `yes_no`, `likert_5` ve `fill_in_the_blank` soru türleri.
- Eğitmen, yardımcı eğitmen ve yönetici yetkileri; öğrenciler kullanıcı hesabı değil, oturuma bağlı katılımcıdır.
- HTTP polling ile canlı soru, katılımcı, tepki ve sonuç güncellemeleri.
- JSON, CSV, yazdırılabilir HTML, PDF, Moodle GIFT ve not defteri CSV çıktıları.
- Oturum anonimleştirme, yumuşak silme ve otomatik veri saklama kuralları.
- cPanel, Nginx ve Docker tabanlı kurulum seçenekleri.

## Temel kavramlar

| Kavram | eduQR'deki anlamı |
| --- | --- |
| Ders (`course`) | Bir eğitmene ait, oturumları ve soru bankasını gruplayan kayıt. |
| Oturum (`session`) | Tek bir ders anlatımı sırasında yürütülen canlı sınıf etkinliği. PHP oturumu değildir. |
| Katılımcı (`participant`) | Hesabı olmayan, takma adla belirli bir oturuma katılan öğrenci. |
| Soru | Oturum içinde taslak, aktif veya kapalı durumda bulunan yoklama öğesi. |
| Kısa kod | Karışabilecek karakterleri içermeyen altı karakterli genel oturum kodu. |
| Projeksiyon görünümü | QR kodu ve canlı sonuçları sınıf ekranında gösteren herkese açık görünüm. |

Ayrıntılı terimler için [GLOSSARY.md](../../GLOSSARY.md) dosyasına bakın.

## Kaynakların önceliği

Bir wiki cümlesi ile bağlayıcı belge çelişirse şu kaynak geçerlidir:

1. Süreç ve kodlama kuralları: [AGENTS.md](../../AGENTS.md)
2. Ürün davranışı: [PRODUCT_REQUIREMENTS.md](../../PRODUCT_REQUIREMENTS.md)
3. API sözleşmesi: [API_SPEC.md](../../API_SPEC.md)
4. Veri yapısı: [DATA_MODEL.md](../../DATA_MODEL.md)
5. Mimari: [SYSTEM_ARCHITECTURE.md](../../SYSTEM_ARCHITECTURE.md)
6. Güvenlik ve gizlilik: [SECURITY_PRIVACY.md](../../SECURITY_PRIVACY.md)
7. Çok dillilik: [I18N_SPEC.md](../../I18N_SPEC.md)

## Proje durumu

MVP'nin 0–10. aşamaları tamamlanmıştır. Phase 11 kapsamındaki özelliklerin büyük bölümü de uygulanmıştır. Kesin iş durumu [TASKS.md](../../TASKS.md), resmi tamamlanma kapıları ise [ACCEPTANCE_CRITERIA.md](../../ACCEPTANCE_CRITERIA.md) üzerinden izlenir. İşaretlenmemiş kabul maddeleri doğrulanmadan sınıf pilotunun tamamlandığı varsayılmamalıdır.

