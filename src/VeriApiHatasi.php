<?php

declare(strict_types=1);

namespace VeriApi;

/** API ya da bağlantı hatası. Bağlantı ve zaman aşımında $kod ve $httpDurumu 0'dır. */
final class VeriApiHatasi extends \RuntimeException
{
    public readonly Kota $kota;

    /**
     * @param array<int, array<string, mixed>> $alanlar 422'de hatalı parametreler
     * @param float|null $retryAfter 429'da saniye
     */
    public function __construct(
        public readonly int $kod,
        public readonly string $mesaj,
        public readonly int $httpDurumu,
        public readonly array $alanlar = [],
        public readonly ?float $retryAfter = null,
        ?Kota $kota = null,
        public readonly bool $zamanAsimi = false,
    ) {
        parent::__construct($mesaj, $kod);
        $this->kota = $kota ?? new Kota();
    }
}
