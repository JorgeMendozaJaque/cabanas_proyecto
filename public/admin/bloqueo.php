<?php
require __DIR__ . '/../../src/admin.php';
exigir_admin();
exigir_post_csrf();

if (($_POST['accion'] ?? '') === 'borrar') {
    $id = filter_var($_POST['id'] ?? null, FILTER_VALIDATE_INT);
    $id && borrar_bloqueo($id) ? avisar('Días reabiertos.') : avisar('Ese cierre ya no existe.', 'error');
} else {
    $error = crear_bloqueo($_POST);
    $error ? avisar($error, 'error') : avisar('Días cerrados. Ya no aparecen disponibles en el calendario.');
}
header('Location: ./?vista=dias');
