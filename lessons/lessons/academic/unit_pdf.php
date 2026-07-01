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
$source = preg_replace('/function\s+ws_head\s*\(int\s+\$n,\s*string\s+\$kicker,\s*string\s+\$title,\s*string\s+\$instr,\s*bool\s+\$isKey,\s*string\s+\$cardClass\s*=\s*\'\'\):\s*string\s*\{.*?\n\}/s', $headPatch, $source, 1);

$activityPatch = <<<'PHP'
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
        $out .= '<div class="pr-print-main"><div class="pr-print-word">'.h($word).'</div>';
        if ($ipa !== '') $out .= '<div class="pr-print-ipa">'.h($ipa).'</div>';
        elseif ($ph !== '') $out .= '<div class="pr-print-ipa">'.h($ph).'</div>';
        if ($meaning !== '') $out .= '<div class="pr-print-meaning">'.h($meaning).'</div>';
        if ($example !== '') $out .= '<div class="pr-print-example">'.h($example).'</div>';
        $out .= '</div></div>';
    }
    return $out.ws_foot();
}

function rc_pdf_text(array $d): array { if(isset($d['texts'])&&is_array($d['texts'])&&!empty($d['texts'])){ $t=is_array($d['texts'][0])?$d['texts'][0]:[]; if(!isset($t['root_title']))$t['root_title']=trim((string)($d['title']??'')); return $t; } return $d; }
function rc_pdf_words(array $t,array $d): array { $raw=is_array($t['words']??null)?$t['words']:(is_array($d['words']??null)?$d['words']:[]); $out=[]; foreach($raw as $w){ if(!is_array($w))continue; $word=pdf_pick($w,['word','text','term']); if($word==='')continue; $opts=[]; if(isset($w['options'])&&is_array($w['options'])) $opts=pdf_list($w['options']); else { $c=pdf_pick($w,['correct','meaning','definition']); if($c!=='')$opts[]=$c; foreach((array)($w['distractors']??[]) as $dist){$dist=trim((string)$dist); if($dist!=='')$opts[]=$dist;} } $out[]=['word'=>$word,'options'=>$opts]; } return $out; }
function rc_pdf_highlight(string $body,array $words,bool $numbered): string { $html=nl2br(h($body!==''?$body:'No passage text configured.')); foreach($words as $idx=>$w){ $term=trim((string)$w['word']); if($term==='')continue; $safe=preg_quote(h($term),'/'); $badge=$numbered?'<sup class="rc-num">'.($idx+1).'</sup>':''; $pat=preg_match('/\s/',$term)?'/('.$safe.')/iu':'/\b('.$safe.')\b/iu'; $html=preg_replace($pat,'<span class="rc-word">$1</span>'.$badge,$html,1); } return $html; }
function ws_reading(array $d,int $n,bool $k): string { $t=rc_pdf_text($d); $mode=strtolower(trim((string)($t['mode']??$d['mode']??'vocab'))); $isComp=in_array($mode,['comp','comprehension','reading_comprehension'],true); $title=pdf_pick($t,['title','root_title'],pdf_pick($d,['title'],'Reading Comprehension')); $body=pdf_pick($t,['body','text','passage','content'],pdf_pick($d,['body','text','passage','content'],'')); $genre=pdf_pick($t,['genre'],'Informational text'); $level=pdf_pick($t,['level'],pdf_pick($d,['level'],'')); $wc=(int)($t['wordCount']??$d['wordCount']??0); if($wc<=0&&$body!=='')$wc=count(preg_split('/\s+/',trim($body))?:[]); $words=rc_pdf_words($t,$d); $questions=is_array($t['questions']??null)?$t['questions']:(is_array($d['questions']??null)?$d['questions']:[]); $kicker=$isComp?'READING COMPREHENSION':'READING COMPREHENSION · VOCAB MEANING'; $out=ws_head($n,$kicker,$title,$isComp?'Read the passage carefully and answer the questions.':'Circle the correct meaning for each highlighted word.',$k,'card-open rc-print-card'); $out.='<div class="rc-kicker">'.h($kicker).'</div><h3 class="rc-title">'.h($title).'</h3><div class="rc-meta">'.h('Genre: '.$genre.($wc>0?' · '.$wc.' words':'').($level!==''?' · Level: '.$level:'')).'</div><div class="rc-passage">'.rc_pdf_highlight($body,$words,!$isComp).'</div>'; if($isComp){ if($words){$out.='<div class="rc-vocab-box"><div class="rc-box-title">Vocabulary — write the meaning of each word</div><div class="rc-meaning-grid">'; foreach($words as $w)$out.='<div class="rc-meaning-item"><span>'.h($w['word']).'</span><span class="dotline"></span></div>'; $out.='</div></div>';} $out.='<div class="rc-section-label">Comprehension questions</div>'; foreach($questions as $i=>$q){ if(!is_array($q))continue; $stem=pdf_pick($q,['stem','question','prompt']); $opts=pdf_list($q['options']??[]); $out.='<div class="rc-question"><div class="rc-q-stem"><strong>'.($i+1).'.</strong> '.h($stem).'</div>'; if($opts){$out.='<div class="rc-options">'; foreach($opts as $j=>$op){$label=preg_match('/^[A-D][\.)]\s*/i',$op)?$op:(chr(65+$j).'. '.$op); $out.='<div class="rc-option"><span class="radio"></span>'.h($label).'</div>';}$out.='</div>';} else $out.='<div class="rc-open-lines"><div class="dotline"></div><div class="dotline"></div></div>'; $out.='</div>';}} else { $out.='<div class="rc-section-label purple">What do the highlighted words mean?</div>'; foreach($words as $i=>$w){$out.='<div class="rc-vocab-card"><div class="rc-card-word"><span class="rc-num solid">'.($i+1).'</span><strong>'.h($w['word']).'</strong></div>'; foreach($w['options'] as $op)$out.='<div class="rc-vocab-option"><span class="radio"></span>'.h($op).'</div>'; $out.='</div>';} $out.='<div class="rc-bonus"><strong>BONUS — use one highlighted word in your own sentence</strong><div class="dotline"></div></div>'; } return $out.ws_foot(); }

