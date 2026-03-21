<?php
session_start();

// 1. SEGURIDAD Y LOGIN
if (!isset($_SESSION['academic_logged']) || $_SESSION['academic_logged'] !== true) {
    header('Location: /lessons/lessons/academic/login.php');
    exit;
}

require_once __DIR__ . '/../../config/db.php';

// --- FUNCIONES CORE DEL PROYECTO ---
function h(string $value): string {
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

// Validación de existencia de tablas/columnas para evitar errores de DB
function table_exists(PDO $pdo, string $tableName): bool {
    $stmt = $pdo->prepare("SELECT 1 FROM information_schema.tables WHERE table_name = :t LIMIT 1");
    $stmt->execute(['t' => $tableName]);
    return (bool) $stmt->fetchColumn();
}

// 2. CAPTURA DE PARÁMETROS
$assignmentId = trim((string)($_GET['assignment'] ?? ''));
$unitId       = trim((string)($_GET['unit'] ?? ''));
$activityId   = trim((string)($_GET['activity'] ?? ''));
$teacherId    = (string)($_SESSION['teacher_id'] ?? '');

if ($assignmentId === '' || $unitId === '') {
    die('Información de asignación o unidad incompleta.');
}

// 3. CARGAR CONTEXTO (Actividades de la unidad)
// Buscamos todas las actividades para armar la navegación Previous/Next
$orderBy = table_exists($pdo, 'activities') && true ? "position ASC, id ASC" : "id ASC";
$stmt = $pdo->prepare("SELECT id, type, data FROM activities WHERE unit_id = :uid ORDER BY {$orderBy}");
$stmt->execute(['uid' => $unitId]);
$activities = $stmt->fetchAll(PDO::FETCH_ASSOC);

if (empty($activities)) {
    die('No hay actividades en esta unidad.');
}

// Determinar cuál es la actividad actual (si no se pasa ID, cargar la primera)
$currentActivity = null;
$prevAct = null;
$nextAct = null;
$currentIndex = 0;

if ($activityId === '') {
    $currentActivity = $activities[0];
} else {
    foreach ($activities as $idx => $act) {
        if ($act['id'] == $activityId) {
            $currentActivity = $act;
            $currentIndex = $idx;
            $prevAct = $activities[$idx - 1] ?? null;
            $nextAct = $activities[$idx + 1] ?? null;
            break;
        }
    }
}

if (!$currentActivity) {
    die('Actividad no encontrada.');
}

// 4. PREPARAR DATOS VISUALES
$data = json_decode($currentActivity['data'] ?? '{}', true);
$displayTitle = !empty($data['title']) ? $data['title'] : ucfirst(str_replace('_', ' ', $currentActivity['type']));

// URL del Viewer (Ruta absoluta estandarizada)
$viewerUrl = "/lessons/lessons/activities/{$currentActivity['type']}/viewer.php?id={$currentActivity['id']}&unit={$unitId}&assignment={$assignmentId}";

?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title><?php echo h($displayTitle); ?> - Inglesdeuna</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com">
    <style>
        /* ESTILO UNIFICADO (Línea Pronunciation) */
        body { font-family: 'Segoe UI', Tahoma, sans-serif; background-color: #f8f9fa; margin: 0; padding: 0; color: #333; }
        
        .main-header {
            background: #fff;
            padding: 12px 40px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            border-bottom: 1px solid #eee;
            box-shadow: 0 2px 5px rgba(0,0,0,0.02);
        }

        .activity-info { display: flex; align-items: center; gap: 15px; }
        .activity-info h1 { font-size: 1.1rem; margin: 0; color: #444; }
        .icon-box { width: 35px; height: 35px; background: #e0f7ff; border-radius: 8px; display: flex; align-items: center; justify-content: center; color: #00d4ff; }

        .btn-exit { text-decoration: none; color: #999; font-size: 1.2rem; transition: 0.2s; }
        .btn-exit:hover { color: #f44336; }

        /* EL CONTENEDOR MAESTRO */
        .content-container {
            max-width: 1100px; /* Tamaño optimizado para todas las actividades */
            margin: 30px auto;
            padding: 0 20px;
        }

        .activity-frame-wrapper {
            background: #fff;
            border-radius: 16px;
            box-shadow: 0 15px 35px rgba(0,0,0,0.07);
            overflow: hidden;
            position: relative;
            border: 1px solid #eee;
        }

        iframe {
            width: 100%;
            height: 720px; /* Altura ideal para flipbooks y drag&drop */
            border: none;
            display: block;
        }

        /* NAVEGACIÓN ESTANDARIZADA */
        .navigation-footer {
            margin-top: 25px;
            display: flex;
            justify-content: center;
        }

        .nav-pill {
            background: #fff;
            padding: 10px 30px;
            border-radius: 40px;
            display: flex;
            align-items: center;
            gap: 25px;
            box-shadow: 0 4px 15px rgba(0,0,0,0.05);
            border: 1px solid #efefef;
        }

        .nav-link {
            text-decoration: none;
            font-weight: 700;
            color: #666;
            font-size: 0.9rem;
            display: flex;
            align-items: center;
            gap: 8px;
            transition: 0.2s;
        }

        .nav-link:hover:not(.disabled) { color: #00d4ff; }
        .nav-link.disabled { color: #ccc; cursor: not-allowed; }

        .progress-text {
            font-size: 0.8rem;
            color: #bbb;
            text-transform: uppercase;
            letter-spacing: 1px;
            font-weight: bold;
            padding: 0 20px;
            border-left: 1px solid #eee;
            border-right: 1px solid #eee;
        }

        .btn-finish {
            background: #4caf50;
            color: #fff !important;
            padding: 6px 18px;
            border-radius: 20px;
        }
    </style>
</head>
<body>

    <header class="main-header">
        <div class="activity-info">
            <div class="icon-box"><i class="fas fa-book-open"></i></div>
            <h1><?php echo h($displayTitle); ?></h1>
        </div>
        <a href="teacher_unit.php?assignment=<?php echo $assignmentId; ?>&unit=<?php echo $unitId; ?>" class="btn-exit" title="Cerrar actividad">
            <i class="fas fa-times-circle"></i>
        </a>
    </header>

    <main class="content-container">
        <div class="activity-frame-wrapper">
            <!-- IFRAME MAESTRO -->
            <iframe 
                src="<?php echo $viewerUrl; ?>" 
                allow="autoplay; microphone; fullscreen; clipboard-write;"
                id="main-activity-iframe">
            </iframe>
        </div>

        <footer class="navigation-footer">
            <div class="nav-pill">
                <!-- Anterior -->
                <?php if ($prevAct): ?>
                    <a href="?assignment=<?php echo $assignmentId; ?>&unit=<?php echo $unitId; ?>&activity=<?php echo $prevAct['id']; ?>" class="nav-link">
                        <i class="fas fa-chevron-left"></i> PREVIOUS
                    </a>
                <?php else: ?>
                    <span class="nav-link disabled"><i class="fas fa-chevron-left"></i> PREVIOUS</span>
                <?php endif; ?>

                <div class="progress-text">
                    Activity <?php echo $currentIndex + 1; ?> / <?php echo count($activities); ?>
                </div>

                <!-- Siguiente / Finalizar -->
                <?php if ($nextAct): ?>
                    <a href="?assignment=<?php echo $assignmentId; ?>&unit=<?php echo $unitId; ?>&activity=<?php echo $nextAct['id']; ?>" class="nav-link">
                        NEXT <i class="fas fa-chevron-right"></i>
                    </a>
                <?php else: ?>
                    <a href="teacher_unit.php?assignment=<?php echo $assignmentId; ?>&unit=<?php echo $unitId; ?>" class="nav-link btn-finish">
                        FINISH <i class="fas fa-check-circle"></i>
                    </a>
                <?php endif; ?>
            </div>
        </footer>
    </main>

</body>
</html>
