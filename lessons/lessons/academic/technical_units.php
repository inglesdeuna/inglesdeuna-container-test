$courseParam = $_GET["course"] ?? null;

if (!$courseParam) {
    die("Curso no especificado.");
}

/* Buscar curso por ID primero */
$stmt = $pdo->prepare("
    SELECT * FROM courses
    WHERE id = :param
    LIMIT 1
");

$stmt->execute(["param" => $courseParam]);
$course = $stmt->fetch(PDO::FETCH_ASSOC);

/* Si no lo encuentra, buscar por nombre (compatibilidad vieja) */
if (!$course) {
    $stmt = $pdo->prepare("
        SELECT * FROM courses
        WHERE name = :param
        LIMIT 1
    ");
    $stmt->execute(["param" => strtoupper($courseParam)]);
    $course = $stmt->fetch(PDO::FETCH_ASSOC);
}

if (!$course) {
    die("Curso no encontrado.");
}

$courseId = $course["id"];
