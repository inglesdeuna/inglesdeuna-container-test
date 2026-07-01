<?php
/*
 * Worksheet PDF visual wrapper.
 *
 * The original data fetch, activity ordering, student/key modes and renderer
 * remain in unit_pdf_base.php. This file only patches printable-only activity
 * support and injects a unified visual system for the worksheet export.
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
function rp_pdf_pick(array $a, array $keys, string $fallback = ''): string {
    foreach ($keys as $key) {
        if (isset($a[$key]) && trim((string)$a[$key]) !== '') return trim((string)$a[$key]);
    }
    return $fallback;
}
function rp_pdf_lines(int $lines = 6): string {
    $lines = max(2, min(10, $lines));
    return '<div class="rp-pdf-lines">'.str_repeat('<div class="writeline"></div>', $lines).'</div>';
}
function rp_pdf_scene(array $d): array {
    $scene = isset($d['scene']) && is_array($d['scene']) ? $d['scene'] : $d;
    return [
        'title' => rp_pdf_pick($scene, ['title'], rp_pdf_pick($d, ['title'], 'Roleplay')),
        'scenario' => rp_pdf_pick($scene, ['scenario','description','instructions'], rp_pdf_pick($d, ['description','instructions','scenario'], '')),
        'agentRole' => rp_pdf_pick($scene, ['agentRole','agent_role','teacherRole','teacher_role'], 'Agent'),
        'studentRole' => rp_pdf_pick($scene, ['studentRole','student_role','learnerRole','learner_role'], 'Student'),
        'level' => rp_pdf_pick($scene, ['level'], rp_pdf_pick($d, ['level'], '')),
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
    return rp_pdf_pick($turn, $keys);
}
function ws_roleplay(array $d, int $n, bool $k): string {
    $scene = rp_pdf_scene($d);
    $turns = rp_pdf_turns($d);
    $title = $scene['title'] !== '' ? $scene['title'] : 'Roleplay';
    $out = ws_head($n, 'Roleplay Activity', $title, 'Read the description. Practice the dialogue with a partner. Use the lines below for the class activity.', $k, 'card-open rp-card');

    $out .= '<div class="rp-scene">';
    if ($scene['scenario'] !== '') {
        $out .= '<div class="rp-desc"><span class="rp-label">Description</span>'.nl2br(h($scene['scenario'])).'</div>';
    }
    $out .= '<div class="rp-roles">';
    $out .= '<div><span class="rp-label">Agent role</span><strong>'.h($scene['agentRole']).'</strong></div>';
    $out .= '<div><span class="rp-label">Student role</span><strong>'.h($scene['studentRole']).'</strong></div>';
    if ($scene['level'] !== '') $out .= '<div><span class="rp-label">Level</span><strong>'.h($scene['level']).'</strong></div>';
    $out .= '</div></div>';

    $out .= '<div class="rp-dialogue">';
    if (empty($turns)) {
        $out .= '<div class="rp-turn"><div class="rp-line agent"><span>'.h($scene['agentRole']).':</span></div><div class="rp-line student"><span>'.h($scene['studentRole']).':</span></div></div>';
    } else {
        foreach ($turns as $i => $turn) {
            $agent = rp_pdf_text($turn, ['agent','teacherLine','teacher_line','agentLine','agent_line','prompt','question']);
            $student = rp_pdf_text($turn, ['ideal','studentLine','student_line','answer','model','model_answer','hint']);
            $hint = rp_pdf_text($turn, ['hint','support','cue']);
            $criteria = rp_pdf_text($turn, ['criteria','objective']);
            if ($agent === '' && $student === '' && $hint === '') continue;
            $out .= '<div class="rp-turn">';
            $out .= '<div class="rp-turn-num">'.($i + 1).'</div>';
            if ($agent !== '') $out .= '<div class="rp-line agent"><span>'.h($scene['agentRole']).':</span> '.nl2br(h($agent)).'</div>';
            if ($student !== '') {
                $studentLabel = $k ? 'Model '.$scene['studentRole'] : $scene['studentRole'];
                $out .= '<div class="rp-line student"><span>'.h($studentLabel).':</span> '.nl2br(h($student)).'</div>';
            } elseif ($hint !== '') {
                $out .= '<div class="rp-line student"><span>'.h($scene['studentRole']).':</span> '.nl2br(h($hint)).'</div>';
            }
            if ($k && $criteria !== '') $out .= '<div class="rp-criteria"><strong>Criteria:</strong> '.h($criteria).'</div>';
            $out .= '</div>';
        }
    }
    $out .= '</div>';

    $out .= '<div class="rp-class"><span class="rp-label">Class activity</span><p>Write your own answers or notes for the roleplay.</p>'.rp_pdf_lines(6).'</div>';
    return $out.ws_foot();
}
PHP;

// Add printable roleplay renderer without changing existing fetch/order/mode logic.
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

/* =========================
   ONES worksheet v2 system
   ========================= */
