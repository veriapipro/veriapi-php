<?php

declare(strict_types=1);

namespace VeriApi;

final class HamYanit
{
    /** @param array<string, string> $basliklar küçük harfli başlık adları */
    public function __construct(
        public readonly int $durum,
        public readonly string $govde,
        public readonly array $basliklar = [],
    ) {
    }
}
