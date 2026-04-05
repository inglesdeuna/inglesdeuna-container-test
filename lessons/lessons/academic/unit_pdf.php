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
        'flashcards'          => ['label'=>'Vocabulary',           'cls'=>'blue'],
        'quiz'                => ['label'=>'Quiz',                 'cls'=>'purple'],
        'multiple_choice'     => ['label'=>'Multiple Choice',      'cls'=>'purple'],
        'drag_drop'           => ['label'=>'Fill in the Blanks',   'cls'=>'cyan'],
        'writing_practice'    => ['label'=>'Writing Practice',     'cls'=>'green'],
        'match'               => ['label'=>'Match the Pairs',      'cls'=>'orange'],
        'order_sentences'     => ['label'=>'Order the Sentences',  'cls'=>'orange'],
        'listen_order'        => ['label'=>'Listen & Order',       'cls'=>'cyan'],
        'crossword'           => ['label'=>'Crossword',            'cls'=>'orange'],
        'memory_cards'        => ['label'=>'Memory Cards',         'cls'=>'blue'],
        'video_comprehension' => ['label'=>'Video Activity',       'cls'=>'blue'],
        'hangman'             => ['label'=>'Word Challenge',       'cls'=>'pink'],
        'external'            => ['label'=>'External Resource',    'cls'=>'pink'],
        'powerpoint'          => ['label'=>'Presentation',         'cls'=>'pink'],
        'pronunciation'       => ['label'=>'Pronunciation',        'cls'=>'pink'],
        'tracing'             => ['label'=>'Tracing Activity',     'cls'=>'pink'],
        'dictation'           => ['label'=>'Dictation',            'cls'=>'pink'],
    ];
    return $map[$type] ?? ['label' => ucwords(str_replace('_',' ',$type)), 'cls' => 'blue'];
}

function ws_head(int $n, string $type, string $title, string $instr, bool $isKey): string {
    $cfg = ws_cfg($type);
    $cls = $cfg['cls'];
    $lbl = h($cfg['label']);
    $t   = trim($title);
    $i   = trim($instr);
    $out = '<div class="ws-sec"><div class="section-head '.$cls.'"><div class="num">'.$n.'</div>';
    $out .= '<div><div class="section-kicker">'.$lbl;
    if ($isKey) $out .= '<span class="key-tag">Answer Key</span>';
    $out .= '</div>';
    if ($t !== '' && $t !== $cfg['label']) $out .= '<div class="section-title">'.h($t).'</div>';
    $out .= '</div></div><div class="card">';
    if ($i !== '') $out .= '<div class="instruction">'.h($i).'</div>';
    return $out;
}
function ws_foot(): string { return '</div></div>'; }

