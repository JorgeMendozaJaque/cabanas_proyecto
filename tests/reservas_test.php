<?php
// Prueba de crear_reserva contra la base local. Uso: php tests/reservas_test.php
// Solo crea y borra filas marcadas como de prueba (correos @test.invalid, motivo 'test').
require __DIR__ . '/../src/reservas.php';

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
}

$d = fn(int $n) => (new DateTimeImmutable(hoy_chile()))->modify("+$n day")->format('Y-m-d');
$base = [
    'cabana_id' => $C, 'personas' => 4, 'nombre' => 'Ana Test', 'ciudad' => 'Temuco',
    'telefono' => '+56912345678', 'telefono2' => '', 'email' => 'ana@test.invalid', 'email2' => '',
];
$pedir = fn(int $desde, int $hasta, array $extra = []) =>
    crear_reserva(['fecha_entrada' => $d($desde), 'fecha_salida' => $d($hasta)] + $extra + $base);

limpiar();
try {
    echo "Validaciones\n";
    $r = $pedir(100, 103);
    chequear($r['ok'] && $r['noches'] === 3 && $r['monto'] === 240000 && $r['abono'] === 120000, 'reserva válida: 3 noches = $240.000, abono $120.000');
    $fila = db()->query("SELECT estado, telefono2, email2 FROM reservas WHERE id = {$r['id']}")->fetch();
    chequear($fila === ['estado' => 'pendiente', 'telefono2' => null, 'email2' => null], 'queda pendiente y los opcionales vacíos como NULL');
    chequear(!$pedir(110, 111, ['personas' => 7])['ok'], 'rechaza más personas que la capacidad');
    chequear(!$pedir(110, 110)['ok'], 'rechaza salida igual a la llegada');
    chequear(!$pedir(-1, 2)['ok'], 'rechaza llegada en el pasado');
    chequear(!$pedir(110, 141)['ok'], 'rechaza más de 30 noches');
    chequear(!$pedir(360, 366)['ok'], 'rechaza más de un año hacia adelante');
    chequear(!$pedir(110, 111, ['email' => 'no-es-correo'])['ok'], 'rechaza correo 1 inválido');
    chequear(!$pedir(110, 111, ['email2' => 'malo@'])['ok'], 'rechaza correo 2 inválido');
    chequear(!$pedir(110, 111, ['telefono' => 'abc'])['ok'], 'rechaza teléfono inválido');
    chequear(!$pedir(110, 111, ['ciudad' => ''])['ok'], 'rechaza ciudad vacía');

    echo "Tipo de texto por campo\n";
    chequear(!$pedir(110, 111, ['nombre' => 'Ana 2'])['ok'], 'rechaza números en el nombre');
    chequear(!$pedir(110, 111, ['ciudad' => 'Temuco123'])['ok'], 'rechaza números en la ciudad');
    chequear(!$pedir(110, 111, ['ciudad' => 'Gotham'])['ok'], 'rechaza ciudad que no está en la lista');
    chequear($pedir(110, 111, ['ciudad' => 'Argentina'])['ok'], 'acepta un país (botón "Soy extranjero")');
    db()->exec("DELETE FROM reservas WHERE ciudad = 'Argentina' AND email LIKE '%@test.invalid'"); // libera +110 para las pruebas siguientes
    chequear(!in_array('Chile', PAISES, true), 'Chile no está en la lista de países');
    chequear(campo_texto(['nombre' => ['x']], 'nombre') === '' && campo_texto(['n' => ' Ana '], 'n') === 'Ana', 'un campo enviado como lista vale vacío, no "Array"');
    chequear(dominio_recibe_correo('ana@gmail.com') && dominio_recibe_correo('ana@Hotmail.com'), 'acepta dominios de correo reales');
    chequear(!dominio_recibe_correo('ana@gmial.con') && !dominio_recibe_correo('ana@asdasdqwe123.cl'), 'rechaza dominios que no reciben correo');
    chequear(region_de('Temuco') === 'La Araucanía' && region_de('Argentina') === 'Extranjero' && region_de('X') === '', 'región de cada ciudad');
    $todas = array_merge(PAISES, ...array_values(CIUDADES));
    chequear(count($todas) === count(array_unique($todas)), 'la lista no repite ciudades entre regiones');
    chequear(!$pedir(110, 111, ['nombre' => 'Ana <b>'])['ok'], 'rechaza símbolos en el nombre');
    chequear(!$pedir(110, 111, ['telefono' => '+56 9 1234 5678'])['ok'], 'rechaza espacios en el teléfono');
    chequear(!$pedir(110, 111, ['telefono' => '5691234567x'])['ok'], 'rechaza letras en el teléfono');
    chequear(!$pedir(110, 111, ['telefono' => '56+912345678'])['ok'], 'rechaza + que no está al inicio');
    chequear(!$pedir(110, 111, ['telefono' => '1234567'])['ok'], 'rechaza teléfono de 7 dígitos');
    chequear(!$pedir(110, 111, ['telefono' => '+1234567890123456'])['ok'], 'rechaza teléfono de 16 dígitos');
    chequear(!$pedir(110, 111, ['telefono2' => '12ab5678'])['ok'], 'rechaza letras en el teléfono 2');
    chequear(!$pedir(110, 111, ['email' => 'ana @test.invalid'])['ok'], 'rechaza espacios en el correo');
    chequear(!$pedir(110, 111, ['personas' => '2.5'])['ok'], 'rechaza personas con decimales');
    $r = $pedir(110, 111, ['nombre' => "María José O'Higgins-Ñuñez", 'ciudad' => 'Puerto Montt', 'telefono' => '123456789012345', 'telefono2' => '22345678']);
    chequear($r['ok'], 'acepta tildes, ñ, apóstrofo y guion; teléfono de 15 dígitos y de 8');
    chequear(!$pedir(110, 111, ['cabana_id' => 999])['ok'], 'rechaza cabaña inexistente');

    echo "Choque de fechas (ya existe una reserva del día +100 al +103)\n";
    $r = $pedir(102, 105);
    chequear(!$r['ok'] && !empty($r['ocupado']), 'rechaza si se cruza con una pendiente');
    chequear(!$pedir(103, 104)['ok'], 'limpieza: rechaza llegar el día en que el otro sale');
    chequear(!$pedir(104, 106)['ok'], 'limpieza: rechaza llegar el día de limpieza');
    chequear($pedir(105, 106)['ok'], 'limpieza: acepta llegar 2 días después de la salida');
    chequear(!$pedir(98, 100)['ok'], 'limpieza: rechaza salir el día en que el otro llega');
    chequear(!$pedir(97, 99)['ok'], 'limpieza: rechaza salir el día antes de que el otro llegue');
    chequear($pedir(96, 98)['ok'], 'limpieza: acepta salir 2 días antes de que el otro llegue');
    chequear(in_array(['desde' => $d(98), 'hasta' => $d(105)], dias_ocupados($C), true), 'la disponibilidad muestra la reserva +100→+103 con limpieza: noches [+98, +105)');
    db()->exec("UPDATE reservas SET estado = 'rechazada' WHERE email LIKE '%@test.invalid' AND fecha_entrada = '{$d(100)}'");
    chequear($pedir(100, 103)['ok'], 'una rechazada libera las fechas');

    echo "Días cerrados por el admin (bloqueo del +120 al +122, inclusive)\n";
    db()->exec("INSERT INTO bloqueos (cabana_id, fecha_inicio, fecha_fin, motivo) VALUES ($C, '{$d(120)}', '{$d(122)}', 'test')");
    chequear(!$pedir(122, 123)['ok'], 'rechaza dormir la noche del último día cerrado');
    chequear($pedir(123, 124)['ok'], 'acepta llegar el día después del cierre');
    chequear($pedir(119, 120)['ok'], 'acepta salir el primer día cerrado');
    $rangos = dias_ocupados($C);
    chequear(in_array(['desde' => $d(120), 'hasta' => $d(123)], $rangos, true), 'la disponibilidad muestra el cierre como noches [+120, +123)');
    chequear(array_keys($rangos[0]) === ['desde', 'hasta'], 'la disponibilidad no expone datos de clientes');

    echo "Dos solicitudes al mismo tiempo\n";
    $c = (require __DIR__ . '/../config.php')['db'];
    $otra = new PDO("mysql:host={$c['host']};dbname={$c['nombre']};charset=utf8mb4", $c['usuario'], $c['clave']);
    $otra->beginTransaction();
    $otra->query("SELECT id FROM cabanas WHERE id = $C FOR UPDATE"); // la "primera" solicitud tiene la cabaña
    $proc = proc_open(
        [PHP_BINARY, '-r', 'require "src/reservas.php"; echo json_encode(crear_reserva(json_decode($argv[1], true)));', '--',
         json_encode(['fecha_entrada' => $d(150), 'fecha_salida' => $d(152)] + $base)],
        [1 => ['pipe', 'w']], $pipes, dirname(__DIR__)
    );
    sleep(1);
    chequear(proc_get_status($proc)['running'], 'la segunda solicitud espera a la primera');
    $otra->exec("INSERT INTO reservas (cabana_id, nombre, ciudad, telefono, email, fecha_entrada, fecha_salida, personas, monto, abono)
                 VALUES ($C, 'Primera', 'Temuco', '+56911111111', 'primera@test.invalid', '{$d(150)}', '{$d(152)}', 2, 160000, 80000)");
    $otra->commit();
    $segunda = json_decode(stream_get_contents($pipes[1]), true);
    proc_close($proc);
    chequear(($segunda['ok'] ?? null) === false && !empty($segunda['ocupado']), 'la segunda se rechaza: sin overbooking');
} finally {
    limpiar();
    db()->exec("DELETE FROM cabanas WHERE id = $C");
}

echo $fallas ? "\n$fallas prueba(s) fallaron\n" : "\nTodo bien\n";
exit($fallas ? 1 : 0);