function wp_pdf_items(array $d): array { if(isset($d['items'])&&is_array($d['items']))return array_values($d['items']); if(isset($d['questions'])&&is_array($d['questions']))return array_values($d['questions']); return [$d]; }
function ws_writing(array $d,int $n,bool $k): string { $title=pdf_pick($d,['title'],'Writing Practice'); $out=ws_head($n,'WRITING PRACTICE',$title,'',$k,'card-open wp-print'); $out.='<div class="wp-kicker">WRITING PRACTICE</div><h3 class="wp-title">'.h($title).'</h3>'; foreach(wp_pdf_items($d) as $idx=>$item){ if(!is_array($item))continue; $source=pdf_pick($item,['prompt_text','prompt','question','source_text','text','instruction'],pdf_pick($d,['prompt_text','prompt','source_text'],'')); $bank=[]; foreach(['word_bank','wordBank','keywords','vocabulary','words'] as $key){ if(isset($item[$key])){$bank=pdf_list($item[$key]);break;} if(isset($d[$key])){$bank=pdf_list($d[$key]);break;} } if($idx>0)$out.='<div class="wp-divider"></div>'; $out.='<div class="wp-source"><div class="wp-source-label">SOURCE TEXT — read and translate into English</div><div class="wp-source-text">'.nl2br(h($source)).'</div></div>'; if($bank)$out.='<div class="wp-use">Use at least four words from the box below in your translation.</div><div class="wp-bank"><div class="wp-bank-label">WORD BANK</div><div>'.h(implode(' · ',$bank)).'</div></div>'; $out.='<div class="wp-your">Your translation</div><div class="wp-lines">'.str_repeat('<div class="writeline"></div>',6).'</div>'; } $out.='<div class="wp-self"><div class="wp-self-title">SELF-CHECK — after your teacher reviews this page</div><span class="check green"></span>Correct words <span class="check coral"></span>Missing words <span class="check orange"></span>Extra words</div>'; return $out.ws_foot(); }

