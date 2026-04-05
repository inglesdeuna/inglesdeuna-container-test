<?php
session_start();

$isTeacher = !empty($_SESSION['academic_logged']) || !empty($_SESSION['admin_logged']);
$isStudent  = !empty($_SESSION['student_logged']);
if (!$isTeacher && !$isStudent) { header('Location: login.php'); exit; }

$unitId       = trim((string) ($_GET['unit']       ?? ''));
$assignmentId = trim((string) ($_GET['assignment'] ?? ''));
if ($unitId === '') die('Unit not specified.');

$dbFile = __DIR__ . '/../config/db.php';
if (!file_exists($dbFile)) die('Database configuration not found.');
require $dbFile;
if (!isset($pdo) || !($pdo instanceof PDO)) die('Database connection unavailable.');

/* helpers */
function h(string $v): string { return htmlspecialchars($v, ENT_QUOTES, 'UTF-8'); }
function ws_col(PDO $pdo, string $t, string $c): bool {
    try { $pdo->query("SELECT {$c} FROM {$t} LIMIT 0"); return true; } catch (Throwable $e) { return false; }
}

/* unit data */
$unitName = ''; $unitLevel = ''; $unitModule = '';
try {
    $cols = ['name'];
    if (ws_col($pdo,'units','level'))       $cols[] = 'level';
    if (ws_col($pdo,'units','module_name')) $cols[] = 'module_name';
    $s = $pdo->prepare('SELECT ' . implode(',', $cols) . ' FROM units WHERE id=:id LIMIT 1');
    $s->execute(['id' => $unitId]);
    $row = $s->fetch(PDO::FETCH_ASSOC);
    if ($row) {
        $unitName   = trim((string) ($row['name']        ?? ''));
        $unitLevel  = trim((string) ($row['level']       ?? ''));
        $unitModule = trim((string) ($row['module_name'] ?? ''));
    }
} catch (Throwable $e) {}
if ($unitName === '') $unitName = 'Unit';

/* program name from assignment */
$programName = '';
if ($assignmentId !== '') {
    try {
        $s = $pdo->prepare('SELECT course_name, program_type FROM assignments WHERE id=:id LIMIT 1');
        $s->execute(['id' => $assignmentId]);
        $ar = $s->fetch(PDO::FETCH_ASSOC);
        if ($ar) {
            $cn = trim((string) ($ar['course_name']  ?? ''));
            $pt = trim((string) ($ar['program_type'] ?? ''));
            if ($cn !== '')           $programName = $cn;
            elseif ($pt === 'english') $programName = 'English Program';
            elseif ($pt !== '')        $programName = ucfirst($pt) . ' Program';
        }
    } catch (Throwable $e) {}
}
if ($programName === '') $programName = 'Technical English';

