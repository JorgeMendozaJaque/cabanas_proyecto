<?php
require_once __DIR__ . '/reservas.php';

// Envía un correo de texto con la API HTTP de Resend. Nunca lanza: un correo fallido no debe deshacer una reserva.
function enviar_correo(array $para, string $asunto, string $texto): bool
{
    // .invalid es un dominio reservado que no existe: lo usan las pruebas, así que nunca llegan a Resend.
    $para = array_values(array_filter($para, fn($x) => $x && !str_ends_with(strtolower($x), '.invalid')));
    if (!$para) return false;
    try {
        $c = config()['resend'] ?? [];
        if (empty($c['api_key'])) throw new RuntimeException('falta resend.api_key en config.php');
        $ch = curl_init('https://api.resend.com/emails');
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 10,
            CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $c['api_key'], 'Content-Type: application/json'],
            CURLOPT_POSTFIELDS => json_encode([
                'from' => $c['remitente'],
                'to' => $para,
                'subject' => $asunto,
                'text' => $texto,
            ]),
        ]);
        $respuesta = curl_exec($ch);
        $codigo = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        if ($codigo !== 200) throw new RuntimeException("HTTP $codigo " . ($respuesta ?: curl_error($ch)));
        return true;
    } catch (Throwable $e) {
        error_log("Correo no enviado ($asunto): " . $e->getMessage());
        return false;
    }
}

function reserva_para_correo(int $id): array
{
    $st = db()->prepare('SELECT r.*, c.nombre AS cabana FROM reservas r JOIN cabanas c ON c.id = r.cabana_id WHERE r.id = ?');
    $st->execute([$id]);
    return $st->fetch() ?: throw new RuntimeException("Reserva $id no existe");
}

function resumen_reserva(array $r): string
{
    $noches = dias_entre($r['fecha_entrada'], $r['fecha_salida']);
    return "Reserva N° {$r['id']}\n"
        . "Cabaña: {$r['cabana']}\n"
        . 'Llegada: ' . fecha_larga($r['fecha_entrada']) . "\n"
        . 'Salida: ' . fecha_larga($r['fecha_salida']) . "\n"
        . "Estadía: $noches " . ($noches === 1 ? 'noche' : 'noches') . " · {$r['personas']} " . ($r['personas'] == 1 ? 'persona' : 'personas') . "\n"
        . 'Total de la estadía: ' . pesos($r['monto']) . "\n";
}

function datos_transferencia(array $r): string
{
    $t = config()['transferencia'];
    return "Datos para transferencia:\n"
        . "Banco: {$t['banco']}\n"
        . "Tipo de cuenta: {$t['tipo_cuenta']}\n"
        . "N° de cuenta: {$t['numero_cuenta']}\n"
        . "Titular: {$t['titular']}\n"
        . "RUT: {$t['rut']}\n"
        . "Correo: {$t['email']}\n"
        . "Comentario: Reserva N° {$r['id']}\n";
}

// [para, asunto, texto] de cada correo. Separados del envío para poder probarlos sin red.
function mensajes_reserva_nueva(array $r): array
{
    $pct = config()['abono_porcentaje'];
    $plazo = plazo_pago($r['creada_en']);
    $nombre = strtok($r['nombre'], ' ');
    return [
        [
            [config()['resend']['admin_email']],
            "Nueva solicitud N° {$r['id']}: {$r['nombre']}",
            "Llegó una solicitud nueva. Las fechas quedaron apartadas hasta que la aceptes o rechaces.\n\n"
            . resumen_reserva($r)
            . 'Abono (' . $pct . '%): ' . pesos($r['abono']) . "\n\n"
            . "Cliente: {$r['nombre']} ({$r['ciudad']})\n"
            . "Teléfono: {$r['telefono']}" . ($r['telefono2'] ? " / {$r['telefono2']}" : '') . "\n"
            . "Correo: {$r['email']}" . ($r['email2'] ? " / {$r['email2']}" : '') . "\n\n"
            . "Plazo para el abono: $plazo\n"
            . 'Revísala en el panel de administración.',
        ],
        [
            [$r['email'], $r['email2']],
            "Recibimos tu solicitud de reserva N° {$r['id']}",
            "Hola $nombre,\n\n"
            . "Recibimos tu solicitud. Tus fechas quedaron apartadas por 48 horas mientras la revisamos.\n\n"
            . resumen_reserva($r)
            . 'Abono (' . $pct . '%): ' . pesos($r['abono']) . "\n"
            . 'Saldo al llegar: ' . pesos($r['monto'] - $r['abono']) . "\n\n"
            . "Para confirmar, transfiere al menos el abono de " . pesos($r['abono']) . " antes del $plazo. "
            . 'Si prefieres, puedes pagar el total de ' . pesos($r['monto']) . " de una vez.\n\n"
            . datos_transferencia($r) . "\n"
            . "El abono es la garantía de tu reserva y no es reembolsable. "
            . "Si no recibimos el abono en el plazo, la solicitud se anula y las fechas se liberan.\n"
            . 'Te avisaremos por correo cuando confirmemos tu pago.',
        ],
    ];
}

