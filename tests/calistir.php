<?php

// Bağımlılıksız birim testleri. Çalıştırma: php tests/calistir.php

declare(strict_types=1);

use VeriApi\HamYanit;
use VeriApi\Istemci;
use VeriApi\Tasiyici;
use VeriApi\VeriApiHatasi;
use VeriApi\Webhook;

spl_autoload_register(static function (string $sinif): void {
    if (str_starts_with($sinif, 'VeriApi\\')) {
        require __DIR__ . '/../src/' . substr($sinif, 8) . '.php';
    }
});

final class SahteTasiyici implements Tasiyici
{
    /** @var array<int, array{string, array<int, string>, float}> */
    public array $istekler = [];
    /** @var HamYanit[] */
    public array $yanitlar = [];

    public function gonder(string $url, array $basliklar, float $zamanAsimi): HamYanit
    {
        $this->istekler[] = [$url, $basliklar, $zamanAsimi];
        return array_shift($this->yanitlar) ?? new HamYanit(200, json_encode(ZARF), KOTA);
    }
}

const KOTA = [
    'x-ratelimit-limit-day' => '1000', 'x-ratelimit-remaining-day' => '999', 'x-ratelimit-limit-minute' => '20',
    'x-ratelimit-remaining-minute' => '19', 'x-istek-maliyeti' => '1',
];
const ZARF = [
    'veri' => ['kurlar' => [['kod' => 'USD']]],
    'kaynak' => [['kod' => 'ecb', 'ad' => 'ECB', 'lisans' => 'x', 'atif' => 'y', 'url' => 'z']],
    'guncelleme' => '2026-11-05T10:00:00',
    'bayat' => true,
];

$gecen = 0;
$kalan = 0;

function esit(mixed $beklenen, mixed $gercek, string $ad = ''): void
{
    if ($beklenen !== $gercek) {
        throw new RuntimeException($ad . ' beklenen: ' . var_export($beklenen, true) . ' gerçek: ' . var_export($gercek, true));
    }
}

function test(string $ad, callable $govde): void
{
    global $gecen, $kalan;
    try {
        $govde();
        $gecen++;
        echo "ok   {$ad}\n";
    } catch (Throwable $hata) {
        $kalan++;
        echo "HATA {$ad}: {$hata->getMessage()}\n";
    }
}

function hataBekle(callable $cagri): VeriApiHatasi
{
    try {
        $cagri();
    } catch (VeriApiHatasi $hata) {
        return $hata;
    }
    throw new RuntimeException('hata bekleniyordu');
}

/** @return array{Istemci, SahteTasiyici, ArrayObject} */
function kur(HamYanit ...$yanitlar): array
{
    $tasiyici = new SahteTasiyici();
    $tasiyici->yanitlar = $yanitlar;
    $beklemeler = new ArrayObject();
    $istemci = new Istemci('va_test', 'http://sahte/', tasiyici: $tasiyici, uyu: static function (float $s) use ($beklemeler): void {
        $beklemeler->append($s);
    });
    return [$istemci, $tasiyici, $beklemeler];
}

function sinir(?string $retryAfter): HamYanit
{
    $basliklar = $retryAfter === null ? [] : ['retry-after' => $retryAfter];
    return new HamYanit(429, json_encode(['hata' => ['kod' => 429, 'mesaj' => 'Dakikalık istek sınırı aşıldı']]), $basliklar);
}

test('zarf ve kota', function (): void {
    [$api, $t] = kur();
    $sonuc = $api->get('/v1/doviz', ['kod' => 'USD', 'bos' => null]);
    esit(ZARF['veri'], $sonuc->veri);
    esit('ecb', $sonuc->kaynak[0]['kod']);
    esit(ZARF['guncelleme'], $sonuc->guncelleme);
    esit(true, $sonuc->bayat);
    esit([1000, 999, 20, 19, 1], [
        $sonuc->kota->gunlukLimit, $sonuc->kota->gunlukKalan, $sonuc->kota->dakikalikLimit,
        $sonuc->kota->dakikalikKalan, $sonuc->kota->istekMaliyeti,
    ]);
    esit('http://sahte/v1/doviz?kod=USD', $t->istekler[0][0]);
    esit('X-API-Key: va_test', $t->istekler[0][1][0]);
});

test('kısa yol ve zarfsız gövde', function (): void {
    [$api, $t] = kur(new HamYanit(200, '{"durum":"ok"}'));
    $sonuc = $api->get('durum');
    esit('http://sahte/v1/durum', $t->istekler[0][0]);
    esit(['durum' => 'ok'], $sonuc->veri);
    esit([], $sonuc->kaynak);
    esit(null, $sonuc->kota->gunlukKalan);
});