/* activities */
$activities = [];
try {
    $ob = ws_col($pdo,'activities','position')
        ? 'ORDER BY COALESCE(position,0) ASC, id ASC' : 'ORDER BY id ASC';
    $s = $pdo->prepare("SELECT id, type, data FROM activities WHERE unit_id=:uid AND type != 'flipbooks' {$ob}");
    $s->execute(['uid' => $unitId]);
    $activities = $s->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (Throwable $e) {}

/*  render helpers  */

function ws_decode($raw): array {
    if (!is_string($raw) || trim($raw) === '') return [];
    $d = json_decode($raw, true);
    return is_array($d) ? $d : [];
}

function ws_cfg(string $type): array {
    static $map = [
        'flashcards'          => ['label'=>'Vocabulary',          'c'=>'#2563eb'],
        'quiz'                => ['label'=>'Quiz',                'c'=>'#7c3aed'],
        'multiple_choice'     => ['label'=>'Multiple Choice',     'c'=>'#a855f7'],
        'drag_drop'           => ['label'=>'Fill in the Blanks',  'c'=>'#0891b2'],
        'writing_practice'    => ['label'=>'Writing Practice',    'c'=>'#059669'],
        'match'               => ['label'=>'Match the Pairs',     'c'=>'#ea580c'],
        'order_sentences'     => ['label'=>'Order the Sentences', 'c'=>'#e11d48'],
        'listen_order'        => ['label'=>'Listen & Order',      'c'=>'#4338ca'],
        'crossword'           => ['label'=>'Crossword',           'c'=>'#d97706'],
        'memory_cards'        => ['label'=>'Memory Cards',        'c'=>'#0ea5e9'],
        'hangman'             => ['label'=>'Word Challenge',      'c'=>'#64748b'],
        'video_comprehension' => ['label'=>'Video Activity',      'c'=>'#64748b'],
        'external'            => ['label'=>'External Resource',   'c'=>'#64748b'],
        'powerpoint'          => ['label'=>'Presentation',        'c'=>'#64748b'],
        'pronunciation'       => ['label'=>'Pronunciation',       'c'=>'#64748b'],
        'tracing'             => ['label'=>'Tracing Activity',    'c'=>'#64748b'],
        'dictation'           => ['label'=>'Dictation',           'c'=>'#64748b'],
    ];
    return $map[$type] ?? ['label' => ucwords(str_replace('_',' ',$type)), 'c' => '#64748b'];
}

function ws_head(int $n, string $type, string $title, string $instr, bool $isKey): string {
    $cfg   = ws_cfg($type);
    $color = $cfg['c'];
    $label = h($cfg['label']);
    $t     = trim($title);
    $i     = trim($instr);
    $out   = '<div class="ws-sec">';
    $out  .= '<div class="ws-sec-hd" style="border-left-color:'.$color.';background:linear-gradient(90deg,'.$color.'18 0%,transparent 100%)">';
    $out  .= '<div class="ws-badge" style="background:'.$color.'">'.$n.'</div>';
    $out  .= '<div class="ws-hd-meta">';
    $out  .= '<div class="ws-hd-type" style="color:'.$color.'">'.$label.'</div>';
    if ($t !== '' && $t !== $cfg['label']) $out .= '<div class="ws-hd-title">'.h($t).'</div>';
    $out  .= '</div>';
    if ($isKey) $out .= '<span class="ws-key-badge">Answer Key</span>';
    $out  .= '</div>';
    if ($i !== '') $out .= '<div class="ws-instr">'.h($i).'</div>';
    return $out;
}
function ws_foot(): string { return '</div>'; }

/* VOCABULARY */
function ws_flashcards(array $d, int $n, bool $k): string {
    $cards = is_array($d['cards'] ?? null) ? $d['cards'] : [];
    $out   = ws_head($n,'flashcards',$d['title']??'','Study the vocabulary list below.',$k);
    if (empty($cards)) return $out.'<p class="ws-empty">No items.</p>'.ws_foot();
    $out .= '<table class="ws-tbl"><thead><tr><th class="tc-hn">#</th><th>Word / Phrase</th><th class="tc-hw">Translation</th></tr></thead><tbody>';
    foreach ($cards as $i => $c) {
        $tx = trim((string)($c['text'] ?? ''));
        if ($tx === '') continue;
        $out .= '<tr class="'.($i%2===0?'tr-a':'tr-b').'"><td class="tc-n">'.($i+1).'</td><td class="tc-w">'.h($tx).'</td><td class="tc-bl">&nbsp;</td></tr>';
    }
    return $out.'</tbody></table>'.ws_foot();
}

/* QUIZ */
function ws_quiz(array $d, int $n, bool $k): string {
    $desc = trim((string)($d['description'] ?? ''));
    $qs   = is_array($d['questions'] ?? null) ? $d['questions'] : [];
    $ltrs = ['A','B','C','D'];
    $out  = ws_head($n,'quiz',$d['title']??'',$desc?:'Circle the correct answer.',$k);
    foreach ($qs as $qi => $q) {
        $qt = trim((string)($q['question'] ?? ''));
        $op = is_array($q['options'] ?? null) ? $q['options'] : [];
        $ck = (int)($q['correct'] ?? 0);
        $ex = trim((string)($q['explanation'] ?? ''));
        $out .= '<div class="ws-qb"><div class="ws-qt"><span class="ws-qn">'.($qi+1).'</span>'.h($qt).'</div><div class="ws-opts">';
        foreach ($op as $oi => $o) {
            $ot = trim((string)$o); if ($ot==='') continue;
            $is = $k && $oi===$ck;
            $out .= '<div class="ws-opt'.($is?' ws-ck':'').'"><span class="ws-ol">'.($ltrs[$oi]??chr(65+$oi)).'</span>'.h($ot).'</div>';
        }
        $out .= '</div>';
        if ($k && $ex !== '') $out .= '<div class="ws-expl">&#128161; '.h($ex).'</div>';
        $out .= '</div>';
    }
    return $out.ws_foot();
}

/* MULTIPLE CHOICE */
function ws_mc(array $d, int $n, bool $k): string {
    $qs   = is_array($d['questions'] ?? null) ? $d['questions'] : [];
    $ltrs = ['A','B','C'];
    $out  = ws_head($n,'multiple_choice',$d['title']??'','Choose the correct option.',$k);
    foreach ($qs as $qi => $q) {
        $qt  = trim((string)($q['question']     ?? ''));
        $qtp = $q['question_type'] ?? 'text';
        $op  = is_array($q['options'] ?? null) ? $q['options'] : [];
        $otp = $q['option_type'] ?? 'text';
        $ck  = (int)($q['correct'] ?? 0);
        $out .= '<div class="ws-qb"><div class="ws-qt"><span class="ws-qn">'.($qi+1).'</span>';
        $out .= ($qtp==='listen') ? '<em class="ws-audio">&#127911; Listen and choose.</em>' : h($qt);
        $out .= '</div><div class="ws-opts">';
        foreach ($op as $oi => $o) {
            $ot = trim((string)$o);
            $is = $k && $oi===$ck;
            $dp = ($otp==='image') ? '[image '.($oi+1).']' : $ot;
            $out .= '<div class="ws-opt'.($is?' ws-ck':'').'"><span class="ws-ol">'.($ltrs[$oi]??chr(65+$oi)).'</span>'.h($dp).'</div>';
        }
        $out .= '</div></div>';
    }
    return $out.ws_foot();
}

/* FILL IN THE BLANKS */
function ws_dragdrop(array $d, int $n, bool $k): string {
    $blocks   = is_array($d['blocks'] ?? null) ? $d['blocks'] : [];
    $allWords = [];
    foreach ($blocks as $b) {
        foreach ((array)($b['missing_words'] ?? []) as $w) {
            $w = trim((string)$w); if ($w !== '') $allWords[] = $w;
        }
    }
    $bank = array_values(array_unique($allWords)); shuffle($bank);
    $out  = ws_head($n,'drag_drop',$d['title']??'',$k?'Sentences with correct answers shown.':'Fill in the blanks using the word bank.',$k);
    if (!empty($bank) && !$k) {
        $out .= '<div class="ws-bank"><span class="ws-blbl">Word Bank:</span>';
        foreach ($bank as $w) $out .= '<span class="ws-chip">'.h($w).'</span>';
        $out .= '</div>';
    }
    foreach ($blocks as $bi => $bl) {
        $tx = trim((string)($bl['text'] ?? ''));
        $ms = is_array($bl['missing_words'] ?? null) ? $bl['missing_words'] : [];
        if ($tx === '') continue;
        $out .= '<div class="ws-fr"><span class="ws-fn">'.($bi+1).'.</span><span class="ws-fb">';
        if ($k) {
            $out .= '<span class="ws-fs">'.h($tx).'</span>';
        } else {
            $bl2 = $tx;
            foreach ($ms as $mw) {
                $mw = trim((string)$mw); if ($mw==='') continue;
                $bl2 = preg_replace('/'.preg_quote($mw,'/').'/',str_repeat('_',max(6,mb_strlen($mw,'UTF-8')+4)),$bl2,1);
            }
            $out .= h($bl2);
        }
        $out .= '</span></div>';
    }
    return $out.ws_foot();
}

/* WRITING PRACTICE */
function ws_writing(array $d, int $n, bool $k): string {
    $desc = trim((string)($d['description'] ?? ''));
    $qs   = is_array($d['questions'] ?? null) ? $d['questions'] : [];
    $out  = ws_head($n,'writing_practice',$d['title']??'',$desc?:'Write your answers in complete sentences.',$k);
    foreach ($qs as $qi => $q) {
        $qt = trim((string)($q['question']    ?? ''));
        $in = trim((string)($q['instruction'] ?? ''));
        $an = is_array($q['correct_answers'] ?? null) ? $q['correct_answers'] : [];
        $out .= '<div class="ws-wb"><div class="ws-qt"><span class="ws-qn">'.($qi+1).'</span>'.h($qt).'</div>';
        if ($in !== '') $out .= '<div class="ws-wi">'.h($in).'</div>';
        if ($k && !empty($an)) {
            $out .= '<div class="ws-ab">';
            foreach ($an as $a) { $a=trim((string)$a); if($a!=='') $out .= '<div class="ws-ma">&#10003; '.h($a).'</div>'; }
            $out .= '</div>';
        } else {
            $out .= '<div class="ws-lines">'.str_repeat('<div class="ws-line"></div>',4).'</div>';
        }
        $out .= '</div>';
    }
    return $out.ws_foot();
}

/* MATCH */
function ws_match(array $d, int $n, bool $k): string {
    $pairs = is_array($d['pairs'] ?? null) ? $d['pairs'] : [];
    $ltrs  = range('A','Z');
    $lefts = []; $rights = [];
    foreach ($pairs as $p) { $lefts[]=trim((string)($p['left_text']??'')); $rights[]=trim((string)($p['right_text']??'')); }
    $sh = $rights; if (!$k) shuffle($sh);
    $out  = ws_head($n,'match',$d['title']??'','Match Column A to Column B. Write the letter on the line.',$k);
    $out .= '<div class="ws-mcols">';
    /* col A */
    $out .= '<div class="ws-mcol"><div class="ws-chhd">Column A</div>';
    foreach ($lefts as $i => $it) $out .= '<div class="ws-mr"><span class="ws-mn">'.($i+1).'.</span><span class="ws-mbl"></span><span class="ws-mt">'.h($it).'</span></div>';
    $out .= '</div>';
    /* col B */
    $out .= '<div class="ws-mcol"><div class="ws-chhd">Column B</div>';
    foreach ($sh as $i => $it) $out .= '<div class="ws-mr"><span class="ws-ml">'.($ltrs[$i]??'?').'.</span><span class="ws-mt">'.h($it).'</span></div>';
    $out .= '</div></div>';
    return $out.ws_foot();
}

/* ORDER SENTENCES */
function ws_order(array $d, int $n, bool $k): string {
    $in = trim((string)($d['instructions'] ?? ''));
    $ss = is_array($d['sentences'] ?? null) ? $d['sentences'] : [];
    $sh = $ss; if (!$k) shuffle($sh);
    $out = ws_head($n,'order_sentences',$d['title']??'',$in?:'Number the sentences in the correct order (1, 2, 3)',$k);
    foreach ($sh as $s) { $tx=trim((string)($s['text']??'')); if($tx==='') continue; $out .= '<div class="ws-or"><span class="ws-ob"></span><span class="ws-ot">'.h($tx).'</span></div>'; }
    return $out.ws_foot();
}

/* LISTEN ORDER */
function ws_listenorder(array $d, int $n, bool $k): string {
    $bl = is_array($d['blocks'] ?? null) ? $d['blocks'] : [];
    $sh = $bl; if (!$k) shuffle($sh);
    $out = ws_head($n,'listen_order',$d['title']??'',' Listen and number the sentences in the correct order.',$k);
    foreach ($sh as $b) { $tx=trim((string)($b['sentence']??'')); if($tx==='') continue; $out .= '<div class="ws-or"><span class="ws-ob"></span><span class="ws-ot">'.h($tx).'</span></div>'; }
    return $out.ws_foot();
}

/* CROSSWORD */
function ws_crossword(array $d, int $n, bool $k): string {
    $words = is_array($d['words'] ?? null) ? $d['words'] : [];
    $out   = ws_head($n,'crossword',$d['title']??'Crossword','Use the clues to complete the crossword puzzle.',$k);
    $out  .= '<table class="ws-tbl"><thead><tr><th class="tc-hn">#</th><th>Clue</th><th>Answer</th></tr></thead><tbody>';
    foreach ($words as $i => $w) {
        $cl = trim((string)($w['clue']??$w['raw_clue']??''));
        $wo = trim((string)($w['word']??''));
        $le = mb_strlen($wo,'UTF-8');
        $out .= '<tr class="'.($i%2===0?'tr-a':'tr-b').'"><td class="tc-n">'.($i+1).'</td><td>'.h($cl).'</td>';
        $out .= '<td class="tc-bl">'.($k?'<b class="tc-ak">'.h($wo).'</b>':str_repeat('_ ',$le)).'</td></tr>';
    }
    return $out.'</tbody></table>'.ws_foot();
}

/* MEMORY CARDS */
function ws_memory(array $d, int $n, bool $k): string {
    $cards = is_array($d['cards']??null)?$d['cards']:(is_array($d['pairs']??null)?$d['pairs']:[]);
    $out   = ws_head($n,'memory_cards',$d['title']??'','Write the matching word for each card.',$k);
    $out  .= '<table class="ws-tbl"><thead><tr><th class="tc-hn">#</th><th>Card</th><th class="tc-hw">Match</th></tr></thead><tbody>';
    foreach ($cards as $i => $c) {
        $tx = trim((string)($c['text']??$c['word']??$c['front']??''));
        if ($tx==='') continue;
        $out .= '<tr class="'.($i%2===0?'tr-a':'tr-b').'"><td class="tc-n">'.($i+1).'</td><td class="tc-w">'.h($tx).'</td><td class="tc-bl">&nbsp;</td></tr>';
    }
    return $out.'</tbody></table>'.ws_foot();
}

/* PLACEHOLDER */
function ws_placeholder(string $type, int $n): string {
    static $msgs = [
        'video_comprehension'=>'This activity contains a video. Complete it in the app.',
        'external'           =>'This activity links to an external resource.',
        'powerpoint'         =>'This is a Canva / PowerPoint presentation.',
        'hangman'            =>'This is an interactive word challenge game.',
        'pronunciation'      =>'This is a speaking / pronunciation activity.',
        'tracing'            =>'This is a handwriting / tracing activity.',
        'dictation'          =>'This is a dictation listening activity.',
    ];
    $out  = ws_head($n,$type,'','',false);
    $out .= '<div class="ws-hold">'.h($msgs[$type]??'This activity is interactive and cannot be printed.').'</div>';
    return $out.ws_foot();
}

/*  build sections  */
$sections = []; $actN = 0;
foreach ($activities as $act) {
    $type = strtolower(trim((string)($act['type']??'')));
    $data = ws_decode($act['data']??null);
    $actN++;
    switch ($type) {
        case 'flashcards':       $html = ws_flashcards($data,$actN,$isTeacher);    break;
        case 'quiz':             $html = ws_quiz($data,$actN,$isTeacher);           break;
        case 'multiple_choice':  $html = ws_mc($data,$actN,$isTeacher);             break;
        case 'drag_drop':        $html = ws_dragdrop($data,$actN,$isTeacher);       break;
        case 'writing_practice': $html = ws_writing($data,$actN,$isTeacher);        break;
        case 'match':            $html = ws_match($data,$actN,$isTeacher);          break;
        case 'order_sentences':  $html = ws_order($data,$actN,$isTeacher);          break;
        case 'listen_order':     $html = ws_listenorder($data,$actN,$isTeacher);    break;
        case 'crossword':        $html = ws_crossword($data,$actN,$isTeacher);      break;
        case 'memory_cards':     $html = ws_memory($data,$actN,$isTeacher);         break;
        default:                 $html = ws_placeholder($type,$actN);               break;
    }
    $sections[] = ['type'=>$type,'html'=>$html];
}

$today  = date('F j, Y');
$isKey  = $isTeacher;
?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1.0">
<title><?= h($unitName) ?> &mdash; Worksheet</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Fredoka:wght@400;500;600;700&family=Nunito:wght@400;600;700;800&display=swap" rel="stylesheet">
<style>
/*  tokens  */
:root{
  --navy:#1a1a2e; --navy2:#16213e; --navy3:#0f3460;
  --orange:#f14902; --orange2:#d33d00;
  --purple:#7c3aed; --purpleL:#a855f7;
  --blue:#2563eb; --sky:#0ea5e9;
  --text:#0f172a; --text2:#1e293b; --text3:#475569; --text4:#94a3b8;
  --border:#e2e8f0; --border2:#cbd5e1;
  --bg:#f8fafc; --bg2:#f1f5f9; --white:#ffffff;
  --r:8px; --rL:14px;
}
/*  reset  */
*{box-sizing:border-box;margin:0;padding:0}
html{-webkit-print-color-adjust:exact;print-color-adjust:exact}
body{font-family:'Nunito','Segoe UI',Arial,sans-serif;font-size:14px;line-height:1.6;color:var(--text);background:#dde1e8}
/*  toolbar  */
.toolbar{position:sticky;top:0;z-index:100;background:var(--navy);display:flex;align-items:center;gap:12px;padding:10px 24px;box-shadow:0 2px 16px rgba(0,0,0,.32);-webkit-print-color-adjust:exact;print-color-adjust:exact}
.tb-brand{font-family:'Fredoka',sans-serif;font-size:18px;font-weight:600;color:rgba(255,255,255,.7);margin-right:auto}
.tb-unit{font-size:12px;font-weight:600;color:rgba(255,255,255,.45);max-width:280px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.tb-badge{font-size:10px;font-weight:800;padding:3px 10px;border-radius:20px;text-transform:uppercase;letter-spacing:.06em}
.b-ws{background:var(--orange);color:#fff} .b-key{background:#10b981;color:#fff}
.btn-print{background:linear-gradient(180deg,#f36022,var(--orange2));color:#fff;border:none;border-radius:8px;padding:8px 20px;font-size:13px;font-weight:700;font-family:inherit;cursor:pointer;text-decoration:none;display:inline-flex;align-items:center;gap:6px;transition:filter .15s,transform .15s}
.btn-print:hover{filter:brightness(1.1);transform:translateY(-1px)}
/*  document wrapper  */
.ws-doc{max-width:820px;margin:24px auto 60px;background:var(--white);box-shadow:0 12px 50px rgba(15,23,42,.2);border-radius:3px;overflow:hidden}
/*  cover  */
.ws-cover{position:relative;overflow:hidden;background:linear-gradient(148deg,var(--navy) 0%,var(--navy2) 52%,var(--navy3) 100%);display:flex;flex-direction:column;-webkit-print-color-adjust:exact;print-color-adjust:exact}
.cover-deco{position:absolute;inset:0;pointer-events:none;-webkit-print-color-adjust:exact;print-color-adjust:exact}
.cover-body{position:relative;z-index:2;padding:38px 48px 26px;display:flex;flex-direction:column;gap:14px}
.cover-brand{font-family:'Fredoka',sans-serif;font-size:13px;font-weight:500;color:rgba(255,255,255,.48);text-transform:uppercase;letter-spacing:.18em}
.cover-prog{font-size:13px;font-weight:600;color:rgba(255,255,255,.58);margin-top:2px}
.cover-main{display:flex;flex-direction:column;gap:10px;padding:20px 0 8px}
.cover-super{font-size:11px;font-weight:800;letter-spacing:.2em;text-transform:uppercase;color:var(--orange)}
.cover-title{font-family:'Fredoka',sans-serif;font-size:50px;font-weight:700;color:#fff;line-height:1.08;letter-spacing:-.01em}
.cover-sub{color:rgba(255,255,255,.48);font-size:13px;font-weight:600;margin-top:2px}
.cover-chips{display:flex;gap:8px;flex-wrap:wrap;margin-top:6px}
.chip{display:inline-flex;align-items:center;padding:4px 12px;border-radius:20px;font-size:11px;font-weight:800;text-transform:uppercase;letter-spacing:.06em}
.chip-ct{background:rgba(255,255,255,.13);color:rgba(255,255,255,.75)}
.chip-ws{background:var(--orange);color:#fff} .chip-key{background:#10b981;color:#fff}
.chip-dt{background:rgba(255,255,255,.08);color:rgba(255,255,255,.5)}
.cover-info{position:relative;z-index:2;background:rgba(255,255,255,.065);border-top:1px solid rgba(255,255,255,.1);padding:18px 48px;display:grid;grid-template-columns:1fr 160px 160px;gap:16px;-webkit-print-color-adjust:exact;print-color-adjust:exact}
.ci-field{display:flex;flex-direction:column;gap:5px}
.ci-label{font-size:10px;font-weight:800;text-transform:uppercase;letter-spacing:.13em;color:rgba(255,255,255,.42)}
.ci-line{border-bottom:1px solid rgba(255,255,255,.28);height:22px;padding-bottom:3px}
/*  page header  */
.ws-hdr{display:flex;align-items:center;gap:10px;background:var(--navy2);padding:9px 22px;-webkit-print-color-adjust:exact;print-color-adjust:exact}
.hdr-brand{font-family:'Fredoka',sans-serif;font-size:13px;font-weight:600;color:rgba(255,255,255,.62);white-space:nowrap}
.hdr-sep{width:1px;height:16px;background:rgba(255,255,255,.18)}
.hdr-unit{font-size:12px;font-weight:700;color:#fff;flex:1;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.hdr-fields{display:flex;gap:16px;flex-shrink:0}
.hdr-f{display:flex;align-items:center;gap:5px;font-size:10px;font-weight:700;color:rgba(255,255,255,.42);white-space:nowrap}
.hdr-f span{border-bottom:1px solid rgba(255,255,255,.22);display:inline-block;min-width:80px}
/*  body  */
.ws-body{padding:28px 40px}
/*  activity section  */
.ws-sec{margin-bottom:30px;break-inside:avoid}
.ws-sec:last-child{margin-bottom:0}
.ws-sec-hd{display:flex;align-items:center;gap:12px;padding:9px 16px 9px 12px;border-left:5px solid #ccc;border-radius:0 var(--r) var(--r) 0;margin-bottom:12px;-webkit-print-color-adjust:exact;print-color-adjust:exact}
.ws-badge{display:inline-flex;align-items:center;justify-content:center;width:32px;height:32px;min-width:32px;border-radius:50%;font-size:14px;font-weight:800;color:#fff;-webkit-print-color-adjust:exact;print-color-adjust:exact}
.ws-hd-meta{flex:1}
.ws-hd-type{font-size:10px;font-weight:800;text-transform:uppercase;letter-spacing:.12em}
.ws-hd-title{font-size:15px;font-weight:700;color:var(--text2);margin-top:2px}
.ws-key-badge{font-size:10px;font-weight:800;text-transform:uppercase;letter-spacing:.06em;background:#10b981;color:#fff;padding:3px 9px;border-radius:12px;-webkit-print-color-adjust:exact;print-color-adjust:exact}
.ws-instr{font-size:12px;font-style:italic;color:var(--text3);margin-bottom:12px;padding:0 4px}
/*  vocab / crossword / memory table  */
.ws-tbl{width:100%;border-collapse:collapse;font-size:13px;margin-top:4px}
.ws-tbl thead th{background:var(--bg2);text-align:left;padding:7px 12px;font-size:10px;font-weight:800;text-transform:uppercase;letter-spacing:.08em;color:var(--text3);border:1px solid var(--border2);-webkit-print-color-adjust:exact;print-color-adjust:exact}
.ws-tbl tbody td{padding:7px 12px;border:1px solid var(--border);vertical-align:middle}
.tr-a td{background:var(--bg);-webkit-print-color-adjust:exact;print-color-adjust:exact}
.tc-hn{width:36px;text-align:center} .tc-hw{width:180px}
.tc-n{text-align:center;color:var(--text4);font-size:12px} .tc-w{font-weight:700}
.tc-bl{color:var(--text4)} .tc-ak{color:var(--blue);font-family:monospace;font-size:13px;letter-spacing:.04em}
/*  question blocks  */
.ws-qb{margin-bottom:16px;break-inside:avoid}
.ws-qt{font-weight:600;font-size:14px;line-height:1.4;margin-bottom:8px;display:flex;align-items:flex-start;gap:8px}
.ws-qn{display:inline-flex;align-items:center;justify-content:center;min-width:22px;height:22px;border-radius:50%;background:var(--navy);color:#fff;font-size:11px;font-weight:800;flex-shrink:0;margin-top:1px;-webkit-print-color-adjust:exact;print-color-adjust:exact}
.ws-audio{color:#4338ca;font-style:italic;font-size:13px}
.ws-opts{display:grid;grid-template-columns:1fr 1fr;gap:5px 12px;padding-left:30px}
.ws-opt{display:flex;align-items:center;gap:7px;border:1px solid var(--border2);border-radius:var(--r);padding:5px 10px;font-size:13px;background:var(--white)}
.ws-ol{min-width:22px;height:22px;display:flex;align-items:center;justify-content:center;border-radius:50%;border:2px solid var(--border2);font-size:11px;font-weight:800;color:var(--text3);flex-shrink:0}
.ws-ck{background:#f0fdf9;border-color:#10b981;-webkit-print-color-adjust:exact;print-color-adjust:exact}
.ws-ck .ws-ol{background:#10b981;color:#fff;border-color:#10b981;-webkit-print-color-adjust:exact;print-color-adjust:exact}
.ws-expl{font-size:11px;color:#059669;background:#f0fdf4;border-left:3px solid #10b981;border-radius:0 6px 6px 0;padding:5px 10px;margin-top:6px;margin-left:30px;-webkit-print-color-adjust:exact;print-color-adjust:exact}
/*  fill in the blanks  */
.ws-bank{display:flex;flex-wrap:wrap;align-items:center;gap:6px;padding:10px 14px;background:#f0f9ff;border:1px dashed #bae6fd;border-radius:var(--r);margin-bottom:14px;-webkit-print-color-adjust:exact;print-color-adjust:exact}
.ws-blbl{font-size:10px;font-weight:800;text-transform:uppercase;letter-spacing:.1em;color:#0369a1;margin-right:4px}
.ws-chip{background:#fff;border:1px solid #bae6fd;border-radius:20px;padding:2px 10px;font-size:12px;font-weight:600;color:var(--text2)}
.ws-fr{display:flex;align-items:baseline;gap:8px;margin-bottom:10px;font-size:14px;line-height:1.8;break-inside:avoid}
.ws-fn{font-size:12px;font-weight:800;color:var(--text3);min-width:20px}
.ws-fs{color:var(--blue);font-weight:600} .ws-fb{flex:1}
/*  writing  */
.ws-wb{margin-bottom:18px;break-inside:avoid}
.ws-wi{font-size:12px;color:var(--text3);font-style:italic;margin:4px 0 6px 30px}
.ws-lines{display:flex;flex-direction:column;gap:6px}
.ws-line{border-bottom:1px solid var(--border2);height:30px;width:100%}
.ws-ab{background:#f0fdf4;border:1px solid #86efac;border-radius:var(--r);padding:8px 12px;margin-left:30px;-webkit-print-color-adjust:exact;print-color-adjust:exact}
.ws-ma{font-size:13px;color:#166534;font-weight:600;margin-bottom:4px}
.ws-ma:last-child{margin-bottom:0}
/*  match  */
.ws-mcols{display:grid;grid-template-columns:1fr 1fr;gap:20px}
.ws-mcol{}
.ws-chhd{font-size:11px;font-weight:800;text-transform:uppercase;letter-spacing:.08em;color:var(--text3);border-bottom:2px solid var(--border2);padding-bottom:7px;margin-bottom:8px}
.ws-mr{display:flex;align-items:center;gap:8px;padding:7px 6px;border-bottom:1px solid var(--border);font-size:13px}
.ws-mn,.ws-ml{font-size:12px;font-weight:800;color:var(--text3);min-width:22px}
.ws-mbl{display:inline-block;min-width:32px;border-bottom:1px solid var(--text)} .ws-mt{flex:1;line-height:1.4}
/*  order  */
.ws-or{display:flex;align-items:center;gap:10px;padding:8px 4px;border-bottom:1px solid var(--border);font-size:14px;break-inside:avoid}
.ws-ob{width:34px;height:24px;min-width:34px;border:1px solid var(--border2);border-radius:4px;background:var(--bg);-webkit-print-color-adjust:exact;print-color-adjust:exact}
.ws-ot{flex:1;line-height:1.4}
/*  placeholder  */
.ws-hold{text-align:center;padding:20px;background:var(--bg);border:1px dashed var(--border2);border-radius:var(--r);color:var(--text4);font-style:italic;font-size:13px}
.ws-empty{font-size:12px;color:var(--text4);font-style:italic;padding:8px 0}
/*  page divider (screen only)  */
.ws-pdiv{border:none;border-top:2px dashed var(--border);margin:28px 0}
.ws-plbl{text-align:center;margin:-11px 0 28px}
.ws-plbl span{background:var(--white);padding:0 14px;font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.14em;color:var(--border2)}
/*  print  */
@media print{
  @page{size:letter;margin:14mm 18mm}
  body{background:#fff;font-size:12px}
  .toolbar,.ws-pdiv,.ws-plbl{display:none!important}
  .ws-doc{box-shadow:none;border-radius:0;max-width:100%;margin:0}
  .ws-body{padding:16px 22px}
  .ws-sec{margin-bottom:18px;padding-bottom:12px}
  .ws-cover,.cover-deco,.cover-info,.ws-hdr{-webkit-print-color-adjust:exact;print-color-adjust:exact}
  .ws-sec-hd,.ws-badge,.ws-key-badge{-webkit-print-color-adjust:exact;print-color-adjust:exact}
  .ws-tbl thead th,.tr-a td{-webkit-print-color-adjust:exact;print-color-adjust:exact}
  .ws-ck,.ws-ck .ws-ol,.ws-expl{-webkit-print-color-adjust:exact;print-color-adjust:exact}
  .ws-bank,.ws-chip,.ws-ab{-webkit-print-color-adjust:exact;print-color-adjust:exact}
  .ws-ob,.chip,.tb-badge{-webkit-print-color-adjust:exact;print-color-adjust:exact}
  a{color:inherit;text-decoration:none}
}
</style>
</head>
<body>

<div class="toolbar">
  <span class="tb-brand">InglésDeUna</span>
  <span class="tb-unit"><?= h($unitName) ?></span>
  <span class="tb-badge <?= $isKey?'b-key':'b-ws' ?>"><?= $isKey?'Answer Key':'Worksheet' ?></span>
  <button class="btn-print" onclick="window.print()">&#128424; Print / Save as PDF</button>
</div>

<div class="ws-doc">

  <!-- COVER -->
  <div class="ws-cover">
    <svg class="cover-deco" viewBox="0 0 820 410" preserveAspectRatio="xMidYMid slice" xmlns="http://www.w3.org/2000/svg">
      <!-- diagonal bands -->
      <polygon points="0,410 820,410 820,240 0,310" fill="#f14902" opacity="0.18"/>
      <polygon points="0,410 820,410 820,330 0,385" fill="#7c3aed" opacity="0.13"/>
      <!-- glow circles -->
      <circle cx="-25" cy="-25" r="190" fill="#ffffff" opacity="0.022"/>
      <circle cx="790" cy="55" r="100" fill="#f14902" opacity="0.10"/>
      <circle cx="60"  cy="380" r="60"  fill="#7c3aed" opacity="0.09"/>
      <!-- dot matrix top-right -->
      <?php
      for ($dr = 0; $dr < 4; $dr++) {
          for ($dc = 0; $dc < 6; $dc++) {
              $cx = 650 + $dc * 22; $cy = 90 + $dr * 22;
              echo '<circle cx="'.$cx.'" cy="'.$cy.'" r="2.5" fill="#ffffff" opacity="0.07"/>';
          }
      }
      ?>
    </svg>

    <div class="cover-body">
      <div>
        <div class="cover-brand">InglésDeUna</div>
        <div class="cover-prog"><?= h($programName) ?></div>
      </div>
      <div class="cover-main">
        <div class="cover-super">Unit Worksheet</div>
        <div class="cover-title"><?= h($unitName) ?></div>
        <?php $sub = implode(' &middot; ', array_filter([h($unitLevel), h($unitModule)])); ?>
        <?php if ($sub !== ''): ?><div class="cover-sub"><?= $sub ?></div><?php endif; ?>
        <div class="cover-chips">
          <span class="chip chip-ct"><?= count($sections) ?> activit<?= count($sections)===1?'y':'ies' ?></span>
          <?php if ($isKey): ?>
            <span class="chip chip-key">&#10003; Answer Key</span>
          <?php else: ?>
            <span class="chip chip-ws">Worksheet</span>
          <?php endif; ?>
          <span class="chip chip-dt"><?= h($today) ?></span>
        </div>
      </div>
    </div>

    <div class="cover-info">
      <div class="ci-field"><div class="ci-label">Full Name</div><div class="ci-line"></div></div>
      <div class="ci-field"><div class="ci-label">Date</div><div class="ci-line"></div></div>
      <div class="ci-field"><div class="ci-label">Group / Level</div><div class="ci-line"></div></div>
    </div>
  </div><!-- /cover -->

  <!-- PAGE HEADER -->
  <div class="ws-hdr">
    <span class="hdr-brand">InglésDeUna</span>
    <div class="hdr-sep"></div>
    <span class="hdr-unit"><?= h($unitName) ?></span>
    <div class="hdr-fields">
      <div class="hdr-f">Name: <span style="min-width:110px"></span></div>
      <div class="hdr-f">Date: <span style="min-width:60px"></span></div>
    </div>
  </div>

  <!-- ACTIVITIES -->
  <div class="ws-body">
  <?php if (empty($sections)): ?>
    <p style="text-align:center;color:var(--text4);font-style:italic;padding:40px 0">No printable activities found for this unit.</p>
  <?php else: ?>
    <?php foreach ($sections as $idx => $sec): ?>
      <?php if ($idx > 0 && $idx % 3 === 0): ?>
        <hr class="ws-pdiv">
        <div class="ws-plbl"><span>&#8212; Page <?= floor($idx / 3) + 1 ?> &#8212;</span></div>
      <?php endif; ?>
      <?= $sec['html'] ?>
    <?php endforeach; ?>
  <?php endif; ?>
  </div>

</div><!-- /ws-doc -->

<script>
(function(){
  var p = new URLSearchParams(window.location.search);
  if (p.get('autoprint') === '1') setTimeout(function(){ window.print(); }, 800);
})();
</script>
</body>
</html>