<?php
// Acciones de la pestaña Precios: precio general, crear y borrar precios especiales.
require __DIR__ . '/../../src/admin.php';
exigir_admin();
exigir_post_csrf();

switch ($_POST['accion'] ?? '') {
    case 'base':
        $error = cambiar_precio_base($_POST);
        $error ? avisar($error, 'error') : avisar('Precio general guardado. Se aplica a las reservas nuevas.');
        break;
    case 'borrar':
        $id = filter_var($_POST['id'] ?? null, FILTER_VALIDATE_INT);
        $id && borrar_precio($id) ? avisar('Precio especial borrado. Esos días vuelven al precio general.') : avisar('Ese precio especial ya no existe.', 'error');
        break;
    default:
        $error = crear_precio($_POST);
        $error ? avisar($error, 'error') : avisar('Precio especial guardado. Ya se aplica a las reservas nuevas de esos días.');
}
header('Location: ./?vista=precios');
