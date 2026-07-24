<?php
session_start();

$isTeacher = !empty($_SESSION['academic_logged']) || !empty($_SESSION['admin_logged']);
$isStudent  = !empty($_SESSION['student_logged']);
if (!$isTeacher && !$isStudent) { header('Location: login.php'); exit; }

$unitId       = trim((string) ($_GET['unit']       ?? ''));
$assignmentId = trim((string) ($_GET['assignment'] ?? ''));
if ($unitId === '') die('Unit not specified.');

/* mode=student -> force student worksheet (no answers), even for teachers
   mode=key     -> force answer key (teacher only)
   default      -> student gets worksheet, teacher gets answer key */
$modeParam = trim((string) ($_GET['mode'] ?? ''));
if ($modeParam === 'student') {
    $isKey = false;
} elseif ($modeParam === 'key' && $isTeacher) {
    $isKey = true;
} else {
    $isKey = $isTeacher;
}

$dbFile = __DIR__ . '/../config/db.php';
if (!file_exists($dbFile)) die('Database configuration not found.');
require $dbFile;
if (!isset($pdo) || !($pdo instanceof PDO)) die('Database connection unavailable.');

/* ── helpers ─────────────────────────────────────────────────── */
function h(string $v): string { return htmlspecialchars($v, ENT_QUOTES, 'UTF-8'); }

/**
 * Highlights vocabulary words inside a reading-comprehension passage, mirroring
 * activities/reading_comprehension/viewer.php's client-side highlight() so the
 * printed worksheet matches what students see in the activity.
 */
function ws_rc_highlight(string $body, array $words): string {
    $out = nl2br(h($body));
    $terms = [];
    foreach ($words as $w) {
        $term = trim((string)($w['word'] ?? ''));
        if ($term !== '') $terms[] = $term;
    }
    usort($terms, fn($a, $b) => mb_strlen($b) <=> mb_strlen($a));
    foreach ($terms as $term) {
        $pattern = preg_match('/\s/', $term)
            ? '/(' . preg_quote($term, '/') . ')/iu'
            : '/\b(' . preg_quote($term, '/') . ')\b/iu';
        $out = preg_replace_callback($pattern, function ($m) {
            return '<span class="ws-rc-hl">' . $m[1] . '</span>';
        }, $out);
    }
    return $out;
}
function col_exists(PDO $pdo, string $t, string $c): bool {
    try { $pdo->query("SELECT {$c} FROM {$t} LIMIT 0"); return true; } catch (Throwable $e) { return false; }
}

/* ── unit data ───────────────────────────────────────────────── */
$unitName = ''; $unitLevel = ''; $unitModule = '';
try {
    $cols = ['name'];
    if (col_exists($pdo,'units','level'))       $cols[] = 'level';
    if (col_exists($pdo,'units','module_name')) $cols[] = 'module_name';
    $s = $pdo->prepare('SELECT ' . implode(',', $cols) . ' FROM units WHERE id=:id LIMIT 1');
    $s->execute(['id' => $unitId]);
    $row = $s->fetch(PDO::FETCH_ASSOC);
    if ($row) {
        $unitName   = trim((string)($row['name']        ?? ''));
        $unitLevel  = trim((string)($row['level']       ?? ''));
        $unitModule = trim((string)($row['module_name'] ?? ''));
    }
} catch (Throwable $e) {}
if ($unitName === '') $unitName = 'Unit';

/* ── course / grade from assignment ─────────────────────────── */
$courseName  = '';
$programType = '';
$gradeName   = '';
try {
    /* Try student assignments table first */
    $s = $pdo->prepare("SELECT course_name, program_type FROM assignments WHERE id=:id LIMIT 1");
    $s->execute(['id' => $assignmentId]);
    $ar = $s->fetch(PDO::FETCH_ASSOC);
    if ($ar) {
        $courseName  = trim((string)($ar['course_name']  ?? ''));
        $programType = trim((string)($ar['program_type'] ?? ''));
    }
} catch (Throwable $e) {}
/* Fallback: teacher_assignments */
if ($courseName === '') {
    try {
        $s = $pdo->prepare("SELECT course_name, program_type FROM teacher_assignments WHERE id=:id LIMIT 1");
        $s->execute(['id' => $assignmentId]);
        $ar = $s->fetch(PDO::FETCH_ASSOC);
        if ($ar) {
            $courseName  = trim((string)($ar['course_name']  ?? ''));
            $programType = trim((string)($ar['program_type'] ?? ''));
        }
    } catch (Throwable $e) {}
}
/* Resolve grade / program label */
if ($courseName !== '') {
    $gradeName = $courseName;
} elseif ($programType === 'english') {
    $gradeName = 'English Program';
} elseif ($programType !== '') {
    $gradeName = ucfirst($programType) . ' Program';
} else {
    $gradeName = 'English Program';
}

