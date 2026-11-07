<?php

declare(strict_types=1);

namespace VeriApi;

/** X-RateLimit-* ve X-Istek-Maliyeti başlıkları; başlık yoksa alan null. */
final class Kota
{
    public function __construct(
        public readonly ?int $gunlukLimit = null,
        public readonly ?int $gunlukKalan = null,
        public readonly ?int $dakikalikLimit = null,
        public readonly ?int $dakikalikKalan = null,
        public readonly ?int $istekMaliyeti = null,
    ) {
    }

    /** @param array<string, string> $basliklar küçük harfli başlık adları */
    public static function basliklardan(array $basliklar): self
    {
        $tam = static function (string $ad) use ($basliklar): ?int {
            $deger = $basliklar[$ad] ?? null;
            return $deger !== null && preg_match('/\A-?\d+\z/', trim($deger)) === 1 ? (int) $deger : null;
        };
        return new self(
            $tam('x-ratelimit-limit-day'),
            $tam('x-ratelimit-remaining-day'),
            $tam('x-ratelimit-limit-minute'),
            $tam('x-ratelimit-remaining-minute'),
            $tam('x-istek-maliyeti'),
        );
    }
}
