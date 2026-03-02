<?php
session_start();
if (!isset($_SESSION["admin_logged"])) { header("Location: ../admin/login.php"); exit; }
require __DIR__ . "/../config/db.php";

$stmtProgram = $pdo->prepare("SELECT * FROM programs WHERE slug='prog_english_courses' LIMIT 1");
$stmtProgram->execute();
$program = $stmtProgram->fetch(PDO::FETCH_ASSOC);
if (!$program) die("Programa no encontrado.");

if ($_SERVER["REQUEST_METHOD"] === "POST" && !empty($_POST["name"])) {
    $stmt = $pdo->prepare("INSERT INTO english_levels (program_id,name) VALUES (:program_id,:name)");
    $stmt->execute(["program_id"=>$program["id"],"name"=>trim($_POST["name"])]);
    header("Location: english_structure_levels.php");
    exit;
}

$stmt = $pdo->prepare("SELECT * FROM english_levels WHERE program_id=:pid ORDER BY id ASC");
$stmt->execute(["pid"=>$program["id"]]);
$levels = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>
<h2>Levels</h2>
<form method="POST">
<input name="name" required placeholder="A1, A2...">
<button>Crear</button>
</form>
<?php foreach($levels as $l): ?>
<div>
<?= htmlspecialchars($l["name"]) ?>
<a href="english_structure_phases.php?level=<?= $l["id"] ?>">Fases →</a>
</div>
<?php endforeach; ?>
