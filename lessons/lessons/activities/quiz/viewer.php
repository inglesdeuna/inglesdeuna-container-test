<?php
// ======================================================
// QUIZ VIEWER.PHP
// Versión corregida: sin salida antes de session_start()
// y sin header() después del HTML
// ======================================================

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../../../lessons/core/db.php';

if (!function_exists('get_pdo')) {
    die('<div style="color:red;text-align:center;margin-top:40px;">Error: No se encontró la función get_pdo(). Verifica que el archivo db.php la defina correctamente.</div>');
}

// ------------------------------------------------------
// Funciones auxiliares
// ------------------------------------------------------

function get_questions_by_type($questions, $type) {
    return array_values(array_filter($questions, function($q) use ($type) {
        return strtolower($q['type'] ?? '') === $type;
    }));
}

function redirect_to_step($step, $unit_id, $assignment, $q = null) {
    $url = '?step=' . intval($step) . '&unit=' . intval($unit_id) . '&assignment=' . intval($assignment);

    if ($q !== null) {
        $url .= '&q=' . intval($q);
    }

    header('Location: ' . $url);
    exit;
}

function safe_json_decode($value) {
    if ($value === null || $value === '') {
        return [];
    }

    $decoded = json_decode($value, true);

    return is_array($decoded) ? $decoded : [];
}

function is_valid_question($q, $type) {
    if ($type === 'multiple_choice') {
        $question = trim((string)($q['question'] ?? ''));
        $options = safe_json_decode($q['options'] ?? '');
        return $question !== '' && count($options) > 0;
    }

    if ($type === 'fill') {
        $question = trim((string)($q['question'] ?? ''));
        $answer = trim((string)($q['answer'] ?? ''));
        return $question !== '' && $answer !== '';
    }

    if ($type === 'match') {
        $pairs = safe_json_decode($q['pairs'] ?? '');
        return count($pairs) > 0;
    }

    if ($type === 'dictation') {
        $audio = trim((string)($q['audio'] ?? ''));
        $answer = trim((string)($q['answer'] ?? ''));
        return $audio !== '' && $answer !== '';
    }

    if ($type === 'pronunciation') {
        $prompt = trim((string)($q['prompt'] ?? ''));
        $expected = trim((string)($q['expected'] ?? ''));
        return $prompt !== '' && $expected !== '';
    }

    return false;
}

function find_next_valid_index($questions, $type, $startIndex) {
    $total = count($questions);

    for ($i = $startIndex; $i < $total; $i++) {
        if (is_valid_question($questions[$i], $type)) {
            return $i;
        }
    }

    return null;
}

function normalize_answer($value) {
    return strtolower(trim((string)$value));
}

// ------------------------------------------------------
// Parámetros
// ------------------------------------------------------

$step = isset($_GET['step']) ? intval($_GET['step']) : 0;
if ($step < 0 || $step > 7) {
    $step = 0;
}

$unit_id = isset($_GET['unit']) ? intval($_GET['unit']) : 0;
$assignment = isset($_GET['assignment']) ? intval($_GET['assignment']) : 0;

if (!$unit_id) {
    die('<div style="color:red;text-align:center;margin-top:40px;">Error: Falta unit_id en la URL.</div>');
}

// ------------------------------------------------------
// Cargar actividades desde la base de datos
// ------------------------------------------------------

try {
    $pdo = get_pdo();
    $stmt = $pdo->prepare("SELECT * FROM activities WHERE unit_id = :unit_id ORDER BY id ASC");
    $stmt->execute(['unit_id' => $unit_id]);
    $activities = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    die('<div style="color:red;text-align:center;margin-top:40px;">Error de conexión: ' . htmlspecialchars($e->getMessage()) . '</div>');
}

if (!$activities) {
    die('<div style="color:#f14902;text-align:center;margin-top:40px;">No hay actividades para esta unidad.</div>');
}

// ------------------------------------------------------
// Clasificar preguntas
// ------------------------------------------------------

$questions = [];

$type_counts = [
    'multiple_choice' => 0,
    'fill' => 0,
    'match' => 0,
    'dictation' => 0,
    'pronunciation' => 0,
];

