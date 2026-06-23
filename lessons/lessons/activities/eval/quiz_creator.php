<?php
/**
 * quiz_creator.php
 * Create/edit evaluations from scratch.
 * Saves to eval_exams + eval_questions + eval_answers.
 */
session_start();

$isAdmin   = !empty($_SESSION['admin_logged']);
$isTeacher = !empty($_SESSION['academic_logged']);

if (!$isAdmin && !$isTeacher) {
    header('Location: /lessons/lessons/admin/login.php');
    exit;
}

require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../config/init_db.php';
if (!isset($pdo) || !($pdo instanceof PDO)) die('DB unavailable.');

function h(string $v): string { return htmlspecialchars($v, ENT_QUOTES, 'UTF-8'); }
function reqv(string $key, string $default = ''): string { return trim((string)($_REQUEST[$key] ?? $default)); }
function postv(string $key, string $default = ''): string { return trim((string)($_POST[$key] ?? $default)); }
function redirect_creator(string $unitId, int $examId, string $msg = ''): void {
    $params = [];
    if ($unitId !== '') $params['unit'] = $unitId;
    if ($examId > 0) $params['exam_id'] = (string)$examId;
    if ($msg !== '') $params['msg'] = $msg;
    header('Location: quiz_creator.php' . ($params ? '?' . http_build_query($params) : ''));
    exit;
}

$unitId = reqv('unit', reqv('unit_id', ''));
if ($unitId === '0') $unitId = '';
$examId = (int) reqv('exam_id', '0');
$msg = '';
$errorMsg = '';

$skillLabels = [
    'grammar'    => 'Grammar',
    'vocabulary' => 'Vocabulary',
    'listening'  => 'Listening',
    'reading'    => 'Reading',
    'writing'    => 'Writing',
    'speaking'   => 'Speaking',
];

