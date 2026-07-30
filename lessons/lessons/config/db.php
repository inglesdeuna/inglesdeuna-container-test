<?php

$databaseUrl = getenv("DATABASE_URL");

if (!$databaseUrl) {
    die("DATABASE_URL no está definida.");
}

/*
 * Teacher course sidebar shortcut.
 *
 * teacher_course.php currently renders a legacy "My assignments" link in the
 * sidebar. Replace only that exact link at final render time with direct access
 * to the selected unit quiz. The condition keeps every other page that uses
 * this shared database bootstrap completely unchanged.
 */
if (
    basename((string) ($_SERVER['SCRIPT_NAME'] ?? '')) === 'teacher_course.php'
    && !defined('TEACHER_COURSE_QUIZ_SHORTCUT_BUFFER')
) {
    define('TEACHER_COURSE_QUIZ_SHORTCUT_BUFFER', true);

    ob_start(static function (string $html): string {
        $assignmentId = trim((string) ($_GET['assignment'] ?? ''));
        $unitId = trim((string) ($_GET['unit'] ?? ''));

        if ($assignmentId === '' || $unitId === '') {
            return $html;
        }

        $returnTo = (string) ($_SERVER['REQUEST_URI'] ?? '');
        $quizHref = 'teacher_quiz.php?' . http_build_query([
            'assignment' => $assignmentId,
            'unit' => $unitId,
            'return_to' => $returnTo,
        ]);

        $oldLink = '<a class="side-btn gray" href="teacher_assignments.php">🧾 My assignments</a>';
        $newLink = '<a class="side-btn gray" href="'
            . htmlspecialchars($quizHref, ENT_QUOTES, 'UTF-8')
            . '">🧠 Quiz</a>';

        return str_replace($oldLink, $newLink, $html);
    });
}

$parsed = parse_url($databaseUrl);

$host = $parsed["host"];
$port = $parsed["port"] ?? 5432;
$user = $parsed["user"];
$pass = $parsed["pass"];
$db   = ltrim($parsed["path"], "/");

try {

    $dsn = "pgsql:host=$host;port=$port;dbname=$db;sslmode=require;keepalives=1;keepalives_idle=30;keepalives_interval=10;keepalives_count=5";

    $pdo = new PDO($dsn, $user, $pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    ]);

} catch (PDOException $e) {

    die("Error de conexión: " . $e->getMessage());

}
