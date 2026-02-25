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

/* ===============================
   CREAR SEMESTRE (SIN REPETIR)
=============================== */
$error = "";

if ($_SERVER["REQUEST_METHOD"] === "POST" && !empty($_POST["course_name"])) {

    $name = strtoupper(trim($_POST["course_name"]));

    $validSemesters = ["SEMESTRE 1", "SEMESTRE 2", "SEMESTRE 3", "SEMESTRE 4"];

    if (!in_array($name, $validSemesters)) {
        $error = "Solo se permiten SEMESTRE 1, 2, 3 o 4.";
    } else {

        $check = $pdo->prepare("
            SELECT id FROM courses
            WHERE program_id = :program_id
            AND name = :name
            LIMIT 1
        ");

        $check->execute([
            "program_id" => $programId,
            "name" => $name
        ]);

        if ($check->fetch()) {
            $error = "Ese semestre ya existe.";
        } else {

            $courseId = uniqid("tech_sem");

            $stmt = $pdo->prepare("
                INSERT INTO courses (id, program_id, name)
                VALUES (:id, :program_id, :name)
            ");

            $stmt->execute([
                "id" => $courseId,
                "program_id" => $programId,
                "name" => $name
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
    SELECT id, name
    FROM courses
    WHERE program_id = :program
    ORDER BY name ASC
");

$stmt->execute([
    "program" => $programId
]);

$courses = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>

<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<title>Programa Técnico</title>
<style>
body{font-family:Arial;background:#f4f8ff;padding:40px}
h1{color:#2563eb;margin-bottom:25px}
.card{background:#fff;padding:25px;border-radius:12px;margin-bottom:25px;max-width:800px}
.item{background:#f1f5f9;padding:15px;border-radius:10px;margin-bottom:12px;display:flex;justify-content:space-between;align-items:center}
a{text-decoration:none;color:#2563eb;font-weight:bold}
input{width:100%;padding:12px;margin-top:10px}
button{margin-top:15px;padding:12px 18px;background:#2563eb;color:#fff;border:none;border-radius:8px;font-weight:700}
.back{display:inline-block;margin-bottom:20px;background:#6b7280;color:#fff;padding:10px 18px;border-radius:8px;text-decoration:none}
.error{color:#dc2626;font-weight:bold;margin-top:10px}
</style>
</head>
<body>

<a class="back" href="../admin/dashboard.php">
← Volver al Dashboard
</a>

<h1>📘 Programa Técnico</h1>

<div class="card">
  <h2>➕ Crear Semestre</h2>
  <form method="post">
    <input type="text" name="course_name" required placeholder="SEMESTRE 1">
    <button>Crear</button>
  </form>

  <?php if ($error): ?>
    <div class="error"><?= htmlspecialchars($error) ?></div>
  <?php endif; ?>
</div>

<div class="card">
  <h2>📋 Semestres creados</h2>

  <?php if (empty($courses)): ?>
    <p>No hay semestres creados.</p>
  <?php else: ?>
    <?php foreach ($courses as $c): ?>
      <div class="item">
        <strong><?= htmlspecialchars($c["name"]) ?></strong>
        <a href="technical_units.php?course=<?= urlencode($c["id"]) ?>">
          Administrar →
        </a>
      </div>
    <?php endforeach; ?>
  <?php endif; ?>

</div>

</body>
</html>
