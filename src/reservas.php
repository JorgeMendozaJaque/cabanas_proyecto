<?php
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/ciudades.php';

const MAX_NOCHES = 30;
const MAX_DIAS_ANTICIPACION = 365;
// Estados que apartan fechas (opción B: la primera solicitud en llegar bloquea).
const ESTADOS_QUE_BLOQUEAN = "('pendiente','aceptada','pagada')";
// Noches libres entre reservas: la del día de salida y un día completo de limpieza y mantención.
// Si alguien se va el 26, el siguiente llega el 28 (y quien reserve antes debe irse a más tardar 2 días antes de la llegada).
const NOCHES_LIMPIEZA = 2;

function fecha_valida(string $f): bool
{
    return preg_match('/^\d{4}-\d{2}-\d{2}$/', $f)
        && checkdate((int) substr($f, 5, 2), (int) substr($f, 8, 2), (int) substr($f, 0, 4));
}

// Abono mínimo (porcentaje de config.php), redondeado hacia arriba al peso.
function abono(int $monto): int
{
    return intdiv($monto * config()['abono_porcentaje'] + 99, 100);
}

function pesos(int|string $n): string
{
    return '$' . number_format((int) $n, 0, ',', '.');
}

// "2026-11-14" → "14 de noviembre de 2026", sin pasar por zonas horarias.
function fecha_larga(string $f): string
{
    $meses = ['enero', 'febrero', 'marzo', 'abril', 'mayo', 'junio', 'julio', 'agosto', 'septiembre', 'octubre', 'noviembre', 'diciembre'];
    return (int) substr($f, 8, 2) . ' de ' . $meses[(int) substr($f, 5, 2) - 1] . ' de ' . substr($f, 0, 4);
}

// Hasta cuándo puede transferir el abono: 48 h desde que envió la solicitud.
function plazo_pago(string $creada_en): string
{
    return date('d-m-Y \a \l\a\s H:i', strtotime($creada_en . ' +48 hours'));
}

// ¿El dominio del correo puede recibir correos (MX, o A como respaldo)? Atrapa errores como "gmial.con".
// Si el DNS del servidor no responde, lo deja pasar para no bloquear reservas.
function dominio_recibe_correo(string $email): bool
{
    $dominio = substr(strrchr($email, '@'), 1) . '.'; // el punto final evita que se le agregue el dominio local
    return checkdnsrr($dominio, 'MX') || checkdnsrr($dominio, 'A') || !checkdnsrr('gmail.com.', 'MX');
}

// Sesión del cliente: guarda qué reserva puede ver en la página de confirmación.
function sesion_reserva(): void
{
    session_name('cabanas_reserva');
    session_set_cookie_params(['path' => '/', 'httponly' => true, 'samesite' => 'Lax', 'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off']);
    session_start();
}

function hoy_chile(): string
{
    return (new DateTimeImmutable('now', new DateTimeZone('America/Santiago')))->format('Y-m-d');
}

// Días entre dos fechas YYYY-MM-DD, en UTC para que el horario de verano no corra un día.
function dias_entre(string $desde, string $hasta): int
{
    $utc = new DateTimeZone('UTC');
    return (int) (new DateTimeImmutable($desde, $utc))->diff(new DateTimeImmutable($hasta, $utc))->format('%r%a');
}

function validar_reserva(array $r): ?string
{
    if (!$r['cabana_id']) return 'Elige una cabaña.';
    if (!$r['personas'] || $r['personas'] < 1) return 'Indica cuántas personas vienen.';
    // Letras de cualquier idioma (tildes, ñ), espacios, apóstrofo, punto y guion. Sin números.
    $letras = "/^\p{L}[\p{L} '.-]*$/u";
    if (mb_strlen($r['nombre']) < 2 || mb_strlen($r['nombre']) > 100) return 'Escribe tu nombre.';
    if (!preg_match($letras, $r['nombre'])) return 'El nombre solo puede tener letras.';
    if (!ciudad_valida($r['ciudad'])) return 'Elige tu ciudad de la lista.';

    $telefono = '/^\+?[0-9]{8,15}$/'; // máximo internacional: 15 dígitos
    if (!preg_match($telefono, $r['telefono'])) return 'El teléfono 1 no es válido.';
    if ($r['telefono2'] !== null && !preg_match($telefono, $r['telefono2'])) return 'El teléfono 2 no es válido.';

    foreach (['email' => 'El correo 1 no es válido.', 'email2' => 'El correo 2 no es válido.'] as $campo => $msg) {
        $v = $r[$campo];
        if (($campo === 'email' || $v !== null) && (strlen($v ?? '') > 254 || !filter_var($v, FILTER_VALIDATE_EMAIL))) return $msg;
    }

    if (!fecha_valida($r['fecha_entrada']) || !fecha_valida($r['fecha_salida'])) return 'Elige las fechas en el calendario.';
    $hoy = hoy_chile();
    if ($r['fecha_entrada'] < $hoy) return 'La fecha de llegada ya pasó.';
    $noches = dias_entre($r['fecha_entrada'], $r['fecha_salida']);
    if ($noches < 1) return 'La salida debe ser después de la llegada.';
    if ($noches > MAX_NOCHES) return 'La estadía máxima es de ' . MAX_NOCHES . ' noches.';
    if (dias_entre($hoy, $r['fecha_salida']) > MAX_DIAS_ANTICIPACION) return 'Solo se puede reservar hasta un año hacia adelante.';
    return null;
}

