# Plan: Web de reservas de cabañas

Contexto para retomar el trabajo en una sesión nueva. Leer completo antes de empezar.

## Qué es

Una web donde los clientes piden reservas de cabañas familiares y un admin las acepta o rechaza desde un panel privado. Cada decisión le llega al cliente por correo. El pago es siempre por transferencia bancaria, fuera del sistema. No hay precios dinámicos ni pagos en línea.

## Stack (decidido)

- **HTML, CSS y JavaScript** sin frameworks para las páginas.
- **PHP** en el servidor: recibe el formulario, valida, guarda y maneja el panel admin.
- **MySQL** para los datos, con acceso desde PHP vía PDO.
- **Resend** para los correos. PHP llama a su API HTTP con `curl`, sin instalar SDK ni Composer.
- **Por ahora todo corre en local.** Se trabaja en dos equipos: un Mac (PHP y MySQL con Homebrew) y un PC Windows (PHP y MariaDB con winget). Ver "Estado actual".
- Hosting: **por definir**, se elige en la Parte 5. Debe tener PHP 8 y MySQL 8, y permitir conexiones HTTPS salientes, que Resend necesita (varios hostings gratuitos las bloquean). Gasto fijo: dominio `.cl` y, probablemente, el hosting.

### Qué es Resend

Un servicio para enviar correos desde código. Cuando pasa algo (una reserva nueva, una aceptada), PHP le envía a Resend el destinatario, el asunto y el texto, junto con una clave secreta (API key). Resend se encarga de que el correo llegue y no caiga en spam. El plan gratuito alcanza de sobra para este proyecto.

## Requisitos funcionales

1. Formulario público con: cabaña, personas (parte en blanco, obligatorio), nombre, ciudad (desplegable de regiones y ciudades de Chile; con el botón "Soy extranjero" cambia a países del mundo), teléfono 1, teléfono 2 (opcional), correo 1 y correo 2 (opcional), más un calendario de la cabaña elegida donde se ven los días disponibles y se marcan la entrada y la salida.
2. **La primera solicitud en llegar aparta las fechas** (opción B, decidida). Al enviarla, las fechas quedan bloqueadas y nadie más puede pedirlas. No se aceptan fechas que choquen con una reserva `pendiente`, `aceptada` o `pagada`, ni con días cerrados por el admin.
3. Login de admin.
4. Panel con la lista de reservas, filtrable por estado.
5. **Abono:** para confirmar, el cliente transfiere al menos el 50% (porcentaje en `config.php`) dentro de 48 h desde que envía la solicitud, o el total si prefiere. El saldo se paga al llegar. **El abono no es reembolsable**: es la garantía.
5b. Botones del panel, solo para pendientes: **Aceptar · abono**, **Aceptar · pago total** (se guarda cuánto pagó, para saber el saldo al llegar) y **Rechazar** (si no llega el abono en 48 h o por otro motivo).
6. **Cierre de días para mantenimiento:** el admin cierra un rango de días de una cabaña (con un motivo opcional) y puede volver a abrirlo. No se puede cerrar un rango que ya tenga reservas `pendiente`, `aceptada` o `pagada`.
7. Correos, todos con Resend. Los del cliente van al correo 1 y, si lo dejó, también al correo 2:
   - **Al admin:** cuando entra una solicitud nueva.
   - **Al cliente, solicitud recibida:** confirma que la solicitud llegó y que las fechas quedaron apartadas mientras se revisa.
   - **Al cliente, solicitud recibida:** datos de transferencia, abono, saldo y plazo de 48 h (lo mismo que la página de confirmación).
   - **Al cliente, aceptada:** reserva confirmada, cuánto pagó y el saldo a pagar al llegar.
   - **Al cliente, rechazada:** las fechas se liberaron.
8. **Pestaña Calendario** del panel: vista por mes con un color por cliente, "Llega" / "Se va" en cada día, el día de limpieza marcado y la lista "Quién viene" arriba. Letra grande: el admin es una persona mayor.
9. **Pestaña Ganancias** del panel: el año como 12 casillas con el total de cada mes y el total anual. Al tocar un mes se ve el detalle por reserva (cuánto aportó y saldo por cobrar). Solo cuentan las reservas aceptadas, y cada noche suma al mes en que se duerme.

