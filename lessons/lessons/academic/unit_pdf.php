<?php
/*
 * Thin wrapper for the printable unit worksheet.
 * The original renderer lives in unit_pdf_base.php. This wrapper injects
 * print-only typography overrides and patches newer activity exports without
 * changing the original renderer structure.
 */

$basePath = __DIR__ . '/unit_pdf_base.php';
$source = file_get_contents($basePath);
if ($source === false) {
    die('Worksheet renderer not found.');
}

// Memory Cards is an interactive-only activity and should not appear in PDF exports.
$source = str_replace(
    "$SKIP_TYPES = ['flipbooks','hangman','crossword','coloring','dot_to_dot','tracing'];",
    "$SKIP_TYPES = ['flipbooks','hangman','crossword','coloring','dot_to_dot','tracing','memory_cards'];",
    $source
);
$source = str_replace(
    "case 'memory_cards':         $html = ws_memory($data, $actN, $isKey);      break;",
    "case 'memory_cards':         $actN--; continue 2;",
    $source
);

$writingPatch = <<<'PHP'
function wp_pdf_pick(array $a, array $keys, string $fallback = ''): string {
    foreach ($keys as $key) {
        if (isset($a[$key]) && trim((string)$a[$key]) !== '') return trim((string)$a[$key]);
    }
    return $fallback;
}
function wp_pdf_items(array $d): array {
    if (isset($d['items']) && is_array($d['items'])) return array_values($d['items']);
    if (isset($d['questions']) && is_array($d['questions'])) return array_values($d['questions']);
    return [];
}
function wp_pdf_lines(int $lines = 5): string {
    $lines = max(3, min(9, $lines));
    return '<div class="wp-write-lines">'.str_repeat('<div class="wp-write-line"></div>', $lines).'</div>';
}
function ws_writing(array $d, int $n, bool $k): string {
    $items = wp_pdf_items($d);
    $title = wp_pdf_pick($d, ['title'], 'Writing Practice');
    $desc = wp_pdf_pick($d, ['description','instructions'], 'Read the prompt and write your answer in complete sentences.');
    $out = ws_head($n, 'Writing Practice', $title, $desc, $k, 'card-open wp-card-open');
    if (empty($items)) {
        $out .= '<div class="wp-print-card"><div class="wp-prompt-title">Prompt</div>'.wp_pdf_lines(6).'</div>';
        return $out.ws_foot();
    }
    foreach ($items as $qi => $item) {
        if (!is_array($item)) continue;
        $instruction = wp_pdf_pick($item, ['instruction','instructions','direction','directions']);
        $prompt = wp_pdf_pick($item, ['prompt_text','prompt','question','stem','text']);
        $answer = wp_pdf_pick($item, ['answer','sample_answer','model_answer']);
        $type = strtolower(wp_pdf_pick($item, ['type'], 'writing'));
        if ($prompt === '' && $instruction === '' && $answer === '') continue;
        $wordHint = '';
        if (preg_match('/paragraph|describe|essay|story|opinion|explain/i', $instruction.' '.$prompt)) {
            $lines = 7;
            $wordHint = '<span class="wp-word-hint">Use complete sentences.</span>';
        } else {
            $lines = 5;
        }
        $out .= '<div class="wp-print-card">';
        $out .= '<div class="wp-print-head"><span class="qnum">'.($qi + 1).'</span><span class="wp-prompt-title">Writing prompt</span>'.$wordHint.'</div>';
        if ($instruction !== '') $out .= '<div class="wp-instruction">'.h($instruction).'</div>';
        if ($prompt !== '') $out .= '<div class="wp-prompt-box">'.nl2br(h($prompt)).'</div>';
        if ($k && $answer !== '') {
            $out .= '<div class="wp-answer-key"><strong>Sample answer:</strong> '.nl2br(h($answer)).'</div>';
        } elseif (in_array($type, ['fill_sentence','fill_paragraph','listen_write'], true)) {
            $answers = [];
            if (isset($item['correct_answers']) && is_array($item['correct_answers'])) $answers = $item['correct_answers'];
            elseif ($answer !== '') $answers = [$answer];
            $out .= ws_render_blanks($prompt, $answers, $type);
            $out .= wp_pdf_lines(3);
        } else {
            $out .= wp_pdf_lines($lines);
        }
        $out .= '</div>';
    }
    return $out.ws_foot();
}
PHP;

