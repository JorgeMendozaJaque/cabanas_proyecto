# Plan: Web de reservas de cabañas

Contexto para retomar el trabajo en una sesión nueva. Leer completo antes de empezar.

## Qué es

Una web donde los clientes piden reservas de cabañas familiares y un admin las acepta o rechaza desde un panel privado. Cada decisión le llega al cliente por correo. El pago es siempre por transferencia bancaria, fuera del sistema. No hay precios dinámicos ni pagos en línea.

## Stack (decidido)

- **Next.js** (TypeScript + Tailwind) publicado en **Vercel**. Frontend y rutas API en un solo proyecto.
- **Supabase**: PostgreSQL para los datos y Supabase Auth para el login de admin.
- **Resend** para los correos.
- Costo inicial $0. El único gasto fijo es un dominio `.cl`.

## Requisitos funcionales

1. Formulario público con cabaña, fecha de entrada, fecha de salida, personas, nombre, email y teléfono.
2. Validación de disponibilidad: no se aceptan fechas que choquen con una reserva `aceptada` o `pagada`.
3. Login de admin.
4. Panel con la lista de reservas, filtrable por estado.
5. Botones Aceptar, Rechazar, Pagada y Cancelar.
6. Correo al cliente con la decisión. Si fue aceptada, incluye los datos de transferencia, el monto y el plazo de pago (48 h).
7. Correo al admin cuando entra una solicitud nueva.
8. Marcar como pagada y avisar al cliente con un correo de confirmación final.

No funcionales: debe funcionar bien en el celular, costar $0, permitir agregar cabañas o admins sin cambiar código, y mantener el panel protegido sin exponer datos personales.

## Modelo de datos

**cabanas**: `id uuid pk`, `nombre text`, `capacidad int`, `precio_noche int` (CLP), `activa boolean`.

**reservas**: `id uuid pk`, `cabana_id uuid fk`, `nombre`, `email` y `telefono text`, `fecha_entrada date`, `fecha_salida date` (no cuenta como noche), `personas int`, `monto int` (noches × precio_noche, en CLP), `estado enum`, `creada_en timestamptz`.

**admins**: los maneja Supabase Auth.

## Estados

`pendiente` → `aceptada` → `pagada`
`pendiente` → `rechazada`
`aceptada` → `cancelada` (si no llega el pago en el plazo; libera las fechas)

Las reservas pendientes **no** bloquean fechas. Solo bloquean las que están en `aceptada` o `pagada`.

## Regla de choque de fechas

Dos reservas de la misma cabaña chocan si `nueva_entrada < salida_existente AND nueva_salida > entrada_existente`. Quien sale el día 15 no choca con quien entra el 15.

La regla se aplica en 3 lugares: el formulario (avisa y muestra los días ocupados), el botón Aceptar y la base de datos (restricción de exclusión).

## Decisiones de diseño (correcciones al informe original)

1. **Desactivar el registro público en Supabase Auth.** Las cuentas de admin se crean a mano. Si no se desactiva, cualquiera puede crearse una cuenta.
2. **El público no toca la tabla.** El formulario envía los datos a una ruta API de Next.js, que valida y guarda usando la service key. El **monto lo calcula el servidor**, nunca el navegador. RLS: el rol anónimo no tiene acceso a `reservas`.
3. **Restricción de choque:** activar la extensión `btree_gist` y usar `EXCLUDE USING gist (cabana_id WITH =, daterange(fecha_entrada, fecha_salida, '[)') WITH &&) WHERE (estado IN ('aceptada','pagada'))`. Agregar también `CHECK (fecha_salida > fecha_entrada)`.
4. **Validar personas:** `personas <= capacidad` de la cabaña.
5. **Fechas como texto `YYYY-MM-DD`.** No pasarlas por `new Date()` en JS, porque en Chile se corren un día.
6. **Resend sin dominio verificado** solo envía correos al email del dueño de la cuenta. Sirve para desarrollar; los clientes reciben correos solo cuando el dominio esté verificado.
7. **Datos de transferencia** (banco, RUT, cuenta) y email del admin van en variables de entorno.
8. La disponibilidad pública devuelve solo rangos ocupados, nunca nombres ni correos.
9. Las claves (service key de Supabase y API key de Resend) viven solo en el servidor (`.env.local`, que no se sube al repo).

## Plan por partes

Se avanza parte por parte. Cada una termina con algo que se puede probar, y el usuario la revisa antes de seguir.

| Parte | Qué hace Claude | Qué hace el usuario |
|---|---|---|
| **1. Base** | Proyecto Next.js en este repo, `.gitignore` para Node, archivo SQL con tablas, restricción y RLS | Crear la cuenta y el proyecto en Supabase y ejecutar el SQL |
| **2. Formulario** | Página pública, ruta API que valida y guarda, aviso de fechas ocupadas | Probar reservas que chocan |
| **3. Panel admin** | Login, lista con filtro, botones Aceptar, Rechazar, Pagada y Cancelar | Crear su usuario admin en Supabase |
| **4. Correos** | Los 4 correos con Resend (solicitud nueva, aceptada, rechazada, pagada) | Crear la cuenta en Resend |
| **5. Publicación** | Captcha (Cloudflare Turnstile), cron contra la pausa de Supabase, deploy | Vercel, dominio y DNS, repo en GitHub |

Queda fuera, a propósito: calendario visual, precios por temporada, cancelación automática, WhatsApp y pago en línea.

## Estado actual

- Repo git propio en `~/Desktop/entorno_prueba/cabanas-proyecto`, rama `main`, con un commit inicial de la plantilla Ponytail.
- Reglas del agente en `AGENTS.md`: modo Ponytail, mínimo código necesario y respuestas en español.
- Node 24 y npm 11 instalados. No están instalados `gh`, `supabase` ni `vercel`.
- El repo todavía no tiene remoto en GitHub.
- La plantilla trae `src/` vacío y un `.gitignore` pensado para Python, que hay que ajustar para Node.
- **Siguiente paso: Parte 1.**
