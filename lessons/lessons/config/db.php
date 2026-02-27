<?php

$host = "localhost";
$port = "5432";
$db   = "inglesdeuna_db";
$user = "TU_USUARIO_REAL";
$pass = "TU_PASSWORD_REAL";

try {

    $dsn = "pgsql:host=$host;port=$port;dbname=$db;";
    $pdo = new PDO($dsn, $user, $pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
    ]);

} catch (PDOException $e) {

    die("Error de conexión a la base de datos.");

}