/**
 * Valida y guarda una solicitud como 'pendiente'.
 * Devuelve ['ok' => true, 'id', 'noches', 'monto', 'abono'] o ['ok' => false, 'error', 'ocupado'?].
 */
// Campo de texto de un formulario. Si llega como lista (nombre[]=x, vía Postman) vale '' y no "Array".
function campo_texto(array $datos, string $k): string
{
    return is_scalar($datos[$k] ?? null) ? trim((string) $datos[$k]) : '';
}

function crear_reserva(array $datos): array
{
    $texto = fn(string $k) => campo_texto($datos, $k);
    $r = [
        'cabana_id' => filter_var($datos['cabana_id'] ?? null, FILTER_VALIDATE_INT),
        'personas' => filter_var($datos['personas'] ?? null, FILTER_VALIDATE_INT),
        'nombre' => preg_replace('/ {2,}/', ' ', $texto('nombre')),
        'ciudad' => preg_replace('/ {2,}/', ' ', $texto('ciudad')),
        'telefono' => $texto('telefono'),
        'telefono2' => $texto('telefono2') ?: null,
        'email' => $texto('email'),
        'email2' => $texto('email2') ?: null,
        'fecha_entrada' => $texto('fecha_entrada'),
        'fecha_salida' => $texto('fecha_salida'),
    ];
    if ($error = validar_reserva($r)) return ['ok' => false, 'error' => $error];

    $db = db();
    // Tras esperar el bloqueo, cada consulta debe ver lo que la otra solicitud ya guardó.
    $db->exec('SET TRANSACTION ISOLATION LEVEL READ COMMITTED');
    $db->beginTransaction();
    try {
        // Bloquea la cabaña: si dos personas piden a la vez, la segunda espera aquí.
        $st = $db->prepare('SELECT capacidad, precio_noche FROM cabanas WHERE id = ? AND activa FOR UPDATE');
        $st->execute([$r['cabana_id']]);
        $cabana = $st->fetch();
        if (!$cabana) return ['ok' => false, 'error' => 'Esa cabaña no está disponible.'];
        if ($r['personas'] > $cabana['capacidad']) {
            return ['ok' => false, 'error' => "Esta cabaña es para máximo {$cabana['capacidad']} personas."];
        }

        $st = $db->prepare(
            'SELECT EXISTS(SELECT 1 FROM reservas WHERE cabana_id = ? AND estado IN ' . ESTADOS_QUE_BLOQUEAN . '
                 AND fecha_entrada < DATE_ADD(?, INTERVAL ' . NOCHES_LIMPIEZA . ' DAY)
                 AND DATE_ADD(fecha_salida, INTERVAL ' . NOCHES_LIMPIEZA . ' DAY) > ?)
             OR EXISTS(SELECT 1 FROM bloqueos WHERE cabana_id = ? AND fecha_inicio < ? AND fecha_fin >= ?)'
        );
        $st->execute([$r['cabana_id'], $r['fecha_salida'], $r['fecha_entrada'], $r['cabana_id'], $r['fecha_salida'], $r['fecha_entrada']]);
        if ($st->fetchColumn()) {
            return ['ok' => false, 'ocupado' => true, 'error' => 'Esas fechas ya no están disponibles. Elige otras en el calendario.'];
        }

        $noches = dias_entre($r['fecha_entrada'], $r['fecha_salida']);
        $r['monto'] = $noches * $cabana['precio_noche'];
        $r['abono'] = abono($r['monto']);
        $db->prepare(
            'INSERT INTO reservas (cabana_id, nombre, ciudad, telefono, telefono2, email, email2, fecha_entrada, fecha_salida, personas, monto, abono)
             VALUES (:cabana_id, :nombre, :ciudad, :telefono, :telefono2, :email, :email2, :fecha_entrada, :fecha_salida, :personas, :monto, :abono)'
        )->execute($r);
        $id = (int) $db->lastInsertId();
        $db->commit();
        return ['ok' => true, 'id' => $id, 'noches' => $noches, 'monto' => $r['monto'], 'abono' => $r['abono']];
    } finally {
        if ($db->inTransaction()) $db->rollBack();
    }
}

// Rangos ocupados o cerrados desde hoy, como noches [desde, hasta). Sin datos de clientes.
// Las reservas se amplían NOCHES_LIMPIEZA hacia ambos lados, igual que el chequeo de crear_reserva.
function dias_ocupados(int $cabana_id): array
{
    $n = NOCHES_LIMPIEZA;
    $st = db()->prepare(
        "SELECT DATE_SUB(fecha_entrada, INTERVAL $n DAY) AS desde, DATE_ADD(fecha_salida, INTERVAL $n DAY) AS hasta FROM reservas
           WHERE cabana_id = ? AND estado IN " . ESTADOS_QUE_BLOQUEAN . " AND DATE_ADD(fecha_salida, INTERVAL $n DAY) > ?
         UNION ALL
         SELECT fecha_inicio, DATE_ADD(fecha_fin, INTERVAL 1 DAY) FROM bloqueos
           WHERE cabana_id = ? AND fecha_fin >= ?
         ORDER BY desde"
    );
    $hoy = hoy_chile();
    $st->execute([$cabana_id, $hoy, $cabana_id, $hoy]);
    return $st->fetchAll();
}