function ws_roleplay(array $d,int $n,bool $k): string { $scene=is_array($d['scene']??null)?$d['scene']:$d; $title=pdf_pick($scene,['title'],pdf_pick($d,['title'],'Roleplay')); $out=ws_head($n,'Roleplay Activity',$title,'Read the description. Practice the dialogue with a partner.',$k,'card-open rp-card'); $desc=pdf_pick($scene,['scenario','description','instructions']); if($desc!=='')$out.='<div class="rp-desc"><span class="rp-label">Description</span>'.nl2br(h($desc)).'</div>'; $turns=[]; foreach(['turns','dialogue','dialogs','lines','items'] as $key){ if(isset($d[$key])&&is_array($d[$key])){$turns=$d[$key];break;} } foreach($turns as $i=>$turn){ if(!is_array($turn))continue; $a=pdf_pick($turn,['agent','teacherLine','agentLine','prompt','question']); $s=pdf_pick($turn,['ideal','studentLine','answer','model','hint']); $out.='<div class="rp-turn"><div class="rp-turn-num">'.($i+1).'</div>'; if($a!=='')$out.='<div class="rp-line"><strong>Agent:</strong> '.nl2br(h($a)).'</div>'; if($s!=='')$out.='<div class="rp-line"><strong>Student:</strong> '.nl2br(h($s)).'</div>'; $out.='</div>'; } $out.='<div class="rp-class"><span class="rp-label">Class activity</span><div class="rp-pdf-lines">'.str_repeat('<div class="writeline"></div>',6).'</div></div>'; return $out.ws_foot(); }
PHP;

$replacements = [
    'ws_flashcards' => '/function\s+ws_flashcards\s*\(array\s+\$d,\s*int\s+\$n,\s*bool\s+\$k\):\s*string\s*\{.*?\n\}\n\n\/\* ── QUIZ/s',
    'ws_pronunciation' => '/function\s+ws_pronunciation\s*\(array\s+\$d,\s*int\s+\$n,\s*bool\s+\$k\):\s*string\s*\{.*?\n\}\n\n\/\* ── POWERPOINT/s',
    'ws_reading' => '/function\s+ws_reading\s*\(array\s+\$d,\s*int\s+\$n,\s*bool\s+\$k\):\s*string\s*\{.*?\n\}\n\n\/\* ── BUILD SECTIONS/s',
    'ws_writing' => '/function\s+ws_writing\s*\(array\s+\$d,\s*int\s+\$n,\s*bool\s+\$k\):\s*string\s*\{.*?\n\}\n\n\/\* ── MATCH/s',
];
$source = preg_replace($replacements['ws_flashcards'], $activityPatch."\n\n/* ── QUIZ", $source, 1);
$source = preg_replace($replacements['ws_pronunciation'], "/* pronunciation replaced above */\n\n/* ── POWERPOINT", $source, 1);
$source = preg_replace($replacements['ws_reading'], "/* reading replaced above */\n\n/* ── BUILD SECTIONS", $source, 1);
$source = preg_replace($replacements['ws_writing'], "/* writing replaced above */\n\n/* ── MATCH", $source, 1);
$source = str_replace("case 'reading_comprehension':\$html = ws_reading(\$data, \$actN, \$isKey);    break;", "case 'reading_comprehension':\$html = ws_reading(\$data, \$actN, \$isKey);    break;\n        case 'roleplay':\n        case 'roleplay_activity':\n        case 'roleplay_kids':         \$html = ws_roleplay(\$data, \$actN, \$isKey); break;", $source);

ob_start();
eval('?>'.$source);
$html = ob_get_clean();

