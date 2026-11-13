<?php
require __DIR__ . '/../../src/admin.php';
$admin = exigir_admin();

$vista = ($_GET['vista'] ?? '') === 'dias' ? 'dias' : 'reservas';
$estado = in_array($_GET['estado'] ?? '', ESTADOS, true) ? $_GET['estado'] : null;
$conteo = contar_por_estado();
$bloqueos = listar_bloqueos();
if ($vista === 'reservas') {
    $reservas = listar_reservas($estado);
} else {
    $cabanas = db()->query('SELECT id, nombre FROM cabanas ORDER BY id')->fetchAll();
}
$aviso = tomar_aviso();
$csrf = token_csrf();
$ahora = ahora_chile();

$e = fn($v) => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
$pesos = fn($n) => '$' . number_format((int) $n, 0, ',', '.');
$fecha = fn($f) => date('d-m-Y', strtotime($f));
$fechaHora = fn($f) => date('d-m-Y H:i', strtotime($f));
$botones = [
    'pendiente' => [['aceptar', 'Aceptar', ''], ['rechazar', 'Rechazar', '¿Rechazar esta solicitud? Las fechas quedarán libres.']],
    'aceptada' => [['pagada', 'Marcar pagada', '¿Confirmas que llegó la transferencia?'], ['cancelar', 'Cancelar', '¿Cancelar esta reserva? Las fechas quedarán libres.']],
];
?>
<!doctype html>
<html lang="es">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="robots" content="noindex">
  <title>Panel admin · Reservas</title>
  <link rel="stylesheet" href="../estilos.css">
</head>
<body>
<header class="admin-barra">
  <strong>Panel de reservas</strong>
  <div>
    <span class="admin-email"><?= $e($admin['email']) ?></span>
    <a href="../" target="_blank" rel="noopener">Ver sitio</a>
    <form method="post" action="salir.php">
      <input type="hidden" name="csrf" value="<?= $e($csrf) ?>">
      <button type="submit">Salir</button>
    </form>
  </div>
</header>

