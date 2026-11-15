<?php
require __DIR__ . '/../../src/admin.php';
require __DIR__ . '/../../src/correos.php';
exigir_admin();
exigir_post_csrf();

$id = filter_var($_POST['id'] ?? null, FILTER_VALIDATE_INT);
$accion = (string) ($_POST['accion'] ?? '');
$mensajes = [
    'aceptar_abono' => 'Reserva #%d aceptada con abono. El saldo se paga al llegar.',
    'aceptar_total' => 'Reserva #%d aceptada con pago total.',
    'rechazar' => 'Reserva #%d rechazada. Las fechas quedaron libres.',
    'pagar_saldo' => 'Reserva #%d: saldo pagado, ya no debe nada.',
];

if ($id && isset($mensajes[$accion]) && cambiar_estado($id, $accion)) {
    if ($accion !== 'pagar_saldo') correo_decision($id); // el saldo se paga en persona: sin correo
    avisar(sprintf($mensajes[$accion], $id));
} else {
    avisar('No se pudo hacer el cambio: la reserva ya no está en ese estado. Revisa la lista actualizada.', 'error');
}

// Vuelve a la misma lista y filtro desde donde se hizo la acción.
$volver = http_build_query(array_filter([
    'estado' => in_array($_POST['volver'] ?? '', ESTADOS, true) ? $_POST['volver'] : null,
    'q' => mb_substr(campo_texto($_POST, 'q'), 0, 100),
    'p' => filter_var($_POST['p'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 2]]) ?: null,
]));
header('Location: ./' . ($volver ? "?$volver" : ''));
