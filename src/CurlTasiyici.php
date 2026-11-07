<?php

declare(strict_types=1);

namespace VeriApi;

final class CurlTasiyici implements Tasiyici
{
    public function gonder(string $url, array $basliklar, float $zamanAsimi): HamYanit
    {
        $gelen = [];
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => $basliklar,
            CURLOPT_FOLLOWLOCATION => false, // X-API-Key başka adrese taşınmasın
            CURLOPT_TIMEOUT_MS => (int) ceil($zamanAsimi * 1000),
            CURLOPT_NOSIGNAL => true, // saniyenin altındaki süreler için
            CURLOPT_HEADERFUNCTION => static function ($ch, string $satir) use (&$gelen): int {
                if (str_starts_with($satir, 'HTTP/')) {
                    $gelen = []; // vekil sunucu ara yanıtları
                } elseif (str_contains($satir, ':')) {
                    [$ad, $deger] = explode(':', $satir, 2);
                    $gelen[strtolower(trim($ad))] = trim($deger);
                }
                return strlen($satir);
            },
        ]);
        $govde = curl_exec($ch);
        if ($govde === false) {
            $hataNo = curl_errno($ch);
            if ($hataNo === CURLE_OPERATION_TIMEDOUT) {
                throw new VeriApiHatasi(0, "Zaman aşımı ({$zamanAsimi} sn)", 0, zamanAsimi: true);
            }
            throw new VeriApiHatasi(0, 'Bağlantı hatası: ' . curl_error($ch), 0);
        }
        return new HamYanit((int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE), (string) $govde, $gelen);
    }
}
