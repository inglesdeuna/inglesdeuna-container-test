<?php
/**
 * quiz_from_scratch.php
 * Flow for creating an exam from zero using scoreable activity editors.
 */
session_start();
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../config/init_db.php';

$isAdmin   = !empty($_SESSION['admin_logged']);
$isTeacher = !empty($_SESSION['academic_logged']);
if (!$isAdmin && !$isTeacher) {
    header('Location: /lessons/lessons/admin/login.php');
    exit;
}

function h(string $v): string { return htmlspecialchars($v, ENT_QUOTES, 'UTF-8'); }
function rq(string $key, string $default = ''): string { return trim((string)($_REQUEST[$key] ?? $default)); }
function redirect_to(string $url): void { header('Location: ' . $url); exit; }

$unitId = rq('unit');
$examId = (int)rq('exam_id', '0');
$mode   = rq('mode', 'select');
$msg    = rq('msg');
$error  = '';

$scoreableTypes = [
    'multiple_choice'       => ['label' => 'Multiple Choice',        'editor' => '../multiple_choice/editor.php',       'skill' => 'grammar'],
    'fillblank'             => ['label' => 'Fill in the Blank',      'editor' => '../fillblank/editor.php',             'skill' => 'grammar'],
    'reading_comprehension' => ['label' => 'Reading Comprehension',  'editor' => '../reading_comprehension/editor.php', 'skill' => 'reading'],
    'writing_practice'      => ['label' => 'Writing Practice',       'editor' => '../writing_practice/editor.php',      'skill' => 'writing'],
    'dictation'             => ['label' => 'Dictation',              'editor' => '../dictation/editor.php',             'skill' => 'listening'],
    'match'                 => ['label' => 'Match',                  'editor' => '../match/editor.php',                 'skill' => 'grammar'],
    'drag_drop'             => ['label' => 'Drag & Drop',            'editor' => '../drag_drop/editor.php',             'skill' => 'grammar'],
    'drag_drop_kids'        => ['label' => 'Drag & Drop Kids',       'editor' => '../drag_drop_kids/editor.php',        'skill' => 'grammar'],
    'unscramble'            => ['label' => 'Unscramble',             'editor' => '../unscramble/editor.php',            'skill' => 'grammar'],
    'order_sentences'       => ['label' => 'Order the Sentences',    'editor' => '../order_sentences/editor.php',       'skill' => 'grammar'],
    'pronunciation'         => ['label' => 'Pronunciation',          'editor' => '../pronunciation/editor.php',         'skill' => 'listening'],
    'listen_order'          => ['label' => 'Listen Order',           'editor' => '../listen_order/editor.php',          'skill' => 'listening'],
    'question_answer'       => ['label' => 'Question Answer',        'editor' => '../question_answer/editor.php',      'skill' => 'reading'],
];

$unit = null;
if ($unitId !== '') {
    $s = $pdo->prepare('SELECT id, name, course_id, phase_id FROM units WHERE id=? LIMIT 1');
    $s->execute([$unitId]);
    $unit = $s->fetch(PDO::FETCH_ASSOC);
    if (!$unit) die('Unidad no encontrada.');
}

if ($examId > 0 && $unitId === '') {
    $s = $pdo->prepare('SELECT unit_id FROM eval_exams WHERE id=? LIMIT 1');
    $s->execute([$examId]);
    $unitId = (string)($s->fetchColumn() ?: '');
}

if ($unitId === '') die('Unidad no especificada.');

