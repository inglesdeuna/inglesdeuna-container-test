<?php
session_start();
if (!isset($_SESSION["admin_logged"])) { header("Location: ../admin/login.php"); exit; }
require __DIR__ . "/../config/db.php";

$levelId=$_GET["level"]??null;
if(!$levelId) die("Level requerido");

$stmt=$pdo->prepare("SELECT * FROM english_phases WHERE level_id=:id");
$stmt->execute(["id"=>$levelId]);
$phases=$stmt->fetchAll(PDO::FETCH_ASSOC);
?>
<h2>Phases</h2>
<?php foreach($phases as $p): ?>
<div>
<?= htmlspecialchars($p["name"]) ?>
<a href="english_units_view.php?phase=<?= $p["id"] ?>">Units →</a>
</div>
<?php endforeach; ?>
