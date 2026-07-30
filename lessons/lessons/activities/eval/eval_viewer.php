<?php
declare(strict_types=1);

require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../config/init_db.php';
require_once __DIR__ . '/exam_question_selector.php';
require_once __DIR__ . '/../quiz/_quiz_lib.php';

if (session_status() === PHP_SESSION_NONE) session_start();

function ev_h($value): string { return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8'); }
function ev_redirect(array $params): void { header('Location: eval_viewer.php?' . http_build_query($params)); exit; }
function ev_is_image($value): bool {
    $value = trim((string)$value);
    if ($value === '') return false;
    if (str_starts_with($value, 'data:image/')) return true;
    $path = (string)(parse_url($value, PHP_URL_PATH) ?? '');
    return (bool)preg_match('/\.(png|jpe?g|gif|webp|svg)$/i', $path);
}
function ev_media($value, string $alt = '', string $class = 'quiz-media'): string {
    $value = trim((string)$value);
    if ($value === '') return '';
    return ev_is_image($value)
        ? '<img class="' . ev_h($class) . '" src="' . ev_h($value) . '" alt="' . ev_h($alt) . '" loading="eager">'
        : ev_h($value);
}
function ev_unit_questions(PDO $pdo, string $unitId, string $assignmentKey, int $attempt): array {
    $stmt = $pdo->prepare('SELECT id,type,unit_id,data FROM activities WHERE unit_id::text=:unit ORDER BY id ASC');
    $stmt->execute(['unit' => $unitId]);
    $all = [];
    foreach (($stmt->fetchAll(PDO::FETCH_ASSOC) ?: []) as $activity) {
        foreach (qz_normalize_activity($activity) as $question) {
            if (($question['type'] ?? '') !== 'pronunciation') $all[] = $question;
        }
    }
    return qz_build($all, $unitId, $assignmentKey, $attempt);
}
function ev_result_totals(array $quiz, array $answers): array {
    $stats = qz_answers_totals($quiz, $answers);
    $possible = max(0, (int)($stats['possible'] ?? 0));
    $earned = max(0.0, min((float)$possible, (float)($stats['earned'] ?? 0)));
    return [
        'correct' => (int)($stats['correct_questions'] ?? 0),
        'total' => count($quiz),
        'possible' => $possible,
        'earned' => $earned,
        'percent' => $possible > 0 ? (int)round(($earned / $possible) * 100) : 0,
    ];
}

$token = trim((string)($_GET['t'] ?? ''));
$step = trim((string)($_GET['step'] ?? 'welcome'));
$resultId = max(0, (int)($_GET['rid'] ?? 0));
$qIndex = max(0, (int)($_GET['q'] ?? 0));
$isPreview = isset($_GET['preview']) && $_GET['preview'] === '1';
$link = null;

if ($isPreview) {
    if (empty($_SESSION['admin_logged']) && empty($_SESSION['academic_logged'])) {
        http_response_code(403); exit('Acceso denegado.');
    }
    $examId = max(0, (int)($_GET['exam_id'] ?? 0));
    $stmt = $pdo->prepare("SELECT e.id AS exam_id,e.title AS exam_title,e.time_limit_min,e.max_attempts,e.instructions,e.cefr_level AS exam_cefr,e.status AS exam_status,e.modalities,e.unit_id AS exam_unit_id,'group' AS link_type,'' AS student_name,'' AS student_doc,'' AS student_phone,'' AS student_email,9999 AS max_uses,0 AS uses_count,NULL AS expires_at FROM eval_exams e WHERE e.id=? LIMIT 1");
    $stmt->execute([$examId]);
    $link = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    if (!$link) exit('Examen no encontrado.');
    $link['id'] = null;
    $token = 'PREVIEW_' . $examId;
} else {
    if ($token !== '') {
        $stmt = $pdo->prepare("SELECT l.*,e.title AS exam_title,e.time_limit_min,e.max_attempts,e.instructions,e.cefr_level AS exam_cefr,e.status AS exam_status,e.modalities,e.unit_id AS exam_unit_id FROM eval_links l JOIN eval_exams e ON e.id=l.exam_id WHERE l.token=? AND (l.expires_at IS NULL OR l.expires_at>NOW()) AND (l.uses_count<l.max_uses OR EXISTS (SELECT 1 FROM eval_results r WHERE r.link_id=l.id AND r.id=? AND r.status IN ('started','submitted'))) LIMIT 1");
        $stmt->execute([$token, $resultId]);
        $link = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }
}

if (!$link) {
    http_response_code(404);
    exit('<!doctype html><html><body style="font-family:Arial;text-align:center;padding:60px;background:#fff8e8"><h1>Link inválido o expirado</h1><p>Solicita un nuevo enlace a la institución.</p></body></html>');
}

$examId = (int)$link['exam_id'];
$isUnitExam = trim((string)($link['exam_unit_id'] ?? '')) !== '';
$errorMsg = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['start_exam'])) {
    $studentName = trim((string)($_POST['student_name'] ?? $link['student_name'] ?? ''));
    $studentDoc = trim((string)($_POST['student_doc'] ?? $link['student_doc'] ?? ''));
    $studentPhone = trim((string)($_POST['student_phone'] ?? $link['student_phone'] ?? ''));
    $studentEmail = trim((string)($_POST['student_email'] ?? $link['student_email'] ?? ''));
    if ($studentName === '') {
        $errorMsg = 'Por favor ingresa tu nombre.';
    } else {
        $hist = $pdo->prepare("SELECT id FROM eval_results WHERE exam_id=? AND student_doc=? AND status='submitted'");
        $hist->execute([$examId, $studentDoc]);
        $attempt = count($hist->fetchAll(PDO::FETCH_ASSOC)) + 1;
        if ($attempt > (int)($link['max_attempts'] ?? 1)) {
            $errorMsg = 'Ya alcanzaste el número máximo de intentos.';
        } else {
            if ($isUnitExam) {
                $questions = ev_unit_questions($pdo, (string)$link['exam_unit_id'], (string)($link['id'] ?? $examId), $attempt);
            } else {
                $config = ['exam_id'=>$examId,'unit_ids'=>[],'assignment_id'=>(string)($link['id'] ?? $examId),'total_questions'=>20,'quotas'=>DEFAULT_QUOTAS,'skills'=>array_keys(DEFAULT_QUOTAS)];
                $questions = select_exam_questions($pdo, $config, $studentDoc ?: $studentName, $attempt, []);
            }
            $selection = serialize_exam_selection($questions);
            $stmt = $pdo->prepare("INSERT INTO eval_results (exam_id,link_id,student_name,student_doc,student_phone,student_email,modality,selection_json,status,started_at) VALUES (?,?,?,?,?,?,'online',?,'started',CURRENT_TIMESTAMP) RETURNING id");
            $stmt->execute([$examId,$isPreview?null:(int)$link['id'],$studentName,$studentDoc,$studentPhone?:null,$studentEmail?:null,$selection]);
            $newResultId = (int)$stmt->fetchColumn();
            if (!$isPreview && !empty($link['id'])) $pdo->prepare('UPDATE eval_links SET uses_count=uses_count+1 WHERE id=?')->execute([$link['id']]);
            ev_redirect(['t'=>$token,'step'=>'quiz','rid'=>$newResultId,'q'=>0] + ($isPreview ? ['preview'=>1,'exam_id'=>$examId] : []));
        }
    }
}

