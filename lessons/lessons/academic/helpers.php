<?php
function getUserRole($course, $session) {

  // ADMIN siempre editor
  if (isset($session["admin_id"])) {
    return "editor";
  }

  // CUALQUIER DOCENTE LOGUEADO = editor
  if (isset($session["teacher_id"])) {
    return "editor";
  }

  // ESTUDIANTE
  if (isset($session["student_id"])) {
    return "viewer";
  }

  return "viewer";
}
