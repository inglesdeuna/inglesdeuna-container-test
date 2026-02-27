<?php

$host = "TU_HOST_REAL_DE_RENDER";
$port = "5432";
$db   = "TU_DB_NAME";
$user = "TU_DB_USER";
$pass = "TU_DB_PASSWORD";

try {

    $dsn = "pgsql:host=$host;port=$port;dbname=$db;";
    $pdo = new PDO($dsn, $user, $pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION
    ]);

} catch (PDOException $e) {

    die($e->getMessage());

}
