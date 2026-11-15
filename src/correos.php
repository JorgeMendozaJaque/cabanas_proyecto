<?php
require_once __DIR__ . '/reservas.php';

// Collage de la galería que va arriba de cada correo, incrustado (cid:banner). Se rehace con public/fotos/ si cambian las fotos.
const BANNER_CORREO = __DIR__ . '/../public/fotos/banner-correo.jpg';

// Envía un correo con la API HTTP de Resend (HTML y texto plano de respaldo). Nunca lanza: un correo fallido no debe deshacer una reserva.
function enviar_correo(array $para, string $asunto, string $texto, ?string $html = null): bool
{
    // .invalid es un dominio reservado que no existe: lo usan las pruebas, así que nunca llegan a Resend.
    $para = array_values(array_filter($para, fn($x) => $x && !str_ends_with(strtolower($x), '.invalid')));
    if (!$para) return false;
    try {
        $c = config()['resend'] ?? [];
        if (empty($c['api_key'])) throw new RuntimeException('falta resend.api_key en config.php');
        $datos = ['from' => $c['remitente'], 'to' => $para, 'subject' => $asunto, 'text' => $texto];
        if ($html) {
            $datos['html'] = $html;
            $datos['attachments'] = [['filename' => 'cabanas.jpg', 'content' => base64_encode(file_get_contents(BANNER_CORREO)), 'content_id' => 'banner']];
        }
        $ch = curl_init('https://api.resend.com/emails');
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 10,
            CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $c['api_key'], 'Content-Type: application/json'],
            CURLOPT_POSTFIELDS => json_encode($datos),
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

// Arma [para, asunto, texto, html]. $cuadros: ['Título del cuadro' => ['Dato' => 'valor', ...], ...].
// $extra: HTML que va debajo de los cuadros (solo en la versión HTML).
function correo(array $para, string $asunto, string $titulo, string $intro, array $cuadros, string $nota = '', string $extra = ''): array
{
    $e = fn($x) => htmlspecialchars((string) $x, ENT_QUOTES, 'UTF-8');

    $texto = "$intro\n\n";
    foreach ($cuadros as $nombre => $filas) {
        $texto .= "$nombre\n";
        foreach ($filas as $dato => $valor) $texto .= "$dato: $valor\n";
        $texto .= "\n";
    }
    $texto .= $nota;

    $html = '';
    foreach ($cuadros as $nombre => $filas) {
        $html .= '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin:0 0 16px;border:1px solid #cfe0d5;border-radius:10px;background:#f3f8f5;border-collapse:separate">'
            . '<tr><td colspan="2" style="padding:14px 18px 6px;font-size:13px;font-weight:700;letter-spacing:.04em;text-transform:uppercase;color:#2f5d46">' . $e($nombre) . '</td></tr>';
        foreach ($filas as $dato => $valor) {
            $html .= '<tr><td style="padding:5px 18px;font-size:15px;color:#5d6b63">' . $e($dato) . '</td>'
                . '<td align="right" style="padding:5px 18px;font-size:15px;font-weight:600;color:#1f2a24">' . $e($valor) . '</td></tr>';
        }
        $html .= '<tr><td colspan="2" style="height:10px"></td></tr></table>';
    }
    $html = '<!doctype html><html lang="es"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width"></head>'
        . '<body style="margin:0;padding:0;background:#f6f4ef;font-family:-apple-system,\'Segoe UI\',Roboto,Arial,sans-serif;color:#1f2a24">'
        . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f6f4ef"><tr><td align="center" style="padding:24px 12px">'
        . '<table role="presentation" width="600" cellpadding="0" cellspacing="0" style="width:100%;max-width:600px;background:#ffffff;border-radius:12px;overflow:hidden">'
        . '<tr><td><img src="cid:banner" width="600" alt="Fotos de la cabaña" style="display:block;width:100%;height:auto;border:0"></td></tr>'
        . '<tr><td style="padding:28px 28px 12px">'
        . '<h1 style="margin:0 0 10px;font-size:24px;line-height:1.25;color:#2f5d46">' . $e($titulo) . '</h1>'
        . '<p style="margin:0 0 22px;font-size:16px;line-height:1.5">' . nl2br($e($intro)) . '</p>'
        . $html . $extra
        . ($nota ? '<p style="margin:4px 0 0;font-size:14px;line-height:1.5;color:#5d6b63">' . nl2br($e($nota)) . '</p>' : '')
        . '</td></tr>'
        . '<tr><td style="padding:16px 28px 24px;font-size:12px;color:#8a958e;border-top:1px solid #eef0ec">Cabañas · Este correo se envió por una solicitud de reserva.</td></tr>'
        . '</table></td></tr></table></body></html>';

    return [$para, $asunto, $texto, $html];
}

// Calendario chico de cada mes que toca la estadía: noches en verde y el día de salida en verde claro.
function calendario_html(string $entrada, string $salida): string
{
    $meses = ['Enero', 'Febrero', 'Marzo', 'Abril', 'Mayo', 'Junio', 'Julio', 'Agosto', 'Septiembre', 'Octubre', 'Noviembre', 'Diciembre'];
    $celda = 'width:28px;height:24px;font-size:12px;text-align:center;';
    $html = '';
    for ($mes = new DateTime(substr($entrada, 0, 7) . '-01'); $mes->format('Y-m') <= substr($salida, 0, 7); $mes->modify('+1 month')) {
        $html .= '<table role="presentation" cellpadding="0" cellspacing="2" style="display:inline-table;margin:0 8px 8px;vertical-align:top">'
            . '<tr><td colspan="7" style="padding:0 0 4px;font-size:13px;font-weight:700;color:#2f5d46">' . $meses[$mes->format('n') - 1] . ' ' . $mes->format('Y') . '</td></tr><tr>';
        foreach (['L', 'M', 'M', 'J', 'V', 'S', 'D'] as $d) $html .= "<td style=\"{$celda}color:#8a958e\">$d</td>";
        $html .= '</tr><tr>' . str_repeat('<td></td>', $mes->format('N') - 1);
        for ($d = 1, $n = (int) $mes->format('t'); $d <= $n; $d++) {
            $f = $mes->format('Y-m-') . str_pad((string) $d, 2, '0', STR_PAD_LEFT);
            $color = $f >= $entrada && $f < $salida ? 'background:#2f5d46;color:#ffffff;font-weight:700;' : ($f === $salida ? 'background:#dcebe1;color:#2f5d46;font-weight:700;' : 'color:#1f2a24;');
            $html .= "<td style=\"{$celda}border-radius:5px;$color\">$d</td>";
            if (($mes->format('N') - 1 + $d) % 7 === 0 && $d < $n) $html .= '</tr><tr>';
        }
        $html .= '</tr></table>';
    }
    return '<div style="margin:0 0 16px;text-align:center">' . $html . '<div style="font-size:12px;color:#5d6b63">'
        . '<span style="display:inline-block;width:10px;height:10px;border-radius:3px;background:#2f5d46"></span> Noches &nbsp; '
        . '<span style="display:inline-block;width:10px;height:10px;border-radius:3px;background:#dcebe1"></span> Día de salida</div></div>';
}

function filas_reserva(array $r): array
{
    $noches = dias_entre($r['fecha_entrada'], $r['fecha_salida']);
    return [
        'Cabaña' => $r['cabana'],
        'Llegada' => fecha_larga($r['fecha_entrada']),
        'Salida' => fecha_larga($r['fecha_salida']),
        'Estadía' => "$noches " . ($noches === 1 ? 'noche' : 'noches') . " · {$r['personas']} " . ($r['personas'] == 1 ? 'persona' : 'personas'),
        'Total' => pesos($r['monto']),
    ];
}

function filas_transferencia(array $r): array
{
    $t = config()['transferencia'];
    return [
        'Banco' => $t['banco'],
        'Tipo de cuenta' => $t['tipo_cuenta'],
        'N° de cuenta' => $t['numero_cuenta'],
        'Titular' => $t['titular'],
        'RUT' => $t['rut'],
        'Correo' => $t['email'],
        'Comentario' => "Reserva N° {$r['id']}",
    ];
}

// Separados del envío para poder probarlos sin red.
function mensajes_reserva_nueva(array $r): array
{
    $pct = config()['abono_porcentaje'];
    $plazo = plazo_pago($r['creada_en']);
    $nombre = strtok($r['nombre'], ' ');
    $abono = ["Abono ($pct%)" => pesos($r['abono'])];
    return [
        correo(
            [config()['resend']['admin_email']],
            "🔔 Nueva solicitud N° {$r['id']}: {$r['nombre']}",
            'Nueva solicitud de reserva',
            "Las fechas quedaron apartadas hasta que la aceptes o rechaces. Plazo para el abono: $plazo.",
            [
                "Reserva N° {$r['id']}" => filas_reserva($r) + $abono,
                'Cliente' => [
                    'Nombre' => "{$r['nombre']} ({$r['ciudad']})",
                    'Teléfono' => $r['telefono'] . ($r['telefono2'] ? " / {$r['telefono2']}" : ''),
                    'Correo' => $r['email'] . ($r['email2'] ? " / {$r['email2']}" : ''),
                ],
            ],
            'Revísala en el panel de administración.',
            calendario_html($r['fecha_entrada'], $r['fecha_salida'])
        ),
        correo(
            [$r['email'], $r['email2']],
            "🏡 Recibimos tu solicitud de reserva N° {$r['id']}",
            'Recibimos tu solicitud',
            "Hola $nombre, tus fechas quedaron apartadas por 48 horas. Para confirmar, transfiere el abono antes del $plazo.",
            [
                "Tu reserva N° {$r['id']}" => filas_reserva($r) + $abono + ['Saldo al llegar' => pesos($r['monto'] - $r['abono'])],
                'Datos para transferencia' => filas_transferencia($r),
            ],
            'Si prefieres, puedes pagar el total de una vez. El abono no es reembolsable. Te avisaremos cuando confirmemos tu pago.'
        ),
    ];
}

function mensaje_decision(array $r): array
{
    $nombre = strtok($r['nombre'], ' ');
    if ($r['estado'] === 'aceptada') {
        $saldo = $r['monto'] - $r['pagado'];
        return correo(
            [$r['email'], $r['email2']],
            "✅ Tu reserva N° {$r['id']} está confirmada",
            '¡Reserva confirmada!',
            "Hola $nombre, recibimos tu pago. ¡Te esperamos!",
            ["Tu reserva N° {$r['id']}" => filas_reserva($r) + ['Pagado' => pesos($r['pagado'])]
                + ($saldo > 0 ? ['Saldo a pagar al llegar' => pesos($saldo)] : [])],
            $saldo > 0 ? '' : 'Tu estadía está pagada por completo.'
        );
    }
    return correo(
        [$r['email'], $r['email2']],
        "❌ Tu solicitud de reserva N° {$r['id']} fue anulada",
        'Solicitud anulada',
        "Hola $nombre, tu solicitud no pudo confirmarse y las fechas se liberaron.",
        ["Reserva N° {$r['id']}" => filas_reserva($r)],
        'Si aún quieres venir, puedes hacer una nueva solicitud en nuestra web.'
    );
}

function mensaje_recordatorio(array $r): array
{
    $nombre = strtok($r['nombre'], ' ');
    $plazo = plazo_pago($r['creada_en']);
    return correo(
        [$r['email'], $r['email2']],
        "⏰ Recordatorio: te quedan 24 horas para confirmar tu reserva N° {$r['id']}",
        'Te quedan 24 horas',
        "Hola $nombre, aún no recibimos tu abono. Transfiere al menos " . pesos($r['abono']) . " antes del $plazo o las fechas se liberarán.",
        [
            "Tu reserva N° {$r['id']}" => filas_reserva($r),
            'Datos para transferencia' => filas_transferencia($r),
        ],
        'Si ya transferiste, ignora este correo: te avisaremos cuando confirmemos tu pago.'
    );
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
