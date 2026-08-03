<?php
/**
 * test_fuentes.php — DIAGNÓSTICO TEMPORAL
 * ─────────────────────────────────────────────────────────────────────────────
 * Prueba, desde ESTE servidor, a qué fuentes de precios se puede llegar.
 * El monitor del MAGYP nos bloquea con un 403 de su WAF (BunkerWeb) y hay que
 * saber cuál de las alternativas responde antes de reescribir el sincronizador.
 *
 * No toca la base de datos. Borrar este archivo cuando el tema esté resuelto.
 *
 * Uso: abrir https://agroplanner.online/cron/test_fuentes.php en el navegador.
 * ─────────────────────────────────────────────────────────────────────────────
 */

header('Content-Type: text/plain; charset=UTF-8');
error_reporting(E_ALL);
ini_set('display_errors', '1');

$FUENTES = [
    'MAGYP monitor (el que falla)' => 'https://monitorsiogranos.magyp.gob.ar/v5_ajax/funcionUltimaFechaParaMostrar_min.php',
    'BCR Camara Arbitral pizarra'  => 'https://www.cac.bcr.com.ar/es/precios-de-pizarra',
    'BCR cotizaciones locales'     => 'https://www.bcr.com.ar/es/mercados/mercado-de-granos/cotizaciones/cotizaciones-locales-0',
    'Agrofy precios pizarra'       => 'https://news.agrofy.com.ar/granos/precios-pizarra',
    'granos.ar'                    => 'https://granos.ar/',
];

echo "Prueba de alcance a fuentes de precios\n";
echo "Fecha: " . date('Y-m-d H:i:s') . "\n";
echo "IP saliente de este servidor: " . ($_SERVER['SERVER_ADDR'] ?? '?') . "\n";
echo str_repeat('=', 70) . "\n\n";

foreach ($FUENTES as $nombre => $url) {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_TIMEOUT        => 6,
        CURLOPT_CONNECTTIMEOUT => 4,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_ENCODING       => '',
        CURLOPT_USERAGENT      => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 '
                                . '(KHTML, like Gecko) Chrome/126.0.0.0 Safari/537.36',
        CURLOPT_HTTPHEADER     => [
            'Accept: text/html,application/xhtml+xml,application/json,*/*;q=0.8',
            'Accept-Language: es-AR,es;q=0.9,en;q=0.8',
        ],
    ]);
    $body  = curl_exec($ch);
    $code  = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err   = curl_error($ch);
    $seg   = curl_getinfo($ch, CURLINFO_TOTAL_TIME);
    curl_close($ch);

    printf("%-32s HTTP %-4s %5.2fs  %s\n", $nombre, $code ?: '---', $seg,
        $err !== '' ? "ERROR: $err" : '');

    if ($body !== false && $body !== '') {
        $bloqueado = stripos($body, '403') !== false && stripos($body, 'Forbidden') !== false;
        $limpio    = trim(preg_replace('/\s+/', ' ', strip_tags(substr($body, 0, 4000))));

        echo '   ' . ($code === 200 && !$bloqueado ? '>> LLEGA' : '>> BLOQUEADO / ERROR') . "\n";
        echo '   Primeros caracteres: ' . mb_substr($limpio, 0, 180) . "\n";

        // Pistas de que la pagina realmente trae precios
        foreach (['Soja', 'Maíz', 'Maiz', 'Trigo', 'Girasol', 'Sorgo'] as $grano) {
            if (stripos($body, $grano) !== false) { echo "   Menciona: $grano\n"; break; }
        }
    }
    echo "\n";
}

echo str_repeat('=', 70) . "\n";
echo "Listo. Pasale esta salida a Claude para elegir la fuente de reemplazo.\n";
