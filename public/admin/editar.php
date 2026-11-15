<?php
// Editar personas y datos de contacto de una reserva. Fechas, montos y estado no se tocan aquí.
require __DIR__ . '/../../src/admin.php';
exigir_admin();

$id = filter_var($_GET['id'] ?? null, FILTER_VALIDATE_INT) ?: 0;
$st = db()->prepare('SELECT r.*, c.nombre AS cabana, c.capacidad FROM reservas r JOIN cabanas c ON c.id = r.cabana_id WHERE r.id = ?');
$st->execute([$id]);
$reserva = $st->fetch();
if (!$reserva) {
    iniciar_sesion();
    avisar('Esa reserva no existe.', 'error');
    header('Location: ./');
    exit;
}

$error = null;
$v = $reserva; // valores del formulario: los guardados, o lo que se envió si hubo error
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    exigir_post_csrf();
    $error = editar_contacto($id, $_POST);
    if (!$error) {
        avisar("Datos de la reserva #$id guardados.");
        header('Location: ./?q=' . $id);
        exit;
    }
    $v = leer_contacto($_POST) + $reserva;
}
$e = fn($x) => htmlspecialchars((string) $x, ENT_QUOTES, 'UTF-8');
$opcion = fn(string $nombre) => '<option' . ($nombre === $v['ciudad'] ? ' selected' : '') . '>' . $e($nombre) . '</option>';
?>
<!doctype html>
<html lang="es">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="robots" content="noindex">
  <title>Editar reserva #<?= (int) $id ?> · Panel admin</title>
  <link rel="stylesheet" href="../estilos.css">
</head>
<body>
<main class="login">
  <a class="volver" href="./?q=<?= (int) $id ?>">← Volver a reservas</a>
  <h1>Editar reserva #<?= (int) $id ?></h1>
  <p class="intro"><?= $e($reserva['cabana']) ?> · <?= $e(fecha_larga($reserva['fecha_entrada'])) ?> → <?= $e(fecha_larga($reserva['fecha_salida'])) ?>. Las fechas y montos no se cambian aquí.</p>

  <form method="post" class="tarjeta">
    <input type="hidden" name="csrf" value="<?= $e(token_csrf()) ?>">
    <label>Nombre <input name="nombre" value="<?= $e($v['nombre']) ?>" required minlength="2" maxlength="100"></label>
    <label>Ciudad o país
      <select name="ciudad" required>
        <option value="">Elige la ciudad o el país</option>
        <?php foreach (CIUDADES as $region => $ciudades): ?>
          <optgroup label="<?= $e($region) ?>"><?= implode('', array_map($opcion, $ciudades)) ?></optgroup>
        <?php endforeach ?>
        <optgroup label="Otros países"><?= implode('', array_map($opcion, PAISES)) ?></optgroup>
      </select>
    </label>
    <label>Personas <span class="opcional">(máx. <?= (int) $reserva['capacidad'] ?>)</span>
      <input type="number" name="personas" value="<?= $e($v['personas']) ?>" min="1" max="<?= (int) $reserva['capacidad'] ?>" step="1" required inputmode="numeric">
    </label>
    <label>Teléfono 1 <input type="tel" name="telefono" value="<?= $e($v['telefono']) ?>" required pattern="\+?[0-9]{8,15}" maxlength="16"></label>
    <label>Teléfono 2 <span class="opcional">(opcional)</span> <input type="tel" name="telefono2" value="<?= $e($v['telefono2']) ?>" pattern="\+?[0-9]{8,15}" maxlength="16"></label>
    <label>Correo 1 <input type="email" name="email" value="<?= $e($v['email']) ?>" required maxlength="254"></label>
    <label>Correo 2 <span class="opcional">(opcional)</span> <input type="email" name="email2" value="<?= $e($v['email2']) ?>" maxlength="254"></label>
    <button type="submit">Guardar cambios</button>
    <?php if ($error): ?><p class="mensaje error" role="alert"><?= $e($error) ?></p><?php endif ?>
  </form>
</main>
</body>
</html>
