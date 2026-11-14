<?php
require_once __DIR__ . '/reservas.php';

const PLAZO_PAGO_HORAS = 48; // desde que se envía la solicitud
// acción => [estado actual requerido, estado nuevo, columna que se guarda como "pagado"]
const TRANSICIONES = [
    'aceptar_abono' => ['pendiente', 'aceptada', 'abono'],
    'aceptar_total' => ['pendiente', 'aceptada', 'monto'],
    'rechazar' => ['pendiente', 'rechazada', null],
];
const ESTADOS = ['pendiente', 'aceptada', 'rechazada'];

function iniciar_sesion(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) return;
    session_name('cabanas_admin');
    session_set_cookie_params([
        'path' => '/',
        'httponly' => true,
        'samesite' => 'Lax',
        'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
    ]);
    session_start();
}

function exigir_admin(): array
{
    iniciar_sesion();
    if (empty($_SESSION['admin'])) {
        header('Location: login.php');
        exit;
    }
    return $_SESSION['admin'];
}

function token_csrf(): string
{
    iniciar_sesion();
    return $_SESSION['csrf'] ??= bin2hex(random_bytes(32));
}

function exigir_post_csrf(): void
{
    if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !hash_equals(token_csrf(), (string) ($_POST['csrf'] ?? ''))) {
        http_response_code(400);
        exit('Solicitud inválida. Vuelve atrás y recarga la página.');
    }
}

function avisar(string $texto, string $tipo = 'ok'): void
{
    $_SESSION['aviso'] = [$texto, $tipo];
}

function tomar_aviso(): ?array
{
    $aviso = $_SESSION['aviso'] ?? null;
    unset($_SESSION['aviso']);
    return $aviso;
}

function iniciar_admin(string $email, string $clave): bool
{
    $st = db()->prepare('SELECT id, email, password_hash FROM admins WHERE email = ?');
    $st->execute([mb_strtolower(trim($email))]);
    $admin = $st->fetch();
    // Se verifica aunque el correo no exista, para no revelar por el tiempo de respuesta qué correos son admin.
    $ok = password_verify($clave, $admin['password_hash'] ?? password_hash('x', PASSWORD_DEFAULT)) && $admin;
    if (!$ok) return false;
    iniciar_sesion();
    session_regenerate_id(true);
    $_SESSION['admin'] = ['id' => $admin['id'], 'email' => $admin['email']];
    $_SESSION['csrf'] = bin2hex(random_bytes(32));
    return true;
}

function cerrar_sesion_admin(): void
{
    iniciar_sesion();
    $_SESSION = [];
    session_destroy();
}

function ahora_chile(): string
{
    return (new DateTimeImmutable('now', new DateTimeZone('America/Santiago')))->format('Y-m-d H:i:s');
}