test('kolaylık yöntemleri', function (): void {
    [$api, $t] = kur();
    $son = static fn (): string => substr(end($t->istekler)[0], strlen('http://sahte'));
    $api->doviz(['USD', 'EUR'], 'referans');
    esit('/v1/doviz?kod=USD%2CEUR&tur=referans', $son());
    $api->hava(il: 'Düzce', ilce: 'Akçakoca');
    esit('/v1/hava?il=D%C3%BCzce&ilce=Ak%C3%A7akoca', $son());
    $api->hava(enlem: 0.0, boylam: 32.5);
    esit('/v1/hava?enlem=0&boylam=32.5', $son());
    $api->namaz(konum: 'duzce-akcakoca', tarih: '2026-11-05', gun: 7);
    esit('/v1/namaz?konum=duzce-akcakoca&tarih=2026-11-05&gun=7', $son());
    $api->depremler(limit: 5, minBuyukluk: 4.5, saat: 24, il: 81, yurtici: true);
    esit('/v1/deprem/son?limit=5&min_buyukluk=4.5&saat=24&il=81&yurtici=true', $son());
    $api->iller();
    esit('/v1/konumlar/iller', $son());
    $api->ilceler('Düzce/x');
    esit('/v1/konumlar/iller/D%C3%BCzce%2Fx/ilceler', $son());
});

test('tipli hata', function (): void {
    [$api] = kur(new HamYanit(404, '{"hata":{"kod":404,"mesaj":"İl bulunamadı: x"}}', KOTA));
    $hata = hataBekle(static fn () => $api->ilceler('x'));
    esit([404, 'İl bulunamadı: x', 404, 'İl bulunamadı: x'], [$hata->kod, $hata->mesaj, $hata->httpDurumu, $hata->getMessage()]);
    esit(999, $hata->kota->gunlukKalan);
});

test('422 alanlar', function (): void {
    $alanlar = [['alan' => 'gun', 'mesaj' => 'çok büyük']];
    [$api] = kur(new HamYanit(422, json_encode(['hata' => ['kod' => 422, 'mesaj' => ['metin' => 'Geçersiz parametre', 'alanlar' => $alanlar]]])));
    $hata = hataBekle(static fn () => $api->namaz(il: 'duzce', gun: 99));
    esit('Geçersiz parametre', $hata->mesaj);
    esit($alanlar, $hata->alanlar);
});

test('JSON olmayan hata', function (): void {
    [$api] = kur(new HamYanit(502, '<html>Bad Gateway</html>'));
    $hata = hataBekle(static fn () => $api->iller());
    esit([502, 502], [$hata->kod, $hata->httpDurumu]);
});

test('geçersiz JSON başarı', function (): void {
    [$api] = kur(new HamYanit(200, '<html>'));
    $hata = hataBekle(static fn () => $api->iller());
    esit([0, 200], [$hata->kod, $hata->httpDurumu]);
});

test('boş anahtar', function (): void {
    try {
        new Istemci('');
    } catch (InvalidArgumentException) {
        return;
    }
    throw new RuntimeException('hata bekleniyordu');
});

test('Retry-After uyulur', function (): void {
    [$api, $t, $b] = kur(sinir('7'), sinir('3'));
    esit(true, $api->iller()->bayat);
    esit([7.0, 3.0], $b->getArrayCopy());
    esit(3, count($t->istekler));
});

test('en çok iki deneme', function (): void {
    [$api, $t] = kur(sinir('1'), sinir('1'), sinir('1'));
    $hata = hataBekle(static fn () => $api->iller());
    esit([429, 1.0], [$hata->httpDurumu, $hata->retryAfter]);
    esit(3, count($t->istekler));
});

test('yeniden deneme kapatılabilir', function (): void {
    $t = new SahteTasiyici();
    $t->yanitlar = [sinir('1')];
    $api = new Istemci('k', 'http://sahte', yenidenDeneme: 0, tasiyici: $t, uyu: static function (): void {
        throw new RuntimeException('beklememeliydi');
    });
    hataBekle(static fn () => $api->iller());
    esit(1, count($t->istekler));
});

test('uzun bekleme denenmez', function (): void {
    [$api, $t, $b] = kur(sinir('40000'));
    $hata = hataBekle(static fn () => $api->iller());
    esit(40000.0, $hata->retryAfter);
    esit([], $b->getArrayCopy());
    esit(1, count($t->istekler));
});

test('başlık yoksa varsayılan bekleme', function (): void {
    [$api, , $b] = kur(sinir(null));
    $api->iller();
    esit([1.0], $b->getArrayCopy());
});

test('başka hata denenmez', function (): void {
    [$api, $t] = kur(new HamYanit(503, '{"hata":{"kod":503,"mesaj":"hazır değil"}}', ['retry-after' => '1']));
    hataBekle(static fn () => $api->iller());
    esit(1, count($t->istekler));
});

// Dört SDK'nın testlerinde aynı örnek
const GIZLI = 'whsec_ornek';
const ZAMAN = '1762300800';
const GOVDE = '{"olay":"deprem.yeni","veri":{"buyukluk":4.2,"il":"Düzce"}}';
const IMZA = 'sha256=c7be673a84807aac83b2d3f90ef21166c532c8006043a2558cefe9c3d839edab';
const SIMDI = 1762300900.0;

