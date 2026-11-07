<?php

declare(strict_types=1);

namespace VeriApi;

/** Çözülmüş yanıt zarfı. Zarfsız uçlarda (/v1/durum) $veri gövdenin tamamıdır. */
final class Sonuc
{
    /** @param array<int, array<string, mixed>> $kaynak */
    public function __construct(
        public readonly mixed $veri,
        public readonly array $kaynak,
        public readonly ?string $guncelleme,
        public readonly bool $bayat,
        public readonly Kota $kota,
    ) {
    }
}
