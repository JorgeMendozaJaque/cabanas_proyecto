# Plan: Web de reservas de cabañas

Contexto para retomar el trabajo en una sesión nueva. Leer completo antes de empezar.

## Qué es

Una web donde los clientes piden reservas de cabañas familiares y un admin las acepta o rechaza desde un panel privado. Cada decisión le llega al cliente por correo. El pago es siempre por transferencia bancaria, fuera del sistema. No hay precios dinámicos ni pagos en línea.

## Stack (decidido)

- **HTML, CSS y JavaScript** sin frameworks para las páginas.
- **PHP** en el servidor: recibe el formulario, valida, guarda y maneja el panel admin.
- **MySQL** para los datos, con acceso desde PHP vía PDO.
- **Resend** para los correos. PHP llama a su API HTTP con `curl`, sin instalar SDK ni Composer.
- **Por ahora todo corre en local** (PHP y MySQL en el Mac). La base de datos todavía no está lista y el usuario avisa cuando lo esté.
- Hosting: **por definir**, se elige en la Parte 5. Debe tener PHP 8 y MySQL 8, y permitir conexiones HTTPS salientes, que Resend necesita (varios hostings gratuitos las bloquean). Gasto fijo: dominio `.cl` y, probablemente, el hosting.

### Qué es Resend

Un servicio para enviar correos desde código. Cuando pasa algo (una reserva nueva, una aceptada), PHP le envía a Resend el destinatario, el asunto y el texto, junto con una clave secreta (API key). Resend se encarga de que el correo llegue y no caiga en spam. El plan gratuito alcanza de sobra para este proyecto.

## Requisitos funcionales

1. Formulario público con cabaña, fecha de entrada, fecha de salida, personas, nombre, email y teléfono.
2. Validación de disponibilidad: no se aceptan fechas que choquen con una reserva `aceptada` o `pagada`.
3. Login de admin.
4. Panel con la lista de reservas, filtrable por estado.
5. Botones Aceptar, Rechazar, Pagada y Cancelar.
6. Correo al cliente con la decisión. Si fue aceptada, incluye los datos de transferencia, el monto y el plazo de pago (48 h).
7. Correo al admin cuando entra una solicitud nueva.
8. Marcar como pagada y avisar al cliente con un correo de confirmación final.

No funcionales: debe funcionar bien en el celular, tener costo mínimo, permitir agregar cabañas o admins sin cambiar código, y mantener el panel protegido sin exponer datos personales.

## Estructura de carpetas

- `public/`: lo único que el servidor web expone (páginas, CSS, JS y los PHP de entrada).
- `src/`: conexión a la base, funciones compartidas y envío de correos. No accesible desde la web.
- `config.php`: claves y datos sensibles, fuera de `public/`, y no se sube al repo. Se sube un `config.example.php` sin valores reales.
- `sql/schema.sql`: tablas y datos iniciales.

## Modelo de datos

**cabanas**: `id int auto_increment pk`, `nombre varchar`, `capacidad int`, `precio_noche int` (CLP), `activa boolean`.

**reservas**: `id int auto_increment pk`, `cabana_id int fk`, `nombre`, `email` y `telefono varchar`, `fecha_entrada date`, `fecha_salida date` (no cuenta como noche), `personas int`, `monto int` (noches × precio_noche, en CLP), `estado enum('pendiente','aceptada','rechazada','pagada','cancelada')`, `creada_en timestamp`.

**admins**: `id int auto_increment pk`, `email varchar unique`, `password_hash varchar`. Las contraseñas se guardan con `password_hash()` de PHP, nunca en texto plano.

## Estados

`pendiente` → `aceptada` → `pagada`
`pendiente` → `rechazada`
`aceptada` → `cancelada` (si no llega el pago en el plazo; libera las fechas)

Las reservas pendientes **no** bloquean fechas. Solo bloquean las que están en `aceptada` o `pagada`.

## Regla de choque de fechas

Dos reservas de la misma cabaña chocan si `nueva_entrada < salida_existente AND nueva_salida > entrada_existente`. Quien sale el día 15 no choca con quien entra el 15.

La regla se aplica en 2 lugares:

1. **El formulario** avisa y muestra los días ocupados. Es solo informativo, porque una pendiente no bloquea.
2. **El botón Aceptar** es el control real. MySQL no tiene restricciones de exclusión como PostgreSQL, así que se hace en una transacción: `SELECT ... FROM cabanas WHERE id = ? FOR UPDATE` (bloquea esa cabaña), luego se buscan choques con reservas `aceptada`/`pagada` y, si no hay, se actualiza a `aceptada`. El bloqueo evita que dos admins acepten reservas que chocan al mismo tiempo. Las tablas deben ser InnoDB.

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
10. La disponibilidad pública devuelve solo rangos ocupados, nunca nombres ni correos.

## Plan por partes

Se avanza parte por parte. Cada una termina con algo que se puede probar, y el usuario la revisa antes de seguir.

| Parte | Qué hace Claude | Qué hace el usuario |
|---|---|---|
| **1. Base** | Estructura de carpetas, `.gitignore`, `sql/schema.sql`, `config.example.php`, conexión PDO | Encender MySQL (`brew services start mysql`), crear la base y ejecutar el SQL |
| **2. Formulario** | Página pública, PHP que valida y guarda, aviso de fechas ocupadas | Probar reservas que chocan |
| **3. Panel admin** | Login, script para crear admins, lista con filtro, botones Aceptar, Rechazar, Pagada y Cancelar | Crear su usuario admin con el script |
| **4. Correos** | Los 4 correos con Resend (solicitud nueva, aceptada, rechazada, pagada) | Crear la cuenta en Resend y obtener la API key |
| **5. Publicación** | Captcha (Cloudflare Turnstile), guía de subida al hosting | Elegir hosting, dominio y DNS |

En desarrollo se usa el servidor que trae PHP: `php -S localhost:8000 -t public`.

Queda fuera, a propósito: calendario visual, precios por temporada, cancelación automática, WhatsApp y pago en línea.

## Estado actual

- Repo git en `~/Desktop/entorno_prueba/cabanas-proyecto`, rama `main`, con remoto en GitHub: https://github.com/JorgeMendozaJaque/cabanas_proyecto
- Reglas del agente en `AGENTS.md`: modo Ponytail, mínimo código necesario y respuestas en español.
- Instalados con Homebrew: PHP 8.5 (con `pdo_mysql`, `curl` y `mbstring`) y MySQL. Composer no está y no hace falta.
- La plantilla trae `src/` vacío y un `.gitignore` pensado para Python, que hay que ajustar.
- **Siguiente paso: Parte 1.**
