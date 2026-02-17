<?php
// core/db.php

$host = getenv("DB_HOST") ?: "localhost";
$db   = getenv("DB_NAME") ?: "inglesdeuna";
$user = getenv("DB_USER") ?: "postgres";
$pass = getenv("DB_PASS") ?: "password";
$port = getenv("DB_PORT") ?: "5432";

$dsn = "pgsql:host=$host;port=$port;dbname=$db;";

try {
    $pdo = new PDO($dsn, $user, $pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
    ]);
} catch (PDOException $e) {
    die("DB Connection failed: " . $e->getMessage());
}
