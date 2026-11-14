<?php
// Envía el recordatorio de pago a las reservas pendientes que llevan 24 h. Correr cada 15 min (cron en el hosting).
// Uso: php recordatorios.php
if (PHP_SAPI !== 'cli') exit;
require __DIR__ . '/src/correos.php';

echo date('Y-m-d H:i') . ' recordatorios enviados: ' . enviar_recordatorios() . PHP_EOL;
