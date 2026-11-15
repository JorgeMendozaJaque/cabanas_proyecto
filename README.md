# Cabañas

Web simple para pedir reservas de cabañas familiares.

- El cliente elige cabaña, fechas y cantidad de personas, y deja sus datos de contacto. La primera solicitud en llegar aparta las fechas.
- Al enviarla recibe por correo los datos de transferencia: para confirmar paga un abono del 50% (no reembolsable) dentro de 48 h, y el saldo al llegar.
- Un administrador acepta o rechaza la solicitud desde un panel privado, que también tiene buscador, calendario, ganancias por mes, cierre de días por mantención y precios (general y especiales por fechas, para promos o temporada alta).
- El cliente recibe cada decisión por correo, y un recordatorio a las 24 h si aún no paga.

No incluye pagos en línea.

## Tecnología

- HTML, CSS y JavaScript, sin frameworks
- PHP 8 en el servidor
- MySQL 8 o MariaDB para la base de datos
- [Resend](https://resend.com) para los correos (vía `curl`, sin Composer)

## Cómo levantarlo en local

Requisitos: PHP 8 con `pdo_mysql`, `mbstring`, `curl` y `openssl`, y MySQL o MariaDB encendido.

```sh
mysql -u root < sql/schema.sql          # crea la base "cabanas" con una cabaña de ejemplo
cp config.example.php config.php        # y completa base de datos, transferencia y Resend
php crear_admin.php tu@correo.cl        # crea el admin (pide la contraseña)
php -S localhost:8000 -t public         # abre http://localhost:8000
```

- Panel admin: http://localhost:8000/admin/
- Recordatorio de pago: `php recordatorios.php`, cada 15 minutos (un cron en el hosting).
- Sin dominio verificado en Resend, los correos solo llegan al dueño de la cuenta.

Notas de instalación por equipo (Mac y Windows) en [PLAN.md](PLAN.md#estado-actual).

## Pruebas

Corren contra la base local y solo crean y borran sus propias filas de prueba:

```sh
php tests/reservas_test.php
php tests/admin_test.php
php tests/correos_test.php              # con --enviar manda un correo real al admin
```

## Carpetas

- `public/`: lo único que expone el servidor web (páginas, CSS, JS y panel en `public/admin/`).
- `src/`: lógica compartida (reservas, panel, correos, conexión).
- `sql/schema.sql`: tablas y datos iniciales.
- `config.php`: claves y datos sensibles; no se sube al repo.

## Estado

En desarrollo: partes 1 a 4 listas, falta la publicación (captcha, hosting y dominio). El plan completo y el avance están en [PLAN.md](PLAN.md).

---

Las reglas del asistente de código vienen de la plantilla [Ponytail](https://github.com/dietrichgebert/ponytail) (MIT, ver `LICENSE-ponytail`).
