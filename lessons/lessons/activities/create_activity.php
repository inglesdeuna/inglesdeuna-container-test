<?php
include("db_connect.php");

// Datos recibidos del formulario
$name      = $_POST['name'];
$semester  = $_POST['semester'];

// Determinar el course_id dinámicamente según el semestre
switch ($semester) {
    case 1:
        $course_id = 'tech_sem1';
        break;
    case 2:
        $course_id = 'tech_sem2';
        break;
    case 3:
        $course_id = 'tech_sem3';
        break;
    case 4:
        $course_id = 'tech_sem4';
        break;
    default:
        $course_id = 'tech_sem1'; // fallback por si acaso
}

// Insertar nueva unidad
$query = "INSERT INTO units (name, semester, course_id) VALUES ('$name', '$semester', '$course_id')";
mysqli_query($conn, $query);

// Obtener ID recién creado
$unit_id = mysqli_insert_id($conn);

// Redirigir a la vista de la unidad creada con su course correcto
header("Location: unit_view.php?unit=unit_$unit_id&course=$course_id");
exit;
?>
