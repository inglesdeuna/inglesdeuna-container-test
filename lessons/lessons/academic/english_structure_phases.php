<?php
session_start();
if (!isset($_SESSION["admin_logged"])) { header("Location: ../admin/login.php"); exit; }
require __DIR__ . "/../config/db.php";

$levelId = $_GET["level"] ?? null;
if (!$levelId) die("Level requerido");

if ($_SERVER["REQUEST_METHOD"]==="POST" && !empty($_POST["name"])) {
    $stmt=$pdo->prepare("INSERT INTO english_phases (level_id,name) VALUES (:level_id,:name)");
    $stmt->execute(["level_id"=>$levelId,"name"=>trim($_POST["name"])]);
    header("Location: english_structure_phases.php?level=".$levelId);
    exit;
}

$stmt=$pdo->prepare("SELECT * FROM english_phases WHERE level_id=:id ORDER BY id ASC");
$stmt->execute(["id"=>$levelId]);
$phases=$stmt->fetchAll(PDO::FETCH_ASSOC);
?>
<h2>Phases</h2>
<form method="POST">
<input name="name" required placeholder="Phase 1">
<button>Crear</button>
</form>
<?php foreach($phases as $p): ?>
<div>
<?= htmlspecialchars($p["name"]) ?>
<a href="english_structure_units.php?phase=<?= $p["id"] ?>">Units →</a>
</div>
<?php endforeach; ?>