No funcionales: debe funcionar bien en el celular, tener costo mínimo, permitir agregar cabañas o admins sin cambiar código, y mantener el panel protegido sin exponer datos personales.

## Estructura de carpetas

- `public/`: lo único que el servidor web expone (páginas, CSS, JS y los PHP de entrada).
- `src/`: conexión a la base, funciones compartidas y envío de correos. No accesible desde la web.
- `config.php`: claves y datos sensibles, fuera de `public/`, y no se sube al repo. Se sube un `config.example.php` sin valores reales.
- `sql/schema.sql`: tablas y datos iniciales.

## Modelo de datos

**cabanas**: `id int auto_increment pk`, `nombre varchar`, `capacidad int`, `precio_noche int` (CLP), `activa boolean`.

**reservas**: `id int auto_increment pk`, `cabana_id int fk`, `nombre`, `ciudad`, `telefono` y `email varchar`, `telefono2` y `email2 varchar null` (opcionales), `fecha_entrada date`, `fecha_salida date` (no cuenta como noche), `personas int`, `monto int` (noches × precio_noche, en CLP), `estado enum('pendiente','aceptada','rechazada','pagada','cancelada')`, `creada_en timestamp`.

**bloqueos** (días cerrados por el admin): `id int auto_increment pk`, `cabana_id int fk`, `fecha_inicio date`, `fecha_fin date` (ambos días cerrados, inclusive), `motivo varchar null`, `creado_en timestamp`. Reabrir días = borrar el bloqueo.

**admins**: `id int auto_increment pk`, `email varchar unique`, `password_hash varchar`. Las contraseñas se guardan con `password_hash()` de PHP, nunca en texto plano.

## Estados

`pendiente` → `aceptada` (llegó el abono o el total)
`pendiente` → `rechazada` (no llegó el abono en 48 h u otro motivo; libera las fechas)

Bloquean fechas: `pendiente` y `aceptada`. Las libera: `rechazada`. El rechazo por falta de pago lo hace el admin a mano; el panel marca en rojo las pendientes con el plazo vencido. Los valores `pagada` y `cancelada` siguen en el ENUM de la base pero ya no se usan.

## Regla de choque de fechas

Una reserva ocupa las noches desde `fecha_entrada` hasta el día anterior a `fecha_salida`. **Después de cada salida hay un día completo de limpieza y mantención:** entre una reserva y otra quedan 2 noches libres (`NOCHES_LIMPIEZA = 2` en `src/reservas.php`). Si alguien se va el 26, el siguiente llega el 28; y quien reserve antes debe irse a más tardar 2 días antes de la llegada del otro.

- **Choca con otra reserva** de la misma cabaña si `nueva_entrada < salida_existente + 2 AND nueva_salida + 2 > entrada_existente`.
- **Choca con un bloqueo** de la misma cabaña si `nueva_entrada <= fecha_fin AND nueva_salida > fecha_inicio`, porque el bloqueo incluye su último día.

La regla se aplica en 2 lugares:

1. **El calendario del formulario** muestra los días ocupados (con la limpieza incluida) y cerrados, y no deja elegirlos. Es solo ayuda visual; el navegador no es de confianza.
2. **El PHP que guarda la solicitud** es el control real. MySQL no tiene restricciones de exclusión como PostgreSQL, así que se hace en una transacción: `SELECT ... FROM cabanas WHERE id = ? FOR UPDATE` (bloquea esa cabaña), luego se buscan choques con reservas `pendiente`/`aceptada`/`pagada` y con bloqueos y, si no hay, se inserta. Si dos personas envían la misma fecha al mismo tiempo, la segunda espera a que termine la primera y recibe "fechas no disponibles". Así nunca hay overbooking. Las tablas deben ser InnoDB.

El cierre de días del admin usa la misma transacción: bloquea la cabaña y revisa que no haya reservas en ese rango antes de guardar.

## Decisiones de diseño

