<?php
session_start();

// --- Paso actual ---
$step = isset($_GET['step']) ? (int)$_GET['step'] : 0;
if ($step < 0 || $step > 7) $step = 0;

// --- Cargar actividades reales de la unidad ---
// =============================
// QUIZ VIEWER.PHP — RECONSTRUCCIÓN TOTAL
// =============================
// 1. Sin lógica ni arrays legacy. 2. Carga dinámica de preguntas desde la base de datos. 3. Estructura y estilos 100% mockup. 4. Sin warnings ni errores.

session_start();
require_once __DIR__ . '/../../../lessons/core/db.php'; // Ajusta el path según tu estructura real

// --- Parámetros de unidad y assignment ---
$unit_id = isset($_GET['unit']) ? intval($_GET['unit']) : 0;
$assignment = isset($_GET['assignment']) ? intval($_GET['assignment']) : 0;
if (!$unit_id) {
  die('<div style="color:red;text-align:center;margin-top:40px;">Error: Falta unit_id en la URL.</div>');
}

// --- Cargar preguntas dinámicamente desde la base de datos ---
try {
  $pdo = get_pdo();
  $stmt = $pdo->prepare("SELECT * FROM activities WHERE unit_id = :unit_id AND assignment = :assignment ORDER BY id ASC");
  $stmt->execute(['unit_id' => $unit_id, 'assignment' => $assignment]);
  $activities = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
  die('<div style="color:red;text-align:center;margin-top:40px;">Error de conexión: '.htmlspecialchars($e->getMessage()).'</div>');
}
if (!$activities) {
  die('<div style="color:#f14902;text-align:center;margin-top:40px;">No hay actividades para esta unidad.</div>');
}

// --- Procesar y clasificar preguntas por tipo ---
$questions = [];
$type_counts = [
  'multiple_choice' => 0,
  'fill' => 0,
  'match' => 0,
  'dictation' => 0,
  'pronunciation' => 0,
];
foreach ($activities as $act) {
  $type = strtolower($act['type']);
  if (isset($type_counts[$type])) {
    $type_counts[$type]++;
    $questions[] = $act;
  }
}

// --- Paso actual ---
$step = isset($_GET['step']) ? (int)$_GET['step'] : 0;
if ($step < 0 || $step > 6) $step = 0;

?><!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Unit Quiz</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link href="https://fonts.googleapis.com/css2?family=Fredoka:wght@500;600&family=Nunito:wght@500;600;700&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@tabler/icons-webfont@latest/tabler-icons.min.css">
  <style>
    <?php echo file_get_contents(__DIR__ . '/quiz_mockup_style.css'); ?>
  </style>
</head>
<body>
<div class="quiz-container">
<?php
// --- Utilidades para navegación y sesión ---
if (!isset($_SESSION['quiz_answers'])) {
  $_SESSION['quiz_answers'] = [];
}
$answers = &$_SESSION['quiz_answers'];

