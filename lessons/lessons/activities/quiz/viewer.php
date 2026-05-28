<?php
session_start();

// --- Paso actual ---
$step = isset($_GET['step']) ? (int)$_GET['step'] : 0;
if ($step < 0 || $step > 7) $step = 0;

// --- Cargar actividades reales de la unidad ---
require_once __DIR__ . '/../../config/db.php';
$unit_id = $_GET['unit'] ?? '';
if (!$unit_id) { die('Unidad no especificada.'); }

// Tipos de actividad de puntaje
$score_types = ['quiz', 'multiple_choice', 'fill', 'match', 'dictation', 'pronunciation'];

// Obtener actividades de la unidad
$stmt = $pdo->prepare("SELECT * FROM activities WHERE unit_id = :unit_id ORDER BY position ASC, id ASC");
$stmt->execute(['unit_id' => $unit_id]);
$all_activities = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Filtrar solo actividades de puntaje
$questions = array_values(array_filter($all_activities, function($a) use ($score_types) {
  return in_array(strtolower($a['type'] ?? ''), $score_types, true);
}));

// Inicializar respuestas en sesión si no existen
if (!isset($_SESSION['quiz_answers'])) {
  $_SESSION['quiz_answers'] = [];
}
$answers = &$_SESSION['quiz_answers'];

// --- Manejo de POST y redirecciones antes de cualquier salida HTML ---
// Multiple Choice
if ($step === 1) {
  $qIdx = isset($_GET['q']) ? (int)$_GET['q'] : 0;
  $mcQuestions = array_values(array_filter($questions, fn($q) => $q['type']==='mc'));
  $total = count($mcQuestions);
  if ($qIdx < 0) $qIdx = 0;
  if ($qIdx >= $total) $qIdx = $total-1;
  if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['answer'])) {
    $answers['mc'][$qIdx] = (int)$_POST['answer'];
    if ($qIdx+1 < $total) {
      header('Location: ?step=1&q=' . ($qIdx+1));
      exit;
    } else {
      header('Location: ?step=2');
      exit;
    }
  }
}
// Fill in the blank
if ($step === 2) {
  $qIdx = isset($_GET['q']) ? (int)$_GET['q'] : 0;
  $fillQuestions = array_values(array_filter($questions, fn($q) => $q['type']==='fill'));
  $total = count($fillQuestions);
  if ($qIdx < 0) $qIdx = 0;
  if ($qIdx >= $total) $qIdx = $total-1;
  $q = $fillQuestions[$qIdx];
  if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['answer'])) {
    $userAnswer = trim($_POST['answer']);
    $answers['fill'][$qIdx] = $userAnswer;
    $isCorrect = (strcasecmp($userAnswer, $q['answer']) === 0);
    if ($isCorrect && $qIdx+1 < $total) {
      header('Location: ?step=2&q=' . ($qIdx+1));
      exit;
    } elseif ($isCorrect && $qIdx+1 >= $total) {
      header('Location: ?step=3');
      exit;
    }
  }
}
// Match
if ($step === 3) {
  $matchQuestions = array_values(array_filter($questions, fn($q) => $q['type']==='match'));
  $qIdx = 0;
  $q = $matchQuestions[$qIdx] ?? null;
  $pairs = $q['pairs'];
  if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $userAnswers = [];
    foreach ($pairs as $i => $pair) {
      $userAnswers[$i] = isset($_POST['right'][$i]) ? trim($_POST['right'][$i]) : '';
    }
    $answers['match'][$qIdx] = $userAnswers;
    header('Location: ?step=4');
    exit;
  }
}
// Dictation
if ($step === 4) {
  $dictationQuestions = array_values(array_filter($questions, fn($q) => $q['type']==='dictation'));
  $qIdx = 0;
  $q = $dictationQuestions[$qIdx] ?? null;
  if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['answer'])) {
    $userAnswer = trim($_POST['answer']);
    $answers['dictation'][$qIdx] = $userAnswer;
    header('Location: ?step=5');
    exit;
  }
}
// Pronunciation
if ($step === 5) {
  $pronQuestions = array_values(array_filter($questions, fn($q) => $q['type']==='pronunciation'));
  $qIdx = 0;
  $q = $pronQuestions[$qIdx] ?? null;
  if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['answer'])) {
    $userAnswer = trim($_POST['answer']);
    $answers['pronunciation'][$qIdx] = $userAnswer;
    header('Location: ?step=6');
    exit;
  }
}