1. **No hay registro público.** Los admins se crean con un script PHP de línea de comandos (`php crear_admin.php email`) que pide la contraseña y guarda el hash.
2. **El público no toca la base directamente.** El formulario envía los datos a un PHP que valida todo y guarda. El **monto lo calcula el servidor**, nunca el navegador.
3. **Consultas siempre con sentencias preparadas de PDO** (`?` o `:nombre`), nunca concatenando texto del usuario. Evita la inyección SQL.
4. **Restricciones en la base:** `CHECK (fecha_salida > fecha_entrada)` y `CHECK (personas > 0)` (MySQL 8.0.16+), y una clave foránea a `cabanas`.
5. **Validar personas:** `personas <= capacidad` de la cabaña, en PHP.
6. **Fechas como texto `YYYY-MM-DD`.** En JS no pasarlas por `new Date()`, porque en Chile se corren un día. En PHP, para contar noches usar `DateTimeImmutable` con zona `UTC`.
7. **Panel protegido:** sesiones de PHP con `session_regenerate_id()` al hacer login, cookie `HttpOnly`, `Secure` y `SameSite=Lax`, y un token CSRF en cada botón del panel. Toda salida de datos pasa por `htmlspecialchars()`.
8. **Resend sin dominio verificado** solo envía correos al email del dueño de la cuenta. Sirve para desarrollar; los clientes reciben correos solo cuando el dominio esté verificado.
9. **Datos de transferencia** (banco, RUT, cuenta), email del admin, credenciales de MySQL y API key de Resend van en `config.php`.
10. La disponibilidad pública devuelve solo rangos ocupados o cerrados, nunca nombres, correos ni el motivo del cierre.
11. **Riesgo de la opción B:** como una solicitud aparta fechas sin pagar, alguien podría bloquear días con solicitudes falsas. Se mitiga con el captcha (Parte 5) y con el botón Rechazar, que las libera.
13. **Tipo de texto por campo** (se filtra al escribir, lo valida el HTML y lo vuelve a validar el servidor): nombre solo letras (con tildes y ñ), espacios, apóstrofo, punto y guion, de 2 a 100 caracteres; ciudad debe ser una de la lista `CIUDADES` o un país de `PAISES` (`src/ciudades.php`; el país se guarda en la misma columna `ciudad`); teléfonos solo números, de 8 a 15 dígitos, con `+` opcional al inicio y sin espacios; correos sin espacios, máximo 254; personas solo enteros.
12. **Calendario hecho a mano** en JS (una grilla por mes), sin librerías. El `<input type="date">` del navegador no permite marcar días deshabilitados.

## Plan por partes

Se avanza parte por parte. Cada una termina con algo que se puede probar, y el usuario la revisa antes de seguir.

| Parte | Qué hace Claude | Qué hace el usuario |
|---|---|---|
| **1. Base** | Estructura de carpetas, `.gitignore`, `sql/schema.sql`, `config.example.php`, conexión PDO | Encender MySQL (`brew services start mysql`), crear la base y ejecutar el SQL |
| **2. Formulario** | PHP que valida y guarda (con el bloqueo de la cabaña), PHP de disponibilidad, formulario con calendario, CSS para celular | Cargar cabañas y probar reservas que chocan |
| **3. Panel admin** | Login, script para crear admins, lista con filtro, botones Aceptar, Rechazar, Pagada y Cancelar, cierre y reapertura de días | Crear su usuario admin con el script |
| **4. Correos** | Los 4 correos con Resend (nueva al admin; recibida, aceptada y rechazada al cliente) | Crear la cuenta en Resend y obtener la API key |
| **5. Publicación** | Captcha (Cloudflare Turnstile), guía de subida al hosting | Elegir hosting, dominio y DNS |

En desarrollo se usa el servidor que trae PHP: `php -S localhost:8000 -t public`.

Queda fuera, a propósito: precios por temporada, cancelación automática, WhatsApp y pago en línea.

## Estado actual

