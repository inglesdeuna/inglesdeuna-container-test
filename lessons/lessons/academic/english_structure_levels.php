<?php
session_start();
require __DIR__ . "/../config/db.php";

if (!isset($_SESSION["admin_logged"])) {
    header("Location: ../admin/login.php");
    exit;
}

$phaseId = $_GET["phase"] ?? null;
if (!$phaseId) {
    die("Fase no especificada");
}

/* CREAR NIVEL */
if ($_SERVER["REQUEST_METHOD"] === "POST" && !empty($_POST["level_name"])) {

    $levelId = uniqid("level_");

    $stmt = $pdo->prepare("
        INSERT INTO levels (id, name, course_id)
        VALUES (:id, :name, :course)
    ");

    $stmt->execute([
        "id" => $levelId,
        "name" => trim($_POST["level_name"]),
        "course" => $phaseId
    ]);

    header("Location: english_structure_levels.php?phase=" . urlencode($phaseId));
    exit;
}

/* LISTAR NIVELES */
$stmt = $pdo->prepare("
    SELECT id, name
    FROM levels
    WHERE course_id = :phase
    ORDER BY name ASC
");
$stmt->execute(["phase" => $phaseId]);
$levels = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>

<!DOCTYPE html>
<html>
<head>
<meta charset="UTF-8">
<title>Estructura Inglés — Niveles</title>
<style>
body{font-family:Arial;background:#f4f8ff;padding:40px}
.card{background:#fff;padding:25px;border-radius:12px;margin-bottom:25px;max-width:600px}
.item{background:#f1f5f9;padding:15px;border-radius:10px;margin-bottom:10px;display:flex;justify-content:space-between}
a{text-decoration:none;color:#2563eb;font-weight:bold}
input{width:100%;padding:10px;margin-top:10px}
button{margin-top:10px;padding:10px 16px;background:#2563eb;color:#fff;border:none;border-radius:8px}
</style>
</head>
<body>

<h1>📘 Estructura — Niveles</h1>

<a href="english_structure_phases.php">← Volver a fases</a>

<div class="card">
<h2>➕ Crear nivel</h2>
<form method="post">
  <input type="text" name="level_name" required placeholder="Ej: Preschool">
  <button>Crear nivel</button>
</form>
</div>

<div class="card">
<h2>📋 Niveles creados</h2>

<?php if (empty($levels)): ?>
  <p>No hay niveles creados.</p>
<?php else: ?>
  <?php foreach ($levels as $l): ?>
    <div class="item">
      <strong><?= htmlspecialchars($l["name"]) ?></strong>
      <a href="english_structure_units.php?level=<?= urlencode($l["id"]) ?>">
        Administrar →
      </a>
    </div>
  <?php endforeach; ?>
<?php endif; ?>

</div>

</body>
</html>