$backHub = '/lessons/lessons/activities/hub/index.php?unit=' . urlencode($unitId);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = rq('action');

    if ($action === 'create_exam') {
        $title = rq('title');
        $selected = $_POST['types'] ?? [];
        if (!is_array($selected)) $selected = [];
        $selected = array_values(array_filter($selected, fn($t) => isset($scoreableTypes[$t])));

        if ($title === '') $error = 'Escribe el nombre del examen.';
        elseif (!$selected) $error = 'Selecciona al menos un bloque evaluable.';
        else {
            $createdBy = $_SESSION['admin_username'] ?? $_SESSION['teacher_username'] ?? 'admin';
            $stmt = $pdo->prepare("INSERT INTO eval_exams
                (title, cefr_level, unit_id, time_limit_min, max_attempts, status, modalities, created_by, instructions)
                VALUES (?, NULL, ?, 50, 1, 'draft', ?, ?, ?)
                RETURNING id");
            $stmt->execute([
                $title,
                $unitId,
                json_encode(['online','printed'], JSON_UNESCAPED_UNICODE),
                $createdBy,
                'Examen creado desde cero con bloques de actividades evaluables.'
            ]);
            $examId = (int)$stmt->fetchColumn();

            $_SESSION['eval_builder_exam_for_unit'][$unitId] = $examId;

            foreach ($selected as $type) {
                $qty = max(1, min(9, (int)($_POST['qty'][$type] ?? 1)));
                for ($i = 0; $i < $qty; $i++) {
                    $pos = $pdo->prepare('SELECT COALESCE(MAX(position),0)+1 FROM activities WHERE unit_id=?');
                    $pos->execute([$unitId]);
                    $position = (int)$pos->fetchColumn();
                    $ins = $pdo->prepare('INSERT INTO activities(unit_id, type, data, position, created_at) VALUES (?,?,?,?,CURRENT_TIMESTAMP)');
                    $ins->execute([$unitId, $type, json_encode(['_exam_id' => $examId, '_exam_builder' => true], JSON_UNESCAPED_UNICODE), $position]);
                }
            }

            redirect_to('quiz_from_scratch.php?mode=edit&unit=' . urlencode($unitId) . '&exam_id=' . $examId . '&msg=created');
        }
    }
}

$exam = null;
if ($examId > 0) {
    $s = $pdo->prepare('SELECT * FROM eval_exams WHERE id=? LIMIT 1');
    $s->execute([$examId]);
    $exam = $s->fetch(PDO::FETCH_ASSOC);
    if ($exam) $_SESSION['eval_builder_exam_for_unit'][$unitId] = $examId;
}

