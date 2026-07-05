<?php
/* Worksheet PDF visual wrapper. Keeps data fetching, order, paging and mode logic in unit_pdf_base.php. */
$basePath = __DIR__ . '/unit_pdf_base.php';
$source = file_get_contents($basePath);
if ($source === false) die('Worksheet renderer not found.');

$source = str_replace(
    '$SKIP_TYPES = [\'flipbooks\',\'hangman\',\'crossword\',\'coloring\',\'dot_to_dot\',\'tracing\'];',
    '$SKIP_TYPES = [\'flipbooks\',\'hangman\',\'crossword\',\'coloring\',\'dot_to_dot\',\'tracing\',\'memory_cards\'];',
    $source
);
$source = str_replace("case 'memory_cards':         \$html = ws_memory(\$data, \$actN, \$isKey);      break;", "case 'memory_cards':         \$actN--; continue 2;", $source);

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
$source = preg_replace('/function\s+ws_head\s*\([\s\S]*?function\s+ws_foot/s', $headPatch . "\nfunction ws_foot", $source, 1);

$activityPatch = <<<'PHP'
/* ── VOCABULARY / FLASHCARDS ───────────────────────────────── */
function pdf_pick(array $a, array $keys, string $fallback = ''): string { foreach ($keys as $key) { if (isset($a[$key]) && trim((string)$a[$key]) !== '') return trim((string)$a[$key]); } return $fallback; }
function pdf_list($raw): array { if (is_array($raw)) return array_values(array_filter(array_map('trim', array_map('strval', $raw)), static fn($v)=>$v!=='')); $s=trim((string)$raw); if($s==='')return []; return array_values(array_filter(array_map('trim', preg_split('/\s*(?:,|\n|;|\|)\s*/',$s)?:[]), static fn($v)=>$v!=='')); }

function ws_flashcards(array $d, int $n, bool $k): string {
    $cards = is_array($d['cards'] ?? null) ? $d['cards'] : [];
    $title = trim((string)($d['title'] ?? 'Vocabulary'));
    $out = ws_head($n, 'Vocabulary', $title, 'Study each word. Read the pronunciation and meaning.', $k, 'card-open fc-print');
    if (empty($cards)) return $out.'<p class="ws-empty">No items.</p>'.ws_foot();
    $out .= '<div class="fc-print-grid">';
    foreach ($cards as $card) {
        if (!is_array($card)) continue;
        $word = pdf_pick($card, ['text','word','english_text']);
        $img = pdf_pick($card, ['image','img']);
        $ipa = pdf_pick($card, ['ipa']);
        $meaning = pdf_pick($card, ['meaning']);
        $example = pdf_pick($card, ['example','sentence']);
        if ($word==='' && $img==='') continue;
        $out .= '<div class="fc-print-card">';
        if ($img !== '') $out .= '<div class="fc-print-img"><img src="'.h($img).'" alt="" loading="eager"></div>';
        $out .= '<div class="fc-print-word">'.h($word).'</div>';
        if ($ipa !== '') $out .= '<div class="fc-print-ipa">'.h($ipa).'</div>';
        if ($meaning !== '') $out .= '<div class="fc-print-meaning">'.h($meaning).'</div>';
        if ($example !== '') $out .= '<div class="fc-print-example">'.h($example).'</div>';
        $out .= '</div>';
    }
    return $out.'</div>'.ws_foot();
}
PHP;

$writingPatch = <<<'PHP'
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
    $items = is_array($d['items'] ?? null) ? $d['items'] : [];
    if (empty($items) && is_array($d['questions'] ?? null)) $items = $d['questions'];
    $out = ws_head($n, 'Writing Practice', trim((string)($d['title'] ?? '')), 'Write your answers in complete sentences.', $k, 'card-open wp-print');
    if (empty($items)) return $out.'<div class="notes-box"></div>'.ws_foot();
    foreach ($items as $qi => $it) {
        if (!is_array($it)) continue;
        $in = trim((string)($it['instruction'] ?? ''));
        $qt = trim((string)($it['prompt_text'] ?? ($it['question'] ?? ($it['prompt'] ?? ''))));
        $an = trim((string)($it['answer'] ?? ($it['model_answer'] ?? '')));
        $combined = mb_strtolower($in.' '.$qt, 'UTF-8');
        $len = mb_strlen($qt.' '.$in, 'UTF-8');
        $numLines = 5;
        if (preg_match('/translate|translation|traducir|traduce|traduccion|traducción|english|ingles|inglés/u', $combined)) $numLines = 8;
        if (preg_match('/paragraph|describe|summarize|summary|resumen|resume/u', $combined)) $numLines = max($numLines, 7);
        if ($len > 280) $numLines = max($numLines, 7);
        if ($len > 520) $numLines = max($numLines, 9);
        $numLines = min(10, $numLines);
        $out .= '<div class="ws-wb wp-item">';
        $out .= '<div class="ws-qt"><span class="qnum">'.($qi+1).'</span>'.h($qt !== '' ? $qt : ($in ?: 'Write your answer.')).'</div>';
        if ($in !== '' && $qt !== '') $out .= '<div class="ws-wi">'.h($in).'</div>';
        if ($k && $an !== '') {
            $out .= '<div class="ws-ab"><div class="ws-ma">&#10003; '.h($an).'</div></div>';
        } else {
            $out .= '<div class="ws-open-lines wp-lines">'.str_repeat('<div class="ws-open-line"></div>', $numLines).'</div>';
        }
        $out .= '</div>';
    }
    return $out.ws_foot();
}
PHP;