$dictationPatch = <<<'PHP'
function dict_pdf_items(array $d): array {
    if (isset($d['items']) && is_array($d['items'])) return array_values($d['items']);
    if (isset($d['prompts']) && is_array($d['prompts'])) return array_values($d['prompts']);
    if (isset($d['sentences']) && is_array($d['sentences'])) return array_values($d['sentences']);
    return [];
}
function dict_pdf_text(array $item): string {
    foreach (['en','text','sentence','prompt','word','phrase'] as $key) {
        if (isset($item[$key]) && trim((string)$item[$key]) !== '') return trim((string)$item[$key]);
    }
    return '';
}
function dict_pdf_img(array $item): string {
    foreach (['img','image','url','picture'] as $key) {
        if (isset($item[$key]) && trim((string)$item[$key]) !== '') return trim((string)$item[$key]);
    }
    return '';
}
function ws_dictation(array $d, int $n, bool $k): string {
    $items = dict_pdf_items($d);
    $title = trim((string)($d['title'] ?? 'Dictation'));
    $out = ws_head($n, 'Dictation', $title, 'Listen carefully. Write exactly what you hear.', $k, 'card-open dict-card-open');
    if (empty($items)) {
        $out .= '<div class="dict-grid"><div class="dict-print-item"><div class="dict-print-top"><span class="dict-num">1</span><span class="dict-icon">🎧</span><span class="dict-label">Listen and write</span></div><div class="dict-lines">'.str_repeat('<div class="dict-line"></div>', 4).'</div></div></div>';
        return $out.ws_foot();
    }
    $out .= '<div class="dict-grid">';
    foreach ($items as $i => $item) {
        if (!is_array($item)) $item = ['en' => (string)$item];
        $text = dict_pdf_text($item);
        $img = dict_pdf_img($item);
        $wc = $text !== '' ? count(preg_split('/\s+/', trim($text)) ?: []) : 5;
        $lines = $wc <= 5 ? 3 : ($wc <= 12 ? 4 : 5);
        $out .= '<div class="dict-print-item">';
        $out .= '<div class="dict-print-top"><span class="dict-num">'.($i + 1).'</span><span class="dict-icon">🎧</span><span class="dict-label">Listen and write</span></div>';
        if ($img !== '') $out .= '<div class="dict-img"><img src="'.h($img).'" alt="dictation '.($i + 1).'" loading="eager"></div>';
        if ($k && $text !== '') $out .= '<div class="dict-answer"><strong>Answer:</strong> '.h($text).'</div>';
        $out .= '<div class="dict-lines">'.str_repeat('<div class="dict-line"></div>', $lines).'</div>';
        $out .= '</div>';
    }
    $out .= '</div>';
    return $out.ws_foot();
}
PHP;