:root{
  --ink:#1A1A1A; --ink-soft:#4A4A4A;
  --orange:#F97316; --purple:#7F77DD;
  --peach:#FFF6EE; --lila:#F5F3FF; --line:#E7E4F0;
}
*{box-sizing:border-box;}
.ws-body{color:var(--ink) !important;font-family:'Nunito',Arial,sans-serif !important;}
.ws-sec,.section{margin-bottom:36px !important;break-inside:auto !important;page-break-inside:auto !important;}
.sec-head,.section-head{display:flex !important;align-items:baseline !important;gap:12px !important;margin:0 0 14px !important;}
.snum,.num{width:26px !important;height:26px !important;border-radius:50% !important;background:var(--orange) !important;color:#fff !important;font-family:'Fredoka','Fredoka One',Arial,sans-serif !important;font-weight:600 !important;font-size:13px !important;display:flex !important;align-items:center !important;justify-content:center !important;flex-shrink:0 !important;}
.sec-meta{display:flex !important;align-items:baseline !important;gap:12px !important;flex-wrap:wrap !important;}
.sec-title,.section-head h2{font-family:'Fredoka','Fredoka One',Arial,sans-serif !important;font-weight:600 !important;font-size:17px !important;line-height:1.2 !important;margin:0 !important;color:var(--ink) !important;}
.sec-kicker,.section-head .kind{font-size:10px !important;font-weight:700 !important;letter-spacing:.06em !important;color:var(--purple) !important;text-transform:uppercase !important;}
.key-tag{margin-left:8px !important;background:transparent !important;color:var(--purple) !important;border:0 !important;padding:0 !important;font-size:10px !important;font-weight:700 !important;}
.instructions{font-size:13px !important;color:var(--ink-soft) !important;font-style:italic !important;margin:0 0 16px 38px !important;line-height:1.45 !important;}
.card-box,.card{border:1.5px solid var(--line) !important;border-radius:14px !important;padding:22px 26px !important;background:#fff !important;box-shadow:none !important;}
.card-open{padding:22px 26px !important;border:1.5px solid var(--line) !important;border-radius:14px !important;}
.ibox{border:0 !important;background:transparent !important;border-radius:0 !important;padding:0 !important;margin:0 0 16px 38px !important;color:var(--ink-soft) !important;font-style:italic !important;display:block !important;}
.ilbl{display:none !important;}.itxt{font-size:13px !important;color:var(--ink-soft) !important;line-height:1.45 !important;}

/* Shared item rhythm */
.ws-qb,.q,.fill-block,.dt-item,.dict-print-item,.mrow,.ws-or,.ws-wb,.rp-turn{margin-bottom:20px !important;break-inside:avoid !important;page-break-inside:avoid !important;}
.ws-qb:last-child,.q:last-child,.fill-block:last-child,.dt-item:last-child,.dict-print-item:last-child,.mrow:last-child,.ws-or:last-child,.ws-wb:last-child,.rp-turn:last-child{margin-bottom:0 !important;}
.ws-qt,.q-text{font-size:14px !important;font-weight:700 !important;line-height:1.45 !important;margin:0 0 10px !important;color:var(--ink) !important;}
.qnum{width:22px !important;height:22px !important;border-radius:50% !important;background:var(--purple) !important;color:#fff !important;display:inline-flex !important;align-items:center !important;justify-content:center !important;font-weight:700 !important;font-size:10px !important;flex-shrink:0 !important;margin-right:8px !important;}

/* Multiple choice / quiz */
.ws-opts,.options{display:flex !important;gap:12px !important;flex-wrap:wrap !important;margin:8px 0 0 !important;padding-left:0 !important;}
.ws-opt,.opt{display:flex !important;align-items:center !important;gap:9px !important;border:1.5px solid var(--line) !important;border-radius:10px !important;padding:9px 14px !important;font-size:13px !important;line-height:1.35 !important;flex:1 1 220px !important;min-height:auto !important;color:var(--ink) !important;background:#fff !important;}
.opt-l,.letter{width:20px !important;height:20px !important;border-radius:50% !important;border:1.5px solid var(--ink) !important;background:#fff !important;color:var(--ink) !important;font-size:11px !important;font-weight:700 !important;display:flex !important;align-items:center !important;justify-content:center !important;flex-shrink:0 !important;}
.ws-ck{background:#fff !important;border-color:var(--ink) !important;}.ws-ck .opt-l{background:#fff !important;color:var(--ink) !important;border-color:var(--ink) !important;}

/* Vocabulary / flashcards */
.fc-grid,.flash-grid{display:grid !important;grid-template-columns:repeat(4,minmax(0,1fr)) !important;gap:18px !important;}
.fc-card,.flash{border:0 !important;background:transparent !important;padding:0 !important;box-shadow:none !important;display:flex !important;flex-direction:column !important;break-inside:avoid !important;page-break-inside:avoid !important;}
.fc-img,.pr-img,.mc-frame,.pdf-act-img,.dict-img{border:0 !important;background:var(--lila) !important;border-radius:10px !important;overflow:hidden !important;margin:0 0 10px !important;}
.fc-img img,.pr-img img,.mc-frame img,.pdf-act-img img,.dict-img img{width:100% !important;height:100% !important;object-fit:contain !important;display:block !important;}
.fc-word,.fc-blbl,.writeline-label{font-size:9px !important;font-weight:700 !important;letter-spacing:.05em !important;color:var(--ink-soft) !important;text-transform:uppercase !important;text-align:center !important;margin:0 0 6px !important;}
.fc-blank-zone{margin-top:6px !important;}

/* Writing lines: always black */
.writeline,.dict-line,.wp-write-line,.rp-pdf-write-line,.rp-pdf-lines .writeline,.ws-open-line,.ws-line,.fc-bline,.pr-blank,.sf-line{border:0 !important;border-bottom:2px solid var(--ink) !important;height:1px !important;background:transparent !important;opacity:1 !important;}
.u,.ws-inline-blank{display:inline-block !important;border:0 !important;border-bottom:2px solid var(--ink) !important;min-width:90px !important;height:1px !important;margin:0 3px !important;background:transparent !important;vertical-align:baseline !important;}
.dict-lines,.wp-write-lines,.rp-pdf-lines,.ws-open-lines,.ws-lines{display:grid !important;gap:12px !important;margin-top:10px !important;padding:0 !important;}
.ws-open-line::before{display:none !important;}

/* Fill in blanks */
.ws-bank,.wordbank{background:var(--peach) !important;border:0 !important;border-radius:10px !important;padding:14px 18px !important;font-size:12px !important;margin:0 0 18px !important;line-height:1.9 !important;}
.ws-blbl{color:var(--orange) !important;font-family:'Fredoka','Fredoka One',Arial,sans-serif !important;font-weight:600 !important;font-size:11px !important;letter-spacing:.04em !important;text-transform:uppercase !important;margin-right:8px !important;}
.ws-chip,.wordbank span{display:inline-block !important;border:1.3px solid var(--line) !important;border-radius:16px !important;padding:3px 12px !important;margin:2px 3px !important;background:#fff !important;color:var(--ink) !important;font-size:12px !important;}
.ws-fr,.blank-sentence{font-size:14px !important;line-height:2.1 !important;margin:0 0 14px !important;padding:0 !important;border:0 !important;background:transparent !important;color:var(--ink) !important;}
.ws-fn{width:20px !important;height:20px !important;border-radius:50% !important;background:var(--purple) !important;color:#fff !important;font-size:10px !important;font-weight:700 !important;display:inline-flex !important;align-items:center !important;justify-content:center !important;margin-right:8px !important;}
.ws-fb{line-height:2.1 !important;color:var(--ink) !important;}

/* Dictation */
.dict-grid{display:grid !important;grid-template-columns:1fr 1fr !important;gap:24px !important;}
.dict-print-item,.dt-item{border:0 !important;background:transparent !important;padding:0 !important;box-shadow:none !important;display:flex !important;gap:16px !important;}
.dict-print-top{display:flex !important;align-items:center !important;gap:8px !important;margin-bottom:8px !important;}
.dict-num,.dt-num{width:26px !important;height:26px !important;border-radius:10px !important;background:var(--lila) !important;color:var(--purple) !important;display:flex !important;align-items:center !important;justify-content:center !important;font-family:'Fredoka','Fredoka One',Arial,sans-serif !important;font-weight:600 !important;font-size:13px !important;flex-shrink:0 !important;}
.dt-write{flex:1 !important;}.dict-label{font-weight:700 !important;color:var(--ink) !important;}

/* Matching / order */
.mrow,.ws-or{border:0 !important;border-bottom:1.5px solid var(--line) !important;border-radius:0 !important;background:#fff !important;padding:0 0 12px !important;color:var(--ink) !important;}
.ws-ob{border:1.5px solid var(--ink) !important;background:#fff !important;}

/* Roleplay printable */
.rp-scene,.rp-turn,.rp-class{border:0 !important;background:#fff !important;padding:0 !important;}
.rp-desc{font-size:14px !important;line-height:1.5 !important;margin-bottom:18px !important;color:var(--ink) !important;}
.rp-label{display:block !important;font-size:10px !important;font-weight:700 !important;letter-spacing:.06em !important;color:var(--purple) !important;text-transform:uppercase !important;margin-bottom:6px !important;}
.rp-roles{display:grid !important;grid-template-columns:repeat(3,minmax(0,1fr)) !important;gap:12px !important;margin-bottom:20px !important;}
.rp-roles>div{border:1.5px solid var(--line) !important;border-radius:10px !important;padding:9px 14px !important;background:#fff !important;}
.rp-dialogue{display:grid !important;gap:20px !important;margin:0 0 20px !important;}
.rp-turn{position:relative !important;padding-left:34px !important;}
.rp-turn-num{position:absolute !important;left:0 !important;top:0 !important;width:22px !important;height:22px !important;border-radius:50% !important;background:var(--purple) !important;color:#fff !important;display:flex !important;align-items:center !important;justify-content:center !important;font-size:10px !important;font-weight:700 !important;}
.rp-line{font-size:14px !important;line-height:1.55 !important;margin:0 0 8px !important;color:var(--ink) !important;border:0 !important;padding:0 !important;}
.rp-line span{font-weight:700 !important;color:var(--ink) !important;}.rp-class p{font-size:13px !important;margin:0 0 10px !important;color:var(--ink-soft) !important;font-style:italic !important;}

/* Print consistency */
@media print{
  .ws-sec,.section{margin-bottom:36px !important;}
  .card-box,.card{padding:22px 26px !important;}
  .writeline,.dict-line,.wp-write-line,.ws-open-line,.fc-bline,.pr-blank,.sf-line{border-bottom:2px solid var(--ink) !important;}
}
CSS;

$worksheetJs = <<<'JS'
<script>
(function(){
  function cleanText(node){
    return (node ? node.textContent : '').replace(/Answer Key/g,'').replace(/\s+/g,' ').trim();
  }
  function normalizeSections(){
    document.querySelectorAll('.ws-sec').forEach(function(section){
      section.classList.add('section');
      var head = section.querySelector(':scope > .sec-head');
      var card = section.querySelector(':scope > .card-box');
      if (head) head.classList.add('section-head');
      if (card) card.classList.add('card');
      var num = head ? head.querySelector('.snum') : null;
      if (num) num.classList.add('num');

      var meta = head ? head.querySelector('.sec-meta') : null;
      var kicker = meta ? meta.querySelector('.sec-kicker') : null;
      var title = meta ? meta.querySelector('.sec-title') : null;
      if (kicker && !kicker.classList.contains('kind')) kicker.classList.add('kind');
      if (title && title.tagName !== 'H2') {
        var h2 = document.createElement('h2');
        h2.className = title.className;
        h2.textContent = cleanText(title);
        title.replaceWith(h2);
      }

      if (card) {
        var ibox = card.querySelector(':scope > .ibox');
        if (ibox) {
          var p = document.createElement('p');
          p.className = 'instructions';
          var itxt = ibox.querySelector('.itxt');
          p.textContent = cleanText(itxt || ibox);
          section.insertBefore(p, card);
          ibox.remove();
        }
      }
    });

    document.querySelectorAll('.ws-open-line,.ws-line,.dict-line,.wp-write-line,.fc-bline,.pr-blank,.sf-line').forEach(function(line){
      line.classList.add('writeline');
    });
    document.querySelectorAll('.ws-inline-blank').forEach(function(blank){
      blank.classList.add('u');
    });
  }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', normalizeSections);
  else normalizeSections();
})();
</script>
JS;

if (strpos($html, '</style>') !== false) {
    $html = preg_replace('/<\/style>/', $worksheetCss . "\n</style>", $html, 1);
} else {
    $html = str_replace('</head>', '<style>' . $worksheetCss . '</style></head>', $html);
}

$html = str_replace('</body>', $worksheetJs . "\n</body>", $html);

echo $html;