$dictationPatch = <<<'PHP'
/* ── DICTATION ───────────────────────────────────────────── */
function ws_dictation(array $d, int $n, bool $k): string {
    $items = is_array($d['items'] ?? null) ? $d['items'] : [];
    $out = ws_head($n, 'Dictation', trim((string)($d['title'] ?? '')), 'Listen to each item and write what you hear.', $k, 'card-open dictation-print');
    if (empty($items)) return $out.'<div class="notes-box"></div>'.ws_foot();
    foreach ($items as $i => $item) {
        if (!is_array($item)) continue;
        $en = pdf_pick($item, ['en','answer','text','sentence']);
        $img = pdf_pick($item, ['img','image']);
        $rowClass = $img !== '' ? 'dt-with-img' : 'dt-no-img';
        $out .= '<div class="dt-item '.$rowClass.'">';
        $out .= '<div class="dt-num">'.($i+1).'.</div>';
        if ($img !== '') {
            $out .= '<div class="dt-img"><img src="'.h($img).'" alt="item '.($i+1).'" loading="eager"></div>';
        }
        $out .= '<div class="dt-write">';
        if ($k && $en !== '') $out .= '<div class="ws-ans dt-ans">'.h($en).'</div>';
        $out .= '<div class="ws-open-lines dt-lines">';
        for ($l = 0; $l < 4; $l++) $out .= '<div class="ws-open-line"></div>';
        $out .= '</div></div></div>';
    }
    return $out.ws_foot();
}
PHP;

$pronPatch = <<<'PHP'
/* ── PRONUNCIATION ───────────────────────────────────────── */
function ws_pronunciation(array $d, int $n, bool $k): string {
    $items = is_array($d['items'] ?? null) ? $d['items'] : [];
    $out = ws_head($n, 'Pronunciation', trim((string)($d['title'] ?? '')), 'Read each word, pronunciation and example.', $k, 'card-open pr-print');
    if (empty($items)) return $out.'<div class="notes-box"></div>'.ws_foot();
    foreach ($items as $i => $item) {
        if (!is_array($item)) continue;
        $word = pdf_pick($item, ['en','word','text']);
        $ipa = pdf_pick($item, ['ipa']);
        $ph = pdf_pick($item, ['ph']);
        $meaning = pdf_pick($item, ['meaning']);
        $example = pdf_pick($item, ['example','sentence']);
        $img = pdf_pick($item, ['img','image']);
        if ($word==='' && $img==='') continue;
        $out .= '<div class="pr-print-row"><div class="pr-print-num">'.($i+1).'</div>';
        if ($img !== '') $out .= '<div class="pr-print-img"><img src="'.h($img).'" alt="" loading="eager"></div>';
        else $out .= '<div class="pr-print-img pr-print-empty"></div>';
        $out .= '<div class="pr-print-main"><div class="pr-print-word">'.h($word).'</div>';
        if ($ipa !== '') $out .= '<div class="pr-print-ipa">'.h($ipa).'</div>';
        elseif ($ph !== '') $out .= '<div class="pr-print-ipa">'.h($ph).'</div>';
        if ($meaning !== '') $out .= '<div class="pr-print-meaning">'.h($meaning).'</div>';
        if ($example !== '') $out .= '<div class="pr-print-example">'.h($example).'</div>';
        $out .= '</div></div>';
    }
    return $out.ws_foot();
}
PHP;

