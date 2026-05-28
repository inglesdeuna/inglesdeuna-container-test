<?php
session_start();

// --- Configuración Bootstrap y meta ---
?><!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Unit Quiz</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
  <style>
    body { background: #f8f7ff; }
    .quiz-container { max-width: 600px; margin: 40px auto; background: #fff; border-radius: 18px; box-shadow: 0 4px 24px #0001; padding: 32px 24px; }
    .qz-chip { display: inline-block; background: #f5f3ff; color: #7c3aed; border-radius: 16px; padding: 4px 12px; font-size: 13px; margin-right: 6px; margin-bottom: 6px; font-weight: 600; }
    .qz-title { font-family: 'Fredoka', 'Trebuchet MS', sans-serif; font-size: 2rem; color: #f14902; font-weight: 700; }
    .qz-lead { color: #7c3aed; font-size: 1.1rem; margin-bottom: 18px; }
    .qz-section { margin-top: 32px; }
  </style>
  <link href="https://fonts.googleapis.com/css2?family=Fredoka:wght@500;700&family=Nunito:wght@400;700&display=swap" rel="stylesheet">
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
  ];
  $_SESSION['quiz_answers'] = [];
}
$questions = $_SESSION['quiz_questions'];
$answers = &$_SESSION['quiz_answers'];

// --- Pantalla 0: Intro ---
if ($step === 0) {
  // Meta chips y desglose
  echo '<div class="qz-title mb-2">Unit Quiz</div>';
  echo '<div class="qz-lead">Answer all questions to complete this unit and unlock the next one.</div>';
  echo '<div class="mb-3">';
  echo '<span class="qz-chip">' . count($questions) . ' questions</span>';
  echo '<span class="qz-chip">~8 min</span>';
  echo '<span class="qz-chip">3 attempts</span>';
  echo '</div>';
  echo '<div class="mb-3"><strong>What\'s included</strong></div>';
  echo '<ul class="list-group mb-4">';
  echo '<li class="list-group-item">Multiple choice <span class="badge bg-primary float-end">' . count(array_filter($questions, fn($q) => $q['type']==='mc')) . '</span></li>';
  echo '<li class="list-group-item">Fill in the blank <span class="badge bg-warning text-dark float-end">' . count(array_filter($questions, fn($q) => $q['type']==='fill')) . '</span></li>';
  echo '<li class="list-group-item">Match pairs <span class="badge bg-info text-dark float-end">' . count(array_filter($questions, fn($q) => $q['type']==='match')) . '</span></li>';
  echo '</ul>';
  echo '<form method="get"><input type="hidden" name="step" value="1"><button class="btn btn-lg btn-primary w-100">Start quiz</button></form>';
}
// --- Pantalla 1: Multiple Choice ---
elseif ($step === 1) {
  $qIdx = isset($_GET['q']) ? (int)$_GET['q'] : 0;
  $mcQuestions = array_values(array_filter($questions, fn($q) => $q['type']==='mc'));
  $total = count($mcQuestions);
  if ($qIdx < 0) $qIdx = 0;
  if ($qIdx >= $total) $qIdx = $total-1;
  $q = $mcQuestions[$qIdx];

  // Guardar respuesta si viene por POST
  if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['answer'])) {
    $answers['mc'][$qIdx] = (int)$_POST['answer'];
    // Siguiente pregunta o paso
    if ($qIdx+1 < $total) {
      header('Location: ?step=1&q=' . ($qIdx+1));
      exit;
    } else {
      header('Location: ?step=2'); // Siguiente bloque
      exit;
    }
  }

  echo '<div class="mb-3"><span class="badge bg-primary">Multiple choice</span></div>';
  echo '<div class="mb-3"><strong>Question ' . ($qIdx+1) . ' of ' . $total . '</strong></div>';
  echo '<form method="post">';
  echo '<div class="mb-3 fs-5">' . htmlspecialchars($q['question']) . '</div>';
  foreach ($q['options'] as $i => $opt) {
    echo '<div class="form-check mb-2">';
    echo '<input class="form-check-input" type="radio" name="answer" id="opt'.$i.'" value="'.$i.'" required>';
    echo '<label class="form-check-label" for="opt'.$i.'">' . htmlspecialchars($opt) . '</label>';
    echo '</div>';
  }
  echo '<button class="btn btn-primary mt-3">'.($qIdx+1<$total?'Next':'Continue').'</button>';
  echo '</form>';
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
// --- Pantalla 7: Resultados ---
if ($step === 7) {
  echo '<div class="qz-title mb-2">Results</div>';
  echo '<div class="qz-lead">You answered all questions correctly.</div>';
  echo '<form method="get"><input type="hidden" name="step" value="0"><button class="btn btn-lg btn-primary w-100">Start over</button></form>';
}
// --- Pantalla 8: Fin ---
if ($step === 8) {
  echo '<div class="qz-title mb-2">Quiz ended.</div>';
  echo '<div class="qz-lead">Thank you for playing.</div>';
  echo '<form method="get"><input type="hidden" name="step" value="0"><button class="btn btn-lg btn-primary w-100">Start over</button></form>';
}
?>
</div>
</body>
</html>
