<?php
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../core/_activity_viewer_template.php';

if (session_status() !== PHP_SESSION_ACTIVE) {
  session_start();
}

$activityId = isset($_GET['id']) ? trim((string) $_GET['id']) : '';
$unit = isset($_GET['unit']) ? trim((string) $_GET['unit']) : '';
$returnTo = isset($_GET['return_to']) ? trim((string) $_GET['return_to']) : '';

if ($activityId === '' && $unit === '') {
    die('Activity not specified');
}

function resolve_unit_from_activity(PDO $pdo, string $activityId): string
{
    if ($activityId === '') {
        return '';
    }

    $stmt = $pdo->prepare("SELECT unit_id FROM activities WHERE id = :id LIMIT 1");
    $stmt->execute(['id' => $activityId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    return $row && isset($row['unit_id']) ? (string) $row['unit_id'] : '';
}

function normalize_quiz_payload($rawData): array
{
    $default = [
        'title' => 'Unit Quiz',
        'description' => 'Answer and submit your result.',
        'questions' => [],
    ];

    if ($rawData === null || $rawData === '') {
        return $default;
    }

    $decoded = is_string($rawData) ? json_decode($rawData, true) : $rawData;
    if (!is_array($decoded)) {
        return $default;
    }

    $questions = [];
    foreach ((array) ($decoded['questions'] ?? []) as $item) {
        if (!is_array($item)) {
            continue;
        }

        $options = isset($item['options']) && is_array($item['options']) ? $item['options'] : [];
        $normalizedOptions = [];
        for ($i = 0; $i < 4; $i++) {
            $normalizedOptions[] = trim((string) ($options[$i] ?? ''));
        }

        $correct = (int) ($item['correct'] ?? 0);
        if ($correct < 0 || $correct > 3) {
            $correct = 0;
        }

        $questions[] = [
            'question' => trim((string) ($item['question'] ?? '')),
            'options' => $normalizedOptions,
            'correct' => $correct,
            'explanation' => trim((string) ($item['explanation'] ?? '')),
        ];
    }

    return [
        'title' => trim((string) ($decoded['title'] ?? '')) !== '' ? trim((string) $decoded['title']) : $default['title'],
        'description' => trim((string) ($decoded['description'] ?? $default['description'])),
        'questions' => $questions,
    ];
}

function load_quiz_fallback_from_multiple_choice(PDO $pdo, string $unit): array
{
  if ($unit === '') {
    return [
      'id' => '',
      'title' => 'Unit Quiz',
      'description' => 'Answer and submit your result.',
      'questions' => [],
    ];
  }

  try {
    $stmt = $pdo->prepare("\n            SELECT id, data\n            FROM activities\n            WHERE unit_id = :unit\n              AND type = 'multiple_choice'\n            ORDER BY id ASC\n        ");
    $stmt->execute(['unit' => $unit]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $questions = [];
    foreach ($rows as $row) {
      $raw = $row['data'] ?? null;
      $decoded = is_string($raw) ? json_decode($raw, true) : $raw;
      if (!is_array($decoded)) {
        continue;
      }

      $sourceQuestions = [];
      if (isset($decoded['questions']) && is_array($decoded['questions'])) {
        $sourceQuestions = $decoded['questions'];
      } elseif (isset($decoded['items']) && is_array($decoded['items'])) {
        $sourceQuestions = $decoded['items'];
      } elseif (isset($decoded['data']) && is_array($decoded['data'])) {
        $sourceQuestions = $decoded['data'];
      } else {
        $sourceQuestions = $decoded;
      }

      foreach ($sourceQuestions as $item) {
        if (!is_array($item)) {
          continue;
        }

        $options = isset($item['options']) && is_array($item['options'])
          ? $item['options']
          : [
            (string) ($item['option_a'] ?? ''),
            (string) ($item['option_b'] ?? ''),
            (string) ($item['option_c'] ?? ''),
          ];

        $normalizedOptions = [];
        foreach ($options as $optionLabel) {
          $normalizedOptions[] = trim((string) $optionLabel);
        }
        $normalizedOptions = array_values(array_filter($normalizedOptions, static function ($value) {
          return $value !== '';
        }));

        if (count($normalizedOptions) < 2) {
          continue;
        }

        $correct = (int) ($item['correct'] ?? 0);
        if ($correct < 0 || $correct >= count($normalizedOptions)) {
          $correct = 0;
        }

        $questionText = trim((string) ($item['question'] ?? ''));
        if ($questionText === '') {
          continue;
        }

        $questions[] = [
          'question' => $questionText,
          'options' => $normalizedOptions,
          'correct' => $correct,
          'explanation' => '',
        ];
      }
    }

    return [
      'id' => 'quiz_unit_' . $unit,
      'title' => 'Unit Quiz',
      'description' => 'Answer and submit your result.',
      'questions' => $questions,
    ];
  } catch (Throwable $e) {
    return [
      'id' => '',
      'title' => 'Unit Quiz',
      'description' => 'Answer and submit your result.',
      'questions' => [],
    ];
  }
}

function build_fixed_quiz_question_set(array $questions, int $targetCount = 6): array
{
  if ($targetCount <= 0) {
    return [];
  }

  $normalized = [];
  foreach ($questions as $item) {
    if (!is_array($item)) {
      continue;
    }

    $questionText = trim((string) ($item['question'] ?? ''));
    if ($questionText === '') {
      continue;
    }

    $rawOptions = isset($item['options']) && is_array($item['options']) ? $item['options'] : [];
    $options = [];
    foreach ($rawOptions as $option) {
      $label = trim((string) $option);
      if ($label !== '') {
        $options[] = $label;
      }
    }

    if (count($options) < 2) {
      continue;
    }

    $correct = (int) ($item['correct'] ?? 0);
    if ($correct < 0 || $correct >= count($options)) {
      $correct = 0;
    }

    $normalized[] = [
      'question' => $questionText,
      'options' => $options,
      'correct' => $correct,
      'explanation' => trim((string) ($item['explanation'] ?? '')),
    ];
  }

  if (empty($normalized)) {
    return [];
  }

  $pool = $normalized;
  shuffle($pool);
  $selected = array_slice($pool, 0, min($targetCount, count($pool)));

  while (count($selected) < $targetCount) {
    try {
      $index = random_int(0, count($pool) - 1);
    } catch (Throwable $e) {
      $index = 0;
    }
    $picked = $pool[$index];
    $selected[] = [
      'question' => (string) ($picked['question'] ?? ''),
      'options' => isset($picked['options']) && is_array($picked['options']) ? array_values($picked['options']) : [],
      'correct' => (int) ($picked['correct'] ?? 0),
      'explanation' => (string) ($picked['explanation'] ?? ''),
    ];
  }

  return $selected;
}

function load_quiz_activity(PDO $pdo, string $activityId, string $unit): array
{
    $fallback = [
        'id' => '',
        'title' => 'Unit Quiz',
        'description' => 'Answer and submit your result.',
        'questions' => [],
    ];

    $row = null;

    if ($activityId !== '') {
        $stmt = $pdo->prepare("SELECT id, data FROM activities WHERE id = :id AND type = 'quiz' LIMIT 1");
        $stmt->execute(['id' => $activityId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
    }

    if (!$row && $unit !== '') {
        $stmt = $pdo->prepare("SELECT id, data FROM activities WHERE unit_id = :unit AND type = 'quiz' ORDER BY id ASC LIMIT 1");
        $stmt->execute(['unit' => $unit]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
    }

    if (!$row) {
      $fallbackFromMultipleChoice = load_quiz_fallback_from_multiple_choice($pdo, $unit);
      return !empty($fallbackFromMultipleChoice['questions']) ? $fallbackFromMultipleChoice : $fallback;
    }

    $payload = normalize_quiz_payload($row['data'] ?? null);

    $questions = isset($payload['questions']) && is_array($payload['questions']) ? $payload['questions'] : [];
    if (empty($questions)) {
      $fallbackFromMultipleChoice = load_quiz_fallback_from_multiple_choice($pdo, $unit);
      if (!empty($fallbackFromMultipleChoice['questions'])) {
        return $fallbackFromMultipleChoice;
      }
    }

    return [
        'id' => (string) ($row['id'] ?? ''),
        'title' => (string) ($payload['title'] ?? 'Unit Quiz'),
        'description' => (string) ($payload['description'] ?? ''),
      'questions' => $questions,
    ];
}

function load_quiz_match_pairs(PDO $pdo, string $unit): array
{
  if ($unit === '') {
    return [];
  }

  try {
    $stmt = $pdo->prepare("\n            SELECT data\n            FROM activities\n            WHERE unit_id = :unit\n              AND type = 'match'\n            ORDER BY id ASC\n            LIMIT 1\n        ");
    $stmt->execute(['unit' => $unit]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!is_array($row)) {
      return [];
    }

    $raw = $row['data'] ?? null;
    $decoded = is_string($raw) ? json_decode($raw, true) : $raw;
    if (!is_array($decoded)) {
      return [];
    }

    $pairsSource = $decoded;
    if (isset($decoded['pairs']) && is_array($decoded['pairs'])) {
      $pairsSource = $decoded['pairs'];
    } elseif (isset($decoded['items']) && is_array($decoded['items'])) {
      $pairsSource = $decoded['items'];
    } elseif (isset($decoded['data']) && is_array($decoded['data'])) {
      $pairsSource = $decoded['data'];
    }

    $pairs = [];
    foreach ($pairsSource as $item) {
      if (!is_array($item)) {
        continue;
      }

      $legacyText = isset($item['text']) ? trim((string) $item['text']) : (isset($item['word']) ? trim((string) $item['word']) : '');
      $legacyImage = isset($item['image']) ? trim((string) $item['image']) : (isset($item['img']) ? trim((string) $item['img']) : '');

      $leftText = isset($item['left_text']) ? trim((string) $item['left_text']) : '';
      $leftImage = isset($item['left_image']) ? trim((string) $item['left_image']) : $legacyImage;
      $rightText = isset($item['right_text']) ? trim((string) $item['right_text']) : $legacyText;
      $rightImage = isset($item['right_image']) ? trim((string) $item['right_image']) : '';

      if ($leftText === '' && $leftImage === '' && $rightText === '' && $rightImage === '') {
        continue;
      }

      $pairs[] = [
        'left_text' => $leftText,
        'left_image' => $leftImage,
        'right_text' => $rightText,
        'right_image' => $rightImage,
      ];
    }

    return $pairs;
  } catch (Throwable $e) {
    return [];
  }
}

function load_quiz_pronunciation_items(PDO $pdo, string $unit): array
{
  if ($unit === '') {
    return [];
  }

  try {
    $stmt = $pdo->prepare("\n            SELECT data\n            FROM activities\n            WHERE unit_id = :unit\n              AND type = 'pronunciation'\n            ORDER BY id ASC\n            LIMIT 1\n        ");
    $stmt->execute(['unit' => $unit]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!is_array($row)) {
      return [];
    }

    $raw = $row['data'] ?? null;
    $decoded = is_string($raw) ? json_decode($raw, true) : $raw;
    if (!is_array($decoded)) {
      return [];
    }

    $itemsSource = $decoded;
    if (isset($decoded['items']) && is_array($decoded['items'])) {
      $itemsSource = $decoded['items'];
    } elseif (isset($decoded['data']) && is_array($decoded['data'])) {
      $itemsSource = $decoded['data'];
    } elseif (isset($decoded['words']) && is_array($decoded['words'])) {
      $itemsSource = $decoded['words'];
    }

    $items = [];
    foreach ($itemsSource as $item) {
      if (!is_array($item)) {
        continue;
      }

      $word = trim((string) ($item['en'] ?? ($item['word'] ?? '')));
      $image = trim((string) ($item['img'] ?? ($item['image'] ?? '')));
      $audio = trim((string) ($item['audio'] ?? ''));

      if ($word === '' && $image === '' && $audio === '') {
        continue;
      }

      $items[] = [
        'en' => $word,
        'img' => $image,
        'audio' => $audio,
      ];
    }

    return $items;
  } catch (Throwable $e) {
    return [];
  }
}

function table_has_column(PDO $pdo, string $tableName, string $columnName): bool
{
  try {
    $stmt = $pdo->prepare("SELECT 1 FROM information_schema.columns WHERE table_schema = 'public' AND table_name = :table_name AND column_name = :column_name LIMIT 1");
    $stmt->execute([
      'table_name' => $tableName,
      'column_name' => $columnName,
    ]);
    return (bool) $stmt->fetchColumn();
  } catch (Throwable $e) {
    return false;
  }
}

function ensure_quiz_attempts_column(PDO $pdo): void
{
  try {
    $pdo->exec("ALTER TABLE student_activity_results ADD COLUMN IF NOT EXISTS attempts_count INTEGER NOT NULL DEFAULT 1");
  } catch (Throwable $e) {
  }
}

function get_quiz_attempt_policy(PDO $pdo, string $studentId, string $assignmentId, string $unitId, string $activityId): array
{
  $state = [
    'attempts_used' => 0,
    'attempts_allowed' => 3,
    'attempted_today' => false,
    'finish_enabled' => true,
    'message' => '',
  ];

  if ($studentId === '' || $assignmentId === '' || $unitId === '' || $activityId === '') {
    return $state;
  }

  try {
    $hasAttemptsColumn = table_has_column($pdo, 'student_activity_results', 'attempts_count');
    $sql = $hasAttemptsColumn
      ? "\n                SELECT attempts_count, total_count, updated_at\n                FROM student_activity_results\n                WHERE student_id = :student_id\n                  AND assignment_id = :assignment_id\n                  AND unit_id = :unit_id\n                  AND activity_id = :activity_id\n                LIMIT 1\n            "
      : "\n                SELECT total_count, updated_at\n                FROM student_activity_results\n                WHERE student_id = :student_id\n                  AND assignment_id = :assignment_id\n                  AND unit_id = :unit_id\n                  AND activity_id = :activity_id\n                LIMIT 1\n            ";

    $stmt = $pdo->prepare($sql);
    $stmt->execute([
      'student_id' => $studentId,
      'assignment_id' => $assignmentId,
      'unit_id' => $unitId,
      'activity_id' => $activityId,
    ]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!is_array($row)) {
      return $state;
    }

    $attemptsUsed = 0;
    if ($hasAttemptsColumn) {
      $attemptsUsed = max(0, (int) ($row['attempts_count'] ?? 0));
    } else {
      $totalCount = max(0, (int) ($row['total_count'] ?? 0));
      // Fallback when attempts_count is not available.
      $attemptsUsed = $totalCount > 0 ? 1 : 0;
    }

    $attemptedToday = false;
    $updatedAt = trim((string) ($row['updated_at'] ?? ''));
    if ($updatedAt !== '') {
      try {
        $attemptDate = (new DateTimeImmutable($updatedAt))->setTimezone(new DateTimeZone(date_default_timezone_get()))->format('Y-m-d');
        $today = (new DateTimeImmutable('now'))->format('Y-m-d');
        $attemptedToday = ($attemptDate === $today) && $attemptsUsed > 0;
      } catch (Throwable $e) {
        $attemptedToday = false;
      }
    }

    $state['attempts_used'] = $attemptsUsed;
    $state['attempted_today'] = $attemptedToday;

    if ($attemptsUsed >= 3) {
      $state['finish_enabled'] = false;
      $state['message'] = 'You already used 3/3 attempts for this quiz.';
    } elseif ($attemptedToday) {
      $state['finish_enabled'] = false;
      $state['message'] = 'You already attempted this quiz today. Try again tomorrow.';
    }
  } catch (Throwable $e) {
    return $state;
  }

  return $state;
}

if ($unit === '' && $activityId !== '') {
    $unit = resolve_unit_from_activity($pdo, $activityId);
}

if ($returnTo === '') {
  $returnTo = '../../academic/teacher_course.php?assignment=' . urlencode((string) ($_GET['assignment'] ?? '')) . '&unit=' . urlencode($unit) . '&step=9999';
}

$activity = load_quiz_activity($pdo, $activityId, $unit);
$viewerTitle = (string) ($activity['title'] ?? 'Unit Quiz');
$questions = isset($activity['questions']) && is_array($activity['questions']) ? $activity['questions'] : [];
$questions = build_fixed_quiz_question_set($questions, 6);
$description = (string) ($activity['description'] ?? '');
$quizMatchPairs = load_quiz_match_pairs($pdo, $unit);
$quizPronunciationItems = load_quiz_pronunciation_items($pdo, $unit);

ensure_quiz_attempts_column($pdo);

$studentId = trim((string) ($_SESSION['student_id'] ?? ''));
$assignmentId = trim((string) ($_GET['assignment'] ?? ''));
$quizAttemptPolicy = get_quiz_attempt_policy($pdo, $studentId, $assignmentId, $unit, (string) ($activity['id'] ?? $activityId));

ob_start();
?>
<style>
.qz-wrap{max-width:980px;margin:0 auto;padding:8px 0 24px;display:flex;flex-direction:column;gap:14px}
.qz-hero{border:1px solid #dcc4f0;border-radius:18px;padding:18px;background:linear-gradient(145deg,#fff8e6 0%,#fdeaff 55%,#f0e0ff 100%);box-shadow:0 10px 24px rgba(120,40,160,.12)}
.qz-title{margin:0;font-family:'Fredoka','Trebuchet MS',sans-serif;font-size:30px;line-height:1.1;color:#a855c8}
.qz-lead{font-size:15px;color:#b8551f;margin:8px 0 0;line-height:1.5}
.qz-meta{display:flex;gap:8px;flex-wrap:wrap;margin-top:12px}
.qz-chip{display:inline-flex;align-items:center;padding:6px 10px;border-radius:999px;background:#eddeff;color:#a855c8;font-size:12px;font-weight:800;text-transform:uppercase;letter-spacing:.03em}
.qz-progress-head{display:flex;justify-content:space-between;align-items:center;gap:10px;margin-top:12px}
.qz-progress-label{font-size:13px;font-weight:800;color:#b8551f}
.qz-progress-track{width:100%;height:10px;border-radius:999px;background:#f3e5ff;overflow:hidden;border:1px solid #e8ccff}
.qz-progress-fill{height:100%;width:0%;background:linear-gradient(90deg,#a855c8 0%,#f14902 100%);transition:width .2s ease}
.qz-alert{border:1px solid #f7b4b4;background:#fff2f2;color:#9b1c1c;border-radius:12px;padding:12px 14px;font-weight:700}
.qz-empty{padding:14px;border:1px solid #dcc4f0;border-radius:12px;background:#fff;color:#b8551f}
.qz-list{display:flex;flex-direction:column;gap:12px}
.qz-card{border:1px solid #dcc4f0;border-radius:14px;padding:14px;background:#fff;box-shadow:0 6px 16px rgba(120,40,160,.08)}
.qz-card-unanswered{border-color:#ef4444;background:#fff4f4}
.qz-q{font-weight:800;color:#f14902;margin-bottom:10px;font-size:17px}
.qz-opts{display:grid;grid-template-columns:1fr;gap:8px}
.qz-opt{display:flex;align-items:flex-start;gap:10px;padding:10px;border:1px solid #ead6f8;border-radius:10px;background:#fff9ff;cursor:pointer;transition:border-color .15s,background .15s}
.qz-opt:hover{border-color:#a855c8;background:#f9efff}
.qz-opt input{margin-top:2px}
.qz-match-wrap{display:flex;flex-direction:column;gap:10px}
.qz-match-help{font-size:14px;color:#5d6f8f;font-weight:700}
.qz-match-status{font-size:13px;color:#7c3aed;font-weight:800}
.qz-match-rows{display:flex;flex-direction:column;gap:10px}
.qz-match-row{display:grid;grid-template-columns:repeat(auto-fit, minmax(92px, 1fr));gap:8px}
.qz-match-tile{border:1px solid #e6d5f8;border-radius:10px;background:#fff9ff;padding:8px;min-height:70px;display:flex;align-items:center;justify-content:center;text-align:center;font-size:12px;font-weight:800;color:#3f2a63;cursor:pointer;user-select:none}
.qz-match-tile img{max-width:100%;max-height:52px;object-fit:contain;border-radius:8px}
.qz-match-top .qz-match-tile{background:#fff7e8;border-color:#f4d7a3}
.qz-match-bottom .qz-match-tile{background:#eef7ff;border-color:#bcdaf5}
.qz-match-tile.is-selected{outline:2px solid #a855c8;outline-offset:1px}
.qz-match-tile.is-matched{opacity:.55;cursor:default;filter:grayscale(.08)}
.qz-match-tile.is-wrong{background:#fff1f1;border-color:#ef4444}
.qz-pron-wrap{display:flex;flex-direction:column;gap:10px}
.qz-pron-help{font-size:14px;color:#5d6f8f;font-weight:700}
.qz-pron-grid{display:grid;gap:10px}
.qz-pron-grid-6{grid-template-columns:repeat(6,minmax(120px,1fr))}
.qz-pron-grid-8{grid-template-columns:repeat(4,minmax(150px,1fr))}
.qz-pron-card{border:1px solid #e6d5f8;border-radius:12px;background:#fff9ff;padding:10px;display:flex;flex-direction:column;align-items:center;justify-content:flex-start;min-height:200px;gap:8px}
.qz-pron-img{width:100%;max-width:132px;height:90px;object-fit:contain;border-radius:10px;background:#fff}
.qz-pron-word{font-size:16px;font-weight:800;color:#2d1f4f;text-align:center;line-height:1.2;min-height:38px}
.qz-pron-actions{display:flex;gap:6px;flex-wrap:wrap;justify-content:center}
.qz-pron-btn{border:none;border-radius:8px;padding:7px 10px;font-size:12px;font-weight:800;cursor:pointer;color:#fff}
.qz-pron-btn.listen{background:linear-gradient(180deg,#0ea5e9,#0369a1)}
.qz-pron-btn.speak{background:linear-gradient(180deg,#16a34a,#166534)}
.qz-pron-status{font-size:12px;font-weight:800;color:#7c3aed;text-align:center;min-height:18px}
.qz-pron-status.ok{color:#166534}
.qz-pron-status.bad{color:#b91c1c}
.qz-actions{display:flex;gap:10px;flex-wrap:wrap;justify-content:center;position:sticky;bottom:12px;padding:12px;border:1px solid #dcc4f0;border-radius:14px;background:rgba(255,255,255,.95);backdrop-filter:blur(3px)}
.qz-btn{border:none;border-radius:10px;padding:12px 16px;font-weight:800;cursor:pointer;color:#fff;background:linear-gradient(180deg,#f14902,#d33d00);box-shadow:0 8px 18px rgba(241,73,2,.22)}
.qz-btn:disabled{opacity:.55;cursor:not-allowed}
.qz-result{padding:12px;border-radius:10px;background:#e9f8ee;color:#166534;font-weight:700;display:none;text-align:center}
.qz-completed-screen{display:none;text-align:center;max-width:600px;margin:0 auto;padding:40px 20px;border:1px solid #dcc4f0;border-radius:16px;background:#fff;box-shadow:0 10px 24px rgba(120,40,160,.12)}
.qz-completed-screen.active{display:block}
.qz-completed-icon{font-size:72px;margin-bottom:14px}
.qz-completed-title{font-family:'Fredoka','Trebuchet MS',sans-serif;font-size:34px;font-weight:700;color:#a855c8;margin:0 0 14px;line-height:1.2}
.qz-completed-score{font-size:22px;font-weight:800;color:#f14902;margin:0 0 8px}
.qz-completed-text{font-size:16px;color:#b8551f;line-height:1.6;margin:0 0 14px}
.qz-completed-note{font-size:14px;font-weight:700;color:#7c3aed}
@media (max-width:1100px){.qz-pron-grid-6{grid-template-columns:repeat(4,minmax(140px,1fr))}}
@media (max-width:760px){.qz-title{font-size:26px}.qz-q{font-size:16px}.qz-actions{position:static}.qz-pron-grid-6,.qz-pron-grid-8{grid-template-columns:repeat(2,minmax(130px,1fr))}}
</style>

<div class="qz-wrap" id="quizApp">
  <section class="qz-hero">
    <h2 class="qz-title"><?php echo htmlspecialchars($viewerTitle, ENT_QUOTES, 'UTF-8'); ?></h2>
    <?php if ($description !== '') { ?>
      <p class="qz-lead"><?php echo htmlspecialchars($description, ENT_QUOTES, 'UTF-8'); ?></p>
    <?php } else { ?>
      <p class="qz-lead">One-page quiz. Questions are randomized every time you enter.</p>
    <?php } ?>
    <div class="qz-meta">
      <span class="qz-chip">Attempts: <?php echo (int) ($quizAttemptPolicy['attempts_used'] ?? 0); ?>/<?php echo (int) ($quizAttemptPolicy['attempts_allowed'] ?? 3); ?></span>
      <span class="qz-chip">Rule: 1 per day</span>
      <span class="qz-chip">Random questions</span>
      <span class="qz-chip" id="qz-answered-chip">Answered: <span id="qz-answered-count">0</span>/<span id="qz-total-count"><?php echo count($questions); ?></span></span>
    </div>
    <div class="qz-progress-head">
      <span class="qz-progress-label">Progress</span>
      <span class="qz-progress-label" id="qz-progress-percent">0%</span>
    </div>
    <div class="qz-progress-track">
      <div class="qz-progress-fill" id="qz-progress-fill"></div>
    </div>
  </section>

  <?php if (!empty($quizAttemptPolicy['message'])) { ?>
    <div class="qz-alert"><?php echo htmlspecialchars((string) $quizAttemptPolicy['message'], ENT_QUOTES, 'UTF-8'); ?></div>
  <?php } ?>

  <?php if (empty($questions)) { ?>
    <div class="qz-empty">This quiz does not have questions yet. Open the editor to configure it.</div>
  <?php } else { ?>
    <div id="qz-questions-wrap">
      <div class="qz-list" id="qz-list"></div>
      <div class="qz-actions">
        <button type="button" class="qz-btn" id="btnCheckQuiz" <?php echo !empty($quizAttemptPolicy['finish_enabled']) ? '' : 'disabled'; ?>>Finish quiz</button>
      </div>
      <div class="qz-result" id="quizResult"></div>
    </div>

    <div id="qz-completed" class="qz-completed-screen">
      <div class="qz-completed-icon">✅</div>
      <h2 class="qz-completed-title"><?php echo htmlspecialchars($viewerTitle, ENT_QUOTES, 'UTF-8'); ?></h2>
      <p class="qz-completed-score" id="qz-score-text"></p>
      <p class="qz-completed-text" id="qz-completed-text">Your percentage was added to the unit score.</p>
      <p class="qz-completed-note" id="qz-attempt-note"></p>
    </div>
  <?php } ?>
</div>

<script>
window.QUIZ_DATA = <?php echo json_encode($questions, JSON_UNESCAPED_UNICODE); ?>;
window.QUIZ_RETURN_TO = <?php echo json_encode($returnTo, JSON_UNESCAPED_UNICODE); ?>;
window.QUIZ_ACTIVITY_ID = <?php echo json_encode((string) ($activity['id'] ?? ''), JSON_UNESCAPED_UNICODE); ?>;
window.QUIZ_POLICY = <?php echo json_encode($quizAttemptPolicy, JSON_UNESCAPED_UNICODE); ?>;
window.QUIZ_MATCH_DATA = <?php echo json_encode($quizMatchPairs, JSON_UNESCAPED_UNICODE); ?>;
window.QUIZ_PRONUNCIATION_DATA = <?php echo json_encode($quizPronunciationItems, JSON_UNESCAPED_UNICODE); ?>;
(function(){
  const btn = document.getElementById('btnCheckQuiz');
  const questionsWrap = document.getElementById('qz-questions-wrap');
  const completedScreen = document.getElementById('qz-completed');
  const scoreTextEl = document.getElementById('qz-score-text');
  const resultEl = document.getElementById('quizResult');
  const listEl = document.getElementById('qz-list');
  const attemptNoteEl = document.getElementById('qz-attempt-note');
  const answeredCountEl = document.getElementById('qz-answered-count');
  const totalCountEl = document.getElementById('qz-total-count');
  const progressFillEl = document.getElementById('qz-progress-fill');
  const progressPercentEl = document.getElementById('qz-progress-percent');
  const policy = window.QUIZ_POLICY || {};
  const quizMatchData = Array.isArray(window.QUIZ_MATCH_DATA) ? window.QUIZ_MATCH_DATA : [];
  const quizPronunciationData = Array.isArray(window.QUIZ_PRONUNCIATION_DATA) ? window.QUIZ_PRONUNCIATION_DATA : [];

  function shuffleArray(items) {
    const cloned = items.slice();
    for (let i = cloned.length - 1; i > 0; i -= 1) {
      const j = Math.floor(Math.random() * (i + 1));
      const tmp = cloned[i];
      cloned[i] = cloned[j];
      cloned[j] = tmp;
    }
    return cloned;
  }

  function buildRandomizedQuiz(rawQuestions) {
    const mapped = rawQuestions.map(function (q) {
      const options = Array.isArray(q.options) ? q.options : [];
      const correctIndex = Number.isInteger(q.correct) ? q.correct : 0;
      const optionObjects = options.map(function (label, idx) {
        return {
          label: String(label || ''),
          isCorrect: idx === correctIndex,
        };
      });
      return {
        question: String(q.question || ''),
        options: shuffleArray(optionObjects),
      };
    });

    return shuffleArray(mapped);
  }

  function escapeHtml(value) {
    return String(value || '')
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;')
      .replace(/'/g, '&#039;');
  }

  function renderTileContent(text, image) {
    const safeText = escapeHtml(text || '');
    const safeImage = escapeHtml(image || '');
    const media = safeImage !== '' ? ('<img src="' + safeImage + '" alt="">') : '';
    const label = safeText !== '' ? ('<span>' + safeText + '</span>') : '';
    return media + label;
  }

  function persistScoreSilently(targetUrl) {
    if (!targetUrl) return Promise.resolve(false);
    try {
      return fetch(targetUrl, { method: 'GET', credentials: 'same-origin', cache: 'no-store', keepalive: true })
        .then(function (response) { return !!(response && response.ok); })
        .catch(function(){ return false; });
    } catch(e) {
      return Promise.resolve(false);
    }
  }

  function navigateToReturn(targetUrl) {
    if (!targetUrl) return;
    try {
      if (window.top && window.top !== window.self) {
        window.top.location.href = targetUrl;
        return;
      }
    } catch(e) {}
    window.location.href = targetUrl;
  }

  const rawQuizData = Array.isArray(window.QUIZ_DATA) ? window.QUIZ_DATA : [];
  const hasAnyQuizBlock = rawQuizData.length > 0 || quizMatchData.length > 0 || quizPronunciationData.length > 0;
  if (!btn || !listEl || !hasAnyQuizBlock) {
    return;
  }

  const randomizedQuestions = buildRandomizedQuiz(rawQuizData);

  function buildFixedMatchPairs(rawPairs, targetCount) {
    if (!Array.isArray(rawPairs) || rawPairs.length === 0 || targetCount <= 0) {
      return [];
    }

    const cleaned = rawPairs
      .map(function (item) {
        return {
          left_text: String(item.left_text || ''),
          left_image: String(item.left_image || ''),
          right_text: String(item.right_text || ''),
          right_image: String(item.right_image || ''),
        };
      })
      .filter(function (item) {
        return item.left_text !== '' || item.left_image !== '' || item.right_text !== '' || item.right_image !== '';
      });

    if (cleaned.length === 0) {
      return [];
    }

    const shuffled = shuffleArray(cleaned.slice());
    const selected = shuffled.slice(0, Math.min(targetCount, shuffled.length));

    // Keep a fixed card count even when source data has fewer than target pairs.
    while (selected.length < targetCount) {
      const source = selected.length > 0 ? selected : shuffled;
      const randomIndex = Math.floor(Math.random() * source.length);
      const picked = source[randomIndex];
      selected.push({
        left_text: picked.left_text,
        left_image: picked.left_image,
        right_text: picked.right_text,
        right_image: picked.right_image,
      });
    }

    return selected;
  }

  const fixedMatchPairs = buildFixedMatchPairs(quizMatchData, 9);
  function normalizeWord(value) {
    return String(value || '')
      .normalize('NFD')
      .replace(/[\u0300-\u036f]/g, '')
      .toLowerCase()
      .replace(/[^a-z0-9\s]/g, ' ')
      .replace(/\s+/g, ' ')
      .trim();
  }

  function buildFixedPronunciationSet(rawItems) {
    if (!Array.isArray(rawItems) || rawItems.length === 0) {
      return [];
    }

    const targetCount = window.matchMedia && window.matchMedia('(max-width: 1100px)').matches ? 8 : 6;
    const cleaned = rawItems
      .map(function (item) {
        return {
          en: String(item.en || item.word || '').trim(),
          img: String(item.img || item.image || '').trim(),
          audio: String(item.audio || '').trim(),
        };
      })
      .filter(function (item) {
        return item.en !== '' || item.img !== '' || item.audio !== '';
      });

    if (cleaned.length === 0) {
      return [];
    }

    const shuffled = shuffleArray(cleaned.slice());
    const selected = shuffled.slice(0, Math.min(targetCount, shuffled.length));

    while (selected.length < targetCount) {
      const source = selected.length > 0 ? selected : shuffled;
      const idx = Math.floor(Math.random() * source.length);
      selected.push({
        en: source[idx].en,
        img: source[idx].img,
        audio: source[idx].audio,
      });
    }

    return selected;
  }

  const pronunciationItems = buildFixedPronunciationSet(quizPronunciationData);
  const matchState = {
    enabled: fixedMatchPairs.length > 0,
    total: fixedMatchPairs.length,
    answered: 0,
    correct: 0,
    selectedTop: '',
    attemptsByTop: {},
    matchedTop: {},
    matchedBottom: {},
  };
  const pronunciationState = {
    enabled: pronunciationItems.length > 0,
    total: pronunciationItems.length,
    answered: 0,
    correct: 0,
    done: {},
  };

  function updateAnsweredProgress() {
    const totalQuestions = randomizedQuestions.length;
    let answeredQuestions = 0;
    for (let idx = 0; idx < totalQuestions; idx += 1) {
      const hasAnswer = !!document.querySelector('input[name="q_' + idx + '"]:checked');
      if (hasAnswer) {
        answeredQuestions += 1;
      }

      const cardNode = listEl.querySelector('.qz-card[data-index="' + idx + '"]');
      if (cardNode) {
        cardNode.classList.toggle('qz-card-unanswered', !hasAnswer);
      }
    }

    const pronunciationCard = listEl.querySelector('.qz-card[data-index="quiz-pronunciation"]');
    if (pronunciationCard) {
      pronunciationCard.classList.toggle('qz-card-unanswered', pronunciationState.enabled && pronunciationState.answered < pronunciationState.total);
    }

    const answered = answeredQuestions
      + (matchState.enabled ? matchState.answered : 0)
      + (pronunciationState.enabled ? pronunciationState.answered : 0);
    const total = totalQuestions
      + (matchState.enabled ? matchState.total : 0)
      + (pronunciationState.enabled ? pronunciationState.total : 0);

    const pct = total > 0 ? Math.round((answered / total) * 100) : 0;
    if (answeredCountEl) {
      answeredCountEl.textContent = String(answered);
    }
    if (totalCountEl) {
      totalCountEl.textContent = String(total);
    }
    if (progressFillEl) {
      progressFillEl.style.width = String(pct) + '%';
    }
    if (progressPercentEl) {
      progressPercentEl.textContent = String(pct) + '%';
    }

    if (btn) {
      const lockedByPolicy = !policy.finish_enabled;
      btn.disabled = lockedByPolicy;
    }

    return { answered: answered, total: total };
  }

  if (pronunciationState.enabled) {
    const recognitionCtor = window.SpeechRecognition || window.webkitSpeechRecognition || null;

    const pronCard = document.createElement('div');
    pronCard.className = 'qz-card qz-card-unanswered';
    pronCard.setAttribute('data-index', 'quiz-pronunciation');

    const pronTitle = document.createElement('div');
    pronTitle.className = 'qz-q';
    const pronQuestionIndex = randomizedQuestions.length + (matchState.enabled ? 1 : 0) + 1;
    pronTitle.textContent = String(pronQuestionIndex) + '. Pronunciation challenge';
    pronCard.appendChild(pronTitle);

    const pronWrap = document.createElement('div');
    pronWrap.className = 'qz-pron-wrap';
    pronWrap.innerHTML = '<div class="qz-pron-help">Pronounce each card correctly. Desktop shows 6 in one row; compact screens use 2 rows of 4.</div>';

    const pronGrid = document.createElement('div');
    pronGrid.className = 'qz-pron-grid ' + (pronunciationState.total >= 8 ? 'qz-pron-grid-8' : 'qz-pron-grid-6');

    pronunciationItems.forEach(function (item, idx) {
      const card = document.createElement('div');
      card.className = 'qz-pron-card';

      if (item.img) {
        const img = document.createElement('img');
        img.className = 'qz-pron-img';
        img.src = item.img;
        img.alt = item.en || 'Pronunciation card';
        card.appendChild(img);
      }

      const word = document.createElement('div');
      word.className = 'qz-pron-word';
      word.textContent = item.en || 'Pronounce';
      card.appendChild(word);

      const actions = document.createElement('div');
      actions.className = 'qz-pron-actions';

      const listenBtn = document.createElement('button');
      listenBtn.type = 'button';
      listenBtn.className = 'qz-pron-btn listen';
      listenBtn.textContent = 'Listen';

      const speakBtn = document.createElement('button');
      speakBtn.type = 'button';
      speakBtn.className = 'qz-pron-btn speak';
      speakBtn.textContent = recognitionCtor ? 'Speak' : 'Mark Done';

      actions.appendChild(listenBtn);
      actions.appendChild(speakBtn);
      card.appendChild(actions);

      const status = document.createElement('div');
      status.className = 'qz-pron-status';
      status.textContent = 'Pending';
      card.appendChild(status);

      function markPronunciationDone(ok, text) {
        if (pronunciationState.done[idx]) {
          return;
        }
        pronunciationState.done[idx] = true;
        pronunciationState.answered += 1;
        if (ok) {
          pronunciationState.correct += 1;
          status.classList.add('ok');
        } else {
          status.classList.add('bad');
        }
        status.textContent = text;
        speakBtn.disabled = true;
        updateAnsweredProgress();
      }

      listenBtn.addEventListener('click', function () {
        if (item.audio) {
          try {
            const audio = new Audio(item.audio);
            audio.play();
            return;
          } catch (e) {
          }
        }

        if ('speechSynthesis' in window && item.en) {
          const utterance = new SpeechSynthesisUtterance(item.en);
          utterance.lang = 'en-US';
          window.speechSynthesis.cancel();
          window.speechSynthesis.speak(utterance);
        }
      });

      speakBtn.addEventListener('click', function () {
        if (!recognitionCtor) {
          markPronunciationDone(true, 'Completed');
          return;
        }

        const expected = normalizeWord(item.en);
        if (expected === '') {
          markPronunciationDone(true, 'Completed');
          return;
        }

        const recognition = new recognitionCtor();
        recognition.lang = 'en-US';
        recognition.interimResults = false;
        recognition.maxAlternatives = 1;
        status.classList.remove('ok', 'bad');
        status.textContent = 'Listening...';

        recognition.onresult = function (event) {
          const spokenRaw = event && event.results && event.results[0] && event.results[0][0]
            ? String(event.results[0][0].transcript || '')
            : '';
          const spoken = normalizeWord(spokenRaw);
          if (spoken === expected || spoken.indexOf(expected) !== -1 || expected.indexOf(spoken) !== -1) {
            markPronunciationDone(true, 'Correct');
            return;
          }

          status.classList.add('bad');
          status.textContent = 'Try again';
        };

        recognition.onerror = function () {
          status.classList.add('bad');
          status.textContent = 'Try again';
        };

        try {
          recognition.start();
        } catch (e) {
          status.classList.add('bad');
          status.textContent = 'Try again';
        }
      });

      pronGrid.appendChild(card);
    });

    pronWrap.appendChild(pronGrid);
    pronCard.appendChild(pronWrap);
    listEl.appendChild(pronCard);
  }

  function focusFirstUnanswered() {
    const firstMissing = listEl.querySelector('.qz-card.qz-card-unanswered');
    if (!firstMissing) {
      return;
    }

    try {
      firstMissing.scrollIntoView({ behavior: 'smooth', block: 'center' });
    } catch (e) {
      firstMissing.scrollIntoView();
    }
  }

  randomizedQuestions.forEach(function (q, idx) {
    const card = document.createElement('div');
    card.className = 'qz-card';
    card.setAttribute('data-index', String(idx));

    const qTitle = document.createElement('div');
    qTitle.className = 'qz-q';
    qTitle.textContent = (idx + 1) + '. ' + q.question;
    card.appendChild(qTitle);

    const opts = document.createElement('div');
    opts.className = 'qz-opts';

    q.options.forEach(function (opt, optIdx) {
      const label = document.createElement('label');
      label.className = 'qz-opt';

      const radio = document.createElement('input');
      radio.type = 'radio';
      radio.name = 'q_' + idx;
      radio.value = String(optIdx);

      const text = document.createElement('span');
      text.textContent = opt.label;

      label.appendChild(radio);
      label.appendChild(text);
      opts.appendChild(label);
    });

    card.appendChild(opts);
    listEl.appendChild(card);
  });

  if (matchState.enabled) {
    const keyedPairs = fixedMatchPairs.map(function (item, idx) {
      return {
        key: 'qm_' + idx,
        left_text: String(item.left_text || ''),
        left_image: String(item.left_image || ''),
        right_text: String(item.right_text || ''),
        right_image: String(item.right_image || ''),
      };
    });

    const topItems = shuffleArray(keyedPairs.slice());
    const bottomItems = shuffleArray(keyedPairs.slice());

    const matchCard = document.createElement('div');
    matchCard.className = 'qz-card qz-card-unanswered';
    matchCard.setAttribute('data-index', 'quiz-match');

    const title = document.createElement('div');
    title.className = 'qz-q';
    title.textContent = (randomizedQuestions.length + 1) + '. Match the cards (Top with Bottom)';
    matchCard.appendChild(title);

    const wrap = document.createElement('div');
    wrap.className = 'qz-match-wrap';
    wrap.innerHTML = ''
      + '<div class="qz-match-help">Top row matches with bottom row. Choose a top card, then its correct card below.</div>'
      + '<div class="qz-match-status" id="qz-match-status">Matched: 0/' + String(matchState.total) + '</div>'
      + '<div class="qz-match-rows">'
      + '  <div class="qz-match-row qz-match-top" id="qz-match-top"></div>'
      + '  <div class="qz-match-row qz-match-bottom" id="qz-match-bottom"></div>'
      + '</div>';
    matchCard.appendChild(wrap);
    listEl.appendChild(matchCard);

    const topRow = matchCard.querySelector('#qz-match-top');
    const bottomRow = matchCard.querySelector('#qz-match-bottom');
    const status = matchCard.querySelector('#qz-match-status');

    function clearWrongState(node) {
      if (!node) {
        return;
      }
      node.classList.remove('is-wrong');
      setTimeout(function () {
        node.classList.remove('is-wrong');
      }, 260);
    }

    function refreshMatchStatus() {
      if (status) {
        status.textContent = 'Matched: ' + String(matchState.answered) + '/' + String(matchState.total);
      }
      updateAnsweredProgress();
    }

    topItems.forEach(function (item) {
      const btn = document.createElement('button');
      btn.type = 'button';
      btn.className = 'qz-match-tile';
      btn.setAttribute('data-key', item.key);
      btn.innerHTML = renderTileContent(item.left_text, item.left_image);
      // Drag and drop events
      btn.setAttribute('draggable', 'true');
      btn.addEventListener('dragstart', function (e) {
        if (matchState.matchedTop[item.key]) return;
        e.dataTransfer.effectAllowed = 'move';
        e.dataTransfer.setData('text/plain', item.key);
        btn.classList.add('is-selected');
      });
      btn.addEventListener('dragend', function () {
        btn.classList.remove('is-selected');
      });
      topRow.appendChild(btn);
    });

    bottomItems.forEach(function (item) {
      const btn = document.createElement('button');
      btn.type = 'button';
      btn.className = 'qz-match-tile';
      btn.setAttribute('data-key', item.key);
      btn.innerHTML = renderTileContent(item.right_text, item.right_image);
      // Drag and drop events
      btn.addEventListener('dragover', function (e) {
        if (matchState.matchedBottom[item.key]) return;
        e.preventDefault();
        btn.classList.add('is-selected');
      });
      btn.addEventListener('dragleave', function () {
        btn.classList.remove('is-selected');
      });
      btn.addEventListener('drop', function (e) {
        if (matchState.matchedBottom[item.key]) return;
        e.preventDefault();
        btn.classList.remove('is-selected');
        const selectedTop = e.dataTransfer.getData('text/plain');
        if (!selectedTop || matchState.matchedTop[selectedTop]) return;

        const currentAttempts = (matchState.attemptsByTop[selectedTop] || 0) + 1;
        matchState.attemptsByTop[selectedTop] = currentAttempts;

        if (selectedTop === item.key) {
          matchState.matchedTop[selectedTop] = true;
          matchState.matchedBottom[item.key] = true;
          matchState.answered += 1;
          if (currentAttempts === 1) {
            matchState.correct += 1;
          }

          const topBtn = topRow.querySelector('.qz-match-tile[data-key="' + selectedTop + '"]');
          if (topBtn) {
            topBtn.classList.remove('is-selected');
            topBtn.classList.add('is-matched');
          }
          btn.classList.add('is-matched');
          matchState.selectedTop = '';
          refreshMatchStatus();
          return;
        }

        const topBtn = topRow.querySelector('.qz-match-tile[data-key="' + selectedTop + '"]');
        if (topBtn) {
          topBtn.classList.add('is-wrong');
          clearWrongState(topBtn);
        }
        btn.classList.add('is-wrong');
        clearWrongState(btn);
      });
      // Click fallback for accessibility
      btn.addEventListener('click', function () {
        const selectedTop = matchState.selectedTop;
        if (!selectedTop || matchState.matchedBottom[item.key] || matchState.matchedTop[selectedTop]) {
          return;
        }

        const currentAttempts = (matchState.attemptsByTop[selectedTop] || 0) + 1;
        matchState.attemptsByTop[selectedTop] = currentAttempts;

        if (selectedTop === item.key) {
          matchState.matchedTop[selectedTop] = true;
          matchState.matchedBottom[item.key] = true;
          matchState.answered += 1;
          if (currentAttempts === 1) {
            matchState.correct += 1;
          }

          const topBtn = topRow.querySelector('.qz-match-tile[data-key="' + selectedTop + '"]');
          if (topBtn) {
            topBtn.classList.remove('is-selected');
            topBtn.classList.add('is-matched');
          }
          btn.classList.add('is-matched');
          matchState.selectedTop = '';
          refreshMatchStatus();
          return;
        }

        const topBtn = topRow.querySelector('.qz-match-tile[data-key="' + selectedTop + '"]');
        if (topBtn) {
          topBtn.classList.add('is-wrong');
          clearWrongState(topBtn);
        }
        btn.classList.add('is-wrong');
        clearWrongState(btn);
      });
      bottomRow.appendChild(btn);
    });

    refreshMatchStatus();
  }

  listEl.addEventListener('change', function (event) {
    const target = event.target;
    if (target && target.matches('input[type="radio"]')) {
      updateAnsweredProgress();
    }
  });

  updateAnsweredProgress();

  btn.addEventListener('click', async function(){
    if (!policy.finish_enabled) {
      if (resultEl) {
        resultEl.style.display = 'block';
        resultEl.textContent = String(policy.message || 'Finish is locked for now.');
      }
      return;
    }

    const progress = updateAnsweredProgress();
    if (progress.answered < progress.total) {
      if (resultEl) {
        resultEl.style.display = 'block';
        resultEl.style.background = '#fff2f2';
        resultEl.style.color = '#9b1c1c';
        resultEl.textContent = 'Answer all questions before finishing.';
      }
      focusFirstUnanswered();
      return;
    }

    if (resultEl) {
      resultEl.style.display = 'none';
      resultEl.style.background = '#e9f8ee';
      resultEl.style.color = '#166534';
      resultEl.textContent = '';
    }

    let correct = 0;
    const total = randomizedQuestions.length
      + (matchState.enabled ? matchState.total : 0)
      + (pronunciationState.enabled ? pronunciationState.total : 0);

    randomizedQuestions.forEach(function(q, idx){
      const checked = document.querySelector('input[name="q_' + idx + '"]:checked');
      const selected = checked ? parseInt(checked.value || '-1', 10) : -1;
      if (selected >= 0 && q.options[selected] && q.options[selected].isCorrect) {
        correct += 1;
      }
    });

    if (matchState.enabled) {
      correct += matchState.correct;
    }

    if (pronunciationState.enabled) {
      correct += pronunciationState.correct;
    }

    const percent = total > 0 ? Math.round((correct / total) * 100) : 0;
    const errors = Math.max(0, total - correct);
    const hasQuery = window.QUIZ_RETURN_TO.indexOf('?') !== -1;
    const joiner = hasQuery ? '&' : '?';
    const saveUrl = window.QUIZ_RETURN_TO
      + joiner + 'activity_percent=' + encodeURIComponent(String(percent))
      + '&activity_errors=' + encodeURIComponent(String(errors))
      + '&activity_total=' + encodeURIComponent(String(total))
      + '&activity_id=' + encodeURIComponent(String(window.QUIZ_ACTIVITY_ID || ''))
      + '&activity_type=quiz';

    const ok = await persistScoreSilently(saveUrl);
    if (!ok) {
      navigateToReturn(saveUrl);
      return;
    }

    if (questionsWrap) questionsWrap.style.display = 'none';
    if (scoreTextEl) scoreTextEl.textContent = 'Score: ' + correct + ' / ' + total + ' (' + percent + '%)';
    if (attemptNoteEl) {
      const used = Math.min(3, Number(policy.attempts_used || 0) + 1);
      attemptNoteEl.textContent = 'Attempt used: ' + used + '/3';
    }
    if (completedScreen) completedScreen.classList.add('active');
  });
})();
</script>
<?php
$content = ob_get_clean();
render_activity_viewer($viewerTitle, '🧠', $content);
