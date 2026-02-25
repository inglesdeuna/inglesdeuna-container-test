<?php
session_start();

if (!isset($_SESSION["admin_logged"]) || $_SESSION["admin_logged"] !== true) {
    header("Location: ../admin/login.php");
    exit;
}

require __DIR__ . "/../config/db.php";

$programId = $_GET["program"] ?? null;

if (!$programId) {
    die("Programa no especificado");
}

$error = "";

/* ===============================
   CREAR SEMESTRE (SIN REPETIR 1-4)
=============================== */
if ($_SERVER["REQUEST_METHOD"] === "POST" && !empty($_POST["course_name"])) {

    $courseName = strtoupper(trim($_POST["course_name"]));

    $allowed = ["SEMESTRE 1", "SEMESTRE 2", "SEMESTRE 3", "SEMESTRE 4"];

    if (!in_array($courseName, $allowed)) {
        $error = "Solo se permiten SEMESTRE 1, 2, 3 o 4.";
    } else {

        $check = $pdo->prepare("
            SELECT id FROM courses
            WHERE program_id = :program AND name = :name
            LIMIT 1
        ");
        $check->execute([
            "program" => $programId,
            "name" => $courseName
        ]);

        $existing = $check->fetch(PDO::FETCH_ASSOC);

        if ($existing) {
            $error = "Ese semestre ya existe.";
        } else {

            $courseId = uniqid("course_");

            $stmt = $pdo->prepare("
                INSERT INTO courses (id, program_id, name)
                VALUES (:id, :program, :name)
            ");
            $stmt->execute([
                "id" => $courseId,
                "program" => $programId,
                "name" => $courseName
            ]);

            header("Location: courses_manager.php?program=" . urlencode($programId));
            exit;
        }
    }
}

/* ===============================
   LISTAR SEMESTRES
=============================== */
$stmt = $pdo->prepare("
    SELECT * FROM courses
    WHERE program_id = :program
    ORDER BY name ASC
");
$stmt->execute(["program" => $programId]);
$courses = $stmt->fetchAll(PDO::FETCH_ASSOC);

$title = "Programa Técnico";
?>

<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<title><?= htmlspecialchars($title) ?></title>

<style>
body{font-family:Arial;background:#f4f8ff;padding:40px}
.card{background:#fff;padding:25px;border-radius:14px;margin-bottom:25px;max-width:900px;box-shadow:0 10px 25px rgba(0,0,0,.08)}
.item{background:#eef2ff;padding:15px;border-radius:10px;margin-bottom:12px;display:flex;justify-content:space-between;align-items:center}
input{width:100%;padding:12px;margin-top:10px;border-radius:8px;border:1px solid #ddd}
button{margin-top:15px;padding:12px 18px;background:#2563eb;color:#fff;border:none;border-radius:8px;font-weight:700}
a{text-decoration:none;font-weight:bold;color:#2563eb}
.back{display:inline-block;margin-bottom:20px;background:#6b7280;color:#fff;padding:10px 18px;border-radius:8px;text-decoration:none}
.error{color:#dc2626;font-weight:bold;margin-top:10px}
</style>
</head>

<body>

<a class="back" href="../admin/dashboard.php">
← Volver al Dashboard
</a>

<div class="card">
<h2>📘 <?= htmlspecialchars($title) ?></h2>

<h3>➕ Crear Semestre</h3>
<form method="post">
<input type="text" name="course_name" required placeholder="SEMESTRE 1">
<button>Crear</button>
</form>

<?php if ($error): ?>
<div class="error"><?= htmlspecialchars($error) ?></div>
<?php endif; ?>

</div>

<div class="card">
<h3>📋 Semestres creados</h3>

<?php foreach ($courses as $c): ?>
<div class="item">
<strong><?= htmlspecialchars($c["name"]) ?></strong>
<a href="technical_units.php?course=<?= urlencode($c["id"]) ?>">
Administrar →
</a>
</div>
<?php endforeach; ?>

</div>

</body>
</html>