$questionTypes = [
    'multiple_choice'       => 'MC',
    'fill_blank'            => 'Fill',
    'writing_practice'      => 'Writing',
    'dictation'             => 'Dictation',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = postv('action');

    if ($action === 'save_exam') {
        $title    = postv('title');
        $cefr     = postv('cefr_level');
        $uid      = postv('unit_id');
        if ($uid === '0') $uid = '';
        $time     = max(1, (int)postv('time_limit_min', '50'));
        $attempts = max(1, (int)postv('max_attempts', '1'));
        $status   = postv('status', 'draft');
        if (!in_array($status, ['draft', 'active', 'closed'], true)) $status = 'draft';
        $mods     = $_POST['modalities'] ?? ['online', 'printed'];
        if (!is_array($mods)) $mods = ['online', 'printed'];
        $mods     = array_values(array_intersect($mods, ['online', 'printed', 'registered']));
        if (!$mods) $mods = ['online'];

        if ($title === '') {
            $errorMsg = 'El nombre del examen es requerido.';
        } else {
            $modsJson = json_encode($mods, JSON_UNESCAPED_UNICODE);
            $createdBy = $_SESSION['admin_username'] ?? $_SESSION['teacher_username'] ?? 'admin';
            if ($examId > 0) {
                $stmt = $pdo->prepare("UPDATE eval_exams
                    SET title=?, cefr_level=?, unit_id=?, time_limit_min=?, max_attempts=?, status=?, modalities=?
                    WHERE id=?");
                $stmt->execute([$title, $cefr ?: null, $uid ?: null, $time, $attempts, $status, $modsJson, $examId]);
            } else {
                $stmt = $pdo->prepare("INSERT INTO eval_exams
                    (title, cefr_level, unit_id, time_limit_min, max_attempts, status, modalities, created_by)
                    VALUES (?,?,?,?,?,?,?,?) RETURNING id");
                $stmt->execute([$title, $cefr ?: null, $uid ?: null, $time, $attempts, $status, $modsJson, $createdBy]);
                $examId = (int)$stmt->fetchColumn();
            }
            redirect_creator($uid ?: $unitId, $examId, 'saved');
        }
    }

    if ($action === 'save_question') {
        $examId = (int)postv('exam_id', '0');
        $qId    = (int)postv('question_id', '0');
        $type   = postv('type', 'multiple_choice');
        if (!isset($questionTypes[$type])) $type = 'multiple_choice';
        $skill  = postv('skill', 'grammar');
        if (!isset($skillLabels[$skill])) $skill = 'grammar';
        $points = max(0.5, (float)postv('points', '1'));

        if ($examId <= 0) {
            $errorMsg = 'Primero guarda la configuracion del examen.';
        } else {
            if ($unitId === '') {
                $r = $pdo->prepare('SELECT unit_id FROM eval_exams WHERE id=? LIMIT 1');
                $r->execute([$examId]);
                $unitId = trim((string)$r->fetchColumn());
            }

            $text = '';
            $audio = '';
            $image = '';
            $answers = [];
            $data = [];

            if ($type === 'multiple_choice') {
                $text  = postv('mc_question_text');
                $audio = postv('mc_audio_url');
                $image = postv('mc_image_url');
                $answerTexts = (array)($_POST['mc_answer_text'] ?? []);
                $correctIdx = (string)($_POST['mc_correct'] ?? '');
                foreach ($answerTexts as $idx => $aText) {
                    $aText = trim((string)$aText);
                    if ($aText === '') continue;
                    $answers[] = ['text' => $aText, 'correct' => ((string)$idx === $correctIdx)];
                }
                if ($text === '') $errorMsg = 'Escribe la pregunta MC.';
                if (!$answers) $errorMsg = 'Agrega al menos una opcion.';
                if ($answers && !array_filter($answers, fn($a) => $a['correct'])) $errorMsg = 'Marca una opcion correcta.';
            } elseif ($type === 'fill_blank') {
                $text = postv('fill_question_text');
                $audio = postv('fill_audio_url');
                $ans = postv('fill_answer_text');
                if ($ans !== '') $answers[] = ['text' => $ans, 'correct' => true];
                if ($text === '') $errorMsg = 'Escribe la oracion del fill in the blank.';
            } elseif ($type === 'writing_practice') {
                $text = postv('writing_prompt');
                $image = postv('writing_image_url');
                $model = postv('writing_model');
                $data['lines'] = max(1, min(10, (int)postv('writing_lines', '3')));
                if ($model !== '') $answers[] = ['text' => $model, 'correct' => true];
                if ($text === '') $errorMsg = 'Escribe la instruccion de writing.';
            } elseif ($type === 'dictation') {
                $text = postv('dict_instruction', 'Listen and write what you hear.');
                $phrases = (array)($_POST['dict_phrase'] ?? []);
                foreach ($phrases as $phrase) {
                    $phrase = trim((string)$phrase);
                    if ($phrase !== '') $answers[] = ['text' => $phrase, 'correct' => true];
                }
                if (!$answers) $errorMsg = 'Agrega al menos una frase de dictado.';
            }

            if ($errorMsg === '') {
                if ($qId > 0) {
                    $stmt = $pdo->prepare("UPDATE eval_questions
                        SET type=?, skill=?, question_text=?, audio_url=?, image_url=?, points=?, data=?
                        WHERE id=? AND exam_id=?");
                    $stmt->execute([$type, $skill, $text, $audio ?: null, $image ?: null, $points, json_encode($data, JSON_UNESCAPED_UNICODE), $qId, $examId]);
                } else {
                    $posStmt = $pdo->prepare('SELECT COALESCE(MAX(position),0)+1 FROM eval_questions WHERE exam_id=?');
                    $posStmt->execute([$examId]);
                    $position = (int)$posStmt->fetchColumn();
                    $stmt = $pdo->prepare("INSERT INTO eval_questions
                        (exam_id, type, skill, question_text, audio_url, image_url, points, position, data)
                        VALUES (?,?,?,?,?,?,?,?,?) RETURNING id");
                    $stmt->execute([$examId, $type, $skill, $text, $audio ?: null, $image ?: null, $points, $position, json_encode($data, JSON_UNESCAPED_UNICODE)]);
                    $qId = (int)$stmt->fetchColumn();
                }

                $pdo->prepare('DELETE FROM eval_answers WHERE question_id=?')->execute([$qId]);
                $aStmt = $pdo->prepare('INSERT INTO eval_answers(question_id, answer_text, is_correct, order_index) VALUES (?,?,?,?)');
                foreach ($answers as $idx => $ans) {
                    $aStmt->execute([$qId, $ans['text'], $ans['correct'] ? 'true' : 'false', $idx]);
                }
                redirect_creator($unitId, $examId, 'question_saved');
            }
        }
    }

    if ($action === 'delete_question') {
        $qId = (int)postv('question_id', '0');
        $examId = (int)postv('exam_id', '0');
        if ($qId > 0 && $examId > 0) {
            $pdo->prepare('DELETE FROM eval_questions WHERE id=? AND exam_id=?')->execute([$qId, $examId]);
        }
        redirect_creator($unitId, $examId, 'deleted');
    }
}

if (($_GET['msg'] ?? '') === 'saved') $msg = 'Examen guardado. Ahora agrega las preguntas.';
if (($_GET['msg'] ?? '') === 'question_saved') $msg = 'Pregunta guardada.';
if (($_GET['msg'] ?? '') === 'deleted') $msg = 'Pregunta eliminada.';

$exam = null;
if ($examId > 0) {
    $s = $pdo->prepare('SELECT e.*, u.name AS unit_name FROM eval_exams e LEFT JOIN units u ON u.id=e.unit_id WHERE e.id=? LIMIT 1');
    $s->execute([$examId]);
    $exam = $s->fetch(PDO::FETCH_ASSOC);
    if ($exam && $unitId === '' && !empty($exam['unit_id'])) $unitId = (string)$exam['unit_id'];
}

$unitName = '';
if ($unitId !== '') {
    $s = $pdo->prepare('SELECT name FROM units WHERE id=? LIMIT 1');
    $s->execute([$unitId]);
    $unitName = (string)($s->fetchColumn() ?: '');
}

$examQuestions = [];
if ($examId > 0) {
    $s = $pdo->prepare("SELECT eq.*,
            COALESCE(json_agg(json_build_object('text', ea.answer_text, 'correct', ea.is_correct) ORDER BY ea.order_index)
                FILTER (WHERE ea.id IS NOT NULL), '[]'::json) AS answers_json
        FROM eval_questions eq
        LEFT JOIN eval_answers ea ON ea.question_id=eq.id
        WHERE eq.exam_id=?
        GROUP BY eq.id
        ORDER BY eq.position, eq.id");
    $s->execute([$examId]);
    $examQuestions = $s->fetchAll(PDO::FETCH_ASSOC);
    foreach ($examQuestions as &$q) {
        $q['answers'] = json_decode((string)($q['answers_json'] ?? '[]'), true) ?: [];
        $q['data_arr'] = json_decode((string)($q['data'] ?? '{}'), true) ?: [];
    }
    unset($q);
}

$mods = json_decode((string)($exam['modalities'] ?? '["online","printed"]'), true) ?: ['online', 'printed'];
$totalPts = array_sum(array_map(fn($q) => (float)($q['points'] ?? 0), $examQuestions));
$backUrl = $unitId !== '' ? '/lessons/lessons/activities/hub/index.php?unit=' . rawurlencode($unitId) : '/lessons/lessons/activities/eval/admin_eval.php';
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title><?= $exam ? h($exam['title']) : 'Crear quiz' ?> - ONES</title>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@tabler/icons-webfont@latest/tabler-icons.min.css">
<link href="https://fonts.googleapis.com/css2?family=Fredoka+One&family=Nunito:wght@400;600;700;800&display=swap" rel="stylesheet">
<style>
*{box-sizing:border-box;margin:0;padding:0}:root{--ora:#F97316;--pur:#7F77DD;--pur-l:#EEEDFE;--line:#EDE9FA;--bg:#F0EFF8;--ink:#1a1a2e;--muted:#7b719d;--r:14px}body{font-family:Nunito,Arial,sans-serif;background:var(--bg);color:var(--ink);min-height:100vh}.topbar{background:#fff;border-bottom:1.5px solid var(--line);height:58px;display:flex;align-items:center;padding:0 24px;gap:14px;position:sticky;top:0;z-index:50}.tb-logo{font-family:'Fredoka One';font-size:20px;color:var(--ora)}.tb-sep{width:1.5px;height:24px;background:var(--line)}.tb-title{font-size:14px;font-weight:800}.tb-unit{font-size:12px;font-weight:700;color:var(--muted);background:var(--pur-l);padding:3px 10px;border-radius:20px}.tb-right{margin-left:auto;display:flex;gap:8px}.page{max-width:900px;margin:0 auto;padding:24px 20px 70px;display:flex;flex-direction:column;gap:18px}.card{background:#fff;border:1.5px solid var(--line);border-radius:var(--r);overflow:hidden}.card-head{display:flex;align-items:center;justify-content:space-between;padding:14px 20px;border-bottom:1.5px solid var(--line)}.card-title{font-family:'Fredoka One';font-size:17px}.card-sub{font-size:12px;font-weight:700;color:var(--muted);margin-top:2px}.card-body{padding:19px}.fg{margin-bottom:14px}.fg label{display:block;font-size:10px;font-weight:900;color:var(--muted);text-transform:uppercase;letter-spacing:.1em;margin-bottom:5px}.fg input,.fg select,.fg textarea{width:100%;padding:9px 12px;border:1.5px solid var(--line);border-radius:9px;font-family:Nunito,Arial,sans-serif;font-size:13px;color:var(--ink);background:#fff;outline:none}.fg textarea{min-height:76px;resize:vertical}.row2{display:grid;grid-template-columns:1fr 1fr;gap:13px}.row3{display:grid;grid-template-columns:1fr 1fr 1fr;gap:13px}.btn{display:inline-flex;align-items:center;gap:6px;padding:8px 16px;border-radius:9px;font-size:12.5px;font-weight:800;cursor:pointer;border:1.5px solid var(--line);background:#fff;color:#374151;text-decoration:none;white-space:nowrap}.btn-sm{padding:5px 10px;font-size:11.5px}.btn-ora{background:var(--ora);border-color:var(--ora);color:#fff}.btn-pur{background:var(--pur);border-color:var(--pur);color:#fff}.btn-grn{background:#16A34A;border-color:#16A34A;color:#fff}.btn-red{background:#fff;border-color:#FCA5A5;color:#DC2626}.btn-ghost{background:transparent;border-color:transparent;color:var(--muted)}.msg{padding:11px 16px;border-radius:9px;font-size:13px;font-weight:800;background:#D1FAE5;color:#065F46;border:1.5px solid #A7F3D0}.msg.err{background:#FEE2E2;color:#B91C1C;border-color:#FCA5A5}.mod-row{display:flex;gap:20px;flex-wrap:wrap}.mod-label{display:inline-flex;align-items:center;gap:7px;font-size:13px;font-weight:700;color:#374151}.type-grid{display:grid;grid-template-columns:repeat(4,1fr);gap:8px;margin-bottom:18px}.qtype-card{border:2px solid var(--line);border-radius:10px;padding:12px 8px;cursor:pointer;text-align:center;background:#fff;user-select:none}.qtype-card.active{border-color:var(--pur);background:var(--pur-l)}.qtype-card i{font-size:22px;color:var(--muted);display:block;margin-bottom:6px}.qtype-card .ql{font-size:10px;font-weight:900;color:#6B7280;text-transform:uppercase;letter-spacing:.05em}.qtype-fields{display:none}.qtype-fields.active{display:block}.mc-row{display:flex;align-items:center;gap:8px;padding:8px 10px;border:1.5px solid var(--line);border-radius:9px;background:#FAFAFE;margin-bottom:6px}.mc-row.correct-row{border-color:#10B981;background:#F0FDF4}.mc-letter{width:26px;height:26px;border-radius:7px;background:var(--pur-l);color:#534AB7;font-size:11px;font-weight:900;display:flex;align-items:center;justify-content:center;flex-shrink:0}.mc-text{flex:1;border:none;background:transparent;font-family:Nunito,Arial,sans-serif;font-size:13px;outline:none}.mc-mark{padding:3px 9px;border-radius:6px;border:1.5px solid var(--line);background:#fff;font-size:10px;font-weight:900;cursor:pointer;color:#6B7280}.correct-row .mc-mark,.correct-row .mc-letter{background:#10B981;border-color:#10B981;color:#fff}.mc-del{width:24px;height:24px;border-radius:6px;border:none;background:#FEE2E2;color:#DC2626;font-size:14px;cursor:pointer;line-height:1}table{width:100%;border-collapse:collapse;font-size:12.5px}th{font-size:10px;font-weight:900;color:var(--muted);text-transform:uppercase;letter-spacing:.09em;padding:9px 18px;text-align:left;border-bottom:1.5px solid var(--line);background:#FAFAFE}td{padding:11px 18px;border-bottom:1.5px solid var(--line);vertical-align:middle}tr:last-child td{border-bottom:none}.q-badge{display:inline-flex;align-items:center;padding:2px 8px;border-radius:5px;font-size:10px;font-weight:900;text-transform:uppercase;background:var(--pur-l);color:#534AB7}.modal-bg{display:none;position:fixed;inset:0;background:rgba(0,0,0,.45);z-index:200;overflow-y:auto;padding:20px}.modal{background:#fff;border-radius:16px;max-width:700px;margin:auto;padding:26px}.modal-head{display:flex;align-items:center;justify-content:space-between;margin-bottom:18px}.xbtn{background:none;border:none;font-size:24px;color:var(--muted);cursor:pointer;line-height:1}.hint{font-size:12px;color:var(--muted);font-weight:700;margin-top:5px}@media(max-width:760px){.row2,.row3,.type-grid{grid-template-columns:1fr}.topbar{padding:0 12px}.tb-right{display:none}}
</style>
</head>
<body>
<div class="topbar"><div class="tb-logo">ONES</div><div class="tb-sep"></div><div class="tb-title"><?= $exam ? 'Editando quiz' : 'Crear nuevo quiz' ?></div><?php if ($unitName): ?><div class="tb-unit"><i class="ti ti-book-2"></i> <?= h($unitName) ?></div><?php endif; ?><div class="tb-right"><?php if ($examId > 0): ?><a class="btn btn-sm btn-pur" href="eval_viewer.php?preview=1&exam_id=<?= $examId ?>" target="_blank"><i class="ti ti-player-play"></i>Preview</a><a class="btn btn-sm" href="quiz_print.php?exam_id=<?= $examId ?>&mode=student" target="_blank"><i class="ti ti-printer"></i>Imprimir</a><?php endif; ?><a class="btn btn-sm" href="<?= h($backUrl) ?>"><i class="ti ti-arrow-left"></i>Volver</a></div></div>
<div class="page">
<?php if ($msg): ?><div class="msg"><?= h($msg) ?></div><?php endif; ?><?php if ($errorMsg): ?><div class="msg err"><?= h($errorMsg) ?></div><?php endif; ?>
<div class="card"><div class="card-head"><div><div class="card-title"><?= $exam ? 'Configuracion del examen' : 'Paso 1 - Configurar el quiz' ?></div><div class="card-sub">Nombre, nivel, unidad y modalidades</div></div></div><div class="card-body"><form method="POST"><input type="hidden" name="action" value="save_exam"><input type="hidden" name="exam_id" value="<?= $examId ?>"><?php if ($unitId !== ''): ?><input type="hidden" name="unit_id" value="<?= h($unitId) ?>"><?php endif; ?><div class="row2"><div class="fg"><label>Nombre del quiz *</label><input type="text" name="title" required value="<?= h($exam['title'] ?? '') ?>" placeholder="Ej: Advance 3 Unit 6 - Final Quiz"></div><div class="fg"><label>Nivel MCER</label><select name="cefr_level"><option value="">Sin nivel</option><?php foreach (['A1','A2','B1','B2','C1','C2'] as $lvl): ?><option value="<?= $lvl ?>" <?= ($exam['cefr_level'] ?? '') === $lvl ? 'selected' : '' ?>><?= $lvl ?></option><?php endforeach; ?></select></div></div><?php if ($unitId === ''): ?><div class="fg"><label>Unidad asociada (opcional)</label><select name="unit_id"><option value="">- Sin unidad -</option><?php try {$uRows = $pdo->query("SELECT id, name FROM units ORDER BY name")->fetchAll(PDO::FETCH_ASSOC); foreach ($uRows as $u): ?><option value="<?= h((string)$u['id']) ?>" <?= ((string)($exam['unit_id'] ?? '')) === (string)$u['id'] ? 'selected' : '' ?>><?= h($u['name']) ?></option><?php endforeach; } catch (Throwable $e) {} ?></select></div><?php endif; ?><div class="row3"><div class="fg"><label>Tiempo (minutos)</label><input type="number" name="time_limit_min" value="<?= (int)($exam['time_limit_min'] ?? 50) ?>" min="1"></div><div class="fg"><label>Intentos maximos</label><input type="number" name="max_attempts" value="<?= (int)($exam['max_attempts'] ?? 1) ?>" min="1"></div><div class="fg"><label>Status</label><select name="status"><?php foreach (['draft'=>'Draft','active'=>'Activo','closed'=>'Cerrado'] as $sv=>$sl): ?><option value="<?= $sv ?>" <?= ($exam['status'] ?? 'draft') === $sv ? 'selected' : '' ?>><?= $sl ?></option><?php endforeach; ?></select></div></div><div class="fg"><label>Modalidades</label><div class="mod-row"><?php foreach (['online'=>'Online (sin usuario)','printed'=>'Impreso','registered'=>'Con usuario registrado'] as $mv=>$ml): ?><label class="mod-label"><input type="checkbox" name="modalities[]" value="<?= $mv ?>" <?= in_array($mv, $mods, true) ? 'checked' : '' ?>><?= $ml ?></label><?php endforeach; ?></div></div><button type="submit" class="btn btn-grn"><i class="ti ti-device-floppy"></i><?= $examId ? 'Actualizar configuracion' : 'Guardar y continuar' ?></button></form></div></div>
<div class="card"><div class="card-head"><div><div class="card-title">Paso 2 - Preguntas del quiz</div><div class="card-sub"><?= count($examQuestions) ?> preguntas - <?= number_format($totalPts,1) ?> pts total</div></div><?php if ($examId > 0): ?><button class="btn btn-ora" type="button" onclick="openQModal()"><i class="ti ti-plus"></i>Agregar pregunta</button><?php else: ?><span class="hint">Guarda la configuracion primero</span><?php endif; ?></div><table><thead><tr><th>#</th><th>Tipo</th><th>Skill</th><th>Pregunta</th><th>Pts</th><th>Acciones</th></tr></thead><tbody><?php if (!$examQuestions): ?><tr><td colspan="6" style="text-align:center;padding:28px 20px;color:var(--muted);font-weight:700"><?= $examId > 0 ? 'Sin preguntas todavia - haz clic en Agregar pregunta' : 'Guarda la configuracion del examen para comenzar' ?></td></tr><?php else: foreach ($examQuestions as $i => $q): ?><tr><td><?= $i + 1 ?></td><td><span class="q-badge"><?= h($questionTypes[$q['type']] ?? $q['type']) ?></span></td><td><?= h($q['skill'] ?? '') ?></td><td style="max-width:320px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap"><?= h(mb_substr((string)($q['question_text'] ?? ''), 0, 90, 'UTF-8')) ?></td><td><?= number_format((float)$q['points'],1) ?></td><td><div style="display:flex;gap:5px"><button type="button" class="btn btn-sm" onclick='openQModal(<?= json_encode($q, JSON_HEX_APOS|JSON_HEX_QUOT|JSON_UNESCAPED_UNICODE) ?>)'><i class="ti ti-edit"></i>Editar</button><form method="POST" onsubmit="return confirm('Eliminar esta pregunta?')"><input type="hidden" name="action" value="delete_question"><input type="hidden" name="unit_id" value="<?= h($unitId) ?>"><input type="hidden" name="exam_id" value="<?= $examId ?>"><input type="hidden" name="question_id" value="<?= (int)$q['id'] ?>"><button type="submit" class="btn btn-sm btn-red"><i class="ti ti-trash"></i></button></form></div></td></tr><?php endforeach; endif; ?></tbody></table><?php if ($examId > 0): ?><div style="padding:18px;display:flex;gap:8px;flex-wrap:wrap;border-top:1.5px solid var(--line)"><a class="btn btn-pur" href="eval_viewer.php?preview=1&exam_id=<?= $examId ?>" target="_blank"><i class="ti ti-player-play"></i>Preview online</a><a class="btn" href="quiz_print.php?exam_id=<?= $examId ?>&mode=student" target="_blank"><i class="ti ti-printer"></i>Ver impreso</a><a class="btn" href="quiz_print.php?exam_id=<?= $examId ?>&mode=key" target="_blank"><i class="ti ti-key"></i>Ver clave</a><a class="btn btn-ora" href="admin_eval.php?tab=links&exam_id=<?= $examId ?>"><i class="ti ti-send"></i>Enviar / Links</a></div><?php endif; ?></div>
</div>
<?php if ($examId > 0): ?><div id="q-modal-bg" class="modal-bg"><div class="modal"><div class="modal-head"><span style="font-family:'Fredoka One';font-size:18px" id="q-modal-title">Agregar pregunta</span><button type="button" onclick="closeQModal()" class="xbtn">x</button></div><form method="POST" id="q-form"><input type="hidden" name="action" value="save_question"><input type="hidden" name="exam_id" value="<?= $examId ?>"><input type="hidden" name="unit_id" value="<?= h($unitId) ?>"><input type="hidden" name="question_id" id="q-id" value="0"><input type="hidden" name="type" id="q-type-h" value="multiple_choice"><div class="type-grid"><?php foreach ($questionTypes as $qtV=>$qtL): ?><div class="qtype-card" data-type="<?= h($qtV) ?>" onclick="selType('<?= h($qtV) ?>')"><i class="ti ti-list-check"></i><div class="ql"><?= h($qtL) ?></div></div><?php endforeach; ?></div><div class="row2"><div class="fg"><label>Skill</label><select name="skill" id="q-skill"><?php foreach ($skillLabels as $sv=>$sl): ?><option value="<?= h($sv) ?>"><?= h($sl) ?></option><?php endforeach; ?></select></div><div class="fg"><label>Puntos</label><input type="number" name="points" id="q-pts" value="1" step="0.5" min="0.5"></div></div><div id="qf-multiple_choice" class="qtype-fields active"><div class="fg"><label>Pregunta MC</label><textarea name="mc_question_text" id="mc-question" placeholder="Cual es la respuesta correcta?"></textarea></div><div class="row2"><div class="fg"><label>URL audio</label><input type="url" name="mc_audio_url" id="mc-audio"></div><div class="fg"><label>URL imagen</label><input type="url" name="mc_image_url" id="mc-image"></div></div><div class="fg"><label>Opciones - marca la correcta</label><div id="mc-container"></div><button type="button" onclick="addMC()" class="btn btn-ghost btn-sm"><i class="ti ti-plus"></i>Opcion</button></div></div><div id="qf-fill_blank" class="qtype-fields"><div class="fg"><label>Oracion con ___</label><textarea name="fill_question_text" id="fill-question"></textarea></div><div class="fg"><label>Respuestas correctas separadas por |</label><input type="text" name="fill_answer_text" id="fill-answer"></div><div class="fg"><label>URL audio</label><input type="url" name="fill_audio_url" id="fill-audio"></div></div><div id="qf-writing_practice" class="qtype-fields"><div class="fg"><label>Instruccion</label><textarea name="writing_prompt" id="writing-prompt"></textarea></div><div class="fg"><label>Respuesta modelo</label><input type="text" name="writing_model" id="writing-model"></div><div class="row2"><div class="fg"><label>Lineas</label><input type="number" name="writing_lines" id="writing-lines" value="3" min="1" max="10"></div><div class="fg"><label>Imagen</label><input type="url" name="writing_image_url" id="writing-image"></div></div></div><div id="qf-dictation" class="qtype-fields"><div class="fg"><label>Instruccion</label><textarea name="dict_instruction" id="dict-instruction">Listen and write what you hear.</textarea></div><div class="fg"><label>Frases de dictado</label><div id="dict-container"></div><button type="button" class="btn btn-ghost btn-sm" onclick="addDict()"><i class="ti ti-plus"></i>Frase</button></div></div><div style="display:flex;gap:10px;margin-top:18px;padding-top:14px;border-top:1.5px solid var(--line)"><button type="submit" class="btn btn-grn"><i class="ti ti-device-floppy"></i>Guardar pregunta</button><button type="button" onclick="closeQModal()" class="btn">Cancelar</button></div></form></div></div><script>
const LETTERS=['A','B','C','D','E','F','G','H'];function esc(s){return String(s??'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');}function selType(type){document.getElementById('q-type-h').value=type;document.querySelectorAll('.qtype-card').forEach(c=>c.classList.toggle('active',c.dataset.type===type));document.querySelectorAll('.qtype-fields').forEach(f=>f.classList.remove('active'));let p=document.getElementById('qf-'+type);if(p)p.classList.add('active');}function resetForm(){document.getElementById('q-form').reset();document.getElementById('q-id').value='0';document.getElementById('mc-container').innerHTML='';document.getElementById('dict-container').innerHTML='';addMC();addMC();addMC();addMC();addDict();addDict();selType('multiple_choice');}function openQModal(data){resetForm();if(data){document.getElementById('q-modal-title').textContent='Editar pregunta';document.getElementById('q-id').value=data.id||0;document.getElementById('q-pts').value=data.points||1;if(data.skill)document.getElementById('q-skill').value=data.skill;let type=data.type||'multiple_choice';selType(type);let answers=data.answers||[];if(type==='multiple_choice'){document.getElementById('mc-question').value=data.question_text||'';document.getElementById('mc-audio').value=data.audio_url||'';document.getElementById('mc-image').value=data.image_url||'';document.getElementById('mc-container').innerHTML='';answers.forEach((a,i)=>addMC(a.text,a.correct));if(!answers.length){addMC();addMC();addMC();addMC();}}else if(type==='fill_blank'){document.getElementById('fill-question').value=data.question_text||'';document.getElementById('fill-answer').value=answers.map(a=>a.text).join(' | ');document.getElementById('fill-audio').value=data.audio_url||'';}else if(type==='writing_practice'){document.getElementById('writing-prompt').value=data.question_text||'';document.getElementById('writing-model').value=answers[0]?.text||'';document.getElementById('writing-image').value=data.image_url||'';document.getElementById('writing-lines').value=(data.data_arr&&data.data_arr.lines)||3;}else if(type==='dictation'){document.getElementById('dict-instruction').value=data.question_text||'Listen and write what you hear.';document.getElementById('dict-container').innerHTML='';answers.forEach(a=>addDict(a.text));if(!answers.length){addDict();addDict();}}}else{document.getElementById('q-modal-title').textContent='Agregar pregunta';}document.getElementById('q-modal-bg').style.display='block';document.body.style.overflow='hidden';}function closeQModal(){document.getElementById('q-modal-bg').style.display='none';document.body.style.overflow='';}document.getElementById('q-modal-bg').addEventListener('click',e=>{if(e.target.id==='q-modal-bg')closeQModal();});function addMC(text='',correct=false){let c=document.getElementById('mc-container');let idx=c.children.length;let letter=LETTERS[idx]||String.fromCharCode(65+idx);let d=document.createElement('div');d.className='mc-row'+(correct?' correct-row':'');d.innerHTML='<div class="mc-letter">'+letter+'</div><input class="mc-text" type="text" name="mc_answer_text[]" value="'+esc(text)+'" placeholder="Opcion '+letter+'"><input type="radio" name="mc_correct" value="'+idx+'" '+(correct?'checked':'')+' style="display:none"><button type="button" class="mc-mark" onclick="markMC(this)">'+(correct?'Correcta':'Marcar')+'</button><button type="button" class="mc-del" onclick="this.parentElement.remove();reindexMC()">x</button>';c.appendChild(d);}function markMC(btn){document.querySelectorAll('#mc-container .mc-row').forEach((r,i)=>{r.classList.remove('correct-row');r.querySelector('input[type=radio]').checked=false;r.querySelector('.mc-mark').textContent='Marcar';});let row=btn.closest('.mc-row');row.classList.add('correct-row');row.querySelector('input[type=radio]').checked=true;btn.textContent='Correcta';}function reindexMC(){document.querySelectorAll('#mc-container .mc-row').forEach((r,i)=>{r.querySelector('.mc-letter').textContent=LETTERS[i]||String.fromCharCode(65+i);r.querySelector('input[type=radio]').value=i;});}function addDict(text=''){let c=document.getElementById('dict-container');let d=document.createElement('div');d.className='mc-row';d.innerHTML='<input class="mc-text" type="text" name="dict_phrase[]" value="'+esc(text)+'" placeholder="Frase de dictado"><button type="button" class="mc-del" onclick="this.parentElement.remove()">x</button>';c.appendChild(d);}selType('multiple_choice');
</script><?php endif; ?></body></html>
