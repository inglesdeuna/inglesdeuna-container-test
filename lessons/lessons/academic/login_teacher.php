<?php
session_start();

/* CARGAR DOCENTES */
$file = __DIR__ . "/teachers.json";
$teachers = file_exists($file)
  ? json_decode(file_get_contents($file), true)
  : [];

/* YA LOGUEADO */
if (isset($_SESSION["teacher_id"])) {
  header("Location: dashboard.php");
  exit;
}

$error = "";

/* LOGIN */
if ($_SERVER["REQUEST_METHOD"] === "POST") {
  $id = $_POST["teacher_id"] ?? null;

  foreach ($teachers as $t) {
    if (($t["id"] ?? null) === $id) {
      $_SESSION["teacher_id"] = $id;
      header("Location: dashboard.php");
      exit;
    }
  }
  $error = "Docente no válido";
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<title>Login Docente</title>
</head>
<body>

<h2>👩‍🏫 Login Docente</h2>

<?php if ($error): ?>
<p style="color:red"><?= $error ?></p>
<?php endif; ?>

<form method="post">
  <select name="teacher_id" required>
    <option value="">Seleccione su nombre</option>
    <?php foreach ($teachers as $t): ?>
      <option value="<?= htmlspecialchars($t["id"]) ?>">
        <?= htmlspecialchars($t["name"]) ?>
      </option>
    <?php endforeach; ?>
  </select>
  <button>Ingresar</button>
</form>

</body>
</html>