$roleplayPatch = <<<'PHP'
function ws_roleplay(array $d,int $n,bool $k): string { $scene=is_array($d['scene']??null)?$d['scene']:$d; $title=pdf_pick($scene,['title'],pdf_pick($d,['title'],'Roleplay')); $out=ws_head($n,'Roleplay Activity',$title,'Read the description. Practice the dialogue with a partner.',$k,'card-open rp-card'); $desc=pdf_pick($scene,['scenario','description','instructions']); if($desc!=='')$out.='<div class="rp-desc"><span class="rp-label">Description</span>'.nl2br(h($desc)).'</div>'; $turns=[]; foreach(['turns','dialogue','dialogs','lines','items'] as $key){ if(isset($d[$key])&&is_array($d[$key])){$turns=$d[$key];break;} } foreach($turns as $i=>$turn){ if(!is_array($turn))continue; $a=pdf_pick($turn,['agent','teacherLine','agentLine','prompt','question']); $s=pdf_pick($turn,['ideal','studentLine','answer','model','hint']); $out.='<div class="rp-turn"><div class="rp-turn-num">'.($i+1).'</div>'; if($a!=='')$out.='<div class="rp-line"><strong>Agent:</strong> '.nl2br(h($a)).'</div>'; if($s!=='')$out.='<div class="rp-line"><strong>Student:</strong> '.nl2br(h($s)).'</div>'; $out.='</div>'; } $out.='<div class="rp-class"><span class="rp-label">Class activity</span><div class="rp-pdf-lines">'.str_repeat('<div class="writeline"></div>',6).'</div></div>'; return $out.ws_foot(); }
PHP;

$source = preg_replace('/\/\* ── VOCABULARY \/ FLASHCARDS[\s\S]*?\/\* ── QUIZ/s', $activityPatch."\n\n/* ── QUIZ", $source, 1);
$source = preg_replace('/\/\* ── WRITING PRACTICE[\s\S]*?\/\* ── MATCH/s', $writingPatch."\n\n/* ── MATCH", $source, 1);
$source = preg_replace('/\/\* ── DICTATION[\s\S]*?\/\* ── PRONUNCIATION/s', $dictationPatch."\n\n/* ── PRONUNCIATION", $source, 1);
$source = preg_replace('/\/\* ── PRONUNCIATION[\s\S]*?\/\* ── POWERPOINT/s', $pronPatch."\n\n/* ── POWERPOINT", $source, 1);
$source = preg_replace('/\/\* ── BUILD SECTIONS/s', $roleplayPatch."\n\n/* ── BUILD SECTIONS", $source, 1);
$source = str_replace("case 'reading_comprehension':\$html = ws_reading(\$data, \$actN, \$isKey);    break;", "case 'reading_comprehension':\$html = ws_reading(\$data, \$actN, \$isKey);    break;\n        case 'roleplay':\n        case 'roleplay_activity':\n        case 'roleplay_kids':         \$html = ws_roleplay(\$data, \$actN, \$isKey); break;", $source);

ob_start();
eval('?>'.$source);
$html = ob_get_clean();

