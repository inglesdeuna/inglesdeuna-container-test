<?php
session_start();

if (!isset($_SESSION["admin_logged"]) || $_SESSION["admin_logged"] !== true) {
  header("Location: ../admin/login.php");
  exit;
}

require __DIR__ . "/../config/db.php";

$programId = "prog_technical";

$stmt = $pdo->prepare("
  SELECT * FROM courses
  WHERE program_id = :program
  ORDER BY name ASC
");

$stmt->execute([
  "program" => $programId
]);

$courses = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>

<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<title>Semestres creados</title>
<style>
body{font-family:Arial;background:#f4f8ff;padding:40px}
h1{color:#2563eb;margin-bottom:25px}
.card{background:#fff;padding:25px;border-radius:12px;margin-bottom:25px;max-width:800px}
.item{background:#fff;padding:15px;border-radius:10px;margin-bottom:12px;display:flex;justify-content:space-between;align-items:center;box-shadow:0 4px 8px rgba(0,0,0,.08)}
a{text-decoration:none;color:#2563eb;font-weight:bold}
.back{display:inline-block;margin-bottom:20px;background:#6b7280;color:#fff;padding:10px 18px;border-radius:8px;text-decoration:none}
</style>
</head>
<body>

<a class="back" href="../admin/dashboard.php">
  ← Volver al Dashboard
</a>

<h1>📘 Programa Técnico — Semestres creados</h1>

<div class="card">

  <?php if (empty($courses)): ?>
    <p>No hay semestres creados.</p>
  <?php else: ?>
    <?php foreach ($courses as $c): ?>
      <div class="item">
        <strong><?= htmlspecialchars($c["name"]) ?></strong>
        <a href="technical_units.php?course=<?= urlencode($c["id"]) ?>">
          Administrar →
        </a>
      </div>
    <?php endforeach; ?>
  <?php endif; ?>

</div>

</body>
</html>
