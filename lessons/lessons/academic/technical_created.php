<?php
session_start();

if (!isset($_SESSION["admin_logged"]) || $_SESSION["admin_logged"] !== true) {
    header("Location: ../admin/login.php");
    exit;
}

require __DIR__ . "/../config/db.php";

/* ===============================
   OBTENER SEMESTRES PROGRAMA TÉCNICO
=============================== */
$stmt = $pdo->prepare("
    SELECT * FROM courses
    WHERE program_id = 'prog_technical'
    ORDER BY name ASC
");
$stmt->execute();
$semesters = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>

<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<title>Cursos creados</title>

<style>
body{font-family:Arial;background:#f4f8ff;padding:40px}
.card{background:#fff;padding:25px;border-radius:16px;margin-bottom:25px;max-width:950px;box-shadow:0 10px 25px rgba(0,0,0,.08)}
.semester-box{background:#eef2ff;padding:18px;border-radius:12px;margin-bottom:15px}
.semester-title{font-weight:bold;font-size:16px;margin-bottom:12px}
.unit-item{background:#ffffff;padding:12px 15px;border-radius:10px;margin-bottom:10px;display:flex;justify-content:space-between;align-items:center;border:1px solid #e5e7eb}
.btn{background:#2563eb;color:#fff;padding:8px 14px;border-radius:8px;text-decoration:none;font-weight:600;font-size:14px}
.back{display:inline-block;margin-bottom:20px;background:#6b7280;color:#fff;padding:10px 18px;border-radius:8px;text-decoration:none}
.empty{color:#6b7280;font-size:14px}
</style>
</head>

<body>

<a class="back" href="../admin/dashboard.php">
← Volver al Dashboard
</a>

<div class="card">
<h2>📘 Programa Técnico — Cursos creados</h2>

<?php if (empty($semesters)): ?>
    <p>No hay semestres creados.</p>
<?php else: ?>

    <?php foreach ($semesters as $semester): ?>

        <div class="semester-box">
            <div class="semester-title">
                <?= htmlspecialchars($semester["name"]); ?>
            </div>

            <?php
            $stmtUnits = $pdo->prepare("
                SELECT * FROM units
                WHERE course_id = :course
                ORDER BY created_at ASC
            ");
            $stmtUnits->execute([
                "course" => $semester["id"]
            ]);
            $units = $stmtUnits->fetchAll(PDO::FETCH_ASSOC);
            ?>

            <?php if (empty($units)): ?>
                <div class="empty">Sin unidades creadas.</div>
            <?php else: ?>

                <?php foreach ($units as $unit): ?>
                    <div class="unit-item">
                        <div>
                            <?= htmlspecialchars($unit["name"]); ?>
                        </div>

                        <!-- RUTA FINAL CORRECTA -->
                        <a class="btn"
                           href="unit_view.php?unit=<?= urlencode($unit["id"]); ?>">
                           Ver Actividades →
                        </a>
                    </div>
                <?php endforeach; ?>

            <?php endif; ?>

        </div>

    <?php endforeach; ?>

<?php endif; ?>

</div>

</body>
</html>
