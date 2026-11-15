<?php
require __DIR__ . '/../src/reservas.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$cabana = filter_input(INPUT_GET, 'cabana', FILTER_VALIDATE_INT);
if (!$cabana) {
    http_response_code(400);
    exit(json_encode(['error' => 'Cabaña inválida.']));
}

try {
    echo json_encode(['ocupado' => dias_ocupados($cabana), 'precios' => precios_especiales($cabana)]);
} catch (Throwable $e) {
    error_log((string) $e);
    http_response_code(500);
    echo json_encode(['error' => 'No pudimos cargar la disponibilidad.']);
}
