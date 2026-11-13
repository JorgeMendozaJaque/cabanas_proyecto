<?php
require __DIR__ . '/../src/reservas.php';

session_name('cabanas_reserva');
session_set_cookie_params(['path' => '/', 'httponly' => true, 'samesite' => 'Lax', 'secure' => !empty($_SERVER['HTTPS'])]);
session_start();

$id = (int) ($_SESSION['reserva_id'] ?? 0);
$st = db()->prepare('SELECT r.id, r.nombre, r.fecha_entrada, r.fecha_salida, r.personas, r.monto, r.abono, r.creada_en, c.nombre AS cabana
                     FROM reservas r JOIN cabanas c ON c.id = r.cabana_id WHERE r.id = ?');
$st->execute([$id]);
$r = $st->fetch();
if (!$r) {
    header('Location: ./');
    exit;
}

$t = config()['transferencia'];
$pct = config()['abono_porcentaje'];
$plazo = date('d-m-Y \a \l\a\s H:i', strtotime($r['creada_en'] . ' +48 hours'));
$noches = dias_entre($r['fecha_entrada'], $r['fecha_salida']);
$e = fn($v) => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
$pesos = fn($n) => '$' . number_format((int) $n, 0, ',', '.');
$meses = ['enero', 'febrero', 'marzo', 'abril', 'mayo', 'junio', 'julio', 'agosto', 'septiembre', 'octubre', 'noviembre', 'diciembre'];
$fecha = fn($f) => (int) substr($f, 8, 2) . ' de ' . $meses[(int) substr($f, 5, 2) - 1] . ' de ' . substr($f, 0, 4);
?>
<!doctype html>
<html lang="es">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="robots" content="noindex">
  <title>Solicitud recibida</title>
  <link rel="stylesheet" href="estilos.css">
</head>
<body>
<main class="confirmacion">
  <div class="confirmacion-cabecera">
    <span class="check" aria-hidden="true">✓</span>
    <h1>¡Solicitud recibida, <?= $e(strtok($r['nombre'], ' ')) ?>!</h1>
    <p>Tus fechas quedaron apartadas por 48 horas. Para confirmar tu reserva, transfiere el abono antes del <strong><?= $e($plazo) ?></strong>.</p>
  </div>

  <section class="tarjeta">
    <h2>Tu reserva <span class="reserva-id">N° <?= (int) $r['id'] ?></span></h2>
    <dl class="datos">
      <dt>Cabaña</dt><dd><?= $e($r['cabana']) ?></dd>
      <dt>Llegada</dt><dd><?= $e($fecha($r['fecha_entrada'])) ?></dd>
      <dt>Salida</dt><dd><?= $e($fecha($r['fecha_salida'])) ?></dd>
      <dt>Estadía</dt><dd><?= $noches ?> <?= $noches === 1 ? 'noche' : 'noches' ?> · <?= (int) $r['personas'] ?> <?= $r['personas'] == 1 ? 'persona' : 'personas' ?></dd>
      <dt>Total de la estadía</dt><dd><?= $pesos($r['monto']) ?></dd>
      <dt>Abono (<?= (int) $pct ?>%)</dt><dd class="total"><?= $pesos($r['abono']) ?></dd>
      <dt>Saldo al llegar</dt><dd><?= $pesos($r['monto'] - $r['abono']) ?></dd>
    </dl>
  </section>

  <section class="tarjeta">
    <h2>Datos para transferencia</h2>
    <p class="nota">Transfiere al menos el abono de <strong><?= $pesos($r['abono']) ?></strong> antes del <?= $e($plazo) ?>. Si prefieres, puedes pagar el total de <?= $pesos($r['monto']) ?> de una vez. Si no recibimos el abono en ese plazo, la solicitud se anula y las fechas se liberan.</p>
    <dl class="datos">
      <dt>Banco</dt><dd><?= $e($t['banco']) ?></dd>
      <dt>Tipo de cuenta</dt><dd><?= $e($t['tipo_cuenta']) ?></dd>
      <dt>N° de cuenta</dt><dd><?= $e($t['numero_cuenta']) ?></dd>
      <dt>Titular</dt><dd><?= $e($t['titular']) ?></dd>
      <dt>RUT</dt><dd><?= $e($t['rut']) ?></dd>
      <dt>Correo</dt><dd><?= $e($t['email']) ?></dd>
      <dt>Monto mínimo</dt><dd class="total"><?= $pesos($r['abono']) ?></dd>
      <dt>Comentario</dt><dd>Reserva N° <?= (int) $r['id'] ?></dd>
    </dl>
    <p class="politica">El abono es la garantía de tu reserva y <strong>no es reembolsable</strong>. Te confirmaremos por correo cuando recibamos tu transferencia.</p>
  </section>

  <a class="boton boton-verde" href="./">Volver al inicio</a>
</main>
</body>
</html>