/* ── activities (exclude non-printable types) ───────────────── */
$SKIP_TYPES = ['flipbooks','hangman','crossword','coloring','dot_to_dot','tracing'];
$skipList   = implode(',', array_map(fn($t) => "'".$t."'", $SKIP_TYPES));
$activities = [];
try {
    $ob = col_exists($pdo,'activities','position')
        ? 'ORDER BY COALESCE(position,0) ASC, id ASC' : 'ORDER BY id ASC';
    $s = $pdo->prepare("SELECT id, type, data FROM activities
                        WHERE unit_id=:uid AND type NOT IN ({$skipList}) {$ob}");
    $s->execute(['uid' => $unitId]);
    $activities = $s->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (Throwable $e) {}

/* ── render helpers ─────────────────────────────────────────── */
function ws_decode($raw): array {
    if (!is_string($raw) || trim($raw) === '') return [];
    $d = json_decode($raw, true);
    return is_array($d) ? $d : [];
}

/* Section number colour: alternate orange / lila */
function sec_color(int $n): string {
    return ($n % 2 === 1) ? 'ora' : 'lila';
}

function ws_head(int $n, string $kicker, string $title, string $instr, bool $isKey, string $cardClass = ''): string {
    $c   = sec_color($n);
    $cls = 'card-box' . ($cardClass !== '' ? ' '.$cardClass : '');
    $out = '<div class="ws-sec">';
    $out .= '<div class="sec-head"><div class="snum '.$c.'">'.$n.'</div>';
    $out .= '<div class="sec-meta"><div class="sec-kicker">'.h($kicker);
    if ($isKey) $out .= '<span class="key-tag">Answer Key</span>';
    $out .= '</div>';
    if ($title !== '') $out .= '<div class="sec-title">'.h($title).'</div>';
    $out .= '</div></div>';
    $out .= '<div class="'.$cls.'">';
    if ($instr !== '') {
        $out .= '<div class="ibox">'
             .  '<span class="ilbl">Instructions</span>'
             .  '<span class="itxt">'.h($instr).'</span>'
             .  '</div>';
    }
    return $out;
}
function ws_foot(): string { return '</div></div>'; }

/* ── VOCABULARY / FLASHCARDS ───────────────────────────────── */
function ws_flashcards(array $d, int $n, bool $k): string {
    $cards = is_array($d['cards'] ?? null) ? $d['cards'] : [];
    $title = trim((string)($d['title'] ?? 'Vocabulary'));
    $hasImg = false;
    foreach ($cards as $c) { if (!empty($c['image'])) { $hasImg = true; break; } }

    $out = ws_head($n, 'Vocabulary', $title,
        $hasImg ? 'Look at each picture. Write the correct English word on the line.'
                : 'Study the vocabulary list. Write the Spanish translation.',
        $k);

    if (empty($cards)) return $out.'<p class="ws-empty">No items.</p>'.ws_foot();

    if ($hasImg) {
        $out .= '<div class="fc-grid">';
        foreach ($cards as $c) {
            $tx  = trim((string)($c['text']  ?? ''));
            $img = trim((string)($c['image'] ?? ''));
            if ($tx === '' && $img === '') continue;
            $out .= '<div class="fc-card">';
            if ($img !== '') {
                $out .= '<div class="fc-img"><img src="'.h($img).'" alt="'.h($tx).'" loading="eager"></div>';
            } else {
                $out .= '<div class="fc-img fc-img-txt">'.h($tx).'</div>';
            }
            if ($k && $tx !== '') {
                $out .= '<div class="fc-word">'.h($tx).'</div>';
            } else {
                $out .= '<div class="fc-word fc-word-blank">&nbsp;</div>';
            }
            $out .= '<div class="fc-blank-zone">';
            $out .= '<span class="fc-blbl">Write here</span>';
            $out .= '<div class="fc-bline"></div>';
            $out .= '</div></div>';
        }
        $out .= '</div>';
    } else {
        $out .= '<table class="ws-tbl"><thead><tr>'
             .  '<th class="tc-n">#</th><th>Word / Phrase</th><th class="tc-bl">Translation</th>'
             .  '</tr></thead><tbody>';
        foreach ($cards as $i => $c) {
            $tx = trim((string)($c['text'] ?? ''));
            if ($tx === '') continue;
            $cls = ($i % 2 === 0) ? '' : ' class="tr-alt"';
            $out .= '<tr'.$cls.'>'
                 .  '<td class="tc-n">'.($i+1).'</td>'
                 .  '<td class="tc-w">'.h($tx).'</td>'
                 .  '<td class="tc-bl">&nbsp;</td>'
                 .  '</tr>';
        }
        $out .= '</tbody></table>';
    }
    return $out.ws_foot();
}

/* ── QUIZ ─────────────────────────────────────────────────── */
function ws_quiz(array $d, int $n, bool $k): string {
    $qs   = is_array($d['questions'] ?? null) ? $d['questions'] : [];
    $desc = trim((string)($d['description'] ?? ''));
    $ltrs = ['A','B','C','D'];
    $out  = ws_head($n, 'Quiz', trim((string)($d['title'] ?? 'Quiz')),
                    $desc ?: 'Circle the correct answer.', $k);
    foreach ($qs as $qi => $q) {
        $qt = trim((string)($q['question'] ?? ''));
        $op = is_array($q['options'] ?? null) ? $q['options'] : [];
        $ck = (int)($q['correct'] ?? 0);
        $ex = trim((string)($q['explanation'] ?? ''));
        $out .= '<div class="ws-qb">'
             .  '<div class="ws-qt"><span class="qnum">'.($qi+1).'</span>'.h($qt).'</div>'
             .  '<div class="ws-opts">';
        foreach ($op as $oi => $o) {
            $ot = trim((string)$o); if ($ot === '') continue;
            $ck_cls = ($k && $oi === $ck) ? ' ws-ck' : '';
            $out .= '<div class="ws-opt'.$ck_cls.'"><span class="opt-l">'.($ltrs[$oi] ?? chr(65+$oi)).'</span>'.h($ot).'</div>';
        }
        $out .= '</div>';
        if ($k && $ex !== '') $out .= '<div class="ws-expl">'.h($ex).'</div>';
        $out .= '</div>';
    }
    return $out.ws_foot();
}

/* ── MULTIPLE CHOICE ─────────────────────────────────────── */
function ws_mc(array $d, int $n, bool $k): string {
    $qs      = is_array($d['questions'] ?? null) ? $d['questions'] : [];
    $passage = trim((string)($d['passage'] ?? ''));
    $ltrs = ['A','B','C','D'];
    $out  = ws_head($n, 'Multiple Choice', trim((string)($d['title'] ?? '')),
                    'Circle the letter of the correct answer.', $k);
    if ($passage !== '') {
        $out .= '<div class="ws-mc-passage">'
              . '<div class="ws-mc-passage-lbl">📖 Reading Passage</div>'
              . '<div class="ws-mc-passage-body">'.nl2br(h($passage)).'</div>'
              . '</div>';
    }
    foreach ($qs as $qi => $q) {
        $qt   = trim((string)($q['question'] ?? ''));
        $qtp  = $q['question_type'] ?? 'text';
        $qimg = trim((string)($q['image'] ?? ''));
        $op   = is_array($q['options'] ?? null) ? $q['options'] : [];
        $otp  = $q['option_type'] ?? 'text';
        $oimg = is_array($q['option_images'] ?? null) ? $q['option_images'] : [];
        $ck   = (int)($q['correct'] ?? 0);
        $out .= '<div class="ws-qb"><div class="ws-qt"><span class="qnum">'.($qi+1).'</span>';
        if ($qtp === 'listen') {
            $out .= '<em class="ws-audio">&#127911; Listen and choose.</em>';
        } elseif ($qt !== '') {
            $out .= h($qt);
        }
        if ($qimg !== '') $out .= '<div class="mc-qimg"><img src="'.h($qimg).'" alt="" loading="eager"></div>';
        $out .= '</div>';
        if ($otp === 'image') {
            $out .= '<div class="mc-img-opts">';
            foreach ($op as $oi => $o) {
                $url = trim((string)$o);
                $ck_cls = ($k && $oi === $ck) ? ' ws-ck' : '';
                $out .= '<div class="mc-img-opt'.$ck_cls.'">'
                     .  '<span class="opt-l">'.($ltrs[$oi] ?? chr(65+$oi)).'</span>';
                if ($url !== '') $out .= '<div class="mc-frame"><img src="'.h($url).'" alt="" loading="eager"></div>';
                $out .= '</div>';
            }
            $out .= '</div>';
        } else {
            $out .= '<div class="ws-opts">';
            foreach ($op as $oi => $o) {
                $ot = trim((string)$o);
                $oimgUrl = trim((string)($oimg[$oi] ?? ''));
                $ck_cls = ($k && $oi === $ck) ? ' ws-ck' : '';
                $out .= '<div class="ws-opt'.$ck_cls.'"><span class="opt-l">'.($ltrs[$oi] ?? chr(65+$oi)).'</span>';
                if ($oimgUrl !== '') $out .= '<img class="ws-opt-thumb" src="'.h($oimgUrl).'" alt="" loading="eager">';
                $out .= '<span class="ws-opt-txt">'.h($ot).'</span></div>';
            }
            $out .= '</div>';
        }
        $out .= '</div>';
    }
    return $out.ws_foot();
}

/* ── FILL IN THE BLANKS (drag_drop) ─────────────────────── */
function ws_dragdrop(array $d, int $n, bool $k): string {
    $blocks   = is_array($d['blocks'] ?? null) ? $d['blocks'] : [];
    $allWords = [];
    foreach ($blocks as $b) {
        foreach ((array)($b['missing_words'] ?? []) as $w) {
            $w = trim((string)$w); if ($w !== '') $allWords[] = $w;
        }
    }
    $bank = array_values(array_unique($allWords));
    shuffle($bank);
    $out = ws_head($n, 'Fill in the Blanks', trim((string)($d['title'] ?? '')),
                   $k ? 'Sentences with correct answers shown.'
                      : 'Fill in the blanks using the word bank below.', $k, 'card-open');
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
            $out .= '<span class="ws-ans">'.h($tx).'</span>';
        } else {
            $bl2 = $tx;
            foreach ($ms as $mw) {
                $mw = trim((string)$mw); if ($mw === '') continue;
                $bl2 = preg_replace('/'.preg_quote($mw,'/').'/',
                    str_repeat('_', max(6, mb_strlen($mw,'UTF-8') + 4)), $bl2, 1);
            }
            $out .= h($bl2);
        }
        $out .= '</span></div>';
    }
    return $out.ws_foot();
}

/* ── WRITING PRACTICE ────────────────────────────────────── */
function ws_blank_span(string $seed = ''): string {
    $len = max(8, min(26, mb_strlen(trim($seed), 'UTF-8') + 4));
    return '<span class="ws-inline-blank" style="--bl:'.$len.'"></span>';
}

function ws_render_blanks(string $raw, array $answers, string $type): string {
    $raw = str_replace(["\r\n","\r"], "\n", $raw);
    $answers = array_values(array_filter(array_map('trim', $answers), fn($a) => $a !== ''));
    $cls = $type === 'fill_sentence' ? 'ws-fill-prompt ws-fill-sentence' : 'ws-fill-prompt';
    if ($raw === '') {
        $fb = !empty($answers) ? implode(' ', array_fill(0, count($answers), ws_blank_span('answer'))) : ws_blank_span();
        return '<div class="'.$cls.'">'.$fb.'</div>';
    }
    $html = '';
    if (preg_match('/_{2,}/', $raw)) {
        $parts = preg_split('/(_{2,})/', $raw, -1, PREG_SPLIT_DELIM_CAPTURE);
        $bi = 0;
        foreach ($parts as $part) {
            if ($part === '') continue;
            if (preg_match('/^_{2,}$/', $part)) { $html .= ws_blank_span($answers[$bi] ?? ''); $bi++; }
            else $html .= nl2br(h($part));
        }
    } elseif (!empty($answers)) {
        $rem = $raw;
        foreach ($answers as $ans) {
            $pat = '/'.preg_quote($ans,'/')  .'/iu';
            if (preg_match($pat, $rem, $m, PREG_OFFSET_CAPTURE)) {
                $before = substr($rem, 0, (int)$m[0][1]);
                $after  = substr($rem, (int)$m[0][1] + strlen($m[0][0]));
                $html .= nl2br(h($before)) . ws_blank_span($ans);
                $rem = $after;
            } else {
                $html .= nl2br(h($rem)); $rem = '';
                $html .= ' '.ws_blank_span($ans);
            }
        }
        if ($rem !== '') $html .= nl2br(h($rem));
    } else {
        $html = nl2br(h($raw)).' '.ws_blank_span();
    }
    return '<div class="'.$cls.'">'.$html.'</div>';
}

function ws_writing(array $d, int $n, bool $k): string {
    /* Real schema: {title, items:[{id, instruction, prompt_text, answer}]} */
    $items = is_array($d['items'] ?? null) ? $d['items'] : [];
    if (empty($items) && is_array($d['questions'] ?? null)) $items = $d['questions']; // legacy fallback
    $out  = ws_head($n, 'Writing Practice', trim((string)($d['title'] ?? '')),
                    'Write your answers in complete sentences.', $k, 'card-open');
    if (empty($items)) return $out.'<div class="notes-box"></div>'.ws_foot();
    foreach ($items as $qi => $it) {
        $in  = trim((string)($it['instruction'] ?? ''));
        $qt  = trim((string)($it['prompt_text'] ?? ($it['question'] ?? '')));
        $an  = trim((string)($it['answer'] ?? ''));
        /* More lines if instruction/prompt implies a longer paragraph response */
        $numLines = (stripos($in,'paragraph')!==false || stripos($qt,'paragraph')!==false
                     || stripos($in,'describe')!==false) ? 5 : 3;
        $out .= '<div class="ws-wb">';
        $out .= '<div class="ws-qt"><span class="qnum">'.($qi+1).'</span>'.h($qt !== '' ? $qt : ($in ?: 'Write your answer.')).'</div>';
        if ($in !== '' && $qt !== '') $out .= '<div class="ws-wi">'.h($in).'</div>';
        if ($k && $an !== '') {
            $out .= '<div class="ws-ab"><div class="ws-ma">&#10003; '.h($an).'</div></div>';
        } else {
            $out .= '<div class="ws-open-lines">'.str_repeat('<div class="ws-open-line"></div>', $numLines).'</div>';
        }
        $out .= '</div>';
    }
    return $out.ws_foot();
}

