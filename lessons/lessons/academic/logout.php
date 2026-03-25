<?php
session_start();

/* DETECTAR ROL ANTES DE DESTRUIR */
$isAdmin   = isset($_SESSION["admin_id"]);
$isTeacher = isset($_SESSION["teacher_id"]);
$isStudent = isset($_SESSION["student_id"]);

/* CERRAR SESION */
$_SESSION = [];

if (ini_get('session.use_cookies')) {
  $params = session_get_cookie_params();
  setcookie(
    session_name(),
    '',
    time() - 42000,
    $params['path'],
    $params['domain'],
    $params['secure'],
    $params['httponly']
  );
}

session_destroy();

/* REDIRECCION SEGUN ROL */
if ($isAdmin) {
  header("Location: ../admin/login.php");
  exit;
}

if ($isTeacher) {
  header("Location: login_teacher.php");
  exit;
}

if ($isStudent) {
  header("Location: login_student.php");
  exit;
}

/* FALLBACK */
header("Location: login_teacher.php");
exit;
