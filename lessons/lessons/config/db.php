<?php

$DATABASE_URL = "postgresql://inglesdeuna_db_user:9ZHdDdsGhP31Kfx6XEFZ1ku3iiOvbmEO@dpg-d653o1dum26s73bjj5o0-a.oregon-postgres.render.com/inglesdeuna_db";

$dbParts = parse_url($DATABASE_URL);

$dbHost = $dbParts['host'];
$dbPort = $dbParts['port'] ?? 5432; // ← FIX
$dbName = ltrim($dbParts['path'], '/');
$dbUser = $dbParts['user'];
$dbPass = $dbParts['pass'];

try {

    $conn = new PDO(
        "pgsql:host=$dbHost;port=$dbPort;dbname=$dbName",
        $dbUser,
        $dbPass
    );

    $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

} catch (PDOException $e) {
    die("DB Connection Failed: " . $e->getMessage());
}