$quiz = [];
$resultRow = null;
if ($resultId > 0) {
    $stmt = $pdo->prepare('SELECT * FROM eval_results WHERE id=? AND exam_id=? LIMIT 1');
    $stmt->execute([$resultId,$examId]);
    $resultRow = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    if ($resultRow) $quiz = load_exam_questions_from_selection($pdo, (string)($resultRow['selection_json'] ?? ''));
}

$sessionKey = 'eval_answers_' . $resultId;
if (!isset($_SESSION[$sessionKey]) || !is_array($_SESSION[$sessionKey])) $_SESSION[$sessionKey] = [];
$answers =& $_SESSION[$sessionKey];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['eval_answer']) && $resultRow && $resultRow['status'] === 'started') {
    $qIndex = max(0, min((int)($_POST['q_index'] ?? 0), max(0, count($quiz)-1)));
    $question = $quiz[$qIndex] ?? null;
    if ($question) {
        if (isset($_POST['skip'])) {
            $given = null;
        } elseif (in_array(($question['type'] ?? ''), ['match','drag_drop','drag_drop_kids','unscramble'], true)) {
            $given = isset($_POST['answer']) && is_array($_POST['answer']) ? $_POST['answer'] : [];
        } else {
            $given = trim((string)($_POST['answer'] ?? ''));
        }
        $score = qz_answer_score($question, $given);
        $answers[$qIndex] = ['answer'=>$given,'correct'=>(bool)$score['correct'],'earned'=>(float)$score['earned'],'possible'=>(int)$score['possible'],'skipped'=>isset($_POST['skip'])];
    }
    if (count($answers) >= count($quiz)) {
        ev_redirect(['t'=>$token,'step'=>'submit','rid'=>$resultId] + ($isPreview ? ['preview'=>1,'exam_id'=>$examId] : []));
    }
    ev_redirect(['t'=>$token,'step'=>'quiz','rid'=>$resultId,'q'=>min($qIndex+1,max(0,count($quiz)-1))] + ($isPreview ? ['preview'=>1,'exam_id'=>$examId] : []));
}

