<?php
// Crea un admin o le cambia la contraseña. Uso: php crear_admin.php correo@ejemplo.cl
if (PHP_SAPI !== 'cli') exit;
require __DIR__ . '/src/db.php';

$email = mb_strtolower(trim($argv[1] ?? ''));
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    fwrite(STDERR, "Uso: php crear_admin.php correo@ejemplo.cl\n");
    exit(1);
}

// Lee la contraseña sin mostrarla en pantalla.
function leer_clave(string $pregunta): string
{
    echo $pregunta;
    $tty = stream_isatty(STDIN);
    if ($tty) shell_exec('stty -echo');
    $clave = rtrim((string) fgets(STDIN), "\r\n");
    if ($tty) shell_exec('stty echo');
    echo PHP_EOL;
    return $clave;
}

$clave = leer_clave('Contraseña (mínimo 10 caracteres): ');
if (mb_strlen($clave) < 10) {
    fwrite(STDERR, "La contraseña debe tener al menos 10 caracteres.\n");
    exit(1);
}
if ($clave !== leer_clave('Repite la contraseña: ')) {
    fwrite(STDERR, "Las contraseñas no coinciden.\n");
    exit(1);
}

$st = db()->prepare('INSERT INTO admins (email, password_hash) VALUES (?, ?) ON DUPLICATE KEY UPDATE password_hash = VALUES(password_hash)');
$st->execute([$email, password_hash($clave, PASSWORD_DEFAULT)]);
echo $st->rowCount() === 1 ? "Admin $email creado.\n" : "Contraseña de $email actualizada.\n";
