<?php

$databaseUrl = getenv("DATABASE_URL");

if (!$databaseUrl) {
    die("DATABASE_URL no está definida.");
}

$parsed = parse_url($databaseUrl);

$host = $parsed["host"];
$port = $parsed["port"] ?? 5432;
$user = $parsed["user"];
$pass = $parsed["pass"];
$db   = ltrim($parsed["path"], "/");

try {

    $dsn = "pgsql:host=$host;port=$port;dbname=$db;sslmode=require;keepalives=1;keepalives_idle=30;keepalives_interval=10;keepalives_count=5";

    $pdo = new PDO($dsn, $user, $pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    ]);

} catch (PDOException $e) {

    die("Error de conexión: " . $e->getMessage());

}
