<?php
session_start();

if (!isset($_SESSION["admin_logged"]) || $_SESSION["admin_logged"] !== true) {
    header("Location: ../admin/login.php");
    exit;
}

require __DIR__ . "/../config/db.php";

$phaseId = $_GET["phase"] ?? null;

if (!$phaseId) {
    die("Phase requerida");
}

/* Validar que la phase exista */
$check = $pdo->prepare("SELECT id FROM english_phases WHERE id = :id");
$check->execute(["id" => $phaseId]);

if (!$check->fetch()) {
    die("Phase no válida");
}

/* Obtener unidades */
$stmt = $pdo->prepare("
    SELECT id, name 
    FROM units 
    WHERE phase_id = :id
    ORDER BY id ASC
");

$stmt->execute(["id" => $phaseId]);
$units = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>

<h2>Units</h2>

<?php if (empty($units)): ?>
    <p>No hay unidades creadas.</p>
<?php else: ?>

    <?php foreach ($units as $u): ?>
        <div style="margin-bottom:10px;">
            <?= htmlspecialchars($u["name"]) ?>

            <a href="unit_view.php?unit=<?= urlencode($u["id"]) ?>&source=created">
                Ver actividades →
            </a>
        </div>
    <?php endforeach; ?>

<?php endif; ?>