/* VOCABULARY */
function ws_flashcards(array $d, int $n, bool $k): string {
    $cards = is_array($d['cards'] ?? null) ? $d['cards'] : [];
    $out   = ws_head($n,'flashcards',$d['title']??'','Study the vocabulary list below.',$k);
    if (empty($cards)) return $out.'<p class="ws-empty">No items.</p>'.ws_foot();
    /* check if any card has an image */
    $hasImages = false;
    foreach ($cards as $c) { if (!empty($c['image'])) { $hasImages = true; break; } }
    if ($hasImages) {
        $out .= '<div class="fc-grid">';
        foreach ($cards as $c) {
            $tx  = trim((string)($c['text']  ?? ''));
            $img = trim((string)($c['image'] ?? ''));
            if ($tx === '' && $img === '') continue;
            $out .= '<div class="fc-card">';
            if ($img !== '') {
                $out .= '<div class="fc-img"><img src="'.h($img).'" alt="'.h($tx).'" loading="eager"></div>';
            } else {
                $out .= '<div class="fc-img" style="font-weight:600;color:#20324d;font-size:14px;padding:12px">'.h($tx).'</div>';
            }
            if ($tx !== '') $out .= '<div class="fc-label">'.h($tx).'</div>';
            if (!$k) $out .= '<div class="fc-blank"></div>';
            $out .= '</div>';
        }
        $out .= '</div>';
    } else {
        $out .= '<table class="ws-tbl"><thead><tr><th class="tc-hn">#</th><th>Word / Phrase</th><th class="tc-hw">Translation</th></tr></thead><tbody>';
        foreach ($cards as $i => $c) {
            $tx = trim((string)($c['text'] ?? ''));
            if ($tx === '') continue;
            $out .= '<tr class="'.($i%2===0?'tr-a':'tr-b').'"><td class="tc-n">'.($i+1).'</td><td class="tc-w">'.h($tx).'</td><td class="tc-bl">&nbsp;</td></tr>';
        }
        $out .= '</tbody></table>';
    }
    return $out.ws_foot();
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
    $out = ws_head($n,'listen_order',$d['title']??'','Listen and number the sentences in the correct order.',$k);
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
    $pairs = is_array($d['pairs']??null) ? $d['pairs'] : (is_array($d['cards']??null) ? $d['cards'] : []);
    $out   = ws_head($n,'memory_cards',$d['title']??'','Write the matching word or phrase for each card.',$k);
    if (empty($pairs)) return $out.'<p class="ws-empty">No items.</p>'.ws_foot();
    /* detect image sides */
    $hasImg = false;
    foreach ($pairs as $p) {
        $l = $p['left'] ?? null; $r = $p['right'] ?? null;
        if (($l && !empty($l['image'])) || ($r && !empty($r['image']))) { $hasImg = true; break; }
    }
    if ($hasImg) {
        $out .= '<div class="mc-grid">';
        foreach ($pairs as $p) {
            $l = $p['left'] ?? null; $r = $p['right'] ?? null;
            $imgUrl = ''; $lbl = '';
            if ($l && !empty($l['image'])) {
                $imgUrl = $l['image'];
                $lbl    = $r ? trim((string)($r['text'] ?? '')) : '';
            } elseif ($r && !empty($r['image'])) {
                $imgUrl = $r['image'];
                $lbl    = $l ? trim((string)($l['text'] ?? '')) : '';
            } else {
                $lbl = $l ? trim((string)($l['text'] ?? '')) : trim((string)($p['text'] ?? $p['word'] ?? $p['front'] ?? ''));
            }
            $out .= '<div class="mc-card">';
            if ($imgUrl !== '') {
                $out .= '<div class="mc-frame"><img src="'.h($imgUrl).'" alt="'.h($lbl).'" loading="eager"></div>';
                if ($k && $lbl !== '') {
                    $out .= '<div class="mc-meta" style="color:#166534;font-weight:600">&#10003; '.h($lbl).'</div>';
                } else {
                    $out .= '<div class="mc-meta">&nbsp;</div>';
                }
            } else {
                $out .= '<div class="mc-frame" style="font-weight:600;color:#20324d;font-size:14px">'.h($lbl).'</div>';
                $out .= '<div class="mc-meta">&nbsp;</div>';
            }
            $out .= '</div>';
        }
        $out .= '</div>';
    } else {
        $out .= '<table class="ws-tbl"><thead><tr><th class="tc-hn">#</th><th>Card</th><th class="tc-hw">Match</th></tr></thead><tbody>';
        foreach ($pairs as $i => $p) {
            $l  = $p['left'] ?? null;
            $tx = $l ? trim((string)($l['text'] ?? '')) : trim((string)($p['text'] ?? $p['word'] ?? $p['front'] ?? ''));
            if ($tx === '') continue;
            $out .= '<tr class="'.($i%2===0?'tr-a':'tr-b').'"><td class="tc-n">'.($i+1).'</td><td class="tc-w">'.h($tx).'</td><td class="tc-bl">&nbsp;</td></tr>';
        }
        $out .= '</tbody></table>';
    }
    return $out.ws_foot();
}

/* VIDEO COMPREHENSION */
function ws_video_comp(array $d, int $n, bool $k): string {
    $mode  = trim((string)($d['mode'] ?? 'quiz'));
    $qs    = is_array($d['questions'] ?? null) ? $d['questions'] : [];
    $instr = trim((string)($d['instructions'] ?? ''));
    $ltrs  = ['A','B','C','D'];
    if ($mode === 'video_only' || empty($qs)) {
        $out = ws_head($n,'video_comprehension',$d['title']??'','Watch the video in the app and complete the activity.',$k);
        $out .= '<div class="ws-hold">This activity requires video playback. Open it in the InglésDeUna app to complete it.</div>';
        return $out.ws_foot();
    }
    $out = ws_head($n,'video_comprehension',$d['title']??'',($instr !== '' ? $instr : 'Watch the video and answer each question.'),$k);
    foreach ($qs as $qi => $q) {
        $qt = trim((string)($q['question'] ?? ''));
        $op = is_array($q['options'] ?? null) ? $q['options'] : [];
        $ck = (int)($q['correct'] ?? 0);
        $ex = trim((string)($q['explanation'] ?? ''));
        $out .= '<div class="ws-qb"><div class="ws-qt"><span class="ws-qn">'.($qi+1).'</span>'.h($qt).'</div><div class="ws-opts">';
        foreach ($op as $oi => $o) {
            $ot = trim((string)$o); if ($ot === '') continue;
            $is = $k && $oi === $ck;
            $out .= '<div class="ws-opt'.($is?' ws-ck':'').'"><span class="ws-ol">'.($ltrs[$oi]??chr(65+$oi)).'</span>'.h($ot).'</div>';
        }
        $out .= '</div>';
        if ($k && $ex !== '') $out .= '<div class="ws-expl">&#128161; '.h($ex).'</div>';
        $out .= '</div>';
    }
    return $out.ws_foot();
}

