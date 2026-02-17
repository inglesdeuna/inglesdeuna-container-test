<?php

$databaseUrl = getenv("DATABASE_URL");

if (!$databaseUrl) {
    die("DATABASE_URL not configured");
}

$db = parse_url($databaseUrl);

$host = $db["host"] ?? null;
$user = $db["user"] ?? null;
$pass = $db["pass"] ?? null;
$dbname = isset($db["path"]) ? ltrim($db["path"], "/") : null;
$port = $db["port"] ?? 5432; // ← valor por defecto

if (!$host || !$user || !$dbname) {
    die("Invalid DATABASE_URL format");
}

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
    die("DB Connection failed: " . $e->getMessage());
}