$readingPatch = <<<'PHP'
function rc_pdf_pick(array $a, array $keys, string $fallback = ''): string {
    foreach ($keys as $key) {
        if (isset($a[$key]) && trim((string)$a[$key]) !== '') return trim((string)$a[$key]);
    }
    return $fallback;
}
function rc_pdf_text(array $d): array {
    if (isset($d['texts']) && is_array($d['texts']) && !empty($d['texts'])) {
        $t = is_array($d['texts'][0]) ? $d['texts'][0] : [];
        $t['root_title'] = trim((string)($d['title'] ?? ''));
        return $t;
    }
    return $d;
}
function rc_pdf_hl(string $body, array $words): string {
    $html = nl2br(h($body !== '' ? $body : 'No passage text configured.'));
    $terms = [];
    foreach ($words as $w) {
        if (!is_array($w)) continue;
        $term = trim((string)($w['word'] ?? $w['text'] ?? $w['term'] ?? ''));
        if ($term !== '') $terms[] = $term;
    }
    usort($terms, static fn($a, $b) => strlen($b) <=> strlen($a));
    foreach ($terms as $term) {
        $safe = preg_quote(h($term), '/');
        if ($safe === '') continue;
        $pattern = preg_match('/\s/', $term) ? '/(' . $safe . ')/iu' : '/\b(' . $safe . ')\b/iu';
        $html = preg_replace($pattern, '<span class="rc-hl">$1</span>', $html);
    }
    return $html;
}
function rc_pdf_options(array $q): array {
    $raw = $q['options'] ?? [];
    if (!is_array($raw)) $raw = [];
    $out = [];
    foreach ($raw as $o) {
        $txt = trim((string)$o);
        if ($txt !== '') $out[] = $txt;
    }
    return $out;
}
function rc_pdf_question_box(string $stem, array $options, int $correct, bool $k, string $feedback = '', int $num = 1): string {
    $ltrs = ['A','B','C','D'];
    $out = '<div class="ws-qb rc-qb"><div class="ws-qt"><span class="qnum">'.$num.'</span>'.h($stem).'</div>';
    if (!empty($options)) {
        $out .= '<div class="ws-opts">';
        foreach ($options as $oi => $op) {
            $ck_cls = ($k && $oi === $correct) ? ' ws-ck' : '';
            $out .= '<div class="ws-opt'.$ck_cls.'"><span class="opt-l">'.($ltrs[$oi] ?? chr(65 + $oi)).'</span>'.h($op).'</div>';
        }
        $out .= '</div>';
    } else {
        $out .= '<div class="ws-open-lines"><div class="ws-open-line"></div><div class="ws-open-line"></div></div>';
    }
    if ($k && $feedback !== '') $out .= '<div class="ws-expl">'.h($feedback).'</div>';
    return $out.'</div>';
}
function ws_reading(array $d, int $n, bool $k): string {
    $t = rc_pdf_text($d);
    $mode = strtolower(trim((string)($t['mode'] ?? $d['mode'] ?? 'reading')));
    $isComp = in_array($mode, ['comp','comprehension','reading_comprehension'], true);
    $title = rc_pdf_pick($t, ['title', 'root_title'], rc_pdf_pick($d, ['title'], 'Reading Comprehension'));
    $genre = rc_pdf_pick($t, ['genre'], 'Reading text');
    $body = rc_pdf_pick($t, ['body','text','passage','content'], rc_pdf_pick($d, ['body','text','passage','content'], ''));
    $words = is_array($t['words'] ?? null) ? $t['words'] : (is_array($d['words'] ?? null) ? $d['words'] : []);
    $questions = is_array($t['questions'] ?? null) ? $t['questions'] : (is_array($d['questions'] ?? null) ? $d['questions'] : []);
    $wordCount = (int)($t['wordCount'] ?? $d['wordCount'] ?? 0);
    if ($wordCount <= 0 && $body !== '') $wordCount = count(preg_split('/\s+/', trim($body)) ?: []);
    $out = ws_head($n, $isComp ? 'Reading Comprehension' : 'Vocabulary Meaning', $title, 'Read the passage carefully and answer the questions.', $k);
    $meta = trim($genre . ($wordCount > 0 ? ' · ' . $wordCount . ' words' : ''));
    if ($meta !== '') $out .= '<div class="rc-meta">'.h($meta).'</div>';
    $out .= '<div class="rc-text">'.rc_pdf_hl($body, $words).'</div>';
    if ($isComp) {
        if (empty($questions)) $out .= '<div class="notes-box"></div>';
        else foreach ($questions as $qi => $q) {
            if (!is_array($q)) continue;
            $out .= rc_pdf_question_box(rc_pdf_pick($q, ['stem','question','prompt'], 'Question '.($qi + 1)), rc_pdf_options($q), (int)($q['correct'] ?? 0), $k, trim((string)($q['feedback'] ?? $q['explanation'] ?? '')), $qi + 1);
        }
    } else {
        $vocabQs = [];
        foreach ($words as $w) {
            if (!is_array($w)) continue;
            $word = rc_pdf_pick($w, ['word','text','term']);
            if ($word === '') continue;
            $options = [];
            $correctMeaning = rc_pdf_pick($w, ['correct','meaning','definition']);
            if ($correctMeaning !== '') $options[] = $correctMeaning;
            $distractors = is_array($w['distractors'] ?? null) ? $w['distractors'] : [];
            foreach ($distractors as $dist) { $txt = trim((string)$dist); if ($txt !== '') $options[] = $txt; }
            $vocabQs[] = ['word' => $word, 'options' => $options, 'correct' => 0];
        }
        if (empty($vocabQs)) $out .= '<div class="notes-box"></div>';
        else foreach ($vocabQs as $qi => $q) $out .= rc_pdf_question_box('What does "'.$q['word'].'" mean?', $q['options'], $q['correct'], $k, '', $qi + 1);
    }
    return $out.ws_foot();
}
PHP;

