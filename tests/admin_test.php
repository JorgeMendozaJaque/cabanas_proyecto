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

// Las pruebas usan su propia cabaña, para no chocar con reservas reales de la base local.
db()->exec("INSERT INTO cabanas (nombre, capacidad, precio_noche) VALUES ('Cabaña de prueba', 6, 80000)");
$C = (int) db()->lastInsertId();

function limpiar(): void
{
    global $C;
    db()->exec("DELETE FROM reservas WHERE cabana_id = $C");
    db()->exec("DELETE FROM bloqueos WHERE cabana_id = $C");
    db()->exec("DELETE FROM reservas WHERE email LIKE '%@test.invalid'");
    db()->exec("DELETE FROM bloqueos WHERE motivo = 'test'");
    db()->exec("DELETE FROM admins WHERE email LIKE '%@test.invalid'");
}

$d = fn(int $n) => (new DateTimeImmutable(hoy_chile()))->modify("+$n day")->format('Y-m-d');
$reservar = fn(int $desde, int $hasta) => crear_reserva([
    'cabana_id' => $C, 'personas' => 2, 'nombre' => 'Admin Test', 'ciudad' => 'Temuco', 'telefono' => '+56912345678',
    'email' => 'cliente@test.invalid', 'fecha_entrada' => $d($desde), 'fecha_salida' => $d($hasta),
])['id'];
$estado = fn(int $id) => db()->query("SELECT estado FROM reservas WHERE id = $id")->fetchColumn();
$_SESSION = []; // las funciones de sesión se prueban sin navegador

limpiar();
try {
    echo "Cambios de estado (solo aceptar o rechazar una pendiente)\n";
    $fila = fn(int $id) => db()->query("SELECT estado, monto, abono, pagado FROM reservas WHERE id = $id")->fetch();
    $a = $reservar(200, 203); // 3 noches = $240.000, abono $120.000
    chequear($fila($a)['abono'] === 120000, 'guarda el abono del 50% al reservar');
    chequear(cambiar_estado($a, 'aceptar_abono') && $fila($a) === ['estado' => 'aceptada', 'monto' => 240000, 'abono' => 120000, 'pagado' => 120000], 'aceptar con abono: pagado = abono (saldo $120.000 al llegar)');
    chequear((bool) db()->query("SELECT aceptada_en FROM reservas WHERE id = $a")->fetchColumn(), 'guarda la hora de aceptación');
    chequear(!cambiar_estado($a, 'aceptar_total'), 'no se acepta dos veces');
    chequear(!cambiar_estado($a, 'rechazar'), 'una aceptada no se puede rechazar');

    $t = $reservar(215, 217);
    chequear(cambiar_estado($t, 'aceptar_total') && $fila($t)['pagado'] === $fila($t)['monto'], 'aceptar con pago total: pagado = total');

    $b = $reservar(205, 207);
    chequear(cambiar_estado($b, 'rechazar') && $fila($b)['estado'] === 'rechazada' && $fila($b)['pagado'] === null, 'pendiente → rechazada, sin pago');
    chequear(!cambiar_estado($b, 'aceptar_abono'), 'una rechazada no se puede aceptar');
    chequear((bool) $reservar(205, 207), 'las fechas de una rechazada quedan libres');
    chequear(!cambiar_estado($b, 'pagada') && !cambiar_estado($b, 'cancelar') && !cambiar_estado($b, 'borrar'), 'rechaza acciones que ya no existen');

    echo "Abono\n";
    chequear(abono(240000) === 120000 && abono(80001) === 40001 && abono(1) === 1, 'el abono redondea hacia arriba al peso');

    echo "Días cerrados\n";
    $cerrar = fn(int $desde, int $hasta, array $extra = []) =>
        crear_bloqueo($extra + ['cabana_id' => $C, 'fecha_inicio' => $d($desde), 'fecha_fin' => $d($hasta), 'motivo' => 'test']);
    chequear($cerrar(201, 201) !== null, 'no cierra días con una reserva aceptada');
    chequear($cerrar(198, 205) !== null, 'no cierra un rango que contiene reservas activas');
    chequear($cerrar(203, 204) === null, 'cierra desde el día en que sale el huésped');
    chequear($cerrar(220, 219) !== null, 'rechaza "hasta" anterior a "desde"');
    chequear($cerrar(-5, -2) !== null, 'rechaza fechas pasadas');
    chequear($cerrar(230, 231, ['cabana_id' => 999]) !== null, 'rechaza cabaña inexistente');
    chequear($cerrar(230, 231, ['motivo' => str_repeat('x', 201)]) !== null, 'rechaza motivo de más de 200 caracteres');
    chequear(!crear_reserva(['cabana_id' => $C, 'personas' => 2, 'nombre' => 'Otro', 'ciudad' => 'Temuco', 'telefono' => '+56912345678',
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
    db()->exec("DELETE FROM cabanas WHERE id = $C");
}

echo $fallas ? "\n$fallas prueba(s) fallaron\n" : "\nTodo bien\n";
exit($fallas ? 1 : 0);