test('webhook geçerli', function (): void {
    esit(true, Webhook::dogrula(GOVDE, IMZA, ZAMAN, GIZLI, simdi: SIMDI));
    esit(true, Webhook::dogrula(GOVDE, 'sha256=' . strtoupper(substr(IMZA, 7)), ZAMAN, GIZLI, simdi: SIMDI));
});

test('webhook geçersiz', function (): void {
    esit(false, Webhook::dogrula(GOVDE . ' ', IMZA, ZAMAN, GIZLI, simdi: SIMDI), 'gövde');
    esit(false, Webhook::dogrula(GOVDE, IMZA, ZAMAN, 'baska', simdi: SIMDI), 'gizli');
    esit(false, Webhook::dogrula(GOVDE, IMZA, ZAMAN, '', simdi: SIMDI), 'boş gizli');
    esit(false, Webhook::dogrula(GOVDE, IMZA, '1762300801', GIZLI, simdi: SIMDI), 'zaman');
    esit(false, Webhook::dogrula(GOVDE, substr(IMZA, 7), ZAMAN, GIZLI, simdi: SIMDI), 'önek');
    esit(false, Webhook::dogrula(GOVDE, 'sha256=zz', ZAMAN, GIZLI, simdi: SIMDI), 'kısa');
    esit(false, Webhook::dogrula(GOVDE, 'sha256=ğğ', ZAMAN, GIZLI, simdi: SIMDI), 'hex değil');
    esit(false, Webhook::dogrula(GOVDE, null, ZAMAN, GIZLI, simdi: SIMDI), 'imza yok');
    esit(false, Webhook::dogrula(GOVDE, IMZA, null, GIZLI, simdi: SIMDI), 'zaman yok');
});

test('webhook tolerans', function (): void {
    esit(false, Webhook::dogrula(GOVDE, IMZA, ZAMAN, GIZLI, simdi: SIMDI + 300));
    esit(true, Webhook::dogrula(GOVDE, IMZA, ZAMAN, GIZLI, 0, SIMDI + 300));
    esit(true, Webhook::dogrula(GOVDE, IMZA, ZAMAN, GIZLI, 1000, SIMDI + 300));
});

test('webhook ISO zaman', function (): void {
    $zaman = '2026-11-05T00:00:00+03:00';
    $imza = 'sha256=' . hash_hmac('sha256', "{$zaman}.x", 'g');
    $an = 1793826000.0;
    esit(true, Webhook::dogrula('x', $imza, $zaman, 'g', simdi: $an + 10));
    esit(false, Webhook::dogrula('x', $imza, $zaman, 'g', simdi: $an + 1000));
});

// CurlTasiyici: `php -S` ile gerçek yerel HTTP
$port = 18000 + getmypid() % 2000;
$surec = proc_open(
    [PHP_BINARY, '-S', "127.0.0.1:{$port}", __DIR__ . '/sunucu.php'],
    [0 => ['file', '/dev/null', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']],
    $borular,
    null,
    ['PHP_CLI_SERVER_WORKERS' => '2'],
);
$adres = "http://127.0.0.1:{$port}";
for ($i = 0; $i < 100; $i++) {
    $soket = @fsockopen('127.0.0.1', $port, $hataNo, $hataMetni, 0.1);
    if ($soket !== false) {
        fclose($soket);
        break;
    }
    usleep(50_000);
}

test('curl: başlık, sorgu, kota', function () use ($adres): void {
    $sonuc = (new Istemci('va_test', $adres))->get('ornek', ['il' => 'Düzce', 'yurtici' => true]);
    esit(['anahtar' => 'va_test', 'sorgu' => ['il' => 'Düzce', 'yurtici' => 'true']], $sonuc->veri);
    esit(999, $sonuc->kota->gunlukKalan);
});

test('curl: 429', function () use ($adres): void {
    $hata = hataBekle(static fn () => (new Istemci('k', $adres))->get('sinir'));
    esit([429, 0.0, 0], [$hata->httpDurumu, $hata->retryAfter, $hata->kota->dakikalikKalan]);
});

test('curl: yönlendirme izlenmez', function () use ($adres): void {
    $hata = hataBekle(static fn () => (new Istemci('k', $adres))->get('yonlendir'));
    esit(302, $hata->httpDurumu);
});

test('curl: zaman aşımı', function () use ($adres): void {
    $hata = hataBekle(static fn () => (new Istemci('k', $adres, zamanAsimi: 0.2))->get('yavas'));
    esit([true, 0, 0], [$hata->zamanAsimi, $hata->kod, $hata->httpDurumu]);
});

test('curl: bağlantı hatası', function (): void {
    $hata = hataBekle(static fn () => (new Istemci('k', 'http://127.0.0.1:9'))->iller());
    esit([false, 0], [$hata->zamanAsimi, $hata->httpDurumu]);
});

proc_terminate($surec);
proc_close($surec);

echo "\n{$gecen} geçti, {$kalan} kaldı\n";
exit($kalan === 0 ? 0 : 1);
