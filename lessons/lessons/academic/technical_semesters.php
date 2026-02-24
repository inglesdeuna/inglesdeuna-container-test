<?php
session_start();

/**
 * PROGRAMA TÉCNICO
 * Vista directa de semestres 1–4
 */

// 🔐 SOLO ADMIN
if (!isset($_SESSION['admin_logged']) || $_SESSION['admin_logged'] !== true) {
    header("Location: ../admin/login.php");
    exit;
}

/* ==========================
   CONEXIÓN DB
   ========================== */
require_once "../config/db.php";

/* ==========================
   OBTENER SEMESTRES
   ========================== */
$stmt = $pdo->prepare("
    SELECT id, name
    FROM courses
    WHERE program_id = :program
    ORDER BY name ASC
");

$stmt->execute(['program' => 'prog_technical']);
$semesters = $stmt->fetchAll(PDO::FETCH_ASSOC);

?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<title>Programa Técnico</title>

<style>
body{
  font-family:Arial, sans-serif;
  background:#f4f8ff;
  padding:40px;
}

h1{
  color:#2563eb;
  margin-bottom:30px;
}

.card{
  background:#fff;
  padding:30px;
  border-radius:16px;
  max-width:700px;
  box-shadow:0 10px 25px rgba(0,0,0,.08);
}

.semester{
  display:flex;
  justify-content:space-between;
  align-items:center;
  background:#2563eb;
  color:white;
  padding:18px 20px;
  border-radius:12px;
  margin-bottom:15px;
  text-decoration:none;
  font-weight:bold;
}

.semester:hover{
  opacity:0.9;
}

.back{
  display:inline-block;
  margin-bottom:30px;
  text-decoration:none;
  color:#555;
  font-weight:bold;
}
</style>
</head>

<body>

<a class="back" href="../admin/dashboard.php">← Volver al Dashboard</a>

<h1>💻 Programa Técnico — Semestres</h1>

<div class="card">

<?php if (empty($semesters)): ?>

  <p>No hay semestres creados.</p>

<?php else: ?>

  <?php foreach ($semesters as $semester): ?>

    <a class="semester"
       href="course_view.php?course=<?= htmlspecialchars($semester['id']); ?>">
       <?= htmlspecialchars($semester['name']); ?>
       <span>Entrar →</span>
    </a>

  <?php endforeach; ?>

<?php endif; ?>

</div>

</body>
</html>
