<?php
require_once '../config/auth.php';
header('Content-Type: application/json');

$lat = $_GET['latitude'] ?? null;
$lng = $_GET['longitude'] ?? null;
$start = $_GET['start_date'] ?? null;
$end = $_GET['end_date'] ?? null;

if (!$lat || !$lng || !$start || !$end) {
    echo json_encode(['error' => true, 'reason' => 'Faltan parámetros']);
    exit;
}

$url = "https://archive-api.open-meteo.com/v1/archive?latitude=$lat&longitude=$lng&start_date=$start&end_date=$end&daily=precipitation_sum&timezone=UTC";

// Configuración de contexto para ignorar errores de SSL si es necesario (común en XAMPP)
$arrContextOptions = [
    "ssl" => [
        "verify_peer" => false,
        "verify_peer_name" => false,
    ],
    "http" => [
        "timeout" => 15,
        "header" => "User-Agent: AgroPlanner/1.0\r\n"
    ]
];

$response = @file_get_contents($url, false, stream_context_create($arrContextOptions));

if ($response === false) {
    // Intentar con CURL como fallback
    if (function_exists('curl_init')) {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_TIMEOUT, 15);
        $response = curl_exec($ch);
        curl_close($ch);
    }
}

if ($response === false) {
    echo json_encode(['error' => true, 'reason' => 'No se pudo conectar con el servidor meteorológico remoto']);
} else {
    echo $response;
}
