<?php
function getUserRole($course, $session) {

  if (isset($session["admin_id"])) {
    return "editor";
  }

  if (isset($session["teacher_id"])) {
    if (isset($course["teacher"]["id"]) &&
        $course["teacher"]["id"] === $session["teacher_id"]) {
      return "editor";
    }
    return "viewer";
  }

  if (isset($session["student_id"])) {
    foreach ($course["students"] ?? [] as $s) {
      if ($s["id"] === $session["student_id"]) {
        return "viewer";
      }
    }
  }

  return "viewer";
}
