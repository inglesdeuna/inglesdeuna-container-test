<?php
session_start();

// Elimina todas las variables de sesión
session_unset();

// Destruye la sesión
session_destroy();

// Reinicia el array de sesión por seguridad
$_SESSION = [];

// Regenerar ID de sesión para evitar reutilización
session_regenerate_id(true);

// Redirigir al login
header("Location: /lessons/lessons/admin/login.php");
exit;
?>
