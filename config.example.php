<?php
// Copiar como config.php (no se sube al repo) y completar con los datos reales.
return [
    'db' => [
        'host' => '127.0.0.1',
        'nombre' => 'cabanas',
        'usuario' => 'root',
        'clave' => '',
    ],
    // Porcentaje mínimo a transferir dentro de 48 h para confirmar. No es reembolsable.
    'abono_porcentaje' => 50,
    // Datos que ve el cliente para pagar. Reemplazar por los reales.
    'transferencia' => [
        'banco' => 'Banco Ejemplo',
        'tipo_cuenta' => 'Cuenta corriente',
        'numero_cuenta' => '000123456789',
        'titular' => 'Nombre del Titular',
        'rut' => '11.111.111-1',
        'email' => 'pagos@ejemplo.cl',
    ],
    // Correos con Resend. Sin dominio verificado: remitente onboarding@resend.dev y solo llega al dueño de la cuenta.
    'resend' => [
        'api_key' => 're_xxxxxxxx',
        'remitente' => 'Cabañas <onboarding@resend.dev>',
        'admin_email' => 'admin@ejemplo.cl',
    ],
];
