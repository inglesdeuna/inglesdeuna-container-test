<?php
session_start();

/* =====================
   CARGAR DOCENTES
   ===================== */
$teachersFile = __DIR__ . "/teachers.json";
$teachers = [];

if (file_exists($teachersFile)) {
  $raw = file_get_contents($teachersFile);
  $raw = preg_replace('/^\xEF\xBB\xBF/', '', $raw);
  $decoded = json_decode($raw, true);
  if (is_array($decoded)) {
    $teachers = $decoded;
  }
}

/* =====================
   SI YA ESTA LOGUEADO
   ===================== */
if (isset($_SESSION["admin_id"]) || isset($_SESSION["teacher_id"])) {

  if (isset($_SESSION["redirect_after_login"])) {
    $go = $_SESSION["redirect_after_login"];
    unset($_SESSION["redirect_after_login"]);
    header("Location: $go");
    exit;
  }

  // fallback
  if (isset($_SESSION["admin_id"])) {
    header("Location: ../admin/dashboard.php");
  } else {
    header("Location: dashboard.php");
  }
  exit;
}

$error = "";

/* =====================
   PROCESAR LOGIN
   ===================== */
if ($_SERVER["REQUEST_METHOD"] === "POST") {

  $teacherId = $_POST["teacher_id"] ?? null;

  if (!$teacherId) {
    $error = "Seleccione un usuario";
  } else {

    /* ===== ADMIN ===== */
    if ($teacherId === "admin") {
      $_SESSION["admin_id"] = "admin";

      if (isset($_SESSION["redirect_after_login"])) {
        $go = $_SESSION["redirect_after_login"];
        unset($_SESSION["redirect_after_login"]);
        header("Location: $go");
        exit;
      }

      header("Location: ../admin/dashboard.php");
      exit;
    }

    /* ===== DOCENTE ===== */
    foreach ($teachers as $t) {
      if (($t["id"] ?? null) === $teacherId) {

        $_SESSION["teacher_id"] = $teacherId;

        if (isset($_SESSION["redirect_after_login"])) {
          $go = $_SESSION["redirect_after_login"];
          unset($_SESSION["redirect_after_login"]);
          header("Location: $go");
          exit;
        }

        header("Location: dashboard.php");
        exit;
      }
    }

    $error = "Usuario no válido";
  }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<title>Login Académico</title>
<style>
body{font-family:Arial;background:#f4f8ff;padding:40px}
.box{background:#fff;padding:30px;border-radius:14px;max-width:400px;margin:auto}
button,select{padding:10px;width:100%;margin-top:10px}
.error{color:#dc2626;margin-bottom:10px}
</style>
</head>
<body>

<div class="box">
  <h2>🔐 Login Académico</h2>

  <?php if ($error): ?>
    <div class="error"><?= htmlspecialchars($error) ?></div>
  <?php endif; ?>

  <form method="post">
    <select name="teacher_id" required>
      <option value="">Seleccione usuario</option>

      <!-- ADMIN -->
      <option value="admin">Administrador</option>

      <!-- DOCENTES -->
      <?php foreach ($teachers as $t): ?>
        <option value="<?= htmlspecialchars($t["id"]) ?>">
          <?= htmlspecialchars($t["name"]) ?>
        </option>
      <?php endforeach; ?>
    </select>

    <button type="submit">Ingresar</button>
  </form>
</div>

</body>
</html>
