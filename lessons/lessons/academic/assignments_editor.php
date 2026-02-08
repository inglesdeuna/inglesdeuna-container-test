<?php
session_start();

/**
 * ASIGNACIONES ACADÉMICAS
 * Curso + Periodo (A/B) + Docente + Estudiantes
 */

// 🔐 SOLO ADMIN
if (!isset($_SESSION['admin_logged']) || $_SESSION['admin_logged'] !== true) {
    header("Location: ../admin/login.php");
    exit;
}

/* ==========================
   DATA (ACADEMIC ES EL DUEÑO)
   ========================== */
$baseDir = __DIR__ . "/data";

// Archivos
$coursesFile     = $baseDir . "/courses.json";
$teachersFile    = $baseDir . "/teachers.json";
$studentsFile    = $baseDir . "/students.json";
$assignmentsFile = $baseDir . "/assignments.json";

/* ==========================
   ASEGURAR ARCHIVOS
   ========================== */
foreach ([$coursesFile, $teachersFile, $studentsFile, $assignmentsFile] as $file) {
    if (!file_exists($file)) {
        file_put_contents($file, "[]");
    }
}

/* ==========================
   CARGAR DATA
   ========================== */
$courses     = json_decode(file_get_contents($coursesFile), true);
$teachers    = json_decode(file_get_contents($teachersFile), true);
$students    = json_decode(file_get_contents($studentsFile), true);
$assignments = json_decode(file_get_contents($assignmentsFile), true);

$courses     = is_array($courses) ? $courses : [];
$teachers    = is_array($teachers) ? $teachers : [];
$students    = is_array($students) ? $students : [];
$assignments = is_array($assignments) ? $assignments : [];

/* ==========================
   PERIODOS (OPERATIVO)
   ========================== */
$periods = ["A", "B"];

/* ==========================
   GUARDAR ASIGNACIÓN
   ========================== */
if ($_SERVER["REQUEST_METHOD"] === "POST") {

    $courseId   = $_POST["course_id"]  ?? "";
    $period     = $_POST["period"]     ?? "";
    $teacherId  = $_POST["teacher_id"] ?? "";
    $studentIds = $_POST["students"]   ?? [];

    if ($courseId && $period && $teacherId) {

        $assignments[] = [
            "id"         => uniqid("assign_"),
            "course_id"  => $courseId,
            "period"     => $period,
            "teacher_id" => $teacherId,
            "students"   => $studentIds
        ];

        file_put_contents(
            $assignmentsFile,
            json_encode($assignments, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)
        );

        header("Location: ../admin/dashboard.php");
        exit;
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<title>Asignar Curso</title>

<style>
body{
  font-family:Arial, sans-serif;
  background:#f4f8ff;
  padding:40px;
}
.card{
  background:#fff;
  padding:25px;
  border-radius:16px;
  max-width:700px;
  box-shadow:0 10px 25px rgba(0,0,0,.08);
}
label{
  display:block;
  margin-top:15px;
  font-weight:bold;
}
select{
  width:100%;
  padding:10px;
  margin-top:6px;
}
button{
  margin-top:25px;
  padding:12px 18px;
  background:#2563eb;
  color:#fff;
  border:none;
  border-radius:8px;
  font-weight:700;
  cursor:pointer;
}
</style>
</head>

<body>

<h1>👥 Asignar Curso</h1>

<div class="card">
<form method="post">

  <label>Curso</label>
  <select name="course_id" required>
    <option value="">Seleccionar curso</option>
    <?php foreach ($courses as $c): ?>
      <option value="<?= htmlspecialchars($c['id']) ?>">
        <?= htmlspecialchars($c['name']) ?>
      </option>
    <?php endforeach; ?>
  </select>

  <label>Periodo</label>
  <select name="period" required>
    <option value="">Seleccionar periodo</option>
    <?php foreach ($periods as $p): ?>
      <option value="<?= $p ?>">Periodo <?= $p ?></option>
    <?php endforeach; ?>
  </select>

  <label>Docente</label>
  <select name="teacher_id" required>
    <option value="">Seleccionar docente</option>
    <?php foreach ($teachers as $t): ?>
      <option value="<?= htmlspecialchars($t['id']) ?>">
        <?= htmlspecialchars($t['name']) ?>
      </option>
    <?php endforeach; ?>
  </select>

  <label>Estudiantes</label>
  <select name="students[]" multiple size="6">
    <?php foreach ($students as $s): ?>
      <option value="<?= htmlspecialchars($s['id']) ?>">
        <?= htmlspecialchars($s['name']) ?>
      </option>
    <?php endforeach; ?>
  </select>

  <button>Guardar asignación</button>

</form>
</div>

</body>
</html>
