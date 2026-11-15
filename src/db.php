<?php
// Una sola zona horaria para PHP y MySQL; si no, en un hosting en UTC el plazo de pago sale 3 h corrido.
date_default_timezone_set('America/Santiago');

function config(): array
{
    static $config;
    return $config ??= require __DIR__ . '/../config.php';
}

// Conexión única a MySQL, reutilizada en cada petición.
function db(): PDO
{
    static $pdo;
    if (!$pdo) {
        $c = config()['db'];
        $pdo = new PDO(
            "mysql:host={$c['host']};dbname={$c['nombre']};charset=utf8mb4",
            $c['usuario'],
            $c['clave'],
            [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false, // sentencias preparadas reales en MySQL
            ]
        );
        $pdo->exec("SET time_zone = '" . date('P') . "'"); // desfase actual de Chile (-03:00 o -04:00)
    }
    return $pdo;
}
