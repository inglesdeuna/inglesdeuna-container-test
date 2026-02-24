<?php
session_start();
require __DIR__ . "/../config/db.php";

if (!isset($_SESSION["admin_logged"])) {
    header("Location: ../admin/login.php");
    exit;
}

$programId = "prog_english_courses";

$stmt = $pdo->prepare("
    SELECT id, name
    FROM courses
    WHERE program_id = :program
    ORDER BY name ASC
");
$stmt->execute(["program" => $programId]);
$phases = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>

<!DOCTYPE html>
<html>
<head>
<meta charset="UTF-8">
<title>Estructura Inglés</title>
<style>
body{font-family:Arial;background:#f4f8ff;padding:40px}
.card{background:#fff;padding:25px;border-radius:12px;max-width:600px}
.phase{background:#f1f5f9;padding:15px;border-radius:10px;margin-bottom:10px;display:flex;justify-content:space-between}
a{text-decoration:none;color:#2563eb;font-weight:bold}
</style>
</head>
<body>

<h1>📘 Estructura — Fases Inglés</h1>

<div class="card">
<?php foreach ($phases as $p): ?>
  <div class="phase">
    <strong><?= htmlspecialchars($p["name"]) ?></strong>
    <a href="english_structure_levels.php?phase=<?= urlencode($p["id"]) ?>">
      Administrar →
    </a>
  </div>
<?php endforeach; ?>
</div>

</body>
</html>
