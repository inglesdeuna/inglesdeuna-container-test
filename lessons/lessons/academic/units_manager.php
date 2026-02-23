<?php
session_start();

/**
 * UNITS MANAGER
 * Gestiona unidades dentro de un Nivel
 */

// 🔐 SOLO ADMIN
if (!isset($_SESSION["admin_logged"]) || $_SESSION["admin_logged"] !== true) {
  header("Location: ../admin/login.php");
  exit;
}

/* ===============================
   DB CONNECTION
=============================== */
require __DIR__ . "/../config/db.php";

/* ===============================
   VALIDAR LEVEL
=============================== */
$levelId = $_GET["level"] ?? null;
if (!$levelId) {
  die("Nivel no especificado");
}

/* ===============================
   OBTENER LEVEL
=============================== */
$stmtLevel = $pdo->prepare("
  SELECT * FROM levels
  WHERE id = :id
");
$stmtLevel->execute(["id" => $levelId]);
$level = $stmtLevel->fetch(PDO::FETCH_ASSOC);

if (!$level) {
  die("Nivel no encontrado");
}

/* ===============================
   CREAR UNIT
=============================== */
if ($_SERVER["REQUEST_METHOD"] === "POST" && !empty($_POST["unit_name"])) {

  $unitId = uniqid("unit_");

  // obtener siguiente posición
  $stmtPos = $pdo->prepare("
      SELECT COALESCE(MAX(position),0)+1 AS next_pos
      FROM units
      WHERE level_id = :level
  ");
  $stmtPos->execute(["level" => $levelId]);
  $nextPosition = $stmtPos->fetch(PDO::FETCH_ASSOC)["next_pos"];

  $stmtInsert = $pdo->prepare("
      INSERT INTO units (id, name, level_id, position)
      VALUES (:id, :name, :level_id, :position)
  ");

  $stmtInsert->execute([
      "id" => $unitId,
      "name" => trim($_POST["unit_name"]),
      "level_id" => $levelId,
      "position" => $nextPosition
  ]);

  header("Location: units_manager.php?level=" . urlencode($levelId));
  exit;
}

/* ===============================
   LISTAR UNITS
=============================== */
$stmt = $pdo->prepare("
  SELECT * FROM units
  WHERE level_id = :level
  ORDER BY position ASC
");
$stmt->execute(["level" => $levelId]);
$units = $stmt->fetchAll(PDO::FETCH_ASSOC);

?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<title>Units</title>
<style>
body{font-family:Arial;background:#f4f8ff;padding:40px}
h1{color:#2563eb}
.card{background:#fff;padding:25px;border-radius:12px;margin-bottom:25px;max-width:600px}
.unit{
  background:#fff;
  padding:15px;
  border-radius:10px;
  margin-bottom:10px;
  display:flex;
  justify-content:space-between;
  align-items:center;
  box-shadow:0 4px 8px rgba(0,0,0,.08)
}
a{text-decoration:none;color:#2563eb;font-weight:bold}
input{width:100%;padding:12px;margin-top:10px}
button{
  margin-top:15px;
  padding:12px 18px;
  background:#2563eb;
  color:#fff;
  border:none;
  border-radius:8px;
  font-weight:700;
  cursor:pointer;
}
button:hover{background:#1d4ed8}
</style>
</head>
<body>

<h1>📘 Units — <?= htmlspecialchars($level["name"]) ?></h1>

<div class="card">
  <h2>➕ Crear Unit</h2>
  <form method="post">
    <input type="text" name="unit_name" required placeholder="Ej: Unit 1">
    <button>Crear Unit</button>
  </form>
</div>

<div class="card">
  <h2>📋 Units creadas</h2>

  <?php if (empty($units)): ?>
    <p>No hay unidades creadas.</p>
  <?php else: ?>
    <?php foreach ($units as $u): ?>
      <div class="unit">
        <strong><?= htmlspecialchars($u["name"]) ?></strong>
        <a href="unit_view.php?unit=<?= urlencode($u["id"]) ?>">
          Abrir →
        </a>
      </div>
    <?php endforeach; ?>
  <?php endif; ?>

</div>

</body>
</html>
