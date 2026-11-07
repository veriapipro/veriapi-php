<?php

// `php -S` ile çalışan sahte API; CurlTasiyici'yi gerçek HTTP üzerinden sınar.

$yol = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
header('Content-Type: application/json');
if ($yol === '/v1/yavas') {
    sleep(2);
}
if ($yol === '/v1/yonlendir') {
    header('Location: /v1/baska', true, 302);
    exit;
}
if ($yol === '/v1/sinir') {
    http_response_code(429);
    header('Retry-After: 0');
    header('X-RateLimit-Remaining-Minute: 0');
    echo json_encode(['hata' => ['kod' => 429, 'mesaj' => 'Dakikalık istek sınırı aşıldı']]);
    exit;
}
header('X-RateLimit-Limit-Day: 1000');
header('X-RateLimit-Remaining-Day: 999');
echo json_encode([
    'veri' => ['anahtar' => $_SERVER['HTTP_X_API_KEY'] ?? null, 'sorgu' => $_GET],
    'kaynak' => [],
    'guncelleme' => null,
    'bayat' => false,
]);
