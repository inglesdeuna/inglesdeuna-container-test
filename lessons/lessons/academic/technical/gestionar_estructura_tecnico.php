<?php
require_once("../../config/db.php");

if ($_SERVER["REQUEST_METHOD"] === "POST") {

    $name = trim($_POST["name"]);
    $slug = trim($_POST["slug"]);

    if ($name && $slug) {

        $stmt = $pdo->prepare("INSERT INTO technical_courses (name, slug) VALUES (:name, :slug)");
        $stmt->execute([
            ":name" => $name,
            ":slug" => $slug
        ]);

        header("Location: technical_courses.php");
        exit;
    }
}
?>

<h2>Crear Curso Técnico</h2>

<form method="POST">
    <input type="text" name="name" placeholder="Nombre del curso" required>
    <input type="text" name="slug" placeholder="Slug (ej: tech_sem1)" required>
    <button type="submit">Crear Curso</button>
</form>
