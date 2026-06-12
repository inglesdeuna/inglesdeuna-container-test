<?php
/**
 * quiz_print.php
 * Generates a printable PDF-ready quiz from an eval_exam.
 * URL: quiz_print.php?exam_id=N[&mode=student|key]
 * Access: admin_logged only
 */
session_start();

if (empty($_SESSION['admin_logged'])) {
    header('Location: /lessons/lessons/admin/login.php');
    exit;
}

$examId  = (int) ($_GET['exam_id'] ?? 0);
$mode    = trim($_GET['mode'] ?? 'student'); // 'student' | 'key'
$isKey   = ($mode === 'key');

if ($examId <= 0) die('Exam ID required.');

require_once __DIR__ . '/../../config/db.php';
if (!isset($pdo) || !($pdo instanceof PDO)) die('DB unavailable.');

function h(string $v): string { return htmlspecialchars($v, ENT_QUOTES, 'UTF-8'); }

/* ── Load exam ─────────────────────────────────────────────── */
$stmt = $pdo->prepare(
    "SELECT e.*, u.name AS unit_name, u.id AS unit_id_val,
            c.name AS course_name
     FROM eval_exams e
     LEFT JOIN units u ON u.id = e.unit_id
     LEFT JOIN courses c ON c.id = u.course_id
     WHERE e.id = ? LIMIT 1"
);
$stmt->execute([$examId]);
$exam = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$exam) die('Exam not found.');

/* ── Load questions + answers ──────────────────────────────── */
$stmt = $pdo->prepare(
    "SELECT eq.*,
            array_agg(ea.answer_text  ORDER BY ea.order_index)
              FILTER (WHERE ea.answer_text IS NOT NULL) AS answer_texts,
            array_agg(ea.is_correct   ORDER BY ea.order_index)
              FILTER (WHERE ea.answer_text IS NOT NULL) AS answer_corrects
     FROM eval_questions eq
     LEFT JOIN eval_answers ea ON ea.question_id = eq.id
     WHERE eq.exam_id = ?
     GROUP BY eq.id
     ORDER BY eq.position, eq.id"
);
$stmt->execute([$examId]);
$questions = $stmt->fetchAll(PDO::FETCH_ASSOC);

/* ── Parse pg arrays ───────────────────────────────────────── */
function pg_array(string $raw): array {
    $raw = trim($raw, '{}');
    if ($raw === '') return [];
    // Handle quoted strings with commas inside
    $items = [];
    $current = '';
    $inQuote = false;
    for ($i = 0; $i < strlen($raw); $i++) {
        $c = $raw[$i];
        if ($c === '"') { $inQuote = !$inQuote; continue; }
        if ($c === ',' && !$inQuote) { $items[] = $current; $current = ''; continue; }
        $current .= $c;
    }
    $items[] = $current;
    return array_map('trim', $items);
}

foreach ($questions as &$q) {
    $q['answers'] = is_string($q['answer_texts'])
        ? pg_array($q['answer_texts']) : ($q['answer_texts'] ?? []);
    $raw_c = $q['answer_corrects'] ?? '';
    $corr  = is_string($raw_c) ? pg_array($raw_c) : ($raw_c ?? []);
    $q['correct_flags'] = array_map(fn($v) => in_array(strtolower(trim($v)), ['t','true','1'], true), $corr);
}
unset($q);

/* ── Helpers ───────────────────────────────────────────────── */
$LTRS = ['A','B','C','D','E'];

function sec_color(int $n): string {
    return ($n % 2 === 1) ? 'ora' : 'lila';
}

function kicker_for_type(string $type): string {
    return match($type) {
        'multiple_choice' => 'Multiple Choice',
        'true_false'      => 'True / False',
        'fill_blank'      => 'Fill in the Blank',
        'short_answer'    => 'Short Answer',
        'essay'           => 'Essay',
        'matching'        => 'Matching',
        'ordering'        => 'Ordering',
        default           => ucwords(str_replace('_', ' ', $type)),
    };
}

/* Group questions by type for section headers */
$sections = [];
foreach ($questions as $q) {
    $sections[$q['type']][] = $q;
}

$totalPoints = array_sum(array_column($questions, 'points'));
$today = date('F j, Y');
$examTitle  = trim($exam['title'] ?? 'Quiz');
$unitName   = trim($exam['unit_name'] ?? '');
$courseName = trim($exam['course_name'] ?? '');
$cefrLevel  = trim($exam['cefr_level'] ?? '');
$timeLimit  = (int)($exam['time_limit_min'] ?? 0);