function mensaje_decision(array $r): array
{
    $nombre = strtok($r['nombre'], ' ');
    if ($r['estado'] === 'aceptada') {
        $saldo = $r['monto'] - $r['pagado'];
        return [
            [$r['email'], $r['email2']],
            "Tu reserva N° {$r['id']} está confirmada",
            "Hola $nombre,\n\n"
            . "Recibimos tu pago y tu reserva quedó confirmada.\n\n"
            . resumen_reserva($r)
            . 'Pagado: ' . pesos($r['pagado']) . "\n"
            . ($saldo > 0 ? 'Saldo a pagar al llegar: ' . pesos($saldo) : 'Tu estadía está pagada por completo.') . "\n\n"
            . '¡Te esperamos!',
        ];
    }
    return [
        [$r['email'], $r['email2']],
        "Tu solicitud de reserva N° {$r['id']} fue anulada",
        "Hola $nombre,\n\n"
        . "Tu solicitud de reserva no pudo confirmarse y las fechas se liberaron.\n\n"
        . resumen_reserva($r)
        . "\nSi aún quieres venir, puedes hacer una nueva solicitud en nuestra web.",
    ];
}

function mensaje_recordatorio(array $r): array
{
    $nombre = strtok($r['nombre'], ' ');
    $plazo = plazo_pago($r['creada_en']);
    return [
        [$r['email'], $r['email2']],
        "Recordatorio: te quedan 24 horas para confirmar tu reserva N° {$r['id']}",
        "Hola $nombre,\n\n"
        . "Tus fechas siguen apartadas, pero aún no recibimos tu abono. "
        . 'Para confirmar, transfiere al menos ' . pesos($r['abono']) . " antes del $plazo. "
        . "Si no llega a tiempo, la solicitud se anula y las fechas se liberan.\n\n"
        . resumen_reserva($r) . "\n"
        . datos_transferencia($r) . "\n"
        . 'Si ya transferiste, ignora este correo: te avisaremos cuando confirmemos tu pago.',
    ];
}

// Recordatorio a las pendientes que llevan 24 h sin pagar (aún dentro del plazo de 48 h).
// Se marca antes de enviar, así nunca llega dos veces aunque el envío falle o dos ejecuciones se crucen.
function enviar_recordatorios(): int
{
    $ids = db()->query("SELECT id FROM reservas WHERE estado = 'pendiente' AND recordatorio_en IS NULL
                        AND creada_en <= NOW() - INTERVAL 24 HOUR AND creada_en > NOW() - INTERVAL 48 HOUR")
        ->fetchAll(PDO::FETCH_COLUMN);
    $enviados = 0;
    foreach ($ids as $id) {
        $st = db()->prepare('UPDATE reservas SET recordatorio_en = NOW() WHERE id = ? AND recordatorio_en IS NULL');
        $st->execute([$id]);
        if ($st->rowCount() === 1 && enviar_correo(...mensaje_recordatorio(reserva_para_correo($id)))) $enviados++;
    }
    return $enviados;
}

// Correo al admin y al cliente cuando entra una solicitud.
function correos_reserva_nueva(int $id): void
{
    try {
        foreach (mensajes_reserva_nueva(reserva_para_correo($id)) as $m) enviar_correo(...$m);
    } catch (Throwable $e) {
        error_log("Correos de la reserva $id: " . $e->getMessage());
    }
}

// Correo al cliente cuando el admin acepta o rechaza.
function correo_decision(int $id): void
{
    try {
        enviar_correo(...mensaje_decision(reserva_para_correo($id)));
    } catch (Throwable $e) {
        error_log("Correo de decisión de la reserva $id: " . $e->getMessage());
    }
}
