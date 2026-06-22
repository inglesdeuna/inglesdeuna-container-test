<?php
/*
 * Thin wrapper for the printable unit worksheet.
 * The original renderer lives in unit_pdf_base.php. This wrapper injects
 * print-only typography overrides and patches Reading Comprehension export
 * support without changing the original renderer structure.
 */

$basePath = __DIR__ . '/unit_pdf_base.php';
$source = file_get_contents($basePath);
if ($source === false) {
    die('Worksheet renderer not found.');
}

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
    $out = '<div class="ws-qb rc-qb">';
    $out .= '<div class="ws-qt"><span class="qnum">'.$num.'</span>'.h($stem).'</div>';
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
    $out .= '</div>';
    return $out;
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
        if (empty($questions)) {
            $out .= '<div class="notes-box"></div>';
        } else {
            foreach ($questions as $qi => $q) {
                if (!is_array($q)) continue;
                $stem = rc_pdf_pick($q, ['stem','question','prompt'], 'Question '.($qi + 1));
                $options = rc_pdf_options($q);
                $correct = (int)($q['correct'] ?? 0);
                $feedback = trim((string)($q['feedback'] ?? $q['explanation'] ?? ''));
                $out .= rc_pdf_question_box($stem, $options, $correct, $k, $feedback, $qi + 1);
            }
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
            foreach ($distractors as $dist) {
                $txt = trim((string)$dist);
                if ($txt !== '') $options[] = $txt;
            }
            $vocabQs[] = ['word' => $word, 'options' => $options, 'correct' => 0];
        }
        if (empty($vocabQs)) {
            $out .= '<div class="notes-box"></div>';
        } else {
            foreach ($vocabQs as $qi => $q) {
                $out .= rc_pdf_question_box('What does "'.$q['word'].'" mean?', $q['options'], $q['correct'], $k, '', $qi + 1);
            }
        }
    }
    return $out.ws_foot();
}
PHP;

$source = preg_replace('/function\s+ws_reading\s*\(array\s+\$d,\s*int\s+\$n,\s*bool\s+\$k\):\s*string\s*\{.*?\n\}\n\n\/\* ── BUILD SECTIONS/s', $readingPatch . "\n\n/* ── BUILD SECTIONS", $source, 1);
$source = str_replace(
    "case 'reading_comprehension':\$html = ws_reading(\$data, \$actN, \$isKey);    break;",
    "case 'reading':\n        case 'reading_activity':\n        case 'reading_comprehension_new':\n        case 'reading_comprehension_v2':\n        case 'reading_comp':\n        case 'rc':\n        case 'reading_comprehension':\$html = ws_reading(\$data, \$actN, \$isKey);    break;",
    $source
);

ob_start();
eval('?>' . $source);
$html = ob_get_clean();

$printFontCss = <<<'CSS'

/* Print font override: keep header/background colours, make worksheet content readable. */
@media print {
  .ws-body,
  .ws-body :is(.unit-sub,.instr-row,.itxt,.ws-qt,.ws-opt,.ws-expl,.ws-chip,.ws-fr,.ws-fill-prompt,.ws-wi,.ws-ma,.mrow,.ml,.mn,.ws-or,.dt-num,.rc-text,.rc-text *,.rc-meta,.fc-word,.tc-w,table.ws-tbl,table.ws-tbl td,table.ws-tbl th) {
    color: #000 !important;
    font-size: 12pt !important;
    line-height: 1.45 !important;
  }

  .ws-body :is(.sec-title,.unit-title) {
    color: #000 !important;
    font-size: 14pt !important;
  }

  .rc-hl {
    color: #000 !important;
    font-size: 12pt !important;
    font-weight: 800 !important;
    background: #FFF0E6 !important;
    border-bottom: 2px solid #F97316 !important;
    padding: 0 2px !important;
  }
}
CSS;

if (strpos($html, '</style>') !== false) {
    $html = preg_replace('/<\/style>/', $printFontCss . "\n</style>", $html, 1);
} else {
    $html = str_replace('</head>', '<style>' . $printFontCss . '</style></head>', $html);
}

echo $html;