/* ── MATCH ───────────────────────────────────────────────── */
function ws_match(array $d, int $n, bool $k): string {
    $pairs = is_array($d['pairs'] ?? null) ? $d['pairs'] : [];
    $ltrs  = range('A','Z');
    $lefts = []; $rights = [];
    foreach ($pairs as $p) {
        $lefts[]  = trim((string)($p['left_text']  ?? ''));
        $rights[] = trim((string)($p['right_text'] ?? ''));
    }
    $sh = $rights; if (!$k) shuffle($sh);
    $out = ws_head($n, 'Match the Pairs', trim((string)($d['title'] ?? '')),
                   'Match Column A to Column B. Write the letter on the line.', $k);
    $out .= '<div class="mcols">';
    /* col A */
    $out .= '<div><div class="mchd">Column A</div>';
    foreach ($lefts as $i => $it) {
        $out .= '<div class="mrow">'
             .  '<span class="mn">'.($i+1).'.</span>'
             .  '<span class="mbl"></span>'
             .  '<span class="mt">'.h($it).'</span>'
             .  '</div>';
    }
    $out .= '</div>';
    /* col B */
    $out .= '<div><div class="mchd">Column B</div>';
    foreach ($sh as $i => $it) {
        $out .= '<div class="mrow">'
             .  '<span class="ml">'.($ltrs[$i] ?? '?').'.</span>'
             .  '<span class="mt">'.h($it).'</span>'
             .  '</div>';
    }
    $out .= '</div></div>';
    return $out.ws_foot();
}

function ws_matching_lines(array $d, int $n, bool $k): string {
    $boards = is_array($d['boards'] ?? null) ? $d['boards'] : [];
    $first  = isset($boards[0]) && is_array($boards[0]) ? $boards[0] : [];
    $pairs  = is_array($first['pairs'] ?? null) ? $first['pairs'] : [];
    $norm   = [];
    foreach ($pairs as $i => $p) {
        if (!is_array($p)) continue;
        $lt = trim((string)($p['left_text']  ?? '')); if ($lt === '') $lt = 'Item '.($i+1);
        $rt = trim((string)($p['right_text'] ?? '')); if ($rt === '') $rt = 'Item '.($i+1);
        if ($lt === '' || $rt === '') continue;
        $norm[] = ['left_text' => $lt, 'right_text' => $rt];
    }
    return ws_match(['title' => $d['title'] ?? 'Matching Lines', 'pairs' => $norm], $n, $k);
}

/* ── ORDER SENTENCES ─────────────────────────────────────── */
function ws_order(array $d, int $n, bool $k): string {
    $in = trim((string)($d['instructions'] ?? ''));
    $ss = is_array($d['sentences'] ?? null) ? $d['sentences'] : [];
    $sh = $ss; if (!$k) shuffle($sh);
    $out = ws_head($n, 'Order the Sentences', trim((string)($d['title'] ?? '')),
                   $in ?: 'Number the sentences in the correct order (1, 2, 3…).', $k);
    foreach ($sh as $s) {
        $tx = trim((string)($s['text'] ?? '')); if ($tx === '') continue;
        $out .= '<div class="ws-or"><span class="ws-ob"></span><span class="ws-ot">'.h($tx).'</span></div>';
    }
    return $out.ws_foot();
}

/* ── LISTEN ORDER ────────────────────────────────────────── */
function ws_listenorder(array $d, int $n, bool $k): string {
    $bl = is_array($d['blocks'] ?? null) ? $d['blocks'] : [];
    $sh = $bl; if (!$k) shuffle($sh);
    $out = ws_head($n, 'Listen &amp; Order', trim((string)($d['title'] ?? '')),
                   'Listen and number the sentences in the correct order.', $k);
    foreach ($sh as $b) {
        $tx = trim((string)($b['sentence'] ?? '')); if ($tx === '') continue;
        $out .= '<div class="ws-or"><span class="ws-ob"></span><span class="ws-ot">'.h($tx).'</span></div>';
    }
    return $out.ws_foot();
}

/* ── MEMORY CARDS ────────────────────────────────────────── */
function ws_memory(array $d, int $n, bool $k): string {
    $pairs = is_array($d['pairs'] ?? null) ? $d['pairs']
           : (is_array($d['cards'] ?? null) ? $d['cards'] : []);
    $out   = ws_head($n, 'Memory Cards', trim((string)($d['title'] ?? '')),
                     'Write the matching word or phrase for each card.', $k);
    if (empty($pairs)) return $out.'<p class="ws-empty">No items.</p>'.ws_foot();
    $hasImg = false;
    foreach ($pairs as $p) {
        if (($p['left']['image'] ?? '') !== '' || ($p['right']['image'] ?? '') !== '') { $hasImg = true; break; }
    }
    if ($hasImg) {
        $out .= '<div class="fc-grid">';
        foreach ($pairs as $p) {
            $l = $p['left'] ?? null; $r = $p['right'] ?? null;
            $imgUrl = ''; $lbl = '';
            if ($l && !empty($l['image'])) { $imgUrl = $l['image']; $lbl = $r ? trim((string)($r['text'] ?? '')) : ''; }
            elseif ($r && !empty($r['image'])) { $imgUrl = $r['image']; $lbl = $l ? trim((string)($l['text'] ?? '')) : ''; }
            else { $lbl = $l ? trim((string)($l['text'] ?? '')) : trim((string)($p['text'] ?? $p['word'] ?? '')); }
            $out .= '<div class="fc-card">';
            if ($imgUrl !== '') {
                $out .= '<div class="fc-img"><img src="'.h($imgUrl).'" alt="'.h($lbl).'" loading="eager"></div>';
            } else {
                $out .= '<div class="fc-img fc-img-txt">'.h($lbl).'</div>';
            }
            $out .= ($k && $lbl !== '') ? '<div class="fc-word ws-ans">'.h($lbl).'</div>' : '<div class="fc-word fc-word-blank">&nbsp;</div>';
            $out .= '<div class="fc-blank-zone"><span class="fc-blbl">Write here</span><div class="fc-bline"></div></div>';
            $out .= '</div>';
        }
        $out .= '</div>';
    } else {
        $out .= '<table class="ws-tbl"><thead><tr>'
             .  '<th class="tc-n">#</th><th>Card</th><th class="tc-bl">Match</th>'
             .  '</tr></thead><tbody>';
        foreach ($pairs as $i => $p) {
            $l  = $p['left'] ?? null;
            $tx = $l ? trim((string)($l['text'] ?? '')) : trim((string)($p['text'] ?? $p['word'] ?? $p['front'] ?? ''));
            if ($tx === '') continue;
            $cls = ($i % 2 === 0) ? '' : ' class="tr-alt"';
            $out .= '<tr'.$cls.'>'
                 .  '<td class="tc-n">'.($i+1).'</td>'
                 .  '<td class="tc-w">'.h($tx).'</td>'
                 .  '<td class="tc-bl">&nbsp;</td>'
                 .  '</tr>';
        }
        $out .= '</tbody></table>';
    }
    return $out.ws_foot();
}

/* ── VIDEO COMPREHENSION ─────────────────────────────────── */
function ws_video(array $d, int $n, bool $k): string {
    $qs    = is_array($d['questions'] ?? null) ? $d['questions'] : [];
    $instr = trim((string)($d['instructions'] ?? ''));
    $ltrs  = ['A','B','C','D'];
    $out   = ws_head($n, 'Video Activity', trim((string)($d['title'] ?? '')),
                     $instr ?: 'Watch the video and answer each question.', $k);
    if (empty($qs)) {
        $out .= '<div class="notes-box"></div>';
        return $out.ws_foot();
    }
    foreach ($qs as $qi => $q) {
        $qt = trim((string)($q['question'] ?? ''));
        $op = is_array($q['options'] ?? null) ? $q['options'] : [];
        $ck = (int)($q['correct'] ?? 0);
        $ex = trim((string)($q['explanation'] ?? ''));
        $out .= '<div class="ws-qb ws-qb--video"><div class="ws-qt ws-qt--video"><span class="qnum qnum--video">'.($qi+1).'</span>'.h($qt).'</div><div class="ws-opts">';
        foreach ($op as $oi => $o) {
            $ot = trim((string)$o); if ($ot === '') continue;
            $ck_cls = ($k && $oi === $ck) ? ' ws-ck' : '';
            $out .= '<div class="ws-opt ws-opt--video'.$ck_cls.'"><span class="opt-l opt-l--video">'.($ltrs[$oi] ?? chr(65+$oi)).'</span>'.h($ot).'</div>';
        }
        $out .= '</div>';
        if ($k && $ex !== '') $out .= '<div class="ws-expl">'.h($ex).'</div>';
        $out .= '</div>';
    }
    return $out.ws_foot();
}

/* ── DICTATION ───────────────────────────────────────────── */
function ws_dictation(array $d, int $n, bool $k): string {
    $items = is_array($d['items'] ?? null) ? $d['items'] : [];
    $out   = ws_head($n, 'Dictation', trim((string)($d['title'] ?? '')),
                     'Listen to each item and write what you hear.', $k, 'card-open');
    if (empty($items)) {
        $out .= '<div class="notes-box"></div>';
        return $out.ws_foot();
    }
    foreach ($items as $i => $item) {
        $en  = trim((string)($item['en'] ?? ''));
        $img = trim((string)($item['img'] ?? ''));
        $out .= '<div class="dt-item">';
        $out .= '<div class="dt-num">'.($i+1).'.</div>';
        if ($img !== '') {
            $out .= '<div class="dt-img"><img src="'.h($img).'" alt="item '.($i+1).'" loading="eager"></div>';
        } else {
            /* No image on this item — keep the reserved box so every row stays aligned */
            $out .= '<div class="dt-img dt-img-empty"></div>';
        }
        $out .= '<div class="dt-write">';
        if ($k && $en !== '') $out .= '<div class="ws-ans dt-ans">'.h($en).'</div>';
        $out .= '<div class="ws-open-lines dt-lines">';
        for ($l = 0; $l < 4; $l++) $out .= '<div class="ws-open-line"></div>';
        $out .= '</div></div></div>';
    }
    return $out.ws_foot();
}

