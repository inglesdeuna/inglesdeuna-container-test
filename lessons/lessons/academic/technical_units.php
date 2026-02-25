<?php
session_start();

if (!isset($_SESSION["admin_logged"]) || $_SESSION["admin_logged"] !== true) {
    header("Location: ../admin/login.php");
    exit;
}

require __DIR__ . "/../config/db.php";

$courseId = $_GET["course"] ?? null;

if (!$courseId) {
    die("Curso no especificado.");
}

/* CREAR UNIDAD */
if ($_SERVER["REQUEST_METHOD"] === "POST" && !empty($_POST["unit_name"])) {

    $unitName = strtoupper(trim($_POST["unit_name"]));

    $check = $pdo->prepare("
        SELECT id FROM units
        WHERE course_id = :course AND name = :name
        LIMIT 1
    ");
    $check->execute([
        "course" => $courseId,
        "name" => $unitName
    ]);

    $existing = $check->fetch(PDO::FETCH_ASSOC);

    if ($existing) {
        $unitId = $existing["id"];
    } else {
        $unitId = uniqid("unit_");

        $stmt = $pdo->prepare("
            INSERT INTO units (id, course_id, name)
            VALUES (:id, :course, :name)
        ");
        $stmt->execute([
            "id" => $unitId,
            "course" => $courseId,
            "name" => $unitName
        ]);
    }

    header("Location: ../activities/hub/index.php?unit=" . urlencode($unitId));
    exit;
}

/* LISTAR UNIDADES */
$stmt = $pdo->prepare("
    SELECT * FROM units
    WHERE course_id = :course
    ORDER BY created_at ASC
");
$stmt->execute(["course" => $courseId]);
$units = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>

<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<title>Unidades</title>

<style>
body{font-family:Arial;background:#f4f8ff;padding:40px}
.card{background:#fff;padding:25px;border-radius:14px;margin-bottom:25px;max-width:900px;box-shadow:0 10px 25px rgba(0,0,0,.08)}
.item{background:#eef2ff;padding:15px;border-radius:10px;margin-bottom:12px;display:flex;justify-content:space-between;align-items:center}
input{width:100%;padding:12px;margin-top:10px;border-radius:8px;border:1px solid #ddd}
button{margin-top:15px;padding:12px 18px;background:#2563eb;color:#fff;border:none;border-radius:8px;font-weight:700}
.back{display:inline-block;margin-bottom:20px;background:#6b7280;color:#fff;padding:10px 18px;border-radius:8px;text-decoration:none}
</style>
</head>

<body>

<a class="back" href="courses_manager.php?program=prog_technical">
← Volver
</a>

<div class="card">
<h2>➕ Crear Unidad</h2>
<form method="post">
<input type="text" name="unit_name" required placeholder="Ej: UNIDAD 1">
<button>Crear</button>
</form>
</div>

<div class="card">
<h3>📋 Unidades creadas</h3>

<?php foreach ($units as $unit): ?>
<div class="item">
<strong><?= htmlspecialchars($unit["name"]) ?></strong>
<a href="../activities/hub/index.php?unit=<?= urlencode($unit["id"]) ?>">
Administrar →
</a>
</div>
<?php endforeach; ?>

</div>

</body>
</html>