/* DICTATION */
function ws_dictation(array $d, int $n, bool $k): string {
    $items = is_array($d['items'] ?? null) ? $d['items'] : [];
    $instr = 'Listen to each item and write what you hear.';
    $out   = ws_head($n,'dictation',$d['title']??'',$instr,$k);
    if (empty($items)) {
        $out .= '<div class="ws-hold">No items configured for this dictation.</div>';
        return $out.ws_foot();
    }
    foreach ($items as $i => $item) {
        $en  = trim((string)($item['en'] ?? ''));
        $img = trim((string)($item['img'] ?? ''));
        /* Calculate writing lines based on word count */
        $wc    = $en !== '' ? count(preg_split('/\s+/', $en)) : 4;
        $chars = $en !== '' ? mb_strlen($en) : 20;
        /* 1 line per ~8 words, min 1, max 4 */
        $lines = max(1, min(4, (int) ceil($wc / 8)));
        /* Short words (1-3 words) = 1 line; sentences = 2-3 lines */
        if ($wc <= 3)  { $lines = 1; }
        elseif ($wc <= 8)  { $lines = 2; }
        elseif ($wc <= 16) { $lines = 3; }
        else               { $lines = 4; }
        $out .= '<div class="dt-item">';
        $out .= '<div class="dt-num">'.($i+1).'.</div>';
        $out .= '<div class="dt-write">';
        if ($img !== '') {
            $out .= '<div class="dt-img"><img src="'.h($img).'" alt="item '.($i+1).'" loading="eager"></div>';
        }
        /* Answer key: show the text; student: show blank lines */
        if ($k && $en !== '') {
            $out .= '<div class="dt-answer">'.h($en).'</div>';
        }
        /* Always draw writing lines (guides for student + spacing for key) */
        $out .= '<div class="dt-lines" style="--dt-lines:'.$lines.'">';
        for ($l = 0; $l < $lines; $l++) {
            $out .= '<div class="dt-line"></div>';
        }
        $out .= '</div></div></div>';
    }
    return $out.ws_foot();
}

/* POWERPOINT */
function ws_powerpoint(array $d, int $n, bool $k): string {
    $title = trim((string)($d['title'] ?? ''));
    $out   = ws_head($n,'powerpoint',$title,'Watch the presentation carefully. Then summarize the main ideas below.',$k);
    $out  .= '<div class="ppt-summary">';
    $out  .= '<div class="ppt-summary-label">Summary</div>';
    $out  .= '<div class="ppt-lines">';
    for ($i = 0; $i < 8; $i++) {
        $out .= '<div class="ppt-line"></div>';
    }
    $out  .= '</div></div>';
    return $out.ws_foot();
}

/* PRONUNCIATION */
function ws_pronunciation(array $d, int $n, bool $k): string {
    $items = is_array($d['items'] ?? null) ? $d['items'] : [];
    $out   = ws_head($n,'pronunciation',$d['title']??'','Practice the pronunciation of each word. Write the English word, pronunciation, and Spanish translation.',$k);
    if (empty($items)) {
        $out .= '<div class="ws-hold">No items configured for this pronunciation activity.</div>';
        return $out.ws_foot();
    }
    $hasImg = false;
    foreach ($items as $it) { if (trim((string)($it['img'] ?? '')) !== '') { $hasImg = true; break; } }
    if ($hasImg) {
        /* Image grid: each card shows the image + 3 fill-in rows */
        $out .= '<div class="pr-grid">';
        foreach ($items as $it) {
            $img = trim((string)($it['img'] ?? ''));
            $en  = trim((string)($it['en']  ?? ''));
            $ph  = trim((string)($it['ph']  ?? ''));
            $es  = trim((string)($it['es']  ?? ''));
            $out .= '<div class="pr-card">';
            if ($img !== '') {
                $out .= '<div class="pr-img"><img src="'.h($img).'" alt="'.h($en).'" loading="eager"></div>';
            } else {
                $out .= '<div class="pr-img pr-img-txt">'.h($en).'</div>';
            }
            $out .= '<div class="pr-fields">';
            $out .= '<div class="pr-field"><span class="pr-lbl">English</span>'    .($k && $en!=='' ? '<div class="pr-ans">'.h($en).'</div>' : '<div class="pr-blank"></div>').'</div>';
            $out .= '<div class="pr-field"><span class="pr-lbl">Pronunciation</span>'.($k && $ph!=='' ? '<div class="pr-ans">'.h($ph).'</div>' : '<div class="pr-blank"></div>').'</div>';
            $out .= '<div class="pr-field"><span class="pr-lbl">Spanish</span>'    .($k && $es!=='' ? '<div class="pr-ans">'.h($es).'</div>' : '<div class="pr-blank"></div>').'</div>';
            $out .= '</div></div>';
        }
        $out .= '</div>';
    } else {
        /* No images: table with 3 write-in columns */
        $out .= '<table class="ws-tbl pr-tbl"><thead><tr><th>#</th><th>English</th><th>Pronunciation</th><th>Spanish</th></tr></thead><tbody>';
        foreach ($items as $i => $it) {
            $en = trim((string)($it['en'] ?? ''));
            $ph = trim((string)($it['ph'] ?? ''));
            $es = trim((string)($it['es'] ?? ''));
            $cls = $i % 2 === 0 ? 'tr-a' : '';
            $out .= '<tr class="'.$cls.'"><td class="tc-n">'.($i+1).'</td>';
            $out .= '<td>'.($k && $en!=='' ? '<span class="tc-ak">'.h($en).'</span>' : '&nbsp;').'</td>';
            $out .= '<td>'.($k && $ph!=='' ? h($ph) : '&nbsp;').'</td>';
            $out .= '<td>'.($k && $es!=='' ? h($es) : '&nbsp;').'</td></tr>';
        }
        $out .= '</tbody></table>';
    }
    return $out.ws_foot();
}

