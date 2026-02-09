<?php

error_reporting(E_ALL);
ini_set('display_errors', 1);

/* =========================
   CONEXION DB DIRECTA
========================= */

$host = "localhost";
$db   = "DB_NAME";
$user = "DB_USER";
$pass = "DB_PASS";
$charset = "utf8mb4";

$dsn = "mysql:host=$host;dbname=$db;charset=$charset";

try {
    $conn = new PDO($dsn, $user, $pass);
    $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    echo "✅ DB conectada<br>";
} catch (PDOException $e) {
    die("❌ Error DB: " . $e->getMessage());
}


/* =========================
   VALIDAR UNIT
========================= */

if (!isset($_GET['unit']) || empty($_GET['unit'])) {
    die("❌ No se recibió UNIT");
}

$unit_id = $_GET['unit'];

echo "✅ UNIT RECIBIDA: " . $unit_id . "<br>";


/* =========================
   BUSCAR UNIT EN DB
========================= */

try {

    $sql = "SELECT * FROM units WHERE id = :unit LIMIT 1";
    $stmt = $conn->prepare($sql);
    $stmt->bindParam(":unit", $unit_id);
    $stmt->execute();

    $unitData = $stmt->fetch(PDO::FETCH_ASSOC);

    echo "<pre>";
    echo "📦 RESULTADO DB:\n";
    var_dump($unitData);

    if (!$unitData) {
        die("❌ Unidad no encontrada en DB");
    }

    echo "✅ Unidad encontrada correctamente";

} catch (Exception $e) {
    die("❌ Error Query: " . $e->getMessage());
}

?>
