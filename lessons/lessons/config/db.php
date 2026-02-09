<?php

$DATABASE_URL = getenv("DATABASE_URL");

if (!$DATABASE_URL) {
    die("DATABASE_URL no encontrada");
}

$db = parse_url($DATABASE_URL);

$host = $db["host"];
$port = $db["port"] ?? 5432;
$user = $db["user"];
$pass = $db["pass"];
$dbname = ltrim($db["path"], "/");

try {

    $pdo = new PDO(
        "pgsql:host=$host;port=$port;dbname=$dbname",
        $user,
        $pass,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION
        ]
    );

} catch (PDOException $e) {
    die("DB Connection Failed: " . $e->getMessage());
}
