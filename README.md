# VeriApi.Pro PHP SDK: döviz kuru, namaz vakti, hava durumu ve deprem API'si

PHP ile döviz kurlarını, namaz vakitlerini, hava durumunu, son depremleri ve il/ilçe listesini tek API anahtarıyla çekin.

Official PHP client for [VeriApi.Pro](https://veriapi.pro/?ref=github): Turkish exchange rates, prayer times, weather, earthquakes and cities.

PHP 8.1+, `ext-curl`, başka bağımlılık yok.

```bash
composer require veriapi/sdk
```

## Hızlı başlangıç

```php
<?php
require 'vendor/autoload.php';

use VeriApi\Istemci;
use VeriApi\VeriApiHatasi;

$api = new Istemci('va_ANAHTARINIZ');

$sonuc = $api->doviz('USD,EUR');
print_r($sonuc->veri['kurlar']);
echo $sonuc->kaynak[0]['atif'], "\n"; // veriyi gösterdiğiniz yerde görünür yazın
var_dump($sonuc->guncelleme, $sonuc->bayat);
echo $sonuc->kota->gunlukKalan, ' ', $sonuc->kota->dakikalikKalan, ' ', $sonuc->kota->istekMaliyeti, "\n";
```

## Yöntemler

```php
$api->doviz(['USD', 'EUR'], 'referans');                 // tur: anlik | referans
$api->hava(il: 'duzce', ilce: 'akcakoca');               // ya da konum: 'duzce-akcakoca', ya da enlem:, boylam:
$api->namaz(il: 'ankara', tarih: '2026-11-05', gun: 7);
$api->depremler(limit: 10, minBuyukluk: 4.0, saat: 24, il: 'izmir', yurtici: true);
$api->iller();
$api->ilceler('duzce');                                  // ad, slug ya da plaka

$api->get('/v1/takvim/tatiller', ['yil' => 2026]);       // diğer tüm uçlar
```

## Seçenekler

```php
$api = new Istemci(
    'va_ANAHTARINIZ',
    zamanAsimi: 10.0,   // istek başına saniye
    yenidenDeneme: 2,   // 429'da en çok kaç kez daha; 0 kapatır
    azamiBekleme: 60.0, // bundan uzun Retry-After beklenmez, hata hemen fırlatılır
);
```

PHP isteği beklerken süreç kilitlenir. Web isteği içinde kısa bekleme istiyorsanız `azamiBekleme` değerini düşürün ya da `yenidenDeneme: 0` verin.

## Hatalar

```php
try {
    $api->namaz(il: 'duzce', gun: 99);
} catch (VeriApiHatasi $hata) {
    echo $hata->kod, ' ', $hata->mesaj, ' ', $hata->httpDurumu, "\n";
    print_r($hata->alanlar);    // 422: [['alan' => 'gun', 'mesaj' => '...']]
    var_dump($hata->retryAfter); // 429: saniye
    var_dump($hata->zamanAsimi); // zaman aşımında true; bağlantı hatasında kod ve httpDurumu 0
}
```

## Webhook doğrulama

Ham gövdeyi kullanın; JSON'u ayrıştırıp yeniden üretirseniz imza tutmaz.

```php
<?php
require 'vendor/autoload.php';

use VeriApi\Webhook;

$govde = file_get_contents('php://input');
$gecerli = Webhook::dogrula(
    $govde,
    $_SERVER['HTTP_X_VERIAPI_IMZA'] ?? null,
    $_SERVER['HTTP_X_VERIAPI_ZAMAN'] ?? null,
    'WEBHOOK_GIZLI_ANAHTARINIZ',
);
if (!$gecerli) {
    http_response_code(401);
    exit;
}
$olay = $_SERVER['HTTP_X_VERIAPI_OLAY'] ?? '';
$veri = json_decode($govde, true);
http_response_code(204);
```

`tolerans: 300` saniyeden eski ya da ileri tarihli imza reddedilir; `tolerans: 0` kapatır.

## Test

```bash
php tests/calistir.php
```

## Bağlantılar

- Ücretsiz anahtar ve canlı deneme: <https://veriapi.pro/?ref=github>
- Dokümantasyon: <https://veriapi.pro/dokumantasyon>
- OpenAPI: <https://api.veriapi.pro/v1/openapi.json>

Lisans: MIT