$source = preg_replace('/function\s+ws_writing\s*\(array\s+\$d,\s*int\s+\$n,\s*bool\s+\$k\):\s*string\s*\{.*?\n\}\n\n\/\* ── MATCH/s', $writingPatch . "\n\n/* ── MATCH", $source, 1);
$source = preg_replace('/function\s+ws_dictation\s*\(array\s+\$d,\s*int\s+\$n,\s*bool\s+\$k\):\s*string\s*\{.*?\n\}\n\n\/\* ── PRONUNCIATION/s', $dictationPatch . "\n\n/* ── PRONUNCIATION", $source, 1);
$source = preg_replace('/function\s+ws_reading\s*\(array\s+\$d,\s*int\s+\$n,\s*bool\s+\$k\):\s*string\s*\{.*?\n\}\n\n\/\* ── BUILD SECTIONS/s', $readingPatch . "\n\n/* ── BUILD SECTIONS", $source, 1);
$source = str_replace("case 'reading_comprehension':\$html = ws_reading(\$data, \$actN, \$isKey);    break;", "case 'reading':\n        case 'reading_activity':\n        case 'reading_comprehension_new':\n        case 'reading_comprehension_v2':\n        case 'reading_comp':\n        case 'rc':\n        case 'reading_comprehension':\$html = ws_reading(\$data, \$actN, \$isKey);    break;", $source);

ob_start();
eval('?>' . $source);
$html = ob_get_clean();

$printFontCss = <<<'CSS'

/* Shared PDF-preview styles for patched activities. */
.dict-grid {
  display: grid !important;
  grid-template-columns: repeat(2, minmax(0, 1fr)) !important;
  gap: 12px !important;
}
.dict-print-item {
  border: 1.5px solid #DCD7FF !important;
  border-radius: 14px !important;
  background: #fff !important;
  padding: 10px 12px !important;
  break-inside: avoid !important;
  page-break-inside: avoid !important;
}
.dict-print-top {
  display: flex !important;
  align-items: center !important;
  gap: 7px !important;
  margin-bottom: 8px !important;
}
.dict-num {
  width: 24px !important;
  height: 24px !important;
  display: inline-flex !important;
  align-items: center !important;
  justify-content: center !important;
  background: #7F77DD !important;
  color: #fff !important;
  border-radius: 999px !important;
  font-size: 11pt !important;
  font-weight: 900 !important;
  flex: 0 0 auto !important;
}
.dict-label { font-weight: 900 !important; }
.dict-img {
  width: 44mm !important;
  height: 34mm !important;
  max-width: 100% !important;
  border: 1px solid #EDE9FA !important;
  border-radius: 10px !important;
  overflow: hidden !important;
  margin: 6px auto 10px !important;
  background: #FAFAFE !important;
  display: flex !important;
  align-items: center !important;
  justify-content: center !important;
}
.dict-img img {
  width: 100% !important;
  height: 100% !important;
  max-width: 100% !important;
  max-height: 100% !important;
  object-fit: contain !important;
  display: block !important;
}
.dict-lines,
.wp-write-lines {
  display: grid !important;
  gap: 12px !important;
  margin-top: 10px !important;
  clear: both !important;
}
.dict-line,
.wp-write-line {
  height: 20px !important;
  border-bottom: 1.6px solid #000 !important;
}

