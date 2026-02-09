/* ===============================
   BUSCAR COURSE EN DB
=============================== */
$stmt = $pdo->prepare("
    SELECT * FROM courses
    WHERE id = :id
    LIMIT 1
");

$stmt->execute([
    "id" => $courseId
]);

$course = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$course) {
    die("Curso no encontrado");
}


/* ===============================
   CREAR UNIT
=============================== */
if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST["unit_name"])) {

    $unitName = trim($_POST["unit_name"]);

    if ($unitName !== "") {

        $unitId = uniqid("unit_");

        $stmtInsert = $pdo->prepare("
            INSERT INTO units (id, course_id, name, position)
            VALUES (:id, :course, :name, 1)
        ");

        $stmtInsert->execute([
            "id" => $unitId,
            "course" => $courseId,
            "name" => $unitName
        ]);

        header("Location: course_view.php?course=" . urlencode($courseId));
        exit;
    }
}


/* ===============================
   OBTENER UNITS
=============================== */
$stmtUnits = $pdo->prepare("
    SELECT *
    FROM units
    WHERE course_id = :course
    ORDER BY position ASC
");

$stmtUnits->execute([
    "course" => $courseId
]);

$units = $stmtUnits->fetchAll(PDO::FETCH_ASSOC);