foreach ($activities as $act) {
    $type = strtolower($act['type'] ?? '');

    if (isset($type_counts[$type])) {
        $type_counts[$type]++;
        $questions[] = $act;
    }
}

// ------------------------------------------------------
// Inicializar respuestas de sesión
// ------------------------------------------------------

if (!isset($_SESSION['quiz_answers'])) {
    $_SESSION['quiz_answers'] = [];
}

$answers = &$_SESSION['quiz_answers'];

// ------------------------------------------------------
// Redirección anticipada para pasos vacíos o preguntas inválidas
// IMPORTANTE: esto va antes de cualquier HTML.
// ------------------------------------------------------

$step_type_map = [
    1 => 'multiple_choice',
    2 => 'fill',
    3 => 'match',
    4 => 'dictation',
    5 => 'pronunciation',
];

$step_answer_key_map = [
    1 => 'mc',
    2 => 'fill',
    3 => 'match',
    4 => 'dictation',
    5 => 'pronunciation',
];

if (isset($step_type_map[$step])) {
    $currentType = $step_type_map[$step];
    $currentQuestions = get_questions_by_type($questions, $currentType);
    $qIdx = isset($_GET['q']) ? intval($_GET['q']) : 0;

    if ($qIdx < 0) {
        $qIdx = 0;
    }

    $totalCurrent = count($currentQuestions);

    if ($totalCurrent === 0) {
        redirect_to_step($step + 1, $unit_id, $assignment);
    }

    if ($qIdx >= $totalCurrent) {
        $qIdx = $totalCurrent - 1;
    }

    $nextValidIndex = find_next_valid_index($currentQuestions, $currentType, $qIdx);

    if ($nextValidIndex === null) {
        redirect_to_step($step + 1, $unit_id, $assignment);
    }

    if ($nextValidIndex !== $qIdx) {
        redirect_to_step($step, $unit_id, $assignment, $nextValidIndex);
    }
}

// ------------------------------------------------------
// Procesar formularios POST antes del HTML
// ------------------------------------------------------

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($step_type_map[$step])) {
    $currentType = $step_type_map[$step];
    $answerKey = $step_answer_key_map[$step];
    $currentQuestions = get_questions_by_type($questions, $currentType);
    $qIdx = isset($_GET['q']) ? intval($_GET['q']) : 0;

    if ($qIdx < 0) {
        $qIdx = 0;
    }

    $totalCurrent = count($currentQuestions);

    if ($totalCurrent > 0 && $qIdx >= $totalCurrent) {
        $qIdx = $totalCurrent - 1;
    }

    if ($step === 3 && isset($_POST['answer']) && is_array($_POST['answer'])) {
        $answers[$answerKey][$qIdx] = $_POST['answer'];
    } elseif (isset($_POST['answer'])) {
        $answers[$answerKey][$qIdx] = trim((string)$_POST['answer']);
    }

    if ($qIdx + 1 < $totalCurrent) {
        redirect_to_step($step, $unit_id, $assignment, $qIdx + 1);
    } else {
        redirect_to_step($step + 1, $unit_id, $assignment);
    }
}

// ------------------------------------------------------
// A partir de aquí ya puede empezar el HTML
// ------------------------------------------------------

$type_labels = [
    'multiple_choice' => ['Multiple choice', 'Pick the correct answer', 'ti-list-check'],
    'fill' => ['Fill in the blank', 'Complete the sentence', 'ti-input-cursor'],
    'match' => ['Match pairs', 'Connect each word to its pair', 'ti-arrows-shuffle'],
    'dictation' => ['Dictation', 'Listen and write what you hear', 'ti-microphone'],
    'pronunciation' => ['Pronunciation', 'Say the phrase', 'ti-mood-smile'],
];

