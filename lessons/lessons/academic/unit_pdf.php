<?php
/*
 * Printable unit worksheet wrapper.
 * Keeps the original renderer in unit_pdf_base.php and patches printable
 * activity exports that need newer worksheet support.
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

$roleplayPatch = <<<'PHP'
function pdf_pick(array $a, array $keys, string $fallback = ''): string {
    foreach ($keys as $key) {
        if (isset($a[$key]) && trim((string)$a[$key]) !== '') return trim((string)$a[$key]);
    }
    return $fallback;
}
function pdf_lines(int $lines = 6, string $class = 'rp-pdf-lines'): string {
    $lines = max(2, min(10, $lines));
    return '<div class="'.$class.'">'.str_repeat('<div class="rp-pdf-write-line"></div>', $lines).'</div>';
}
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
    $title = $scene['title'] !== '' ? $scene['title'] : 'Roleplay';
    $out = ws_head($n, 'Roleplay Activity', $title, 'Read the description. Practice the dialogue with a partner. Use the lines below for the class activity.', $k, 'card-open rp-pdf-open');

    $out .= '<div class="rp-pdf-scene">';
    if ($scene['scenario'] !== '') {
        $out .= '<div class="rp-pdf-desc"><span class="rp-pdf-label">Description</span>'.nl2br(h($scene['scenario'])).'</div>';
    }
    $out .= '<div class="rp-pdf-roles">';
    $out .= '<div><span class="rp-pdf-label">Agent role</span><strong>'.h($scene['agentRole']).'</strong></div>';
    $out .= '<div><span class="rp-pdf-label">Student role</span><strong>'.h($scene['studentRole']).'</strong></div>';
    if ($scene['level'] !== '') $out .= '<div><span class="rp-pdf-label">Level</span><strong>'.h($scene['level']).'</strong></div>';
    $out .= '</div></div>';

    $out .= '<div class="rp-pdf-dialogue">';
    if (empty($turns)) {
        $out .= '<div class="rp-pdf-turn"><div class="rp-pdf-line agent"><span>'.h($scene['agentRole']).':</span></div><div class="rp-pdf-line student"><span>'.h($scene['studentRole']).':</span></div></div>';
    } else {
        foreach ($turns as $i => $turn) {
            $agent = rp_pdf_text($turn, ['agent','teacherLine','teacher_line','agentLine','agent_line','prompt','question']);
            $student = rp_pdf_text($turn, ['ideal','studentLine','student_line','answer','model','model_answer','hint']);
            $hint = rp_pdf_text($turn, ['hint','support','cue']);
            $criteria = rp_pdf_text($turn, ['criteria','objective']);
            if ($agent === '' && $student === '' && $hint === '') continue;
            $out .= '<div class="rp-pdf-turn">';
            $out .= '<div class="rp-pdf-turn-num">'.($i + 1).'</div>';
            if ($agent !== '') $out .= '<div class="rp-pdf-line agent"><span>'.h($scene['agentRole']).':</span> '.nl2br(h($agent)).'</div>';
            if ($student !== '') {
                $studentLabel = $k ? 'Model '.$scene['studentRole'] : $scene['studentRole'];
                $out .= '<div class="rp-pdf-line student"><span>'.h($studentLabel).':</span> '.nl2br(h($student)).'</div>';
            } elseif ($hint !== '') {
                $out .= '<div class="rp-pdf-line student"><span>'.h($scene['studentRole']).':</span> '.nl2br(h($hint)).'</div>';
            }
            if ($k && $criteria !== '') $out .= '<div class="rp-pdf-criteria"><strong>Criteria:</strong> '.h($criteria).'</div>';
            $out .= '</div>';
        }
    }
    $out .= '</div>';

    $out .= '<div class="rp-pdf-class"><span class="rp-pdf-label">Class activity</span><p>Write your own answers or notes for the roleplay.</p>'.pdf_lines(6, 'rp-pdf-lines').'</div>';
    return $out.ws_foot();
}
PHP;

$source = preg_replace(
    '/\/\* ── BUILD SECTIONS/s',
    $roleplayPatch . "\n\n/* ── BUILD SECTIONS",
    $source,
    1
);
$source = str_replace(
    "case 'reading_comprehension':\$html = ws_reading(\$data, \$actN, \$isKey);    break;",
    "case 'reading_comprehension':\$html = ws_reading(\$data, \$actN, \$isKey);    break;\n        case 'roleplay':\n        case 'roleplay_activity':\n        case 'roleplay_kids':         \$html = ws_roleplay(\$data, \$actN, \$isKey); break;",
    $source
);

ob_start();
eval('?>' . $source);
$html = ob_get_clean();

$strictLineCss = <<<'CSS'

/* Uniform printable line and outline style. */
:root { --pdf-line: 1.4px solid #000; --pdf-gap: 12px; }
.ws-body :is(
  .card-box,.ibox,.ws-qb,.ws-opt,.ws-bank,.ws-fr,.ws-fill-prompt,
  .ws-wb,.wp-print-card,.wp-instruction,.wp-prompt-box,.wp-answer-key,
  .dict-print-item,.dict-answer,.mc-img-opt,.mc-frame,.pdf-act-img,
  .rp-pdf-scene,.rp-pdf-roles > div,.rp-pdf-turn,.rp-pdf-class,.rp-pdf-criteria
) {
  border-color: #000 !important;
  border-width: 1.4px !important;
  border-style: solid !important;
  outline: none !important;
}
.ws-body :is(.dict-line,.wp-write-line,.rp-pdf-write-line,.ws-open-line,.sf-line,.fc-bline,.pr-blank) {
  height: 20px !important;
  border: 0 !important;
  border-bottom: 1.4px solid #000 !important;
  background: transparent !important;
}
.ws-body :is(.dict-lines,.wp-write-lines,.rp-pdf-lines,.ws-open-lines,.ws-lines) {
  display: grid !important;
  gap: 12px !important;
  margin-top: 10px !important;
}
.ws-body :is(.rp-pdf-line.agent,.rp-pdf-line.student,.wp-instruction,.rc-hl) {
  border-left: 1.4px solid #000 !important;
  border-bottom-color: #000 !important;
}
.ws-body :is(.rp-pdf-label,.rp-pdf-line span,.ws-body *, .itxt, .ws-qt, .ws-opt, .dict-label, .rp-pdf-class p) {
  color: #000 !important;
}
.rp-pdf-scene { background:#fff !important; padding:12px !important; margin-bottom:12px !important; }
.rp-pdf-desc { font-weight:800 !important; line-height:1.45 !important; margin-bottom:12px !important; }
.rp-pdf-label { display:block !important; font-size:9pt !important; font-weight:900 !important; text-transform:uppercase !important; letter-spacing:.08em !important; margin-bottom:4px !important; }
.rp-pdf-roles { display:grid !important; grid-template-columns:repeat(3,minmax(0,1fr)) !important; gap:12px !important; }
.rp-pdf-roles > div { padding:8px 10px !important; background:#fff !important; }
.rp-pdf-dialogue { display:grid !important; gap:12px !important; margin:12px 0 !important; }
.rp-pdf-turn { position:relative !important; padding:10px 12px 10px 42px !important; background:#fff !important; break-inside:avoid !important; page-break-inside:avoid !important; }
.rp-pdf-turn-num { position:absolute !important; left:10px !important; top:10px !important; width:23px !important; height:23px !important; border-radius:999px !important; display:inline-flex !important; align-items:center !important; justify-content:center !important; background:#fff !important; color:#000 !important; border:1.4px solid #000 !important; font-weight:900 !important; }
.rp-pdf-line { line-height:1.45 !important; margin:6px 0 !important; }
.rp-pdf-line.agent,.rp-pdf-line.student { padding-left:9px !important; }
.rp-pdf-class { padding:12px !important; margin-top:12px !important; background:#fff !important; break-inside:avoid !important; page-break-inside:avoid !important; }
@media print {
  .ws-body :is(.card-box,.ibox,.ws-qb,.ws-opt,.ws-bank,.ws-fr,.ws-fill-prompt,.ws-wb,.wp-print-card,.wp-instruction,.wp-prompt-box,.wp-answer-key,.dict-print-item,.dict-answer,.mc-img-opt,.mc-frame,.pdf-act-img,.rp-pdf-scene,.rp-pdf-roles > div,.rp-pdf-turn,.rp-pdf-class,.rp-pdf-criteria) {
    border-color:#000 !important;
    border-width:1.4px !important;
    border-style:solid !important;
  }
}
CSS;

if (strpos($html, '</style>') !== false) {
    $html = preg_replace('/<\/style>/', $strictLineCss . "\n</style>", $html, 1);
} else {
    $html = str_replace('</head>', '<style>' . $strictLineCss . '</style></head>', $html);
}

echo $html;
