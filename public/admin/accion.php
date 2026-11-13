<?php
require __DIR__ . '/../../src/admin.php';
exigir_admin();
exigir_post_csrf();

$id = filter_var($_POST['id'] ?? null, FILTER_VALIDATE_INT);
$accion = (string) ($_POST['accion'] ?? '');
$mensajes = [
    'aceptar_abono' => 'Reserva #%d aceptada con abono. El saldo se paga al llegar.',
    'aceptar_total' => 'Reserva #%d aceptada con pago total.',
    'rechazar' => 'Reserva #%d rechazada. Las fechas quedaron libres.',
];

if ($id && isset($mensajes[$accion]) && cambiar_estado($id, $accion)) {
    avisar(sprintf($mensajes[$accion], $id));
} else {
    avisar('No se pudo hacer el cambio: la reserva ya no está en ese estado. Revisa la lista actualizada.', 'error');
}

$volver = in_array($_POST['volver'] ?? '', ESTADOS, true) ? '?estado=' . $_POST['volver'] : '';
header('Location: ./' . $volver);
