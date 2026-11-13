<?php
require __DIR__ . '/../../src/admin.php';
exigir_post_csrf();
cerrar_sesion_admin();
header('Location: login.php');
