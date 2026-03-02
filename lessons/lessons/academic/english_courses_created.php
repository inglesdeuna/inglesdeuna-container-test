<?php
session_start();
if (!isset($_SESSION["admin_logged"])) { header("Location: ../admin/login.php"); exit; }
require __DIR__ . "/../config/db.php";

$stmt=$pdo->query("SELECT * FROM english_levels ORDER BY id ASC");
$levels=$stmt->fetchAll(PDO::FETCH_ASSOC);
?>
<h2>Cursos creados - English</h2>
<?php foreach($levels as $l): ?>
<div>
<strong><?= htmlspecialchars($l["name"]) ?></strong>
<a href="english_levels_view.php?level=<?= $l["id"] ?>">Ver →</a>
</div>
<?php endforeach; ?>
