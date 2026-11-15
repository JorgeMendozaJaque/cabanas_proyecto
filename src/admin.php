<?php
require_once __DIR__ . '/reservas.php';

const PLAZO_PAGO_HORAS = 48; // desde que se envía la solicitud
// acción => [estado actual requerido, estado nuevo, columna que se guarda como "pagado"]
const TRANSICIONES = [
    'aceptar_abono' => ['pendiente', 'aceptada', 'abono'],
    'aceptar_total' => ['pendiente', 'aceptada', 'monto'],
    'rechazar' => ['pendiente', 'rechazada', null],
    'pagar_saldo' => ['aceptada', 'aceptada', 'monto'], // pagó el resto al llegar
];
const ESTADOS = ['pendiente', 'aceptada', 'rechazada'];

function iniciar_sesion(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) return;
    header('X-Frame-Options: DENY'); // que otra web no pueda cargar el panel escondido y hacer clic por el admin
    header('X-Content-Type-Options: nosniff');
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
                         aceptada_en = IF(? = \'aceptada\', COALESCE(aceptada_en, ?), aceptada_en) WHERE id = ? AND estado = ?');
    // Si ya había pagado todo, la fila no cambia y rowCount es 0: no se marca dos veces.
    $st->execute([$hasta, $hasta, ahora_chile(), $id, $desde]);
    return $st->rowCount() === 1;
}

const POR_PAGINA = 6; // reservas por lista en el panel

// WHERE de la lista del panel: estado y búsqueda por nombre, teléfono, correo o N° de reserva.
// La base compara sin mayúsculas ni tildes (utf8mb4_unicode_ci): "jose" encuentra "José".
function filtro_reservas(?string $estado, string $buscar): array
{
    $where = [];
    $params = [];
    if ($estado) {
        $where[] = 'r.estado = ?';
        $params[] = $estado;
    }
    if ($buscar !== '') {
        $like = '%' . addcslashes($buscar, '%_\\') . '%';
        $numero = ltrim($buscar, '#N° ');
        $where[] = '(r.nombre LIKE ? OR r.telefono LIKE ? OR r.telefono2 LIKE ? OR r.email LIKE ? OR r.email2 LIKE ? OR r.id = ?)';
        array_push($params, $like, $like, $like, $like, $like, ctype_digit($numero) ? (int) $numero : 0);
    }
    return [$where ? ' WHERE ' . implode(' AND ', $where) : '', $params];
}

// Una página de reservas (las más recientes primero). $pagina parte en 1.
function listar_reservas(?string $estado, int $pagina = 1, string $buscar = ''): array
{
    [$where, $params] = filtro_reservas($estado, $buscar);
    $desde = (max(1, $pagina) - 1) * POR_PAGINA;
    $st = db()->prepare('SELECT r.*, c.nombre AS cabana FROM reservas r JOIN cabanas c ON c.id = r.cabana_id' . $where
        . ' ORDER BY r.creada_en DESC, r.id DESC LIMIT ' . POR_PAGINA . " OFFSET $desde");
    $st->execute($params);
    return $st->fetchAll();
}

function contar_reservas(?string $estado, string $buscar = ''): int
{
    [$where, $params] = filtro_reservas($estado, $buscar);
    $st = db()->prepare('SELECT COUNT(*) FROM reservas r' . $where);
    $st->execute($params);
    return (int) $st->fetchColumn();
}

// Corrige personas y datos de contacto de una reserva (nunca fechas, montos ni estado).
// Devuelve null si se guardó, o el mensaje de error.
function editar_contacto(int $id, array $datos): ?string
{
    $r = leer_contacto($datos);
    if ($error = validar_contacto($r)) return $error;
    $st = db()->prepare('SELECT c.capacidad FROM reservas r JOIN cabanas c ON c.id = r.cabana_id WHERE r.id = ?');
    $st->execute([$id]);
    $capacidad = $st->fetchColumn();
    if ($capacidad === false) return 'Esa reserva no existe.';
    if ($r['personas'] > $capacidad) return "Esta cabaña es para máximo $capacidad personas.";
    db()->prepare('UPDATE reservas SET personas = :personas, nombre = :nombre, ciudad = :ciudad, telefono = :telefono,
                   telefono2 = :telefono2, email = :email, email2 = :email2 WHERE id = :id')->execute($r + ['id' => $id]);
    return null;
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
        // shortcut: reparte el monto en partes iguales por noche; con un precio especial, una estadía entre dos meses
        // queda aproximada por mes (el total del año sí es exacto). Guardar el precio de cada noche si se necesita exacto.
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
    $inicio = campo_texto($datos, 'fecha_inicio');
    $fin = campo_texto($datos, 'fecha_fin');
    $motivo = campo_texto($datos, 'motivo') ?: null;

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

// Precio escrito por el admin: acepta "80.000", "$80.000" o "80000". Devuelve null si no es válido.
function leer_precio(array $datos, string $k): ?int
{
    $limpio = preg_replace('/[\s.$]/', '', campo_texto($datos, $k));
    $precio = filter_var($limpio, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1000, 'max_range' => 10_000_000]]);
    return $precio === false ? null : $precio;
}

