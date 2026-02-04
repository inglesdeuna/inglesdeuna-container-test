<?php
session_start();

/* DESTRUIR SESIÓN */
$_SESSION = [];
session_destroy();

/* REDIRIGIR A LOGIN */
header("Location: login.php");
exit;