// --- Pantalla 0: Portada (mockup puro) ---
if ($step === 0) {
  $type_labels = [
    'multiple_choice' => ['Multiple choice', 'Pick the correct answer', 'primary', 'ti-list-check', '#ede9fe'],
    'fill' => ['Fill in the blank', 'Complete the sentence', 'warning', 'ti-input-cursor', '#fff7e6'],
    'match' => ['Match pairs', 'Connect each word to its pair', 'info', 'ti-arrows-shuffle', '#e0f7fa'],
    'dictation' => ['Dictation', 'Listen and write what you hear', 'success', 'ti-microphone', '#e6fbe6'],
    'pronunciation' => ['Pronunciation', 'Say the phrase', 'secondary', 'ti-mood-smile', '#f3f3fa'],
  ];
  echo '<div style="text-align:center;margin-bottom:18px;">';
  echo '<div style="color:#7c3aed;font-weight:700;font-size:1.1rem;letter-spacing:.5px;">Quiz de Unidad</div>';
  echo '<div style="color:#a3a3b3;font-size:.95rem;">inglesdeuna · 7 pantallas interactivas</div>';
  echo '<button class="btn btn-light btn-sm mt-2" style="border-radius:8px;font-size:.95rem;"><i class="ti ti-download"></i> Descargar HTML</button>';
  echo '</div>';
  echo '<div class="d-flex justify-content-center mb-3">';
  $steps = ['Intro','Multiple choice','Fill in blank','Match','Dictation','Pronunciation','Resultado','Review'];
  foreach ($steps as $i => $label) {
    $active = $i === 0 ? 'btn-primary' : 'btn-outline-primary';
    echo '<button class="btn '.$active.' btn-sm mx-1" style="border-radius:16px;min-width:90px;">'.$label.'</button>';
  }
  echo '</div>';
  echo '<div style="text-align:center;color:#b0b0c3;font-size:.95rem;margin-bottom:8px;">PANTALLA 1 — PORTADA DEL QUIZ</div>';
  echo '<div class="card shadow-sm mx-auto" style="max-width:420px;border-radius:18px;background:#fff;padding:32px 24px 24px 24px;">';
  echo '<div style="margin-bottom:10px;"><span class="badge bg-warning text-dark" style="font-size:.85rem;border-radius:8px 8px 8px 0;padding:4px 12px 4px 10px;">UNIT '.htmlspecialchars($unit_id).' · QUIZ</span></div>';
  echo '<div class="qz-title mb-2" style="font-size:2.1rem;color:#f14902;">Unit Quiz</div>';
  echo '<div class="qz-lead mb-3" style="color:#7c3aed;font-size:1.1rem;">Answer all questions to complete this unit and unlock the next one.</div>';
  echo '<div class="d-flex justify-content-between mb-2" style="gap:8px;">';
  echo '<span class="qz-chip" style="background:#ede9fe;"><i class="ti ti-list-ol"></i> '.count($questions).' questions</span>';
  echo '<span class="qz-chip" style="background:#e0f2fe;"><i class="ti ti-clock"></i> ~8 min</span>';
  echo '<span class="qz-chip" style="background:#ffe4e6;"><i class="ti ti-refresh"></i> 3 attempts</span>';
  echo '</div>';
  echo '<hr style="margin:18px 0 18px 0;">';
  echo '<div style="font-weight:600;color:#7c3aed;margin-bottom:10px;">WHAT\'S INCLUDED</div>';
  echo '<div class="list-group mb-4">';
  foreach ($type_labels as $type => [$label, $desc, $color, $icon, $bg]) {
    if ($type_counts[$type] < 1) continue;
    echo '<div class="list-group-item d-flex align-items-center justify-content-between" style="border:none;background:'.$bg.';margin-bottom:6px;border-radius:12px;">';
    echo '<div class="d-flex align-items-center">';
    echo '<i class="ti '.$icon.' me-2" style="font-size:1.3em;color:#7c3aed;"></i>';
    echo '<div><div style="font-weight:600;font-size:1.08em;">'.$label.'</div>';
    echo '<div style="font-size:.97em;color:#7c3aed;">'.$desc.'</div></div>';
    echo '</div>';
    echo '<span class="badge bg-'.$color.'" style="font-size:1em;min-width:32px;">'.$type_counts[$type].'</span>';
    echo '</div>';
  }
  echo '</div>';
  echo '<form method="get"><input type="hidden" name="step" value="1"><input type="hidden" name="unit" value="'.htmlspecialchars($unit_id).'"><input type="hidden" name="assignment" value="'.htmlspecialchars($assignment).'"><button class="btn btn-lg w-100" style="background:#7c3aed;color:#fff;font-weight:700;font-size:1.15em;border-radius:12px;">▶ Start quiz</button></form>';
  echo '</div>';
}