// --- Configuración Bootstrap y meta ---
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
// --- Paso actual ---
$step = isset($_GET['step']) ? (int)$_GET['step'] : 0;
if ($step < 0 || $step > 6) $step = 0;

// --- Preguntas mock para demo (reemplazar por carga real) ---
if (!isset($_SESSION['quiz_questions'])) {
  $_SESSION['quiz_questions'] = [
    [
      'type' => 'mc',
      'question' => 'What is the capital of France?',
      'options' => ['Madrid', 'Paris', 'Rome', 'Berlin'],
      'correct' => 1,
    ],
    [
      'type' => 'mc',
      'question' => 'Which is the largest planet?',
      'options' => ['Earth', 'Jupiter', 'Mars', 'Venus'],
      'correct' => 1,
    ],
    [
      'type' => 'fill',
      'question' => 'The sky is _____.',
      'answer' => 'blue',
    ],
    [
      'type' => 'fill',
      'question' => 'Grass is _____.',
      'answer' => 'green',
    ],
    [
      'type' => 'match',
      'pairs' => [
        ['left' => 'Dog', 'right' => 'Perro'],
        ['left' => 'Cat', 'right' => 'Gato'],
      ],
    ],
    [
      'type' => 'dictation',
      'audio' => 'https://cdn.pixabay.com/audio/2022/10/16/audio_12b5fae3b2.mp3',
      'answer' => 'Hello world',
    ],
    [
      'type' => 'pronunciation',
      'prompt' => 'Say: "Good morning"',
      'expected' => 'Good morning',
    ],
  ];
  $_SESSION['quiz_answers'] = [];
}
$questions = $_SESSION['quiz_questions'];
$answers = &$_SESSION['quiz_answers'];