if ($step === 'submit' && $resultRow && $resultRow['status'] === 'started') {
    $totals = ev_result_totals($quiz, $answers);
    $log = [];
    foreach ($quiz as $i => $question) {
        $answer = $answers[$i] ?? ['answer'=>null,'correct'=>false,'earned'=>0,'possible'=>qz_answer_score($question,null)['possible']];
        $log[] = ['q'=>$i,'type'=>$question['type']??'','given'=>is_array($answer['answer'])?json_encode($answer['answer']):(string)($answer['answer']??''),'correct'=>qz_review_correct_text($question),'is_correct'=>(bool)$answer['correct'],'pts_earned'=>(float)$answer['earned'],'pts_max'=>(int)$answer['possible']];
    }
    $pdo->prepare("UPDATE eval_results SET score=?,max_score=?,pct=?,answers_json=?,status='submitted',submitted_at=CURRENT_TIMESTAMP WHERE id=?")->execute([$totals['earned'],$totals['possible'],$totals['percent'],json_encode($log),$resultId]);
    unset($_SESSION[$sessionKey]);
    ev_redirect(['t'=>$token,'step'=>'result','rid'=>$resultId] + ($isPreview ? ['preview'=>1,'exam_id'=>$examId] : []));
}

if ($step === 'result' && $resultRow) {
    $stmt = $pdo->prepare('SELECT * FROM eval_results WHERE id=? LIMIT 1');
    $stmt->execute([$resultId]);
    $resultRow = $stmt->fetch(PDO::FETCH_ASSOC) ?: $resultRow;
}