$activities = [];
if ($examId > 0) {
    $s = $pdo->prepare("SELECT id, type, data, position FROM activities WHERE unit_id=? ORDER BY position, id");
    $s->execute([$unitId]);
    foreach ($s->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $data = is_string($row['data'] ?? null) ? json_decode($row['data'], true) : ($row['data'] ?? []);
        if (isset($scoreableTypes[$row['type']]) && is_array($data) && (int)($data['_exam_id'] ?? 0) === $examId) {
            $activities[] = $row;
        }
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Crear examen desde cero</title>
<style>
@import url('https://fonts.googleapis.com/css2?family=Fredoka:wght@600;700&family=Nunito:wght@500;700;800&display=swap');
:root{--blue:#2563eb;--blue2:#1d4ed8;--orange:#F97316;--bg:#f7f8ff;--line:#dbeafe;--text:#0f172a;--muted:#64748b;--green:#16a34a;--red:#dc2626}*{box-sizing:border-box}body{margin:0;min-height:100vh;font-family:Nunito,Arial,sans-serif;color:var(--text);background:linear-gradient(135deg,#dff5ff 0%,#fff4db 50%,#f8d9e6 100%);padding:24px}.page{max-width:860px;margin:0 auto}.top{display:flex;justify-content:space-between;gap:12px;align-items:center;margin-bottom:14px}.btn{display:inline-flex;align-items:center;gap:8px;border:0;border-radius:12px;padding:10px 16px;font-weight:800;text-decoration:none;cursor:pointer;background:#fff;color:var(--blue2);box-shadow:0 8px 20px rgba(15,23,42,.10)}.btn.primary{background:var(--blue);color:#fff}.btn.orange{background:var(--orange);color:#fff}.btn.green{background:var(--green);color:#fff}.hero,.card{background:rgba(255,255,255,.92);border:1px solid rgba(255,255,255,.8);box-shadow:0 18px 40px rgba(15,23,42,.12);border-radius:24px;padding:22px;margin-bottom:14px}.hero{text-align:center}.hero h1{font-family:Fredoka,Arial,sans-serif;margin:0 0 8px;color:var(--blue2);font-size:30px}.hero p{margin:0;color:var(--muted);line-height:1.5}.msg{padding:12px 14px;border-radius:12px;background:#dcfce7;color:#166534;font-weight:800;margin-bottom:12px}.err{background:#fee2e2;color:#991b1b}.grid{display:grid;grid-template-columns:1fr 1fr;gap:10px}.type-row,.edit-row{display:flex;justify-content:space-between;gap:12px;align-items:center;border:1.5px solid var(--line);border-radius:16px;background:#f8fbff;padding:13px}.type-row label{display:flex;align-items:center;gap:10px;font-weight:900;color:#1e3a8a}.type-row input[type=checkbox]{width:18px;height:18px;accent-color:var(--blue)}.qty{width:58px;border:1.5px solid var(--line);border-radius:9px;padding:5px 8px;text-align:center;font-weight:800;color:var(--blue2)}.fg{margin-bottom:14px}.fg label{display:block;font-size:12px;color:var(--muted);font-weight:900;text-transform:uppercase;letter-spacing:.06em;margin-bottom:6px}.fg input{width:100%;padding:12px;border:1.5px solid var(--line);border-radius:12px;font-size:15px;font-family:Nunito,Arial,sans-serif}.edit-title{font-weight:900;color:#1e3a8a}.small{font-size:12px;color:var(--muted);font-weight:700}.actions{display:flex;gap:10px;flex-wrap:wrap;margin-top:14px}@media(max-width:720px){.grid{grid-template-columns:1fr}.top{align-items:flex-start;flex-direction:column}}
</style>
</head>
<body>
<div class="page">
  <div class="top">
    <a class="btn" href="<?= h($backHub) ?>">← Volver al Hub</a>
    <?php if ($examId > 0): ?><a class="btn" href="admin_eval.php?tab=editor&exam_id=<?= $examId ?>">Admin Evaluaciones</a><?php endif; ?>
  </div>

  <?php if ($msg === 'created'): ?><div class="msg">Examen creado. Edita cada bloque y luego revisa el preview online o impreso.</div><?php endif; ?>
  <?php if ($error): ?><div class="msg err"><?= h($error) ?></div><?php endif; ?>

  <?php if ($mode !== 'edit' || !$exam): ?>
  <section class="hero">
    <h1>Crear examen desde cero</h1>
    <p>Selecciona solamente los bloques evaluables. El sistema creara el examen y abrira los editores de esas actividades para construirlo.</p>
  </section>

  <form method="POST" class="card">
    <input type="hidden" name="action" value="create_exam">
    <input type="hidden" name="unit" value="<?= h($unitId) ?>">
    <div class="fg">
      <label>Nombre del examen</label>
      <input type="text" name="title" required value="<?= h(($unit['name'] ?? 'Unidad') . ' - Quiz') ?>">
    </div>
    <div class="grid">
      <?php foreach ($scoreableTypes as $type => $cfg): ?>
      <?php $editorPath = realpath(__DIR__ . '/' . $cfg['editor']); if (!$editorPath || !is_file($editorPath)) continue; ?>
      <div class="type-row">
        <label><input type="checkbox" name="types[]" value="<?= h($type) ?>"> <?= h($cfg['label']) ?></label>
        <input class="qty" type="number" name="qty[<?= h($type) ?>]" value="1" min="1" max="9">
      </div>
      <?php endforeach; ?>
    </div>
    <div class="actions">
      <button class="btn primary" type="submit">Crear examen y preparar editores →</button>
    </div>
  </form>
  <?php else: ?>
  <section class="hero">
    <h1><?= h($exam['title'] ?? 'Examen') ?></h1>
    <p>Examen draft creado desde cero. Abre cada editor, guarda la actividad y vuelve aqui para continuar. Luego revisa online e impreso desde Admin Evaluaciones.</p>
  </section>

  <div class="card">
    <h2 style="font-family:Fredoka,Arial,sans-serif;color:var(--blue2);margin-top:0">Bloques del examen</h2>
    <?php if (!$activities): ?>
      <p class="small">No hay bloques asociados a este examen.</p>
    <?php else: ?>
      <?php foreach ($activities as $i => $act): $cfg = $scoreableTypes[$act['type']] ?? null; if (!$cfg) continue; $url = $cfg['editor'] . '?unit=' . urlencode($unitId) . '&id=' . urlencode((string)$act['id']) . '&source=eval_builder'; ?>
      <div class="edit-row">
        <div>
          <div class="edit-title"><?= ($i + 1) ?>. <?= h($cfg['label']) ?></div>
          <div class="small">Actividad #<?= h((string)$act['id']) ?> · edita y guarda este bloque</div>
        </div>
        <a class="btn orange" href="<?= h($url) ?>">Abrir editor →</a>
      </div>
      <?php endforeach; ?>
    <?php endif; ?>
    <div class="actions">
      <a class="btn primary" href="eval_viewer.php?preview=1&exam_id=<?= $examId ?>" target="_blank">Preview online</a>
      <a class="btn" href="quiz_print.php?exam_id=<?= $examId ?>&mode=student" target="_blank">Preview impreso</a>
      <a class="btn green" href="admin_eval.php?tab=editor&exam_id=<?= $examId ?>">Ir a Admin Evaluaciones</a>
    </div>
  </div>
  <?php endif; ?>
</div>
</body>
</html>
