<?php

declare(strict_types=1);

namespace VeriApi;

/** VeriApi.Pro istemcisi. */
final class Istemci
{
    public const SURUM = '0.1.0';
    private const VARSAYILAN_ADRES = 'https://api.veriapi.pro';
    private const VARSAYILAN_BEKLEME = 1.0; // 429'da Retry-After yoksa (saniye)

    private string $temelAdres;
    private Tasiyici $tasiyici;
    /** @var callable(float): void */
    private $uyu;

    /**
     * @param float $zamanAsimi istek başına saniye
     * @param int $yenidenDeneme 429'da en çok kaç kez daha denensin (0 kapatır)
     * @param float $azamiBekleme bundan uzun Retry-After beklenmez, hata hemen fırlatılır (günlük kota aşımı saatler sürebilir)
     * @param callable(float): void|null $uyu beklemeyi değiştirmek için (testler)
     */
    public function __construct(
        private string $anahtar,
        string $temelAdres = self::VARSAYILAN_ADRES,
        private float $zamanAsimi = 10.0,
        private int $yenidenDeneme = 2,
        private float $azamiBekleme = 60.0,
        ?Tasiyici $tasiyici = null,
        ?callable $uyu = null,
    ) {
        if ($anahtar === '' || preg_match('/[\r\n]/', $anahtar) === 1) {
            throw new \InvalidArgumentException('Geçerli bir API anahtarı gerekli');
        }
        $this->temelAdres = rtrim($temelAdres, '/');
        $this->tasiyici = $tasiyici ?? new CurlTasiyici();
        $this->uyu = $uyu ?? static function (float $saniye): void {
            usleep((int) ($saniye * 1_000_000));
        };
    }

    /**
     * @param string $yol "/v1/doviz" ya da "doviz"
     * @param array<string, mixed> $parametreler null değerler gönderilmez
     * @throws VeriApiHatasi
     */
    public function get(string $yol, array $parametreler = []): Sonuc
    {
        $url = $this->url($yol, $parametreler);
        $basliklar = [
            'X-API-Key: ' . $this->anahtar,
            'Accept: application/json',
            'User-Agent: veriapi-php/' . self::SURUM,
        ];
        $deneme = 0;
        while (true) {
            $yanit = $this->tasiyici->gonder($url, $basliklar, $this->zamanAsimi);
            if ($yanit->durum !== 429 || $deneme >= $this->yenidenDeneme) {
                break;
            }
            $bekle = self::retryAfter($yanit) ?? self::VARSAYILAN_BEKLEME;
            if ($bekle > $this->azamiBekleme) {
                break;
            }
            ($this->uyu)($bekle);
            $deneme++;
        }
        if ($yanit->durum < 200 || $yanit->durum >= 300) {
            throw self::hata($yanit);
        }
        return self::coz($yanit);
    }

    // Kolaylık yöntemleri (parametreler api/app/routers/data.py ile aynı)

    /**
     * @param string|string[]|null $kod "USD,EUR" ya da ['USD', 'EUR']
     * @param string|null $tur anlik | referans
     */
    public function doviz(string|array|null $kod = null, ?string $tur = null): Sonuc
    {
        return $this->get('/v1/doviz', ['kod' => $kod, 'tur' => $tur]);
    }

    public function hava(
        ?string $konum = null,
        string|int|null $il = null,
        ?string $ilce = null,
        ?float $enlem = null,
        ?float $boylam = null,
    ): Sonuc {
        return $this->get('/v1/hava', ['konum' => $konum, 'il' => $il, 'ilce' => $ilce, 'enlem' => $enlem, 'boylam' => $boylam]);
    }