/* ── PRONUNCIATION ───────────────────────────────────────── */
function ws_pronunciation(array $d, int $n, bool $k): string {
    $items = is_array($d['items'] ?? null) ? $d['items'] : [];
    $out   = ws_head($n, 'Pronunciation', trim((string)($d['title'] ?? '')),
                     'Write the English word, pronunciation guide, and Spanish translation.', $k);
    if (empty($items)) {
        $out .= '<div class="notes-box"></div>';
        return $out.ws_foot();
    }
    $hasImg = false;
    foreach ($items as $it) { if (trim((string)($it['img'] ?? '')) !== '') { $hasImg = true; break; } }
    if ($hasImg) {
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
            $out .= '<div class="pr-field"><span class="pr-lbl">English</span>'.    ($k && $en !== '' ? '<div class="ws-ans pr-ans">'.h($en).'</div>' : '<div class="pr-blank"></div>').'</div>';
            $out .= '<div class="pr-field"><span class="pr-lbl">Pronunciation</span>'.($k && $ph !== '' ? '<div class="ws-ans pr-ans">'.h($ph).'</div>' : '<div class="pr-blank"></div>').'</div>';
            $out .= '<div class="pr-field"><span class="pr-lbl">Spanish</span>'.    ($k && $es !== '' ? '<div class="ws-ans pr-ans">'.h($es).'</div>' : '<div class="pr-blank"></div>').'</div>';
            $out .= '</div></div>';
        }
        $out .= '</div>';
    } else {
        $out .= '<table class="ws-tbl pr-tbl"><thead><tr>'
             .  '<th class="tc-n">#</th><th>English</th><th>Pronunciation</th><th>Spanish</th>'
             .  '</tr></thead><tbody>';
        foreach ($items as $i => $it) {
            $en = trim((string)($it['en'] ?? ''));
            $ph = trim((string)($it['ph'] ?? ''));
            $es = trim((string)($it['es'] ?? ''));
            $cls = ($i % 2 === 0) ? '' : ' class="tr-alt"';
            $out .= '<tr'.$cls.'><td class="tc-n">'.($i+1).'</td>';
            $out .= '<td>'.($k && $en!=='' ? '<span class="ws-ans">'.h($en).'</span>' : '&nbsp;').'</td>';
            $out .= '<td>'.($k && $ph!=='' ? h($ph) : '&nbsp;').'</td>';
            $out .= '<td>'.($k && $es!=='' ? h($es) : '&nbsp;').'</td></tr>';
        }
        $out .= '</tbody></table>';
    }
    return $out.ws_foot();
}

/* ── POWERPOINT / EXTERNAL (notes box) ──────────────────── */
function ws_notes(array $d, int $n, string $kicker, bool $k): string {
    $title = trim((string)($d['title'] ?? ''));
    $instr = $kicker === 'Presentation'
        ? 'Follow along with the presentation. Use the space to write key ideas.'
        : 'Access the external resource in the app. Write your notes and key ideas below.';
    $out = ws_head($n, $kicker, $title, $instr, $k);
    $out .= '<div class="notes-box"></div>';
    return $out.ws_foot();
}

/* ── READING COMPREHENSION ───────────────────────────────── */
function ws_reading(array $d, int $n, bool $k): string {
    // Real payload shape (see activities/reading_comprehension/viewer.php normalizeText()):
    // { mode:'vocab'|'comp', title, genre, wordCount, body, words:[{word,correct,distractors}], questions:[{stem,options[4],correct,feedback}] }
    $mode   = strtolower(trim((string)($d['mode'] ?? 'vocab')));
    $isComp = $mode === 'comp';
    $body   = trim((string)($d['body'] ?? ($d['text'] ?? '')));
    $ltrs   = ['A','B','C','D'];
    $kicker = $isComp ? 'Reading Comprehension' : 'Vocabulary Meaning';
    $instr  = $isComp
        ? 'Read the passage carefully, then answer the questions below.'
        : 'Read the passage. Choose the correct meaning for each highlighted word.';
    $out    = ws_head($n, $kicker, trim((string)($d['title'] ?? '')), $instr, $k);
    if ($body !== '') {
        $words = is_array($d['words'] ?? null) ? $d['words'] : [];
        $bodyHtml = $isComp ? nl2br(h($body)) : ws_rc_highlight($body, $words);
        $out .= '<div class="rc-text">'.$bodyHtml.'</div>';
    }

    if ($isComp) {
        $qs = is_array($d['questions'] ?? null) ? $d['questions'] : [];
        foreach ($qs as $qi => $q) {
            if (!is_array($q)) continue;
            $qt = trim((string)($q['stem'] ?? ($q['question'] ?? '')));
            $op = is_array($q['options'] ?? null) ? $q['options'] : [];
            $ck = (int)($q['correct'] ?? 0);
            $hasOpt = false;
            foreach ($op as $o) { if (trim((string)$o) !== '') { $hasOpt = true; break; } }
            if ($qt === '' && !$hasOpt) continue;
            $out .= '<div class="ws-qb"><div class="ws-qt"><span class="qnum">'.($qi+1).'</span>'.h($qt).'</div>';
            if ($hasOpt) {
                $out .= '<div class="ws-opts">';
                foreach ($op as $oi => $o) {
                    $ot = trim((string)$o); if ($ot === '') continue;
                    $ck_cls = ($k && $oi === $ck) ? ' ws-ck' : '';
                    $out .= '<div class="ws-opt'.$ck_cls.'"><span class="opt-l">'.($ltrs[$oi] ?? chr(65+$oi)).'</span>'.h($ot).'</div>';
                }
                $out .= '</div>';
            } else {
                /* open-answer question — provide writing lines instead of options */
                $out .= '<div class="ws-open-lines">'.str_repeat('<div class="ws-open-line"></div>', 2).'</div>';
                if ($k) {
                    $fb = trim((string)($q['feedback'] ?? ''));
                    if ($fb !== '') $out .= '<div class="ws-expl">'.h($fb).'</div>';
                }
            }
            $out .= '</div>';
        }
    } else {
        $words = is_array($d['words'] ?? null) ? $d['words'] : [];
        foreach ($words as $wi => $w) {
            if (!is_array($w)) continue;
            $word = trim((string)($w['word'] ?? ''));
            if ($word === '') continue;
            $correct     = trim((string)($w['correct'] ?? ''));
            $distractors = is_array($w['distractors'] ?? null) ? $w['distractors'] : [];
            $opts = [];
            if ($correct !== '') $opts[] = $correct;
            foreach ($distractors as $dtxt) { $dtxt = trim((string)$dtxt); if ($dtxt !== '') $opts[] = $dtxt; }
            $out .= '<div class="ws-qb"><div class="ws-qt"><span class="qnum">'.($wi+1).'</span>What does &ldquo;<strong>'.h($word).'</strong>&rdquo; mean?</div>';
            if (!empty($opts)) {
                $out .= '<div class="ws-opts">';
                foreach ($opts as $oi => $o) {
                    $ck_cls = ($k && $o === $correct) ? ' ws-ck' : '';
                    $out .= '<div class="ws-opt'.$ck_cls.'"><span class="opt-l">'.($ltrs[$oi] ?? chr(65+$oi)).'</span>'.h($o).'</div>';
                }
                $out .= '</div>';
            } else {
                $out .= '<div class="ws-open-lines">'.str_repeat('<div class="ws-open-line"></div>', 2).'</div>';
            }
            $out .= '</div>';
        }
    }
    return $out.ws_foot();
}

/* ── BUILD SECTIONS ──────────────────────────────────────── */
$sections = []; $actN = 0;
foreach ($activities as $act) {
    $type = strtolower(trim((string)($act['type'] ?? '')));
    $data = ws_decode($act['data'] ?? null);
    $actN++;
    switch ($type) {
        case 'flashcards':           $html = ws_flashcards($data, $actN, $isKey); break;
        case 'quiz':                 $html = ws_quiz($data, $actN, $isKey);        break;
        case 'multiple_choice':      $html = ws_mc($data, $actN, $isKey);          break;
        case 'drag_drop':            $html = ws_dragdrop($data, $actN, $isKey);    break;
        case 'writing_practice':     $html = ws_writing($data, $actN, $isKey);     break;
        case 'match':                $html = ws_match($data, $actN, $isKey);       break;
        case 'matching_lines':       $html = ws_matching_lines($data, $actN, $isKey); break;
        case 'order_sentences':      $html = ws_order($data, $actN, $isKey);       break;
        case 'listen_order':         $html = ws_listenorder($data, $actN, $isKey); break;
        case 'memory_cards':         $html = ws_memory($data, $actN, $isKey);      break;
        case 'video_comprehension':  $html = ws_video($data, $actN, $isKey);       break;
        case 'dictation':            $html = ws_dictation($data, $actN, $isKey);   break;
        case 'pronunciation':        $html = ws_pronunciation($data, $actN, $isKey); break;
        case 'powerpoint':           $html = ws_notes($data, $actN, 'Presentation', $isKey); break;
        case 'external':             $html = ws_notes($data, $actN, 'External Resource', $isKey); break;
        case 'reading_comprehension':$html = ws_reading($data, $actN, $isKey);    break;
        default:
            /* skip non-printable silently — hangman/crossword/coloring/dot_to_dot/tracing
               already excluded by SQL, but catch any extras */
            $actN--; continue 2;
    }
    $sections[] = ['type' => $type, 'html' => $html];
}