$printTitle = $examTitle . ($isKey ? ' — Answer Key' : '');
?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1.0">
<title><?= h($printTitle) ?></title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Fredoka+One&family=Nunito:wght@400;600;700;800&display=swap" rel="stylesheet">
<style>
/* ═══════════════════════════════════════════════════════════
   ONES Quiz Print — palette: white · #F97316 · #7F77DD · #1a1a2e
   ═══════════════════════════════════════════════════════════ */
@page { size: letter; margin: 12mm 11mm; }

:root {
  --ora:   #F97316;
  --lila:  #7F77DD;
  --lila2: #EDE9FA;
  --lila3: #9B8FCC;
  --ink:   #1a1a2e;
  --muted: #5a5a7a;
  --white: #ffffff;
}

*, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
html { -webkit-print-color-adjust: exact; print-color-adjust: exact; }
body { font-family: 'Nunito', 'Segoe UI', Arial, sans-serif;
       font-size: 13px; line-height: 1.6; color: var(--ink);
       background: #e0e0e0; }

/* ── Toolbar ── */
.toolbar { position: sticky; top: 0; z-index: 100; background: var(--ink);
           display: flex; align-items: center; gap: 12px; padding: 9px 22px;
           box-shadow: 0 2px 12px rgba(0,0,0,.25); }
.tb-brand { font-family: 'Fredoka One', sans-serif; font-size: 15px;
            color: var(--ora); margin-right: auto; }
.tb-title { font-size: 12px; color: rgba(255,255,255,.55);
            max-width: 320px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.tb-badge { font-size: 10px; font-weight: 800; padding: 3px 11px;
            border-radius: 20px; text-transform: uppercase; letter-spacing: .06em; }
.b-ws  { background: var(--ora);  color: #fff; }
.b-key { background: var(--lila); color: #fff; }
.tb-btns { display: flex; gap: 8px; }
.btn-print { background: var(--lila); color: #fff; border: none; border-radius: 8px;
             padding: 7px 14px; font-size: 12px; font-weight: 700; font-family: inherit;
             cursor: pointer; display: inline-flex; align-items: center; gap: 5px; }
.btn-print:hover { filter: brightness(1.1); }
.btn-switch { background: transparent; color: rgba(255,255,255,.7); border: 1px solid rgba(255,255,255,.3);
              border-radius: 8px; padding: 7px 14px; font-size: 12px; font-weight: 700;
              font-family: inherit; cursor: pointer; text-decoration: none;
              display: inline-flex; align-items: center; gap: 5px; }
.btn-switch:hover { background: rgba(255,255,255,.08); }

/* ── Document shell ── */
.ws-doc { max-width: 880px; margin: 22px auto 52px; background: var(--white);
          border-radius: 4px; box-shadow: 0 12px 40px rgba(0,0,0,.10); overflow: hidden; }

/* ── Header ── */
.doc-header { padding: 16px 30px 13px; border-bottom: 2px solid var(--ora);
              display: flex; align-items: center; justify-content: space-between; }
.lockup { display: flex; align-items: center; gap: 14px; }
.ones-text { font-family: 'Fredoka One', sans-serif; font-size: 28px;
             color: var(--ora); line-height: 1; letter-spacing: -.5px; }
.tagline { font-size: 8.5px; font-weight: 800; color: var(--lila); letter-spacing: 2.5px; }
.byline-row { display: flex; align-items: center; gap: 5px; margin-top: 3px; }
.byline-line { width: 16px; height: 1.5px; background: var(--lila2); border-radius: 2px; }
.byline { font-size: 9px; font-weight: 600; color: var(--lila3); }
.hdr-right { display: flex; flex-direction: column; align-items: flex-end; gap: 5px; }
.quiz-badge { border-radius: 20px; padding: 3px 13px; font-size: 9.5px; font-weight: 800;
              letter-spacing: .07em; text-transform: uppercase; }
.quiz-badge.student { background: var(--white); border: 2px solid var(--ora); color: var(--ora); }
.quiz-badge.key     { background: var(--lila); color: #fff; }
.hdr-date { font-size: 9.5px; color: var(--lila3); font-weight: 700; }

/* ── Course/unit bar ── */
.course-bar { background: var(--lila); padding: 6px 30px;
              display: flex; align-items: center; justify-content: space-between; }
.cb-left { display: flex; align-items: center; }
.cb-pill { background: rgba(255,255,255,.2); border: 1px solid rgba(255,255,255,.38);
           border-radius: 20px; padding: 2px 11px; font-size: 9.5px; font-weight: 800; color: #fff; }
.cb-sep  { color: rgba(255,255,255,.38); margin: 0 6px; }
.cb-name { font-size: 10.5px; font-weight: 800; color: #fff; }
.cb-sub  { font-size: 9.5px; font-weight: 700; color: rgba(255,255,255,.72); }
.cb-right { font-size: 9px; font-weight: 700; color: rgba(255,255,255,.65); }

/* ── Body ── */
.ws-body { padding: 20px 30px 30px; }

/* ── Score bar ── */
.score-bar { display: flex; align-items: center; justify-content: space-between;
             border: 1.5px solid var(--lila2); border-radius: 12px;
             padding: 11px 16px; margin-bottom: 16px; }
.score-boxes { display: flex; gap: 10px; margin-top: 4px; }
.score-box   { border: 1.5px solid var(--lila2); border-radius: 8px; padding: 5px 16px; text-align: center; }
.score-lbl   { font-size: 7.5px; font-weight: 800; text-transform: uppercase;
               letter-spacing: .1em; color: var(--lila3); display: block; margin-bottom: 2px; }
.score-val   { font-family: 'Fredoka One', sans-serif; font-size: 20px; color: var(--ora); }
.score-tot   { font-size: 10.5px; color: var(--lila3); font-weight: 600; }
.grade-label { font-size: 7.5px; font-weight: 800; text-transform: uppercase;
               letter-spacing: .1em; color: var(--lila3); display: block; margin-bottom: 5px; text-align: right; }
.grade-circle { width: 52px; height: 52px; border-radius: 50%; border: 2.5px solid var(--lila2);
                display: flex; align-items: center; justify-content: center;
                font-family: 'Fredoka One', sans-serif; font-size: 22px; color: var(--lila3);
                margin-left: auto; }

/* Answer key total box */
.key-total { background: var(--lila2); border-radius: 12px; padding: 10px 18px;
             display: flex; align-items: center; gap: 14px; margin-bottom: 16px; }
.kt-lbl  { font-size: 9px; font-weight: 800; text-transform: uppercase; letter-spacing: .12em; color: var(--lila3); }
.kt-val  { font-family: 'Fredoka One', sans-serif; font-size: 22px; color: var(--lila); }
.kt-sub  { font-size: 10px; color: var(--lila3); font-weight: 600; }

/* ── Student fields ── */
.sf-grid { display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 10px; margin-bottom: 15px; }
.sf { border: 1.5px solid var(--lila2); border-radius: 10px; padding: 8px 12px; }
.sf-lbl { font-size: 7.5px; font-weight: 800; text-transform: uppercase;
          letter-spacing: .13em; color: var(--lila3); display: block; margin-bottom: 11px; }
.sf-line { border-bottom: 2px solid var(--lila2); }

/* ── Instruction row ── */
.instr-row { border-bottom: 1.5px solid var(--lila2); padding-bottom: 9px; margin-bottom: 20px;
             font-size: 10.5px; color: var(--lila); font-weight: 600;
             display: flex; align-items: flex-start; gap: 9px; line-height: 1.5; }
.ins-dot { flex-shrink: 0; width: 19px; height: 19px; background: var(--lila);
           border-radius: 50%; display: flex; align-items: center; justify-content: center;
           margin-top: 1px; }

/* ── Exam meta pills ── */
.exam-meta { display: flex; flex-wrap: wrap; gap: 7px; margin-bottom: 18px; }
.meta-pill { border: 1.5px solid var(--lila2); border-radius: 20px; padding: 3px 12px;
             font-size: 9.5px; font-weight: 700; color: var(--lila3); }
.meta-pill.ora { border-color: var(--ora); color: var(--ora); }

/* ── Section ── */
.ws-sec { margin-bottom: 22px; break-inside: avoid; page-break-inside: avoid; }
.ws-sec:last-child { margin-bottom: 0; }
.sec-head { display: flex; align-items: center; gap: 10px; margin-bottom: 10px; }
.snum { width: 30px; height: 30px; border-radius: 50%;
        display: flex; align-items: center; justify-content: center;
        font-family: 'Fredoka One', sans-serif; font-size: 14px; color: #fff; flex-shrink: 0; }
.snum.ora  { background: var(--ora); }
.snum.lila { background: var(--lila); }
.sec-kicker { font-size: 8px; font-weight: 800; text-transform: uppercase;
              letter-spacing: .14em; color: var(--lila3); }
.sec-title  { font-size: 12.5px; font-weight: 700; color: var(--ink); margin-top: 1px; }
.key-tag { background: var(--lila); color: #fff; font-size: 8.5px; padding: 2px 7px;
           border-radius: 10px; margin-left: 6px; vertical-align: middle; font-weight: 700; }

/* ── Points badge ── */
.pts { font-size: 9px; font-weight: 800; color: var(--ora); margin-left: 6px;
       background: #FFF0E6; border: 1px solid #F97316; border-radius: 10px;
       padding: 1px 6px; vertical-align: middle; }

/* ── Question block ── */
.qb { margin-bottom: 16px; break-inside: avoid; }
.qb:last-child { margin-bottom: 0; }
.qt { font-weight: 700; font-size: 11.5px; line-height: 1.5; margin-bottom: 8px;
      display: flex; align-items: flex-start; gap: 8px; }
.qnum { width: 22px; height: 22px; border-radius: 50%; background: var(--ink); color: #fff;
        display: flex; align-items: center; justify-content: center; font-size: 9.5px;
        font-weight: 800; flex-shrink: 0; margin-top: 1px; }

/* ── MCQ options ── */
.mc-opts { display: grid; grid-template-columns: 1fr 1fr; gap: 5px 12px;
           margin-top: 6px; padding-left: 30px; }
.mc-opt  { display: flex; align-items: center; gap: 7px; border: 1.5px solid var(--lila2);
           border-radius: 9px; padding: 7px 10px; font-size: 11px; min-height: 36px; }
.opt-l   { width: 22px; height: 22px; border-radius: 50%; border: 1.5px solid var(--lila2);
           color: var(--lila3); display: flex; align-items: center; justify-content: center;
           font-weight: 800; flex-shrink: 0; font-size: 10px; }
/* answer key: correct option */
.mc-opt.correct   { background: #EEEDFE; border-color: var(--lila); }
.mc-opt.correct .opt-l { background: var(--lila); color: #fff; border-color: var(--lila); }

/* ── True/False ── */
.tf-opts { display: flex; gap: 10px; padding-left: 30px; margin-top: 6px; }
.tf-opt  { display: flex; align-items: center; gap: 7px; border: 1.5px solid var(--lila2);
           border-radius: 9px; padding: 8px 18px; font-size: 11px; font-weight: 700; }
.tf-opt .opt-l { width: 22px; height: 22px; border-radius: 50%; border: 1.5px solid var(--lila2);
                 display: flex; align-items: center; justify-content: center; flex-shrink: 0;
                 font-size: 10px; font-weight: 800; color: var(--lila3); }
.tf-opt.correct   { background: #EEEDFE; border-color: var(--lila); }
.tf-opt.correct .opt-l { background: var(--lila); color: #fff; border-color: var(--lila); }

/* ── Open lines (fill / short / essay) ── */
.open-lines { display: flex; flex-direction: column; gap: 22px;
              margin-top: 10px; padding-left: 30px; }
.open-line  { border-bottom: 1.5px solid var(--lila2); height: 28px; }
.ans-shown  { padding: 7px 12px; background: #EEEDFE; border-left: 3px solid var(--lila);
              font-size: 11.5px; font-weight: 700; color: var(--lila); margin-left: 30px;
              margin-top: 6px; border-radius: 0 8px 8px 0; }

/* ── Fill-in blank inline ── */
.fi-blank { display: inline-block; min-width: 80px; border-bottom: 2px solid var(--ora);
            vertical-align: baseline; margin: 0 3px; height: 1.1em; }
.fi-ans   { display: inline; font-weight: 800; color: var(--lila); }

/* ── Matching ── */
.match-cols { display: grid; grid-template-columns: 1fr 1fr; gap: 18px; }
.match-hd   { font-size: 8px; font-weight: 800; text-transform: uppercase;
              letter-spacing: .13em; color: var(--lila); border-bottom: 1.5px solid var(--lila2);
              padding-bottom: 5px; margin-bottom: 6px; }
.match-row  { display: flex; align-items: center; gap: 8px; padding: 7px 0;
              border-bottom: 1px solid var(--lila2); font-size: 11px; }
.match-row:last-child { border-bottom: none; }
.match-n    { font-size: 10.5px; font-weight: 700; color: var(--lila3); min-width: 18px; }
.match-bl   { flex: 0 0 28px; height: 1.5px; background: var(--lila3); border-radius: 2px; }
.match-lt   { font-size: 10.5px; font-weight: 800; color: var(--ora); min-width: 18px; }
.match-ans  { font-size: 10.5px; font-weight: 800; color: var(--lila); }

/* ── Word bank ── */
.word-bank { display: flex; flex-wrap: wrap; align-items: center; gap: 6px;
             padding-bottom: 12px; border-bottom: 1.5px solid var(--lila2); margin-bottom: 13px; }
.wb-lbl  { font-size: 9px; font-weight: 800; text-transform: uppercase;
           letter-spacing: .1em; color: var(--ora); margin-right: 4px; }
.wb-chip { padding: 4px 12px; border-radius: 999px; border: 1.5px solid var(--lila2);
           color: var(--ink); font-weight: 600; font-size: 11.5px; }

/* ── Divider ── */
.pdiv { border: none; border-top: 1.5px dashed var(--lila2); margin: 20px 0; }
.plbl { text-align: center; margin: -10px 0 20px; font-size: 9px; font-weight: 800;
        text-transform: uppercase; letter-spacing: .14em; color: var(--lila2); }
.plbl span { background: #fff; padding: 0 14px; }

/* ── Footer ── */
.pg-footer { border-top: 1.5px solid var(--lila2); padding: 7px 30px;
             display: flex; align-items: center; justify-content: space-between; }
.ft-brand { font-size: 8.5px; font-weight: 700; color: var(--lila3); }
.ft-info  { font-size: 8.5px; color: var(--lila3); font-weight: 600; }
.ft-pg    { font-size: 8.5px; font-weight: 800; color: var(--ora); }

/* ═══════════════════════════════════════════════════════════
   PRINT
   ═══════════════════════════════════════════════════════════ */
@media print {
  html, body { width: 100%; background: #fff !important; }
  body { font-size: 10px; line-height: 1.4; }
  .toolbar, .pdiv, .plbl { display: none !important; }
  .ws-doc { box-shadow: none; border-radius: 0; max-width: 100%; margin: 0; }

  .doc-header { padding: 0 0 8px; }
  .ones-text  { font-size: 20px; }
  .tagline    { font-size: 7px; letter-spacing: 2px; }
  .byline     { font-size: 7.5px; }
  .quiz-badge { font-size: 8px; padding: 2px 10px; }
  .hdr-date   { font-size: 8px; }
  .course-bar { padding: 4px 30px; }
  .cb-pill    { font-size: 8px; padding: 2px 9px; }
  .cb-name,.cb-sub { font-size: 9px; }

  .ws-body { padding: 7px 0 0; }

  .score-bar  { padding: 7px 11px; margin-bottom: 9px; border-radius: 7px; }
  .score-val  { font-size: 16px; }
  .grade-circle { width: 42px; height: 42px; font-size: 17px; }
  .key-total  { padding: 6px 12px; margin-bottom: 9px; border-radius: 7px; }
  .kt-val     { font-size: 17px; }

  .sf-grid { gap: 6px; margin-bottom: 8px; }
  .sf { padding: 5px 9px; border-radius: 7px; }
  .sf-lbl { font-size: 6.5px; margin-bottom: 7px; }

  .instr-row { padding-bottom: 6px; font-size: 8.5px; margin-bottom: 11px; }
  .ins-dot   { width: 15px; height: 15px; }

  .exam-meta  { gap: 4px; margin-bottom: 10px; }
  .meta-pill  { font-size: 8px; padding: 2px 9px; }

  .ws-sec  { margin-bottom: 9px; break-inside: auto; page-break-inside: auto; }
  .sec-head { margin-bottom: 5px; }
  .snum    { width: 24px; height: 24px; font-size: 11px; }
  .sec-kicker { font-size: 7px; }
  .sec-title  { font-size: 10px; }

  .qb { margin-bottom: 7px; }
  .qt { font-size: 9.5px; gap: 6px; }
  .qnum { width: 17px; height: 17px; font-size: 8px; }

  .mc-opts { gap: 4px 8px; padding-left: 23px; margin-top: 4px; }
  .mc-opt  { padding: 5px 8px; font-size: 8.5px; min-height: 26px; border-radius: 6px; }
  .opt-l   { width: 17px; height: 17px; font-size: 8px; }

  .tf-opts { gap: 8px; padding-left: 23px; margin-top: 4px; }
  .tf-opt  { padding: 5px 14px; font-size: 8.5px; border-radius: 6px; }
  .tf-opt .opt-l { width: 17px; height: 17px; font-size: 8px; }

  .open-lines { gap: 18px; margin-top: 7px; padding-left: 23px; }
  .open-line  { height: 22px; }
  .ans-shown  { font-size: 9px; padding: 4px 8px; margin-left: 23px; }

  .match-cols { gap: 12px; }
  .match-hd   { font-size: 7px; }
  .match-row  { padding: 5px 0; font-size: 9px; }
  .match-n,.match-lt { font-size: 9px; }

  .word-bank  { gap: 3px 5px; padding-bottom: 8px; margin-bottom: 9px; }
  .wb-lbl     { font-size: 7.5px; }
  .wb-chip    { padding: 2px 9px; font-size: 8.5px; }

  .pg-footer  { padding: 5px 0; }
  .ft-brand,.ft-info,.ft-pg { font-size: 7.5px; }

  .qb,.tf-opts,.mc-opts { break-inside: avoid; page-break-inside: avoid; }
  .sec-head { break-after: avoid; page-break-after: avoid; }

  .doc-header, .course-bar, .score-bar, .key-total, .sf, .snum, .qnum, .opt-l,
  .mc-opt.correct, .tf-opt.correct, .ans-shown, .meta-pill.ora, .wb-chip, .key-tag
  { -webkit-print-color-adjust: exact; print-color-adjust: exact; }
}
</style>
</head>
<body>

<!-- ── Toolbar ── -->
<div class="toolbar">
  <span class="tb-brand">ONES</span>
  <span class="tb-title"><?= h($examTitle) ?><?= $unitName ? ' — ' . h($unitName) : '' ?></span>
  <span class="tb-badge <?= $isKey ? 'b-key' : 'b-ws' ?>"><?= $isKey ? 'Answer Key' : 'Quiz' ?></span>
  <div class="tb-btns">
    <?php if ($isKey): ?>
      <a class="btn-switch" href="quiz_print.php?exam_id=<?= $examId ?>&mode=student">📄 Student version</a>
    <?php else: ?>
      <a class="btn-switch" href="quiz_print.php?exam_id=<?= $examId ?>&mode=key">🔑 Answer Key</a>
    <?php endif; ?>
    <button class="btn-print" onclick="window.print()">&#128424; Print / Save PDF</button>
  </div>
</div>

<div class="ws-doc">

  <!-- ── Header ── -->
  <div class="doc-header">
    <div class="lockup">
      <svg width="52" height="52" viewBox="0 0 64 64" fill="none" xmlns="http://www.w3.org/2000/svg">
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
    <div class="hdr-right">
      <div class="quiz-badge <?= $isKey ? 'key' : 'student' ?>">
        Quiz<?php if ($isKey): ?>&nbsp;<span class="key-tag" style="font-size:9px;padding:2px 8px;">Answer Key</span><?php endif; ?>
      </div>
      <div class="hdr-date"><?= h($today) ?></div>
    </div>
  </div>

  <!-- ── Course bar ── -->
  <div class="course-bar">
    <div class="cb-left">
      <?php if ($courseName !== ''): ?>
        <div class="cb-pill"><?= h($courseName) ?></div>
        <span class="cb-sep">·</span>
      <?php endif; ?>
      <?php if ($unitName !== ''): ?>
        <span class="cb-sub"><?= h($unitName) ?></span>
        <span class="cb-sep">·</span>
      <?php endif; ?>
      <span class="cb-name"><?= h($examTitle) ?></span>
    </div>
    <div class="cb-right"><?= count($questions) ?> question<?= count($questions) !== 1 ? 's' : '' ?><?= $totalPoints > 0 ? ' · ' . number_format($totalPoints, 0) . ' pts' : '' ?></div>
  </div>

  <!-- ── Body ── -->
  <div class="ws-body">

    <?php if ($isKey): ?>
    <!-- Answer key header -->
    <div class="key-total">
      <div>
        <div class="kt-lbl">Total points</div>
        <div><span class="kt-val"><?= number_format($totalPoints, 0) ?></span>
             <span class="kt-sub"> pts</span></div>
      </div>
      <?php if ($cefrLevel): ?>
      <div>
        <div class="kt-lbl">CEFR level</div>
        <div><span class="kt-val" style="font-size:16px;"><?= h($cefrLevel) ?></span></div>
      </div>
      <?php endif; ?>
      <?php if ($timeLimit > 0): ?>
      <div>
        <div class="kt-lbl">Time limit</div>
        <div><span class="kt-val" style="font-size:16px;"><?= $timeLimit ?></span>
             <span class="kt-sub"> min</span></div>
      </div>
      <?php endif; ?>
    </div>

    <?php else: ?>
    <!-- Student score bar -->
    <div class="score-bar">
      <div>
        <div class="score-lbl">Score</div>
        <div class="score-boxes">
          <div class="score-box">
            <span class="score-lbl">Correct</span>
            <div><span class="score-val">__</span><span class="score-tot"> / <?= count($questions) ?></span></div>
          </div>
          <div class="score-box">
            <span class="score-lbl">Score</span>
            <div><span class="score-val">__</span><span class="score-tot"> %</span></div>
          </div>
          <?php if ($totalPoints > 0): ?>
          <div class="score-box">
            <span class="score-lbl">Points</span>
            <div><span class="score-val">__</span><span class="score-tot"> / <?= number_format($totalPoints, 0) ?></span></div>
          </div>
          <?php endif; ?>
        </div>
      </div>
      <div>
        <span class="grade-label">Grade</span>
        <div class="grade-circle">__</div>
      </div>
    </div>
    <?php endif; ?>

    <!-- Student fields -->
    <?php if (!$isKey): ?>
    <div class="sf-grid">
      <div class="sf"><span class="sf-lbl">Student name</span><div class="sf-line"></div></div>
      <div class="sf"><span class="sf-lbl">Date</span><div class="sf-line"></div></div>
      <div class="sf"><span class="sf-lbl">Group / Level</span><div class="sf-line"></div></div>
    </div>
    <?php endif; ?>

    <!-- Exam meta pills -->
    <div class="exam-meta">
      <?php if ($cefrLevel && !$isKey): ?>
        <span class="meta-pill"><?= h($cefrLevel) ?></span>
      <?php endif; ?>
      <?php if ($timeLimit > 0 && !$isKey): ?>
        <span class="meta-pill ora">&#9201; <?= $timeLimit ?> minutes</span>
      <?php endif; ?>
      <?php if ($exam['instructions'] ?? ''): ?>
        <span class="meta-pill" style="max-width:100%;font-size:10px;">
          <?= h(mb_substr($exam['instructions'], 0, 120, 'UTF-8')) ?><?= mb_strlen($exam['instructions'], 'UTF-8') > 120 ? '…' : '' ?>
        </span>
      <?php endif; ?>
    </div>

    <!-- Instruction row (student only) -->
    <?php if (!$isKey): ?>
    <div class="instr-row">
      <div class="ins-dot">
        <svg width="10" height="10" viewBox="0 0 12 12" fill="none">
          <circle cx="6" cy="6" r="5" stroke="#fff" stroke-width="1.2"/>
          <path d="M6 5v4M6 3v1" stroke="#fff" stroke-width="1.3" stroke-linecap="round"/>
        </svg>
      </div>
      Read each question carefully and answer in the space provided. Write clearly.
    </div>
    <?php endif; ?>

    <!-- ── Questions ── -->
    <?php
    $qi = 0;
    foreach ($questions as $q):
      $qi++;
      $type    = $q['type'] ?? 'multiple_choice';
      $text    = trim($q['question_text'] ?? '');
      $answers = $q['answers'] ?? [];
      $corrFlags = $q['correct_flags'] ?? [];
      $pts     = (float)($q['points'] ?? 1);
      $img     = trim($q['image_url'] ?? '');
      $kicker  = kicker_for_type($type);
      $sc      = sec_color($qi);
      $numLines = in_array($type, ['essay']) ? 6 : (in_array($type, ['short_answer','fill_blank']) ? 3 : 2);

      /* Page break every 5 questions on screen */
      if ($qi > 1 && ($qi - 1) % 5 === 0):
    ?>
      <hr class="pdiv">
      <div class="plbl"><span>— continued —</span></div>
    <?php endif; ?>

    <div class="ws-sec">
      <div class="sec-head">
        <div class="snum <?= $sc ?>"><?= $qi ?></div>
        <div>
          <div class="sec-kicker"><?= h($kicker) ?><span class="pts"><?= number_format($pts, $pts == floor($pts) ? 0 : 1) ?> pt<?= $pts != 1 ? 's' : '' ?></span><?php if ($isKey): ?><span class="key-tag">Answer</span><?php endif; ?></div>
          <div class="sec-title"><?= h($text) ?></div>
        </div>
      </div>

      <?php if ($img): ?>
        <div style="padding-left:30px;margin-bottom:8px;">
          <img src="<?= h($img) ?>" alt="" style="max-width:220px;max-height:160px;object-fit:contain;border-radius:8px;border:1px solid var(--lila2);" loading="eager">
        </div>
      <?php endif; ?>

      <?php if ($type === 'multiple_choice'): ?>
        <div class="mc-opts">
          <?php foreach ($answers as $ai => $aText):
            $isCorrect = !empty($corrFlags[$ai]);
            $showCorrect = $isKey && $isCorrect;
          ?>
          <div class="mc-opt<?= $showCorrect ? ' correct' : '' ?>">
            <span class="opt-l"><?= $LTRS[$ai] ?? chr(65 + $ai) ?></span>
            <?= h($aText) ?>
          </div>
          <?php endforeach; ?>
        </div>

      <?php elseif ($type === 'true_false'): ?>
        <div class="tf-opts">
          <?php
          $tfCorrect = '';
          foreach ($answers as $ai => $aText) {
              if (!empty($corrFlags[$ai])) { $tfCorrect = $aText; break; }
          }
          foreach ($answers as $ai => $aText):
            $isCorrect = !empty($corrFlags[$ai]);
            $show = $isKey && $isCorrect;
          ?>
          <div class="tf-opt<?= $show ? ' correct' : '' ?>">
            <span class="opt-l"><?= $LTRS[$ai] ?? ($ai === 0 ? 'T' : 'F') ?></span>
            <?= h($aText) ?>
          </div>
          <?php endforeach; ?>

      <?php elseif ($type === 'matching'): ?>
        <?php
        // Answers alternate: left1, right1, left2, right2...
        // Or stored as left[] and right[] — handle both
        $lefts  = [];
        $rights = [];
        for ($ai = 0; $ai < count($answers); $ai += 2) {
            $lefts[]  = $answers[$ai]        ?? '';
            $rights[] = $answers[$ai + 1]    ?? '';
        }
        $shuffledRights = $rights;
        if (!$isKey) shuffle($shuffledRights);
        ?>
        <div class="match-cols">
          <div>
            <div class="match-hd">Column A</div>
            <?php foreach ($lefts as $li => $lt): ?>
            <div class="match-row">
              <span class="match-n"><?= ($li+1) ?>.</span>
              <?php if (!$isKey): ?>
                <span class="match-bl"></span>
              <?php else: ?>
                <?php $matchIdx = array_search($rights[$li], $shuffledRights);
                      $matchLetter = $LTRS[$matchIdx !== false ? $matchIdx : $li] ?? ($li+1); ?>
                <span class="match-ans"><?= $matchLetter ?></span>
              <?php endif; ?>
              <span><?= h($lt) ?></span>
            </div>
            <?php endforeach; ?>
          </div>
          <div>
            <div class="match-hd">Column B</div>
            <?php foreach ($shuffledRights as $ri => $rt): ?>
            <div class="match-row">
              <span class="match-lt"><?= $LTRS[$ri] ?? chr(65+$ri) ?>.</span>
              <span><?= h($rt) ?></span>
            </div>
            <?php endforeach; ?>
          </div>
        </div>

      <?php elseif ($type === 'fill_blank'): ?>
        <?php
        $correctAns = '';
        foreach ($answers as $ai => $a) {
            if (!empty($corrFlags[$ai])) { $correctAns = $a; break; }
        }
        $allAnswers = array_filter($answers, fn($a) => trim($a) !== '');
        if (!$isKey && !empty($allAnswers)):
        ?>
        <div class="word-bank">
          <span class="wb-lbl">Word Bank:</span>
          <?php $bank = $allAnswers; shuffle($bank);
                foreach ($bank as $w): ?>
            <span class="wb-chip"><?= h($w) ?></span>
          <?php endforeach; ?>
        </div>
        <?php endif; ?>
        <?php if ($isKey && $correctAns !== ''): ?>
          <div class="ans-shown">&#10003; <?= h($correctAns) ?></div>
        <?php else: ?>
          <div class="open-lines">
            <div class="open-line"></div>
          </div>
        <?php endif; ?>

      <?php elseif (in_array($type, ['short_answer', 'essay', 'writing_practice'])): ?>
        <?php
        $correctAns = '';
        foreach ($answers as $ai => $a) {
            if (!empty($corrFlags[$ai])) { $correctAns = $a; break; }
        }
        ?>
        <?php if ($isKey && $correctAns !== ''): ?>
          <div class="ans-shown"><?= nl2br(h($correctAns)) ?></div>
        <?php elseif ($isKey): ?>
          <div class="ans-shown" style="color:var(--lila3);font-style:italic;font-weight:600;">Accept reasonable answers.</div>
        <?php else: ?>
          <div class="open-lines">
            <?php for ($l = 0; $l < $numLines; $l++): ?>
              <div class="open-line"></div>
            <?php endfor; ?>
          </div>
        <?php endif; ?>

      <?php else: ?>
        <?php /* Generic fallback — open lines */ ?>
        <?php if ($isKey): ?>
          <?php $correctAns = '';
                foreach ($answers as $ai => $a) { if (!empty($corrFlags[$ai])) { $correctAns = $a; break; } } ?>
          <?php if ($correctAns): ?>
            <div class="ans-shown">&#10003; <?= h($correctAns) ?></div>
          <?php endif; ?>
        <?php else: ?>
          <div class="open-lines">
            <div class="open-line"></div>
            <div class="open-line"></div>
          </div>
        <?php endif; ?>
      <?php endif; ?>

    </div><!-- /ws-sec -->
    <?php endforeach; ?>

  </div><!-- /ws-body -->

  <!-- Footer -->
  <div class="pg-footer">
    <div class="ft-brand">ONES — Online English Solution &nbsp;&middot;&nbsp; Let&rsquo;s Institute</div>
    <div class="ft-info">
      <?= $courseName ? h($courseName) . ' &nbsp;&middot;&nbsp; ' : '' ?>
      <?= $unitName ? h($unitName) . ' &nbsp;&middot;&nbsp; ' : '' ?>
      <?= h($examTitle) ?>
      <?= $isKey ? ' — Answer Key' : '' ?>
    </div>
    <div class="ft-pg">Printed <?= h($today) ?></div>
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