$steps = [
    'Intro',
    'Multiple choice',
    'Fill in blank',
    'Match',
    'Dictation',
    'Pronunciation',
    'Resultado',
    'Review'
];

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Unit Quiz</title>

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Fredoka:wght@500;600&family=Nunito:wght@500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@tabler/icons-webfont@latest/tabler-icons.min.css">

    <style>
        <?php
        $styleFile = __DIR__ . '/quiz_mockup_style.css';
        if (file_exists($styleFile)) {
            echo file_get_contents($styleFile);
        }
        ?>

        html, body {
            min-height: 100%;
        }

        body {
            margin: 0;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            background: #F8F7FF;
            font-family: 'Nunito', sans-serif;
        }

        .quiz-container {
            width: 100%;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 24px;
            box-sizing: border-box;
        }

        .qz-wrap {
            width: 100%;
            max-width: 430px;
            background: #ffffff;
            border-radius: 24px;
            padding: 28px;
            box-shadow: 0 2px 24px rgba(124, 58, 237, 0.14);
            box-sizing: border-box;
        }

        .qm-tabs {
            position: fixed;
            right: 24px;
            top: 240px;
            display: flex;
            flex-direction: column;
            gap: 8px;
            z-index: 5;
        }

        .qm-tab {
            border: 0;
            border-radius: 999px;
            background: #ede9fe;
            color: #6d5fb3;
            padding: 7px 12px;
            font-weight: 700;
            font-size: 12px;
        }

        .qm-tab.on {
            background: #7c3aed;
            color: white;
        }

        .qz-kicker {
            font-weight: 800;
            color: #7c3aed;
            font-size: 13px;
            letter-spacing: .05em;
            text-transform: uppercase;
            margin-bottom: 8px;
        }

        .qz-title {
            font-family: 'Fredoka', sans-serif;
            font-weight: 700;
            font-size: 34px;
            color: #2d2359;
            margin-bottom: 8px;
        }

        .qz-lead {
            color: #6f6a82;
            font-size: 15px;
            line-height: 1.45;
            margin-bottom: 18px;
        }

        .qz-chips {
            display: flex;
            gap: 8px;
            flex-wrap: wrap;
            margin-bottom: 18px;
        }

        .qz-chip {
            background: #f3f0ff;
            color: #6d5fb3;
            padding: 8px 10px;
            border-radius: 999px;
            font-size: 13px;
            font-weight: 700;
        }

        .qz-intro-divider {
            border: 0;
            border-top: 1px solid #eee9ff;
            margin: 18px 0;
        }

        .qz-type-row {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 12px;
            border-radius: 16px;
            background: #fbfaff;
            margin-bottom: 10px;
        }

        .qz-type-icon {
            width: 38px;
            height: 38px;
            border-radius: 14px;
            background: #ede9fe;
            color: #7c3aed;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 20px;
        }

        .qz-type-info {
            flex: 1;
        }

        .qz-type-name {
            font-weight: 800;
            color: #2d2359;
        }

        .qz-type-desc {
            font-size: 12px;
            color: #8a849b;
        }

        .qz-type-count {
            font-weight: 800;
            color: #7c3aed;
        }

        .qz-btn-start,
        .btn-primary {
            width: 100%;
            border: 0;
            border-radius: 14px;
            background: #7c3aed;
            color: #ffffff;
            padding: 14px 16px;
            font-weight: 800;
            font-size: 16px;
            cursor: pointer;
        }

        .qz-prog-head {
            display: flex;
            justify-content: space-between;
            font-weight: 800;
            color: #6d5fb3;
            font-size: 14px;
            margin-bottom: 8px;
        }

        .qz-prog-track {
            height: 10px;
            border-radius: 999px;
            overflow: hidden;
            background: #ede9fe;
            margin-bottom: 20px;
        }

        .qz-prog-fill {
            height: 100%;
            background: #7c3aed;
        }

        .qz-section-tag {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            background: #f3f0ff;
            color: #7c3aed;
            border-radius: 999px;
            padding: 8px 12px;
            font-weight: 800;
            margin-bottom: 16px;
        }

        .qz-q-text {
            font-size: 20px;
            color: #2d2359;
            font-weight: 800;
            line-height: 1.35;
            margin-bottom: 18px;
        }

        .qz-options {
            display: flex;
            flex-direction: column;
            gap: 10px;
        }

        .qz-opt {
            display: block;
            border: 2px solid #eee9ff;
            border-radius: 16px;
            padding: 14px;
            cursor: pointer;
            color: #2d2359;
            font-weight: 700;
            background: #ffffff;
        }

        .qz-opt input {
            margin-right: 8px;
        }

        .qz-opt-letter {
            display: inline-flex;
            width: 26px;
            height: 26px;
            align-items: center;
            justify-content: center;
            border-radius: 999px;
            background: #ede9fe;
            color: #7c3aed;
            margin-right: 8px;
            font-weight: 900;
        }

        .form-control,
        .form-select {
            width: 100%;
            border: 2px solid #eee9ff;
            border-radius: 14px;
            padding: 13px 14px;
            box-sizing: border-box;
            font-size: 16px;
            font-family: 'Nunito', sans-serif;
            margin-bottom: 14px;
        }

        .qz-match-row {
            margin-bottom: 14px;
        }

        .qz-match-left {
            display: block;
            font-weight: 800;
            color: #2d2359;
            margin-bottom: 6px;
        }

        .screen-label {
            display: none;
        }

        .review-card {
            border: 2px solid #eee9ff;
            border-radius: 16px;
            padding: 16px;
            margin-bottom: 14px;
        }

        .review-type {
            font-weight: 900;
            color: #7c3aed;
            margin-bottom: 8px;
        }

        @media (max-width: 900px) {
            .qm-tabs {
                display: none;
            }
        }
    </style>
