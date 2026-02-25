<?php
session_start();

if (!isset($_SESSION["admin_logged"]) || $_SESSION["admin_logged"] !== true) {
    header("Location: ../admin/login.php");
    exit;
}

require __DIR__ . "/../config/db.php";

/* ===============================
   OBTENER CURSO POR ID REAL
=============================== */
$courseId = $_GET["course"] ?? null;

if (!$courseId) {
    die("Curso no especificado.");
}

$stmt = $pdo->prepare("
    SELECT * FROM courses
    WHERE id = :id
    LIMIT 1
");

$stmt->execute(["id" => $courseId]);
$course = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$course) {
    die("Curso no válido.");
}

/* ===============================
   CREAR / ACCEDER A UNIT
=============================== */
if ($_SERVER["REQUEST_METHOD"] === "POST" && !empty($_POST["unit_name"])) {

    $unitName = strtoupper(trim($_POST["unit_name"]));

    /* Buscar si ya existe */
    $check = $pdo->prepare("
        SELECT id FROM units
        WHERE course_id = :course_id
        AND name = :name
        LIMIT 1
    ");

    $check->execute([
        "course_id" => $courseId,
        "name" => $unitName
    ]);

    $existing = $check->fetch(PDO::FETCH_ASSOC);

    if ($existing) {
        $unitId = $existing["id"];
    } else {
        $unitId = uniqid("unit_");

        $stmt = $pdo->prepare("
            INSERT INTO units (id, course_id, name)
            VALUES (:id, :course_id, :name)
        ");

        $stmt->execute([
            "id" => $unitId,
            "course_id" => $courseId,
            "name" => $unitName
        ]);
    }

    /* REDIRIGE AL HUB REAL */
    header("Location: ../activities/hub/index.php?unit=" . urlencode($unitId));
    exit;
}

/* ===============================
   LISTAR UNITS EXISTENTES
=============================== */
$stmt = $pdo->prepare("
    SELECT * FROM units
    WHERE course_id = :course_id
    ORDER BY name ASC
");

$stmt->execute(["course_id" => $courseId]);
$units = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>

<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<title><?= htmlspecialchars($course["name"]) ?> - Units</title>

<style>
body{font-family:Arial;background:#f4f8ff;padding:40px}
h1{color:#2563eb;margin-bottom:25px}
.card{background:#fff;padding:25px;border-radius:12px;margin-bottom:25px;max-width:800px}
.item{background:#fff;padding:15px;border-radius:10px;margin-bottom:12px;display:flex;justify-content:space-between;align-items:center;box-shadow:0 4px 8px rgba(0,0,0,.08)}
a{text-decoration:none;color:#2563eb;font-weight:bold}
input{width:100%;padding:12px;margin-top:10px}
button{margin-top:15px;padding:12px 18px;background:#2563eb;color:#fff;border:none;border-radius:8px;font-weight:700}
.back{display:inline-block;margin-bottom:20px;background:#6b7280;color:#fff;padding:10px 18px;border-radius:8px;text-decoration:none}
</style>
</head>

<body>

<a class="back" href="technical_created.php">
  ← Volver a Semestres
</a>

<h1>📘 <?= htmlspecialchars($course["name"]) ?></h1>

<div class="card">
  <h2>➕ Crear / Acceder a Unidad</h2>

  <form method="post">
    <input type="text" name="unit_name" required placeholder="Ej: INGLES TECNICO">
    <button>Continuar</button>
  </form>
</div>

<div class="card">
  <h2>📋 Unidades existentes</h2>

  <?php if (empty($units)): ?>
      <p>No hay unidades creadas.</p>
  <?php else: ?>
      <?php foreach ($units as $u): ?>
          <div class="item">
              <strong><?= htmlspecialchars($u["name"]) ?></strong>
              <a href="../activities/hub/index.php?unit=<?= urlencode($u["id"]) ?>">
                  Administrar →
              </a>
          </div>
      <?php endforeach; ?>
  <?php endif; ?>

</div>

</body>
</html>
