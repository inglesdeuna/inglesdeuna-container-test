<?php
session_start();

// ── Auth ──────────────────────────────────────────────────────────────────────
$isTeacher = !empty($_SESSION['academic_logged']) || !empty($_SESSION['admin_logged']);
$isStudent = !empty($_SESSION['student_logged']);

if (!$isTeacher && !$isStudent) {
    header('Location: login.php');
    exit;
}

// ── Params ────────────────────────────────────────────────────────────────────
$unitId       = trim((string) ($_GET['unit']       ?? ''));
$assignmentId = trim((string) ($_GET['assignment'] ?? ''));

if ($unitId === '') {
    die('Unit not specified.');
}

// ── DB ────────────────────────────────────────────────────────────────────────
$dbFile = __DIR__ . '/../config/db.php';
if (!file_exists($dbFile)) {
    die('Database configuration not found.');
}
require $dbFile;
if (!isset($pdo) || !($pdo instanceof PDO)) {
    die('Database connection unavailable.');
}

// ── Helpers ───────────────────────────────────────────────────────────────────
function pdf_h(string $v): string
{
    return htmlspecialchars($v, ENT_QUOTES, 'UTF-8');
}

function pdf_col_exists(PDO $pdo, string $table, string $col): bool
{
    try {
        $pdo->query("SELECT {$col} FROM {$table} LIMIT 0");
        return true;
    } catch (Throwable $e) {
        return false;
    }
}

