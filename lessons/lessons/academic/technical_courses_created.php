<?php
session_start();

if (!isset($_SESSION["admin_logged"]) || $_SESSION["admin_logged"] !== true) {
    header("Location: ../admin/login.php");
    exit;
}

require __DIR__ . "/../config/db.php";

/* ===============================
   AUTO-MIGRATE: technical_modules
=============================== */
try {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS technical_modules (
            id SERIAL PRIMARY KEY,
            course_id INTEGER NOT NULL,
            name TEXT NOT NULL,
            created_at TIMESTAMP DEFAULT NOW()
        )
    ");
} catch (Throwable $e) {}

/* ===============================
   OBTENER PROGRAMA TÉCNICO
=============================== */
$stmtProgram = $pdo->prepare("
    SELECT id FROM programs
    WHERE slug = 'prog_technical'
    LIMIT 1
");
$stmtProgram->execute();
$program = $stmtProgram->fetch(PDO::FETCH_ASSOC);

if (!$program) {
    die("Programa técnico no encontrado.");
}

$programId = $program["id"];

/* ===============================
   OBTENER SEMESTRES Y SUS MÓDULOS
=============================== */
$stmt = $pdo->prepare("
    SELECT
        c.id   AS semester_id,
        c.name AS semester_name,
        m.id   AS module_id,
        m.name AS module_name
    FROM courses c
    LEFT JOIN technical_modules m ON m.course_id = c.id
    WHERE c.program_id = :program_id
    ORDER BY c.id ASC, m.id ASC
");
$stmt->execute(["program_id" => $programId]);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

/* Agrupar por Semestre */
$semesters = [];
foreach ($rows as $row) {
    $semId = $row['semester_id'];
    if (!isset($semesters[$semId])) {
        $semesters[$semId] = [
            'id'      => $semId,
            'name'    => $row['semester_name'],
            'modules' => []
        ];
    }
    if (!empty($row['module_id'])) {
        $semesters[$semId]['modules'][] = [
            'id'   => $row['module_id'],
            'name' => $row['module_name']
        ];
    }
}
?>

<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<title>Cursos creados - Técnico</title>

<style>
:root{
    --bg:#eef7f0;
    --card:#ffffff;
    --line:#d8e8dc;
    --text:#1f3b28;
    --green:#2f9e44;
    --green-dark:#237a35;
    --gray:#6f7e73;
    --shadow:0 10px 24px rgba(0,0,0,.08);
}
body{
    font-family: Arial, sans-serif;
    background:var(--bg);
    padding:40px;
    color:var(--text);
}

.container{
    max-width:1000px;
    margin:0 auto;
}

.back{
    display:inline-block;
    margin-bottom:25px;
    background:linear-gradient(180deg,#7b8b7f,#66756a);
    color:#fff;
    padding:10px 18px;
    border-radius:8px;
    text-decoration:none;
    font-weight:600;
}

.card{
    background:var(--card);
    padding:30px;
    border-radius:18px;
    box-shadow:var(--shadow);
    border:1px solid var(--line);
    margin-bottom:25px;
}

.semester-title{
    font-size:20px;
    font-weight:700;
    margin-bottom:15px;
}

.module-item{
    background:#f7fcf8;
    border:1px solid var(--line);
    padding:15px 18px;
    border-radius:12px;
    margin-bottom:12px;
    display:flex;
    justify-content:space-between;
    align-items:center;
}

.btn{
    background:linear-gradient(180deg,var(--green),var(--green-dark));
    color:#fff;
    padding:8px 16px;
    border-radius:8px;
    text-decoration:none;
    font-weight:600;
}
</style>
</head>

<body>

<div class="container">

    <a class="back" href="../admin/dashboard.php">
        ← Volver
    </a>

    <div class="card">
        <h2>📋 Cursos creados - Técnico</h2>

        <?php if (empty($semesters)): ?>
            <p>No hay estructura creada.</p>
        <?php else: ?>

            <?php foreach ($semesters as $semester): ?>

                <div class="card">
                    <div class="semester-title">
                        <?= htmlspecialchars($semester['name']); ?>
                    </div>

                    <?php if (empty($semester['modules'])): ?>
                        <p>No hay módulos creados.
                            <a href="technical_modules_view.php?course=<?= urlencode($semester['id']); ?>">
                                Agregar módulos →
                            </a>
                        </p>
                    <?php else: ?>

                        <?php foreach ($semester['modules'] as $module): ?>
                            <div class="module-item">
                                <strong><?= htmlspecialchars($module['name']); ?></strong>

                                <a class="btn"
                                   href="technical_units_view.php?module=<?= urlencode($module['id']); ?>">
                                    Ver →
                                </a>
                            </div>
                        <?php endforeach; ?>

                    <?php endif; ?>

                </div>

            <?php endforeach; ?>

        <?php endif; ?>

    </div>

</div>

</body>
</html>
