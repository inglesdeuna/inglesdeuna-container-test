<?php
session_start();

if (!isset($_SESSION['admin_logged']) || $_SESSION['admin_logged'] !== true) {
    header('Location: /lessons/lessons/admin/login.php');
    exit;
}

require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../config/init_db.php';
require_once __DIR__ . '/exam_question_selector.php';

function h(string $v): string { return htmlspecialchars($v, ENT_QUOTES, 'UTF-8'); }

$tab = $_GET['tab'] ?? 'list';
$msg = '';
// Flash message from PRG redirect
if (($_GET['msg'] ?? '') === 'saved') $msg = 'Examen guardado.';
if (($_GET['msg'] ?? '') === 'deleted') $msg = 'Examen eliminado.';

// ─── POST: Crear / Editar examen ─────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $action = $_POST['action'];

    if ($action === 'save_exam') {
        $examId    = (int) ($_POST['exam_id'] ?? 0);
        $title     = trim($_POST['title'] ?? '');
        $level     = trim($_POST['cefr_level'] ?? '');
        $timeLimit = (int) ($_POST['time_limit_min'] ?? 50);
        $maxAtt    = (int) ($_POST['max_attempts'] ?? 1);
        $status    = in_array($_POST['status'] ?? '', ['draft','active','closed'], true) ? $_POST['status'] : 'draft';
        $modalities = array_values(array_filter((array) ($_POST['modalities'] ?? ['online'])));
        $instructions = trim($_POST['instructions'] ?? '');
        $unitId    = trim($_POST['unit_id'] ?? '') ?: null;

        if ($examId > 0) {
            $stmt = $pdo->prepare(
                "UPDATE eval_exams SET title=?, cefr_level=?, time_limit_min=?, max_attempts=?,
                 status=?, modalities=?, instructions=?, unit_id=? WHERE id=?"
            );
            $stmt->execute([$title, $level ?: null, $timeLimit, $maxAtt,
                $status, json_encode($modalities), $instructions, $unitId, $examId]);
        } else {
            $stmt = $pdo->prepare(
                "INSERT INTO eval_exams (title, cefr_level, time_limit_min, max_attempts,
                 status, modalities, instructions, unit_id, created_by)
                 VALUES (?,?,?,?,?,?,?,?,?) RETURNING id"
            );
            $stmt->execute([$title, $level ?: null, $timeLimit, $maxAtt,
                $status, json_encode($modalities), $instructions, $unitId,
                $_SESSION['admin_username'] ?? 'admin']);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            $examId = $row['id'];
        }
        // PRG (Post-Redirect-Get) — prevents stale POST and shows exam in list immediately
        $redirectTab = 'editor';
        header('Location: admin_eval.php?tab=' . $redirectTab . '&exam_id=' . $examId . '&msg=saved');
        exit;
    }

    if ($action === 'save_question') {
        $examId  = (int) ($_POST['exam_id'] ?? 0);
        $qId     = (int) ($_POST['question_id'] ?? 0);
        $type    = trim($_POST['type'] ?? 'multiple_choice');
        $skill   = trim($_POST['skill'] ?? 'grammar');
        $text    = trim($_POST['question_text'] ?? '');
        $audio   = trim($_POST['audio_url'] ?? '');
        $image   = trim($_POST['image_url'] ?? '');
        $points  = (float) ($_POST['points'] ?? 1);
        $pos     = (int) ($_POST['position'] ?? 0);

        if ($qId > 0) {
            $stmt = $pdo->prepare(
                "UPDATE eval_questions SET type=?, skill=?, question_text=?,
                 audio_url=?, image_url=?, points=?, position=? WHERE id=? AND exam_id=?"
            );
            $stmt->execute([$type, $skill, $text, $audio ?: null, $image ?: null,
                $points, $pos, $qId, $examId]);
        } else {
            $stmt = $pdo->prepare(
                "INSERT INTO eval_questions (exam_id, type, skill, question_text,
                 audio_url, image_url, points, position) VALUES (?,?,?,?,?,?,?,?) RETURNING id"
            );
            $stmt->execute([$examId, $type, $skill, $text,
                $audio ?: null, $image ?: null, $points, $pos]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            $qId = $row['id'];
        }

        // Guardar respuestas
        $pdo->prepare("DELETE FROM eval_answers WHERE question_id=?")->execute([$qId]);
        $answerTexts   = (array) ($_POST['answer_text'] ?? []);
        $answerCorrect = (array) ($_POST['answer_correct'] ?? []);
        $aStmt = $pdo->prepare(
            "INSERT INTO eval_answers (question_id, answer_text, is_correct, order_index) VALUES (?,?,?,?)"
        );
        foreach ($answerTexts as $idx => $aText) {
            $aText = trim((string) $aText);
            if ($aText === '') continue;
            $isCorrect = isset($answerCorrect[$idx]) && $answerCorrect[$idx] === '1';
            $aStmt->execute([$qId, $aText, $isCorrect ? 'true' : 'false', $idx]);
        }

        $msg = 'Pregunta guardada.';
        $tab = 'editor';
    }

    if ($action === 'delete_question') {
        $qId    = (int) ($_POST['question_id'] ?? 0);
        $examId = (int) ($_POST['exam_id'] ?? 0);
        $pdo->prepare("DELETE FROM eval_questions WHERE id=? AND exam_id=?")->execute([$qId, $examId]);
        $msg = 'Pregunta eliminada.';
        $tab = 'editor';
    }

    if ($action === 'generate_group_link') {
        $examId       = (int) ($_POST['exam_id'] ?? 0);
        $availDate    = trim($_POST['available_date'] ?? '');
        $durationHrs  = max(1, (int) ($_POST['duration_hours'] ?? 24));
        $maxUses      = (int) ($_POST['max_uses'] ?? 999);
        $token        = bin2hex(random_bytes(16));

        // Compute expires_at: start of available_date + duration_hours
        if ($availDate !== '') {
            $expiresTs = strtotime($availDate . ' 00:00:00') + ($durationHrs * 3600);
            $expires   = date('Y-m-d H:i:s', $expiresTs);
        } else {
            $expires = null;
        }

        $stmt = $pdo->prepare(
            "INSERT INTO eval_links (exam_id, token, link_type, max_uses, expires_at, created_by)
             VALUES (?,?,'group',?,?,?) RETURNING id"
        );
        $stmt->execute([$examId, $token, $maxUses, $expires,
            $_SESSION['admin_username'] ?? 'admin']);
        $msg = 'Link de grupo generado.';
        $tab = 'links';
    }

    if ($action === 'generate_individual_link') {
        $examId  = (int) ($_POST['exam_id'] ?? 0);
        $sName   = trim($_POST['student_name'] ?? '');
        $sDoc    = trim($_POST['student_doc'] ?? '');
        $sPhone  = trim($_POST['student_phone'] ?? '');
        $sEmail  = trim($_POST['student_email'] ?? '');
        $sProg   = trim($_POST['student_program'] ?? '');
        $token   = bin2hex(random_bytes(16));

        $stmt = $pdo->prepare(
            "INSERT INTO eval_links (exam_id, token, link_type, student_name, student_doc,
             student_phone, student_email, student_program, max_uses, created_by)
             VALUES (?,?,'individual',?,?,?,?,?,1,?) RETURNING id"
        );
        $stmt->execute([$examId, $token, $sName, $sDoc, $sPhone, $sEmail, $sProg,
            $_SESSION['admin_username'] ?? 'admin']);
        $msg = 'Link individual generado.';
        $tab = 'links';
    }

    if ($action === 'save_cefr_ranges') {
        $examId   = (int) ($_POST['exam_id'] ?? 0);
        $isGlobal = ($examId === 0);
        $levels   = (array) ($_POST['cefr_level'] ?? []);
        $labels   = (array) ($_POST['label'] ?? []);
        $mins     = (array) ($_POST['min_pct'] ?? []);
        $maxs     = (array) ($_POST['max_pct'] ?? []);

        foreach ($levels as $i => $level) {
            $level = trim((string) $level);
            if ($level === '') continue;
            $min = (float) ($mins[$i] ?? 0);
            $max = (float) ($maxs[$i] ?? 100);
            $lbl = trim((string) ($labels[$i] ?? ''));

            // Upsert
            if ($isGlobal) {
                $check = $pdo->prepare(
                    "SELECT id FROM eval_cefr_ranges WHERE is_global=TRUE AND cefr_level=?"
                );
            } else {
                $check = $pdo->prepare(
                    "SELECT id FROM eval_cefr_ranges WHERE exam_id=? AND cefr_level=?"
                );
            }
            $checkParams = $isGlobal ? [$level] : [$examId, $level];
            $check->execute($checkParams);
            $existing = $check->fetch(PDO::FETCH_ASSOC);

            if ($existing) {
                $pdo->prepare(
                    "UPDATE eval_cefr_ranges SET label=?, min_pct=?, max_pct=? WHERE id=?"
                )->execute([$lbl, $min, $max, $existing['id']]);
            } else {
                $pdo->prepare(
                    "INSERT INTO eval_cefr_ranges (exam_id, cefr_level, label, min_pct, max_pct, is_global)
                     VALUES (?,?,?,?,?,?)"
                )->execute([$isGlobal ? null : $examId, $level, $lbl, $min, $max, $isGlobal ? 'true' : 'false']);
            }
        }
        $msg = 'Rangos MCER guardados.';
        $tab = 'cefr';
    }

    if ($action === 'delete_exam') {
        $examId = (int) ($_POST['exam_id'] ?? 0);
        if ($examId > 0) {
            // CASCADE deletes eval_questions, eval_answers, eval_links, eval_results via FK
            $pdo->prepare("DELETE FROM eval_exams WHERE id=?")->execute([$examId]);
            header('Location: admin_eval.php?tab=list&msg=deleted');
            exit;
        }
    }

    if ($action === 'delete_link') {
        $linkId = (int) ($_POST['link_id'] ?? 0);
        $examId = (int) ($_POST['exam_id'] ?? 0);
        if ($linkId > 0) {
            $pdo->prepare("DELETE FROM eval_links WHERE id=?")->execute([$linkId]);
            $msg = 'Link eliminado.';
            $tab = 'links';
        }
    }

    if ($action === 'edit_link') {
        $linkId      = (int) ($_POST['link_id']       ?? 0);
        $examId      = (int) ($_POST['exam_id']       ?? 0);
        $availDate   = trim($_POST['available_date']  ?? '');
        $durationHrs = max(1, (int) ($_POST['duration_hours'] ?? 24));
        $maxUses     = (int) ($_POST['max_uses']      ?? 999);
        if ($linkId > 0) {
            if ($availDate !== '') {
                $expiresTs = strtotime($availDate . ' 00:00:00') + ($durationHrs * 3600);
                $expiresAt = date('Y-m-d H:i:s', $expiresTs);
            } else {
                $expiresAt = null;
            }
            $pdo->prepare(
                "UPDATE eval_links SET expires_at=?, max_uses=? WHERE id=?"
            )->execute([$expiresAt, $maxUses, $linkId]);
            $msg = 'Link actualizado.';
            $tab = 'links';
        }
    }

    if ($action === 'register_printed') {
        $examId = (int) ($_POST['exam_id'] ?? 0);
        $name   = trim($_POST['student_name'] ?? '');
        $doc    = trim($_POST['student_doc'] ?? '');
        $score  = (float) ($_POST['score'] ?? 0);
        $maxSc  = (float) ($_POST['max_score'] ?? 100);
        $pct    = $maxSc > 0 ? round($score / $maxSc * 100, 2) : 0;

        // Sugerir MCER
        $cefrStmt = $pdo->prepare(
            "SELECT cefr_level FROM eval_cefr_ranges
             WHERE is_global=TRUE AND ? BETWEEN min_pct AND max_pct ORDER BY min_pct LIMIT 1"
        );
        $cefrStmt->execute([$pct]);
        $cefrRow = $cefrStmt->fetch(PDO::FETCH_ASSOC);
        $cefr = $cefrRow ? $cefrRow['cefr_level'] : 'A1';

        $pdo->prepare(
            "INSERT INTO eval_results (exam_id, student_name, student_doc, modality,
             score, max_score, pct, cefr_suggested, status, submitted_at)
             VALUES (?,?,?,'printed',?,?,?,?,'submitted',CURRENT_TIMESTAMP)"
        )->execute([$examId, $name, $doc, $score, $maxSc, $pct, $cefr]);
        $msg = 'Nota impresa registrada.';
        $tab = 'results';
    }
}