// --- Pantalla 0: Intro (diseño igual al mockup) ---
if ($step === 0) {
  // Contar por tipo
  $type_labels = [
    'multiple_choice' => ['Multiple choice', 'Pick the correct answer', 'primary', 'bi-list-check', '#ede9fe'],
    'fill' => ['Fill in the blank', 'Complete the sentence', 'warning', 'bi-input-cursor-text', '#fff7e6'],
    'match' => ['Match pairs', 'Connect each word to its pair', 'info', 'bi-shuffle', '#e0f7fa'],
    'dictation' => ['Dictation', 'Listen and write what you hear', 'success', 'bi-mic', '#e6fbe6'],
    'pronunciation' => ['Pronunciation', 'Say the phrase', 'secondary', 'bi-emoji-smile', '#f3f3fa'],
  ];
  $counts = [];
  foreach ($questions as $q) {
    $type = strtolower($q['type']);
    if (!isset($counts[$type])) $counts[$type] = 0;
    $counts[$type]++;
  }
  echo '<div style="text-align:center;margin-bottom:18px;">';
  echo '<div style="color:#7c3aed;font-weight:700;font-size:1.1rem;letter-spacing:.5px;">Quiz de Unidad – Mockup</div>';
  echo '<div style="color:#a3a3b3;font-size:.95rem;">inglesdeuna · 7 pantallas interactivas</div>';
  echo '<button class="btn btn-light btn-sm mt-2" style="border-radius:8px;font-size:.95rem;"><i class="bi bi-download"></i> Descargar HTML</button>';
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
  echo '<div style="margin-bottom:10px;"><span class="badge bg-warning text-dark" style="font-size:.85rem;border-radius:8px 8px 8px 0;padding:4px 12px 4px 10px;">UNIT 3 · QUIZ</span></div>';
  echo '<div class="qz-title mb-2" style="font-size:2.1rem;color:#f14902;">Unit Quiz</div>';
  echo '<div class="qz-lead mb-3" style="color:#7c3aed;font-size:1.1rem;">Answer all questions to complete this unit and unlock the next one.</div>';
  echo '<div class="d-flex justify-content-between mb-2" style="gap:8px;">';
  echo '<span class="qz-chip" style="background:#ede9fe;"><i class="bi bi-list-ol"></i> '.count($questions).' questions</span>';
  echo '<span class="qz-chip" style="background:#e0f2fe;"><i class="bi bi-clock"></i> ~8 min</span>';
  echo '<span class="qz-chip" style="background:#ffe4e6;"><i class="bi bi-arrow-repeat"></i> 3 attempts</span>';
  echo '</div>';
  echo '<hr style="margin:18px 0 18px 0;">';
  echo '<div style="font-weight:600;color:#7c3aed;margin-bottom:10px;">WHAT\'S INCLUDED</div>';
  echo '<div class="list-group mb-4">';
  foreach ($type_labels as $type => [$label, $desc, $color, $icon, $bg]) {
    if (!isset($counts[$type])) continue;
    echo '<div class="list-group-item d-flex align-items-center justify-content-between" style="border:none;background:'.$bg.';margin-bottom:6px;border-radius:12px;">';
    echo '<div class="d-flex align-items-center">';
    echo '<i class="bi '.$icon.' me-2" style="font-size:1.3em;color:#7c3aed;"></i>';
    echo '<div><div style="font-weight:600;font-size:1.08em;">'.$label.'</div>';
    echo '<div style="font-size:.97em;color:#7c3aed;">'.$desc.'</div></div>';
    echo '</div>';
    echo '<span class="badge bg-'.$color.'" style="font-size:1em;min-width:32px;">'.$counts[$type].'</span>';
    echo '</div>';
  }
  echo '</div>';
  echo '<form method="get"><input type="hidden" name="step" value="1"><input type="hidden" name="unit" value="'.htmlspecialchars($unit_id).'"><button class="btn btn-lg w-100" style="background:#7c3aed;color:#fff;font-weight:700;font-size:1.15em;border-radius:12px;">▶ Start quiz</button></form>';
  echo '</div>';
}
// --- Pantalla 1: Multiple Choice (mockup) ---
elseif ($step === 1) {
  $qIdx = isset($_GET['q']) ? (int)$_GET['q'] : 0;
  $mcQuestions = array_values(array_filter($questions, fn($q) => strtolower($q['type'])==='multiple_choice' || strtolower($q['type'])==='mc'));
  $total = count($mcQuestions);
  if ($qIdx < 0) $qIdx = 0;
  if ($qIdx >= $total) $qIdx = $total-1;
  $q = $mcQuestions[$qIdx];
  $userAnswer = $answers['mc'][$qIdx] ?? null;
  $showFeedback = false;
  $isCorrect = false;

  // Guardar respuesta si viene por POST
  if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['answer'])) {
    $userAnswer = (int)$_POST['answer'];
    $answers['mc'][$qIdx] = $userAnswer;
    $showFeedback = true;
    $isCorrect = ($userAnswer == $q['correct']);
    // Siguiente pregunta o paso
    if ($qIdx+1 < $total) {
      header('Location: ?step=1&q=' . ($qIdx+1));
      exit;
    } else {
      header('Location: ?step=2'); // Siguiente bloque
      exit;
    }
  }

  // Progreso visual
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
  foreach ($q['options'] as $i => $opt) {
    $sel = ($userAnswer !== null && $userAnswer == $i) ? ' sel' : '';
    $letter = chr(65+$i);
    echo '<label class="qz-opt'.$sel.'">';
    echo '<input type="radio" name="answer" value="'.$i.'" style="display:none"'.($userAnswer!==null && $userAnswer==$i?' checked':'').'>';
    echo '<div class="qz-opt-letter">'.$letter.'</div>'.htmlspecialchars($opt);
    echo '</label>';
  }
  echo '</div>';
  echo '<div class="qz-btns">';
  if ($qIdx+1<$total) {
    echo '<button class="qz-btn-next">Next question →</button>';
  } else {
    echo '<button class="qz-btn-next">Continue</button>';
  }
  echo '</div>';
  echo '</form>';
  echo '</div>';
  echo '</div>';
}
// --- Pantalla 2: Fill in the blank ---
elseif ($step === 2) {
  $qIdx = isset($_GET['q']) ? (int)$_GET['q'] : 0;
  $fillQuestions = array_values(array_filter($questions, fn($q) => $q['type']==='fill'));
  $total = count($fillQuestions);
  if ($qIdx < 0) $qIdx = 0;
  if ($qIdx >= $total) $qIdx = $total-1;
  $q = $fillQuestions[$qIdx];
  $feedback = null;
  $answered = isset($answers['fill'][$qIdx]);

  // Guardar respuesta y feedback
  if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['answer'])) {
    $userAnswer = trim($_POST['answer']);
    $answers['fill'][$qIdx] = $userAnswer;
    $isCorrect = (strcasecmp($userAnswer, $q['answer']) === 0);
    $feedback = $isCorrect ? 'correct' : 'incorrect';
    // Si es correcto o ya contestó, avanzar automáticamente
    if ($isCorrect && $qIdx+1 < $total) {
      header('Location: ?step=2&q=' . ($qIdx+1));
      exit;
    } elseif ($isCorrect && $qIdx+1 >= $total) {
      header('Location: ?step=3');
      exit;
    }
  }

  echo '<div class="mb-3"><span class="badge bg-warning text-dark">Fill in the blank</span></div>';
  echo '<div class="mb-3"><strong>Question ' . ($qIdx+1) . ' of ' . $total . '</strong></div>';
  echo '<form method="post">';
  echo '<div class="mb-3 fs-5">' . htmlspecialchars($q['question']) . '</div>';
  echo '<input type="text" class="form-control mb-2" name="answer" value="' . htmlspecialchars($answers['fill'][$qIdx] ?? '') . '" required autocomplete="off">';
  if ($answered) {
    $isCorrect = (strcasecmp($answers['fill'][$qIdx], $q['answer']) === 0);
    if ($isCorrect) {
      echo '<div class="alert alert-success">Correct!</div>';
    } else {
      echo '<div class="alert alert-danger">Incorrect. Correct answer: <strong>' . htmlspecialchars($q['answer']) . '</strong></div>';
    }
  }
  echo '<button class="btn btn-primary mt-3">'.($qIdx+1<$total?'Next':'Continue').'</button>';
  echo '</form>';
}
// --- Pantalla 3: Match ---
elseif ($step === 3) {
  $matchQuestions = array_values(array_filter($questions, fn($q) => $q['type']==='match'));
  $qIdx = 0; // Solo un bloque de match en este mock
  $q = $matchQuestions[$qIdx] ?? null;
  if (!$q) { header('Location: ?step=4'); exit; }
  $pairs = $q['pairs'];
  $userAnswers = $answers['match'][$qIdx] ?? [];
  $feedback = null;

  // Guardar respuesta
  if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $userAnswers = [];
    foreach ($pairs as $i => $pair) {
      $userAnswers[$i] = isset($_POST['right'][$i]) ? trim($_POST['right'][$i]) : '';
    }
    $answers['match'][$qIdx] = $userAnswers;
    // Avanzar al siguiente paso
    header('Location: ?step=4');
    exit;
  }

  echo '<div class="mb-3"><span class="badge bg-info text-dark">Match pairs</span></div>';
  echo '<div class="mb-3"><strong>Match the pairs</strong></div>';
  echo '<form method="post">';
  echo '<div class="row">';
  foreach ($pairs as $i => $pair) {
    echo '<div class="col-6 mb-2">' . htmlspecialchars($pair['left']) . '</div>';
    echo '<div class="col-6 mb-2">';
    echo '<input type="text" class="form-control" name="right['.$i.']" value="' . htmlspecialchars($userAnswers[$i] ?? '') . '" placeholder="Match..." required>';
    echo '</div>';
  }
  echo '</div>';
  echo '<button class="btn btn-primary mt-3">Continue</button>';
  echo '</form>';
}
// --- Pantalla 4: Dictation ---
elseif ($step === 4) {
  $dictationQuestions = array_values(array_filter($questions, fn($q) => $q['type']==='dictation'));
  $qIdx = 0; // Solo un bloque de dictation en este mock
  $q = $dictationQuestions[$qIdx] ?? null;
  if (!$q) { header('Location: ?step=5'); exit; }
  $userAnswer = $answers['dictation'][$qIdx] ?? '';
  $feedback = null;

  // Guardar respuesta
  if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['answer'])) {
    $userAnswer = trim($_POST['answer']);
    $answers['dictation'][$qIdx] = $userAnswer;
    $isCorrect = (strcasecmp($userAnswer, $q['answer']) === 0);
    $feedback = $isCorrect ? 'correct' : 'incorrect';
    // Avanzar al siguiente paso
    header('Location: ?step=5');
    exit;
  }

  echo '<div class="mb-3"><span class="badge bg-success">Dictation</span></div>';
  echo '<div class="mb-3"><strong>Listen and type what you hear</strong></div>';
  echo '<form method="post">';
  echo '<audio controls class="mb-3" src="'.htmlspecialchars($q['audio']).'"></audio>';
  echo '<input type="text" class="form-control mb-2" name="answer" value="' . htmlspecialchars($userAnswer) . '" required autocomplete="off" placeholder="Type what you hear...">';
  if ($userAnswer !== '') {
    $isCorrect = (strcasecmp($userAnswer, $q['answer']) === 0);
    if ($isCorrect) {
      echo '<div class="alert alert-success">Correct!</div>';
    } else {
      echo '<div class="alert alert-danger">Incorrect. Correct answer: <strong>' . htmlspecialchars($q['answer']) . '</strong></div>';
    }
  }
  echo '<button class="btn btn-primary mt-3">Continue</button>';
  echo '</form>';
}
// --- Pantalla 6: Resultados ---
elseif ($step === 6) {
  // Calcular puntaje simple (mock)
  $score = 0;
  $total = 0;
  // Multiple choice
  $mcQuestions = array_values(array_filter($questions, fn($q) => $q['type']==='mc'));
  foreach ($mcQuestions as $i => $q) {
    $total++;
    if (isset($answers['mc'][$i]) && $answers['mc'][$i] == $q['correct']) $score++;
  }
  // Fill in the blank
  $fillQuestions = array_values(array_filter($questions, fn($q) => $q['type']==='fill'));
  foreach ($fillQuestions as $i => $q) {
    $total++;
    if (isset($answers['fill'][$i]) && strcasecmp($answers['fill'][$i], $q['answer']) === 0) $score++;
  }
  // Match
  $matchQuestions = array_values(array_filter($questions, fn($q) => $q['type']==='match'));
  foreach ($matchQuestions as $i => $q) {
    $total++;
    $user = $answers['match'][$i] ?? [];
    $correct = true;
    foreach ($q['pairs'] as $j => $pair) {
      if (!isset($user[$j]) || strcasecmp($user[$j], $pair['right']) !== 0) $correct = false;
    }
    if ($correct) $score++;
  }
  // Dictation
  $dictationQuestions = array_values(array_filter($questions, fn($q) => $q['type']==='dictation'));
  foreach ($dictationQuestions as $i => $q) {
    $total++;
    if (isset($answers['dictation'][$i]) && strcasecmp($answers['dictation'][$i], $q['answer']) === 0) $score++;
  }
  // Pronunciation (simulado)
  $pronQuestions = array_values(array_filter($questions, fn($q) => $q['type']==='pronunciation'));
  foreach ($pronQuestions as $i => $q) {
    $total++;
    if (isset($answers['pronunciation'][$i]) && strcasecmp($answers['pronunciation'][$i], $q['expected']) === 0) $score++;
  }

  echo '<div class="qz-title mb-2">Results</div>';
  echo '<div class="qz-lead">You scored <strong>' . $score . ' / ' . $total . '</strong></div>';
  echo '<div class="mb-4">';
  echo '<form method="get">';
  echo '<input type="hidden" name="step" value="7">';
  echo '<button class="btn btn-lg btn-primary w-100">Review answers</button>';
  echo '</form>';
  echo '</div>';
  echo '<form method="get"><input type="hidden" name="step" value="0"><button class="btn btn-outline-secondary w-100">Start over</button></form>';
}