/* Print layout + typography override: keep header/background colours, make worksheet printable. */
@page { size: auto; margin: 14mm 12mm 18mm 12mm; }
@media print {
  html, body { width: auto !important; height: auto !important; min-height: auto !important; overflow: visible !important; background: #fff !important; }
  body { margin: 0 !important; padding: 0 !important; -webkit-print-color-adjust: exact !important; print-color-adjust: exact !important; }
  .ws-body { width: 100% !important; max-width: 190mm !important; margin: 0 auto !important; padding: 0 0 8mm 0 !important; overflow: visible !important; }
  .ws-page, .unit-page, .print-page, .worksheet-page, .card-box, .ws-body > * { overflow: visible !important; height: auto !important; max-height: none !important; }
  .ws-sec { break-inside: auto !important; page-break-inside: auto !important; break-after: auto !important; page-break-after: auto !important; margin-bottom: 9mm !important; }
  .sec-head, .ibox, .ws-qb, .ws-wb, .wp-print-card, .dict-print-item, .fc-card, .mrow, .ws-or, .rc-qb, tr { break-inside: avoid !important; page-break-inside: avoid !important; }
  .sec-head { margin-top: 2mm !important; }
  .card-box { padding-bottom: 5mm !important; }
  .ws-body, .ws-body :is(.unit-sub,.instr-row,.itxt,.ws-qt,.ws-opt,.ws-expl,.ws-chip,.ws-fr,.ws-fill-prompt,.ws-wi,.ws-ma,.mrow,.ml,.mn,.ws-or,.dt-num,.rc-text,.rc-text *,.rc-meta,.fc-word,.tc-w,.wp-instruction,.wp-prompt-box,.wp-answer-key,.dict-label,.dict-answer,table.ws-tbl,table.ws-tbl td,table.ws-tbl th) { color: #000 !important; font-size: 12pt !important; line-height: 1.45 !important; }
  .ws-body :is(.sec-title,.unit-title) { color: #000 !important; font-size: 14pt !important; }
  .rc-hl { color: #000 !important; font-size: 12pt !important; font-weight: 800 !important; background: #FFF0E6 !important; border-bottom: 2px solid #F97316 !important; padding: 0 2px !important; }
  .wp-print-card, .wp-answer-key, .dict-answer { border-radius: 12px !important; }
  .wp-print-card { border: 1.5px solid #DCD7FF !important; background: #fff !important; padding: 12px 14px !important; margin: 12px 0 !important; break-inside: avoid !important; }
  .wp-print-head { display: flex !important; align-items: center !important; gap: 8px !important; margin-bottom: 8px !important; }
  .wp-prompt-title { color: #000 !important; font-weight: 900 !important; font-size: 12pt !important; }
  .wp-word-hint { margin-left: auto !important; color: #000 !important; font-size: 12pt !important; font-weight: 700 !important; }
  .wp-instruction { background: #F8F7FF !important; border-left: 4px solid #7F77DD !important; border-radius: 10px !important; padding: 8px 10px !important; margin: 8px 0 !important; font-weight: 800 !important; }
  .wp-prompt-box { border: 1.5px solid #EDE9FA !important; border-radius: 12px !important; background: #FAFAFE !important; padding: 10px 12px !important; margin: 8px 0 10px !important; font-weight: 800 !important; }
  .wp-answer-key, .dict-answer { border: 1.5px solid #9FE1CB !important; background: #F0FDF9 !important; padding: 8px 10px !important; margin-top: 8px !important; }
}
CSS;

if (strpos($html, '</style>') !== false) {
    $html = preg_replace('/<\/style>/', $printFontCss . "\n</style>", $html, 1);
} else {
    $html = str_replace('</head>', '<style>' . $printFontCss . '</style></head>', $html);
}

echo $html;
