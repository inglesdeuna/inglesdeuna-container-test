<?php
session_start();

if (!isset($_SESSION['admin_logged']) || $_SESSION['admin_logged'] !== true) {
    header('Location: ../admin/login.php');
    exit;
}

$dataDir = __DIR__ . '/data';
$teachersFile = $dataDir . '/teachers.json';

if (!is_dir($dataDir)) {
    mkdir($dataDir, 0777, true);
}

if (!file_exists($teachersFile)) {
    file_put_contents($teachersFile, '[]');
}

$teachers = json_decode((string) file_get_contents($teachersFile), true);
$teachers = is_array($teachers) ? $teachers : [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name = trim((string) ($_POST['teacher_name'] ?? ''));
    $idNumber = trim((string) ($_POST['teacher_id_number'] ?? ''));
    $phone = trim((string) ($_POST['teacher_phone'] ?? ''));
    $bank = trim((string) ($_POST['teacher_bank_account'] ?? ''));

    if ($name !== '') {
        $exists = false;
        foreach ($teachers as $teacher) {
            if (mb_strtolower((string) ($teacher['name'] ?? '')) === mb_strtolower($name)) {
                $exists = true;
                break;
            }
        }

        if (!$exists) {
            $teachers[] = [
                'id' => uniqid('teacher_'),
                'name' => $name,
                'id_number' => $idNumber,
                'phone' => $phone,
                'bank_account' => $bank,
            ];
            file_put_contents($teachersFile, json_encode(array_values($teachers), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        }
    }

    header('Location: teacher_enrollments.php?saved=1');
    exit;
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<title>Inscripciones Docentes</title>
<style>
body{font-family:Arial,sans-serif;background:#eef2f7;padding:30px;color:#1f2937}
.wrapper{max-width:1000px;margin:0 auto}
.card{background:#fff;border-radius:14px;padding:20px;box-shadow:0 8px 24px rgba(0,0,0,.08);margin-bottom:18px}
.back{display:inline-block;margin-bottom:15px;color:#1f66cc;text-decoration:none;font-weight:700}
.grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:10px}
.full{grid-column:1/-1}
input,button{font:inherit;padding:10px;border:1px solid #d4dce8;border-radius:8px;width:100%}
button{background:#1f66cc;color:#fff;border:none;font-weight:700;cursor:pointer}
.notice{padding:10px 12px;border-radius:8px;background:#eaf9ef;border:1px solid #bfe7cc;color:#1d6a40;margin-bottom:12px}
ul{margin:0;padding-left:18px}
</style>
</head>
<body>
<div class="wrapper">
  <a class="back" href="../admin/dashboard.php">← Volver al dashboard</a>

  <div class="card">
    <h1>🧾 Inscripción de Docentes</h1>
    <?php if (isset($_GET['saved'])) { ?><div class="notice">Docente guardado correctamente.</div><?php } ?>

    <form method="post" class="grid">
      <input class="full" type="text" name="teacher_name" placeholder="Nombre" required>
      <input type="text" name="teacher_id_number" placeholder="C.C">
      <input type="text" name="teacher_phone" placeholder="Teléfono">
      <input class="full" type="text" name="teacher_bank_account" placeholder="# Cuenta">
      <button class="full" type="submit">Guardar docente</button>
    </form>
  </div>

  <div class="card">
    <h2>Docentes inscritos</h2>
    <?php if (empty($teachers)) { ?>
      <p>No hay docentes inscritos.</p>
    <?php } else { ?>
      <ul>
        <?php foreach ($teachers as $teacher) { ?>
          <li><?php echo htmlspecialchars((string) ($teacher['name'] ?? 'Docente')); ?></li>
        <?php } ?>
      </ul>
    <?php } ?>
  </div>
</div>
</body>
</html>