$currentQuestion = $quiz[$qIndex] ?? null;
$timeLimit = (int)($link['time_limit_min'] ?? 50);
?>
<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title><?=ev_h($link['exam_title'])?> — ONES</title><style>
:root{--purple:#7F77DD;--purple-dark:#534AB7;--purple-soft:#EEEDFE;--orange:#F97316;--line:#EDE9FA;--ink:#14113A;--muted:#8178B6;--bg:#F8F7FF}*{box-sizing:border-box}body{margin:0;background:var(--bg);font-family:Nunito,Arial,sans-serif;color:var(--ink)}.page{max-width:820px;margin:auto;padding:28px 18px 50px}.top,.card{background:#fff;border:1px solid var(--line);border-radius:24px;box-shadow:0 8px 28px rgba(127,119,221,.10)}.top{padding:16px 20px;margin-bottom:18px;display:flex;align-items:center;justify-content:space-between}.brand{font-weight:900;color:var(--purple)}.card{padding:28px}.kicker{display:inline-block;background:#FFF0E6;color:#C2580A;border-radius:999px;padding:6px 13px;font-size:12px;font-weight:900}.title{font-size:34px;color:var(--orange);margin:12px 0}.lead{color:var(--muted);font-weight:700;line-height:1.5}.chips{display:flex;gap:8px;flex-wrap:wrap;margin:18px 0}.chip{background:#F0EEF8;color:var(--purple);border-radius:999px;padding:7px 11px;font-size:12px;font-weight:900}.btn{display:inline-flex;align-items:center;justify-content:center;border:0;border-radius:12px;padding:12px 18px;background:var(--purple);color:#fff;font-weight:900;cursor:pointer;text-decoration:none}.btn.orange{background:var(--orange)}.btn.light{background:#fff;color:var(--purple);border:1px solid var(--line)}.w100{width:100%}.actions{display:flex;gap:10px;flex-wrap:wrap;margin-top:18px}.actions>*{flex:1;min-width:150px}.progress{height:9px;background:#EEEAF9;border-radius:999px;overflow:hidden;margin:10px 0 22px}.progress>div{height:100%;background:linear-gradient(90deg,var(--orange),var(--purple))}.question{font-size:23px;font-weight:900;line-height:1.4;margin:12px 0}.question-media{display:flex;justify-content:center;margin:12px 0 18px}.quiz-media{display:block;max-width:100%;max-height:240px;object-fit:contain;border-radius:16px}.option{display:flex;align-items:center;gap:12px;padding:14px;border:1px solid var(--line);border-radius:14px;margin:10px 0;font-weight:800;cursor:pointer}.option:has(input:checked){border-color:var(--purple);background:#F8F7FF}.option-image{display:block;max-width:100%;max-height:180px;object-fit:contain;margin:auto}.input,.select{width:100%;padding:14px;border:1px solid var(--line);border-radius:12px;font:700 16px Nunito,Arial;margin:8px 0}.match-row{display:grid;grid-template-columns:1fr 1fr;gap:10px;align-items:center;margin:8px 0}.listen-box{display:flex;align-items:center;gap:12px;background:#F8F7FF;border:1px solid var(--line);border-radius:16px;padding:14px;margin:12px 0}.listen-btn{border:0;border-radius:12px;padding:12px 18px;background:var(--purple);color:#fff;font-weight:900;cursor:pointer}.dd-shell{border:1px solid #F0EEF8;border-radius:28px;padding:20px;box-shadow:0 8px 30px rgba(127,119,221,.10)}.dd-instruction{font-size:18px;font-weight:800;line-height:2.15;text-align:center;color:var(--purple-dark);background:var(--purple-soft);border-radius:16px;padding:18px;margin-bottom:16px}.dd-slot{display:inline-flex;align-items:center;justify-content:center;min-width:96px;height:38px;padding:0 10px;margin:0 3px;border:2px dashed #d8d3f5;border-radius:10px;background:#fff;color:var(--muted);font-weight:900}.dd-slot.filled{border-style:solid;background:var(--purple-soft);color:var(--purple-dark)}.dd-bank{display:flex;flex-wrap:wrap;justify-content:center;gap:14px;min-height:58px}.dd-chip{padding:12px 22px;border-radius:14px;background:var(--purple-soft);border:2px solid #AFA9EC;color:var(--purple-dark);font-size:17px;font-weight:900;cursor:grab}.dd-chip.selected{background:var(--purple);color:#fff}.dd-chip.used{display:none}.dd-hidden{display:none}.result{font-size:58px;font-weight:900;color:var(--orange);text-align:center}.error{background:#fff1f1;color:#991b1b;border:1px solid #fecaca;border-radius:12px;padding:10px 14px;font-weight:800;margin-bottom:16px}@media(max-width:650px){.match-row{grid-template-columns:1fr}.actions{flex-direction:column}.actions>*{width:100%}}
</style></head><body><div class="page"><div class="top"><div class="brand">ONES · Unit Exam</div><div><?=ev_h($link['exam_title'])?></div></div>
<?php if($step==='welcome'):?><section class="card"><span class="kicker">UNIT EXAM</span><h1 class="title"><?=ev_h($link['exam_title'])?></h1><p class="lead">Complete your information to begin.</p><div class="chips"><span class="chip"><?=$timeLimit?> minutes</span><span class="chip">Same unit activities</span><span class="chip">Results at the end</span></div><?php if($errorMsg):?><div class="error"><?=ev_h($errorMsg)?></div><?php endif;?><form method="post"><input type="hidden" name="start_exam" value="1"><input class="input" name="student_name" required placeholder="Full name" value="<?=ev_h($link['student_name']??'')?>"><input class="input" name="student_doc" placeholder="Document / ID" value="<?=ev_h($link['student_doc']??'')?>"><input class="input" name="student_phone" placeholder="Phone" value="<?=ev_h($link['student_phone']??'')?>"><input class="input" type="email" name="student_email" placeholder="Email" value="<?=ev_h($link['student_email']??'')?>"><button class="btn orange w100" type="submit">Start exam</button></form></section>
<?php elseif($step==='quiz'&&$currentQuestion):?><section class="card"><span class="kicker">QUESTION <?=$qIndex+1?> OF <?=count($quiz)?></span><div class="progress"><div style="width:<?=(int)round((($qIndex+1)/max(1,count($quiz)))*100)?>%"></div></div><div class="question"><?=ev_h($currentQuestion['question']??$currentQuestion['text']??'Answer the question.')?></div><?php if(!empty($currentQuestion['image'])):?><div class="question-media"><?=ev_media($currentQuestion['image'],'Question image')?></div><?php endif;?><form method="post"><input type="hidden" name="eval_answer" value="1"><input type="hidden" name="q_index" value="<?=$qIndex?>">
<?php if(($currentQuestion['type']??'')==='multiple_choice'):foreach(($currentQuestion['options']??[])as$i=>$option):$isImg=ev_is_image($option);?><label class="option"><input type="radio" name="answer" value="<?=$i?>" required><span><?=chr(65+$i)?>.</span><span><?=$isImg?ev_media($option,'Option '.chr(65+$i),'option-image'):ev_h($option)?></span></label><?php endforeach;
elseif(($currentQuestion['type']??'')==='match'):$rights=array_column($currentQuestion['pairs']??[],'right');foreach(($currentQuestion['pairs']??[])as$i=>$pair):?><div class="match-row"><strong><?=ev_is_image($pair['left']??'')?ev_media($pair['left'],'Match item','option-image'):ev_h($pair['left']??'')?></strong><select class="select" name="answer[<?=$i?>]" required><option value="">Choose</option><?php foreach($rights as$right):?><option value="<?=ev_h($right)?>"><?=ev_h($right)?></option><?php endforeach;?></select></div><?php endforeach;
elseif(($currentQuestion['type']??'')==='drag_drop'):$words=array_values((array)($currentQuestion['correct_words']??[]));$instruction=(string)($currentQuestion['instruction']??implode(' ',array_fill(0,count($words),'___')));$parts=preg_split('/(___+)/',$instruction,-1,PREG_SPLIT_DELIM_CAPTURE);?><div class="dd-shell" data-dd><div class="dd-instruction"><?php $si=0;foreach($parts as$part):if(preg_match('/^___+$/',$part)):?><span class="dd-slot" data-slot="<?=$si++?>">___</span><?php else:?><?=ev_h($part)?><?php endif;endforeach;?></div><div class="dd-bank"><?php $shuffled=$words;shuffle($shuffled);foreach($shuffled as$ci=>$word):?><button type="button" class="dd-chip" draggable="true" data-word="<?=ev_h($word)?>" data-chip="<?=$ci?>"><?=ev_h($word)?></button><?php endforeach;?></div><?php foreach($words as$i=>$word):?><input class="dd-hidden" name="answer[<?=$i?>]" data-answer="<?=$i?>" required><?php endforeach;?></div>
<?php elseif(($currentQuestion['type']??'')==='dictation'):?><div class="listen-box"><button class="listen-btn" type="button" data-audio="<?=ev_h($currentQuestion['audio']??'')?>" data-text="<?=ev_h($currentQuestion['correct']??'')?>">🔊 Listen</button><span>Listen, then type exactly what you hear.</span></div><input class="input" name="answer" required placeholder="Type what you hear">
<?php elseif(($currentQuestion['type']??'')==='fill'):?><input class="input" name="answer" required placeholder="Type the missing word or phrase">
<?php else:?><textarea class="input" name="answer" rows="3" required placeholder="Type your answer"></textarea><?php endif;?><div class="actions"><button class="btn orange" type="submit">Next</button><button class="btn light" type="submit" name="skip" value="1" formnovalidate>Skip</button></div></form></section>
<?php elseif($step==='result'&&$resultRow):?><section class="card"><span class="kicker">EXAM COMPLETE</span><div class="result"><?=(int)round((float)($resultRow['pct']??0))?>%</div><h1 class="title" style="text-align:center"><?=ev_h($link['exam_title'])?></h1><div class="chips" style="justify-content:center"><span class="chip">Score <?=ev_h($resultRow['score']??0)?> / <?=ev_h($resultRow['max_score']??0)?></span></div></section>
<?php else:?><section class="card"><h1 class="title">Exam unavailable</h1><p class="lead">Return to the original link and start again.</p></section><?php endif;?></div><script>
(function(){var activeAudio=null;document.querySelectorAll('.listen-btn').forEach(function(button){button.addEventListener('click',function(){var url=(button.dataset.audio||'').trim(),text=(button.dataset.text||'').trim();if(activeAudio){activeAudio.pause();activeAudio=null;}if('speechSynthesis'in window)window.speechSynthesis.cancel();if(url){activeAudio=new Audio(url);activeAudio.play().catch(function(){if(text&&'speechSynthesis'in window){var u=new SpeechSynthesisUtterance(text);u.lang='en-US';u.rate=.85;window.speechSynthesis.speak(u);}});}else if(text&&'speechSynthesis'in window){var u=new SpeechSynthesisUtterance(text);u.lang='en-US';u.rate=.85;window.speechSynthesis.speak(u);}});});document.querySelectorAll('[data-dd]').forEach(function(root){var selected=null,chips=[].slice.call(root.querySelectorAll('.dd-chip')),slots=[].slice.call(root.querySelectorAll('.dd-slot'));function sync(){slots.forEach(function(slot,i){var input=root.querySelector('[data-answer="'+i+'"]');if(input)input.value=slot.dataset.value||'';});}function clear(slot){var id=slot.dataset.chip;if(id!==''){var chip=root.querySelector('[data-chip="'+id+'"]');if(chip)chip.classList.remove('used');}slot.dataset.value='';slot.dataset.chip='';slot.textContent='___';slot.classList.remove('filled');sync();}function place(chip,slot){if(slot.dataset.value)clear(slot);slot.dataset.value=chip.dataset.word;slot.dataset.chip=chip.dataset.chip;slot.textContent=chip.dataset.word;slot.classList.add('filled');chip.classList.add('used');chips.forEach(function(c){c.classList.remove('selected');});selected=null;sync();}chips.forEach(function(chip){chip.addEventListener('dragstart',function(e){e.dataTransfer.setData('text/plain',chip.dataset.chip);});chip.addEventListener('click',function(){chips.forEach(function(c){c.classList.remove('selected');});selected=chip;chip.classList.add('selected');});});slots.forEach(function(slot){slot.dataset.value='';slot.dataset.chip='';slot.addEventListener('dragover',function(e){e.preventDefault();});slot.addEventListener('drop',function(e){e.preventDefault();var chip=root.querySelector('[data-chip="'+e.dataTransfer.getData('text/plain')+'"]');if(chip)place(chip,slot);});slot.addEventListener('click',function(){if(selected)place(selected,slot);else if(slot.dataset.value)clear(slot);});});sync();});})();
</script></body></html>