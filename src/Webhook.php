<?php

declare(strict_types=1);

namespace VeriApi;

/** Webhook imzası: imzalanan metin `<zaman>.<ham gövde>`, HMAC-SHA256 hex, başlık `sha256=<hex>`. */
final class Webhook
{
    /**
     * X-VeriApi-Imza başlığını doğrular; geçersiz her girdide false döner. Karşılaştırma hash_equals ile sabit zamanlıdır.
     *
     * @param string $govde ayrıştırılmamış ham gövde: file_get_contents('php://input')
     * @param string|null $imza X-VeriApi-Imza başlığı
     * @param string|null $zaman X-VeriApi-Zaman başlığı
     * @param int $tolerans yeniden oynatmaya karşı zaman farkı sınırı, saniye (0 kapatır)
     * @param float|null $simdi Unix saniyesi; varsayılan şu an
     */
    public static function dogrula(
        string $govde,
        ?string $imza,
        ?string $zaman,
        string $gizli,
        int $tolerans = 300,
        ?float $simdi = null,
    ): bool {
        if ($gizli === '' || $imza === null || $zaman === null) {
            return false;
        }
        if (preg_match('/\Asha256=([0-9a-fA-F]{64})\z/', trim($imza), $eslesme) !== 1) {
            return false;
        }
        $zaman = trim($zaman);
        if ($zaman === '') {
            return false;
        }
        $beklenen = hash_hmac('sha256', $zaman . '.' . $govde, $gizli);
        if (!hash_equals($beklenen, strtolower($eslesme[1]))) {
            return false;
        }
        if ($tolerans <= 0) {
            return true;
        }
        $saniye = self::saniye($zaman);
        if ($saniye === null) {
            return false;
        }
        return abs(($simdi ?? microtime(true)) - $saniye) <= $tolerans;
    }

    /** Unix saniyesi ya da saat dilimli ISO 8601; başka biçim null. */
    private static function saniye(string $zaman): ?float
    {
        if (preg_match('/\A\d+(\.\d+)?\z/', $zaman) === 1) {
            return (float) $zaman;
        }
        if (preg_match('/\A\d{4}-\d{2}-\d{2}T\S+(Z|[+-]\d{2}:\d{2})\z/', $zaman) === 1) {
            $saniye = strtotime($zaman);
            return $saniye === false ? null : (float) $saniye;
        }
        return null;
    }
}