</head>

<body>
<div class="quiz-container">

    <div class="qm-tabs">
        <?php foreach ($steps as $i => $label): ?>
            <button class="<?php echo $i === $step ? 'qm-tab on' : 'qm-tab'; ?>">
                <?php echo htmlspecialchars($label); ?>
            </button>
        <?php endforeach; ?>
    </div>

    <?php if ($step === 0): ?>

        <div class="qz-wrap">
            <div class="qz-kicker">UNIT <?php echo htmlspecialchars($unit_id); ?> · QUIZ</div>
            <div class="qz-title">Unit Quiz</div>
            <div class="qz-lead">Answer all questions to complete this unit and unlock the next one.</div>

            <div class="qz-chips">
                <div class="qz-chip"><i class="ti ti-list-ol"></i> <?php echo count($questions); ?> questions</div>
                <div class="qz-chip"><i class="ti ti-clock"></i> ~8 min</div>
                <div class="qz-chip"><i class="ti ti-refresh"></i> 3 attempts</div>
            </div>

            <hr class="qz-intro-divider">

            <div style="font-weight:800;color:#9B8FCC;margin-bottom:10px;letter-spacing:.04em;font-size:14px;">
                WHAT'S INCLUDED
            </div>

            <div class="qz-intro-types">
                <?php foreach ($type_labels as $type => $info): ?>
                    <?php if (($type_counts[$type] ?? 0) < 1) continue; ?>
                    <div class="qz-type-row">
                        <span class="qz-type-icon">
                            <i class="ti <?php echo htmlspecialchars($info[2]); ?>"></i>
                        </span>
                        <div class="qz-type-info">
                            <div class="qz-type-name"><?php echo htmlspecialchars($info[0]); ?></div>
                            <div class="qz-type-desc"><?php echo htmlspecialchars($info[1]); ?></div>
                        </div>
                        <span class="qz-type-count"><?php echo intval($type_counts[$type]); ?></span>
                    </div>
                <?php endforeach; ?>
            </div>

            <form method="get">
                <input type="hidden" name="step" value="1">
                <input type="hidden" name="unit" value="<?php echo htmlspecialchars($unit_id); ?>">
                <input type="hidden" name="assignment" value="<?php echo htmlspecialchars($assignment); ?>">
                <button class="qz-btn-start" type="submit">
                    Start quiz <i class="ti ti-arrow-right"></i>
                </button>
            </form>
        </div>

    <?php elseif ($step >= 1 && $step <= 5): ?>

        <?php
        $currentType = $step_type_map[$step];
        $answerKey = $step_answer_key_map[$step];
        $currentQuestions = get_questions_by_type($questions, $currentType);

        $qIdx = isset($_GET['q']) ? intval($_GET['q']) : 0;
        if ($qIdx < 0) $qIdx = 0;

        $total = count($currentQuestions);
        if ($total > 0 && $qIdx >= $total) $qIdx = $total - 1;

        $q = $currentQuestions[$qIdx];
        $progress = $total > 0 ? round((($qIdx + 1) / $total) * 100) : 0;

        $sectionTitle = $type_labels[$currentType][0];
        $sectionIcon = $type_labels[$currentType][2];
        ?>

        <div class="qz-wrap">
            <div class="qz-prog-head">
                <span>Progress</span>
                <span><?php echo ($qIdx + 1); ?> / <?php echo $total; ?></span>
            </div>

            <div class="qz-prog-track">
                <div class="qz-prog-fill" style="width:<?php echo intval($progress); ?>%;"></div>
            </div>

            <div class="qz-section-tag">
                <i class="ti <?php echo htmlspecialchars($sectionIcon); ?>"></i>
                <?php echo htmlspecialchars($sectionTitle); ?>
            </div>

            <form method="post">
                <?php if ($currentType === 'multiple_choice'): ?>

                    <?php
                    $opts = safe_json_decode($q['options'] ?? '');
                    $userAnswer = $answers[$answerKey][$qIdx] ?? null;
                    ?>

                    <p class="qz-q-text"><?php echo htmlspecialchars($q['question'] ?? ''); ?></p>

                    <div class="qz-options">
                        <?php foreach ($opts as $i => $opt): ?>
                            <label class="qz-opt">
                                <input type="radio" name="answer" value="<?php echo intval($i); ?>" <?php echo ((string)$userAnswer === (string)$i) ? 'checked' : ''; ?> required>
                                <span class="qz-opt-letter"><?php echo chr(65 + $i); ?></span>
                                <?php echo htmlspecialchars((string)$opt); ?>
                            </label>
                        <?php endforeach; ?>
                    </div>

                <?php elseif ($currentType === 'fill'): ?>

                    <?php $userAnswer = $answers[$answerKey][$qIdx] ?? ''; ?>

                    <p class="qz-q-text"><?php echo htmlspecialchars($q['question'] ?? ''); ?></p>

                    <input
                        type="text"
                        name="answer"
                        class="form-control"
                        value="<?php echo htmlspecialchars($userAnswer); ?>"
                        required
                        autocomplete="off"
                    >

                <?php elseif ($currentType === 'match'): ?>

                    <?php
                    $pairs = safe_json_decode($q['pairs'] ?? '');
                    $userAnswer = $answers[$answerKey][$qIdx] ?? [];
                    ?>

                    <p class="qz-q-text">Match each item with the correct option.</p>

                    <div class="qz-match-list">
                        <?php foreach ($pairs as $i => $pair): ?>
                            <?php
                            $left = $pair['left'] ?? '';
                            $selectedValue = $userAnswer[$i] ?? '';
                            ?>
                            <div class="qz-match-row">
                                <span class="qz-match-left"><?php echo htmlspecialchars($left); ?></span>

                                <select name="answer[<?php echo intval($i); ?>]" class="form-select" required>
                                    <option value="">Select</option>

                                    <?php foreach ($pairs as $p2): ?>
                                        <?php $right = $p2['right'] ?? ''; ?>
                                        <option value="<?php echo htmlspecialchars($right); ?>" <?php echo ($selectedValue === $right) ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars($right); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        <?php endforeach; ?>
                    </div>

                <?php elseif ($currentType === 'dictation'): ?>

                    <?php $userAnswer = $answers[$answerKey][$qIdx] ?? ''; ?>

                    <p class="qz-q-text">Listen and write what you hear.</p>

                    <audio controls src="<?php echo htmlspecialchars($q['audio'] ?? ''); ?>" style="width:100%;margin-bottom:14px;"></audio>

                    <input
                        type="text"
                        name="answer"
                        class="form-control"
                        value="<?php echo htmlspecialchars($userAnswer); ?>"
                        required
                        autocomplete="off"
                    >

                <?php elseif ($currentType === 'pronunciation'): ?>

                    <?php $userAnswer = $answers[$answerKey][$qIdx] ?? ''; ?>

                    <p class="qz-q-text"><?php echo htmlspecialchars($q['prompt'] ?? ''); ?></p>

                    <input
                        type="text"
                        name="answer"
                        class="form-control"
                        value="<?php echo htmlspecialchars($userAnswer); ?>"
                        required
                        autocomplete="off"
                    >

                <?php endif; ?>

                <button class="btn-primary" type="submit">
                    <?php echo ($qIdx + 1 < $total) ? 'Next' : 'Continue'; ?>
                </button>
            </form>
        </div>

    <?php elseif ($step === 6): ?>

        <?php
        $score = 0;
        $totalScore = 0;

        foreach (get_questions_by_type($questions, 'multiple_choice') as $i => $q) {
            if (!is_valid_question($q, 'multiple_choice')) continue;

            if (isset($answers['mc'][$i]) && (string)$answers['mc'][$i] === (string)($q['correct'] ?? '')) {
                $score++;
            }

            $totalScore++;
        }

        foreach (get_questions_by_type($questions, 'fill') as $i => $q) {
            if (!is_valid_question($q, 'fill')) continue;

            if (isset($answers['fill'][$i]) && normalize_answer($answers['fill'][$i]) === normalize_answer($q['answer'] ?? '')) {
                $score++;
            }

            $totalScore++;
        }

        foreach (get_questions_by_type($questions, 'match') as $i => $q) {
            if (!is_valid_question($q, 'match')) continue;

            $pairs = safe_json_decode($q['pairs'] ?? '');
            $ok = true;

            if (!isset($answers['match'][$i])) {
                $ok = false;
            } else {
                foreach ($pairs as $j => $pair) {
                    if (!isset($answers['match'][$i][$j]) || $answers['match'][$i][$j] !== ($pair['right'] ?? '')) {
                        $ok = false;
                        break;
                    }
                }
            }

            if ($ok) {
                $score++;
            }

            $totalScore++;
        }

        foreach (get_questions_by_type($questions, 'dictation') as $i => $q) {
            if (!is_valid_question($q, 'dictation')) continue;

            if (isset($answers['dictation'][$i]) && normalize_answer($answers['dictation'][$i]) === normalize_answer($q['answer'] ?? '')) {
                $score++;
            }

            $totalScore++;
        }

        foreach (get_questions_by_type($questions, 'pronunciation') as $i => $q) {
            if (!is_valid_question($q, 'pronunciation')) continue;

            if (isset($answers['pronunciation'][$i]) && normalize_answer($answers['pronunciation'][$i]) === normalize_answer($q['expected'] ?? '')) {
                $score++;
            }

            $totalScore++;
        }

        $percent = $totalScore > 0 ? round(($score / $totalScore) * 100) : 0;
        ?>

        <div class="qz-wrap">
            <div class="qz-title" style="color:#f14902;">Quiz Results</div>
            <div class="qz-lead">
                You scored <strong><?php echo $score; ?> / <?php echo $totalScore; ?></strong>
                (<?php echo $percent; ?>%)
            </div>

            <div class="qz-prog-track" style="height:24px;margin-bottom:18px;">
                <div class="qz-prog-fill" style="width:<?php echo intval($percent); ?>%;"></div>
            </div>

            <form method="get">
                <input type="hidden" name="step" value="7">
                <input type="hidden" name="unit" value="<?php echo htmlspecialchars($unit_id); ?>">
                <input type="hidden" name="assignment" value="<?php echo htmlspecialchars($assignment); ?>">
                <button class="btn-primary" type="submit">Review answers</button>
            </form>
        </div>

    <?php elseif ($step === 7): ?>

        <div class="qz-wrap">
            <div class="qz-title" style="color:#f14902;">Review Answers</div>
            <div class="qz-lead">Check your answers below.</div>

            <?php foreach ($questions as $q): ?>
                <?php
                $type = strtolower($q['type'] ?? '');

                if (!isset($type_counts[$type])) {
                    continue;
                }

                if (!is_valid_question($q, $type)) {
                    continue;
                }
                ?>

                <div class="review-card">
                    <div class="review-type">
                        <?php echo htmlspecialchars(ucwords(str_replace('_', ' ', $type))); ?>
                    </div>

                    <?php if ($type === 'multiple_choice'): ?>

                        <?php
                        $typedQuestions = get_questions_by_type($questions, 'multiple_choice');
                        $idx = array_search($q, $typedQuestions, true);
                        $opts = safe_json_decode($q['options'] ?? '');
                        $user = $answers['mc'][$idx] ?? null;
                        ?>

                        <div style="margin-bottom:8px;"><?php echo htmlspecialchars($q['question'] ?? ''); ?></div>

                        <?php foreach ($opts as $i => $opt): ?>
                            <?php
                            $isUser = ((string)$user === (string)$i);
                            $isCorrect = ((string)$i === (string)($q['correct'] ?? ''));
                            $style = $isCorrect ? 'background:#e0fbe6;' : ($isUser ? 'background:#ffe4e6;' : '');
                            ?>
                            <div class="qz-opt" style="<?php echo $style; ?>">
                                <?php echo chr(65 + $i); ?>. <?php echo htmlspecialchars((string)$opt); ?>
                                <?php if ($isCorrect): ?>
                                    <strong style="color:#16a34a;">(correct)</strong>
                                <?php elseif ($isUser): ?>
                                    <strong style="color:#f14902;">(your answer)</strong>
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>

                    <?php elseif ($type === 'fill'): ?>

                        <?php
                        $typedQuestions = get_questions_by_type($questions, 'fill');
                        $idx = array_search($q, $typedQuestions, true);
                        $user = $answers['fill'][$idx] ?? '';
                        ?>

                        <div style="margin-bottom:8px;"><?php echo htmlspecialchars($q['question'] ?? ''); ?></div>
                        <div class="qz-opt" style="background:#e0fbe6;">
                            Correct: <?php echo htmlspecialchars($q['answer'] ?? ''); ?>
                        </div>
                        <div class="qz-opt" style="background:#ffe4e6;">
                            Your answer: <?php echo htmlspecialchars($user); ?>
                        </div>

                    <?php elseif ($type === 'match'): ?>

                        <?php
                        $typedQuestions = get_questions_by_type($questions, 'match');
                        $idx = array_search($q, $typedQuestions, true);
                        $pairs = safe_json_decode($q['pairs'] ?? '');
                        $user = $answers['match'][$idx] ?? [];
                        ?>

                        <?php foreach ($pairs as $i => $pair): ?>
                            <div class="qz-opt">
                                <?php echo htmlspecialchars($pair['left'] ?? ''); ?>
                                →
                                Correct: <?php echo htmlspecialchars($pair['right'] ?? ''); ?>
                                <br>
                                Your answer: <?php echo htmlspecialchars($user[$i] ?? ''); ?>
                            </div>
                        <?php endforeach; ?>

                    <?php elseif ($type === 'dictation'): ?>

                        <?php
                        $typedQuestions = get_questions_by_type($questions, 'dictation');
                        $idx = array_search($q, $typedQuestions, true);
                        $user = $answers['dictation'][$idx] ?? '';
                        ?>

                        <div class="qz-opt" style="background:#e0fbe6;">
                            Correct: <?php echo htmlspecialchars($q['answer'] ?? ''); ?>
                        </div>
                        <div class="qz-opt" style="background:#ffe4e6;">
                            Your answer: <?php echo htmlspecialchars($user); ?>
                        </div>

                    <?php elseif ($type === 'pronunciation'): ?>

                        <?php
                        $typedQuestions = get_questions_by_type($questions, 'pronunciation');
                        $idx = array_search($q, $typedQuestions, true);
                        $user = $answers['pronunciation'][$idx] ?? '';
                        ?>

                        <div style="margin-bottom:8px;"><?php echo htmlspecialchars($q['prompt'] ?? ''); ?></div>
                        <div class="qz-opt" style="background:#e0fbe6;">
                            Expected: <?php echo htmlspecialchars($q['expected'] ?? ''); ?>
                        </div>
                        <div class="qz-opt" style="background:#ffe4e6;">
                            Your answer: <?php echo htmlspecialchars($user); ?>
                        </div>

                    <?php endif; ?>
                </div>
            <?php endforeach; ?>

            <form method="get">
                <input type="hidden" name="step" value="0">
                <input type="hidden" name="unit" value="<?php echo htmlspecialchars($unit_id); ?>">
                <input type="hidden" name="assignment" value="<?php echo htmlspecialchars($assignment); ?>">
                <button class="btn-primary" type="submit">Restart quiz</button>
            </form>
        </div>

    <?php endif; ?>

</div>
</body>
</html>