// ── Load unit name ────────────────────────────────────────────────────────────
$unitName = '';
try {
    $stmt = $pdo->prepare('SELECT name FROM units WHERE id = :id LIMIT 1');
    $stmt->execute(['id' => $unitId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($row) {
        $unitName = trim((string) ($row['name'] ?? ''));
    }
} catch (Throwable $e) {
    // fall through, unitName stays ''
}
if ($unitName === '') {
    $unitName = 'Unit';
}

// ── Load activities ───────────────────────────────────────────────────────────
$activities = [];
try {
    $orderBy = pdf_col_exists($pdo, 'activities', 'position')
        ? 'ORDER BY COALESCE(position, 0) ASC, id ASC'
        : 'ORDER BY id ASC';

    $stmt = $pdo->prepare(
        "SELECT id, type, data FROM activities WHERE unit_id = :uid AND type != 'flipbooks' {$orderBy}"
    );
    $stmt->execute(['uid' => $unitId]);
    $activities = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (Throwable $e) {
    // activities stays []
}

// ── Per-type render functions ─────────────────────────────────────────────────

function pdf_decode_data($raw): array
{
    if (!is_string($raw) || trim($raw) === '') return [];
    $d = json_decode($raw, true);
    return is_array($d) ? $d : [];
}

function pdf_render_section_header(int $num, string $type, string $title): string
{
    $labels = [
        'flashcards'        => 'Vocabulary',
        'quiz'              => 'Quiz',
        'multiple_choice'   => 'Multiple Choice',
        'drag_drop'         => 'Fill in the Blanks',
        'writing_practice'  => 'Writing Practice',
        'match'             => 'Match the Pairs',
        'order_sentences'   => 'Order the Sentences',
        'listen_order'      => 'Listen and Order',
        'crossword'         => 'Crossword Puzzle',
        'hangman'           => 'Word Challenge',
        'memory_cards'      => 'Memory Cards',
        'video_comprehension' => 'Video Comprehension',
        'external'          => 'External Resource',
        'powerpoint'        => 'Presentation',
    ];
    $label = $labels[$type] ?? ucwords(str_replace('_', ' ', $type));
    $h = '<div class="act-header">';
    $h .= '<span class="act-num">' . $num . '</span>';
    $h .= '<div class="act-meta"><div class="act-type">' . pdf_h($label) . '</div>';
    if ($title !== '' && $title !== $label) {
        $h .= '<div class="act-title">' . pdf_h($title) . '</div>';
    }
    $h .= '</div></div>';
    return $h;
}

function pdf_render_flashcards(array $d): string
{
    $cards = is_array($d['cards'] ?? null) ? $d['cards'] : [];
    if (empty($cards)) return '<p class="empty-note">No vocabulary items.</p>';

    $out = '<table class="vocab-table"><thead><tr><th>#</th><th>Word / Phrase</th></tr></thead><tbody>';
    foreach ($cards as $i => $card) {
        $text = trim((string) ($card['text'] ?? ''));
        if ($text === '') continue;
        $out .= '<tr><td class="num-col">' . ($i + 1) . '</td><td>' . pdf_h($text) . '</td></tr>';
    }
    $out .= '</tbody></table>';
    return $out;
}

function pdf_render_quiz(array $d, bool $showAnswers): string
{
    $desc      = trim((string) ($d['description'] ?? ''));
    $questions = is_array($d['questions'] ?? null) ? $d['questions'] : [];
    if (empty($questions)) return '<p class="empty-note">No questions.</p>';

    $letters = ['A', 'B', 'C', 'D'];
    $out = '';
    if ($desc !== '') {
        $out .= '<p class="act-description">' . pdf_h($desc) . '</p>';
    }
    foreach ($questions as $qi => $q) {
        $question = trim((string) ($q['question'] ?? ''));
        $options  = is_array($q['options'] ?? null) ? $q['options'] : [];
        $correct  = (int) ($q['correct'] ?? 0);
        $out .= '<div class="q-block">';
        $out .= '<div class="q-text"><span class="q-num">' . ($qi + 1) . '.</span> ' . pdf_h($question) . '</div>';
        $out .= '<div class="q-options">';
        foreach ($options as $oi => $opt) {
            $optText = trim((string) $opt);
            if ($optText === '') continue;
            $isCorrect = $showAnswers && $oi === $correct;
            $cls = $isCorrect ? 'opt correct-opt' : 'opt';
            $out .= '<div class="' . $cls . '"><span class="opt-letter">' . ($letters[$oi] ?? chr(65 + $oi)) . ')</span> ' . pdf_h($optText) . '</div>';
        }
        $out .= '</div>';
        if ($showAnswers) {
            $expl = trim((string) ($q['explanation'] ?? ''));
            if ($expl !== '') {
                $out .= '<div class="q-explanation"><strong>Explanation:</strong> ' . pdf_h($expl) . '</div>';
            }
        }
        $out .= '</div>';
    }
    return $out;
}

function pdf_render_multiple_choice(array $d, bool $showAnswers): string
{
    $questions = is_array($d['questions'] ?? null) ? $d['questions'] : [];
    if (empty($questions)) return '<p class="empty-note">No questions.</p>';

    $letters = ['A', 'B', 'C'];
    $out = '';
    foreach ($questions as $qi => $q) {
        $question    = trim((string) ($q['question'] ?? ''));
        $qType       = $q['question_type'] ?? 'text';
        $options     = is_array($q['options'] ?? null) ? $q['options'] : [];
        $optionType  = $q['option_type'] ?? 'text';
        $correct     = (int) ($q['correct'] ?? 0);

        if ($question === '' && $qType !== 'listen') continue;

        $out .= '<div class="q-block">';

        if ($qType === 'listen') {
            $out .= '<div class="q-text"><span class="q-num">' . ($qi + 1) . '.</span> <em class="listen-label">[🎧 Listening question]</em></div>';
        } else {
            $out .= '<div class="q-text"><span class="q-num">' . ($qi + 1) . '.</span> ' . pdf_h($question) . '</div>';
        }

        $out .= '<div class="q-options">';
        foreach ($options as $oi => $opt) {
            $optText = trim((string) $opt);
            if ($optText === '' && $optionType !== 'image') continue;
            $isCorrect = $showAnswers && $oi === $correct;
            $cls = $isCorrect ? 'opt correct-opt' : 'opt';
            if ($optionType === 'image') {
                $out .= '<div class="' . $cls . '"><span class="opt-letter">' . ($letters[$oi] ?? chr(65 + $oi)) . ')</span> [image]</div>';
            } else {
                $out .= '<div class="' . $cls . '"><span class="opt-letter">' . ($letters[$oi] ?? chr(65 + $oi)) . ')</span> ' . pdf_h($optText) . '</div>';
            }
        }
        $out .= '</div></div>';
    }
    return $out;
}

function pdf_render_drag_drop(array $d, bool $showAnswers): string
{
    $blocks = is_array($d['blocks'] ?? null) ? $d['blocks'] : [];
    if (empty($blocks)) return '<p class="empty-note">No sentences.</p>';

    $out = '';
    foreach ($blocks as $bi => $block) {
        $text         = trim((string) ($block['text'] ?? ''));
        $missingWords = is_array($block['missing_words'] ?? null) ? $block['missing_words'] : [];
        if ($text === '') continue;

        $out .= '<div class="dd-block">';
        $out .= '<span class="q-num">' . ($bi + 1) . '.</span> ';

        if ($showAnswers) {
            $out .= '<span class="dd-full-sentence">' . pdf_h($text) . '</span>';
        } else {
            // Replace each missing word with a blank line
            $blanked = $text;
            foreach ($missingWords as $word) {
                $wordStr = trim((string) $word);
                if ($wordStr === '') continue;
                $blankLen = max(8, mb_strlen($wordStr, 'UTF-8') + 4);
                $blank = str_repeat('_', $blankLen);
                $blanked = preg_replace('/' . preg_quote($wordStr, '/') . '/u', $blank, $blanked, 1);
            }
            $out .= pdf_h($blanked);
            if (!empty($missingWords)) {
                $wordList = array_map(fn ($w) => trim((string) $w), $missingWords);
                $wordList = array_filter($wordList, fn ($w) => $w !== '');
                if (!empty($wordList)) {
                    shuffle($wordList);
                    $out .= '<div class="word-bank">' . pdf_h(implode('  ·  ', $wordList)) . '</div>';
                }
            }
        }
        $out .= '</div>';
    }
    return $out;
}

function pdf_render_writing_practice(array $d): string
{
    $desc      = trim((string) ($d['description'] ?? ''));
    $questions = is_array($d['questions'] ?? null) ? $d['questions'] : [];
    if (empty($questions)) return '<p class="empty-note">No prompts.</p>';

    $out = '';
    if ($desc !== '') {
        $out .= '<p class="act-description">' . pdf_h($desc) . '</p>';
    }
    foreach ($questions as $qi => $q) {
        $question    = trim((string) ($q['question']    ?? ''));
        $instruction = trim((string) ($q['instruction'] ?? ''));
        $out .= '<div class="q-block">';
        if ($question !== '') {
            $out .= '<div class="q-text"><span class="q-num">' . ($qi + 1) . '.</span> ' . pdf_h($question) . '</div>';
        }
        if ($instruction !== '') {
            $out .= '<div class="q-instruction">' . pdf_h($instruction) . '</div>';
        }
        $out .= '<div class="writing-lines">'
            . '<div class="wl"></div><div class="wl"></div><div class="wl"></div>'
            . '</div>';
        $out .= '</div>';
    }
    return $out;
}

function pdf_render_match(array $d): string
{
    $pairs = is_array($d['pairs'] ?? null) ? $d['pairs'] : [];
    if (empty($pairs)) return '<p class="empty-note">No pairs.</p>';

    $letters = range('A', 'Z');

    // Column 1: left items (numbered), Column 2: right items (lettered, shuffled)
    $leftItems  = [];
    $rightItems = [];
    foreach ($pairs as $pair) {
        $leftItems[]  = trim((string) ($pair['left_text']  ?? ''));
        $rightItems[] = trim((string) ($pair['right_text'] ?? ''));
    }

    $shuffledRight  = $rightItems;
    $shuffledKeys   = array_keys($shuffledRight);
    shuffle($shuffledKeys);
    $reindexed = [];
    foreach ($shuffledKeys as $k) {
        $reindexed[] = $shuffledRight[$k];
    }

    $out = '<table class="match-table">';
    $out .= '<thead><tr><th style="width:45%">Column A</th><th style="width:10%"></th><th style="width:45%">Column B</th></tr></thead><tbody>';

    $count = max(count($leftItems), count($reindexed));
    for ($i = 0; $i < $count; $i++) {
        $left  = $leftItems[$i]  ?? '';
        $right = $reindexed[$i]  ?? '';
        $out .= '<tr>';
        $out .= '<td><span class="match-num">' . ($i + 1) . '.</span> ' . pdf_h($left) . '</td>';
        $out .= '<td class="match-blank">______</td>';
        $out .= '<td><span class="match-letter">' . ($letters[$i] ?? '?') . '.</span> ' . pdf_h($right) . '</td>';
        $out .= '</tr>';
    }
    $out .= '</tbody></table>';
    return $out;
}

function pdf_render_order_sentences(array $d): string
{
    $instructions = trim((string) ($d['instructions'] ?? ''));
    $sentences    = is_array($d['sentences'] ?? null) ? $d['sentences'] : [];
    if (empty($sentences)) return '<p class="empty-note">No sentences.</p>';

    $shuffled = $sentences;
    shuffle($shuffled);

    $out = '';
    if ($instructions !== '') {
        $out .= '<p class="act-description">' . pdf_h($instructions) . '</p>';
    }
    $out .= '<ol class="sentence-list">';
    foreach ($shuffled as $s) {
        $text = trim((string) ($s['text'] ?? ''));
        if ($text === '') continue;
        $out .= '<li>' . pdf_h($text) . '</li>';
    }
    $out .= '</ol>';
    return $out;
}

function pdf_render_listen_order(array $d): string
{
    $blocks = is_array($d['blocks'] ?? null) ? $d['blocks'] : [];
    if (empty($blocks)) return '<p class="empty-note">No sentences.</p>';

    $shuffled = $blocks;
    shuffle($shuffled);

    $out = '<ol class="sentence-list">';
    foreach ($shuffled as $b) {
        $sentence = trim((string) ($b['sentence'] ?? ''));
        if ($sentence === '') continue;
        $out .= '<li>' . pdf_h($sentence) . '</li>';
    }
    $out .= '</ol>';
    return $out;
}

function pdf_render_crossword(array $d): string
{
    $words = is_array($d['words'] ?? null) ? $d['words'] : [];
    if (empty($words)) return '<p class="empty-note">No clues.</p>';

    $out = '<table class="vocab-table"><thead><tr><th>#</th><th>Clue</th><th>Answer</th></tr></thead><tbody>';
    foreach ($words as $i => $w) {
        $clue = trim((string) ($w['clue'] ?? $w['raw_clue'] ?? ''));
        $word = trim((string) ($w['word'] ?? ''));
        $out .= '<tr>';
        $out .= '<td class="num-col">' . ($i + 1) . '</td>';
        $out .= '<td>' . pdf_h($clue) . '</td>';
        $out .= '<td class="blank-answer">' . str_repeat('_ ', mb_strlen($word, 'UTF-8')) . '</td>';
        $out .= '</tr>';
    }
    $out .= '</tbody></table>';
    return $out;
}

function pdf_render_memory_cards(array $d): string
{
    $cards = is_array($d['cards'] ?? null) ? $d['cards'] : (is_array($d['pairs'] ?? null) ? $d['pairs'] : []);
    if (empty($cards)) return '<p class="empty-note">No cards.</p>';

    $out = '<table class="vocab-table"><thead><tr><th>#</th><th>Card</th></tr></thead><tbody>';
    foreach ($cards as $i => $card) {
        $text = trim((string) ($card['text'] ?? $card['word'] ?? $card['front'] ?? ''));
        if ($text === '') continue;
        $out .= '<tr><td class="num-col">' . ($i + 1) . '</td><td>' . pdf_h($text) . '</td></tr>';
    }
    $out .= '</tbody></table>';
    return $out;
}

function pdf_render_placeholder(string $type): string
{
    $notices = [
        'external'          => 'This activity links to an external resource and cannot be printed.',
        'powerpoint'        => 'This activity contains a presentation and cannot be printed.',
        'video_comprehension' => 'This activity contains a video and cannot be printed.',
        'hangman'           => 'This activity is an interactive word game.',
        'pronunciation'     => 'This activity is a pronunciation/speaking exercise.',
        'tracing'           => 'This activity is a handwriting/tracing exercise.',
        'dictation'         => 'This activity is a dictation listening exercise.',
    ];
    $msg = $notices[$type] ?? 'This activity type is not available in print format.';
    return '<p class="placeholder-note">' . pdf_h($msg) . '</p>';
}

// ── Build activity sections ───────────────────────────────────────────────────

$sections = [];
$actNumber = 0;

foreach ($activities as $act) {
    $type = strtolower(trim((string) ($act['type'] ?? '')));
    $data = pdf_decode_data($act['data'] ?? null);
    $title = trim((string) ($data['title'] ?? ''));

    $actNumber++;
    $sectionHtml  = pdf_render_section_header($actNumber, $type, $title);

    switch ($type) {
        case 'flashcards':
            $sectionHtml .= pdf_render_flashcards($data);
            break;
        case 'quiz':
            $sectionHtml .= pdf_render_quiz($data, $isTeacher);
            break;
        case 'multiple_choice':
            $sectionHtml .= pdf_render_multiple_choice($data, $isTeacher);
            break;
        case 'drag_drop':
            $sectionHtml .= pdf_render_drag_drop($data, $isTeacher);
            break;
        case 'writing_practice':
            $sectionHtml .= pdf_render_writing_practice($data);
            break;
        case 'match':
            $sectionHtml .= pdf_render_match($data);
            break;
        case 'order_sentences':
            $sectionHtml .= pdf_render_order_sentences($data);
            break;
        case 'listen_order':
            $sectionHtml .= pdf_render_listen_order($data);
            break;
        case 'crossword':
            $sectionHtml .= pdf_render_crossword($data);
            break;
        case 'memory_cards':
            $sectionHtml .= pdf_render_memory_cards($data);
            break;
        default:
            $sectionHtml .= pdf_render_placeholder($type);
            break;
    }

    $sections[] = ['type' => $type, 'html' => $sectionHtml];
}

$pageTitle = $unitName;
$isAnswerKey = $isTeacher;

?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?php echo pdf_h($pageTitle); ?></title>
<style>
/* ── Screen layout ─────────────────────────────────────────────── */
*{box-sizing:border-box;margin:0;padding:0}
body{
    font-family:'Segoe UI',Arial,sans-serif;
    font-size:13px;
    color:#1a1a2e;
    background:#f4f6fb;
    padding:20px;
}
.pdf-container{
    max-width:800px;
    margin:0 auto;
    background:#fff;
    border-radius:12px;
    box-shadow:0 4px 24px #0001;
    overflow:hidden;
}
/* Cover */
.pdf-cover{
    background:linear-gradient(135deg,#1a1a2e 0%,#16213e 60%,#0f3460 100%);
    color:#fff;
    padding:40px 48px 32px;
    position:relative;
}
.cover-app{font-size:11px;letter-spacing:.12em;text-transform:uppercase;opacity:.6;margin-bottom:8px}
.cover-unit{font-size:28px;font-weight:700;letter-spacing:.01em;line-height:1.2}
.cover-meta{margin-top:14px;font-size:11px;opacity:.55;display:flex;gap:20px;flex-wrap:wrap}
.cover-badge{
    display:inline-block;
    margin-top:16px;
    padding:3px 12px;
    border-radius:20px;
    font-size:10px;
    font-weight:700;
    text-transform:uppercase;
    letter-spacing:.08em;
}
.cover-badge.worksheet{background:#fbbf24;color:#1a1a2e}
.cover-badge.answer-key{background:#34d399;color:#064e3b}
/* Print control bar */
.print-bar{
    background:#f8f9fc;
    border-bottom:1px solid #e8eaf0;
    padding:10px 20px;
    display:flex;
    align-items:center;
    gap:12px;
}
.btn-print{
    padding:6px 18px;
    background:linear-gradient(180deg,#3d73ee,#2563eb);
    color:#fff;
    border:none;
    border-radius:6px;
    cursor:pointer;
    font-size:13px;
    font-weight:600;
    text-decoration:none;
    display:inline-block;
}
.btn-print:hover{filter:brightness(1.1)}
.print-hint{font-size:12px;color:#6b7280}
/* Content body */
.pdf-body{padding:32px 48px}
.activity-section{
    margin-bottom:32px;
    padding-bottom:28px;
    border-bottom:1px dashed #d1d5db;
}
.activity-section:last-child{border-bottom:none}
/* Activity header */
.act-header{
    display:flex;
    align-items:flex-start;
    gap:12px;
    margin-bottom:14px;
}
.act-num{
    width:28px;
    height:28px;
    min-width:28px;
    background:#1a1a2e;
    color:#fff;
    border-radius:50%;
    display:flex;
    align-items:center;
    justify-content:center;
    font-size:12px;
    font-weight:700;
    margin-top:2px;
}
.act-meta{display:flex;flex-direction:column;gap:2px}
.act-type{font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.1em;color:#6b7280}
.act-title{font-size:15px;font-weight:600;color:#1a1a2e}
/* Description / instructions */
.act-description{font-size:12px;color:#4b5563;margin-bottom:10px;font-style:italic}
/* Vocabulary table */
.vocab-table{width:100%;border-collapse:collapse;font-size:13px}
.vocab-table th{background:#f3f4f6;text-align:left;padding:6px 10px;font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:#6b7280;border:1px solid #e5e7eb}
.vocab-table td{padding:6px 10px;border:1px solid #e5e7eb;vertical-align:top}
.num-col{width:36px;text-align:center;color:#9ca3af;font-size:12px}
.blank-answer{font-family:monospace;color:#9ca3af;letter-spacing:.1em}
/* Quiz / MC questions */
.q-block{margin-bottom:14px}
.q-text{font-weight:600;margin-bottom:6px;line-height:1.4}
.q-num{color:#6b7280;font-weight:400;margin-right:4px}
.q-instruction{font-size:12px;color:#4b5563;font-style:italic;margin-bottom:6px}
.q-options{display:flex;flex-direction:column;gap:4px;padding-left:20px}
.opt{font-size:13px;line-height:1.4}
.opt-letter{color:#6b7280;font-weight:600;margin-right:4px}
.correct-opt{background:#d1fae5;padding:2px 6px;border-radius:4px;color:#065f46}
.q-explanation{font-size:11px;color:#059669;background:#ecfdf5;padding:6px 10px;border-radius:4px;margin-top:4px;border-left:3px solid #10b981}
.listen-label{color:#6366f1;font-size:12px}
/* Drag drop */
.dd-block{margin-bottom:10px;line-height:1.7;font-size:13px}
.dd-full-sentence{color:#1e40af}
.word-bank{margin-top:4px;font-size:11px;color:#6b7280;font-style:italic}
/* Writing practice */
.writing-lines{margin-top:8px;margin-bottom:4px}
.wl{border-bottom:1px solid #d1d5db;height:22px;margin-bottom:4px}
/* Match table */
.match-table{width:100%;border-collapse:collapse;font-size:13px}
.match-table th{background:#f3f4f6;padding:6px 10px;font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:#6b7280;border:1px solid #e5e7eb;text-align:left}
.match-table td{padding:7px 10px;border:1px solid #e5e7eb;vertical-align:middle}
.match-num{color:#6b7280;font-weight:600;margin-right:4px}
.match-letter{color:#6b7280;font-weight:600;margin-right:4px}
.match-blank{text-align:center;font-family:monospace;color:#9ca3af;width:80px}
/* Sentence lists */
.sentence-list{padding-left:20px;font-size:13px}
.sentence-list li{margin-bottom:8px;line-height:1.5}
/* Misc */
.placeholder-note{font-size:12px;color:#9ca3af;font-style:italic;padding:10px;background:#f9fafb;border-radius:6px;border:1px dashed #d1d5db}
.empty-note{font-size:12px;color:#9ca3af;font-style:italic}

/* ── Print styles ─────────────────────────────────────────────── */
@media print {
    @page{
        size:letter;
        margin:18mm 20mm;
    }
    body{background:#fff;padding:0;font-size:11px}
    .pdf-container{box-shadow:none;border-radius:0;max-width:100%}
    .print-bar{display:none !important}
    .pdf-cover{
        padding:28px 32px 22px;
        -webkit-print-color-adjust:exact;
        print-color-adjust:exact;
    }
    .pdf-body{padding:20px 32px}
    .activity-section{
        break-inside:avoid;
        margin-bottom:22px;
        padding-bottom:18px;
    }
    /* Force page break before "heavy" activity types */
    .activity-section.type-quiz,
    .activity-section.type-multiple_choice,
    .activity-section.type-writing_practice{
        break-before:auto;
    }
    .vocab-table th,.vocab-table td,
    .match-table th,.match-table td{
        -webkit-print-color-adjust:exact;
        print-color-adjust:exact;
    }
    .correct-opt{
        -webkit-print-color-adjust:exact;
        print-color-adjust:exact;
        background:#d1fae5 !important;
        color:#065f46 !important;
    }
    .q-explanation{
        -webkit-print-color-adjust:exact;
        print-color-adjust:exact;
    }
    a{color:inherit;text-decoration:none}
    .btn-print,.print-bar{display:none}
}
</style>
</head>
<body>

<div class="pdf-container">

  <!-- Print control bar (hidden when printing) -->
  <div class="print-bar">
    <button class="btn-print" onclick="window.print()">🖨️ Print / Save as PDF</button>
    <span class="print-hint">Use your browser's Print → Save as PDF for best results.</span>
  </div>

  <!-- Cover -->
  <div class="pdf-cover">
    <div class="cover-app">InglésDeUna · Activity Sheet</div>
    <div class="cover-unit"><?php echo pdf_h($unitName); ?></div>
    <div class="cover-meta">
      <span><?php echo count($sections); ?> activit<?php echo count($sections) === 1 ? 'y' : 'ies'; ?></span>
      <span><?php echo date('F j, Y'); ?></span>
    </div>
    <?php if ($isAnswerKey): ?>
      <span class="cover-badge answer-key">Answer Key</span>
    <?php else: ?>
      <span class="cover-badge worksheet">Worksheet</span>
    <?php endif; ?>
  </div>

  <!-- Activity sections -->
  <div class="pdf-body">
    <?php if (empty($sections)): ?>
      <p style="color:#9ca3af;font-style:italic;text-align:center;padding:40px 0">
        No printable activities found for this unit.
      </p>
    <?php else: ?>
      <?php foreach ($sections as $sec): ?>
        <div class="activity-section type-<?php echo pdf_h($sec['type']); ?>">
          <?php echo $sec['html']; ?>
        </div>
      <?php endforeach; ?>
    <?php endif; ?>
  </div>

</div>

<script>
// Auto-trigger print dialog if ?autoprint=1
(function () {
    var params = new URLSearchParams(window.location.search);
    if (params.get('autoprint') === '1') {
        setTimeout(function () { window.print(); }, 600);
    }
})();
</script>
</body>
</html>
