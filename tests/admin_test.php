<?php
// Prueba de la lógica del panel admin contra la base local. Uso: php tests/admin_test.php
// Solo crea y borra filas de prueba (correos @test.invalid, motivo 'test').
require __DIR__ . '/../src/admin.php';

$fallas = 0;
function chequear(bool $ok, string $que): void
{
    global $fallas;
    echo ($ok ? '  ok    ' : '  FALLA ') . $que . PHP_EOL;
    if (!$ok) $fallas++;
}

function limpiar(): void
{
    db()->exec("DELETE FROM reservas WHERE email LIKE '%@test.invalid'");
    db()->exec("DELETE FROM bloqueos WHERE motivo = 'test'");
    db()->exec("DELETE FROM admins WHERE email LIKE '%@test.invalid'");
}

$d = fn(int $n) => (new DateTimeImmutable(hoy_chile()))->modify("+$n day")->format('Y-m-d');
$reservar = fn(int $desde, int $hasta) => crear_reserva([
    'cabana_id' => 1, 'personas' => 2, 'nombre' => 'Admin Test', 'ciudad' => 'Temuco', 'telefono' => '+56912345678',
    'email' => 'cliente@test.invalid', 'fecha_entrada' => $d($desde), 'fecha_salida' => $d($hasta),
])['id'];
$estado = fn(int $id) => db()->query("SELECT estado FROM reservas WHERE id = $id")->fetchColumn();
$_SESSION = []; // las funciones de sesión se prueban sin navegador

limpiar();
try {
    echo "Cambios de estado\n";
    $a = $reservar(200, 202);
    chequear(!cambiar_estado($a, 'pagada'), 'no se puede marcar pagada una pendiente');
    chequear(!cambiar_estado($a, 'cancelar'), 'no se puede cancelar una pendiente (se rechaza)');
    chequear(cambiar_estado($a, 'aceptar') && $estado($a) === 'aceptada', 'pendiente → aceptada');
    chequear((bool) db()->query("SELECT aceptada_en FROM reservas WHERE id = $a")->fetchColumn(), 'guarda la hora de aceptación (para el plazo de 48 h)');
    chequear(!cambiar_estado($a, 'aceptar'), 'no se acepta dos veces');
    chequear(cambiar_estado($a, 'pagada') && $estado($a) === 'pagada', 'aceptada → pagada');
    chequear(!cambiar_estado($a, 'cancelar'), 'una pagada no se puede cancelar');

    $b = $reservar(205, 207);
    chequear(cambiar_estado($b, 'rechazar') && $estado($b) === 'rechazada', 'pendiente → rechazada');
    chequear(!cambiar_estado($b, 'aceptar'), 'una rechazada no se puede aceptar');
    chequear((bool) $reservar(205, 207), 'las fechas de una rechazada quedan libres');

    $c = $reservar(210, 212);
    cambiar_estado($c, 'aceptar');
    chequear(cambiar_estado($c, 'cancelar') && $estado($c) === 'cancelada', 'aceptada → cancelada');
    chequear((bool) $reservar(210, 212), 'las fechas de una cancelada quedan libres');
    chequear(!cambiar_estado($c, 'borrar'), 'rechaza acciones que no existen');

    echo "Días cerrados\n";
    $cerrar = fn(int $desde, int $hasta, array $extra = []) =>
        crear_bloqueo($extra + ['cabana_id' => 1, 'fecha_inicio' => $d($desde), 'fecha_fin' => $d($hasta), 'motivo' => 'test']);
    chequear($cerrar(201, 201) !== null, 'no cierra días con una reserva pagada');
    chequear($cerrar(198, 205) !== null, 'no cierra un rango que contiene reservas activas');
    chequear($cerrar(202, 204) === null, 'cierra desde el día en que sale el huésped');
    chequear($cerrar(220, 219) !== null, 'rechaza "hasta" anterior a "desde"');
    chequear($cerrar(-5, -2) !== null, 'rechaza fechas pasadas');
    chequear($cerrar(230, 231, ['cabana_id' => 999]) !== null, 'rechaza cabaña inexistente');
    chequear($cerrar(230, 231, ['motivo' => str_repeat('x', 201)]) !== null, 'rechaza motivo de más de 200 caracteres');
    chequear(!crear_reserva(['cabana_id' => 1, 'personas' => 2, 'nombre' => 'Otro', 'ciudad' => 'Temuco', 'telefono' => '+56912345678',
        'email' => 'otro@test.invalid', 'fecha_entrada' => $d(203), 'fecha_salida' => $d(204)])['ok'], 'un cliente no puede reservar días cerrados');
    $id = (int) db()->query("SELECT id FROM bloqueos WHERE motivo = 'test' ORDER BY id DESC LIMIT 1")->fetchColumn();
    chequear(borrar_bloqueo($id), 'reabrir días');
    chequear((bool) $reservar(203, 204), 'tras reabrir, el cliente puede reservar');

    echo "Login\n";
    db()->prepare('INSERT INTO admins (email, password_hash) VALUES (?, ?)')
        ->execute(['admin@test.invalid', password_hash('clave-segura-123', PASSWORD_DEFAULT)]);
    chequear(!iniciar_admin('admin@test.invalid', 'otra-clave'), 'rechaza contraseña incorrecta');
    chequear(!iniciar_admin('nadie@test.invalid', 'clave-segura-123'), 'rechaza correo que no es admin');
    chequear(empty($_SESSION['admin']), 'tras fallar no queda sesión');
    chequear(@iniciar_admin(' ADMIN@test.invalid ', 'clave-segura-123'), 'acepta la clave correcta (sin importar mayúsculas ni espacios en el correo)');
    chequear(($_SESSION['admin']['email'] ?? '') === 'admin@test.invalid', 'la sesión guarda el admin');
    chequear(strlen($_SESSION['csrf'] ?? '') === 64, 'crea un token CSRF nuevo al entrar');
    $hash = db()->query("SELECT password_hash FROM admins WHERE email = 'admin@test.invalid'")->fetchColumn();
    chequear(!str_contains($hash, 'clave-segura-123') && password_get_info($hash)['algo'] !== null, 'la contraseña se guarda cifrada');
} finally {
    limpiar();
}

echo $fallas ? "\n$fallas prueba(s) fallaron\n" : "\nTodo bien\n";
exit($fallas ? 1 : 0);
