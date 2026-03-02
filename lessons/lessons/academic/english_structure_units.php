<?php
session_start();
if (!isset($_SESSION["admin_logged"])) { header("Location: ../admin/login.php"); exit; }
require __DIR__ . "/../config/db.php";

$phaseId = $_GET["phase"] ?? null;
if (!$phaseId) die("Phase requerida");

if ($_SERVER["REQUEST_METHOD"]==="POST" && !empty($_POST["name"])) {
    $stmt=$pdo->prepare("INSERT INTO units (phase_id,name,created_at) VALUES (:phase_id,:name,NOW())");
    $stmt->execute(["phase_id"=>$phaseId,"name"=>trim($_POST["name"])]);
    $unitId=$pdo->lastInsertId();
    header("Location: ../activities/hub/index.php?unit=".$unitId);
    exit;
}

$stmt=$pdo->prepare("SELECT * FROM units WHERE phase_id=:id ORDER BY id ASC");
$stmt->execute(["id"=>$phaseId]);
$units=$stmt->fetchAll(PDO::FETCH_ASSOC);
?>
<h2>Units</h2>
<form method="POST">
<input name="name" required placeholder="Unit 1">
<button>Crear</button>
</form>
<?php foreach($units as $u): ?>
<div>
<?= htmlspecialchars($u["name"]) ?>
<a href="../activities/hub/index.php?unit=<?= $u["id"] ?>">Actividades →</a>
</div>
<?php endforeach; ?>