$worksheetCss = <<<'CSS'
:root{--ink:#111;--ink-soft:#9B8FCC;--orange:#F97316;--orange-light:#FFF0E6;--orange-dark:#B35112;--purple:#7F77DD;--purple-dark:#3C3489;--purple-text:#534AB7;--lila:#F5F3FF;--lila2:#EEEDFE;--line:#EDE9FA;--dot:#D5D0F0;--green:#1D9E75;--coral:#D85A30}*{box-sizing:border-box}.ws-body{font-family:'Nunito',Arial,sans-serif!important;color:var(--ink)!important}.ws-sec,.section{margin-bottom:36px!important}.sec-head,.section-head{display:flex!important;align-items:baseline!important;gap:12px!important;margin:0 0 14px!important}.snum,.num{width:26px!important;height:26px!important;border-radius:50%!important;background:var(--orange)!important;color:#fff!important;font-family:'Fredoka','Fredoka One',Arial,sans-serif!important;font-weight:600!important;font-size:13px!important;display:flex!important;align-items:center!important;justify-content:center!important;flex-shrink:0!important}.sec-meta{display:flex!important;align-items:baseline!important;gap:12px!important;flex-wrap:wrap!important}.sec-kicker,.kind{font-size:10px!important;font-weight:700!important;letter-spacing:.06em!important;color:var(--purple)!important;text-transform:uppercase!important}.sec-title{font-family:'Fredoka','Fredoka One',Arial,sans-serif!important;font-weight:600!important;font-size:17px!important;margin:0!important;color:var(--ink)!important}.instructions{font-size:13px!important;color:#4A4A4A!important;font-style:italic!important;margin:0 0 16px 38px!important}.card-box,.card{border:1.5px solid var(--line)!important;border-radius:14px!important;padding:22px 26px!important;background:#fff!important;box-shadow:none!important}.ibox{display:none!important}.writeline,.ws-open-line,.fc-bline,.pr-blank{border:0!important;border-bottom:2px solid var(--ink)!important;height:1px!important;background:transparent!important;opacity:1!important}.fc-print-grid{display:grid!important;grid-template-columns:repeat(3,minmax(0,1fr))!important;gap:18px!important}.fc-print-card{border:1.5px solid var(--line)!important;border-radius:14px!important;padding:14px!important;break-inside:avoid!important}.fc-print-img{height:95px!important;background:var(--lila)!important;border-radius:10px!important;display:flex!important;align-items:center!important;justify-content:center!important;margin-bottom:10px!important;overflow:hidden!important}.fc-print-img img,.pr-print-img img{max-width:100%!important;max-height:100%!important;object-fit:contain!important}.fc-print-word,.pr-print-word{font-family:'Fredoka','Fredoka One',Arial,sans-serif!important;font-weight:700!important;color:var(--purple-dark)!important;font-size:15px!important;margin-bottom:4px!important}.fc-print-ipa,.pr-print-ipa{font-style:italic!important;color:var(--orange-dark)!important;font-size:12px!important;margin-bottom:5px!important}.fc-print-meaning,.pr-print-meaning{font-size:12px!important;line-height:1.35!important;color:var(--ink)!important;margin-bottom:5px!important}.fc-print-example,.pr-print-example{font-size:11px!important;line-height:1.35!important;color:#4A4A4A!important}.pr-print-row{display:grid!important;grid-template-columns:26px 72px 1fr!important;gap:12px!important;align-items:start!important;border-bottom:1.5px solid var(--line)!important;padding:0 0 14px!important;margin-bottom:14px!important;break-inside:avoid!important}.pr-print-num{width:24px!important;height:24px!important;border-radius:50%!important;background:var(--purple)!important;color:#fff!important;display:flex!important;align-items:center!important;justify-content:center!important;font-size:10px!important;font-weight:800!important}.pr-print-img{height:58px!important;background:var(--lila)!important;border-radius:10px!important;display:flex!important;align-items:center!important;justify-content:center!important;overflow:hidden!important}.rc-print-card,.wp-print{border:0!important;padding:0!important}.rc-kicker,.wp-kicker{display:inline-flex!important;border-radius:999px!important;padding:4px 16px!important;margin:0 0 10px!important;font-family:'Fredoka','Fredoka One',Arial,sans-serif!important;font-weight:700!important;font-size:10px!important;letter-spacing:.06em!important;text-transform:uppercase!important}.rc-kicker{background:var(--orange-light)!important;color:var(--orange-dark)!important}.wp-kicker{background:var(--lila2)!important;color:var(--purple-dark)!important}.rc-title,.wp-title{font-family:'Fredoka','Fredoka One',Arial,sans-serif!important;font-size:24px!important;color:var(--purple-dark)!important;margin:0 0 5px!important}.rc-meta{font-size:13px!important;color:var(--ink-soft)!important;margin:0 0 20px!important}.rc-passage{border:1.5px solid var(--line)!important;border-radius:14px!important;background:#FAFAFC!important;padding:22px 26px!important;font-size:14px!important;line-height:1.85!important;margin:0 0 22px!important}.rc-word{color:var(--orange)!important;font-weight:800!important}.rc-num{display:inline-flex!important;align-items:center!important;justify-content:center!important;width:15px!important;height:15px!important;border-radius:50%!important;background:var(--orange)!important;color:#fff!important;font-size:9px!important;margin-left:2px!important;vertical-align:super!important}.rc-vocab-box,.rc-vocab-card{background:var(--lila)!important;border-radius:14px!important;padding:16px 18px!important;margin:0 0 18px!important}.rc-box-title,.rc-section-label{font-family:'Fredoka','Fredoka One',Arial,sans-serif!important;font-weight:600!important;font-size:14px!important;color:var(--purple-text)!important;margin:0 0 12px!important}.rc-section-label{color:var(--orange-dark)!important}.rc-meaning-grid{display:grid!important;grid-template-columns:1fr 1fr!important;gap:12px 24px!important}.rc-meaning-item{display:grid!important;grid-template-columns:auto 1fr!important;gap:16px!important;align-items:center!important}.rc-meaning-item span:first-child,.rc-card-word{color:var(--orange)!important;font-weight:800!important}.dotline{display:block!important;border-bottom:1.4px dotted var(--dot)!important;height:1px!important}.rc-options{display:grid!important;grid-template-columns:1fr 1fr!important;gap:10px 18px!important}.rc-option,.rc-vocab-option{display:flex!important;gap:10px!important;font-size:13px!important;line-height:1.35!important}.radio{width:16px!important;height:16px!important;border-radius:50%!important;border:1.5px solid var(--purple)!important;flex:0 0 auto!important}.rc-bonus{border:1.5px dashed var(--dot)!important;border-radius:14px!important;padding:14px 18px!important;color:var(--ink-soft)!important}.wp-source{background:var(--orange-light)!important;border-radius:14px!important;padding:16px 18px!important;margin-bottom:14px!important}.wp-source-label,.wp-bank-label,.wp-self-title{font-family:'Fredoka','Fredoka One',Arial,sans-serif!important;font-size:10px!important;font-weight:700!important;text-transform:uppercase!important;letter-spacing:.04em!important;margin-bottom:8px!important}.wp-source-label{color:var(--orange-dark)!important}.wp-bank{background:var(--lila)!important;border-radius:14px!important;padding:14px 18px!important;margin-bottom:26px!important;color:var(--purple-dark)!important;font-size:12px!important}.wp-lines,.rp-pdf-lines{display:grid!important;gap:28px!important;margin-bottom:36px!important}.wp-lines .writeline{border-bottom:1.5px solid var(--dot)!important}.wp-self{border:1.5px solid var(--line)!important;border-radius:12px!important;padding:14px 18px!important;font-size:12px!important}.check{display:inline-block!important;width:13px!important;height:13px!important;border-radius:3px!important;margin:0 6px 0 16px!important;vertical-align:-2px!important;border:1.5px solid var(--purple)!important}.check.green{border-color:var(--green)!important}.check.coral{border-color:var(--coral)!important}.check.orange{border-color:var(--orange)!important}@media print{.wp-lines,.rp-pdf-lines{gap:28px!important}}
CSS;
if (strpos($html,'</style>')!==false) $html=preg_replace('/<\/style>/',$worksheetCss."\n</style>",$html,1); else $html=str_replace('</head>','<style>'.$worksheetCss.'</style></head>',$html);
echo $html;
