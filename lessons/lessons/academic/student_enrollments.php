<?php
session_start();

if (!isset($_SESSION['admin_logged']) || $_SESSION['admin_logged'] !== true) {
    header('Location: ../admin/login.php');
    exit;
}

$dataDir = __DIR__ . '/data';
$studentsFile = $dataDir . '/students.json';

if (!is_dir($dataDir)) {
    mkdir($dataDir, 0777, true);
}

if (!file_exists($studentsFile)) {
    file_put_contents($studentsFile, '[]');
}

$students = json_decode((string) file_get_contents($studentsFile), true);
$students = is_array($students) ? $students : [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name = trim((string) ($_POST['student_name'] ?? ''));
    $guardian = trim((string) ($_POST['student_guardian'] ?? ''));
    $contact = trim((string) ($_POST['student_contact'] ?? ''));
    $eps = trim((string) ($_POST['student_eps'] ?? ''));

    if ($name !== '') {
        $exists = false;
        foreach ($students as $student) {
            if (mb_strtolower((string) ($student['name'] ?? '')) === mb_strtolower($name)) {
                $exists = true;
                break;
            }
        }

        if (!$exists) {
            $students[] = [
                'id' => uniqid('student_'),
                'name' => $name,
                'guardian' => $guardian,
                'contact' => $contact,
                'eps' => $eps,
            ];
            file_put_contents($studentsFile, json_encode(array_values($students), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        }
    }

    header('Location: student_enrollments.php?saved=1');
    exit;
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<title>Inscripciones Estudiantes</title>
<style>
body{font-family:Arial,sans-serif;background:#eef2f7;padding:30px;color:#1f2937}
.wrapper{max-width:1000px;margin:0 auto}
.card{background:#fff;border-radius:14px;padding:20px;box-shadow:0 8px 24px rgba(0,0,0,.08);margin-bottom:18px}
.back{display:inline-block;margin-bottom:15px;color:#1f66cc;text-decoration:none;font-weight:700}
.grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:10px}
.full{grid-column:1/-1}
input,button{font:inherit;padding:10px;border:1px solid #d4dce8;border-radius:8px;width:100%}
button{background:#7c3aed;color:#fff;border:none;font-weight:700;cursor:pointer}
.notice{padding:10px 12px;border-radius:8px;background:#eaf9ef;border:1px solid #bfe7cc;color:#1d6a40;margin-bottom:12px}
ul{margin:0;padding-left:18px}
</style>
</head>
<body>
<div class="wrapper">
  <a class="back" href="../admin/dashboard.php">← Volver al dashboard</a>

  <div class="card">
    <h1>🧾 Inscripción de Estudiantes</h1>
    <?php if (isset($_GET['saved'])) { ?><div class="notice">Estudiante guardado correctamente.</div><?php } ?>

    <form method="post" class="grid">
      <input class="full" type="text" name="student_name" placeholder="Nombre" required>
      <input type="text" name="student_guardian" placeholder="Acudientes">
      <input type="text" name="student_contact" placeholder="Contacto">
      <input class="full" type="text" name="student_eps" placeholder="EPS">
      <button class="full" type="submit">Guardar estudiante</button>
    </form>
  </div>

  <div class="card">
    <h2>Estudiantes inscritos</h2>
    <?php if (empty($students)) { ?>
      <p>No hay estudiantes inscritos.</p>
    <?php } else { ?>
      <ul>
        <?php foreach ($students as $student) { ?>
          <li><?php echo htmlspecialchars((string) ($student['name'] ?? 'Estudiante')); ?></li>
        <?php } ?>
      </ul>
    <?php } ?>
  </div>
</div>
</body>
</html>
