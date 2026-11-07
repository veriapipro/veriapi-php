<?php

declare(strict_types=1);

namespace VeriApi;

/** HTTP katmanı; varsayılanı CurlTasiyici, testlerde sahtesi verilir. */
interface Tasiyici
{
    /**
     * @param array<int, string> $basliklar "Ad: değer" satırları
     * @throws VeriApiHatasi bağlantı ya da zaman aşımı hatasında
     */
    public function gonder(string $url, array $basliklar, float $zamanAsimi): HamYanit;
}
