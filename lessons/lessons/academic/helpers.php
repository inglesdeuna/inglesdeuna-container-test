<?php
function getUserRole($course, $session) {

  // ADMIN siempre editor
  if (isset($session["admin_id"])) {
    return "editor";
  }

  // DOCENTE
  if (isset($session["teacher_id"])) {
    if (
      isset($course["teacher"]["id"]) &&
      $course["teacher"]["id"] === $session["teacher_id"]
    ) {
      return $course["teacher"]["role"] ?? "editor";
    }
  }

  // ESTUDIANTE
  if (isset($session["student_id"])) {
    foreach ($course["students"] ?? [] as $s) {
      if ($s["id"] === $session["student_id"]) {
        return $s["role"] ?? "viewer";
      }
    }
  }

  return "viewer";
}
