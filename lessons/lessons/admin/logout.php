<?php
session_start();

// Elimina todas las variables de sesión
$_SESSION = [];

// Invalida cookie de sesión si aplica
if (ini_get('session.use_cookies')) {
	$params = session_get_cookie_params();
	setcookie(
		session_name(),
		'',
		time() - 42000,
		$params['path'],
		$params['domain'],
		$params['secure'],
		$params['httponly']
	);
}

// Destruye la sesión activa
session_destroy();

// Redirigir al login
header("Location: /lessons/lessons/admin/login.php");
exit;
