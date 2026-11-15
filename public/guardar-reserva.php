<?php
require __DIR__ . '/../src/correos.php';
header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit(json_encode(['ok' => false, 'error' => 'Método no permitido.']));
}

try {
    // Revisión de DNS solo aquí, en la entrada pública: crear_reserva() queda sin red (y las pruebas usan @test.invalid).
    foreach (['email' => 'El correo 1', 'email2' => 'El correo 2'] as $campo => $nombre) {
        $v = campo_texto($_POST, $campo);
        if (filter_var($v, FILTER_VALIDATE_EMAIL) && !dominio_recibe_correo($v)) {
            http_response_code(422);
            exit(json_encode(['ok' => false, 'error' => "$nombre parece tener un error: revisa lo que va después de @."]));
        }
    }
    $resultado = crear_reserva($_POST);
    if ($resultado['ok']) {
        // Solo quien hizo la reserva puede ver su página de confirmación (no se expone por id en la URL).
        sesion_reserva();
        $_SESSION['reserva_id'] = $resultado['id'];
        session_write_close();
    } else {
        http_response_code(empty($resultado['ocupado']) ? 422 : 409);
    }
} catch (Throwable $e) {
    error_log((string) $e);
    http_response_code(500);
    $resultado = ['ok' => false, 'error' => 'No pudimos guardar la solicitud. Intenta de nuevo en unos minutos.'];
}
echo json_encode($resultado);

if ($resultado['ok']) {
    // Responde al cliente antes de enviar los correos (solo con PHP-FPM; con `php -S` espera como antes).
    if (function_exists('fastcgi_finish_request')) fastcgi_finish_request();
    correos_reserva_nueva($resultado['id']);
}