$today    = date('F j, Y');
$actCount = count($sections);
// $isKey already set above via mode param
?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1.0">
<title><?= h($unitName) ?> — Worksheet</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Fredoka:wght@500;600;700&family=Nunito:wght@400;600;700;800&display=swap" rel="stylesheet">
<style>
/* ════════════════════════════════════════════════════════
   ONES Worksheet — palette: white · #F97316 · #7F77DD · #1a1a2e
   ════════════════════════════════════════════════════════ */
@page { size: letter; margin: 12mm 11mm; }

:root {
  --ora:   #F97316;
  --lila:  #7F77DD;
  --lila2: #EDE9FA;   /* very light lila — borders, bg tints */
  --lila3: #9B8FCC;   /* mid lila — muted text */
  --ink:   #1a1a2e;   /* near-black */
  --muted: #5a5a7a;
  --white: #ffffff;
  --r:     12px;
}

*, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
html { -webkit-print-color-adjust: exact; print-color-adjust: exact; }
body { font-family: 'Nunito', 'Segoe UI', Arial, sans-serif;
       font-size: 13px; line-height: 1.6; color: var(--ink);
       background: #e0e0e0; }

/* ── Toolbar (screen only) ── */
.toolbar { position: sticky; top: 0; z-index: 100;
           background: var(--ink); display: flex; align-items: center;
           gap: 12px; padding: 9px 22px;
           box-shadow: 0 2px 12px rgba(0,0,0,.25); }
.tb-brand { font-family: 'Fredoka', sans-serif; font-weight: 700; font-size: 15px;
            color: var(--ora); margin-right: auto; }
.tb-unit  { font-size: 12px; color: rgba(255,255,255,.55);
            max-width: 300px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.tb-badge { font-size: 10px; font-weight: 800; padding: 3px 11px;
            border-radius: 20px; text-transform: uppercase; letter-spacing: .06em; }
.b-ws  { background: var(--ora);  color: #fff; }
.b-key { background: var(--lila); color: #fff; }
.btn-print { background: var(--lila); color: #fff; border: none; border-radius: 8px;
             padding: 7px 16px; font-size: 12px; font-weight: 700; font-family: inherit;
             cursor: pointer; display: inline-flex; align-items: center; gap: 6px; }
.btn-print:hover { filter: brightness(1.1); }

/* ── Document shell ── */
.ws-doc { max-width: 880px; margin: 22px auto 52px;
          background: var(--white); border-radius: 4px;
          box-shadow: 0 12px 40px rgba(0,0,0,.10); overflow: hidden; }

/* ══════════════════════════════════════════
   HEADER — white, orange bottom border
   ══════════════════════════════════════════ */
.doc-header { padding: 18px 30px 14px; border-bottom: 2px solid var(--ora);
              display: flex; align-items: center; justify-content: space-between;
              background: var(--white); }
.lockup { display: flex; align-items: center; gap: 14px; }
.ones-text { font-family: 'Fredoka', sans-serif; font-weight: 700; font-size: 30px;
             color: var(--ora); line-height: 1; letter-spacing: -.5px; }
.tagline { font-size: 9px; font-weight: 800; color: var(--lila);
           letter-spacing: 2.5px; font-family: 'Nunito', sans-serif; }
.byline-row { display: flex; align-items: center; gap: 5px; margin-top: 3px; }
.byline-line { width: 16px; height: 1.5px; background: var(--lila2); border-radius: 2px; }
.byline { font-size: 9.5px; font-weight: 600; color: var(--lila3); letter-spacing: .3px; }
.header-right { display: flex; flex-direction: column; align-items: flex-end; gap: 5px; }
.ws-badge { background: var(--white); border: 2px solid var(--ora); border-radius: 20px;
            padding: 3px 13px; font-size: 9.5px; font-weight: 800;
            color: var(--ora); letter-spacing: .07em; text-transform: uppercase; }
.ws-date  { font-size: 9.5px; color: var(--lila3); font-weight: 700; }

/* ── Course bar — lila background ── */
.course-bar { background: var(--lila); padding: 7px 30px;
              display: flex; align-items: center; justify-content: space-between; }
.cb-pill { background: rgba(255,255,255,.2); border: 1px solid rgba(255,255,255,.38);
           border-radius: 20px; padding: 3px 12px; font-size: 9.5px;
           font-weight: 800; color: #fff; letter-spacing: .04em; }
.cb-sep  { font-size: 10px; color: rgba(255,255,255,.38); margin: 0 4px; }
.cb-course { font-size: 10.5px; font-weight: 700; color: rgba(255,255,255,.72); }
.cb-unit   { font-size: 10.5px; font-weight: 800; color: #fff; }
.cb-count  { font-size: 9px; font-weight: 700; color: rgba(255,255,255,.6); }

/* ── Body ── */
.ws-body { padding: 22px 30px 30px; }

/* ── Unit hero ── */
.unit-hero { border: 1.5px solid var(--lila2); border-radius: 14px;
             padding: 14px 18px; margin-bottom: 18px;
             display: flex; align-items: flex-start;
             justify-content: space-between; gap: 14px; }
.unit-eyebrow { font-size: 8.5px; font-weight: 800; text-transform: uppercase;
                letter-spacing: .15em; color: var(--lila3); margin-bottom: 4px; }
.unit-title { font-family: 'Fredoka', sans-serif; font-weight: 700; font-size: 18px;
              color: var(--ink); line-height: 1.2; margin-bottom: 3px; }
.unit-sub { font-size: 10px; color: var(--lila3); font-weight: 600; }
.unit-chips { display: flex; gap: 6px; margin-top: 8px; flex-wrap: wrap; }
.uchip { border-radius: 20px; padding: 2px 10px; font-size: 9px; font-weight: 700;
         border: 1.5px solid var(--ora); color: var(--ora); }
.uchip.lila { border-color: var(--lila); color: var(--lila); }
.grade-badge { background: var(--lila); border-radius: 10px; padding: 7px 14px; text-align: center; flex-shrink: 0; }
.gb-lbl { font-size: 7.5px; font-weight: 800; text-transform: uppercase;
          letter-spacing: .12em; color: rgba(255,255,255,.7); display: block; margin-bottom: 2px; }
.gb-val { font-family: 'Fredoka', sans-serif; font-weight: 700; font-size: 15px; color: #fff; }

/* ── Student fields ── */
.student-grid { display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 10px; margin-bottom: 16px; }
.sf { border: 1.5px solid var(--lila2); border-radius: 10px; padding: 9px 12px; }
.sf-lbl { font-size: 7.5px; font-weight: 800; text-transform: uppercase;
          letter-spacing: .13em; color: var(--lila3); display: block; margin-bottom: 11px; }
.sf-line { border-bottom: 2px solid var(--lila2); }

/* ── Instruction row ── */
.instr-row { border: 1.5px solid var(--lila2); border-radius: 10px; padding: 9px 14px;
             margin-bottom: 22px; font-size: 10.5px; color: var(--lila);
             font-weight: 600; display: flex; align-items: flex-start; gap: 9px; line-height: 1.5; }
.ins-dot { flex-shrink: 0; width: 20px; height: 20px; background: var(--lila); border-radius: 50%;
           display: flex; align-items: center; justify-content: center; margin-top: 1px; }

/* ══════════════════════════════════════════
   ACTIVITY SECTIONS
   ══════════════════════════════════════════ */
.ws-sec { margin-bottom: 20px; break-inside: avoid; page-break-inside: avoid; }
.ws-sec:last-child { margin-bottom: 0; }

.sec-head { display: flex; align-items: center; gap: 10px; margin-bottom: 9px; }
.snum { width: 32px; height: 32px; border-radius: 50%; display: flex;
        align-items: center; justify-content: center; font-family: 'Fredoka', sans-serif;
        font-size: 15px; font-weight: 700; color: #fff; flex-shrink: 0; }
.snum.ora  { background: var(--ora); }
.snum.lila { background: var(--lila); }
.sec-kicker { font-size: 8px; font-weight: 800; text-transform: uppercase;
              letter-spacing: .14em; color: var(--lila3); }
.sec-title  { font-size: 12.5px; font-weight: 700; color: var(--ink); margin-top: 1px; }
.key-tag { background: var(--lila); color: #fff; font-size: 8.5px; padding: 2px 7px;
           border-radius: 10px; margin-left: 6px; vertical-align: middle;
           font-weight: 700; letter-spacing: .04em; }

/* ── Card ── */
.card-box { border: 1.5px solid var(--lila2); border-radius: 13px; padding: 13px 16px; }
/* Writing/fill/dictation use open layout — no enclosing rectangle */
.card-open { padding: 0; }
.ibox { display: flex; align-items: flex-start; gap: 7px; background: var(--lila2);
        border-radius: 8px; padding: 7px 10px; margin-bottom: 11px; }
.ilbl { font-size: 8px; font-weight: 800; text-transform: uppercase;
        letter-spacing: .1em; color: var(--lila); white-space: nowrap; margin-top: 1px; }
.itxt { font-size: 10px; color: var(--ink); line-height: 1.5; }
/* In open-layout sections the instruction is a plain line, no background box */
.card-open .ibox { background: transparent; border-radius: 0; padding: 0 0 10px;
                    border-bottom: 1.5px solid var(--lila2); margin-bottom: 16px; }
.card-open .ilbl { color: var(--ora); }

/* ── Tables ── */
table.ws-tbl { width: 100%; border-collapse: collapse; font-size: 11px;
               border: 1px solid var(--lila2); border-radius: 10px; overflow: hidden; }
table.ws-tbl th { background: var(--lila2); padding: 7px 11px; text-align: left;
                  font-size: 8.5px; font-weight: 800; text-transform: uppercase;
                  letter-spacing: .1em; color: var(--lila); border: 1px solid var(--lila2); }
table.ws-tbl td { padding: 9px 11px; border: 1px solid var(--lila2); vertical-align: middle; }
table.ws-tbl .tr-alt td { background: #f9f8ff; }
.tc-n  { width: 28px; text-align: center; color: var(--lila3); font-weight: 700; }
.tc-w  { font-weight: 700; }
.tc-bl { background: #f9f8ff; min-width: 120px; }

/* ── Question blocks ── */
.ws-qb { margin-bottom: 14px; break-inside: avoid; page-break-inside: avoid; }
.ws-qb:last-child { margin-bottom: 0; }
.ws-qt { font-weight: 700; font-size: 11.5px; line-height: 1.5; margin-bottom: 7px;
         display: flex; align-items: flex-start; gap: 8px; }
.qnum  { width: 22px; height: 22px; border-radius: 50%; background: var(--ink); color: #fff;
         display: flex; align-items: center; justify-content: center; font-size: 9.5px;
         font-weight: 800; flex-shrink: 0; margin-top: 1px; }
.ws-audio { color: var(--lila); font-style: italic; font-size: 11px; }

/* ── Options grid ── */
.ws-opts { display: grid; grid-template-columns: 1fr 1fr;
           gap: 6px 12px; margin-top: 8px; padding-left: 30px; }
.ws-opt  { display: flex; align-items: center; gap: 7px; border: 1.5px solid var(--lila2);
           border-radius: 9px; padding: 7px 10px; font-size: 11px; min-height: 38px; }
.opt-l   { width: 22px; height: 22px; border-radius: 50%; border: 1.5px solid var(--lila2);
           color: var(--lila3); display: flex; align-items: center; justify-content: center;
           font-weight: 800; flex-shrink: 0; font-size: 10px; }
.ws-opt-thumb { width: 30px; height: 30px; object-fit: contain; border-radius: 6px;
           flex-shrink: 0; background: #fff; }
.ws-opt-txt { flex: 1; }
.ws-ck   { background: #f0eeff; border-color: var(--lila); }
.ws-ck .opt-l { background: var(--lila); color: #fff; border-color: var(--lila); }
.ws-expl { font-size: 10.5px; color: var(--lila); background: var(--lila2);
           border-left: 3px solid var(--lila); padding: 4px 8px;
           margin-top: 5px; margin-left: 30px; border-radius: 0 6px 6px 0; }

/* ── Video Comprehension — same font/size as the rest of the worksheet ── */
.ws-qt--video { color: var(--ora); }
.qnum--video  { background: var(--ora); }
.ws-opt--video { border-color: #FCDDBF; background: #FFFAF5; }
.ws-opt--video .opt-l--video { border-color: var(--ora); color: var(--ora); }
.ws-opt--video.ws-ck { background: #f0eeff; border-color: var(--lila); }
.ws-opt--video.ws-ck .opt-l--video { background: var(--lila); color: #fff; border-color: var(--lila); }

/* ── Answer key highlight ── */
.ws-ans { color: var(--lila); font-weight: 700; }

/* ── Word bank ── */
.ws-bank { display: flex; flex-wrap: wrap; align-items: center; gap: 7px;
           padding: 10px 0 14px; border-bottom: 1.5px solid var(--lila2);
           margin-bottom: 16px; }
.ws-blbl { font-size: 9px; font-weight: 800; text-transform: uppercase;
           letter-spacing: .1em; color: var(--ora); margin-right: 6px; }
.ws-chip { padding: 5px 13px; border-radius: 999px; border: 1.5px solid var(--lila);
           background: var(--lila2); color: var(--ink); font-family: 'Fredoka', sans-serif;
           font-weight: 700; font-size: 13px; }
.ws-fr   { display: flex; align-items: baseline; gap: 10px; padding-bottom: 14px;
           border-bottom: 1.5px solid var(--lila2); font-size: 13px;
           line-height: 1.6; break-inside: avoid; }
.ws-fr:last-child { border-bottom: none; padding-bottom: 0; }
.ws-fn   { font-size: 11px; font-weight: 800; color: var(--lila3); min-width: 22px; }
.ws-fb   { flex: 1; }

/* ── Fill-in blanks ── */
.ws-fill-prompt { margin-left: 30px; font-size: 12.5px; line-height: 2.2;
                  word-break: break-word; color: var(--ink); }
.ws-inline-blank { display: inline-block;
                   min-width: calc(var(--bl, 10) * 0.65ch);
                   height: 1.2em; border-bottom: 2px solid var(--ora);
                   vertical-align: baseline; margin: 0 4px; }

/* ── Writing practice ── */
.ws-wb   { margin-bottom: 22px; break-inside: avoid; }
.ws-wb:last-child { margin-bottom: 0; }
.ws-wi   { font-size: 10.5px; color: var(--muted); font-style: italic; margin: 4px 0 10px 30px; }

/* Open writing lines — no enclosing box */
.ws-open-lines { display: flex; flex-direction: column; gap: 28px;
                 margin-top: 12px; padding: 0 4px; }
.ws-open-line  { position: relative; border-bottom: 1.5px solid var(--lila2); height: 32px; }
.ws-open-line::before { content: ''; position: absolute; left: 0; right: 0;
                         bottom: -16px; border-bottom: 1px dotted #EDE9FA; }

.ws-lines { display: flex; flex-direction: column; gap: 28px; margin-top: 12px; padding: 0 4px; }
.ws-line  { border-bottom: 1.5px solid var(--lila2); height: 32px; }
.ws-ab   { border-left: 3px solid var(--lila); padding: 6px 11px; margin-left: 30px; }
.ws-ma   { font-size: 11.5px; color: var(--lila); font-weight: 700; margin-bottom: 4px; }
.ws-ma:last-child { margin-bottom: 0; }

/* ── Match ── */
.mcols { display: grid; grid-template-columns: 1fr 1fr; gap: 22px; }
.mchd  { font-size: 8.5px; font-weight: 800; text-transform: uppercase;
          letter-spacing: .13em; color: var(--lila); border-bottom: 1.5px solid var(--lila2);
          padding-bottom: 5px; margin-bottom: 7px; }
.mrow  { display: flex; align-items: center; gap: 8px; padding: 8px 0;
         border-bottom: 1px solid var(--lila2); font-size: 11px; }
.mrow:last-child { border-bottom: none; }
.mn    { font-size: 10.5px; font-weight: 700; color: var(--lila3); min-width: 18px; }
.ml    { font-size: 10.5px; font-weight: 800; color: var(--ora);   min-width: 18px; }
.mbl   { flex: 0 0 30px; height: 1.5px; background: var(--lila3); border-radius: 2px; }
.mt    { flex: 1; line-height: 1.4; }

/* ── Order ── */
.ws-or { display: flex; align-items: center; gap: 12px; padding: 9px 4px;
         border-bottom: 1px solid var(--lila2); font-size: 12px; break-inside: avoid; }
.ws-or:last-child { border-bottom: none; }
.ws-ob { width: 32px; height: 26px; min-width: 32px; border: 1.5px solid var(--lila2);
         border-radius: 7px; flex-shrink: 0; }
.ws-ot { flex: 1; line-height: 1.45; }

/* ── Flashcard / memory grid ── */
.fc-grid { display: grid; grid-template-columns: repeat(4, 1fr); gap: 10px; }
.fc-card { border: 1.5px solid var(--lila2); border-radius: 12px; overflow: hidden; }
.fc-img  { height: 90px; background: var(--lila2); display: flex;
           align-items: center; justify-content: center; padding: 8px; }
.fc-img img { max-width: 100%; max-height: 74px; object-fit: contain; border-radius: 5px; }
.fc-img-txt  { font-weight: 700; font-size: 13px; color: var(--ink); text-align: center; }
.fc-word     { padding: 6px 8px 4px; font-size: 10.5px; font-weight: 700; color: var(--ink);
               text-align: center; border-top: 1px solid var(--lila2); background: #f9f8ff; }
.fc-word-blank { color: transparent; } /* hide word for student version if needed */
.fc-blank-zone { padding: 8px 10px 9px; border-top: 1px solid var(--lila2); }
.fc-blbl { font-size: 7px; font-weight: 800; text-transform: uppercase;
           letter-spacing: .1em; color: var(--lila3); display: block; margin-bottom: 8px; }
.fc-bline { border-bottom: 1.5px solid var(--lila2); }

/* ── Dictation — square image left, writing lines right ── */
.dt-item { display: flex; align-items: flex-start; gap: 20px; padding: 0 0 28px;
           margin-bottom: 28px; border-bottom: 1px solid var(--lila2);
           break-inside: avoid; page-break-inside: avoid; }
.dt-item:last-child { border-bottom: none; margin-bottom: 0; padding-bottom: 0; }
.dt-num  { min-width: 24px; font-weight: 800; color: var(--ink); font-size: 13px;
           padding-top: 2px; flex-shrink: 0; }
.dt-img  { width: 210px; height: 210px; min-width: 210px; flex: 0 0 210px;
           border-radius: 9px; overflow: hidden; border: 1px solid var(--lila2);
           background: #f9f8ff; display: flex; align-items: center; justify-content: center; }
.dt-img img { width: 100%; height: 100%; object-fit: contain; }
.dt-img-empty { background: #f9f8ff; }
.dt-write { flex: 1; display: flex; flex-direction: column; }
.dt-ans  { border: 1.5px solid var(--lila2); border-radius: 8px; padding: 5px 10px;
           font-size: 11.5px; margin-bottom: 12px; }
.dt-lines.ws-open-lines { gap: 21px; margin-top: 0; padding: 0; }
.dt-lines .ws-open-line { border-bottom: 2px solid #000; height: 21px; }
.dt-lines .ws-open-line::before { content: none; }

/* ── Pronunciation ── */
.pr-grid  { display: grid; grid-template-columns: repeat(3, 1fr); gap: 12px; }
.pr-card  { border: 1.5px solid var(--lila2); border-radius: 12px; overflow: hidden;
            break-inside: avoid; }
.pr-img   { height: 100px; background: var(--lila2); display: flex;
            align-items: center; justify-content: center; padding: 8px; border-bottom: 1px solid var(--lila2); }
.pr-img img { max-width: 100%; max-height: 84px; object-fit: contain; border-radius: 5px; }
.pr-img-txt { font-weight: 700; font-size: 13px; color: var(--ink); text-align: center; }
.pr-fields  { padding: 8px 10px; display: flex; flex-direction: column; gap: 7px; }
.pr-field   { display: flex; flex-direction: column; gap: 3px; }
.pr-lbl     { font-size: 7.5px; font-weight: 800; text-transform: uppercase;
              letter-spacing: .1em; color: var(--lila3); }
.pr-blank   { border-bottom: 1.5px solid var(--lila2); height: 18px; }
.pr-ans     { font-size: 11px; font-weight: 700; color: var(--lila); }

/* ── Reading comprehension ── */
.rc-text { font-size: 11.5px; line-height: 1.7; border: 1.5px solid var(--lila2);
           border-radius: 10px; padding: 10px 13px; margin-bottom: 12px; color: var(--ink); }
.ws-rc-hl { color: #C2580A; font-weight: 800; border-bottom: 2px solid var(--ora);
            background: #FFF0E6; border-radius: 3px; padding: 0 2px; }

/* ── MC passage block (reading passage shown before questions) ── */
.ws-mc-passage { border: 1.5px solid var(--lila2); border-left: 4px solid var(--lila);
                 border-radius: 10px; background: #F8F7FE; padding: 11px 14px;
                 margin-bottom: 14px; break-inside: avoid; page-break-inside: avoid; }
.ws-mc-passage-lbl { font-size: 9px; font-weight: 800; text-transform: uppercase;
                     letter-spacing: .07em; color: var(--lila); margin-bottom: 7px; }
.ws-mc-passage-body { font-size: 12px; line-height: 1.75; color: var(--ink); white-space: pre-wrap; word-break: break-word; }

/* ── MC image options ── */
.mc-img-opts { display: grid; grid-template-columns: repeat(3, 1fr); gap: 9px; margin-top: 7px; }
.mc-img-opt  { border: 1.5px solid var(--lila2); border-radius: 11px; overflow: hidden;
               display: flex; flex-direction: column; align-items: center; padding: 6px; break-inside: avoid; }
.mc-img-opt .mc-frame { width: 100%; height: 90px; display: flex; align-items: center;
                         justify-content: center; padding: 5px; }
.mc-img-opt .mc-frame img { max-width: 100%; max-height: 78px; object-fit: contain; }
.mc-img-opt .opt-l { font-weight: 800; color: var(--ink); font-size: 11px; margin-bottom: 4px; }
.mc-img-opt.ws-ck { background: #f0eeff; border-color: var(--lila); }
.mc-qimg img { max-width: 100%; max-height: 120px; border-radius: 8px;
               border: 1px solid var(--lila2); margin: 6px 0; }

/* ── Notes box (presentation / external) ── */
.notes-box { min-height: 200px; border: 1.5px dashed var(--lila2); border-radius: 13px; }

/* ── Empty / placeholder ── */
.ws-empty { font-size: 11px; color: var(--muted); font-style: italic; padding: 8px 0; }

/* ── Page divider (screen) ── */
.ws-pdiv { border: none; border-top: 2px dashed var(--lila2); margin: 26px 0; }
.ws-plbl { text-align: center; margin: -11px 0 26px; }
.ws-plbl span { background: #fff; padding: 0 14px; font-size: 9.5px; font-weight: 800;
                text-transform: uppercase; letter-spacing: .14em; color: var(--lila2); }

/* ── Legibility: activity text (instructions/labels/questions/content)
   uses Verdana Bold 14px. Titles, header and branding keep their own
   font-family (Fredoka) and are not affected. ── */
body,
.itxt, .instr-row, .ws-qt, .ws-wi, .ws-opt, .dt-ans, .ws-ans, .mt, .ws-ot,
.rc-text, .pr-ans, .ws-fr, .ws-fill-prompt, .ws-ma, .ws-expl, .ws-audio {
  font-family: 'Verdana', 'Nunito', 'Segoe UI', Arial, sans-serif;
  font-weight: 700;
}
body { font-size: 14px; }
.itxt, .instr-row, .ws-qt, .ws-wi, .ws-opt, .dt-ans, .ws-ans, .mt, .ws-ot,
.rc-text, .pr-ans, .ws-fr, .ws-fill-prompt, .ws-ma, .ws-expl, .ws-audio {
  font-size: 14px;
}

/* ── Page footer ── */
.page-footer { border-top: 1.5px solid var(--lila2); padding: 7px 30px;
               display: flex; align-items: center; justify-content: space-between; }
.ft-brand { font-size: 8.5px; font-weight: 700; color: var(--lila3); }
.ft-info  { font-size: 8.5px; color: var(--lila3); font-weight: 600; }
.ft-pg    { font-size: 8.5px; font-weight: 800; color: var(--ora); }

/* ════════════════════════════════════════════════════════
   PRINT
   ════════════════════════════════════════════════════════ */
@media print {
  html, body { width: 100%; background: #fff !important; }
  body { font-size: 12px; line-height: 1.4; }
  .toolbar, .ws-pdiv, .ws-plbl { display: none !important; }
  .ws-doc { box-shadow: none; border-radius: 0; max-width: 100%; margin: 0; }

  /* Header */
  .doc-header { padding: 0 0 8px; }
  .ones-text { font-size: 22px; }
  .tagline { font-size: 7px; letter-spacing: 2px; }
  .byline { font-size: 9.5px; }
  .ws-badge { font-size: 8px; padding: 2px 10px; }
  .ws-date  { font-size: 8px; }

  /* Course bar */
  .course-bar { padding: 5px 30px; }
  .cb-pill { font-size: 8px; padding: 2px 9px; }
  .cb-course, .cb-unit { font-size: 9px; }

  /* Body */
  .ws-body { padding: 8px 0 0; }

  /* Hero */
  .unit-hero { padding: 8px 11px; margin-bottom: 8px; border-radius: 8px; }
  .unit-eyebrow { font-size: 7px; }
  .unit-title { font-size: 13px; }
  .unit-sub { font-size: 10.5px; }
  .uchip { font-size: 7.5px; padding: 1px 8px; }
  .grade-badge { padding: 4px 10px; border-radius: 7px; }
  .gb-lbl { font-size: 6.5px; }
  .gb-val { font-size: 12px; }

  /* Student fields */
  .student-grid { gap: 6px; margin-bottom: 8px; }
  .sf { padding: 6px 9px; border-radius: 7px; }
  .sf-lbl { font-size: 6.5px; margin-bottom: 7px; }

  /* Instruction */
  .instr-row { padding: 6px 10px; font-size: 10.5px; margin-bottom: 10px; border-radius: 7px; }
  .ins-dot   { width: 15px; height: 15px; }
  .ins-dot svg { width: 8px; height: 8px; }

  /* Sections */
  .ws-sec { margin-bottom: 8px; break-inside: auto; page-break-inside: auto; }
  .sec-head { margin-bottom: 5px; }
  .snum { width: 24px; height: 24px; font-size: 11px; }
  .sec-kicker { font-size: 7px; }
  .sec-title  { font-size: 10px; }
  .key-tag    { font-size: 7px; padding: 1px 5px; }

  /* Card */
  .card-box { padding: 7px 9px; border-radius: 8px; }
  .ibox { padding: 5px 8px; margin-bottom: 7px; border-radius: 6px; }
  .card-open .ibox { padding: 0 0 7px; border-radius: 0; margin-bottom: 10px; }
  .ilbl { font-size: 7px; }
  .itxt { font-size: 10.5px; }

  /* Tables */
  table.ws-tbl { font-size: 10.5px; }
  table.ws-tbl th { padding: 4px 8px; font-size: 7px; }
  table.ws-tbl td { padding: 7px 8px; }
  .tc-n { width: 22px; }

  /* Questions */
  .ws-qb { margin-bottom: 7px; }
  .ws-qt { font-size: 9.5px; gap: 6px; }
  .qnum  { width: 17px; height: 17px; font-size: 8px; }
  .ws-opts { gap: 4px 8px; padding-left: 23px; margin-top: 5px; }
  .ws-opt  { padding: 5px 8px; font-size: 10.5px; min-height: 28px; border-radius: 7px; }
  .opt-l   { width: 17px; height: 17px; font-size: 8px; }
  .ws-expl { font-size: 10px; padding: 3px 6px; margin-left: 23px; }

  /* Word bank */
  .ws-bank { padding: 5px 0 10px; gap: 3px 5px; margin-bottom: 10px; }
  .ws-blbl { font-size: 7.5px; }
  .ws-chip { padding: 2px 9px; font-size: 11px; }
  .ws-fr   { font-size: 11px; padding-bottom: 10px; }
  .ws-fn   { font-size: 8.5px; }
  .ws-fill-prompt { margin-left: 23px; font-size: 11px; line-height: 2.0; }
  .ws-inline-blank { min-width: calc(var(--bl, 10) * 0.60ch); border-bottom-width: 1.8px; }

  /* Writing */
  .ws-wb  { margin-bottom: 10px; }
  .ws-wi  { font-size: 10px; margin: 2px 0 5px 23px; }
  .ws-open-lines { gap: 22px; margin-top: 8px; }
  .ws-open-line  { height: 24px; }
  .ws-open-line::before { bottom: -11px; }
  .ws-lines { gap: 22px; margin-top: 8px; }
  .ws-line  { height: 24px; border-bottom-width: 1.2px; }
  .ws-ab  { padding: 4px 8px; margin-left: 23px; }
  .ws-ma  { font-size: 9px; }

  /* Match */
  .mcols { gap: 14px; }
  .mchd  { font-size: 7.5px; padding-bottom: 4px; margin-bottom: 5px; }
  .mrow  { padding: 6px 0; font-size: 11px; }
  .mn,.ml { font-size: 9px; }
  .mbl   { flex: 0 0 24px; }

  /* Order */
  .ws-or { padding: 6px 4px; font-size: 11.5px; }
  .ws-ob { width: 26px; height: 20px; border-radius: 5px; }

  /* Flashcard grid */
  .fc-grid { gap: 6px; grid-template-columns: repeat(4,1fr); }
  .fc-card { border-radius: 8px; break-inside: avoid; page-break-inside: avoid; }
  .fc-img  { height: 100px; padding: 6px; }
  .fc-img img { max-height: 84px; }
  .fc-word { padding: 4px 6px; font-size: 9px; }
  .fc-blank-zone { padding: 6px 8px 7px; }
  .fc-blbl { font-size: 6.5px; margin-bottom: 6px; }

  /* Pronunciation */
  .pr-grid { gap: 7px; }
  .pr-img  { height: 100px; }
  .pr-img img { max-height: 84px; }
  .pr-fields { padding: 6px 8px; gap: 5px; }
  .pr-lbl    { font-size: 6.5px; }
  .pr-blank  { height: 14px; }

  /* Dictation — keep the reference layout (square image + lines) in the printed PDF */
  .dt-item { padding: 0 0 24px; margin-bottom: 24px; gap: 18px; }
  .dt-num  { font-size: 11px; min-width: 20px; }
  .dt-img  { width: 210px; height: 210px; min-width: 210px; flex: 0 0 210px; border-radius: 9px; }
  .dt-lines.ws-open-lines { gap: 20px; }
  .dt-lines .ws-open-line { height: 20px; border-bottom-width: 2px; }

  /* Notes box */
  .notes-box { min-height: 130px; border-radius: 7px; }

  /* RC text */
  .rc-text { font-size: 11px; padding: 7px 9px; }

  /* Break control */
  .ws-qb,.ws-wb,.ws-or,.dt-item { break-inside: avoid; page-break-inside: avoid; }
  .fc-card,.pr-card,.mc-img-opt  { break-inside: avoid; page-break-inside: avoid; }
  .sec-head { break-after: avoid; page-break-after: avoid; }
  .sec-head + .card-box { break-before: avoid; page-break-before: avoid; }
  .ibox { break-after: avoid; page-break-after: avoid; }
  .ibox + * { break-before: avoid; page-break-before: avoid; }

  /* Colour fidelity */
  .doc-header,.course-bar,.unit-hero,.snum,.qnum,.opt-l,.ws-ck,
  .ws-ck .opt-l,.key-tag,.ibox,.ws-bank,.ws-chip,.ws-expl,.ws-ab,
  .fc-img,.fc-word,.fc-blank-zone,.grade-badge,.ws-ob,.notes-box,
  .pr-img,.dt-img,.rc-text,.ws-or,.mc-img-opt,.mc-img-opt.ws-ck,
  .ws-qt--video,.qnum--video,.ws-opt--video,.opt-l--video
  { -webkit-print-color-adjust: exact; print-color-adjust: exact; }

  img { max-width: 100%; height: auto;
        -webkit-print-color-adjust: exact; print-color-adjust: exact; }
  a { color: inherit; text-decoration: none; }

  .page-footer { padding: 5px 0; }
  .ft-brand,.ft-info { font-size: 7.5px; }
  .ft-pg { font-size: 7.5px; }

  /* Legibility: keep Verdana Bold 14px for activity text in the printed/exported PDF */
  body { font-size: 14px; }
  .itxt, .instr-row, .ws-qt, .ws-wi, .ws-opt, .dt-ans, .ws-ans, .mt, .ws-ot,
  .rc-text, .pr-ans, .ws-fr, .ws-fill-prompt, .ws-ma, .ws-expl, .ws-audio {
    font-family: 'Verdana', 'Nunito', 'Segoe UI', Arial, sans-serif;
    font-weight: 700;
    font-size: 14px;
  }
}
</style>
</head>
<body>

<!-- ── Toolbar (screen only) ── -->
<div class="toolbar">
  <span class="tb-brand">ONES</span>
  <span class="tb-unit"><?= h($gradeName) ?> — <?= h($unitName) ?></span>
  <span class="tb-badge <?= $isKey ? 'b-key' : 'b-ws' ?>"><?= $isKey ? 'Answer Key' : 'Worksheet' ?></span>
  <button class="btn-print" onclick="window.print()">&#128424; Print / Save PDF</button>
</div>

<div class="ws-doc">

  <!-- ── Header ── -->
  <div class="doc-header">
    <div class="lockup">
      <svg width="54" height="54" viewBox="0 0 64 64" fill="none" xmlns="http://www.w3.org/2000/svg">
        <rect width="64" height="64" rx="17" fill="#FFF0E6"/>
        <circle cx="30" cy="28" r="15" fill="#F97316"/>
        <polygon points="23,41 15,53 37,46" fill="#F97316"/>
        <circle cx="30" cy="28" r="8" fill="#FFF0E6"/>
        <circle cx="42" cy="18" r="6" fill="#7F77DD"/>
        <circle cx="42" cy="18" r="3" fill="#ffffff"/>
      </svg>
      <div>
        <div class="ones-text">ONES</div>
        <div class="tagline">ONLINE ENGLISH SOLUTION</div>
        <div class="byline-row">
          <div class="byline-line"></div>
          <span class="byline">by Let&rsquo;s Institute</span>
        </div>
      </div>
    </div>
    <div class="header-right">
      <div class="ws-badge"><?= $isKey ? 'Answer Key' : 'Worksheet' ?></div>
      <div class="ws-date"><?= h($today) ?></div>
    </div>
  </div>

  <!-- ── Course bar ── -->
  <div class="course-bar">
    <div style="display:flex;align-items:center">
      <?php if ($gradeName !== ''): ?>
        <div class="cb-pill"><?= h($gradeName) ?></div>
        <span class="cb-sep">·</span>
      <?php endif; ?>
      <?php if ($unitLevel !== ''): ?>
        <div class="cb-course"><?= h($unitLevel) ?></div>
        <span class="cb-sep">·</span>
      <?php endif; ?>
      <div class="cb-unit"><?= h($unitName) ?></div>
    </div>
    <div class="cb-count"><?= $actCount ?> activit<?= $actCount === 1 ? 'y' : 'ies' ?></div>
  </div>

  <!-- ── Body ── -->
  <div class="ws-body">

    <!-- Unit hero -->
    <div class="unit-hero">
      <div>
        <div class="unit-eyebrow">Online English Solution &middot; Let&rsquo;s Institute</div>
        <div class="unit-title"><?= h($unitName) ?></div>
        <?php
          $subParts = array_filter([$unitLevel, $unitModule, $gradeName]);
          if ($subParts):
        ?>
          <div class="unit-sub"><?= h(implode(' · ', $subParts)) ?></div>
        <?php endif; ?>
        <div class="unit-chips">
          <span class="uchip"><?= $actCount ?> activit<?= $actCount === 1 ? 'y' : 'ies' ?></span>
          <span class="uchip lila">Print &amp; complete</span>
          <?php if ($isKey): ?>
            <span class="uchip lila">&#10003; Answer Key</span>
          <?php endif; ?>
        </div>
      </div>
      <?php if ($gradeName !== ''): ?>
        <div class="grade-badge">
          <span class="gb-lbl">Course</span>
          <span class="gb-val"><?= h(mb_substr($gradeName, 0, 8, 'UTF-8')) ?></span>
        </div>
      <?php endif; ?>
    </div>

    <!-- Student fields -->
    <div class="student-grid">
      <div class="sf"><span class="sf-lbl">Student name</span><div class="sf-line"></div></div>
      <div class="sf"><span class="sf-lbl">Date</span><div class="sf-line"></div></div>
      <div class="sf"><span class="sf-lbl">Group / Level</span><div class="sf-line"></div></div>
    </div>

    <!-- General instructions -->
    <div class="instr-row">
      <div class="ins-dot">
        <svg width="10" height="10" viewBox="0 0 12 12" fill="none">
          <circle cx="6" cy="6" r="5" stroke="#fff" stroke-width="1.2"/>
          <path d="M6 5v4M6 3v1" stroke="#fff" stroke-width="1.3" stroke-linecap="round"/>
        </svg>
      </div>
      Read each section carefully and complete all activities. Write your answers clearly in the spaces provided.
      <?php if ($isKey): ?> <strong>This is the Answer Key &mdash; correct answers are highlighted.</strong><?php endif; ?>
    </div>

    <!-- Activities -->
    <?php if (empty($sections)): ?>
      <p style="text-align:center;color:var(--muted);font-style:italic;padding:40px 0">
        No printable activities found for this unit.
      </p>
    <?php else: ?>
      <?php foreach ($sections as $idx => $sec): ?>
        <?php if ($idx > 0 && $idx % 4 === 0): ?>
          <hr class="ws-pdiv">
          <div class="ws-plbl"><span>&mdash; Page <?= floor($idx / 4) + 1 ?> &mdash;</span></div>
        <?php endif; ?>
        <?= $sec['html'] ?>
      <?php endforeach; ?>
    <?php endif; ?>

  </div><!-- /ws-body -->

  <!-- Footer -->
  <div class="page-footer">
    <div class="ft-brand">ONES &mdash; Online English Solution &nbsp;&middot;&nbsp; Let&rsquo;s Institute</div>
    <div class="ft-info"><?= h($gradeName) ?> &nbsp;&middot;&nbsp; <?= h($unitName) ?></div>
    <div class="ft-pg">Page 1</div>
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
