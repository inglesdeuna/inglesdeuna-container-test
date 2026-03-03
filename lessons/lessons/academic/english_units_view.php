<?php
session_start();
if (!isset($_SESSION["admin_logged"])) { header("Location: ../admin/login.php"); exit; }
require __DIR__ . "/../config/db.php";

$phaseId=$_GET["phase"]??null;
if(!$phaseId) die("Phase requerida");

$stmt=$pdo->prepare("SELECT * FROM units WHERE phase_id=:id");
$stmt->execute(["id"=>$phaseId]);
$units=$stmt->fetchAll(PDO::FETCH_ASSOC);
?>
<h2>Units</h2>
<?php foreach($units as $u): ?>
<div>
<?= htmlspecialchars($u["name"]) ?>
<a href="unit_view.php?unit=<?= urlencode($u["id"]) ?>&source=created">Ver actividades →</a>
</div>
<?php endforeach; ?>