// ─── Cargar datos para la vista ───────────────────────────────────────────────
$currentExamId = (int) ($_GET['exam_id'] ?? $_POST['exam_id'] ?? 0);

// Lista de exámenes (con nombre de unidad asociada)
$exams = $pdo->query(
    "SELECT e.*, u.name AS unit_name,
            (SELECT COUNT(*) FROM eval_questions WHERE exam_id=e.id) AS q_count
     FROM eval_exams e
     LEFT JOIN units u ON u.id = e.unit_id
     ORDER BY e.created_at DESC"
)->fetchAll(PDO::FETCH_ASSOC);

// Unidades agrupadas por curso para el selector
// Build Level → Phase → Units (same structure as english_courses_created.php)
$unitsTree = []; // [level_name => [phase_name => [[id, unit_name], ...]]]
try {
    // Try english_levels / english_phases structure first
    $uRows = $pdo->query(
        "SELECT
            COALESCE(l.name, 'English')   AS level_name,
            COALESCE(p.name, 'Sin fase')  AS phase_name,
            p.id                           AS phase_id,
            u.id,
            u.name                         AS unit_name
         FROM units u
         LEFT JOIN english_phases p  ON p.id  = u.phase_id
         LEFT JOIN english_levels l  ON l.id  = p.level_id
         ORDER BY l.id ASC, p.id ASC, u.position ASC, u.name ASC"
    )->fetchAll(PDO::FETCH_ASSOC);

    foreach ($uRows as $ur) {
        $lName = trim((string)($ur['level_name'] ?? 'English'));
        $pName = trim((string)($ur['phase_name'] ?? 'Sin fase'));
        $unitsTree[$lName][$pName][] = [
            'id'        => $ur['id'],
            'unit_name' => $ur['unit_name'],
        ];
    }
} catch (Exception $e) {}

// Fallback: course-based grouping if phase tables don't exist
$unitsByCourse = [];
if (empty($unitsTree)) {
    try {
        $uRows = $pdo->query(
            "SELECT u.id, u.name AS unit_name, c.name AS course_name
             FROM units u
             LEFT JOIN courses c ON c.id = u.course_id
             ORDER BY c.name, u.name"
        )->fetchAll(PDO::FETCH_ASSOC);
        foreach ($uRows as $ur) {
            $cName = $ur['course_name'] ?? 'Sin curso';
            $unitsByCourse[$cName][] = $ur;
        }
    } catch (Exception $e) {}
}

$currentExam = null;
$examQuestions = [];
$examLinks = [];
$examResults = [];
$cefrRanges = [];

