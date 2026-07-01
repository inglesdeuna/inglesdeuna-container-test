<?php
/*
 * Worksheet PDF visual wrapper.
 * Keeps the original data fetch, activity order, paging and student/key mode in
 * unit_pdf_base.php. This wrapper only patches printable rendering/visual output.
 */

$basePath = __DIR__ . '/unit_pdf_base.php';
$source = file_get_contents($basePath);
if ($source === false) {
    die('Worksheet renderer not found.');
}

// Memory Cards is interactive-only and should not appear in PDF exports.
$source = str_replace(
    '$SKIP_TYPES = [\'flipbooks\',\'hangman\',\'crossword\',\'coloring\',\'dot_to_dot\',\'tracing\'];',
    '$SKIP_TYPES = [\'flipbooks\',\'hangman\',\'crossword\',\'coloring\',\'dot_to_dot\',\'tracing\',\'memory_cards\'];',
    $source
);
$source = str_replace(
    "case 'memory_cards':         \$html = ws_memory(\$data, \$actN, \$isKey);      break;",
    "case 'memory_cards':         \$actN--; continue 2;",
    $source
);

$headPatch = <<<'PHP'
function ws_head(int $n, string $kicker, string $title, string $instr, bool $isKey, string $cardClass = ''): string {
    $c = sec_color($n);
    $cls = 'card-box card' . ($cardClass !== '' ? ' '.$cardClass : '');
    $out = '<div class="ws-sec section">';
    $out .= '<div class="sec-head section-head"><div class="snum num '.$c.'">'.$n.'</div>';
    $out .= '<div class="sec-meta"><div class="sec-kicker kind">'.h($kicker);
    if ($isKey) $out .= '<span class="key-tag">Answer Key</span>';
    $out .= '</div>';
    if ($title !== '') $out .= '<h2 class="sec-title">'.h($title).'</h2>';
    $out .= '</div></div>';
    if ($instr !== '') $out .= '<p class="instructions">'.h($instr).'</p>';
    $out .= '<div class="'.$cls.'">';
    return $out;
}
PHP;
$source = preg_replace(
    '/function\s+ws_head\s*\(int\s+\$n,\s*string\s+\$kicker,\s*string\s+\$title,\s*string\s+\$instr,\s*bool\s+\$isKey,\s*string\s+\$cardClass\s*=\s*\'\'\):\s*string\s*\{.*?\n\}/s',
    $headPatch,
    $source,
    1
);

