<?php

$databaseUrl = getenv('DATABASE_URL');

if (!$databaseUrl) {
    die("DATABASE_URL no está configurada.");
}

$db = parse_url($databaseUrl);

$host     = $db['host'];
$port     = $db['port'];
$user     = $db['user'];
$password = $db['pass'];
$dbname   = ltrim($db['path'], '/');

try {
    $pdo = new PDO(
        "pgsql:host=$host;port=$port;dbname=$dbname",
        $user,
        $password,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
} catch (PDOException $e) {
    die("Error de conexión: " . $e->getMessage());
}