if ($currentExamId > 0) {
    $stmt = $pdo->prepare(
        "SELECT e.*, u.name AS unit_name FROM eval_exams e LEFT JOIN units u ON u.id = e.unit_id WHERE e.id=?"
    );
    $stmt->execute([$currentExamId]);
    $currentExam = $stmt->fetch(PDO::FETCH_ASSOC);

    $stmt = $pdo->prepare(
        "SELECT eq.*, array_agg(ea.answer_text ORDER BY ea.order_index) FILTER (WHERE ea.answer_text IS NOT NULL) AS answer_texts,
         array_agg(ea.is_correct ORDER BY ea.order_index) FILTER (WHERE ea.answer_text IS NOT NULL) AS answer_corrects
         FROM eval_questions eq
         LEFT JOIN eval_answers ea ON ea.question_id = eq.id
         WHERE eq.exam_id=? GROUP BY eq.id ORDER BY eq.position, eq.id"
    );
    $stmt->execute([$currentExamId]);
    $examQuestions = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $stmt = $pdo->prepare(
        "SELECT *, (SELECT COUNT(*) FROM eval_results WHERE link_id=eval_links.id) AS submissions
         FROM eval_links WHERE exam_id=? ORDER BY created_at DESC"
    );
    $stmt->execute([$currentExamId]);
    $examLinks = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $stmt = $pdo->prepare(
        "SELECT * FROM eval_results WHERE exam_id=? ORDER BY COALESCE(submitted_at, started_at) DESC"
    );
    $stmt->execute([$currentExamId]);
    $examResults = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $stmt = $pdo->prepare("SELECT * FROM eval_cefr_ranges WHERE exam_id=? ORDER BY min_pct");
    $stmt->execute([$currentExamId]);
    $cefrRanges = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// Rangos globales
$globalRanges = $pdo->query(
    "SELECT * FROM eval_cefr_ranges WHERE is_global=TRUE ORDER BY min_pct"
)->fetchAll(PDO::FETCH_ASSOC);

// Stats generales
$statsActiveExams = (int) $pdo->query("SELECT COUNT(*) FROM eval_exams WHERE status='active'")->fetchColumn();
$statsThisMonth   = (int) $pdo->query("SELECT COUNT(*) FROM eval_results WHERE submitted_at >= date_trunc('month', NOW())")->fetchColumn();
$statsPending     = (int) $pdo->query("SELECT COUNT(*) FROM eval_results WHERE status='started'")->fetchColumn();
$statsLinks       = (int) $pdo->query("SELECT COUNT(*) FROM eval_links WHERE (expires_at IS NULL OR expires_at > NOW()) AND uses_count < max_uses")->fetchColumn();

$cefrColors = ['A1'=>'#6c757d','A2'=>'#17a2b8','B1'=>'#28a745','B2'=>'#007bff','C1'=>'#6f42c1','C2'=>'#dc3545'];
$skillLabels = ['grammar'=>'Grammar','vocabulary'=>'Vocabulary','listening'=>'Listening','reading'=>'Reading','writing'=>'Writing','speaking'=>'Speaking'];
$activityTypes = array_keys(SKILL_MAP);

// Always use https — Render runs behind a proxy so $_SERVER['HTTPS'] is not reliable
$_host   = 'https://' . ($_SERVER['HTTP_HOST'] ?? 'inglesdeuna-container-test.onrender.com');
$baseUrl = $_host . '/lessons/lessons/activities/eval/eval_viewer.php?t=';
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Módulo de Evaluaciones</title>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@tabler/icons-webfont@latest/tabler-icons.min.css">
<style>
@import url('https://fonts.googleapis.com/css2?family=Fredoka+One&family=Nunito:wght@400;500;600;700;800&display=swap');
*{box-sizing:border-box;margin:0;padding:0}
body{font-family:'Nunito',sans-serif;background:#F0EFF8;}

/* ── Shell: topbar spans full width, sidebar+main below ── */
.eval-topbar{
  display:grid;grid-template-columns:248px 1fr auto;
  height:60px;background:#fff;
  border-bottom:1.5px solid #EDE9FA;
  position:fixed;top:0;left:0;right:0;z-index:100;
}
.eval-topbar-brand{
  display:flex;align-items:center;gap:11px;
  padding:0 20px;border-right:1.5px solid #EDE9FA;
}
.eval-topbar-brand svg{
  width:38px;height:38px;flex-shrink:0;
  filter:drop-shadow(0 2px 6px rgba(249,115,22,.22));
}
.tb-wordmark{display:flex;flex-direction:column;line-height:1;}
.tb-ones{font-family:'Fredoka One',sans-serif;font-size:21px;color:#F97316;line-height:1;}
.tb-tagline{font-size:9px;font-weight:700;color:#9B8FCC;letter-spacing:1.6px;text-transform:uppercase;margin-top:3px;}
.eval-topbar-center{
  display:flex;align-items:center;gap:10px;padding:0 24px;
}
.eval-topbar-title{font-size:15px;font-weight:700;color:#1a1a2e;font-family:'Nunito',sans-serif;}
.topbar-pill{background:#EEEDFE;color:#534AB7;font-size:10px;font-weight:700;padding:3px 10px;border-radius:20px;}
.eval-topbar-right{
  display:flex;align-items:center;gap:8px;padding:0 20px;
}

/* ── Shell grid below topbar ── */
.eval-shell{
  display:grid;
  grid-template-columns:248px 1fr;
  height:calc(100vh - 60px);
  margin-top:60px;
  overflow:hidden;
}

/* ── Sidebar ── */
.eval-sidebar{
  background:#fff;border-right:1.5px solid #EDE9FA;
  padding:19px 12px 0;
  display:flex;flex-direction:column;gap:2px;
  overflow-y:auto;overflow-x:hidden;
}
.eval-sidebar::-webkit-scrollbar{width:3px;}
.eval-sidebar::-webkit-scrollbar-thumb{background:#EDE9FA;border-radius:99px;}
.es-section{
  font-size:9.5px;font-weight:800;color:#C4B9E8;
  text-transform:uppercase;letter-spacing:.13em;
  padding:14px 10px 5px;display:block;
}
.es-item{
  display:flex;align-items:center;gap:9px;padding:9px 12px;
  border-radius:10px;font-family:'Nunito',sans-serif;
  font-size:13px;font-weight:700;color:#6B7280;
  cursor:pointer;border:none;background:none;
  width:100%;text-align:left;transition:all .15s;
}
.es-item:hover{background:#F5F3FF;color:#534AB7;}
.es-item.active{
  background:linear-gradient(135deg,#EEEDFE,#F5F3FF);
  color:#534AB7;border-left:3px solid #7F77DD;
}
.es-item i{font-size:16px;flex-shrink:0;}
.es-current{
  margin:6px 2px 0;
  background:linear-gradient(135deg,#FFF8F5,#FFF0E6);
  border:1.5px solid #FDDCB5;border-radius:12px;padding:11px 13px;
}
.es-current-lbl{
  font-size:9px;font-weight:800;color:#F97316;
  text-transform:uppercase;letter-spacing:.12em;margin-bottom:4px;
}
.es-current-name{font-size:13px;font-weight:800;color:#1a1a2e;line-height:1.3;}
.es-bottom{
  margin-top:auto;padding:14px 10px 0;
  border-top:1.5px solid #EDE9FA;
}
.es-powered{
  text-align:center;padding:12px 8px 16px;
}
.es-powered-by{font-size:10px;font-weight:600;color:#C4B9E8;letter-spacing:.05em;}
.es-powered-name{
  font-family:'Fredoka One',sans-serif;font-size:16px;
  color:#7F77DD;letter-spacing:.3px;margin-top:3px;display:block;
}
.es-powered-tag{font-size:9px;font-weight:700;color:#9B8FCC;letter-spacing:.08em;display:block;margin-top:2px;}

/* ── Main scroll ── */
.eval-scroll{
  overflow-y:auto;padding:19px;
  display:flex;flex-direction:column;gap:19px;
  background:#F0EFF8;
}
.eval-scroll::-webkit-scrollbar{width:5px;}
.eval-scroll::-webkit-scrollbar-thumb{background:#C4B9E8;border-radius:99px;}

/* ── Stats row ── */
.stats-row{display:grid;grid-template-columns:repeat(4,1fr);gap:19px;}
.stat-box{background:#fff;border-radius:14px;padding:19px;border:1.5px solid #EDE9FA;}
.stat-label{
  display:block;font-size:10px;font-weight:800;color:#9B8FCC;
  text-transform:uppercase;letter-spacing:.1em;margin-bottom:7px;
}
.stat-value{
  display:block;font-family:'Fredoka One',sans-serif;font-size:28px;
  color:#7F77DD;line-height:1;
}
.stat-value.ora{color:#F97316;}
.stat-value.pur{color:#7F77DD;}

/* ── Card ── */
.card{
  background:#fff;border-radius:16px;
  border:1.5px solid #EDE9FA;overflow:hidden;
  margin-bottom:0;
}
.card h3{
  font-family:'Fredoka One',sans-serif;font-size:17px;
  color:#1a1a2e;letter-spacing:.2px;margin:0 0 19px;
}
.card-head{
  display:flex;align-items:center;justify-content:space-between;
  padding:13px 19px;border-bottom:1.5px solid #EDE9FA;
}
.card-sub-text{font-size:11px;font-weight:600;color:#9B8FCC;margin-top:3px;}
/* Content sections inside cards that are NOT table or card-head */
.card > form,
.card > .card-body,
.card > p,
.card > .form-row,
.card > .form-row-3,
.card > .form-group,
.card > h3 {
  padding-left:19px;padding-right:19px;
}
.card > h3:first-child { padding-top:19px; }
.card > form { padding-top:19px; padding-bottom:19px; }
.card-body { padding:19px; }

/* ── Table ── */
table{width:100%;border-collapse:collapse;font-size:12.5px;font-family:'Nunito',sans-serif;}
th{
  text-align:left;padding:9px 19px;background:#FAFAFE;
  color:#9B8FCC;font-size:10px;font-weight:800;
  text-transform:uppercase;letter-spacing:.1em;
  border-bottom:1.5px solid #EDE9FA;white-space:nowrap;
}
td{padding:11px 19px;border-bottom:1.5px solid #EDE9FA;vertical-align:middle;}
tr:last-child td{border-bottom:none;}
tr:hover td{background:#FAFAFE;}

/* ── Badges ── */
.badge{
  display:inline-flex;align-items:center;gap:5px;
  padding:3px 9px;border-radius:7px;
  font-size:11px;font-weight:800;
}
.badge-draft{background:#F3F4F6;color:#6B7280;border:1.5px solid #E5E7EB;}
.badge-active{background:#D1FAE5;color:#065F46;}
.badge-closed{background:#FEE2E2;color:#DC2626;}
.badge-online{background:#EFF6FF;color:#1D4ED8;}
.badge-printed{background:#FEF3C7;color:#92400E;}
.badge-group{background:#D1FAE5;color:#065F46;}
.badge-individual{background:#EFF6FF;color:#1D4ED8;}
.badge-expired{background:#FEE2E2;color:#DC2626;}
.badge-b1{background:#EEEDFE;color:#534AB7;}
.badge-unit{background:#ECFDF5;color:#065F46;}
.badge-dot{width:5px;height:5px;border-radius:50%;background:#10B981;flex-shrink:0;}

/* ── Buttons ── */
.btn{
  display:inline-flex;align-items:center;gap:6px;
  padding:8px 15px;border-radius:9px;
  font-family:'Nunito',sans-serif;font-size:12.5px;font-weight:700;
  cursor:pointer;border:1.5px solid #EDE9FA;
  background:#fff;color:#374151;
  white-space:nowrap;transition:all .15s;text-decoration:none;
}
.btn i{font-size:14px;}
.btn:hover{background:#F5F3FF;border-color:#C4B9E8;color:#534AB7;}
.btn-primary{background:#16A34A;border-color:#16A34A;color:#fff;}
.btn-primary:hover{background:#15803D;border-color:#15803D;color:#fff;}
.btn-secondary{background:#fff;border-color:#EDE9FA;color:#374151;}
.btn-secondary:hover{background:#F5F3FF;border-color:#C4B9E8;color:#534AB7;}
.btn-purple{background:#7F77DD;border-color:#7F77DD;color:#fff;}
.btn-purple:hover{background:#6B63CC;border-color:#6B63CC;color:#fff;}
.btn-green{background:#25D366;border-color:#25D366;color:#fff;}
.btn-green:hover{background:#1EBF5A;border-color:#1EBF5A;color:#fff;}
.btn-danger{background:#DC2626;border-color:#DC2626;color:#fff;}
.btn-danger:hover{background:#B91C1C;border-color:#B91C1C;color:#fff;}
.btn-sm{padding:6px 11px;font-size:11.5px;border-radius:8px;}
.btn-sm i{font-size:13px;}

/* ── Misc ── */
.actions-cell{display:flex;gap:5px;align-items:center;flex-wrap:nowrap;}
.mono{font-family:monospace;font-size:11px;color:#9B8FCC;letter-spacing:.05em;}
.exp-date{color:#DC2626;font-weight:700;}
.filter-row{
  display:flex;gap:10px;padding:11px 19px;
  border-bottom:1.5px solid #EDE9FA;background:#FAFAFE;
  flex-wrap:wrap;align-items:center;
}
.filter-row input,.filter-row select{
  font-family:'Nunito',sans-serif;font-size:12px;font-weight:600;
  padding:7px 11px;border-radius:9px;
  border:1.5px solid #EDE9FA;background:#fff;color:#374151;height:34px;
}
.filter-row input{flex:1;max-width:210px;}
.form-group{margin-bottom:14px;}
.form-group label{
  display:block;font-size:11px;font-weight:700;color:#9B8FCC;
  text-transform:uppercase;letter-spacing:.08em;margin-bottom:5px;
}
.form-group input,.form-group select,.form-group textarea{
  width:100%;padding:8px 12px;
  border:1.5px solid #EDE9FA;border-radius:10px;
  font-size:13px;font-family:'Nunito',sans-serif;
  background:#fff;color:#1a1a2e;
}
.form-group textarea{min-height:80px;resize:vertical;}
.form-row{display:grid;grid-template-columns:1fr 1fr;gap:14px;}
.form-row-3{display:grid;grid-template-columns:1fr 1fr 1fr;gap:14px;}
.msg{
  padding:11px 16px;border-radius:10px;margin-bottom:14px;
  font-size:13px;font-weight:700;
  background:#D1FAE5;color:#065F46;
  border:1.5px solid #A7F3D0;
}
.tab-panel{display:none;}
.tab-panel.active{display:block;}
.skill-chip{
  display:inline-block;padding:4px 12px;border-radius:20px;
  font-size:12px;font-weight:700;cursor:pointer;
  border:1.5px solid #EDE9FA;margin:3px;transition:all .2s;
  font-family:'Nunito',sans-serif;
}
.skill-chip.selected{background:#7F77DD;color:#fff;border-color:#7F77DD;}
.modal-bg{
  display:none;position:fixed;inset:0;
  background:rgba(0,0,0,.4);z-index:1000;
  align-items:center;justify-content:center;
}
.modal-bg.open{display:flex;}
.modal{
  background:#fff;border-radius:16px;padding:28px;
  max-width:520px;width:95%;
  box-shadow:0 20px 60px rgba(127,119,221,.2);
}
.modal h3{
  margin:0 0 18px;font-family:'Fredoka One',sans-serif;
  font-size:20px;color:#1a1a2e;
}
.url-box{
  background:#FAFAFE;border:1.5px solid #EDE9FA;border-radius:10px;
  padding:10px 14px;font-size:13px;word-break:break-all;
  margin-bottom:12px;color:#374151;font-family:monospace;
}
.cefr-badge{
  display:inline-block;padding:3px 10px;border-radius:20px;
  font-size:11px;font-weight:700;color:#fff;
}
.side-doc-actions{display:flex;gap:8px;}
.side-doc-actions .btn{flex:1;justify-content:center;}
.dur-wrap{display:flex;align-items:center;gap:8px;}
.dur-wrap input[type=number]{flex:1;min-width:0;}
.dur-clock{
  display:inline-flex;align-items:center;gap:5px;
  padding:5px 10px;border-radius:9px;
  background:#F5F3FF;border:1.5px solid #EDE9FA;
  font-size:12px;font-weight:700;color:#7F77DD;white-space:nowrap;
}
.dur-clock i{font-size:13px;}
@media(max-width:900px){
  .eval-shell{grid-template-columns:1fr;}
  .eval-sidebar{display:none;}
  .stats-row{grid-template-columns:1fr 1fr;}
  .form-row,.form-row-3{grid-template-columns:1fr;}
}
</style>
</head>
<body>

<div class="eval-topbar">
  <div class="eval-topbar-brand">
    <svg width="38" height="38" viewBox="0 0 38 38" fill="none" xmlns="http://www.w3.org/2000/svg">
    <rect width="38" height="38" rx="11" fill="#FFF0E6"/>
    <circle cx="17" cy="16" r="10" fill="#F97316"/>
    <polygon points="13,25 8,33 22,29" fill="#F97316"/>
    <circle cx="17" cy="16" r="5.5" fill="#FFF0E6"/>
    <circle cx="27" cy="9" r="4" fill="#7F77DD"/>
    <circle cx="27" cy="9" r="2" fill="#ffffff"/>
  </svg>
    <div class="tb-wordmark">
      <span class="tb-ones">ONES</span>
      <span class="tb-tagline">Online English Solution</span>
    </div>
  </div>
  <div class="eval-topbar-center">
    <span class="eval-topbar-title">Módulo de Evaluaciones</span>
    <span class="topbar-pill">Admin</span>
  </div>
  <div class="eval-topbar-right">
    <a href="/lessons/lessons/admin/dashboard.php" class="btn btn-sm"><i class="ti ti-layout-dashboard" aria-hidden="true"></i>Dashboard</a>
    <a href="/lessons/lessons/admin/logout.php" class="btn btn-sm"><i class="ti ti-logout" aria-hidden="true"></i>Cerrar sesión</a>
  </div>
</div>

<div class="eval-shell">
  <aside class="eval-sidebar">


    <span class="es-section">Módulo</span>
    <button class="es-item <?= $tab==='list'?'active':'' ?>" onclick="showTab('list')">
      <i class="ti ti-clipboard-list" aria-hidden="true"></i>Todos los exámenes</button>
    <button class="es-item <?= $tab==='editor'?'active':'' ?>" onclick="showTab('editor')">
      <i class="ti ti-plus" aria-hidden="true"></i>Crear examen</button>
    <button class="es-item <?= $tab==='links'?'active':'' ?>" onclick="showTab('links')">
      <i class="ti ti-link" aria-hidden="true"></i>Links activos</button>
    <button class="es-item <?= $tab==='results'?'active':'' ?>" onclick="showTab('results')">
      <i class="ti ti-chart-bar" aria-hidden="true"></i>Resultados</button>
    <button class="es-item <?= $tab==='cefr'?'active':'' ?>" onclick="showTab('cefr')">
      <i class="ti ti-certificate" aria-hidden="true"></i>Rangos MCER</button>

    <?php if ($currentExam): ?>
    <span class="es-section">Examen actual</span>
    <div class="es-current">
      <div class="es-current-lbl">Seleccionado</div>
      <?= h($currentExam['title']) ?>
      <span class="badge badge-<?= h($currentExam['status']) ?>" style="margin-left:6px;font-size:10px;">
        <?= h($currentExam['status']) ?></span>
    </div>
    <?php endif; ?>

    <div class="es-bottom">
      <a class="es-item" href="/lessons/lessons/admin/dashboard.php">
        <i class="ti ti-layout-dashboard" aria-hidden="true"></i>Dashboard</a>
      <div class="es-powered">
        <div class="es-powered-by">Powered by</div>
        <span class="es-powered-name">Let's Institute</span>
        <span class="es-powered-tag">ONES &middot; Online English Solution</span>
      </div>
    </div>
  </aside>

  <div class="eval-main eval-scroll">

    <?php if ($msg): ?>
    <div class="msg"><?= h($msg) ?></div>
    <?php endif; ?>

    <!-- TAB: Lista de exámenes -->
    <div id="tab-list" class="tab-panel">
      <div class="stats-row">
        <div class="stat-box"><span class="stat-label">Exámenes activos</span><span class="stat-value ora"><?= $statsActiveExams ?></span></div>
        <div class="stat-box"><span class="stat-label">Presentados este mes</span><span class="stat-value ora"><?= $statsThisMonth ?></span></div>
        <div class="stat-box"><span class="stat-label">Pendientes de nota</span><span class="stat-value ora"><?= $statsPending ?></span></div>
        <div class="stat-box"><span class="stat-label">Links activos</span><span class="stat-value ora"><?= $statsLinks ?></span></div>
      </div>

      <div class="card">
        <div class="card-head"><h3>Exámenes</h3><button class="btn btn-primary" onclick="showTab('editor')">+ Crear examen</button></div>
        <div class="filter-row">
          <input type="text" id="search-exam" placeholder="Buscar..." oninput="filterExams()">
          <select id="filter-level" onchange="filterExams()">
            <option value="">Todos los niveles</option>
            <?php foreach (['A1','A2','B1','B2','C1','C2'] as $lvl): ?>
            <option value="<?= $lvl ?>"><?= $lvl ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <table id="exam-table">
          <thead>
            <tr>
              <th>Nivel</th><th>Nombre</th><th>Unidad</th><th>Preguntas / Tiempo</th>
              <th>Status</th><th>Acciones</th>
            </tr>
          </thead>
          <tbody>
          <?php foreach ($exams as $ex): ?>
          <?php $cc = $cefrColors[$ex['cefr_level'] ?? ''] ?? '#6c757d'; ?>
          <tr data-title="<?= h(strtolower($ex['title'])) ?>" data-level="<?= h($ex['cefr_level'] ?? '') ?>">
            <td><?php if ($ex['cefr_level']): ?><span class="cefr-badge" style="background:<?= $cc ?>"><?= h($ex['cefr_level']) ?></span><?php endif; ?></td>
            <td><strong><?= h($ex['title']) ?></strong></td>
            <td><?php if ($ex['unit_name']): ?><span class="badge badge-online" style="font-size:11px;">📚 <?= h($ex['unit_name']) ?></span><?php else: ?><span style="color:var(--muted);font-size:12px;">—</span><?php endif; ?></td>
            <td><?= (int)$ex['q_count'] ?> preguntas / <?= (int)$ex['time_limit_min'] ?> min</td>
            <td><span class="badge badge-<?= h($ex['status']) ?>"><?= h($ex['status']) ?></span></td>
            <td>
              <a class="btn btn-primary btn-sm" href="?tab=links&exam_id=<?= $ex['id'] ?>">Enviar</a>
              <a class="btn btn-secondary btn-sm" href="?tab=editor&exam_id=<?= $ex['id'] ?>">Editar</a>
              <a class="btn btn-secondary btn-sm" href="eval_results.php?exam_id=<?= $ex['id'] ?>">Resultados</a>
              <form method="POST" style="display:inline"
                    onsubmit="return confirm('¿Eliminar el examen «<?= h(addslashes($ex['title'])) ?>» y todos sus links y resultados? Esta acción no se puede deshacer.')">
                <input type="hidden" name="action"   value="delete_exam">
                <input type="hidden" name="exam_id"  value="<?= $ex['id'] ?>">
                <button type="submit" class="btn btn-danger btn-sm">🗑 Eliminar</button>
              </form>
            </td>
          </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>

    <!-- TAB: Editor de preguntas -->
    <div id="tab-editor" class="tab-panel">
      <div class="card">
        <div class="card-head">
          <h3><?= $currentExam ? 'Editar examen: ' . h($currentExam['title']) : 'Crear examen' ?></h3>
        </div>
        <div class="card-body">
        <form method="POST">
          <input type="hidden" name="action" value="save_exam">
          <input type="hidden" name="exam_id" value="<?= $currentExamId ?>">
          <div class="form-row">
            <div class="form-group">
              <label>Nombre del examen *</label>
              <input type="text" name="title" required value="<?= h($currentExam['title'] ?? '') ?>">
            </div>
            <div class="form-group">
              <label>Nivel MCER</label>
              <select name="cefr_level">
                <option value="">Sin nivel</option>
                <?php foreach (['A1','A2','B1','B2','C1','C2'] as $lvl): ?>
                <option value="<?= $lvl ?>" <?= ($currentExam['cefr_level'] ?? '') === $lvl ? 'selected' : '' ?>><?= $lvl ?></option>
                <?php endforeach; ?>
              </select>
            </div>
          </div>
          <div class="form-group">
            <label>Unidad asociada <span style="font-weight:400;color:var(--muted)">(opcional — para exámenes de unidad con link compartible)</span></label>
            <select name="unit_id" style="font-size:13px;">
              <option value="">— Sin unidad —</option>
              <?php if (!empty($unitsTree)): ?>
                <?php foreach ($unitsTree as $levelName => $phases): ?>
                  <?php foreach ($phases as $phaseName => $punits): ?>
                  <optgroup label="<?= h($levelName . ' · ' . $phaseName) ?>">
                    <?php foreach ($punits as $u): ?>
                    <option value="<?= h($u['id']) ?>"
                      <?= ((string)($currentExam['unit_id'] ?? '')) === (string)$u['id'] ? 'selected' : '' ?>>
                      <?= h($u['unit_name']) ?>
                    </option>
                    <?php endforeach; ?>
                  </optgroup>
                  <?php endforeach; ?>
                <?php endforeach; ?>
              <?php else: ?>
                <?php foreach ($unitsByCourse as $courseName => $courseUnits): ?>
                <optgroup label="<?= h($courseName) ?>">
                  <?php foreach ($courseUnits as $u): ?>
                  <option value="<?= h($u['id']) ?>"
                    <?= ((string)($currentExam['unit_id'] ?? '')) === (string)$u['id'] ? 'selected' : '' ?>>
                    <?= h($u['unit_name']) ?>
                  </option>
                  <?php endforeach; ?>
                </optgroup>
                <?php endforeach; ?>
              <?php endif; ?>
            </select>
          </div>
          <div class="form-row-3">
            <div class="form-group">
              <label>Tiempo (minutos)</label>
              <input type="number" name="time_limit_min" value="<?= (int)($currentExam['time_limit_min'] ?? 50) ?>" min="1">
            </div>
            <div class="form-group">
              <label>Intentos máximos</label>
              <input type="number" name="max_attempts" value="<?= (int)($currentExam['max_attempts'] ?? 1) ?>" min="1">
            </div>
            <div class="form-group">
              <label>Status</label>
              <select name="status">
                <?php foreach (['draft','active','closed'] as $st): ?>
                <option value="<?= $st ?>" <?= ($currentExam['status'] ?? 'draft') === $st ? 'selected' : '' ?>><?= ucfirst($st) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
          </div>
          <div class="form-group">
            <label>Modalidades</label>
            <?php
            $mods = json_decode($currentExam['modalities'] ?? '["online","printed"]', true) ?: ['online','printed'];
            foreach (['online' => 'Online (sin usuario)', 'printed' => 'Impreso', 'registered' => 'Con usuario registrado'] as $mv => $ml):
            ?>
            <label style="font-weight:400;display:inline-flex;align-items:center;gap:6px;margin-right:14px;">
              <input type="checkbox" name="modalities[]" value="<?= $mv ?>" <?= in_array($mv, $mods, true) ? 'checked' : '' ?>>
              <?= $ml ?>
            </label>
            <?php endforeach; ?>
          </div>
          <div class="form-group">
            <label>Instrucciones</label>
            <textarea name="instructions"><?= h($currentExam['instructions'] ?? '') ?></textarea>
          </div>
          <button type="submit" class="btn btn-primary">Guardar examen</button>
        </form>
        </div><!-- /card-body -->
      </div>

      <?php if ($currentExamId > 0): ?>
      <!-- Lista de preguntas -->
      <div class="card" style="margin-top:19px;">
        <div class="card-head">
          <h3>Preguntas del examen</h3>
          <button class="btn btn-primary" onclick="openQuestionModal(0)">+ Agregar pregunta</button>
        </div>
        <table>
          <thead>
            <tr><th>#</th><th>Tipo</th><th>Skill</th><th>Pregunta</th><th>Pts</th><th>Acciones</th></tr>
          </thead>
          <tbody>
          <?php foreach ($examQuestions as $i => $q): ?>
          <tr>
            <td><?= $i + 1 ?></td>
            <td><span class="badge badge-draft"><?= h($q['type']) ?></span></td>
            <td><?= h($q['skill'] ?? '') ?></td>
            <td><?= h(mb_substr($q['question_text'] ?? '', 0, 60)) ?><?= mb_strlen($q['question_text'] ?? '') > 60 ? '…' : '' ?></td>
            <td><?= h($q['points'] ?? '1') ?></td>
            <td>
              <button class="btn btn-secondary btn-sm" onclick='openQuestionModal(<?= $q["id"] ?>,<?= htmlspecialchars(json_encode($q), ENT_QUOTES) ?>)'>Editar</button>
              <form method="POST" style="display:inline" onsubmit="return confirm('¿Eliminar esta pregunta?')">
                <input type="hidden" name="action" value="delete_question">
                <input type="hidden" name="exam_id" value="<?= $currentExamId ?>">
                <input type="hidden" name="question_id" value="<?= $q['id'] ?>">
                <button type="submit" class="btn btn-danger btn-sm">Eliminar</button>
              </form>
            </td>
          </tr>
          <?php endforeach; ?>
          <?php if (empty($examQuestions)): ?>
          <tr><td colspan="6" style="text-align:center;color:var(--muted);padding:24px;">Sin preguntas todavía.</td></tr>
          <?php endif; ?>
          </tbody>
        </table>
      </div>
      <?php endif; ?>
    </div>

    <!-- TAB: Links -->
    <div id="tab-links" class="tab-panel">
      <div class="card">
        <h3>Link de grupo</h3>
        <form method="POST">
          <input type="hidden" name="action" value="generate_group_link">
          <div class="form-row">
            <div class="form-group">
              <label>Examen</label>
              <select name="exam_id" required>
                <option value="">Seleccionar examen</option>
                <?php foreach ($exams as $ex): ?>
                <option value="<?= $ex['id'] ?>" <?= $ex['id'] == $currentExamId ? 'selected' : '' ?>><?= h($ex['title']) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="form-group">
              <label>Fecha disponible</label>
              <input type="date" name="available_date" id="avail-date"
                     min="<?= date('Y-m-d') ?>"
                     value="<?= date('Y-m-d') ?>"
                     onchange="updateExpiryPreview()">
            </div>
            <div class="form-group">
              <label>Duración (horas)</label>
              <input type="number" name="duration_hours" id="avail-hours"
                     min="1" max="720" value="24"
                     onchange="updateExpiryPreview()">
              <small id="expiry-preview" style="color:var(--muted);font-size:11px;margin-top:4px;display:block;">
                Expira: <?= date('d/m/Y') ?> a las 23:59
              </small>
            </div>
            <div class="form-group">
              <label>Usos máximos</label>
              <input type="number" name="max_uses" value="999" min="1">
            </div>
          </div>
          <button type="submit" class="btn btn-primary">Generar link de grupo</button>
        </form>
      </div>

      <div class="card">
        <h3>Link individual</h3>
        <form method="POST">
          <input type="hidden" name="action" value="generate_individual_link">
          <div class="form-row">
            <div class="form-group">
              <label>Examen</label>
              <select name="exam_id" required>
                <option value="">Seleccionar examen</option>
                <?php foreach ($exams as $ex): ?>
                <option value="<?= $ex['id'] ?>" <?= $ex['id'] == $currentExamId ? 'selected' : '' ?>><?= h($ex['title']) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="form-group">
              <label>Nombre del estudiante</label>
              <input type="text" name="student_name">
            </div>
          </div>
          <div class="form-row-3">
            <div class="form-group">
              <label>Documento</label>
              <input type="text" name="student_doc">
            </div>
            <div class="form-group">
              <label>WhatsApp</label>
              <input type="text" name="student_phone">
            </div>
            <div class="form-group">
              <label>Correo</label>
              <input type="email" name="student_email">
            </div>
          </div>
          <div class="form-group">
            <label>Programa</label>
            <input type="text" name="student_program">
          </div>
          <button type="submit" class="btn btn-primary">Generar link individual</button>
        </form>
      </div>

      <?php if (!empty($examLinks)): ?>
      <div class="card">
        <div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:8px;margin-bottom:4px;">
          <div><h3>Links activos — <?= h($currentExam['title'] ?? '') ?></h3>
          <?php if (!empty($currentExam['unit_name'])): ?><div class="card-sub-text"><?= h($currentExam['unit_name']) ?></div><?php endif; ?></div>
          <?php
            $_mods = json_decode($currentExam['modalities'] ?? '[]', true) ?: [];
            if ($currentExamId && in_array('printed', $_mods)):
          ?>
          <div style="display:flex;gap:7px;">
            <a class="btn btn-secondary btn-sm"
               href="quiz_print.php?exam_id=<?= $currentExamId ?>&mode=student"
               target="_blank" rel="noopener noreferrer"
               title="Abrir versión imprimible para estudiante"
               style="background:#F97316;color:#fff;border-color:#F97316;">&#128196; Quiz imprimible</a>
            <a class="btn btn-secondary btn-sm"
               href="quiz_print.php?exam_id=<?= $currentExamId ?>&mode=key"
               target="_blank" rel="noopener noreferrer"
               title="Abrir clave de respuestas"
               style="background:#7F77DD;color:#fff;border-color:#7F77DD;">&#128273; Clave</a>
          </div>
          <?php endif; ?>
        </div>
        <table>
          <thead><tr><th>Token</th><th>Tipo</th><th>Estudiante</th><th>Expira</th><th>Usos</th><th>Compartir</th></tr></thead>
          <tbody>
          <?php foreach ($examLinks as $lnk): ?>
          <?php
            $linkUrl   = $baseUrl . $lnk['token'];
            $examTitle = h($currentExam['title'] ?? 'Examen');
            $waMsg     = urlencode('Realiza tu examen "' . ($currentExam['title'] ?? 'Examen') . '" aquí: ' . $linkUrl);
            $emailSubj = urlencode('Examen: ' . ($currentExam['title'] ?? 'Examen'));
            $emailBody = urlencode("Hola,\n\nTe invitamos a realizar el examen \"" . ($currentExam['title'] ?? 'Examen') . "\".\n\nAccede desde el siguiente enlace:\n" . $linkUrl . "\n\nSaludos.");
          ?>
          <tr>
            <td><code><?= h(substr($lnk['token'], 0, 8)) ?>…</code></td>
            <td><span class="badge <?= $lnk['link_type'] === 'group' ? 'badge-active' : 'badge-online' ?>"><?= h($lnk['link_type']) ?></span></td>
            <td><?= h($lnk['student_name'] ?? '-') ?><?php if ($lnk['student_email']): ?><br><small style="color:var(--muted)"><?= h($lnk['student_email']) ?></small><?php endif; ?></td>
            <td>
              <?php if ($lnk['expires_at']): ?>
                <?php
                  $expTs  = strtotime($lnk['expires_at']);
                  $today  = strtotime(date('Y-m-d'));
                  $expDay = strtotime(date('Y-m-d', $expTs));
                  $diff   = (int)(($expTs - time()) / 3600);
                  $label  = date('d/m/Y', $expTs);
                  $style  = $expTs < time() ? 'color:#dc3545;font-weight:700;' : ($diff < 24 ? 'color:#F97316;font-weight:700;' : '');
                ?>
                <span style="<?= $style ?>"><?= $label ?></span>
                <?php if ($expTs < time()): ?>
                  <span style="font-size:10px;background:#dc3545;color:#fff;border-radius:4px;padding:1px 5px;margin-left:3px;">expirado</span>
                <?php elseif ($diff < 24): ?>
                  <span style="font-size:10px;background:#F97316;color:#fff;border-radius:4px;padding:1px 5px;margin-left:3px;">hoy</span>
                <?php endif; ?>
              <?php else: ?>
                <span style="color:var(--muted);">∞ sin límite</span>
              <?php endif; ?>
            </td>
            <td><?= (int)$lnk['submissions'] ?> / <?= (int)$lnk['max_uses'] ?></td>
            <td style="white-space:nowrap;display:flex;flex-wrap:wrap;gap:4px;align-items:center;">
              <button class="btn btn-secondary btn-sm" onclick="copyLink('<?= h($linkUrl) ?>')">Copiar</button>
              <?php
                $waPhone = $lnk['student_phone'] ? preg_replace('/\D/','', $lnk['student_phone']) : '';
                $waHref  = $waPhone
                    ? 'https://wa.me/' . $waPhone . '?text=' . $waMsg
                    : 'https://api.whatsapp.com/send?text=' . $waMsg;
              ?>
              <a class="btn btn-primary btn-sm" href="<?= h($waHref) ?>" target="_blank" title="Enviar por WhatsApp">📱 WA</a>
              <a class="btn btn-secondary btn-sm" href="mailto:<?= h($lnk['student_email'] ?? '') ?>?subject=<?= $emailSubj ?>&body=<?= $emailBody ?>" title="Enviar por correo">✉️ Email</a>
              <button class="btn btn-secondary btn-sm" style="background:#F97316;color:#fff;border-color:#F97316;"
                      onclick="openEditLink(<?= $lnk['id'] ?>, '<?= $currentExamId ?>', '<?= h($lnk['expires_at'] ?? '') ?>', <?= (int)$lnk['max_uses'] ?>)"
                      title="Editar fecha/hora y usos">✏️ Editar</button>
              <form method="POST" style="display:inline"
                    onsubmit="return confirm('¿Eliminar este link? Los resultados ya registrados se conservan.')">
                <input type="hidden" name="action"   value="delete_link">
                <input type="hidden" name="link_id"  value="<?= $lnk['id'] ?>">
                <input type="hidden" name="exam_id"  value="<?= $currentExamId ?>">
                <button type="submit" class="btn btn-danger btn-sm" title="Eliminar link">🗑</button>
              </form>
            </td>
          </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <?php endif; ?>
    </div>

    <!-- TAB: Resultados -->
    <div id="tab-results" class="tab-panel">
      <div class="card">
        <div class="card-head">
          <h3>Resultados<?= $currentExam ? ' — ' . h($currentExam['title']) : '' ?></h3>
          <div style="display:flex;gap:8px;flex-wrap:wrap;">
            <button class="btn btn-primary" onclick="openPrintedModal()">+ Nota impresa</button>
          </div>
        </div>
        <?php if (!$currentExamId): ?>
        <p style="color:var(--muted);">Selecciona un examen desde la lista para ver resultados.</p>
        <?php else: ?>
        <table>
          <thead>
            <tr><th>Nombre</th><th>Doc</th><th>Modalidad</th><th>Fecha</th><th>Puntaje</th><th>%</th><th>MCER</th><th>Status</th></tr>
          </thead>
          <tbody>
          <?php foreach ($examResults as $r): ?>
          <?php $cc2 = $cefrColors[$r['cefr_suggested'] ?? ''] ?? '#6c757d'; ?>
          <tr>
            <td><?= h($r['student_name'] ?? '-') ?></td>
            <td><?= h($r['student_doc'] ?? '-') ?></td>
            <td><span class="badge badge-<?= $r['modality'] === 'printed' ? 'printed' : 'online' ?>"><?= h($r['modality']) ?></span></td>
            <td><?= $r['submitted_at'] ? h(date('d/m/Y H:i', strtotime($r['submitted_at']))) : '<em>Pendiente</em>' ?></td>
            <td><?= $r['score'] !== null ? number_format((float)$r['score'], 1) . '/' . number_format((float)$r['max_score'], 1) : '-' ?></td>
            <td><?= $r['pct'] !== null ? number_format((float)$r['pct'], 1) . '%' : '-' ?></td>
            <td><?php if ($r['cefr_suggested']): ?><span class="cefr-badge" style="background:<?= $cc2 ?>"><?= h($r['cefr_suggested']) ?></span><?php endif; ?></td>
            <td><span class="badge badge-<?= $r['status'] === 'submitted' ? 'active' : 'draft' ?>"><?= h($r['status']) ?></span></td>
          </tr>
          <?php endforeach; ?>
          <?php if (empty($examResults)): ?>
          <tr><td colspan="8" style="text-align:center;color:var(--muted);padding:24px;">Sin resultados todavía.</td></tr>
          <?php endif; ?>
          </tbody>
        </table>
        <?php endif; ?>
      </div>
    </div>

    <!-- TAB: Rangos MCER -->
    <div id="tab-cefr" class="tab-panel">
      <div class="card">
        <h3>Rangos MCER globales</h3>
        <form method="POST">
          <input type="hidden" name="action" value="save_cefr_ranges">
          <input type="hidden" name="exam_id" value="0">
          <table>
            <thead><tr><th>Nivel</th><th>Etiqueta</th><th>% Mínimo</th><th>% Máximo</th></tr></thead>
            <tbody>
            <?php
            $globalMap = [];
            foreach ($globalRanges as $gr) $globalMap[$gr['cefr_level']] = $gr;
            foreach (['A1','A2','B1','B2','C1'] as $lvl):
            $gr = $globalMap[$lvl] ?? ['label'=>'','min_pct'=>0,'max_pct'=>100];
            ?>
            <tr>
              <td><input type="hidden" name="cefr_level[]" value="<?= $lvl ?>"><span class="cefr-badge" style="background:<?= $cefrColors[$lvl] ?>"><?= $lvl ?></span></td>
              <td><input type="text" name="label[]" value="<?= h($gr['label']) ?>" style="width:140px;padding:6px 10px;border:1px solid var(--line);border-radius:8px;"></td>
              <td><input type="number" name="min_pct[]" value="<?= h($gr['min_pct']) ?>" step="0.01" style="width:90px;padding:6px 10px;border:1px solid var(--line);border-radius:8px;"></td>
              <td><input type="number" name="max_pct[]" value="<?= h($gr['max_pct']) ?>" step="0.01" style="width:90px;padding:6px 10px;border:1px solid var(--line);border-radius:8px;"></td>
            </tr>
            <?php endforeach; ?>
            </tbody>
          </table>
          <br>
          <button type="submit" class="btn btn-primary">Guardar rangos</button>
        </form>
      </div>

      <?php if ($currentExamId > 0): ?>
      <div class="card">
        <h3>Rangos específicos — <?= h($currentExam['title'] ?? '') ?></h3>
        <form method="POST">
          <input type="hidden" name="action" value="save_cefr_ranges">
          <input type="hidden" name="exam_id" value="<?= $currentExamId ?>">
          <?php
          $examRangeMap = [];
          foreach ($cefrRanges as $er) $examRangeMap[$er['cefr_level']] = $er;
          ?>
          <table>
            <thead><tr><th>Nivel</th><th>Etiqueta</th><th>% Mínimo</th><th>% Máximo</th></tr></thead>
            <tbody>
            <?php foreach (['A1','A2','B1','B2','C1'] as $lvl):
            $er = $examRangeMap[$lvl] ?? ['label'=>'','min_pct'=>0,'max_pct'=>100];
            ?>
            <tr>
              <td><input type="hidden" name="cefr_level[]" value="<?= $lvl ?>"><span class="cefr-badge" style="background:<?= $cefrColors[$lvl] ?>"><?= $lvl ?></span></td>
              <td><input type="text" name="label[]" value="<?= h($er['label']) ?>" style="width:140px;padding:6px 10px;border:1px solid var(--line);border-radius:8px;"></td>
              <td><input type="number" name="min_pct[]" value="<?= h($er['min_pct']) ?>" step="0.01" style="width:90px;padding:6px 10px;border:1px solid var(--line);border-radius:8px;"></td>
              <td><input type="number" name="max_pct[]" value="<?= h($er['max_pct']) ?>" step="0.01" style="width:90px;padding:6px 10px;border:1px solid var(--line);border-radius:8px;"></td>
            </tr>
            <?php endforeach; ?>
            </tbody>
          </table>
          <br>
          <button type="submit" class="btn btn-primary">Guardar rangos del examen</button>
        </form>
      </div>
      <?php endif; ?>
    </div>

    </div><!-- /eval-scroll -->
</div><!-- /eval-shell -->

<!-- Modal: Agregar/Editar pregunta -->
<div class="modal-bg" id="question-modal">
  <div class="modal">
    <h3 id="q-modal-title">Agregar pregunta</h3>
    <form method="POST" id="q-form">
      <input type="hidden" name="action" value="save_question">
      <input type="hidden" name="exam_id" value="<?= $currentExamId ?>">
      <input type="hidden" name="question_id" id="q-id" value="0">
      <div class="form-row">
        <div class="form-group">
          <label>Tipo de actividad</label>
          <select name="type" id="q-type">
            <?php foreach ($activityTypes as $at): ?>
            <option value="<?= h($at) ?>"><?= h($at) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="form-group">
          <label>Skill</label>
          <select name="skill" id="q-skill">
            <?php foreach ($skillLabels as $sv => $sl): ?>
            <option value="<?= h($sv) ?>"><?= h($sl) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
      </div>
      <div class="form-group">
        <label>Texto de la pregunta</label>
        <textarea name="question_text" id="q-text" required></textarea>
      </div>
      <div class="form-row">
        <div class="form-group">
          <label>URL de audio (opcional)</label>
          <input type="url" name="audio_url" id="q-audio">
        </div>
        <div class="form-group">
          <label>URL de imagen (opcional)</label>
          <input type="url" name="image_url" id="q-image">
        </div>
      </div>
      <div class="form-row">
        <div class="form-group">
          <label>Puntos</label>
          <input type="number" name="points" id="q-points" value="1" step="0.5" min="0.5">
        </div>
        <div class="form-group">
          <label>Posición</label>
          <input type="number" name="position" id="q-pos" value="0" min="0">
        </div>
      </div>
      <!-- Respuestas -->
      <div class="form-group">
        <label>Respuestas (marca ✓ la correcta)</label>
        <div id="answers-container"></div>
        <button type="button" class="btn btn-secondary btn-sm" onclick="addAnswerRow()" style="margin-top:8px;">+ Respuesta</button>
      </div>
      <div style="display:flex;gap:10px;margin-top:4px;">
        <button type="submit" class="btn btn-primary">Guardar</button>
        <button type="button" class="btn btn-secondary" onclick="closeQuestionModal()">Cancelar</button>
      </div>
    </form>
  </div>
</div>

<!-- Modal: Nota impresa -->
<div class="modal-bg" id="printed-modal">
  <div class="modal">
    <h3>Registrar nota impresa</h3>
    <form method="POST">
      <input type="hidden" name="action" value="register_printed">
      <input type="hidden" name="exam_id" value="<?= $currentExamId ?>">
      <div class="form-group"><label>Nombre del estudiante</label><input type="text" name="student_name" required></div>
      <div class="form-group"><label>Documento</label><input type="text" name="student_doc"></div>
      <div class="form-row">
        <div class="form-group"><label>Puntaje obtenido</label><input type="number" name="score" step="0.01" min="0" required></div>
        <div class="form-group"><label>Puntaje máximo</label><input type="number" name="max_score" step="0.01" min="0.01" value="100" required></div>
      </div>
      <div style="display:flex;gap:10px;">
        <button type="submit" class="btn btn-primary">Registrar</button>
        <button type="button" class="btn btn-secondary" onclick="document.getElementById('printed-modal').classList.remove('open')">Cancelar</button>
      </div>
    </form>
  </div>
</div>

<!-- ── Modal: editar link ── -->
<div class="modal-bg" id="edit-link-modal">
  <div class="modal" style="max-width:420px;">
    <h3 style="margin-bottom:16px;">✏️ Editar link</h3>
    <form method="POST" id="edit-link-form">
      <input type="hidden" name="action"  value="edit_link">
      <input type="hidden" name="link_id" id="el-link-id">
      <input type="hidden" name="exam_id" id="el-exam-id">
      <div class="form-group">
        <label>Fecha disponible</label>
        <input type="date" name="available_date" id="el-avail-date"
               style="width:100%;padding:8px 10px;border:1.5px solid #dee2e6;border-radius:8px;font-size:14px;"
               onchange="updateEditPreview()">
      </div>
      <div class="form-group">
        <label>Duración (horas)</label>
        <input type="number" name="duration_hours" id="el-duration-hrs"
               min="1" max="720" value="24"
               style="width:100%;padding:8px 10px;border:1.5px solid #dee2e6;border-radius:8px;font-size:14px;"
               onchange="updateEditPreview()">
        <small id="el-expiry-preview" style="color:var(--muted);font-size:11px;margin-top:4px;display:block;">
          El link expira automáticamente después de las horas indicadas.
        </small>
      </div>
      <div class="form-group">
        <label>Usos máximos</label>
        <input type="number" name="max_uses" id="el-max-uses" min="1" max="9999"
               style="width:100%;padding:8px 10px;border:1.5px solid #dee2e6;border-radius:8px;font-size:14px;">
        <small style="color:var(--muted);font-size:11px;margin-top:4px;display:block;">
          Número de estudiantes que pueden usar este link.
        </small>
      </div>
      <div style="display:flex;gap:10px;margin-top:18px;">
        <button type="submit" class="btn btn-primary" style="flex:1;">💾 Guardar cambios</button>
        <button type="button" class="btn btn-secondary"
                onclick="document.getElementById('edit-link-modal').classList.remove('open')">Cancelar</button>
      </div>
    </form>
  </div>
</div>

<script>
const INITIAL_TAB = <?= json_encode($tab) ?>;

function showTab(name) {
  document.querySelectorAll('.tab-panel').forEach(p => p.classList.remove('active'));
  const panel = document.getElementById('tab-' + name);
  if (panel) panel.classList.add('active');
  document.querySelectorAll('.es-item').forEach(l => l.classList.remove('active'));
  const active = document.querySelector('.es-item[onclick*="\'' + name + '\'"]');
  if (active) active.classList.add('active');
}

function filterExams() {
  const search = document.getElementById('search-exam').value.toLowerCase();
  const level  = document.getElementById('filter-level').value;
  document.querySelectorAll('#exam-table tbody tr').forEach(tr => {
    const title = tr.dataset.title || '';
    const lvl   = tr.dataset.level || '';
    const show  = (!search || title.includes(search)) && (!level || lvl === level);
    tr.style.display = show ? '' : 'none';
  });
}

function openQuestionModal(id, data) {
  document.getElementById('q-id').value = id || 0;
  document.getElementById('q-modal-title').textContent = id ? 'Editar pregunta' : 'Agregar pregunta';
  document.getElementById('q-text').value = data ? (data.question_text || '') : '';
  document.getElementById('q-audio').value = data ? (data.audio_url || '') : '';
  document.getElementById('q-image').value = data ? (data.image_url || '') : '';
  document.getElementById('q-points').value = data ? (data.points || 1) : 1;
  document.getElementById('q-pos').value = data ? (data.position || 0) : 0;
  if (data && data.type) {
    const sel = document.getElementById('q-type');
    for (let i = 0; i < sel.options.length; i++) {
      if (sel.options[i].value === data.type) { sel.selectedIndex = i; break; }
    }
  }
  if (data && data.skill) {
    const sel = document.getElementById('q-skill');
    for (let i = 0; i < sel.options.length; i++) {
      if (sel.options[i].value === data.skill) { sel.selectedIndex = i; break; }
    }
  }
  // Cargar respuestas
  const container = document.getElementById('answers-container');
  container.innerHTML = '';
  const texts = data && data.answer_texts ? data.answer_texts : [];
  const corrects = data && data.answer_corrects ? data.answer_corrects : [];
  if (Array.isArray(texts) && texts.length) {
    texts.forEach((t, i) => addAnswerRow(t, corrects[i] === true || corrects[i] === 't'));
  } else {
    addAnswerRow(); addAnswerRow();
  }
  document.getElementById('question-modal').classList.add('open');
}

function closeQuestionModal() {
  document.getElementById('question-modal').classList.remove('open');
}

function addAnswerRow(text, correct) {
  const container = document.getElementById('answers-container');
  const div = document.createElement('div');
  div.style.cssText = 'display:flex;gap:8px;margin-bottom:6px;align-items:center;';
  div.innerHTML = `
    <input type="text" name="answer_text[]" value="${text ? escHtml(text) : ''}"
      style="flex:1;padding:7px 10px;border:1px solid #d8e8dc;border-radius:8px;">
    <label style="display:flex;align-items:center;gap:4px;font-size:13px;cursor:pointer;white-space:nowrap;">
      <input type="checkbox" name="answer_correct[]" value="1" ${correct ? 'checked' : ''}> Correcta
    </label>
    <button type="button" onclick="this.parentElement.remove()" style="background:#e55353;color:#fff;border:none;border-radius:8px;padding:5px 10px;cursor:pointer;">✕</button>
  `;
  container.appendChild(div);
}

function escHtml(s) {
  return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}

function copyLink(url) {
  navigator.clipboard.writeText(url).then(() => alert('¡Link copiado!')).catch(() => {
    prompt('Copia este link:', url);
  });
}

function pad2(n){ return String(n).padStart(2,'0'); }

function updateExpiryPreview() {
  var d = document.getElementById('avail-date');
  var h = document.getElementById('avail-hours');
  var p = document.getElementById('expiry-preview');
  if (!d || !h || !p) return;
  var date = d.value, hrs = parseInt(h.value) || 24;
  if (!date) { p.textContent = ''; return; }
  var start = new Date(date + 'T00:00:00');
  var end   = new Date(start.getTime() + hrs * 3600 * 1000);
  p.textContent = 'Expira: ' + pad2(end.getDate()) + '/' + pad2(end.getMonth()+1) + '/' + end.getFullYear()
                + ' a las ' + pad2(end.getHours()) + ':' + pad2(end.getMinutes());
}

function updateEditPreview() {
  var d = document.getElementById('el-avail-date');
  var h = document.getElementById('el-duration-hrs');
  var p = document.getElementById('el-expiry-preview');
  if (!d || !h || !p) return;
  var date = d.value, hrs = parseInt(h.value) || 24;
  if (!date) { p.textContent = 'Sin fecha de expiración.'; return; }
  var start = new Date(date + 'T00:00:00');
  var end   = new Date(start.getTime() + hrs * 3600 * 1000);
  p.textContent = 'Expira: ' + pad2(end.getDate()) + '/' + pad2(end.getMonth()+1) + '/' + end.getFullYear()
                + ' a las ' + pad2(end.getHours()) + ':' + pad2(end.getMinutes());
}

function openEditLink(linkId, examId, expiresAt, maxUses) {
  document.getElementById('el-link-id').value  = linkId;
  document.getElementById('el-exam-id').value  = examId;
  document.getElementById('el-max-uses').value = maxUses;

  // Pre-fill date and compute duration from existing expires_at
  var dateEl = document.getElementById('el-avail-date');
  var hrsEl  = document.getElementById('el-duration-hrs');
  if (expiresAt && expiresAt !== '') {
    var exp = new Date(expiresAt.replace(' ','T'));
    if (!isNaN(exp.getTime())) {
      // Set date to expiry date
      dateEl.value = exp.getFullYear() + '-' + pad2(exp.getMonth()+1) + '-' + pad2(exp.getDate());
      // Compute hours from midnight of that day
      var midnight = new Date(exp.getFullYear(), exp.getMonth(), exp.getDate(), 0, 0, 0);
      var hrs = Math.round((exp - midnight) / 3600000);
      hrsEl.value = hrs > 0 ? hrs : 24;
    } else {
      dateEl.value = new Date().toISOString().slice(0,10);
      hrsEl.value  = 24;
    }
  } else {
    dateEl.value = new Date().toISOString().slice(0,10);
    hrsEl.value  = 24;
  }
  updateEditPreview();
  document.getElementById('edit-link-modal').classList.add('open');
}

function openPrintedModal() {
  document.getElementById('printed-modal').classList.add('open');
}

// Cerrar modales al click fuera
document.querySelectorAll('.modal-bg').forEach(bg => {
  bg.addEventListener('click', e => { if (e.target === bg) bg.classList.remove('open'); });
});

// Iniciar en el tab correcto
showTab(INITIAL_TAB);
</script>
</body>
</html>