// Cambia el estado solo si la reserva está en el estado esperado. Devuelve false si no correspondía.
function cambiar_estado(int $id, string $accion): bool
{
    if (!isset(TRANSICIONES[$accion])) return false;
    [$desde, $hasta, $pagado] = TRANSICIONES[$accion];
    // $pagado sale de la constante (abono o monto), nunca del usuario.
    $st = db()->prepare('UPDATE reservas SET estado = ?, pagado = ' . ($pagado ?? 'pagado') . ',
                         aceptada_en = IF(? = \'aceptada\', ?, aceptada_en) WHERE id = ? AND estado = ?');
    $st->execute([$hasta, $hasta, ahora_chile(), $id, $desde]);
    return $st->rowCount() === 1;
}

function listar_reservas(?string $estado): array
{
    $sql = 'SELECT r.*, c.nombre AS cabana FROM reservas r JOIN cabanas c ON c.id = r.cabana_id';
    $params = [];
    if ($estado) {
        $sql .= ' WHERE r.estado = ?';
        $params[] = $estado;
    }
    // shortcut: muestra las 300 más recientes, sin paginar; paginar si el volumen crece.
    $st = db()->prepare($sql . ' ORDER BY r.creada_en DESC, r.id DESC LIMIT 300');
    $st->execute($params);
    return $st->fetchAll();
}

// Reservas activas que tocan el mes 'YYYY-MM', incluido el día de limpieza que sigue a la salida.
function reservas_del_mes(string $mes): array
{
    $inicio = "$mes-01";
    $st = db()->prepare('SELECT r.id, r.nombre, r.estado, r.personas, r.telefono, r.fecha_entrada, r.fecha_salida, c.nombre AS cabana
                         FROM reservas r JOIN cabanas c ON c.id = r.cabana_id
                         WHERE r.estado IN ' . ESTADOS_QUE_BLOQUEAN . ' AND r.fecha_entrada <= ? AND DATE_ADD(r.fecha_salida, INTERVAL 1 DAY) >= ?
                         ORDER BY r.fecha_entrada, r.id');
    $st->execute([date('Y-m-t', strtotime($inicio)), $inicio]);
    return $st->fetchAll();
}

/**
 * Ganancias de un año por mes (1..12 => monto, noches, reservas), solo reservas aceptadas.
 * Cada noche suma al mes en que se duerme, así una estadía entre dos meses se reparte.
 */
function ganancias_del_ano(int $ano): array
{
    $meses = array_fill(1, 12, ['monto' => 0, 'noches' => 0, 'reservas' => 0, 'detalle' => []]);
    $st = db()->prepare("SELECT r.id, r.nombre, r.fecha_entrada, r.fecha_salida, r.monto, r.pagado, c.nombre AS cabana
                         FROM reservas r JOIN cabanas c ON c.id = r.cabana_id
                         WHERE r.estado IN ('aceptada','pagada') AND r.fecha_entrada < ? AND r.fecha_salida > ?
                         ORDER BY r.fecha_entrada, r.id");
    $st->execute([($ano + 1) . '-01-01', "$ano-01-01"]);
    foreach ($st->fetchAll() as $r) {
        $noches = dias_entre($r['fecha_entrada'], $r['fecha_salida']);
        $porNoche = intdiv($r['monto'], $noches);
        $resto = $r['monto'] - $porNoche * $noches; // si no divide exacto, el sobrante va a la primera noche
        $f = new DateTimeImmutable($r['fecha_entrada'], new DateTimeZone('UTC'));
        for ($i = 0; $i < $noches; $i++, $f = $f->modify('+1 day')) {
            if ((int) $f->format('Y') !== $ano) continue;
            $m = (int) $f->format('n');
            $suma = $porNoche + ($i === 0 ? $resto : 0);
            $meses[$m]['monto'] += $suma;
            $meses[$m]['noches']++;
            // Detalle por reserva: cuánto y cuántas noches aportó a este mes.
            $meses[$m]['detalle'][$r['id']] ??= $r + ['noches_mes' => 0, 'monto_mes' => 0];
            $meses[$m]['detalle'][$r['id']]['noches_mes']++;
            $meses[$m]['detalle'][$r['id']]['monto_mes'] += $suma;
        }
    }
    foreach ($meses as &$mes) $mes['reservas'] = count($mes['detalle']);
    return $meses;
}

function contar_por_estado(): array
{
    return db()->query('SELECT estado, COUNT(*) FROM reservas GROUP BY estado')->fetchAll(PDO::FETCH_KEY_PAIR);
}

/** Cierra días de una cabaña. Devuelve null si se guardó, o el mensaje de error. */
function crear_bloqueo(array $datos): ?string
{
    $cabana_id = filter_var($datos['cabana_id'] ?? null, FILTER_VALIDATE_INT);
    $inicio = trim((string) ($datos['fecha_inicio'] ?? ''));
    $fin = trim((string) ($datos['fecha_fin'] ?? ''));
    $motivo = trim((string) ($datos['motivo'] ?? '')) ?: null;

    if (!$cabana_id) return 'Elige una cabaña.';
    if (!fecha_valida($inicio) || !fecha_valida($fin)) return 'Elige las fechas.';
    if ($fin < $inicio) return 'La fecha "hasta" no puede ser anterior a "desde".';
    if ($fin < hoy_chile()) return 'Esas fechas ya pasaron.';
    if ($motivo !== null && mb_strlen($motivo) > 200) return 'El motivo es muy largo (máximo 200 caracteres).';

    $db = db();
    $db->exec('SET TRANSACTION ISOLATION LEVEL READ COMMITTED');
    $db->beginTransaction();
    try {
        // Mismo bloqueo que al reservar: nadie puede guardar una reserva en esta cabaña mientras se revisa.
        $st = $db->prepare('SELECT id FROM cabanas WHERE id = ? FOR UPDATE');
        $st->execute([$cabana_id]);
        if (!$st->fetch()) return 'Esa cabaña no existe.';

        $st = $db->prepare('SELECT COUNT(*) FROM reservas WHERE cabana_id = ? AND estado IN ' . ESTADOS_QUE_BLOQUEAN . '
                            AND fecha_entrada <= ? AND fecha_salida > ?');
        $st->execute([$cabana_id, $fin, $inicio]);
        if ($n = (int) $st->fetchColumn()) {
            return "No se puede cerrar: hay $n " . ($n === 1 ? 'reserva activa' : 'reservas activas') . ' en esas fechas. Recházala o cancélala primero.';
        }

        $db->prepare('INSERT INTO bloqueos (cabana_id, fecha_inicio, fecha_fin, motivo) VALUES (?, ?, ?, ?)')
            ->execute([$cabana_id, $inicio, $fin, $motivo]);
        $db->commit();
        return null;
    } finally {
        if ($db->inTransaction()) $db->rollBack();
    }
}

function borrar_bloqueo(int $id): bool
{
    $st = db()->prepare('DELETE FROM bloqueos WHERE id = ?');
    $st->execute([$id]);
    return $st->rowCount() === 1;
}

function listar_bloqueos(): array
{
    $st = db()->prepare('SELECT b.*, c.nombre AS cabana FROM bloqueos b JOIN cabanas c ON c.id = b.cabana_id
                         WHERE b.fecha_fin >= ? ORDER BY b.fecha_inicio');
    $st->execute([hoy_chile()]);
    return $st->fetchAll();
}