$readingWritingPatch = <<<'PHP'
function pdf_pick(array $a, array $keys, string $fallback = ''): string {
    foreach ($keys as $key) {
        if (isset($a[$key]) && trim((string)$a[$key]) !== '') return trim((string)$a[$key]);
    }
    return $fallback;
}
function pdf_list($raw): array {
    if (is_array($raw)) {
        $out = [];
        foreach ($raw as $v) {
            $s = trim((string)$v);
            if ($s !== '') $out[] = $s;
        }
        return $out;
    }
    $s = trim((string)$raw);
    if ($s === '') return [];
    $parts = preg_split('/\s*(?:,|\n|;|\|)\s*/', $s) ?: [];
    return array_values(array_filter(array_map('trim', $parts), static fn($v) => $v !== ''));
}
function rc_pdf_text(array $d): array {
    if (isset($d['texts']) && is_array($d['texts']) && !empty($d['texts'])) {
        $t = is_array($d['texts'][0]) ? $d['texts'][0] : [];
        if (!isset($t['root_title'])) $t['root_title'] = trim((string)($d['title'] ?? ''));
        return $t;
    }
    return $d;
}
function rc_pdf_body(array $t, array $d): string {
    return pdf_pick($t, ['body','text','passage','content'], pdf_pick($d, ['body','text','passage','content'], ''));
}
function rc_pdf_words(array $t, array $d): array {
    $raw = is_array($t['words'] ?? null) ? $t['words'] : (is_array($d['words'] ?? null) ? $d['words'] : []);
    $out = [];
    foreach ($raw as $w) {
        if (!is_array($w)) continue;
        $word = pdf_pick($w, ['word','text','term']);
        if ($word === '') continue;
        $options = [];
        if (isset($w['options']) && is_array($w['options'])) {
            foreach ($w['options'] as $op) { $op = trim((string)$op); if ($op !== '') $options[] = $op; }
        } else {
            $correct = pdf_pick($w, ['correct','meaning','definition']);
            if ($correct !== '') $options[] = $correct;
            $distractors = is_array($w['distractors'] ?? null) ? $w['distractors'] : [];
            foreach ($distractors as $dist) { $dist = trim((string)$dist); if ($dist !== '') $options[] = $dist; }
        }
        $out[] = ['word' => $word, 'options' => $options];
    }
    return $out;
}
function rc_pdf_questions(array $t, array $d): array {
    $raw = is_array($t['questions'] ?? null) ? $t['questions'] : (is_array($d['questions'] ?? null) ? $d['questions'] : []);
    $out = [];
    foreach ($raw as $q) {
        if (!is_array($q)) continue;
        $stem = pdf_pick($q, ['stem','question','prompt']);
        $options = is_array($q['options'] ?? null) ? array_values(array_filter(array_map('trim', array_map('strval', $q['options'])))) : [];
        if ($stem === '' && empty($options)) continue;
        $out[] = ['stem' => $stem, 'options' => $options, 'type' => empty($options) ? 'open' : 'mc'];
    }
    return $out;
}
function rc_pdf_highlight(string $body, array $words, bool $numbered): string {
    $html = nl2br(h($body !== '' ? $body : 'No passage text configured.'));
    $terms = [];
    foreach ($words as $idx => $w) {
        $term = trim((string)($w['word'] ?? ''));
        if ($term !== '') $terms[] = ['term' => $term, 'num' => $idx + 1];
    }
    usort($terms, static fn($a, $b) => strlen($b['term']) <=> strlen($a['term']));
    foreach ($terms as $item) {
        $safe = preg_quote(h($item['term']), '/');
        if ($safe === '') continue;
        $badge = $numbered ? '<sup class="rc-num">'.$item['num'].'</sup>' : '';
        $pattern = preg_match('/\s/', $item['term']) ? '/(' . $safe . ')/iu' : '/\b(' . $safe . ')\b/iu';
        $html = preg_replace($pattern, '<span class="rc-word">$1</span>'.$badge, $html, 1);
    }
    return $html;
}
function ws_reading(array $d, int $n, bool $k): string {
    $t = rc_pdf_text($d);
    $mode = strtolower(trim((string)($t['mode'] ?? $d['mode'] ?? 'vocab')));
    $isComp = in_array($mode, ['comp','comprehension','reading_comprehension'], true);
    $title = pdf_pick($t, ['title','root_title'], pdf_pick($d, ['title'], 'Reading Comprehension'));
    $genre = pdf_pick($t, ['genre'], 'Informational text');
    $body = rc_pdf_body($t, $d);
    $words = rc_pdf_words($t, $d);
    $questions = rc_pdf_questions($t, $d);
    $level = pdf_pick($t, ['level'], pdf_pick($d, ['level'], ''));
    $wordCount = (int)($t['wordCount'] ?? $d['wordCount'] ?? 0);
    if ($wordCount <= 0 && $body !== '') $wordCount = count(preg_split('/\s+/', trim($body)) ?: []);

    $kicker = $isComp ? 'READING COMPREHENSION' : 'READING COMPREHENSION · VOCAB MEANING';
    $instr = $isComp ? 'Read the passage carefully and answer the questions.' : 'Circle the correct meaning for each highlighted word.';
    $out = ws_head($n, $kicker, $title, $instr, $k, 'card-open rc-print-card '.($isComp ? 'rc-comp' : 'rc-vocab'));
    $out .= '<div class="rc-kicker">'.h($kicker).'</div>';
    $out .= '<h3 class="rc-title">'.h($title).'</h3>';
    $meta = 'Genre: '.$genre.($wordCount > 0 ? ' · '.$wordCount.' words' : '').($level !== '' ? ' · Level: '.$level : '');
    if (!$isComp) $meta .= ' · Circle the correct meaning for each highlighted word';
    $out .= '<div class="rc-meta">'.h($meta).'</div>';
    $out .= '<div class="rc-passage">'.rc_pdf_highlight($body, $words, !$isComp).'</div>';

    if ($isComp) {
        if (!empty($words)) {
            $out .= '<div class="rc-vocab-box"><div class="rc-box-title">Vocabulary — write the meaning of each word</div><div class="rc-meaning-grid">';
            foreach ($words as $w) {
                $out .= '<div class="rc-meaning-item"><span>'.h($w['word']).'</span><span class="dotline"></span></div>';
            }
            $out .= '</div></div>';
        }
        $out .= '<div class="rc-section-label">Comprehension questions</div>';
        if (empty($questions)) $out .= '<div class="notes-box"></div>';
        foreach ($questions as $qi => $q) {
            $out .= '<div class="rc-question"><div class="rc-q-stem"><strong>'.($qi + 1).'.</strong> '.h($q['stem']).'</div>';
            if ($q['type'] === 'mc') {
                $out .= '<div class="rc-options">';
                foreach ($q['options'] as $oi => $op) {
                    $label = preg_match('/^[A-D][\.)]\s*/i', $op) ? $op : (chr(65 + $oi).'. '.$op);
                    $out .= '<div class="rc-option"><span class="radio"></span>'.h($label).'</div>';
                }
                $out .= '</div>';
            } else {
                $out .= '<div class="rc-open-lines"><div class="dotline"></div><div class="dotline"></div></div>';
            }
            $out .= '</div>';
        }
    } else {
        $out .= '<div class="rc-section-label purple">What do the highlighted words mean?</div>';
        foreach ($words as $i => $w) {
            $out .= '<div class="rc-vocab-card"><div class="rc-card-word"><span class="rc-num solid">'.($i + 1).'</span><strong>'.h($w['word']).'</strong></div>';
            foreach ($w['options'] as $op) {
                $out .= '<div class="rc-vocab-option"><span class="radio"></span>'.h($op).'</div>';
            }
            $out .= '</div>';
        }
        $out .= '<div class="rc-bonus"><strong>BONUS — use one highlighted word in your own sentence</strong><div class="dotline"></div></div>';
    }
    return $out.ws_foot();
}
function wp_pdf_items(array $d): array {
    if (isset($d['items']) && is_array($d['items'])) return array_values($d['items']);
    if (isset($d['questions']) && is_array($d['questions'])) return array_values($d['questions']);
    return [$d];
}
function wp_pdf_word_bank(array $d, array $item): array {
    foreach (['word_bank','wordBank','keywords','vocabulary','words'] as $key) {
        if (isset($item[$key])) { $list = pdf_list($item[$key]); if ($list) return $list; }
        if (isset($d[$key])) { $list = pdf_list($d[$key]); if ($list) return $list; }
    }
    return [];
}
function ws_writing(array $d, int $n, bool $k): string {
    $items = wp_pdf_items($d);
    $title = pdf_pick($d, ['title'], 'Writing Practice');
    $out = ws_head($n, 'WRITING PRACTICE', $title, '', $k, 'card-open wp-print');
    $out .= '<div class="wp-kicker">WRITING PRACTICE</div>';
    $out .= '<h3 class="wp-title">'.h($title).'</h3>';
    foreach ($items as $idx => $item) {
        if (!is_array($item)) continue;
        $source = pdf_pick($item, ['prompt_text','prompt','question','source_text','text','instruction'], pdf_pick($d, ['prompt_text','prompt','source_text'], ''));
        if ($source === '' && count($items) > 1) continue;
        $bank = wp_pdf_word_bank($d, $item);
        if ($idx > 0) $out .= '<div class="wp-divider"></div>';
        $out .= '<div class="wp-source"><div class="wp-source-label">SOURCE TEXT — read and translate into English</div><div class="wp-source-text">'.nl2br(h($source)).'</div></div>';
        if (!empty($bank)) {
            $out .= '<div class="wp-use">Use at least four words from the box below in your translation.</div>';
            $out .= '<div class="wp-bank"><div class="wp-bank-label">WORD BANK</div><div>'.h(implode(' · ', $bank)).'</div></div>';
        }
        $out .= '<div class="wp-your">Your translation</div>';
        $out .= '<div class="wp-lines">'.str_repeat('<div class="writeline"></div>', 6).'</div>';
    }
    $out .= '<div class="wp-self"><div class="wp-self-title">SELF-CHECK — after your teacher reviews this page</div>';
    $out .= '<span class="check green"></span>Correct words <span class="check coral"></span>Missing words <span class="check orange"></span>Extra words</div>';
    return $out.ws_foot();
}
PHP;
$source = preg_replace('/function\s+ws_reading\s*\(array\s+\$d,\s*int\s+\$n,\s*bool\s+\$k\):\s*string\s*\{.*?\n\}\n\n\/\* ── BUILD SECTIONS/s', $readingWritingPatch . "\n\n/* ── BUILD SECTIONS", $source, 1);
$source = preg_replace('/function\s+ws_writing\s*\(array\s+\$d,\s*int\s+\$n,\s*bool\s+\$k\):\s*string\s*\{.*?\n\}\n\n\/\* ── MATCH/s', "/* writing replaced later */\n\n/* ── MATCH", $source, 1);