    /**
     * @param string|null $tarih YYYY-AA-GG (varsayılan bugün)
     * @param int|null $gun 1-31
     */
    public function namaz(
        ?string $konum = null,
        string|int|null $il = null,
        ?string $ilce = null,
        ?float $enlem = null,
        ?float $boylam = null,
        ?string $tarih = null,
        ?int $gun = null,
    ): Sonuc {
        return $this->get('/v1/namaz', [
            'konum' => $konum, 'il' => $il, 'ilce' => $ilce, 'enlem' => $enlem, 'boylam' => $boylam,
            'tarih' => $tarih, 'gun' => $gun,
        ]);
    }

    public function depremler(
        ?int $limit = null,
        ?float $minBuyukluk = null,
        ?int $saat = null,
        string|int|null $il = null,
        ?bool $yurtici = null,
    ): Sonuc {
        return $this->get('/v1/deprem/son', [
            'limit' => $limit, 'min_buyukluk' => $minBuyukluk, 'saat' => $saat, 'il' => $il, 'yurtici' => $yurtici,
        ]);
    }

    public function iller(): Sonuc
    {
        return $this->get('/v1/konumlar/iller');
    }

    /** @param string|int $il ad, slug ya da plaka */
    public function ilceler(string|int $il): Sonuc
    {
        return $this->get('/v1/konumlar/iller/' . rawurlencode((string) $il) . '/ilceler');
    }

    /** @param array<string, mixed> $parametreler */
    private function url(string $yol, array $parametreler): string
    {
        if (!str_starts_with($yol, '/v1/')) {
            $yol = '/v1/' . ltrim($yol, '/');
        }
        $temiz = [];
        foreach ($parametreler as $ad => $deger) {
            if ($deger === null) {
                continue;
            }
            $temiz[$ad] = match (true) {
                is_bool($deger) => $deger ? 'true' : 'false',
                is_array($deger) => implode(',', $deger),
                default => (string) $deger,
            };
        }
        if ($temiz === []) {
            return $this->temelAdres . $yol;
        }
        $ayrac = str_contains($yol, '?') ? '&' : '?';
        return $this->temelAdres . $yol . $ayrac . http_build_query($temiz, '', '&', PHP_QUERY_RFC3986);
    }

    private static function retryAfter(HamYanit $yanit): ?float
    {
        $deger = $yanit->basliklar['retry-after'] ?? null;
        return $deger !== null && is_numeric($deger) ? max(0.0, (float) $deger) : null;
    }

    private static function hata(HamYanit $yanit): VeriApiHatasi
    {
        $kod = $yanit->durum;
        $mesaj = "Beklenmeyen yanıt (HTTP {$yanit->durum})";
        $alanlar = [];
        $belge = json_decode($yanit->govde, true); // JSON olmayan yanıtta (vekil sunucu 502 sayfası vb.) null
        $hata = is_array($belge) ? ($belge['hata'] ?? null) : null;
        if (is_array($hata)) {
            if (is_int($hata['kod'] ?? null)) {
                $kod = $hata['kod'];
            }
            $ham = $hata['mesaj'] ?? null;
            if (is_string($ham) && $ham !== '') {
                $mesaj = $ham;
            } elseif (is_array($ham)) { // 422: {"metin": "...", "alanlar": [...]}
                $mesaj = (string) ($ham['metin'] ?? $mesaj);
                $alanlar = is_array($ham['alanlar'] ?? null) ? $ham['alanlar'] : [];
            }
        }
        return new VeriApiHatasi($kod, $mesaj, $yanit->durum, $alanlar, self::retryAfter($yanit), Kota::basliklardan($yanit->basliklar));
    }

    private static function coz(HamYanit $yanit): Sonuc
    {
        $kota = Kota::basliklardan($yanit->basliklar);
        $belge = json_decode($yanit->govde, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new VeriApiHatasi(0, 'Yanıt JSON değil', $yanit->durum, kota: $kota);
        }
        if (!is_array($belge) || !array_key_exists('veri', $belge)) {
            return new Sonuc($belge, [], null, false, $kota);
        }
        return new Sonuc($belge['veri'], $belge['kaynak'] ?? [], $belge['guncelleme'] ?? null, (bool) ($belge['bayat'] ?? false), $kota);
    }
}
