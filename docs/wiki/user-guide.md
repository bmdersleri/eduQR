# Nasıl yapılır: eduQR ile sınıf oturumu yürütme

Bu rehber, bir eğitmenin sınıf oturumunu hazırlamasından rapor almasına kadar olan günlük akışı açıklar.

## Eğitmen olarak oturum yürütme

### 1. Ders oluşturun

Oturum açtıktan sonra yönetim panelinden dersler sayfasına gidin. Başlık zorunludur; ders kodu, dönem ve açıklama isteğe bağlıdır. Varsayılan dil, bu ders altında açılan oturumların başlangıç dilini belirler.

Ders sahibi dersi arşivleyebilir, geri yükleyebilir ve yardımcı eğitmenleri yönetebilir. Yardımcı eğitmenler oturum, soru ve raporları yönetebilir; sahiplik işlemlerini yapamaz. Yönetici tüm derslere yardımcı eğitmen düzeyinde erişir fakat otomatik olarak ders sahibi olmaz.

### 2. Oturum başlatın

Ders ayrıntısından yeni oturum oluşturun. Sistem altı karakterli kısa kod ve bu koda yönelen QR üretir. Durum akışı şöyledir:

```text
draft → active ⇄ paused → closed
```

- `active`: öğrenciler katılabilir ve aktif soruyu yanıtlayabilir.
- `paused`: yeni yanıtlar reddedilir.
- `closed`: yeni katılım ve yanıt kalıcı olarak kapanır.

Sınıf ekranında `/live/{short_code}` ile QR'ı, `/live/{short_code}/results` ile sonuçları gösterin.

### 3. Soru hazırlayın ve yayınlayın

Soruları önceden veya oturum sırasında oluşturabilirsiniz:

| Tür | Beklenen yanıt |
| --- | --- |
| `multiple_choice` | 2–8 seçenekten biri |
| `open_text` | En çok 2000 karakter serbest metin |
| `yes_no` | Otomatik iki seçenek |
| `likert_5` | Otomatik beş ölçek seçeneği |
| `fill_in_the_blank` | Kırpılmış, büyük/küçük harf duyarsız doğru metin |

Aynı oturumda aynı anda yalnız bir soru aktif olabilir. Yeni bir soruyu etkinleştirmek önceki aktif soruyu kapatır. `exam_mode` açıksa öğrenci sonuçları ve doğruluk bilgisi, diğer görünürlük ayarlarından bağımsız olarak gizlenir.

Soru bankasıyla ders kapsamındaki soruları saklayabilir, ders notlarından soru üretebilir ve seçtiklerinizi yeni oturumlara taslak olarak kopyalayabilirsiniz. JSON içe aktarma, eski `questions[]` biçimini ve `opening`, `middle`, `closing` bölümlü akışı destekler.

### 4. Canlı sınıfı izleyin

Eğitmen sonuç ekranı yaklaşık 2 saniyede, öğrenci ekranı yaklaşık 3 saniyede yenilenir. Değişmeyen polling yanıtları `ETag` üzerinden `304 Not Modified` döner.

- Seçenekli sorularda adet ve yüzdeleri izleyin.
- Açık metin yanıtlarını geliş sırasıyla inceleyin; uygunsuz bir yanıtı gizleyin.
- `got_it` ve `lost` tepkileriyle sınıfın kavrama durumunu izleyin. Toplamlar öğrencilere gösterilmez.
- Oturum ve soru görünürlük anahtarları açıksa sonuçları öğrenci cihazlarında da gösterin.

## Öğrenci olarak katılma

1. QR kodu tarayın veya `/join/{short_code}` bağlantısını açın.
2. Gerçek ad vermek zorunda olmadan 1–24 karakterlik bir takma ad seçin.
3. Aktif soru yoksa bekleme ekranında kalın; soru yayınlandığında ekran otomatik güncellenir.
4. Yanıtı gönderin. Çoklu yanıta izin verilmemişse aynı soruya ikinci yanıt reddedilir.
5. İsterseniz aktif soru için `got_it` veya `lost` tepkisi verin; yeni tepki öncekinin yerini alır.

Aynı tarayıcı ve cihaz tanımlayıcısı korunuyorsa öğrenci aynı oturuma otomatik dönebilir. Bu mekanizma güvenlik garantisi değil, katılım sürtünmesini azaltan bir kolaylıktır.

## Oturumu kapatma ve rapor alma

Oturumu kapattıktan sonra rapor sayfasından özetleri, soru dağılımlarını, ham yanıtları ve zamanları inceleyin. Kullanılabilir çıktılar:

- JSON rapor
- CSV
- Yazdırılabilir HTML ve PDF
- Moodle GIFT soru dosyası
- Quiz not defteri CSV'si

Raporlar kimlik doğrulaması gerektirir; herkese açık rapor URL'si yoktur. `device_hash` ve IP adresleri hiçbir rapora girmez. Kapalı bir oturumu anonimleştirmek takma adları ve cihaz karmalarını geri döndürülemez biçimde kaldırır; silme ise yedi günlük bekleme sonrasında kalıcı olur.

## Dil değiştirme

Dil seçici her kullanıcı arayüzünde bulunur. Öncelik sırası URL öneki, `?lang=`, dil çerezi, `Accept-Language` ve varsayılan uygulama dilidir. Kullanıcının yazdığı ders, soru, takma ad ve yanıt metinleri çevrilmez.

## Sorun giderme

| Durum | Açıklama |
| --- | --- |
| Öğrenci katılamıyor | Oturumun `active` olduğunu ve kısa kodun doğru yazıldığını kontrol edin. |
| Yanıt gönderilemiyor | Oturum duraklatılmış, soru kapanmış veya öğrenci daha önce yanıtlamış olabilir. |
| Öğrenci sonuçları göremiyor | Oturum ve soru görünürlük ayarlarını, ayrıca `exam_mode` değerini kontrol edin. |
| Takma ad reddediliyor | Uzunluk, izin verilen karakterler, benzersizlik ve uygunsuz sözcük filtresi uygulanır. |
| Rapor açılmıyor | Eğitmen oturumunu ve ders üzerindeki sahip/yardımcı eğitmen erişimini kontrol edin. |

Teknik hata kodları ve uç noktalar için [teknik referansa](technical-reference.md) ve bağlayıcı [API_SPEC.md](../../API_SPEC.md) belgesine bakın.
