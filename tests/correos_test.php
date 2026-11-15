<?php
// Prueba el contenido de los correos sin enviarlos. Uso: php tests/correos_test.php
// Con --enviar, además manda un correo real de prueba al admin_email de config.php.
if (PHP_SAPI !== 'cli') exit; // nunca desde el navegador: toca la base real
require __DIR__ . '/../src/correos.php';

$fallas = 0;
function chequear(bool $ok, string $que): void
{
    global $fallas;
    echo ($ok ? '  ok    ' : '  FALLA ') . $que . PHP_EOL;
    if (!$ok) $fallas++;
}

$r = [
    'id' => 7, 'cabana' => 'Cabaña 1', 'nombre' => 'Ana María Pérez', 'ciudad' => 'Temuco',
    'telefono' => '+56912345678', 'telefono2' => null, 'email' => 'ana@test.invalid', 'email2' => 'ana2@test.invalid',
    'fecha_entrada' => '2026-12-30', 'fecha_salida' => '2027-01-02', 'personas' => 2,
    'monto' => 240000, 'abono' => 120000, 'pagado' => 0, 'estado' => 'pendiente', 'creada_en' => '2026-11-14 10:00:00',
];

echo "Solicitud nueva\n";
[$admin, $cliente] = mensajes_reserva_nueva($r);
chequear($admin[0] === [config()['resend']['admin_email']], 'el aviso va al admin');
chequear(str_contains($admin[2], '+56912345678') && str_contains($admin[2], 'ana@test.invalid'), 'el admin ve teléfono y correo del cliente');
chequear(!str_contains($admin[2], '+56912345678 /'), 'sin teléfono 2 no muestra separador');
chequear($cliente[0] === ['ana@test.invalid', 'ana2@test.invalid'], 'al cliente le llega a correo 1 y correo 2');
chequear(str_contains($cliente[2], 'Hola Ana,'), 'saluda por el primer nombre');
chequear(str_contains($cliente[2], '30 de diciembre de 2026') && str_contains($cliente[2], '3 noches'), 'fechas y noches');
chequear(str_contains($cliente[2], '$120.000') && str_contains($cliente[2], 'Saldo al llegar: $120.000'), 'abono y saldo');
chequear(str_contains($cliente[2], '16-11-2026 a las 10:00'), 'plazo de 48 h');
chequear(str_contains($cliente[2], config()['transferencia']['numero_cuenta']), 'datos de transferencia');

echo "Decisión\n";
$acep = mensaje_decision(['estado' => 'aceptada', 'pagado' => 120000] + $r);
chequear(str_contains($acep[1], 'confirmada') && str_contains($acep[2], 'Saldo a pagar al llegar: $120.000'), 'aceptada con abono: muestra el saldo');
$total = mensaje_decision(['estado' => 'aceptada', 'pagado' => 240000] + $r);
chequear(str_contains($total[2], 'pagada por completo'), 'aceptada con pago total: sin saldo');
$rech = mensaje_decision(['estado' => 'rechazada'] + $r);
chequear(str_contains($rech[2], 'fechas se liberaron') && $rech[0] === $acep[0], 'rechazada: avisa que se liberaron, a los mismos correos');

echo "Recordatorio\n";
$rec = mensaje_recordatorio($r);
chequear($rec[0] === $cliente[0] && str_contains($rec[1], '24 horas'), 'va al cliente y dice cuánto le queda');
chequear(str_contains($rec[2], '16-11-2026 a las 10:00') && str_contains($rec[2], '$120.000') && str_contains($rec[2], config()['transferencia']['numero_cuenta']), 'plazo, abono y datos de transferencia');

if (in_array('--enviar', $argv, true)) {
    echo "Envío real\n";
    chequear(enviar_correo([config()['resend']['admin_email']], 'Prueba de correo de Cabañas', "Si lees esto, Resend funciona.\n\n" . $cliente[2]), 'Resend aceptó el correo');
}

echo $fallas ? "\n$fallas prueba(s) fallaron\n" : "\nTodo bien\n";
exit($fallas ? 1 : 0);