// --- Pantalla 7: Review ---
elseif ($step === 7) {
  echo '<div class="qz-title mb-2">Review your answers</div>';
  echo '<div class="qz-section">';
  // Multiple choice
  $mcQuestions = array_values(array_filter($questions, fn($q) => $q['type']==='mc'));
  if ($mcQuestions) {
    echo '<div class="mb-2"><strong>Multiple choice</strong></div>';
    foreach ($mcQuestions as $i => $q) {
      $user = $answers['mc'][$i] ?? null;
      $isCorrect = ($user !== null && $user == $q['correct']);
      echo '<div class="mb-1">Q: ' . htmlspecialchars($q['question']) . '<br>';
      echo 'Your answer: <span class="' . ($isCorrect ? 'text-success' : 'text-danger') . '">' .
        ($user !== null ? htmlspecialchars($q['options'][$user]) : '<em>No answer</em>') . '</span>';
      if (!$isCorrect && $user !== null) {
        echo ' <span class="text-muted">(Correct: ' . htmlspecialchars($q['options'][$q['correct']]) . ')</span>';
      }
      echo '</div>';
    }
  }
  // Fill in the blank
  $fillQuestions = array_values(array_filter($questions, fn($q) => $q['type']==='fill'));
  if ($fillQuestions) {
    echo '<div class="mt-3 mb-2"><strong>Fill in the blank</strong></div>';
    foreach ($fillQuestions as $i => $q) {
      $user = $answers['fill'][$i] ?? null;
      $isCorrect = ($user !== null && strcasecmp($user, $q['answer']) === 0);
      echo '<div class="mb-1">Q: ' . htmlspecialchars($q['question']) . '<br>';
      echo 'Your answer: <span class="' . ($isCorrect ? 'text-success' : 'text-danger') . '">' .
        ($user !== null ? htmlspecialchars($user) : '<em>No answer</em>') . '</span>';
      if (!$isCorrect && $user !== null) {
        echo ' <span class="text-muted">(Correct: ' . htmlspecialchars($q['answer']) . ')</span>';
      }
      echo '</div>';
    }
  }
  // Match
  $matchQuestions = array_values(array_filter($questions, fn($q) => $q['type']==='match'));
  if ($matchQuestions) {
    echo '<div class="mt-3 mb-2"><strong>Match pairs</strong></div>';
    foreach ($matchQuestions as $i => $q) {
      $user = $answers['match'][$i] ?? [];
      $allCorrect = true;
      foreach ($q['pairs'] as $j => $pair) {
        $isCorrect = (isset($user[$j]) && strcasecmp($user[$j], $pair['right']) === 0);
        if (!$isCorrect) $allCorrect = false;
        echo '<div class="mb-1">' . htmlspecialchars($pair['left']) . ' → ';
        echo '<span class="' . ($isCorrect ? 'text-success' : 'text-danger') . '">' .
          (isset($user[$j]) ? htmlspecialchars($user[$j]) : '<em>No answer</em>') . '</span>';
        if (!$isCorrect && isset($user[$j])) {
          echo ' <span class="text-muted">(Correct: ' . htmlspecialchars($pair['right']) . ')</span>';
        }
        echo '</div>';
      }
    }
  }
  // Dictation
  $dictationQuestions = array_values(array_filter($questions, fn($q) => $q['type']==='dictation'));
  if ($dictationQuestions) {
    echo '<div class="mt-3 mb-2"><strong>Dictation</strong></div>';
    foreach ($dictationQuestions as $i => $q) {
      $user = $answers['dictation'][$i] ?? null;
      $isCorrect = ($user !== null && strcasecmp($user, $q['answer']) === 0);
      echo '<div class="mb-1">Q: [audio] <em>' . htmlspecialchars($q['answer']) . '</em><br>';
      echo 'Your answer: <span class="' . ($isCorrect ? 'text-success' : 'text-danger') . '">' .
        ($user !== null ? htmlspecialchars($user) : '<em>No answer</em>') . '</span>';
      if (!$isCorrect && $user !== null) {
        echo ' <span class="text-muted">(Correct: ' . htmlspecialchars($q['answer']) . ')</span>';
      }
      echo '</div>';
    }
  }
  // Pronunciation
  $pronQuestions = array_values(array_filter($questions, fn($q) => $q['type']==='pronunciation'));
  if ($pronQuestions) {
    echo '<div class="mt-3 mb-2"><strong>Pronunciation</strong></div>';
    foreach ($pronQuestions as $i => $q) {
      $user = $answers['pronunciation'][$i] ?? null;
      $isCorrect = ($user !== null && strcasecmp($user, $q['expected']) === 0);
      echo '<div class="mb-1">Q: ' . htmlspecialchars($q['prompt']) . '<br>';
      echo 'Your answer: <span class="' . ($isCorrect ? 'text-success' : 'text-danger') . '">' .
        ($user !== null ? htmlspecialchars($user) : '<em>No answer</em>') . '</span>';
      if (!$isCorrect && $user !== null) {
        echo ' <span class="text-muted">(Expected: ' . htmlspecialchars($q['expected']) . ')</span>';
      }
      echo '</div>';
    }
  }
  echo '</div>';
  echo '<form method="get" class="mt-4"><input type="hidden" name="step" value="0"><button class="btn btn-lg btn-primary w-100">Start over</button></form>';
}
?>
</div>
</body>
</html>