<main class="admin">
  <nav class="pestanas" aria-label="Secciones del panel">
    <a href="./" <?= $vista === 'reservas' ? 'aria-current="page"' : '' ?>>Reservas <span><?= array_sum($conteo) ?></span></a>
    <a href="?vista=dias" <?= $vista === 'dias' ? 'aria-current="page"' : '' ?>>Días cerrados <span><?= count($bloqueos) ?></span></a>
  </nav>

  <?php if ($aviso): ?>
    <p class="aviso <?= $e($aviso[1]) ?>" role="status"><?= $e($aviso[0]) ?></p>
  <?php endif ?>

  <?php if ($vista === 'reservas'): ?>
  <section>
    <h1 class="oculto">Reservas</h1>
    <nav class="filtros" aria-label="Filtrar por estado">
      <a href="./" <?= $estado ? '' : 'aria-current="page"' ?>>Todas <span><?= array_sum($conteo) ?></span></a>
      <?php foreach (ESTADOS as $est): ?>
        <a href="?estado=<?= $e($est) ?>" <?= $estado === $est ? 'aria-current="page"' : '' ?>>
          <?= $e(ucfirst($est)) ?> <span><?= (int) ($conteo[$est] ?? 0) ?></span>
        </a>
      <?php endforeach ?>
    </nav>

    <?php if (!$reservas): ?>
      <p class="vacio">No hay reservas<?= $estado ? ' en estado ' . $e($estado) : '' ?>.</p>
    <?php endif ?>

    <div class="lista-reservas">
      <?php foreach ($reservas as $r):
        $noches = dias_entre($r['fecha_entrada'], $r['fecha_salida']);
        $vence = $r['aceptada_en'] ? date('Y-m-d H:i:s', strtotime($r['aceptada_en'] . ' +' . PLAZO_PAGO_HORAS . ' hours')) : null;
      ?>
        <article class="reserva-card">
          <div class="reserva-cabecera">
            <span class="estado estado-<?= $e($r['estado']) ?>"><?= $e($r['estado']) ?></span>
            <span class="reserva-id">#<?= (int) $r['id'] ?></span>
          </div>
          <h2><?= $e($r['nombre']) ?> <small><?= $e($r['ciudad']) ?></small></h2>
          <p class="reserva-fechas">
            <strong><?= $fecha($r['fecha_entrada']) ?> → <?= $fecha($r['fecha_salida']) ?></strong>
            · <?= $noches ?> <?= $noches === 1 ? 'noche' : 'noches' ?> · <?= (int) $r['personas'] ?> pers. · <?= $e($r['cabana']) ?>
          </p>
          <p class="reserva-monto"><?= $pesos($r['monto']) ?></p>
          <ul class="reserva-contacto">
            <li><a href="tel:<?= $e($r['telefono']) ?>"><?= $e($r['telefono']) ?></a><?php if ($r['telefono2']): ?> · <a href="tel:<?= $e($r['telefono2']) ?>"><?= $e($r['telefono2']) ?></a><?php endif ?></li>
            <li><a href="mailto:<?= $e($r['email']) ?>"><?= $e($r['email']) ?></a><?php if ($r['email2']): ?> · <a href="mailto:<?= $e($r['email2']) ?>"><?= $e($r['email2']) ?></a><?php endif ?></li>
          </ul>
          <p class="reserva-meta">Solicitada el <?= $fechaHora($r['creada_en']) ?></p>
          <?php if ($r['estado'] === 'aceptada' && $vence): ?>
            <p class="reserva-plazo <?= $vence < $ahora ? 'vencido' : '' ?>">
              <?= $vence < $ahora ? 'Plazo de pago vencido el' : 'Plazo de pago hasta el' ?> <?= $fechaHora($vence) ?>
            </p>
          <?php endif ?>

          <?php if (isset($botones[$r['estado']])): ?>
            <div class="reserva-acciones">
              <?php foreach ($botones[$r['estado']] as [$accion, $texto, $confirmar]): ?>
                <form method="post" action="accion.php" <?= $confirmar ? 'onsubmit="return confirm(' . $e(json_encode($confirmar)) . ')"' : '' ?>>
                  <input type="hidden" name="csrf" value="<?= $e($csrf) ?>">
                  <input type="hidden" name="id" value="<?= (int) $r['id'] ?>">
                  <input type="hidden" name="accion" value="<?= $e($accion) ?>">
                  <input type="hidden" name="volver" value="<?= $e($estado ?? '') ?>">
                  <button type="submit" class="accion-<?= $e($accion) ?>"><?= $e($texto) ?></button>
                </form>
              <?php endforeach ?>
            </div>
          <?php endif ?>
        </article>
      <?php endforeach ?>
    </div>
  </section>

  <?php else: ?>
  <section class="admin-bloqueos">
    <h1 class="oculto">Días cerrados</h1>
    <p class="intro">Cierra días por mantenimiento u otro motivo. No se pueden cerrar días que ya tienen reservas activas.</p>
    <form method="post" action="bloqueo.php" class="tarjeta form-bloqueo">
      <input type="hidden" name="csrf" value="<?= $e($csrf) ?>">
      <input type="hidden" name="accion" value="crear">
      <label>Cabaña
        <select name="cabana_id" required>
          <?php foreach ($cabanas as $c): ?><option value="<?= (int) $c['id'] ?>"><?= $e($c['nombre']) ?></option><?php endforeach ?>
        </select>
      </label>
      <div class="fila">
        <label>Desde <input type="date" name="fecha_inicio" min="<?= $e(hoy_chile()) ?>" required></label>
        <label>Hasta (inclusive) <input type="date" name="fecha_fin" min="<?= $e(hoy_chile()) ?>" required></label>
      </div>
      <label>Motivo <span class="opcional">(opcional, solo lo ves tú)</span> <input name="motivo" maxlength="200" placeholder="Mantención"></label>
      <button type="submit">Cerrar días</button>
    </form>

    <?php if ($bloqueos): ?>
      <ul class="lista-bloqueos">
        <?php foreach ($bloqueos as $b): ?>
          <li>
            <div>
              <strong><?= $fecha($b['fecha_inicio']) ?> → <?= $fecha($b['fecha_fin']) ?></strong> · <?= $e($b['cabana']) ?>
              <?php if ($b['motivo']): ?><br><span class="opcional"><?= $e($b['motivo']) ?></span><?php endif ?>
            </div>
            <form method="post" action="bloqueo.php" onsubmit="return confirm('¿Reabrir estos días?')">
              <input type="hidden" name="csrf" value="<?= $e($csrf) ?>">
              <input type="hidden" name="accion" value="borrar">
              <input type="hidden" name="id" value="<?= (int) $b['id'] ?>">
              <button type="submit" class="accion-reabrir">Reabrir</button>
            </form>
          </li>
        <?php endforeach ?>
      </ul>
    <?php else: ?>
      <p class="vacio">No hay días cerrados.</p>
    <?php endif ?>
  </section>
  <?php endif ?>
</main>
</body>
</html>
