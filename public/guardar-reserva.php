<?php
require __DIR__ . '/../src/reservas.php';
header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit(json_encode(['ok' => false, 'error' => 'Método no permitido.']));
}

try {
    $resultado = crear_reserva($_POST);
    if (!$resultado['ok']) http_response_code(empty($resultado['ocupado']) ? 422 : 409);
} catch (Throwable $e) {
    error_log((string) $e);
    http_response_code(500);
    $resultado = ['ok' => false, 'error' => 'No pudimos guardar la solicitud. Intenta de nuevo en unos minutos.'];
}
echo json_encode($resultado);
