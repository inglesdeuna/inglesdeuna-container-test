<?php
session_start();

if (!isset($_SESSION["admin_logged"]) || $_SESSION["admin_logged"] !== true) {
  header("Location: ../admin/login.php");
  exit;
}

require __DIR__ . "/../config/db.php";

/* ===============================
   OBTENER FASES (COURSES DEL PROGRAMA INGLÉS)
=============================== */
$stmt = $pdo->prepare("
  SELECT * FROM courses
  WHERE program_id = 'prog_english_courses'
  ORDER BY name ASC
");

$stmt->execute();
$phases = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>

<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<title>Fases — Cursos de Inglés</title>
<style>
body{font-family:Arial;background:#f4f8ff;padding:40px}
h1{color:#2563eb;margin-bottom:30px}
.card{background:#fff;padding:25px;border-radius:12px;margin-bottom:20px;max-width:600px}
.phase{background:#fff;padding:18px;border-radius:10px;margin-bottom:12px;display:flex;justify-content:space-between;align-items:center;box-shadow:0 4px 8px rgba(0,0,0,.08)}
a{text-decoration:none;color:#2563eb;font-weight:bold}
.back{display:inline-block;margin-bottom:20px;background:#6b7280;color:#fff;padding:10px 18px;border-radius:8px;text-decoration:none}
</style>
</head>
<body>

<a class="back" href="../admin/dashboard.php">← Volver al Dashboard</a>

<h1>📘 Cursos de Inglés — Fases</h1>

<div class="card">
  <h2>Seleccionar fase</h2>

  <?php if (empty($phases)): ?>
    <p>No hay fases creadas.</p>
  <?php else: ?>
    <?php foreach ($phases as $phase): ?>
      <div class="phase">
        <strong><?= htmlspecialchars($phase["name"]) ?></strong>
        <a href="english_levels.php?phase=<?= urlencode($phase["id"]) ?>">
          Entrar →
        </a>
      </div>
    <?php endforeach; ?>
  <?php endif; ?>

</div>

</body>
</html>