$worksheetCss = <<<'CSS'
:root{--ink:#111;--ink-soft:#9B8FCC;--orange:#F97316;--orange-light:#FFF0E6;--orange-dark:#B35112;--purple:#7F77DD;--purple-dark:#3C3489;--purple-text:#534AB7;--lila:#F5F3FF;--line:#EDE9FA;--dot:#D5D0F0}.ws-sec,.section{margin-bottom:36px!important}.sec-head,.section-head{display:flex!important;align-items:baseline!important;gap:12px!important;margin:0 0 14px!important}.snum,.num{width:26px!important;height:26px!important;border-radius:50%!important;background:var(--orange)!important;color:#fff!important;font-family:'Fredoka',Arial,sans-serif!important;font-weight:600!important;font-size:13px!important;display:flex!important;align-items:center!important;justify-content:center!important;flex-shrink:0!important}.sec-meta{display:flex!important;align-items:baseline!important;gap:12px!important;flex-wrap:wrap!important}.sec-kicker,.kind{font-size:10px!important;font-weight:700!important;letter-spacing:.06em!important;color:var(--purple)!important;text-transform:uppercase!important}.sec-title{font-family:'Fredoka',Arial,sans-serif!important;font-size:17px!important;margin:0!important;color:var(--ink)!important}.instructions{font-size:13px!important;color:#4A4A4A!important;font-style:italic!important;margin:0 0 16px 38px!important}.card-box,.card{border:1.5px solid var(--line)!important;border-radius:14px!important;padding:22px 26px!important;background:#fff!important;box-shadow:none!important}.ibox{display:none!important}.writeline,.ws-open-line,.fc-bline,.pr-blank{border:0!important;border-bottom:2px solid var(--ink)!important;height:1px!important;background:transparent!important;opacity:1!important}.fc-print-grid{display:grid!important;grid-template-columns:repeat(3,minmax(0,1fr))!important;gap:18px!important}.fc-print-card{border:1.5px solid var(--line)!important;border-radius:14px!important;padding:14px!important;break-inside:avoid!important}.fc-print-img{height:95px!important;background:var(--lila)!important;border-radius:10px!important;display:flex!important;align-items:center!important;justify-content:center!important;margin-bottom:10px!important;overflow:hidden!important}.fc-print-img img,.pr-print-img img{max-width:100%!important;max-height:100%!important;object-fit:contain!important}.fc-print-word,.pr-print-word{font-family:'Fredoka',Arial,sans-serif!important;font-weight:700!important;color:var(--purple-dark)!important;font-size:15px!important;margin-bottom:4px!important}.fc-print-ipa,.pr-print-ipa{font-style:italic!important;color:var(--orange-dark)!important;font-size:12px!important;margin-bottom:5px!important}.fc-print-meaning,.pr-print-meaning{font-size:12px!important;line-height:1.35!important;color:var(--ink)!important;margin-bottom:5px!important}.fc-print-example,.pr-print-example{font-size:11px!important;line-height:1.35!important;color:#4A4A4A!important}.pr-print-row{display:grid!important;grid-template-columns:26px 72px 1fr!important;gap:12px!important;align-items:start!important;border-bottom:1.5px solid var(--line)!important;padding:0 0 14px!important;margin-bottom:14px!important;break-inside:avoid!important}.pr-print-num{width:24px!important;height:24px!important;border-radius:50%!important;background:var(--purple)!important;color:#fff!important;display:flex!important;align-items:center!important;justify-content:center!important;font-size:10px!important;font-weight:800!important}.pr-print-img{height:58px!important;background:var(--lila)!important;border-radius:10px!important;display:flex!important;align-items:center!important;justify-content:center!important;overflow:hidden!important}.rp-pdf-lines{display:grid!important;gap:28px!important;margin-top:10px!important}.rp-turn{position:relative!important;padding-left:34px!important;margin-bottom:14px!important}.rp-turn-num{position:absolute!important;left:0!important;top:0!important;width:22px!important;height:22px!important;border-radius:50%!important;background:var(--purple)!important;color:#fff!important;display:flex!important;align-items:center!important;justify-content:center!important;font-size:10px!important;font-weight:700!important}
/* ── Print-friendly main text: instructions, questions, options, word banks, labels, activity text ── */
.instructions,.ws-qt,.ws-opt,.ws-chip,.ws-blbl,.rc-text,.ws-fb,.ws-ot,.mt,.ws-ma,.dt-ans,.pr-ans,.rp-desc,.rp-line,.fc-print-meaning,.fc-print-example,.pr-print-meaning,.pr-print-example{font-family:Verdana,sans-serif!important;font-weight:700!important;font-size:14px!important;line-height:1.6!important}
/* ── Roomier writing spacing so students have comfortable space to write ── */
.ws-qb{margin-bottom:24px!important}
.ws-opt{min-height:44px!important;padding:9px 12px!important}
.ws-open-lines,.ws-lines{gap:34px!important;margin-top:14px!important}
.ws-open-line,.ws-line{height:38px!important}
.ws-open-line::before{bottom:-18px!important}
.rp-pdf-lines{gap:32px!important}
/* ── Writing practice: more answer lines for translation/summarize prompts without oversized gaps ── */
.wp-print .wp-item{margin-bottom:26px!important;break-inside:avoid!important}.wp-print .wp-lines{display:grid!important;gap:16px!important;margin-top:16px!important}.wp-print .wp-lines .ws-open-line{height:12px!important;min-height:12px!important;border-bottom:2px solid #111!important}.wp-print .ws-wi{font-family:Verdana,sans-serif!important;font-weight:700!important;font-size:14px!important;font-style:italic!important;line-height:1.45!important;margin-top:8px!important;color:#2F2A55!important}
/* ── Dictation: image only when available; no empty image box; compact line spacing like DISEÑO DICTADO ── */
.dictation-print{padding:18px 24px!important}.dt-item{display:flex!important;align-items:flex-start!important;gap:10px!important;margin:0 0 24px!important;break-inside:avoid!important}.dt-num{width:22px!important;min-width:22px!important;font-family:Verdana,sans-serif!important;font-weight:700!important;font-size:14px!important;line-height:1!important;padding-top:6px!important}.dt-img{width:150px!important;height:138px!important;min-width:150px!important;flex:0 0 150px!important;background:#fff!important;border:0!important;border-radius:0!important;display:flex!important;align-items:center!important;justify-content:center!important;overflow:hidden!important}.dt-img img{width:100%!important;height:100%!important;object-fit:contain!important}.dt-img-empty{display:none!important}.dt-write{flex:1!important;min-width:0!important}.dt-lines{display:grid!important;gap:22px!important;margin-top:6px!important}.dt-with-img .dt-lines{margin-top:8px!important}.dt-no-img .dt-write{flex-basis:100%!important}.dt-no-img .dt-lines{margin-top:2px!important}.dt-lines .ws-open-line{height:0!important;min-height:0!important;border-bottom:2px solid #111!important}
CSS;
if (strpos($html,'</style>')!==false) $html=preg_replace('/<\/style>/',$worksheetCss."\n</style>",$html,1); else $html=str_replace('</head>','<style>'.$worksheetCss.'</style></head>',$html);
echo $html;
