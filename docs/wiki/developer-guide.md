# Nasıl yapılır: eduQR geliştirme akışı

Bu rehber, kod tabanına yeni özellik veya düzeltme eklerken korunması gereken mimari sınırları ve doğrulama akışını açıklar.

## Mimari neden böyle?

eduQR'nin ana dağıtım hedefi standart kurumsal ve paylaşımlı hosting ortamlarıdır. Bu nedenle uygulama, ek çalışma zamanı veya derleme hattı gerektirmeyen düz PHP, sunucu tarafında HTML ve Vanilla JavaScript kullanır. Canlılık WebSocket yerine kısa aralıklı HTTP polling ile sağlanır.

```text
İstemci
   │ HTTPS
public/index.php → Bootstrap → Router
                              │
                    HTML/API Controller
                              │
                         Middleware
                              │
                           Service
                              │
                         Repository
                              │
                      MySQL / MariaDB
```

Bu ayrım üç arızayı önler: HTTP ayrıntılarının iş kurallarına yayılması, SQL'in denetleyici veya şablonlara sızması ve aynı bağımlılık ağının farklı yerlerde farklı kurulması. `Container` tek composition root'tur; servisler tipli domain exception üretir; HTML şablonları yalnız kendilerine verilen view model'i render eder.

## Değişikliğe başlamadan önce

1. [AGENTS.md](../../AGENTS.md) ve görevle ilgili spesifikasyonları okuyun.
2. Değişikliğin karşıladığı `FR-xx` veya `NFR-xx` kimliğini belirleyin.
3. Büyük bir işse [docs/superpowers/plans](../superpowers/plans/) altındaki ilgili planı izleyin.
4. API değişiyorsa önce `API_SPEC.md`; şema değişiyorsa yeni migrasyonla birlikte `DATA_MODEL.md` ve `database/schema.sql` dosyalarını güncelleyin.
5. Çalışma ağacındaki size ait olmayan değişiklikleri koruyun.

## Katman sınırları

| Katman | Sorumluluk | Yasak |
| --- | --- | --- |
| `Controllers/Api` | Girdi, HTTP durumu, ortak JSON sınırı | SQL ve iş kuralı |
| `Controllers/Admin`, `Controllers/Public` | Kimlik doğrulama, view model, HTML durumu | Şablon içinde veri toplatma |
| `Services` | İş kuralları ve çok kayıtlı doğrulama | SQL ve HTML |
| `Repositories` | Yalnız hazırlanmış PDO sorguları | İş kararı ve kullanıcı metni |
| `Middleware` | Auth, CSRF, oran sınırı, i18n | Domain davranışı |
| `templates` | Kaçış uygulanmış HTML render | Servis çözme, SQL, auth, header |

Yeni bağımlılıkları `src/Container.php` içinde bağlayın ve mümkünse `src/Contracts/` arayüzü üzerinden tüketin. Kullanıcı girdisini HTML'de `htmlspecialchars(..., ENT_QUOTES, 'UTF-8')`, JSON'da ortak API denetleyicisi üzerinden kodlayın.

## Kullanıcıya görünen metin ekleme

1. Kararlı bir `area.screen.element` anahtarı seçin.
2. Anahtarı `locales/en.json` ve `locales/tr.json` dosyalarına aynı değişiklikte ekleyin.
3. PHP'de `t('key')`, yer tutucularda `t('key', ['name' => $value])` kullanın.
4. Türkçe çeviriyi insan incelemesine gönderin; LLM çevirisini doğrudan yayınlamayın.
5. `php bin/locale-check.php tr --min=95` çalıştırın.

## API davranışı ekleme

1. Yolu, yöntemi, yetkiyi, gövdeyi, yanıtı ve hata kodlarını `API_SPEC.md` içinde yayınlayın.
2. Rotayı `Bootstrap::registerRoutes()` içinde uygun API denetleyicisine bağlayın.
3. Ortak başarı/hata zarfını `ApiController` üzerinden üretin.
4. İş başarısızlıklarını `NotFoundException`, `AuthenticationException`, `ForbiddenException`, `ValidationException`, `ConflictException` veya `UpstreamServiceException` ile bildirin.
5. Mutasyonlarda eğitmen CSRF kontrolünü; herkese açık öğrenci yollarında katılımcı çerezi ve oran sınırını doğrulayın.
6. Birim ve entegrasyon testi ekleyin; test adında gereksinim kimliğine yer verin.

## Şema değişikliği yapma

1. Son numarayı izleyen `database/migrations/NNNN_<eylem>_<konu>.sql` dosyasını ekleyin. Eski migrasyonları değiştirmeyin.
2. `database/schema.sql` dosyasını kümülatif son durumla eşleştirin.
3. `DATA_MODEL.md`, repository ve servisleri güncelleyin.
4. `bash bin/verify-migrations.sh` ile gerçek MySQL 8.4 üzerinde yeniden üretilebilirliği doğrulayın.

## Test ve kalite kapısı

```bash
composer test
composer lint
php bin/locale-check.php tr
composer audit
```

Docker kullanıyorsanız komutları `docker compose exec app` önekiyle çalıştırın. Davranış değişikliklerinde en az bir birim veya entegrasyon testi bulunmalıdır. Hazırlanmış SQL, HTML kaçışı, locale eşliği ve gereksinim izlenebilirliği inceleme kapılarıdır.

Commit biçimi:

```text
<type>(<scope>): <konu> [FR-xx | NFR-xx]
```

Örnek: `fix(answer): reject submissions to closed questions [FR-44, NFR-21]`

## İlgili kaynaklar

- [Teknik referans](technical-reference.md)
- [SYSTEM_ARCHITECTURE.md](../../SYSTEM_ARCHITECTURE.md)
- [SECURITY_PRIVACY.md](../../SECURITY_PRIVACY.md)
- [I18N_SPEC.md](../../I18N_SPEC.md)
- [ADRs](../adr/)
