<?php
// DEPURACIÓN DE CURSOS MANAGER

// Mostrar errores en pantalla (solo mientras depuras)
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Conexión a la base de datos
include_once("../config/db.php"); // Ajusta la ruta si tu archivo de conexión está en otro lugar

// Verificar que el parámetro "program" llega
if (!isset($_GET['program'])) {
    die("ERROR: Falta el parámetro 'program'.");
}

$program = $_GET['program'];

// Consulta de cursos creados
$query = "SELECT id, name, description FROM courses WHERE program = ?";
$stmt = $conn->prepare($query);
$stmt->bind_param("s", $program);
$stmt->execute();
$result = $stmt->get_result();

// Layout con estilos y botones
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Cursos creados</title>
    <link rel="stylesheet" href="../assets/css/bootstrap.min.css"> <!-- Mantiene diseño -->
</head>
<body class="container mt-4">
    <h2 class="mb-4">Cursos creados - <?php echo htmlspecialchars($program); ?></h2>

    <table class="table table-bordered table-striped">
        <thead class="table-dark">
            <tr>
                <th>ID</th>
                <th>Nombre</th>
                <th>Descripción</th>
                <th>Acciones</th>
            </tr>
        </thead>
        <tbody>
            <?php if ($result->num_rows > 0): ?>
                <?php while ($row = $result->fetch_assoc()): ?>
                    <tr>
                        <td><?php echo $row['id']; ?></td>
                        <td><?php echo htmlspecialchars($row['name']); ?></td>
                        <td><?php echo htmlspecialchars($row['description']); ?></td>
                        <td>
                            <a href="edit_course.php?id=<?php echo $row['id']; ?>" class="btn btn-sm btn-warning">Editar</a>
                            <a href="view_course.php?id=<?php echo $row['id']; ?>" class="btn btn-sm btn-info">Ver</a>
                            <a href="delete_course.php?id=<?php echo $row['id']; ?>" class="btn btn-sm btn-danger">Eliminar</a>
                        </td>
                    </tr>
                <?php endwhile; ?>
            <?php else: ?>
                <tr>
                    <td colspan="4" class="text-center">No hay cursos creados para este programa.</td>
                </tr>
            <?php endif; ?>
        </tbody>
    </table>

    <a href="../admin/dashboard.php" class="btn btn-secondary">Volver al Dashboard</a>
</body>
</html>
