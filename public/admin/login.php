<?php
require __DIR__ . '/../../src/admin.php';
iniciar_sesion();
if (!empty($_SESSION['admin'])) {
    header('Location: ./');
    exit;
}

$error = null;
$email = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    exigir_post_csrf();
    $email = (string) ($_POST['email'] ?? '');
    if (iniciar_admin($email, (string) ($_POST['clave'] ?? ''))) {
        header('Location: ./');
        exit;
    }
    sleep(1); // frena los intentos de adivinar contraseñas
    $error = 'Correo o contraseña incorrectos.';
}
$e = fn($v) => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
?>
<!doctype html>
<html lang="es">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="robots" content="noindex">
  <title>Ingresar · Panel admin</title>
  <link rel="stylesheet" href="../estilos.css">
</head>
<body>
<main class="login">
  <a class="volver" href="../">← Volver al sitio</a>
  <h1>Panel admin</h1>
  <form method="post" class="tarjeta">
    <input type="hidden" name="csrf" value="<?= $e(token_csrf()) ?>">
    <label>Correo <input type="email" name="email" value="<?= $e($email) ?>" required autocomplete="username" autofocus></label>
    <label>Contraseña <input type="password" name="clave" required autocomplete="current-password"></label>
    <button type="submit">Ingresar</button>
    <?php if ($error): ?><p class="mensaje error" role="alert"><?= $e($error) ?></p><?php endif ?>
  </form>
</main>
</body>
</html>