- Repo git, rama `main`, con remoto en GitHub: https://github.com/JorgeMendozaJaque/cabanas_proyecto. Copias en el Mac (`~/Desktop/entorno_prueba/cabanas-proyecto`) y en el PC Windows (`C:\Users\hahaha\cabanas_proyecto`). Antes de trabajar en un equipo, `git pull`; al terminar, commit y `git push`.
- Reglas del agente en `AGENTS.md`: modo Ponytail, mínimo código necesario y respuestas en español.
- **Mac:** instalados con Homebrew PHP 8.5 (con `pdo_mysql`, `curl` y `mbstring`) y MySQL. Composer no está y no hace falta.
- **PC Windows:** PHP 8.4 y MariaDB 13 instalados con winget; `php.ini` creado con `pdo_mysql` y `mbstring`. MariaDB no quedó como servicio (falta una terminal de administrador), así que tras reiniciar el PC hay que levantarlo: `& "C:\Program Files\MariaDB 13.0\bin\mariadbd.exe" --defaults-file="C:\Program Files\MariaDB 13.0\data\my.ini"`. Base `cabanas` creada desde `sql/schema.sql` y `config.php` copiado del ejemplo (`root` sin clave). En Windows `crear_admin.php` muestra la contraseña al escribirla (no existe `stty`).
- **Parte 1 lista y probada:** MySQL corre con `brew services` (usuario `root` sin clave, solo local). Base `cabanas` creada desde `sql/schema.sql`, `config.php` local creado, conexión PDO y restricciones de la base verificadas. Cargada "Cabaña 1" (6 personas, $80.000 por noche); la segunda se agrega con el `INSERT` comentado en `sql/schema.sql`.
- **Parte 2 lista y probada, esperando revisión del usuario:** `src/reservas.php` (validación, transacción con bloqueo de la cabaña, disponibilidad), `public/guardar-reserva.php`, `public/disponibilidad.php`, `public/reserva.php` (formulario: primero los datos, después cabaña y fechas; lado a lado desde 900 px de ancho), `public/app.js` y `public/estilos.css`.
- **Landing** en `public/index.html`: HTML estático con espacios vacíos para fotos reales (instrucciones en un comentario al inicio del archivo; las fotos van en `public/fotos/`) y el botón "Reserva aquí" al final, que lleva a `reserva.php`. Prueba: `php tests/reservas_test.php`. Límites agregados: máximo 30 noches por reserva y hasta 365 días hacia adelante.
- **Parte 3 lista y probada, esperando revisión del usuario:** `src/admin.php` (sesión, login, CSRF, cambios de estado, días cerrados), `crear_admin.php` (crea un admin o cambia su contraseña), y `public/admin/` (`login.php`, `index.php`, `accion.php`, `bloqueo.php`, `salir.php`). Se agregó la columna `reservas.aceptada_en` para mostrar el plazo de pago de 48 h. Prueba: `php tests/admin_test.php`.
- El usuario debe crear su admin: `php crear_admin.php su@correo.cl`.
- **Página de confirmación** (`public/confirmacion.php`): al reservar, el cliente pasa a una página con el resumen, los datos de transferencia (desde `config.php`, por ahora genéricos) y "Volver al inicio". Solo la ve quien hizo la reserva (se guarda en su sesión, no va el id en la URL). Indica transferir al recibir la confirmación por correo.
- **Abono del 50%** implementado: columnas `reservas.abono` y `reservas.pagado`, `abono_porcentaje` en `config.php`, confirmación con abono/saldo/política, panel con Aceptar · abono / Aceptar · pago total / Rechazar. Las pruebas usan una cabaña temporal propia para no chocar con datos reales.
- **Mejoras del panel y del formulario (13-11-2026):** pestañas Calendario y Ganancias (`reservas_del_mes()` y `ganancias_del_ano()` en `src/admin.php`), día de limpieza entre reservas, personas en blanco, ciudad por lista con "Soy extranjero" (`src/ciudades.php`). Todo con pruebas en `tests/`.
- Los IDs de reservas tienen huecos porque las pruebas crean y borran filas en la misma base. Es normal; si molesta, se puede usar una base aparte para pruebas.
- **Siguiente paso: Parte 4 (correos con Resend).** El usuario debe crear la cuenta en Resend y pasar la API key.
