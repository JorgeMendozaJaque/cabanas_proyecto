<?php
require __DIR__ . '/../src/db.php';
$cabanas = db()->query('SELECT id, nombre, capacidad, precio_noche FROM cabanas WHERE activa ORDER BY id')->fetchAll();
$e = fn($v) => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
?>
<!doctype html>
<html lang="es">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Reserva tu cabaña</title>
  <link rel="stylesheet" href="estilos.css">
</head>
<body>
<main class="reserva">
  <a class="volver" href="./">← Volver</a>
  <h1>Reserva tu cabaña</h1>
  <p class="intro">Completa tus datos, elige tus fechas y envía la solicitud. Las fechas quedan apartadas mientras la revisamos y te respondemos por correo.</p>

  <?php if (!$cabanas): ?>
    <p class="mensaje error">Por ahora no hay cabañas disponibles.</p>
  <?php else: ?>
  <form id="form-reserva">
    <div class="columnas">
      <fieldset>
        <legend>1. Tus datos</legend>
        <label>Nombre <input name="nombre" data-solo="letras" required minlength="2" maxlength="100" pattern="\p{L}[\p{L} '.\-]*" title="Solo letras, espacios, apóstrofo, punto o guion." autocomplete="name"></label>
        <label>Ciudad <input name="ciudad" data-solo="letras" required minlength="2" maxlength="100" pattern="\p{L}[\p{L} '.\-]*" title="Solo letras, espacios, apóstrofo, punto o guion." autocomplete="address-level2"></label>
        <div class="fila">
          <label>Teléfono 1 <input type="tel" name="telefono" data-solo="telefono" required minlength="8" maxlength="16" pattern="\+?[0-9]{8,15}" title="Solo números (8 a 15), con + opcional al inicio." placeholder="+56912345678" inputmode="tel" autocomplete="tel"></label>
          <label>Teléfono 2 <span class="opcional">(opcional)</span> <input type="tel" name="telefono2" data-solo="telefono" minlength="8" maxlength="16" pattern="\+?[0-9]{8,15}" title="Solo números (8 a 15), con + opcional al inicio." inputmode="tel"></label>
        </div>
        <div class="fila">
          <label>Correo 1 <input type="email" name="email" data-solo="correo" required maxlength="254" autocomplete="email"></label>
          <label>Correo 2 <span class="opcional">(opcional)</span> <input type="email" name="email2" data-solo="correo" maxlength="254"></label>
        </div>
      </fieldset>

      <fieldset>
        <legend>2. Cabaña y fechas</legend>
        <div class="fila">
          <label>Cabaña
            <select name="cabana_id" required>
              <?php foreach ($cabanas as $c): ?>
                <option value="<?= $e($c['id']) ?>" data-capacidad="<?= $e($c['capacidad']) ?>" data-precio="<?= $e($c['precio_noche']) ?>">
                  <?= $e($c['nombre']) ?> · $<?= number_format($c['precio_noche'], 0, ',', '.') ?>/noche
                </option>
              <?php endforeach ?>
            </select>
          </label>
          <label class="corto">Personas <span class="opcional">(máx. <span id="capacidad"><?= $e($cabanas[0]['capacidad']) ?></span>)</span>
            <input type="number" name="personas" min="1" max="<?= $e($cabanas[0]['capacidad']) ?>" value="1" step="1" required inputmode="numeric">
          </label>
        </div>

        <div id="calendario" class="calendario" aria-live="polite"></div>
        <p class="leyenda"><span class="muestra libre"></span>Disponible <span class="muestra ocupado"></span>No disponible <span class="muestra elegido"></span>Tu estadía</p>
        <p id="resumen" class="resumen" aria-live="polite"></p>
        <input type="hidden" name="fecha_entrada">
        <input type="hidden" name="fecha_salida">
      </fieldset>
    </div>

    <button type="submit">Enviar solicitud</button>
    <p id="mensaje" class="mensaje" role="status" aria-live="polite"></p>
  </form>
  <script src="app.js"></script>
  <?php endif ?>
</main>
</body>
</html>
