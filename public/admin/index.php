<?php
require __DIR__ . '/../../src/admin.php';
$admin = exigir_admin();

$vista = in_array($_GET['vista'] ?? '', ['dias', 'calendario', 'ganancias', 'precios'], true) ? $_GET['vista'] : 'reservas';
$ano = filter_var($_GET['ano'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 2000, 'max_range' => 2100]]) ?: (int) substr(hoy_chile(), 0, 4);
$mes = preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $_GET['mes'] ?? '') ? $_GET['mes'] : substr(hoy_chile(), 0, 7);
$delMes = $vista === 'calendario' ? reservas_del_mes($mes) : [];
$cerradosMes = $vista === 'calendario' ? bloqueos_del_mes($mes) : [];
$estado = in_array($_GET['estado'] ?? '', ESTADOS, true) ? $_GET['estado'] : null;
$conteo = contar_por_estado();
$bloqueos = listar_bloqueos();
$buscar = mb_substr(trim(campo_texto($_GET, 'q')), 0, 100);
$total = $buscar === '' ? ($estado ? (int) ($conteo[$estado] ?? 0) : array_sum($conteo)) : contar_reservas($estado, $buscar);
$paginas = max(1, (int) ceil($total / POR_PAGINA));
// Si tras aceptar o rechazar la última lista quedó vacía, muestra la última que exista.
$pagina = min($paginas, filter_var($_GET['p'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: 1);
$enlace = fn(int $p) => '?' . http_build_query(array_filter(['estado' => $estado, 'q' => $buscar, 'p' => $p > 1 ? $p : null]));
$reservas = $vista === 'reservas' ? listar_reservas($estado, $pagina, $buscar) : [];
$cabanas = in_array($vista, ['dias', 'precios'], true) ? db()->query('SELECT id, nombre, precio_noche FROM cabanas ORDER BY id')->fetchAll() : [];
$aviso = tomar_aviso();
$csrf = token_csrf();
$ahora = ahora_chile();

$e = fn($v) => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
$pesos = pesos(...);
$fecha = fn($f) => date('d-m-Y', strtotime($f));
$fechaHora = fn($f) => date('d-m-Y H:i', strtotime($f));
$MESES = ['enero', 'febrero', 'marzo', 'abril', 'mayo', 'junio', 'julio', 'agosto', 'septiembre', 'octubre', 'noviembre', 'diciembre'];
// Pendientes: aceptar al recibir el abono o el total, o rechazar. Aceptadas con saldo: marcar que pagó el resto.
$botones = fn(array $r) => match (true) {
    $r['estado'] === 'pendiente' => [
        ['aceptar_abono', 'Aceptar · abono ' . $pesos($r['abono']), '¿Recibiste el abono de ' . $pesos($r['abono']) . '? Saldo al llegar: ' . $pesos($r['monto'] - $r['abono']) . '.'],
        ['aceptar_total', 'Aceptar · pago total ' . $pesos($r['monto']), '¿Recibiste el pago total de ' . $pesos($r['monto']) . '?'],
        ['rechazar', 'Rechazar', '¿Rechazar esta solicitud? Las fechas quedarán libres.'],
    ],
    $r['estado'] === 'aceptada' && $r['pagado'] < $r['monto'] => [
        ['pagar_saldo', 'Pagó el saldo ' . $pesos($r['monto'] - $r['pagado']), '¿Recibiste el saldo de ' . $pesos($r['monto'] - $r['pagado']) . '?'],
    ],
    default => [],
};
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
    <a href="?vista=precios" <?= $vista === 'precios' ? 'aria-current="page"' : '' ?>>Precios</a>
    <a href="?vista=calendario" <?= $vista === 'calendario' ? 'aria-current="page"' : '' ?>>Calendario</a>
    <a href="?vista=ganancias" <?= $vista === 'ganancias' ? 'aria-current="page"' : '' ?>>Ganancias</a>
  </nav>

  <?php if ($aviso): ?>
    <p class="aviso <?= $e($aviso[1]) ?>" role="status"><?= $e($aviso[0]) ?></p>
  <?php endif ?>

  <?php if ($vista === 'reservas'): ?>
  <section>
    <h1 class="oculto">Reservas</h1>
    <form class="buscador" method="get" role="search">
      <?php if ($estado): ?><input type="hidden" name="estado" value="<?= $e($estado) ?>"><?php endif ?>
      <label for="buscar" class="oculto">Buscar reserva</label>
      <input id="buscar" type="search" name="q" value="<?= $e($buscar) ?>" maxlength="100" placeholder="Buscar por nombre, teléfono, correo o N° de reserva">
      <button type="submit">Buscar</button>
      <?php if ($buscar !== ''): ?><a href="./<?= $estado ? '?estado=' . $e($estado) : '' ?>">Limpiar</a><?php endif ?>
    </form>
    <nav class="filtros" aria-label="Filtrar por estado">
      <a href="./<?= $buscar !== '' ? '?q=' . $e(urlencode($buscar)) : '' ?>" <?= $estado ? '' : 'aria-current="page"' ?>>Todas <span><?= array_sum($conteo) ?></span></a>
      <?php foreach (ESTADOS as $est): ?>
        <a href="?<?= $e(http_build_query(array_filter(['estado' => $est, 'q' => $buscar]))) ?>" <?= $estado === $est ? 'aria-current="page"' : '' ?>>
          <?= $e(ucfirst($est)) ?> <span><?= (int) ($conteo[$est] ?? 0) ?></span>
        </a>
      <?php endforeach ?>
    </nav>

    <?php if (!$reservas): ?>
      <p class="vacio">No hay reservas<?= $estado ? ' en estado ' . $e($estado) : '' ?><?= $buscar !== '' ? ' que coincidan con «' . $e($buscar) . '»' : '' ?>.</p>
    <?php endif ?>

    <div class="lista-reservas">
      <?php foreach ($reservas as $r):
        $noches = dias_entre($r['fecha_entrada'], $r['fecha_salida']);
        $vence = date('Y-m-d H:i:s', strtotime($r['creada_en'] . ' +' . PLAZO_PAGO_HORAS . ' hours'));
      ?>
        <article class="reserva-card">
          <div class="reserva-cabecera">
            <span class="estado estado-<?= $e($r['estado']) ?>"><?= $e($r['estado']) ?></span>
            <span class="reserva-id">#<?= (int) $r['id'] ?></span>
          </div>
          <h2><?= $e($r['nombre']) ?></h2>
          <p class="reserva-fechas">
            <strong><?= $fecha($r['fecha_entrada']) ?> → <?= $fecha($r['fecha_salida']) ?></strong>
            · <?= $noches ?> <?= $noches === 1 ? 'noche' : 'noches' ?> · <?= (int) $r['personas'] ?> pers. · <?= $e($r['cabana']) ?>
          </p>
          <dl class="reserva-montos">
            <dt>Total</dt><dd><?= $pesos($r['monto']) ?></dd>
            <?php if ($r['estado'] === 'aceptada' && $r['pagado'] !== null): ?>
              <dt>Pagado</dt><dd><?= $pesos($r['pagado']) ?></dd>
              <dt>Saldo al llegar</dt><dd class="saldo"><?= $r['monto'] > $r['pagado'] ? $pesos($r['monto'] - $r['pagado']) : 'Nada, pagó todo' ?></dd>
            <?php else: ?>
              <dt>Abono mínimo</dt><dd><?= $pesos($r['abono']) ?></dd>
            <?php endif ?>
          </dl>
          <ul class="reserva-contacto">
            <li><a href="tel:<?= $e($r['telefono']) ?>"><?= $e($r['telefono']) ?></a></li>
            <li><a href="mailto:<?= $e($r['email']) ?>"><?= $e($r['email']) ?></a></li>
          </ul>
          <details class="reserva-mas">
            <summary>Ver más</summary>
            <dl>
              <dt>Ciudad</dt><dd><?= $e($r['ciudad']) ?></dd>
              <?php if ($region = region_de($r['ciudad'])): ?><dt><?= $region === 'Extranjero' ? 'Origen' : 'Región' ?></dt><dd><?= $e($region) ?></dd><?php endif ?>
              <dt>Teléfono 2</dt><dd><?= $r['telefono2'] ? '<a href="tel:' . $e($r['telefono2']) . '">' . $e($r['telefono2']) . '</a>' : '—' ?></dd>
              <dt>Correo 2</dt><dd><?= $r['email2'] ? '<a href="mailto:' . $e($r['email2']) . '">' . $e($r['email2']) . '</a>' : '—' ?></dd>
              <dt>Solicitada</dt><dd><?= $fechaHora($r['creada_en']) ?></dd>
              <dd class="reserva-editar"><a href="editar.php?id=<?= (int) $r['id'] ?>">✏️ Editar datos</a></dd>
            </dl>
          </details>
          <?php if ($r['estado'] === 'pendiente'): ?>
            <p class="reserva-plazo <?= $vence < $ahora ? 'vencido' : '' ?>">
              <?= $vence < $ahora ? 'Plazo del abono vencido el' : 'Plazo del abono hasta el' ?> <?= $fechaHora($vence) ?>
            </p>
          <?php endif ?>
          <?php if ($botones($r)): ?>
            <div class="reserva-acciones">
              <?php foreach ($botones($r) as [$accion, $texto, $confirmar]): ?>
                <form method="post" action="accion.php" <?= $confirmar ? 'onsubmit="return confirm(' . $e(json_encode($confirmar)) . ')"' : '' ?>>
                  <input type="hidden" name="csrf" value="<?= $e($csrf) ?>">
                  <input type="hidden" name="id" value="<?= (int) $r['id'] ?>">
                  <input type="hidden" name="accion" value="<?= $e($accion) ?>">
                  <input type="hidden" name="volver" value="<?= $e($estado ?? '') ?>">
                  <input type="hidden" name="p" value="<?= $pagina ?>">
                  <input type="hidden" name="q" value="<?= $e($buscar) ?>">
                  <button type="submit" class="accion-<?= $e($accion) ?>"><?= $e($texto) ?></button>
                </form>
              <?php endforeach ?>
            </div>
          <?php endif ?>
        </article>
      <?php endforeach ?>
    </div>

    <?php if ($paginas > 1): ?>
      <nav class="filtros paginas" aria-label="Listas de reservas">
        <?php for ($p = 1; $p <= $paginas; $p++): ?>
          <a href="<?= $e($enlace($p)) ?>" <?= $p === $pagina ? 'aria-current="page"' : '' ?> aria-label="Lista <?= $p ?>"><?= $p ?></a>
        <?php endfor ?>
      </nav>
    <?php endif ?>
  </section>

  <?php elseif ($vista === 'ganancias'):
    $ganancias = ganancias_del_ano($ano);
    $mesHoy = substr(hoy_chile(), 0, 7);
    $elegido = filter_var($_GET['m'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 12]]) ?: null;
  ?>
  <section class="ganancias">
    <div class="cal-admin-cabecera">
      <a class="cal-admin-nav" href="?vista=ganancias&ano=<?= $ano - 1 ?>" aria-label="Año anterior">◀ <?= $ano - 1 ?></a>
      <h1>Ganancias <?= $ano ?></h1>
      <a class="cal-admin-nav" href="?vista=ganancias&ano=<?= $ano + 1 ?>" aria-label="Año siguiente"><?= $ano + 1 ?> ▶</a>
    </div>
    <p class="ganancias-total">Total del año <strong><?= $pesos(array_sum(array_column($ganancias, 'monto'))) ?></strong></p>
    <p class="intro">Solo reservas aceptadas. Cada noche suma al mes en que se duerme. Los meses que aún no terminan muestran lo ya reservado.</p>

    <div class="ganancias-meses">
      <?php foreach ($ganancias as $m => $g): $clave = sprintf('%d-%02d', $ano, $m); ?>
        <a href="?vista=ganancias&ano=<?= $ano ?>&m=<?= $m ?>#resumen" <?= $m === $elegido ? 'aria-current="true"' : '' ?> class="ganancias-mes <?= $clave === $mesHoy ? 'actual' : '' ?> <?= $g['monto'] ? '' : 'vacio-mes' ?>">
          <span class="ganancias-nombre"><?= ucfirst($MESES[$m - 1]) ?><?= $clave > $mesHoy ? ' <small>por venir</small>' : ($clave === $mesHoy ? ' <small>en curso</small>' : '') ?></span>
          <strong><?= $pesos($g['monto']) ?></strong>
          <span><?= $g['noches'] ?> <?= $g['noches'] === 1 ? 'noche' : 'noches' ?> · <?= $g['reservas'] ?> <?= $g['reservas'] === 1 ? 'reserva' : 'reservas' ?></span>
        </a>
      <?php endforeach ?>
    </div>

    <?php if ($elegido): $g = $ganancias[$elegido]; $nombre = $MESES[$elegido - 1]; ?>
      <div id="resumen" class="ganancias-resumen tarjeta">
        <div class="ganancias-resumen-cabecera">
          <h2>Resumen de <?= $nombre ?> <?= $ano ?></h2>
          <a href="?vista=ganancias&ano=<?= $ano ?>" class="cal-admin-nav">Cerrar ✕</a>
        </div>
        <?php if (!$g['detalle']): ?>
          <p class="vacio">No hubo reservas aceptadas en <?= $nombre ?>.</p>
        <?php else: ?>
          <ul class="ganancias-detalle">
            <?php foreach ($g['detalle'] as $r): $saldo = $r['monto'] - (int) $r['pagado']; ?>
              <li>
                <div>
                  <strong><?= $e($r['nombre']) ?></strong>
                  <span><?= $fecha($r['fecha_entrada']) ?> → <?= $fecha($r['fecha_salida']) ?> · <?= $e($r['cabana']) ?></span>
                  <span><?= $r['noches_mes'] ?> <?= $r['noches_mes'] === 1 ? 'noche' : 'noches' ?> en <?= $nombre ?><?= $r['monto_mes'] !== $r['monto'] ? ' (de una reserva de ' . $pesos($r['monto']) . ')' : '' ?></span>
                  <span class="<?= $saldo > 0 ? 'saldo' : '' ?>"><?= $saldo > 0 ? 'Saldo por cobrar al llegar: ' . $pesos($saldo) : 'Pagó todo' ?></span>
                </div>
                <b><?= $pesos($r['monto_mes']) ?></b>
              </li>
            <?php endforeach ?>
          </ul>
        <?php endif ?>
        <p><a href="?vista=calendario&desde=ganancias&mes=<?= sprintf('%d-%02d', $ano, $elegido) ?>">Ver <?= $nombre ?> en el calendario →</a></p>
      </div>
    <?php endif ?>
  </section>

  <?php elseif ($vista === 'calendario'):
    $primero = new DateTimeImmutable("$mes-01");
    $nombreMes = $MESES[$primero->format('n') - 1];
    $diaSemana = fn($f) => ['lunes', 'martes', 'miércoles', 'jueves', 'viernes', 'sábado', 'domingo'][date('N', strtotime($f)) - 1];
    $color = fn(array $r) => 'cliente-' . ($r['id'] % 8); // por id, así el cliente mantiene su color al cambiar de mes
    $hoy = hoy_chile();
    $vienen = array_filter($delMes, fn($r) => $r['fecha_salida'] >= "$mes-01"); // sin quien solo aparece por la limpieza del día 1
    $desde = ($_GET['desde'] ?? '') === 'ganancias' ? '&desde=ganancias' : ''; // se mantiene al cambiar de mes
  ?>
  <section class="calendario-admin">
    <?php if ($desde): ?>
      <p><a class="cal-admin-nav" href="?vista=ganancias&ano=<?= $primero->format('Y') ?>&m=<?= $primero->format('n') ?>#resumen">◀ Volver a Ganancias</a></p>
    <?php endif ?>
    <div class="cal-admin-cabecera">
      <a class="cal-admin-nav" href="?vista=calendario<?= $desde ?>&mes=<?= $primero->modify('-1 month')->format('Y-m') ?>" aria-label="Mes anterior">◀ Anterior</a>
      <h1><?= ucfirst($nombreMes) ?> <?= $primero->format('Y') ?></h1>
      <a class="cal-admin-nav" href="?vista=calendario<?= $desde ?>&mes=<?= $primero->modify('+1 month')->format('Y-m') ?>" aria-label="Mes siguiente">Siguiente ▶</a>
    </div>
    <?php if ($mes !== substr($hoy, 0, 7)): ?><p class="cal-admin-hoy"><a href="?vista=calendario">Volver al mes de hoy</a></p><?php endif ?>

    <h2>Quién viene en <?= $nombreMes ?></h2>
    <?php if (!$vienen): ?>
      <p class="vacio">No hay reservas este mes.</p>
    <?php else: ?>
      <ul class="cal-admin-lista">
        <?php foreach ($vienen as $r): $noches = dias_entre($r['fecha_entrada'], $r['fecha_salida']); ?>
          <li class="<?= $color($r) ?> <?= $r['estado'] === 'pendiente' ? 'pendiente' : '' ?>">
            <strong><?= $e($r['nombre']) ?></strong>
            <?php if ($r['estado'] === 'pendiente'): ?><span class="estado estado-pendiente">pendiente de pago</span><?php endif ?>
            <span>Llega el <?= $diaSemana($r['fecha_entrada']) ?> <?= $fecha($r['fecha_entrada']) ?></span>
            <span>Se va el <?= $diaSemana($r['fecha_salida']) ?> <?= $fecha($r['fecha_salida']) ?></span>
            <details>
              <summary>Ver más</summary>
              <span><?= $noches ?> <?= $noches === 1 ? 'noche' : 'noches' ?> · <?= (int) $r['personas'] ?> pers. · <?= $e($r['cabana']) ?></span>
              <span><a href="tel:<?= $e($r['telefono']) ?>"><?= $e($r['telefono']) ?></a></span>
            </details>
          </li>
        <?php endforeach ?>
      </ul>
    <?php endif ?>

    <?php if ($cerradosMes): ?>
      <h2>Días cerrados en <?= $nombreMes ?></h2>
      <ul class="cal-admin-lista">
        <?php foreach ($cerradosMes as $b): ?>
          <li class="cal-admin-cerrado">
            <strong><?= $e($b['motivo'] ?: 'Cerrado') ?></strong>
            <span>Del <?= $diaSemana($b['fecha_inicio']) ?> <?= $fecha($b['fecha_inicio']) ?> al <?= $diaSemana($b['fecha_fin']) ?> <?= $fecha($b['fecha_fin']) ?></span>
            <span><?= $e($b['cabana']) ?></span>
          </li>
        <?php endforeach ?>
      </ul>
    <?php endif ?>

    <table class="cal-admin">
      <thead><tr><?php foreach (['Lun', 'Mar', 'Mié', 'Jue', 'Vie', 'Sáb', 'Dom'] as $s): ?><th scope="col"><?= $s ?></th><?php endforeach ?></tr></thead>
      <tbody><tr>
        <?php for ($i = 1; $i < $primero->format('N'); $i++): ?><td class="fuera"></td><?php endfor ?>
        <?php for ($dia = 1; $dia <= $primero->format('t'); $dia++):
          $f = sprintf('%s-%02d', $mes, $dia);
          if ($dia > 1 && date('N', strtotime($f)) == 1) echo '</tr><tr>';
        ?>
          <td class="<?= $f === $hoy ? 'hoy' : '' ?>">
            <span class="cal-admin-dia"><?= $dia ?></span>
            <?php foreach ($cerradosMes as $b): if ($f < $b['fecha_inicio'] || $f > $b['fecha_fin']) continue; ?>
              <span class="cal-admin-cliente cal-admin-cerrado" title="<?= $e('Cerrado · ' . $b['cabana'] . ($b['motivo'] ? ' · ' . $b['motivo'] : '')) ?>">
                <b>Cerrado</b> <?= $e($b['motivo'] ?? '') ?>
              </span>
            <?php endforeach ?>
            <?php foreach ($delMes as $r):
              if ($f === date('Y-m-d', strtotime($r['fecha_salida'] . ' +1 day'))) echo '<span class="cal-admin-cliente cal-admin-limpieza">Limpieza</span>';
              if ($f < $r['fecha_entrada'] || $f > $r['fecha_salida']) continue;
            ?>
              <span class="cal-admin-cliente <?= $color($r) ?> <?= $r['estado'] === 'pendiente' ? 'pendiente' : '' ?>" title="<?= $e($r['nombre'] . ' · ' . $r['cabana']) ?>">
                <?php if ($f === $r['fecha_entrada']): ?><b>Llega</b> <?php elseif ($f === $r['fecha_salida']): ?><b>Se va</b> <?php endif ?><?= $e(strtok($r['nombre'], ' ')) ?>
              </span>
            <?php endforeach ?>
          </td>
        <?php endfor ?>
        <?php for ($i = (int) date('N', strtotime("$mes-" . $primero->format('t'))); $i < 7; $i++): ?><td class="fuera"></td><?php endfor ?>
      </tr></tbody>
    </table>
  </section>

  <?php elseif ($vista === 'precios'): $precios = listar_precios(); ?>
  <section class="admin-bloqueos">
    <h1 class="oculto">Precios</h1>

    <h2>Precio general por noche</h2>
    <p class="intro">Es el precio de los días sin precio especial. Al cambiarlo, solo cambian las reservas nuevas: las que ya existen mantienen su monto.</p>
    <?php foreach ($cabanas as $c): ?>
      <form method="post" action="precios.php" class="tarjeta form-bloqueo form-precio" data-actual="<?= (int) $c['precio_noche'] ?>">
        <input type="hidden" name="csrf" value="<?= $e($csrf) ?>">
        <input type="hidden" name="accion" value="base">
        <input type="hidden" name="cabana_id" value="<?= (int) $c['id'] ?>">
        <label><?= $e($c['nombre']) ?> <span class="opcional">(ahora <?= $pesos($c['precio_noche']) ?>)</span>
          <input name="precio" value="<?= (int) $c['precio_noche'] ?>" inputmode="numeric" required>
        </label>
        <button type="submit">Guardar</button>
      </form>
    <?php endforeach ?>

    <h2>Precios especiales por fechas</h2>
    <p class="intro">Para promos (más barato) o temporada alta (más caro). Cada noche entre esas fechas cuesta este precio en vez del general. No se pueden cruzar dos en la misma cabaña.</p>
    <div class="precios-editor">
    <form method="post" action="precios.php" class="tarjeta form-bloqueo" id="form-precio-especial">
      <input type="hidden" name="csrf" value="<?= $e($csrf) ?>">
      <input type="hidden" name="accion" value="crear">
      <label>Cabaña
        <select name="cabana_id" required>
          <?php foreach ($cabanas as $c): ?><option value="<?= (int) $c['id'] ?>"><?= $e($c['nombre']) ?> · general <?= $pesos($c['precio_noche']) ?></option><?php endforeach ?>
        </select>
      </label>
      <div class="fila">
        <label>Desde <input type="date" name="fecha_inicio" min="<?= $e(hoy_chile()) ?>" required></label>
        <label>Hasta (inclusive) <input type="date" name="fecha_fin" min="<?= $e(hoy_chile()) ?>" required></label>
      </div>
      <div class="fila">
        <label>Precio por noche <input name="precio" inputmode="numeric" placeholder="50.000" required></label>
        <label>Nombre <span class="opcional">(opcional, solo lo ves tú)</span> <input name="motivo" maxlength="200" placeholder="Promo invierno"></label>
      </div>
      <button type="submit">Guardar precio especial</button>
    </form>

    <div class="tarjeta mini-cal">
      <div id="mini-cal" class="calendario" aria-live="polite"></div>
      <p class="leyenda">
        <span class="muestra ocupado"></span>Con huéspedes <span class="muestra cerrado"></span>Cerrado
        <span class="muestra especial"></span>Precio especial <span class="muestra especial pasado"></span>Ya terminó
        <span class="muestra elegido"></span>Tu selección
      </p>
      <p id="aviso-precio" class="mensaje" role="status"></p>
    </div>
    </div>
    <!-- Datos del mini calendario: solo fechas, sin datos de clientes. -->
    <script type="application/json" id="datos-precios"><?= json_encode([
        'hoy' => hoy_chile(),
        'reservas' => reservas_activas_futuras(),
        'cerrados' => array_map(fn($b) => [$b['cabana_id'], $b['fecha_inicio'], $b['fecha_fin']], $bloqueos),
        'precios' => precios_para_calendario(),
    ], JSON_HEX_TAG | JSON_HEX_AMP) ?></script>
    <script src="precios.js"></script>

    <?php if ($precios): ?>
      <ul class="lista-bloqueos">
        <?php foreach ($precios as $p): ?>
          <li>
            <div>
              <strong><?= $fecha($p['fecha_inicio']) ?> → <?= $fecha($p['fecha_fin']) ?></strong> · <?= $e($p['cabana']) ?>
              <br><strong class="<?= $p['precio_noche'] < $p['precio_base'] ? 'precio-promo' : 'precio-alto' ?>"><?= $pesos($p['precio_noche']) ?> por noche</strong>
              <span class="opcional">(general <?= $pesos($p['precio_base']) ?>)</span>
              <?php if ($p['motivo']): ?><br><span class="opcional"><?= $e($p['motivo']) ?></span><?php endif ?>
            </div>
            <form method="post" action="precios.php" onsubmit="return confirm('¿Borrar este precio especial? Esos días vuelven al precio general.')">
              <input type="hidden" name="csrf" value="<?= $e($csrf) ?>">
              <input type="hidden" name="accion" value="borrar">
              <input type="hidden" name="id" value="<?= (int) $p['id'] ?>">
              <button type="submit" class="accion-reabrir">Borrar</button>
            </form>
          </li>
        <?php endforeach ?>
      </ul>
    <?php else: ?>
      <p class="vacio">No hay precios especiales.</p>
    <?php endif ?>
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