// --- Pantallas siguientes ... (ya implementadas en el bloque principal) ---
  if (!$activities) {
    die('<div style="color:#f14902;text-align:center;margin-top:40px;">No hay actividades para esta unidad.</div>');
  }

  // --- Procesar y clasificar preguntas por tipo ---
  $questions = [];
  $type_counts = [
    'multiple_choice' => 0,
    'fill' => 0,
    'match' => 0,
    'dictation' => 0,
    'pronunciation' => 0,
  ];
  foreach ($activities as $act) {
    $type = strtolower($act['type']);
    if (isset($type_counts[$type])) {
      $type_counts[$type]++;
      $questions[] = $act;
    }
  }

  // --- Paso actual ---
  $step = isset($_GET['step']) ? (int)$_GET['step'] : 0;
  if ($step < 0 || $step > 6) $step = 0;

  ?><!DOCTYPE html>
  <html lang="en">
  <head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Unit Quiz</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Fredoka:wght@500;600&family=Nunito:wght@500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@tabler/icons-webfont@latest/tabler-icons.min.css">
    <style>
      <?php echo file_get_contents(__DIR__ . '/quiz_mockup_style.css'); ?>
    </style>
  </head>
  <body>
  <div class="quiz-container">
  <?php
  // --- Utilidades para navegación y sesión ---
  function get_questions_by_type($questions, $type) {
    return array_values(array_filter($questions, function($q) use ($type) {
      return strtolower($q['type']) === $type;
    }));
  }
  if (!isset($_SESSION['quiz_answers'])) {
    $_SESSION['quiz_answers'] = [];
  }
  $answers = &$_SESSION['quiz_answers'];

  // --- Pantalla 0: Portada (mockup puro) ---
  if ($step === 0) {
    $type_labels = [
      'multiple_choice' => ['Multiple choice', 'Pick the correct answer', 'primary', 'ti-list-check', '#ede9fe'],
      'fill' => ['Fill in the blank', 'Complete the sentence', 'warning', 'ti-input-cursor', '#fff7e6'],
      'match' => ['Match pairs', 'Connect each word to its pair', 'info', 'ti-arrows-shuffle', '#e0f7fa'],
      'dictation' => ['Dictation', 'Listen and write what you hear', 'success', 'ti-microphone', '#e6fbe6'],
      'pronunciation' => ['Pronunciation', 'Say the phrase', 'secondary', 'ti-mood-smile', '#f3f3fa'],
    ];
    echo '<div style="text-align:center;margin-bottom:18px;">';
    echo '<div style="color:#7c3aed;font-weight:700;font-size:1.1rem;letter-spacing:.5px;">Quiz de Unidad</div>';
    echo '<div style="color:#a3a3b3;font-size:.95rem;">inglesdeuna · 7 pantallas interactivas</div>';
    echo '<button class="btn btn-light btn-sm mt-2" style="border-radius:8px;font-size:.95rem;"><i class="ti ti-download"></i> Descargar HTML</button>';
    echo '</div>';
    echo '<div class="d-flex justify-content-center mb-3">';
    $steps = ['Intro','Multiple choice','Fill in blank','Match','Dictation','Pronunciation','Resultado','Review'];
    foreach ($steps as $i => $label) {
      $active = $i === 0 ? 'btn-primary' : 'btn-outline-primary';
      echo '<button class="btn '.$active.' btn-sm mx-1" style="border-radius:16px;min-width:90px;">'.$label.'</button>';
    }
    echo '</div>';
    echo '<div style="text-align:center;color:#b0b0c3;font-size:.95rem;margin-bottom:8px;">PANTALLA 1 — PORTADA DEL QUIZ</div>';
    echo '<div class="card shadow-sm mx-auto" style="max-width:420px;border-radius:18px;background:#fff;padding:32px 24px 24px 24px;">';
    echo '<div style="margin-bottom:10px;"><span class="badge bg-warning text-dark" style="font-size:.85rem;border-radius:8px 8px 8px 0;padding:4px 12px 4px 10px;">UNIT '.htmlspecialchars($unit_id).' · QUIZ</span></div>';
    echo '<div class="qz-title mb-2" style="font-size:2.1rem;color:#f14902;">Unit Quiz</div>';
    echo '<div class="qz-lead mb-3" style="color:#7c3aed;font-size:1.1rem;">Answer all questions to complete this unit and unlock the next one.</div>';
    echo '<div class="d-flex justify-content-between mb-2" style="gap:8px;">';
    echo '<span class="qz-chip" style="background:#ede9fe;"><i class="ti ti-list-ol"></i> '.count($questions).' questions</span>';
    echo '<span class="qz-chip" style="background:#e0f2fe;"><i class="ti ti-clock"></i> ~8 min</span>';
    echo '<span class="qz-chip" style="background:#ffe4e6;"><i class="ti ti-refresh"></i> 3 attempts</span>';
    echo '</div>';
    echo '<hr style="margin:18px 0 18px 0;">';
    echo '<div style="font-weight:600;color:#7c3aed;margin-bottom:10px;">WHAT\'S INCLUDED</div>';
    echo '<div class="list-group mb-4">';
    foreach ($type_labels as $type => [$label, $desc, $color, $icon, $bg]) {
      if ($type_counts[$type] < 1) continue;
      echo '<div class="list-group-item d-flex align-items-center justify-content-between" style="border:none;background:'.$bg.';margin-bottom:6px;border-radius:12px;">';
      echo '<div class="d-flex align-items-center">';
      echo '<i class="ti '.$icon.' me-2" style="font-size:1.3em;color:#7c3aed;"></i>';
      echo '<div><div style="font-weight:600;font-size:1.08em;">'.$label.'</div>';
      echo '<div style="font-size:.97em;color:#7c3aed;">'.$desc.'</div></div>';
      echo '</div>';
      echo '<span class="badge bg-'.$color.'" style="font-size:1em;min-width:32px;">'.$type_counts[$type].'</span>';
      echo '</div>';
    }
    echo '</div>';
    echo '<form method="get"><input type="hidden" name="step" value="1"><input type="hidden" name="unit" value="'.htmlspecialchars($unit_id).'"><input type="hidden" name="assignment" value="'.htmlspecialchars($assignment).'"><button class="btn btn-lg w-100" style="background:#7c3aed;color:#fff;font-weight:700;font-size:1.15em;border-radius:12px;">▶ Start quiz</button></form>';
    echo '</div>';
  }

  // --- Pantalla 1: Multiple Choice ---
  elseif ($step === 1) {
    $mcQuestions = get_questions_by_type($questions, 'multiple_choice');
    $qIdx = isset($_GET['q']) ? (int)$_GET['q'] : 0;
    $total = count($mcQuestions);
    if ($total === 0) { header('Location: ?step=2&unit='.$unit_id.'&assignment='.$assignment); exit; }
    if ($qIdx < 0) $qIdx = 0;
    if ($qIdx >= $total) $qIdx = $total-1;
    $q = $mcQuestions[$qIdx];
    $userAnswer = $answers['mc'][$qIdx] ?? null;
    $showFeedback = false;
    $isCorrect = false;
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['answer'])) {
      $userAnswer = (int)$_POST['answer'];
      $answers['mc'][$qIdx] = $userAnswer;
      $showFeedback = true;
      $isCorrect = ($userAnswer == $q['correct']);
      if ($qIdx+1 < $total) {
        header('Location: ?step=1&q=' . ($qIdx+1) . '&unit='.$unit_id.'&assignment='.$assignment); exit;
      } else {
        header('Location: ?step=2&unit='.$unit_id.'&assignment='.$assignment); exit;
      }
    }
    $progress = $total > 0 ? round((($qIdx+1)/$total)*100) : 0;
    echo '<div class="qm-screen on" id="sc-mc">';
    echo '<p class="screen-label">Pantalla 2 — Multiple choice · pregunta activa</p>';
    echo '<div class="qz-wrap">';
    echo '<div class="qz-prog-head">';
    echo '<span class="qz-prog-label">Progress</span>';
    echo '<span class="qz-prog-count">'.($qIdx+1).' / '.$total.'</span>';
    echo '</div>';
    echo '<div class="qz-prog-track"><div class="qz-prog-fill" style="width:'.$progress.'%"></div></div>';
    echo '<div class="qz-section-tag"><i class="ti ti-checks"></i> Multiple choice</div>';
    echo '<p class="qz-q-text">'.htmlspecialchars($q['question']).'</p>';
    echo '<form method="post">';
    echo '<div class="qz-options" id="mc-opts">';
    $opts = json_decode($q['options'], true);
    foreach ($opts as $i => $opt) {
      $sel = ($userAnswer !== null && $userAnswer == $i) ? ' sel' : '';
      $letter = chr(65+$i);
      echo '<label class="qz-opt'.$sel.'">';
      echo '<input type="radio" name="answer" value="'.$i.'" '.($userAnswer == $i ? 'checked' : '').' required> <span class="qz-opt-letter">'.$letter.'</span> '.htmlspecialchars($opt);
      echo '</label>';
    }
    echo '</div>';
    echo '<button class="btn btn-primary w-100 mt-3" style="border-radius:10px;">'.($qIdx+1<$total?'Next':'Continue').'</button>';
    echo '</form>';
    echo '</div></div>';
  }

  // --- Pantalla 2: Fill in the blank ---
  elseif ($step === 2) {
    $fillQuestions = get_questions_by_type($questions, 'fill');
    $qIdx = isset($_GET['q']) ? (int)$_GET['q'] : 0;
    $total = count($fillQuestions);
    if ($total === 0) { header('Location: ?step=3&unit='.$unit_id.'&assignment='.$assignment); exit; }
    if ($qIdx < 0) $qIdx = 0;
    if ($qIdx >= $total) $qIdx = $total-1;
    $q = $fillQuestions[$qIdx];
    $userAnswer = $answers['fill'][$qIdx] ?? '';
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['answer'])) {
      $userAnswer = trim($_POST['answer']);
      $answers['fill'][$qIdx] = $userAnswer;
      if ($qIdx+1 < $total) {
        header('Location: ?step=2&q=' . ($qIdx+1) . '&unit='.$unit_id.'&assignment='.$assignment); exit;
      } else {
        header('Location: ?step=3&unit='.$unit_id.'&assignment='.$assignment); exit;
      }
    }
    $progress = $total > 0 ? round((($qIdx+1)/$total)*100) : 0;
    echo '<div class="qm-screen on" id="sc-fill">';
    echo '<p class="screen-label">Pantalla 3 — Fill in the blank</p>';
    echo '<div class="qz-wrap">';
    echo '<div class="qz-prog-head">';
    echo '<span class="qz-prog-label">Progress</span>';
    echo '<span class="qz-prog-count">'.($qIdx+1).' / '.$total.'</span>';
    echo '</div>';
    echo '<div class="qz-prog-track"><div class="qz-prog-fill" style="width:'.$progress.'%"></div></div>';
    echo '<div class="qz-section-tag"><i class="ti ti-input-cursor"></i> Fill in the blank</div>';
    echo '<p class="qz-q-text">'.htmlspecialchars($q['question']).'</p>';
    echo '<form method="post">';
    echo '<input type="text" name="answer" class="form-control" value="'.htmlspecialchars($userAnswer).'" required autocomplete="off" style="font-size:1.1em;">';
    echo '<button class="btn btn-primary w-100 mt-3" style="border-radius:10px;">'.($qIdx+1<$total?'Next':'Continue').'</button>';
    echo '</form>';
    echo '</div></div>';
  }

  // --- Pantalla 3: Match pairs ---
  elseif ($step === 3) {
    $matchQuestions = get_questions_by_type($questions, 'match');
    $qIdx = isset($_GET['q']) ? (int)$_GET['q'] : 0;
    $total = count($matchQuestions);
    if ($total === 0) { header('Location: ?step=4&unit='.$unit_id.'&assignment='.$assignment); exit; }
    if ($qIdx < 0) $qIdx = 0;
    if ($qIdx >= $total) $qIdx = $total-1;
    $q = $matchQuestions[$qIdx];
    $userAnswer = $answers['match'][$qIdx] ?? [];
    $pairs = json_decode($q['pairs'], true);
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['answer']) && is_array($_POST['answer'])) {
      $userAnswer = $_POST['answer'];
      $answers['match'][$qIdx] = $userAnswer;
      if ($qIdx+1 < $total) {
        header('Location: ?step=3&q=' . ($qIdx+1) . '&unit='.$unit_id.'&assignment='.$assignment); exit;
      } else {
        header('Location: ?step=4&unit='.$unit_id.'&assignment='.$assignment); exit;
      }
    }
    $progress = $total > 0 ? round((($qIdx+1)/$total)*100) : 0;
    echo '<div class="qm-screen on" id="sc-match">';
    echo '<p class="screen-label">Pantalla 4 — Match pairs</p>';
    echo '<div class="qz-wrap">';
    echo '<div class="qz-prog-head">';
    echo '<span class="qz-prog-label">Progress</span>';
    echo '<span class="qz-prog-count">'.($qIdx+1).' / '.$total.'</span>';
    echo '</div>';
    echo '<div class="qz-prog-track"><div class="qz-prog-fill" style="width:'.$progress.'%"></div></div>';
    echo '<div class="qz-section-tag"><i class="ti ti-arrows-shuffle"></i> Match pairs</div>';
    echo '<form method="post">';
    echo '<div class="qz-match-list">';
    foreach ($pairs as $i => $pair) {
      $val = isset($userAnswer[$i]) ? htmlspecialchars($userAnswer[$i]) : '';
      echo '<div class="qz-match-row">';
      echo '<span class="qz-match-left">'.htmlspecialchars($pair['left']).'</span>';
      echo '<select name="answer['.$i.']" class="form-select qz-match-select" required>';
      echo '<option value="">Select</option>';
      foreach ($pairs as $j => $p2) {
        $selected = ($val == $p2['right']) ? 'selected' : '';
        echo '<option value="'.htmlspecialchars($p2['right']).'" '.$selected.'>'.htmlspecialchars($p2['right']).'</option>';
      }
      echo '</select>';
      echo '</div>';
    }
    echo '</div>';
    echo '<button class="btn btn-primary w-100 mt-3" style="border-radius:10px;">'.($qIdx+1<$total?'Next':'Continue').'</button>';
    echo '</form>';
    echo '</div></div>';
  }

  // --- Pantalla 4: Dictation ---
  elseif ($step === 4) {
    $dictQuestions = get_questions_by_type($questions, 'dictation');
    $qIdx = isset($_GET['q']) ? (int)$_GET['q'] : 0;
    $total = count($dictQuestions);
    if ($total === 0) { header('Location: ?step=5&unit='.$unit_id.'&assignment='.$assignment); exit; }
    if ($qIdx < 0) $qIdx = 0;
    if ($qIdx >= $total) $qIdx = $total-1;
    $q = $dictQuestions[$qIdx];
    $userAnswer = $answers['dictation'][$qIdx] ?? '';
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['answer'])) {
      $userAnswer = trim($_POST['answer']);
      $answers['dictation'][$qIdx] = $userAnswer;
      if ($qIdx+1 < $total) {
        header('Location: ?step=4&q=' . ($qIdx+1) . '&unit='.$unit_id.'&assignment='.$assignment); exit;
      } else {
        header('Location: ?step=5&unit='.$unit_id.'&assignment='.$assignment); exit;
      }
    }
    $progress = $total > 0 ? round((($qIdx+1)/$total)*100) : 0;
    echo '<div class="qm-screen on" id="sc-dict">';
    echo '<p class="screen-label">Pantalla 5 — Dictation</p>';
    echo '<div class="qz-wrap">';
    echo '<div class="qz-prog-head">';
    echo '<span class="qz-prog-label">Progress</span>';
    echo '<span class="qz-prog-count">'.($qIdx+1).' / '.$total.'</span>';
    echo '</div>';
    echo '<div class="qz-prog-track"><div class="qz-prog-fill" style="width:'.$progress.'%"></div></div>';
    echo '<div class="qz-section-tag"><i class="ti ti-microphone"></i> Dictation</div>';
    echo '<audio controls src="'.htmlspecialchars($q['audio']).'" style="width:100%;margin-bottom:10px;"></audio>';
    echo '<form method="post">';
    echo '<input type="text" name="answer" class="form-control" value="'.htmlspecialchars($userAnswer).'" required autocomplete="off" style="font-size:1.1em;">';
    echo '<button class="btn btn-primary w-100 mt-3" style="border-radius:10px;">'.($qIdx+1<$total?'Next':'Continue').'</button>';
    echo '</form>';
    echo '</div></div>';
  }

  // --- Pantalla 5: Pronunciation ---
  elseif ($step === 5) {
    $pronQuestions = get_questions_by_type($questions, 'pronunciation');
    $qIdx = isset($_GET['q']) ? (int)$_GET['q'] : 0;
    $total = count($pronQuestions);
    if ($total === 0) { header('Location: ?step=6&unit='.$unit_id.'&assignment='.$assignment); exit; }
    if ($qIdx < 0) $qIdx = 0;
    if ($qIdx >= $total) $qIdx = $total-1;
    $q = $pronQuestions[$qIdx];
    $userAnswer = $answers['pronunciation'][$qIdx] ?? '';
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['answer'])) {
      $userAnswer = trim($_POST['answer']);
      $answers['pronunciation'][$qIdx] = $userAnswer;
      if ($qIdx+1 < $total) {
        header('Location: ?step=5&q=' . ($qIdx+1) . '&unit='.$unit_id.'&assignment='.$assignment); exit;
      } else {
        header('Location: ?step=6&unit='.$unit_id.'&assignment='.$assignment); exit;
      }
    }
    $progress = $total > 0 ? round((($qIdx+1)/$total)*100) : 0;
    echo '<div class="qm-screen on" id="sc-pron">';
    echo '<p class="screen-label">Pantalla 6 — Pronunciation</p>';
    echo '<div class="qz-wrap">';
    echo '<div class="qz-prog-head">';
    echo '<span class="qz-prog-label">Progress</span>';
    echo '<span class="qz-prog-count">'.($qIdx+1).' / '.$total.'</span>';
    echo '</div>';
    echo '<div class="qz-prog-track"><div class="qz-prog-fill" style="width:'.$progress.'%"></div></div>';
    echo '<div class="qz-section-tag"><i class="ti ti-mood-smile"></i> Pronunciation</div>';
    echo '<div class="qz-q-text">'.htmlspecialchars($q['prompt']).'</div>';
    echo '<form method="post">';
    echo '<input type="text" name="answer" class="form-control" value="'.htmlspecialchars($userAnswer).'" required autocomplete="off" style="font-size:1.1em;">';
    echo '<button class="btn btn-primary w-100 mt-3" style="border-radius:10px;">'.($qIdx+1<$total?'Next':'Continue').'</button>';
    echo '</form>';
    echo '</div></div>';
  }

  // --- Pantalla 6: Resultados ---
  elseif ($step === 6) {
    // Calcular puntaje simple (solo para demo, ajusta según reglas reales)
    $score = 0; $total = 0;
    foreach (get_questions_by_type($questions, 'multiple_choice') as $i => $q) {
      if (isset($answers['mc'][$i]) && $answers['mc'][$i] == $q['correct']) $score++;
      $total++;
    }
    foreach (get_questions_by_type($questions, 'fill') as $i => $q) {
      if (isset($answers['fill'][$i]) && strtolower(trim($answers['fill'][$i])) == strtolower(trim($q['answer']))) $score++;
      $total++;
    }
    foreach (get_questions_by_type($questions, 'match') as $i => $q) {
      $pairs = json_decode($q['pairs'], true);
      $ok = true;
      if (isset($answers['match'][$i])) {
        foreach ($pairs as $j => $pair) {
          if (!isset($answers['match'][$i][$j]) || $answers['match'][$i][$j] != $pair['right']) $ok = false;
        }
        if ($ok) $score++;
      }
      $total++;
    }
    foreach (get_questions_by_type($questions, 'dictation') as $i => $q) {
      if (isset($answers['dictation'][$i]) && strtolower(trim($answers['dictation'][$i])) == strtolower(trim($q['answer']))) $score++;
      $total++;
    }
    foreach (get_questions_by_type($questions, 'pronunciation') as $i => $q) {
      if (isset($answers['pronunciation'][$i]) && strtolower(trim($answers['pronunciation'][$i])) == strtolower(trim($q['expected']))) $score++;
      $total++;
    }
    $percent = $total > 0 ? round(($score/$total)*100) : 0;
    echo '<div class="qm-screen on" id="sc-result">';
    echo '<div class="qz-wrap">';
    echo '<div class="qz-title mb-2" style="font-size:2.1rem;color:#f14902;">Quiz Results</div>';
    echo '<div class="qz-lead mb-3" style="color:#7c3aed;font-size:1.1rem;">You scored <b>'.$score.' / '.$total.'</b> ('.$percent.'%)</div>';
    echo '<div class="qz-result-bar" style="height:24px;background:#ede9fe;border-radius:12px;overflow:hidden;margin-bottom:18px;">';
    echo '<div style="height:100%;background:#7c3aed;width:'.$percent.'%;transition:.5s;"></div>';
    echo '</div>';
    echo '<form method="get">';
    echo '<input type="hidden" name="step" value="7">';
    echo '<input type="hidden" name="unit" value="'.htmlspecialchars($unit_id).'">';
    echo '<input type="hidden" name="assignment" value="'.htmlspecialchars($assignment).'">';
    echo '<button class="btn btn-primary w-100" style="border-radius:10px;">Review answers</button>';
    echo '</form>';
    echo '</div></div>';
  }

  // --- Pantalla 7: Review ---
  elseif ($step === 7) {
    echo '<div class="qm-screen on" id="sc-review">';
    echo '<div class="qz-wrap">';
    echo '<div class="qz-title mb-2" style="font-size:2.1rem;color:#f14902;">Review Answers</div>';
    echo '<div class="qz-lead mb-3" style="color:#7c3aed;font-size:1.1rem;">Check your answers below.</div>';
    foreach ($questions as $q) {
      $type = strtolower($q['type']);
      echo '<div class="card mb-3" style="border-radius:14px;padding:18px 16px;">';
      echo '<div style="font-weight:600;color:#7c3aed;margin-bottom:6px;">'.ucwords(str_replace('_',' ',$type)).'</div>';
      if ($type === 'multiple_choice') {
        $idx = array_search($q, get_questions_by_type($questions, 'multiple_choice'));
        $opts = json_decode($q['options'], true);
        $user = $answers['mc'][$idx] ?? null;
        echo '<div style="margin-bottom:6px;">'.htmlspecialchars($q['question']).'</div>';
        foreach ($opts as $i => $opt) {
          $isUser = ($user !== null && $user == $i);
          $isCorrect = ($i == $q['correct']);
          $style = $isCorrect ? 'background:#e0fbe6;' : ($isUser ? 'background:#ffe4e6;' : '');
          echo '<div class="qz-opt" style="'.$style.'">'.chr(65+$i).'. '.htmlspecialchars($opt).($isCorrect?' <span style="color:#16a34a;font-weight:600;">(correct)</span>':'').($isUser && !$isCorrect?' <span style="color:#f14902;font-weight:600;">(your answer)</span>':'').'</div>';
        }
      } elseif ($type === 'fill') {
        $idx = array_search($q, get_questions_by_type($questions, 'fill'));
        $user = $answers['fill'][$idx] ?? '';
        echo '<div style="margin-bottom:6px;">'.htmlspecialchars($q['question']).'</div>';
        echo '<div class="qz-opt" style="background:#e0fbe6;">Correct: '.htmlspecialchars($q['answer']).'</div>';
        echo '<div class="qz-opt" style="background:#e0fbe6;">Correct: '.htmlspecialchars($q['answer']).'</div>';
        echo '<div class="qz-opt" style="background:#ffe4e6;">Your answer: '.htmlspecialchars($user).'</div>';
      } elseif ($type === 'pronunciation') {
        $idx = array_search($q, get_questions_by_type($questions, 'pronunciation'));
        $user = $answers['pronunciation'][$idx] ?? '';
        echo '<div style="margin-bottom:6px;">'.htmlspecialchars($q['prompt']).'</div>';
        echo '<div class="qz-opt" style="background:#e0fbe6;">Expected: '.htmlspecialchars($q['expected']).'</div>';
        echo '<div class="qz-opt" style="background:#ffe4e6;">Your answer: '.htmlspecialchars($user).'</div>';
      }
      echo '</div>';
    }
    echo '<form method="get">';
    echo '<input type="hidden" name="step" value="0">';
    echo '<input type="hidden" name="unit" value="'.htmlspecialchars($unit_id).'">';
    echo '<input type="hidden" name="assignment" value="'.htmlspecialchars($assignment).'">';
    echo '<button class="btn btn-primary w-100" style="border-radius:10px;">Restart quiz</button>';
    echo '</form>';
    echo '</div></div>';
  }