/* PLACEHOLDER */
function ws_placeholder(string $type, int $n): string {
    static $msgs = [
        'external'       => 'This activity links to an external resource. Open it in the app.',
        'hangman'        => 'Interactive word challenge. Complete it in the app.',
        'tracing'        => 'Handwriting and tracing activity. Complete it in the app.',
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
        case 'memory_cards':        $html = ws_memory($data,$actN,$isTeacher);       break;
        case 'video_comprehension': $html = ws_video_comp($data,$actN,$isTeacher);   break;
        case 'dictation':           $html = ws_dictation($data,$actN,$isTeacher);    break;
        case 'powerpoint':           $html = ws_powerpoint($data,$actN,$isTeacher);    break;
        case 'pronunciation':        $html = ws_pronunciation($data,$actN,$isTeacher);  break;
        default:                    $html = ws_placeholder($type,$actN);              break;
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
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&family=Poppins:wght@400;500;600;700;800&family=Nunito:wght@400;600;700;800&display=swap" rel="stylesheet">
<style>
/* ── Design tokens ─────────────────────────────────────────── */
:root{
  --navy:#20324d; --text:#223046; --muted:#66758a; --line:#d9e4ef;
  --paper:#f7fbff; --white:#ffffff; --bg:#f8fcff;
  --blue:#dfeeff;    --blue-strong:#3d7cf4;
  --cyan:#dff6ff;    --cyan-strong:#1e9ecb;
  --green:#e6f7ef;   --green-strong:#13a06f;
  --orange:#fff0e3;  --orange-strong:#ef7c2b;
  --purple:#f1e8ff;  --purple-strong:#8b5cf6;
  --pink:#ffe7ef;    --pink-strong:#e64b7b;
  --answer:#10b981;  --answer-bg:#f0fdf4; --answer-border:#86efac;
  --border:#dce7f0;  --border2:#cfdceb;
  --shadow:0 10px 30px rgba(31,61,111,.08);
  --r:10px; --rL:16px;
}
/* ── Reset ── */
*{box-sizing:border-box;margin:0;padding:0}
html{-webkit-print-color-adjust:exact;print-color-adjust:exact}
body{font-family:'Inter','Poppins','Segoe UI',Arial,sans-serif;font-size:14px;line-height:1.6;color:var(--text);background:radial-gradient(circle at top left,#eef6ff 0,transparent 28%),linear-gradient(180deg,#f5faff 0%,#eef6ff 100%);padding:0}
/* ── Toolbar ── */
.toolbar{position:sticky;top:0;z-index:100;background:var(--navy);display:flex;align-items:center;gap:12px;padding:10px 24px;box-shadow:0 2px 16px rgba(31,61,111,.3);-webkit-print-color-adjust:exact;print-color-adjust:exact}
.tb-brand{font-size:16px;font-weight:800;color:rgba(255,255,255,.80);margin-right:auto;letter-spacing:-.02em}
.tb-unit{font-size:12px;font-weight:500;color:rgba(255,255,255,.5);max-width:280px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.tb-badge{font-size:10px;font-weight:700;padding:3px 10px;border-radius:20px;text-transform:uppercase;letter-spacing:.06em}
.b-ws{background:var(--orange-strong);color:#fff}.b-key{background:var(--answer);color:#fff}
.btn-print{background:var(--blue-strong);color:#fff;border:none;border-radius:8px;padding:8px 18px;font-size:13px;font-weight:700;font-family:inherit;cursor:pointer;display:inline-flex;align-items:center;gap:6px;transition:filter .15s,transform .15s}
.btn-print:hover{filter:brightness(1.1);transform:translateY(-1px)}
/* ── Document ── */
.ws-doc{max-width:920px;margin:24px auto 60px;background:var(--white);box-shadow:var(--shadow);border-radius:4px;overflow:hidden}
/* ── Cover ── */
.ws-cover{background:#fff;padding:36px 36px 0;position:relative;-webkit-print-color-adjust:exact;print-color-adjust:exact}
.brand-row{display:flex;justify-content:space-between;align-items:flex-start;gap:24px;margin-bottom:28px}
.logo-box{min-width:120px;max-width:180px}
.logo-box img{max-width:100%;height:auto;display:block;object-fit:contain;max-height:70px}
.badge-row{display:flex;flex-wrap:wrap;gap:8px;justify-content:flex-end}
.badge{padding:8px 12px;border-radius:999px;font-size:11px;font-weight:700;letter-spacing:.08em;text-transform:uppercase;color:var(--navy);background:#eef5ff;border:1px solid #d7e5f6;-webkit-print-color-adjust:exact;print-color-adjust:exact}
.hero{background:linear-gradient(135deg,#f8fbff 0%,#eaf4ff 55%,#f5edff 100%);border:1px solid #e0eaf5;border-radius:24px;padding:28px 30px;margin-bottom:22px;-webkit-print-color-adjust:exact;print-color-adjust:exact}
.eyebrow{font-size:12px;text-transform:uppercase;letter-spacing:.16em;font-weight:800;color:var(--blue-strong);margin-bottom:10px}
.hero h1{margin:0 0 10px;font-size:clamp(24px,4vw,44px);line-height:1.05;letter-spacing:-.03em;color:var(--navy);font-weight:800}
.hero p{margin:0;color:var(--muted);font-size:14px;line-height:1.65}
.meta-grid{display:grid;grid-template-columns:repeat(3,1fr);gap:14px;margin-bottom:22px}
.meta-card{background:var(--paper);border:1px solid #e2ebf3;border-radius:18px;padding:16px;-webkit-print-color-adjust:exact;print-color-adjust:exact}
.meta-card span{display:block;color:var(--muted);font-size:10px;text-transform:uppercase;letter-spacing:.12em;font-weight:700;margin-bottom:14px}
.ci-line{height:18px;border-bottom:2px solid var(--line)}
.intro-card{background:#fcfeff;border:1px dashed #d5e4f2;border-radius:18px;padding:18px 20px;color:var(--muted);font-size:13px;line-height:1.7;margin-bottom:30px}
/* ── Page header ── */
.ws-hdr{display:flex;align-items:center;gap:10px;background:var(--navy);padding:8px 22px;-webkit-print-color-adjust:exact;print-color-adjust:exact}
.hdr-brand{font-size:11px;font-weight:700;color:rgba(255,255,255,.60);white-space:nowrap}
.hdr-sep{width:1px;height:14px;background:rgba(255,255,255,.22)}
.hdr-unit{font-size:12px;font-weight:600;color:#fff;flex:1;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.hdr-fields{display:flex;gap:14px;flex-shrink:0}
.hdr-f{display:flex;align-items:center;gap:5px;font-size:10px;font-weight:600;color:rgba(255,255,255,.5);white-space:nowrap}
.hdr-f span{border-bottom:1px solid rgba(255,255,255,.28);display:inline-block;min-width:80px}
/* ── Body ── */
.ws-body{padding:28px 36px}
/* ── Activity section ── */
.ws-sec{margin-bottom:26px;break-inside:avoid;page-break-inside:avoid}
.ws-sec:last-child{margin-bottom:0}
.section-head{display:flex;align-items:center;gap:14px;padding:13px 16px;border-radius:22px;border:1px solid transparent;margin-bottom:14px;-webkit-print-color-adjust:exact;print-color-adjust:exact}
.section-head.blue  {background:linear-gradient(90deg,var(--blue),#f7fbff);border-color:#d9e8fb}
.section-head.cyan  {background:linear-gradient(90deg,var(--cyan),#f8fdff);border-color:#d8eef8}
.section-head.green {background:linear-gradient(90deg,var(--green),#fbfffd);border-color:#d9eee6}
.section-head.orange{background:linear-gradient(90deg,var(--orange),#fffaf7);border-color:#f3e1d1}
.section-head.purple{background:linear-gradient(90deg,var(--purple),#fcf9ff);border-color:#e7ddfb}
.section-head.pink  {background:linear-gradient(90deg,var(--pink),#fffafd);border-color:#f2d8e2}
.num{width:44px;height:44px;border-radius:50%;display:grid;place-items:center;font-weight:800;color:#fff;flex:0 0 44px;font-size:18px;box-shadow:0 8px 18px rgba(55,86,140,.16);-webkit-print-color-adjust:exact;print-color-adjust:exact}
.blue  .num{background:var(--blue-strong)}
.cyan  .num{background:var(--cyan-strong)}
.green .num{background:var(--green-strong)}
.orange .num{background:var(--orange-strong)}
.purple .num{background:var(--purple-strong)}
.pink  .num{background:var(--pink-strong)}
.section-kicker{font-size:11px;text-transform:uppercase;letter-spacing:.14em;font-weight:800;color:var(--muted);margin-bottom:2px}
.section-title{font-size:16px;font-weight:800;color:var(--navy)}
.key-tag{background:var(--answer);color:#fff;font-size:10px;padding:2px 8px;border-radius:10px;margin-left:6px;vertical-align:middle;font-weight:700;letter-spacing:.04em;-webkit-print-color-adjust:exact;print-color-adjust:exact}
/* ── Card ── */
.card{border:1px solid #e1ebf4;background:#fff;border-radius:18px;padding:18px 20px}
.instruction{color:var(--muted);font-style:italic;margin-bottom:14px;line-height:1.7;font-size:13px}
/* ── Tables ── */
table.ws-tbl{width:100%;border-collapse:collapse;overflow:hidden;border-radius:14px;border:1px solid var(--border);font-size:13px;margin-top:4px}
table.ws-tbl th,table.ws-tbl td{border:1px solid var(--border);padding:10px 14px;text-align:left;vertical-align:middle}
table.ws-tbl th{background:#f3f8fd;text-transform:uppercase;letter-spacing:.08em;font-size:11px;color:#56677d;font-weight:700;-webkit-print-color-adjust:exact;print-color-adjust:exact}
.tr-a td{background:var(--bg);-webkit-print-color-adjust:exact;print-color-adjust:exact}
.tc-hn{width:36px;text-align:center}.tc-hw{width:180px}
.tc-n{text-align:center;color:var(--muted);font-size:12px}.tc-w{font-weight:600}
.tc-bl{color:var(--muted)}.tc-ak{color:var(--blue-strong);font-family:monospace;font-size:13px;letter-spacing:.04em;font-weight:700}
/* ── Question blocks ── */
.ws-qb{margin-bottom:18px;break-inside:avoid}
.ws-qt{font-weight:600;font-size:14px;line-height:1.5;margin-bottom:10px;display:flex;align-items:flex-start;gap:10px}
.ws-qn{width:30px;height:30px;border-radius:50%;display:grid;place-items:center;background:var(--navy);color:#fff;font-size:12px;font-weight:800;flex:0 0 30px;margin-top:2px;-webkit-print-color-adjust:exact;print-color-adjust:exact}
.ws-audio{color:var(--cyan-strong);font-style:italic;font-size:13px}
/* ── MCQ options ── */
.ws-opts{display:grid;grid-template-columns:1fr 1fr;gap:10px 14px;margin-top:12px;padding-left:40px}
.ws-opt{display:flex;align-items:center;gap:10px;border:1px solid var(--line);border-radius:14px;padding:10px 12px;font-size:13px;background:#fff;break-inside:avoid;min-height:48px}
.ws-ol{width:30px;height:30px;border-radius:50%;border:2px solid var(--line);color:#607186;display:grid;place-items:center;font-weight:800;flex:0 0 30px;font-size:12px;-webkit-print-color-adjust:exact;print-color-adjust:exact}
.ws-ck{background:var(--answer-bg);border-color:var(--answer);-webkit-print-color-adjust:exact;print-color-adjust:exact}
.ws-ck .ws-ol{background:var(--answer);color:#fff;border-color:var(--answer);-webkit-print-color-adjust:exact;print-color-adjust:exact}
.ws-expl{font-size:11px;color:#059669;background:var(--answer-bg);border-left:3px solid var(--answer);border-radius:0 6px 6px 0;padding:5px 10px;margin-top:8px;margin-left:40px;-webkit-print-color-adjust:exact;print-color-adjust:exact}
/* ── Word bank / Fill in blanks ── */
.ws-bank{display:flex;flex-wrap:wrap;align-items:center;gap:8px;padding:12px 14px;border:1px dashed #cfe0ef;border-radius:16px;background:#f8fcff;margin-bottom:16px;-webkit-print-color-adjust:exact;print-color-adjust:exact}
.ws-blbl{font-size:10px;font-weight:800;text-transform:uppercase;letter-spacing:.1em;color:var(--blue-strong);margin-right:4px}
.ws-chip{padding:8px 14px;border-radius:999px;background:#fff;border:1px solid #b9d7f1;color:var(--navy);font-weight:500;font-size:13px}
.ws-fr{display:flex;align-items:baseline;gap:8px;margin-bottom:12px;font-size:14px;line-height:1.8;break-inside:avoid}
.ws-fn{font-size:12px;font-weight:800;color:var(--muted);min-width:20px}
.ws-fs{color:var(--blue-strong);font-weight:600}.ws-fb{flex:1}
/* ── Writing practice ── */
.ws-wb{margin-bottom:20px;break-inside:avoid}
.ws-wi{font-size:12px;color:var(--muted);font-style:italic;margin:4px 0 8px 40px}
.ws-lines{display:flex;flex-direction:column;gap:10px;margin-top:10px}
.ws-line{height:28px;border-bottom:2px solid var(--line);width:100%}
.ws-ab{background:var(--answer-bg);border:1px solid var(--answer-border);border-radius:12px;padding:8px 12px;margin-left:40px;-webkit-print-color-adjust:exact;print-color-adjust:exact}
.ws-ma{font-size:13px;color:#166534;font-weight:600;margin-bottom:3px}
.ws-ma:last-child{margin-bottom:0}
/* ── Match ── */
.ws-mcols{display:grid;grid-template-columns:1fr 1fr;gap:24px}
.ws-chhd{font-size:12px;text-transform:uppercase;letter-spacing:.12em;font-weight:800;color:#5c6d82;margin-bottom:10px;padding-bottom:8px;border-bottom:3px solid var(--line);-webkit-print-color-adjust:exact;print-color-adjust:exact}
.ws-mr{display:flex;align-items:center;gap:10px;padding:10px 0;border-bottom:1px solid #e5edf5;font-size:13px}
.ws-mn,.ws-ml{font-size:12px;font-weight:700;color:var(--muted);min-width:22px}
.ws-mbl{width:40px;height:2px;background:#475569;border-radius:2px;flex:0 0 40px;-webkit-print-color-adjust:exact;print-color-adjust:exact}.ws-mt{flex:1;line-height:1.4}
/* ── Order ── */
.ws-or{display:flex;align-items:center;gap:12px;padding:10px 4px;border-bottom:1px solid #e5edf5;font-size:14px;break-inside:avoid}
.ws-ob{width:34px;height:28px;min-width:34px;border:2px solid var(--line);border-radius:8px;background:var(--bg);-webkit-print-color-adjust:exact;print-color-adjust:exact}
.ws-ot{flex:1;line-height:1.4}
/* ── Flashcard image grid ── */
.fc-grid{display:grid;grid-template-columns:repeat(3,1fr);gap:12px;margin-top:4px}
.fc-card{border:1px solid var(--border);border-radius:16px;overflow:hidden;background:#fff;break-inside:avoid}
.fc-img{height:130px;background:#fff;display:flex;align-items:center;justify-content:center;padding:10px;}
.fc-img img{max-width:100%;max-height:110px;width:auto;height:auto;object-fit:contain;display:block;border-radius:6px}
.fc-label{padding:8px 10px;border-top:1px solid var(--border);font-size:13px;font-weight:600;color:var(--navy);text-align:center}
.fc-blank{padding:8px 10px;border-top:1px solid var(--border);color:var(--muted);min-height:30px;border-bottom:1px solid #b0bec5}
/* ── Memory card grid ── */
.mc-grid{display:grid;grid-template-columns:repeat(3,1fr);gap:12px}
.mc-card{border:1px solid var(--border);border-radius:16px;overflow:hidden;background:#fff;break-inside:avoid}
.mc-frame{height:130px;background:#fff;display:flex;align-items:center;justify-content:center;padding:10px;color:var(--muted);font-size:12px;text-align:center}
.mc-frame img{max-width:100%;max-height:110px;width:auto;height:auto;object-fit:contain;display:block;border-radius:6px}
.mc-meta{padding:10px 12px;border-top:1px solid var(--border);font-size:13px;color:var(--muted)}
/* ── Dictation ── */
.dt-item{display:flex;align-items:flex-start;gap:10px;padding:10px 0;border-bottom:1px solid #eef2f7;break-inside:avoid;page-break-inside:avoid}
.dt-item:last-child{border-bottom:none}
.dt-num{min-width:28px;font-weight:800;color:var(--navy);font-size:14px;padding-top:2px}
.dt-write{flex:1;display:flex;flex-direction:column;gap:6px}
.dt-img{width:80px;height:60px;flex:0 0 80px;border-radius:10px;overflow:hidden;border:1px solid var(--border);background:#fff}
.dt-img img{width:100%;height:100%;object-fit:contain;display:block}
.dt-answer{background:var(--answer-bg);border:1px solid var(--answer-border);border-radius:8px;padding:6px 10px;font-weight:700;color:#166534;font-size:13px;-webkit-print-color-adjust:exact;print-color-adjust:exact}
.dt-lines{display:flex;flex-direction:column;gap:10px;padding-top:4px}
.dt-line{height:0;border-bottom:1.5px solid #b0bfcc;width:100%}
/* ── Pronunciation ── */
.pr-grid{display:grid;grid-template-columns:repeat(3,1fr);gap:14px;margin-top:4px}
.pr-card{border:1px solid var(--border);border-radius:16px;overflow:hidden;background:#fff;break-inside:avoid;page-break-inside:avoid;-webkit-print-color-adjust:exact;print-color-adjust:exact}
.pr-img{height:130px;background:#fff;display:flex;align-items:center;justify-content:center;padding:10px;border-bottom:1px solid var(--border)}
.pr-img img{max-width:100%;max-height:110px;width:auto;height:auto;object-fit:contain;display:block;border-radius:6px}
.pr-img-txt{font-weight:700;font-size:15px;color:var(--navy);padding:12px;text-align:center}
.pr-fields{padding:8px 10px;display:flex;flex-direction:column;gap:6px}
.pr-field{display:flex;flex-direction:column;gap:2px}
.pr-lbl{font-size:9px;font-weight:800;text-transform:uppercase;letter-spacing:.1em;color:var(--muted)}
.pr-blank{height:20px;border-bottom:1.5px solid #b0bfcc;width:100%}
.pr-ans{font-size:12px;font-weight:600;color:var(--answer);background:var(--answer-bg);border-radius:4px;padding:2px 6px;-webkit-print-color-adjust:exact;print-color-adjust:exact}
.pr-tbl th:nth-child(2),.pr-tbl td:nth-child(2){width:30%}
.pr-tbl th:nth-child(3),.pr-tbl td:nth-child(3){width:30%}
.pr-tbl th:nth-child(4),.pr-tbl td:nth-child(4){width:30%}
@media print{.pr-grid{grid-template-columns:repeat(3,1fr)}.pr-card{break-inside:avoid;page-break-inside:avoid}}
/* ── Powerpoint summary ── */
.ppt-summary{padding:4px 0 8px}
.ppt-summary-label{font-size:10px;font-weight:800;text-transform:uppercase;letter-spacing:.12em;color:var(--muted);margin-bottom:10px}
.ppt-lines{display:flex;flex-direction:column;gap:14px}
.ppt-line{height:0;border-bottom:1.5px solid #b0bfcc;width:100%}
/* ── Placeholder ── */
.ws-hold{padding:22px;border:1px dashed var(--border);border-radius:16px;background:#fbfdff;color:var(--muted);text-align:center;font-style:italic;font-size:13px}
.ws-empty{font-size:12px;color:var(--muted);font-style:italic;padding:8px 0}
/* ── Screen page divider ── */
.ws-pdiv{border:none;border-top:2px dashed var(--border);margin:28px 0}
.ws-plbl{text-align:center;margin:-11px 0 28px}
.ws-plbl span{background:#fff;padding:0 14px;font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.14em;color:var(--border)}
/* ── Print ── */
@media print{
  @page{size:letter;margin:14mm 18mm}
  body{background:#fff;font-size:12px;padding:0}
  .toolbar,.ws-pdiv,.ws-plbl{display:none!important}
  .ws-doc{box-shadow:none;border-radius:0;max-width:100%;margin:0}
  .ws-body{padding:14px 20px}
  .ws-sec{margin-bottom:18px;break-inside:avoid;page-break-inside:avoid}
  .section-head,.num,.ws-qn,.ws-ol,.ws-ck,.ws-ck .ws-ol,.key-tag{-webkit-print-color-adjust:exact;print-color-adjust:exact}
  table.ws-tbl th,.tr-a td,.ws-bank,.ws-chip,.ws-expl,.ws-ab,.ws-ob,.hero,.ws-hdr,.badge{-webkit-print-color-adjust:exact;print-color-adjust:exact}
  .card,.fc-card,.mc-card{break-inside:avoid;page-break-inside:avoid;-webkit-print-color-adjust:exact;print-color-adjust:exact}
  .fc-grid,.mc-grid{break-inside:avoid;page-break-inside:avoid}
  .ws-opt{break-inside:avoid}
  img{max-width:100%;height:auto;-webkit-print-color-adjust:exact;print-color-adjust:exact}
  a{color:inherit;text-decoration:none}
}
@media (max-width:820px){
  .ws-cover{padding:22px 22px 0}
  .brand-row{flex-direction:column}
  .badge-row{justify-content:flex-start}
  .meta-grid,.ws-mcols,.ws-opts,.fc-grid,.mc-grid{grid-template-columns:1fr}
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
    <div class="brand-row">
      <div class="logo-box">
        <img src="/lessons/lessons/hangman/assets/LETS%20NUEVO%20-%20copia.jpeg" alt="LET'S Institute">
      </div>
      <div class="badge-row">
        <div class="badge"><?= h($programName) ?></div>
        <?php if ($isKey): ?>
          <div class="badge" style="background:#f0fdf4;border-color:#86efac;color:#166534;">&#10003; Answer Key</div>
        <?php else: ?>
          <div class="badge" style="background:#fff0e3;border-color:#fcd39a;color:#7c4a00;">Worksheet</div>
        <?php endif; ?>
        <div class="badge"><?= h($today) ?></div>
      </div>
    </div>

    <div class="hero">
      <div class="eyebrow">LET'S Institute &bull; <?= h($programName) ?></div>
      <h1><?= h($unitName) ?></h1>
      <?php
        $heroParts = array_filter([$unitLevel, $unitModule]);
        $heroSub   = implode(' &middot; ', array_map('h', $heroParts));
      ?>
      <p><?= $heroSub !== '' ? $heroSub.' &mdash; ' : '' ?><?= count($sections) ?> activit<?= count($sections)===1?'y':'ies' ?> included in this worksheet.</p>
    </div>

    <div class="meta-grid">
      <div class="meta-card"><span>Student Name</span><div class="ci-line"></div></div>
      <div class="meta-card"><span>Date</span><div class="ci-line"></div></div>
      <div class="meta-card"><span>Group / Level</span><div class="ci-line"></div></div>
    </div>

    <div class="intro-card">
      Read each section carefully and complete all activities. Write your answers clearly.
      <?php if ($isKey): ?> <strong>This is the teacher&apos;s Answer Key &mdash; correct answers are highlighted.</strong><?php endif; ?>
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