$roleplayPatch = <<<'PHP'
function rp_pdf_scene(array $d): array {
    $scene = isset($d['scene']) && is_array($d['scene']) ? $d['scene'] : $d;
    return [
        'title' => pdf_pick($scene, ['title'], pdf_pick($d, ['title'], 'Roleplay')),
        'scenario' => pdf_pick($scene, ['scenario','description','instructions'], pdf_pick($d, ['description','instructions','scenario'], '')),
        'agentRole' => pdf_pick($scene, ['agentRole','agent_role','teacherRole','teacher_role'], 'Agent'),
        'studentRole' => pdf_pick($scene, ['studentRole','student_role','learnerRole','learner_role'], 'Student'),
        'level' => pdf_pick($scene, ['level'], pdf_pick($d, ['level'], '')),
    ];
}
function rp_pdf_turns(array $d): array {
    foreach (['turns','dialogue','dialogs','lines','items'] as $key) {
        if (isset($d[$key]) && is_array($d[$key])) return array_values($d[$key]);
    }
    return [];
}
function rp_pdf_text($turn, array $keys): string {
    if (is_string($turn)) return trim($turn);
    if (!is_array($turn)) return '';
    return pdf_pick($turn, $keys);
}
function ws_roleplay(array $d, int $n, bool $k): string {
    $scene = rp_pdf_scene($d);
    $turns = rp_pdf_turns($d);
    $out = ws_head($n, 'Roleplay Activity', $scene['title'], 'Read the description. Practice the dialogue with a partner. Use the lines below for the class activity.', $k, 'card-open rp-card');
    if ($scene['scenario'] !== '') $out .= '<div class="rp-desc"><span class="rp-label">Description</span>'.nl2br(h($scene['scenario'])).'</div>';
    $out .= '<div class="rp-roles"><div><span class="rp-label">Agent role</span><strong>'.h($scene['agentRole']).'</strong></div><div><span class="rp-label">Student role</span><strong>'.h($scene['studentRole']).'</strong></div>';
    if ($scene['level'] !== '') $out .= '<div><span class="rp-label">Level</span><strong>'.h($scene['level']).'</strong></div>';
    $out .= '</div><div class="rp-dialogue">';
    foreach ($turns as $i => $turn) {
        $agent = rp_pdf_text($turn, ['agent','teacherLine','teacher_line','agentLine','agent_line','prompt','question']);
        $student = rp_pdf_text($turn, ['ideal','studentLine','student_line','answer','model','model_answer','hint']);
        if ($agent === '' && $student === '') continue;
        $out .= '<div class="rp-turn"><div class="rp-turn-num">'.($i + 1).'</div>';
        if ($agent !== '') $out .= '<div class="rp-line"><strong>'.h($scene['agentRole']).':</strong> '.nl2br(h($agent)).'</div>';
        if ($student !== '') $out .= '<div class="rp-line"><strong>'.h($scene['studentRole']).':</strong> '.nl2br(h($student)).'</div>';
        $out .= '</div>';
    }
    $out .= '</div><div class="rp-class"><span class="rp-label">Class activity</span><p>Write your own answers or notes for the roleplay.</p><div class="rp-pdf-lines">'.str_repeat('<div class="writeline"></div>', 6).'</div></div>';
    return $out.ws_foot();
}
PHP;
$source = preg_replace('/\/\* ── BUILD SECTIONS/s', $roleplayPatch . "\n\n/* ── BUILD SECTIONS", $source, 1);
$source = str_replace(
    "case 'reading_comprehension':\$html = ws_reading(\$data, \$actN, \$isKey);    break;",
    "case 'reading_comprehension':\$html = ws_reading(\$data, \$actN, \$isKey);    break;\n        case 'roleplay':\n        case 'roleplay_activity':\n        case 'roleplay_kids':         \$html = ws_roleplay(\$data, \$actN, \$isKey); break;",
    $source
);