/** Cambia el precio general de una cabaña. Solo afecta a las reservas nuevas. */
function cambiar_precio_base(array $datos): ?string
{
    $cabana_id = filter_var($datos['cabana_id'] ?? null, FILTER_VALIDATE_INT);
    $precio = leer_precio($datos, 'precio');
    if (!$cabana_id) return 'Elige una cabaña.';
    if ($precio === null) return 'Escribe un precio entre $1.000 y $10.000.000.';
    $st = db()->prepare('SELECT 1 FROM cabanas WHERE id = ?');
    $st->execute([$cabana_id]);
    if (!$st->fetchColumn()) return 'Esa cabaña no existe.';
    db()->prepare('UPDATE cabanas SET precio_noche = ? WHERE id = ?')->execute([$precio, $cabana_id]);
    return null;
}

/** Crea un precio especial por fechas. Devuelve null si se guardó, o el mensaje de error. */
function crear_precio(array $datos): ?string
{
    $cabana_id = filter_var($datos['cabana_id'] ?? null, FILTER_VALIDATE_INT);
    $inicio = campo_texto($datos, 'fecha_inicio');
    $fin = campo_texto($datos, 'fecha_fin');
    $precio = leer_precio($datos, 'precio');
    $motivo = campo_texto($datos, 'motivo') ?: null;

    if (!$cabana_id) return 'Elige una cabaña.';
    if (!fecha_valida($inicio) || !fecha_valida($fin)) return 'Elige las fechas.';
    if ($fin < $inicio) return 'La fecha "hasta" no puede ser anterior a "desde".';
    if ($fin < hoy_chile()) return 'Esas fechas ya pasaron.';
    if ($precio === null) return 'Escribe un precio entre $1.000 y $10.000.000.';
    if ($motivo !== null && mb_strlen($motivo) > 200) return 'El nombre es muy largo (máximo 200 caracteres).';

    $db = db();
    $db->exec('SET TRANSACTION ISOLATION LEVEL READ COMMITTED');
    $db->beginTransaction();
    try {
        $st = $db->prepare('SELECT id FROM cabanas WHERE id = ? FOR UPDATE');
        $st->execute([$cabana_id]);
        if (!$st->fetch()) return 'Esa cabaña no existe.';
        // Sin cruces: cada noche tiene un solo precio especial posible.
        $st = $db->prepare('SELECT fecha_inicio, fecha_fin FROM precios WHERE cabana_id = ? AND fecha_inicio <= ? AND fecha_fin >= ? LIMIT 1');
        $st->execute([$cabana_id, $fin, $inicio]);
        if ($cruce = $st->fetch()) {
            return 'Se cruza con otro precio especial (del ' . fecha_larga($cruce['fecha_inicio']) . ' al ' . fecha_larga($cruce['fecha_fin']) . '). Bórralo o elige otras fechas.';
        }
        $db->prepare('INSERT INTO precios (cabana_id, fecha_inicio, fecha_fin, precio_noche, motivo) VALUES (?, ?, ?, ?, ?)')
            ->execute([$cabana_id, $inicio, $fin, $precio, $motivo]);
        $db->commit();
        return null;
    } finally {
        if ($db->inTransaction()) $db->rollBack();
    }
}

function borrar_precio(int $id): bool
{
    $st = db()->prepare('DELETE FROM precios WHERE id = ?');
    $st->execute([$id]);
    return $st->rowCount() === 1;
}

// Reservas activas que aún no terminan, como [cabana_id, entrada, salida], para el mini calendario de Precios.
function reservas_activas_futuras(): array
{
    $st = db()->prepare('SELECT cabana_id, fecha_entrada, fecha_salida FROM reservas WHERE estado IN ' . ESTADOS_QUE_BLOQUEAN . ' AND fecha_salida > ?');
    $st->execute([hoy_chile()]);
    return $st->fetchAll(PDO::FETCH_NUM);
}

// Todos los precios especiales, también los que ya pasaron, como [cabana_id, inicio, fin, precio], para el mini calendario.
function precios_para_calendario(): array
{
    return db()->query('SELECT cabana_id, fecha_inicio, fecha_fin, precio_noche FROM precios ORDER BY fecha_inicio')->fetchAll(PDO::FETCH_NUM);
}

// Precios especiales que aún no terminan, para el panel (con su nombre).
function listar_precios(): array
{
    $st = db()->prepare('SELECT p.*, c.nombre AS cabana, c.precio_noche AS precio_base FROM precios p JOIN cabanas c ON c.id = p.cabana_id
                         WHERE p.fecha_fin >= ? ORDER BY p.fecha_inicio');
    $st->execute([hoy_chile()]);
    return $st->fetchAll();
}

function borrar_bloqueo(int $id): bool
{
    $st = db()->prepare('DELETE FROM bloqueos WHERE id = ?');
    $st->execute([$id]);
    return $st->rowCount() === 1;
}

// Días cerrados que tocan el mes 'YYYY-MM', con su motivo (solo para el panel).
function bloqueos_del_mes(string $mes): array
{
    $inicio = "$mes-01";
    $st = db()->prepare('SELECT b.*, c.nombre AS cabana FROM bloqueos b JOIN cabanas c ON c.id = b.cabana_id
                         WHERE b.fecha_inicio <= ? AND b.fecha_fin >= ? ORDER BY b.fecha_inicio');
    $st->execute([date('Y-m-t', strtotime($inicio)), $inicio]);
    return $st->fetchAll();
}

function listar_bloqueos(): array
{
    $st = db()->prepare('SELECT b.*, c.nombre AS cabana FROM bloqueos b JOIN cabanas c ON c.id = b.cabana_id
                         WHERE b.fecha_fin >= ? ORDER BY b.fecha_inicio');
    $st->execute([hoy_chile()]);
    return $st->fetchAll();
}