ob_start();
eval('?>' . $source);
$html = ob_get_clean();

$worksheetCss = <<<'CSS'

/* Approved PDF worksheet visual system. */
:root{
  --ink:#111111; --ink-soft:#9B8FCC;
  --orange:#F97316; --orange-light:#FFF0E6; --orange-dark:#B35112;
  --purple:#7F77DD; --purple-dark:#3C3489; --purple-text:#534AB7;
  --lila:#F5F3FF; --lila2:#EEEDFE; --line:#EDE9FA; --dot:#D5D0F0;
  --green:#1D9E75; --coral:#D85A30;
}
*{box-sizing:border-box;}
.ws-body{font-family:'Nunito',Arial,sans-serif !important;color:var(--ink) !important;}
.ws-sec,.section{margin-bottom:36px !important;break-inside:auto !important;page-break-inside:auto !important;}
.sec-head,.section-head{display:flex !important;align-items:baseline !important;gap:12px !important;margin:0 0 14px !important;}
.snum,.num{width:26px !important;height:26px !important;border-radius:50% !important;background:var(--orange) !important;color:#fff !important;font-family:'Fredoka','Fredoka One',Arial,sans-serif !important;font-weight:600 !important;font-size:13px !important;display:flex !important;align-items:center !important;justify-content:center !important;flex-shrink:0 !important;}
.sec-meta{display:flex !important;align-items:baseline !important;gap:12px !important;flex-wrap:wrap !important;}
.sec-kicker,.kind{font-size:10px !important;font-weight:700 !important;letter-spacing:.06em !important;color:var(--purple) !important;text-transform:uppercase !important;}
.sec-title,.section-head h2{font-family:'Fredoka','Fredoka One',Arial,sans-serif !important;font-weight:600 !important;font-size:17px !important;margin:0 !important;color:var(--ink) !important;}
.key-tag{margin-left:8px !important;background:transparent !important;color:var(--purple) !important;padding:0 !important;font-size:10px !important;}
.instructions{font-size:13px !important;color:#4A4A4A !important;font-style:italic !important;margin:0 0 16px 38px !important;}
.card-box,.card{border:1.5px solid var(--line) !important;border-radius:14px !important;padding:22px 26px !important;background:#fff !important;box-shadow:none !important;}
.card-open{border:1.5px solid var(--line) !important;border-radius:14px !important;padding:22px 26px !important;}
.ibox{display:none !important;}

/* Existing generic activities. */
.ws-qb,.q,.fill-block,.dt-item,.dict-print-item,.mrow,.ws-or,.ws-wb{margin-bottom:20px !important;break-inside:avoid !important;page-break-inside:avoid !important;}
.ws-qb:last-child,.q:last-child,.fill-block:last-child,.dt-item:last-child,.dict-print-item:last-child,.mrow:last-child,.ws-or:last-child,.ws-wb:last-child{margin-bottom:0 !important;}
.ws-qt,.q-text{font-size:14px !important;font-weight:700 !important;line-height:1.45 !important;margin:0 0 10px !important;color:var(--ink) !important;}
.qnum{width:22px !important;height:22px !important;border-radius:50% !important;background:var(--purple) !important;color:#fff !important;display:inline-flex !important;align-items:center !important;justify-content:center !important;font-weight:700 !important;font-size:10px !important;flex-shrink:0 !important;margin-right:8px !important;}
.ws-opts,.options{display:flex !important;gap:12px !important;flex-wrap:wrap !important;margin:8px 0 0 !important;padding-left:0 !important;}
.ws-opt,.opt{display:flex !important;align-items:center !important;gap:9px !important;border:1.5px solid var(--line) !important;border-radius:10px !important;padding:9px 14px !important;font-size:13px !important;line-height:1.35 !important;flex:1 1 220px !important;background:#fff !important;color:var(--ink) !important;}
.opt-l,.letter{width:20px !important;height:20px !important;border-radius:50% !important;border:1.5px solid var(--purple) !important;background:#fff !important;color:var(--purple) !important;font-size:11px !important;font-weight:700 !important;display:flex !important;align-items:center !important;justify-content:center !important;flex-shrink:0 !important;}
.writeline,.dict-line,.wp-write-line,.ws-open-line,.fc-bline,.pr-blank,.sf-line{border:0 !important;border-bottom:2px solid var(--ink) !important;height:1px !important;background:transparent !important;opacity:1 !important;}
.u,.ws-inline-blank{display:inline-block !important;border:0 !important;border-bottom:2px solid var(--ink) !important;min-width:90px !important;height:1px !important;margin:0 3px !important;background:transparent !important;}
.dict-lines,.wp-write-lines,.ws-open-lines,.ws-lines{display:grid !important;gap:28px !important;margin-top:10px !important;padding:0 !important;}
.ws-open-line::before{display:none !important;}

/* Reading comprehension approved design. */
.rc-print-card{border:0 !important;padding:0 !important;}
.rc-kicker,.wp-kicker{display:inline-flex !important;align-items:center !important;min-height:14px !important;border-radius:999px !important;padding:4px 16px !important;margin:0 0 10px !important;font-family:'Fredoka','Fredoka One',Arial,sans-serif !important;font-weight:700 !important;font-size:10px !important;line-height:1 !important;letter-spacing:.06em !important;text-transform:uppercase !important;}
.rc-kicker{background:var(--orange-light) !important;color:var(--orange-dark) !important;}.wp-kicker{background:var(--lila2) !important;color:var(--purple-dark) !important;}
.rc-title,.wp-title{font-family:'Fredoka','Fredoka One',Arial,sans-serif !important;font-size:24px !important;line-height:1.1 !important;font-weight:600 !important;color:var(--purple-dark) !important;margin:0 0 5px !important;}
.rc-meta{font-size:13px !important;color:var(--ink-soft) !important;margin:0 0 20px !important;}
.rc-passage{border:1.5px solid var(--line) !important;border-radius:14px !important;background:#FAFAFC !important;padding:22px 26px !important;font-size:14px !important;line-height:1.85 !important;color:var(--ink) !important;margin:0 0 22px !important;}
.rc-word{color:var(--orange) !important;font-weight:800 !important;}.rc-num{display:inline-flex !important;align-items:center !important;justify-content:center !important;width:15px !important;height:15px !important;border-radius:50% !important;background:var(--orange) !important;color:#fff !important;font-size:9px !important;font-weight:800 !important;line-height:1 !important;margin-left:2px !important;vertical-align:super !important;}
.rc-vocab-box{background:var(--lila) !important;border-radius:14px !important;padding:16px 18px !important;margin:0 0 22px !important;}.rc-box-title,.rc-section-label{font-family:'Fredoka','Fredoka One',Arial,sans-serif !important;font-weight:600 !important;font-size:14px !important;color:var(--purple-text) !important;margin:0 0 12px !important;}.rc-section-label{color:var(--orange-dark) !important;margin-top:4px !important;}.rc-section-label.purple{color:var(--purple-text) !important;}
.rc-meaning-grid{display:grid !important;grid-template-columns:1fr 1fr !important;gap:12px 24px !important;}.rc-meaning-item{display:grid !important;grid-template-columns:auto 1fr !important;gap:16px !important;align-items:center !important;font-size:13px !important;}.rc-meaning-item span:first-child{color:var(--orange) !important;font-weight:800 !important;}
.dotline{display:block !important;border:0 !important;border-bottom:1.4px dotted var(--dot) !important;height:1px !important;min-height:1px !important;}
.rc-question{margin-bottom:18px !important;}.rc-q-stem{font-size:14px !important;line-height:1.45 !important;margin:0 0 10px !important;color:var(--ink) !important;}.rc-options{display:grid !important;grid-template-columns:1fr 1fr !important;gap:10px 18px !important;}.rc-option,.rc-vocab-option{display:flex !important;align-items:flex-start !important;gap:10px !important;font-size:13px !important;line-height:1.35 !important;color:var(--ink) !important;}.radio{width:16px !important;height:16px !important;border-radius:50% !important;border:1.5px solid var(--purple) !important;background:#fff !important;flex:0 0 auto !important;margin-top:1px !important;}.rc-open-lines{display:grid !important;gap:12px !important;margin-top:10px !important;}.rc-vocab-card{background:var(--lila) !important;border-radius:14px !important;padding:16px 22px !important;margin:0 0 14px !important;break-inside:avoid !important;page-break-inside:avoid !important;}.rc-card-word{display:flex !important;align-items:center !important;gap:10px !important;margin-bottom:10px !important;color:var(--orange) !important;font-size:14px !important;}.rc-num.solid{position:static !important;vertical-align:middle !important;margin:0 !important;}.rc-vocab-option{margin:8px 0 0 30px !important;}.rc-bonus{border:1.5px dashed var(--dot) !important;border-radius:14px !important;padding:14px 18px !important;margin-top:18px !important;color:var(--ink-soft) !important;font-size:12px !important;}.rc-bonus .dotline{margin-top:14px !important;}

/* Writing practice approved design. */
.wp-print{border:0 !important;padding:0 !important;}.wp-source{background:var(--orange-light) !important;border-radius:14px !important;padding:16px 18px !important;margin:0 0 14px !important;}.wp-source-label{font-family:'Fredoka','Fredoka One',Arial,sans-serif !important;color:var(--orange-dark) !important;font-size:10px !important;font-weight:700 !important;letter-spacing:.04em !important;margin-bottom:8px !important;text-transform:uppercase !important;}.wp-source-text{font-size:15px !important;line-height:1.55 !important;color:var(--ink) !important;}.wp-use{font-size:12px !important;color:var(--ink-soft) !important;margin:0 0 10px !important;}.wp-bank{background:var(--lila) !important;border-radius:14px !important;padding:14px 18px !important;margin:0 0 26px !important;color:var(--purple-dark) !important;font-size:12px !important;line-height:1.6 !important;}.wp-bank-label{font-family:'Fredoka','Fredoka One',Arial,sans-serif !important;color:var(--purple-text) !important;font-size:10px !important;font-weight:700 !important;letter-spacing:.06em !important;margin-bottom:4px !important;text-transform:uppercase !important;}.wp-your{font-family:'Fredoka','Fredoka One',Arial,sans-serif !important;color:var(--orange-dark) !important;font-size:13px !important;font-weight:700 !important;margin:0 0 32px !important;}.wp-lines{display:grid !important;gap:28px !important;margin-bottom:36px !important;}.wp-lines .writeline{border-bottom:1.5px solid var(--dot) !important;height:1px !important;}.wp-self{border:1.5px solid var(--line) !important;border-radius:12px !important;padding:14px 18px !important;color:var(--ink) !important;font-size:12px !important;}.wp-self-title{font-family:'Fredoka','Fredoka One',Arial,sans-serif !important;color:var(--purple-text) !important;font-size:10px !important;font-weight:700 !important;letter-spacing:.04em !important;margin-bottom:10px !important;text-transform:uppercase !important;}.check{display:inline-block !important;width:13px !important;height:13px !important;border-radius:3px !important;margin:0 6px 0 16px !important;vertical-align:-2px !important;border:1.5px solid var(--purple) !important;}.check:first-of-type{margin-left:0 !important;}.check.green{border-color:var(--green) !important;}.check.coral{border-color:var(--coral) !important;}.check.orange{border-color:var(--orange) !important;}.wp-divider{height:1px !important;background:var(--line) !important;margin:26px 0 !important;}

/* Roleplay simple print support. */
.rp-desc{font-size:14px !important;line-height:1.5 !important;margin-bottom:18px !important;}.rp-label{display:block !important;font-size:10px !important;font-weight:700 !important;letter-spacing:.06em !important;color:var(--purple) !important;text-transform:uppercase !important;margin-bottom:6px !important;}.rp-roles{display:grid !important;grid-template-columns:repeat(3,minmax(0,1fr)) !important;gap:12px !important;margin-bottom:20px !important;}.rp-roles>div{border:1.5px solid var(--line) !important;border-radius:10px !important;padding:9px 14px !important;}.rp-dialogue{display:grid !important;gap:20px !important;margin-bottom:20px !important;}.rp-turn{position:relative !important;padding-left:34px !important;}.rp-turn-num{position:absolute !important;left:0 !important;top:0 !important;width:22px !important;height:22px !important;border-radius:50% !important;background:var(--purple) !important;color:#fff !important;display:flex !important;align-items:center !important;justify-content:center !important;font-size:10px !important;font-weight:700 !important;}.rp-line{font-size:14px !important;line-height:1.55 !important;margin-bottom:8px !important;}.rp-pdf-lines{display:grid !important;gap:28px !important;margin-top:10px !important;}

@media print{.ws-sec,.section{margin-bottom:36px !important;}.card-box,.card{padding:22px 26px !important;}.wp-lines,.rp-pdf-lines{gap:28px !important;}}
CSS;

if (strpos($html, '</style>') !== false) {
    $html = preg_replace('/<\/style>/', $worksheetCss . "\n</style>", $html, 1);
} else {
    $html = str_replace('</head>', '<style>' . $worksheetCss . '</style></head>', $html);
}

echo $html;
