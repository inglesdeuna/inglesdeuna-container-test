<?php
/**
 * placement/index.php — Admin Placement Test Management Panel
 * Requires admin session. Manages A2, B1, B2 placement exams.
 */
session_start();

if (!isset($_SESSION['admin_logged']) || $_SESSION['admin_logged'] !== true) {
    header('Location: /lessons/lessons/admin/login.php');
    exit;
}

require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../config/init_db.php';

function h(string $v): string { return htmlspecialchars($v, ENT_QUOTES, 'UTF-8'); }

// ─── Seed Function ────────────────────────────────────────────────────────────
function seed_placement_exams(PDO $pdo): void
{
    // Ensure is_placement column exists (idempotent)
    try {
        $pdo->exec("ALTER TABLE eval_exams ADD COLUMN IF NOT EXISTS is_placement BOOLEAN DEFAULT FALSE");
    } catch (Throwable $e) { /* already exists */ }

    $exams = [
        'A2' => [
            'title'        => 'Placement Test — Nivel A2',
            'time_limit'   => 45,
            'cefr_level'   => 'A2',
            'questions'    => _get_a2_questions(),
        ],
        'B1' => [
            'title'        => 'Placement Test — Nivel B1',
            'time_limit'   => 45,
            'cefr_level'   => 'B1',
            'questions'    => _get_b1_questions(),
        ],
        'B2' => [
            'title'        => 'Placement Test — Nivel B2',
            'time_limit'   => 45,
            'cefr_level'   => 'B2',
            'questions'    => _get_b2_questions(),
        ],
    ];

    foreach ($exams as $level => $data) {
        // Idempotency check
        $check = $pdo->prepare(
            "SELECT id FROM eval_exams WHERE is_placement=TRUE AND cefr_level=? LIMIT 1"
        );
        $check->execute([$data['cefr_level']]);
        $existing = $check->fetchColumn();
        if ($existing) continue;

        // Insert exam
        $ins = $pdo->prepare(
            "INSERT INTO eval_exams
                (title, cefr_level, time_limit_min, max_attempts, status, modalities,
                 instructions, is_placement, created_by)
             VALUES (?,?,?,1,'active','[\"online\"]',?,TRUE,'system') RETURNING id"
        );
        $ins->execute([
            $data['title'],
            $data['cefr_level'],
            $data['time_limit'],
            'This is an English placement test. Answer all 20 questions. You have 45 minutes.',
        ]);
        $examId = (int) $ins->fetchColumn();

        // Insert questions
        $pos = 0;
        foreach ($data['questions'] as $q) {
            $qIns = $pdo->prepare(
                "INSERT INTO eval_questions
                    (exam_id, type, skill, question_text, points, position)
                 VALUES (?,'multiple_choice',?,?,1,?) RETURNING id"
            );
            $qIns->execute([$examId, $q['skill'], $q['text'], $pos++]);
            $qId = (int) $qIns->fetchColumn();

            // Insert answers
            $aIns = $pdo->prepare(
                "INSERT INTO eval_answers (question_id, answer_text, is_correct, order_index)
                 VALUES (?,?,?,?)"
            );
            foreach ($q['options'] as $idx => $opt) {
                $isCorrect = ($opt === $q['correct']);
                $aIns->execute([$qId, $opt, $isCorrect ? 'true' : 'false', $idx]);
            }
        }

        // Seed CEFR ranges for this placement exam
        _seed_cefr_ranges($pdo, $examId, $level);
    }
}

function _seed_cefr_ranges(PDO $pdo, int $examId, string $targetLevel): void
{
    $ranges = [
        'A2' => [
            ['level'=>'A1','label'=>'Needs Foundation','min'=>0,'max'=>34],
            ['level'=>'A2','label'=>'Ready for A2','min'=>35,'max'=>65],
            ['level'=>'B1','label'=>'Above A2 Level','min'=>66,'max'=>100],
        ],
        'B1' => [
            ['level'=>'A2','label'=>'Needs A2 First','min'=>0,'max'=>34],
            ['level'=>'B1','label'=>'Ready for B1','min'=>35,'max'=>65],
            ['level'=>'B2','label'=>'Above B1 Level','min'=>66,'max'=>100],
        ],
        'B2' => [
            ['level'=>'B1','label'=>'Needs B1 First','min'=>0,'max'=>34],
            ['level'=>'B2','label'=>'Ready for B2','min'=>35,'max'=>65],
            ['level'=>'C1','label'=>'Above B2 Level','min'=>66,'max'=>100],
        ],
    ];

    foreach (($ranges[$targetLevel] ?? []) as $r) {
        $exist = $pdo->prepare(
            "SELECT id FROM eval_cefr_ranges WHERE exam_id=? AND cefr_level=? LIMIT 1"
        );
        $exist->execute([$examId, $r['level']]);
        if ($exist->fetchColumn()) continue;
        $pdo->prepare(
            "INSERT INTO eval_cefr_ranges (exam_id, cefr_level, label, min_pct, max_pct, is_global)
             VALUES (?,?,?,?,?,FALSE)"
        )->execute([$examId, $r['level'], $r['label'], $r['min'], $r['max']]);
    }
}

// ─── Question Banks ───────────────────────────────────────────────────────────
function _get_a2_questions(): array {
    return [
        // Grammar (8)
        ['skill'=>'grammar','text'=>'She ___ to school every day.',
         'options'=>['go','goes','going','went'],'correct'=>'goes'],
        ['skill'=>'grammar','text'=>'We ___ a great film last Saturday night.',
         'options'=>['watch','watches','watched','are watching'],'correct'=>'watched'],
        ['skill'=>'grammar','text'=>'Look! The children ___ in the garden.',
         'options'=>['play','played','is playing','are playing'],'correct'=>'are playing'],
        ['skill'=>'grammar','text'=>'___ do you live? — In London.',
         'options'=>['What','When','Where','Why'],'correct'=>'Where'],
        ['skill'=>'grammar','text'=>'An elephant is ___ than a cat.',
         'options'=>['bigger','more bigger','biggest','big'],'correct'=>'bigger'],
        ['skill'=>'grammar','text'=>'You ___ stop at a red light. It is the law.',
         'options'=>['can','might','must','should'],'correct'=>'must'],
        ['skill'=>'grammar','text'=>'I am reading ___ interesting book right now.',
         'options'=>['a','an','the','---'],'correct'=>'an'],
        ['skill'=>'grammar','text'=>'My sister\'s birthday is ___ April.',
         'options'=>['in','on','at','for'],'correct'=>'in'],
        // Vocabulary (6)
        ['skill'=>'vocabulary','text'=>'A person who designs buildings is an ___.',
         'options'=>['artist','architect','engineer','actor'],'correct'=>'architect'],
        ['skill'=>'vocabulary','text'=>'Which of these is a cold drink?',
         'options'=>['lemonade','bread','pasta','salad'],'correct'=>'lemonade'],
        ['skill'=>'vocabulary','text'=>'Your mother\'s mother is your ___.',
         'options'=>['aunt','cousin','grandmother','niece'],'correct'=>'grandmother'],
        ['skill'=>'vocabulary','text'=>'If it is 2:30, it is half ___ two.',
         'options'=>['after','to','past','over'],'correct'=>'past'],
        ['skill'=>'vocabulary','text'=>'Traffic lights use red, orange, and ___.',
         'options'=>['pink','brown','purple','green'],'correct'=>'green'],
        ['skill'=>'vocabulary','text'=>'I need to ___ the bus to get to school.',
         'options'=>['drive','ride','take','go'],'correct'=>'take'],
        // Reading (6)
        ['skill'=>'reading','text'=>'[SIGN] "SALE — 50% OFF ALL ITEMS — This weekend only." — When can you buy things cheaply?',
         'options'=>['Every day','Only this weekend','Only on weekdays','Any time of the year'],'correct'=>'Only this weekend'],
        ['skill'=>'reading','text'=>'"Hi María, I can\'t come to class tomorrow. I have a bad cold. Can you send me the homework? — Rosa" — Why can\'t Rosa come to class?',
         'options'=>['She forgot','She is on holiday','She is sick','She has homework already'],'correct'=>'She is sick'],
        ['skill'=>'reading','text'=>'[LABEL] "KEEP OUT OF REACH OF CHILDREN. Contains chemicals. Wear gloves when using." — Who should NOT use this without care?',
         'options'=>['Adults','Doctors','Teachers','Children'],'correct'=>'Children'],
        ['skill'=>'reading','text'=>'"Bus 42 will NOT stop at City Hall on Saturday due to road works." — On Saturday, where will bus 42 NOT stop?',
         'options'=>['The hospital','The school','City Hall','The airport'],'correct'=>'City Hall'],
        ['skill'=>'reading','text'=>'"Museum opening hours: Mon–Fri 9am–6pm. Sat 10am–4pm. CLOSED Sundays." — What time does the museum close on Saturday?',
         'options'=>['9am','6pm','10am','4pm'],'correct'=>'4pm'],
        ['skill'=>'reading','text'=>'"New York is a very big city. More than 8 million people live there. It is famous for its tall buildings, yellow taxis, and Central Park." — What does the text say about New York?',
         'options'=>['It is the capital of the USA','Only 1 million people live there','It is famous for its taxis and parks','It has very old buildings'],'correct'=>'It is famous for its taxis and parks'],
    ];
}

function _get_b1_questions(): array {
    return [
        // Grammar (8)
        ['skill'=>'grammar','text'=>'I ___ to Japan three times. I love it there.',
         'options'=>['went','go','had been','have been'],'correct'=>'have been'],
        ['skill'=>'grammar','text'=>'She ___ a shower when the phone rang.',
         'options'=>['had','has had','is having','was having'],'correct'=>'was having'],
        ['skill'=>'grammar','text'=>'If you study hard, you ___ pass the exam.',
         'options'=>['would','will','should','might'],'correct'=>'will'],
        ['skill'=>'grammar','text'=>'If I ___ more free time, I would learn to play the guitar.',
         'options'=>['have','would have','am having','had'],'correct'=>'had'],
        ['skill'=>'grammar','text'=>'The Eiffel Tower ___ in 1889.',
         'options'=>['built','has been built','is building','was built'],'correct'=>'was built'],
        ['skill'=>'grammar','text'=>'He said he ___ very tired after the long journey.',
         'options'=>['is','will be','has been','was'],'correct'=>'was'],
        ['skill'=>'grammar','text'=>'My father ___ cycle to work, but now he drives a car.',
         'options'=>['is used to','was used to','gets used to','used to'],'correct'=>'used to'],
        ['skill'=>'grammar','text'=>'The book ___ you recommended last week was excellent.',
         'options'=>['who','whose','where','that'],'correct'=>'that'],
        // Vocabulary (6)
        ['skill'=>'vocabulary','text'=>'Before an interview, you should send your ___ to the employer.',
         'options'=>['receipt','CV','contract','invoice'],'correct'=>'CV'],
        ['skill'=>'vocabulary','text'=>'You need to ___ online 24 hours before your flight.',
         'options'=>['check out','check in','book','cancel'],'correct'=>'check in'],
        ['skill'=>'vocabulary','text'=>'After the examination, the doctor gave me a ___ for antibiotics.',
         'options'=>['recipe','bill','prescription','certificate'],'correct'=>'prescription'],
        ['skill'=>'vocabulary','text'=>'Plants and animals at risk of dying out are called ___ species.',
         'options'=>['domestic','poisonous','wild','endangered'],'correct'=>'endangered'],
        ['skill'=>'vocabulary','text'=>'She shared the document with colleagues using ___ storage.',
         'options'=>['satellite','solar','cloud','manual'],'correct'=>'cloud'],
        ['skill'=>'vocabulary','text'=>'He was ___ when he found out he had won first prize.',
         'options'=>['devastated','furious','embarrassed','thrilled'],'correct'=>'thrilled'],
        // Reading (6)
        ['skill'=>'reading','text'=>'"Research suggests that reading fiction helps people develop empathy. By imagining characters\' lives, readers practice understanding different perspectives." — What does this text say fiction helps develop?',
         'options'=>['Memory','Writing skills','Empathy','Vocabulary'],'correct'=>'Empathy'],
        ['skill'=>'reading','text'=>'"WANTED: Part-time receptionist. Hours: Mon–Wed, 9am–1pm. Requirements: Good phone manner, experience with computers." — How many days a week is the job?',
         'options'=>['Two','Four','Five','Three'],'correct'=>'Three'],
        ['skill'=>'reading','text'=>'"Q: Can I get a refund? A: Yes, provided you return the item within 14 days and it is in its original packaging." — What must be done to get a refund?',
         'options'=>['Call first','The item must be used','Return within 14 days in original packaging','Fill in a form'],'correct'=>'Return within 14 days in original packaging'],
        ['skill'=>'reading','text'=>'"Amsterdam has over 800,000 bicycles — more than its human population. Cycling is the most popular form of transport." — What is surprising about Amsterdam according to the text?',
         'options'=>['It has no cars','Everyone walks to work','There are more bikes than people','The city is very small'],'correct'=>'There are more bikes than people'],
        ['skill'=>'reading','text'=>'"Orders placed before 2pm are dispatched the same day. Orders placed after 2pm or on weekends will be sent the next working day." — If you order on Saturday afternoon, when will it be dispatched?',
         'options'=>['Saturday','Sunday','Friday','Monday'],'correct'=>'Monday'],
        ['skill'=>'reading','text'=>'"Some educators believe homework helps students practice. Others argue it adds unnecessary stress and reduces time for other activities." — What is one argument AGAINST homework?',
         'options'=>['It helps with practice','It is very useful','It reduces stress','It creates extra pressure'],'correct'=>'It creates extra pressure'],
    ];
}

function _get_b2_questions(): array {
    return [
        // Grammar (8)
        ['skill'=>'grammar','text'=>'If she had followed the doctor\'s advice, she ___ feeling better now.',
         'options'=>['would have been','will be','had been','would be'],'correct'=>'would be'],
        ['skill'=>'grammar','text'=>'The company is believed ___ millions in offshore accounts.',
         'options'=>['to hide','to be hiding','hiding','to have hidden'],'correct'=>'to have hidden'],
        ['skill'=>'grammar','text'=>'She announced that the results ___ the following week.',
         'options'=>['will be published','are published','were published','would be published'],'correct'=>'would be published'],
        ['skill'=>'grammar','text'=>'I wish I ___ the piano when I was younger.',
         'options'=>['learn','would learn','was learning','had learned'],'correct'=>'had learned'],
        ['skill'=>'grammar','text'=>'Not only ___ the exam, she also got the highest mark in the class.',
         'options'=>['she passed','she had passed','did she pass','had she passed'],'correct'=>'did she pass'],
        ['skill'=>'grammar','text'=>'The committee consists ___ twelve elected members from across the region.',
         'options'=>['from','with','by','of'],'correct'=>'of'],
        ['skill'=>'grammar','text'=>'The manager denied ___ the confidential report to the press.',
         'options'=>['to leak','that she leaked','leaked','having leaked'],'correct'=>'having leaked'],
        ['skill'=>'grammar','text'=>'He ___ the meeting — his car was in the car park all morning.',
         'options'=>['should have attended','would have attended','could have attended','must have attended'],'correct'=>'must have attended'],
        // Vocabulary (6)
        ['skill'=>'vocabulary','text'=>'The researcher\'s ___ provided compelling evidence for the new theory.',
         'options'=>['sayings','answers','thoughts','findings'],'correct'=>'findings'],
        ['skill'=>'vocabulary','text'=>'After the major setback, she decided to ___ and look for new opportunities.',
         'options'=>['face the music','cut corners','bite the bullet','burn bridges'],'correct'=>'bite the bullet'],
        ['skill'=>'vocabulary','text'=>'The government decided to ___ new legislation to combat climate change.',
         'options'=>['do','make','have','enact'],'correct'=>'enact'],
        ['skill'=>'vocabulary','text'=>'The board has agreed to ___ the proposal pending further independent review.',
         'options'=>['put off','delay','cancel','defer'],'correct'=>'defer'],
        ['skill'=>'vocabulary','text'=>'His speech was deliberately ___, making it impossible to determine his true position.',
         'options'=>['transparent','straightforward','explicit','ambiguous'],'correct'=>'ambiguous'],
        ['skill'=>'vocabulary','text'=>'She ___ her sincere gratitude for the generous donation to the foundation.',
         'options'=>['said','told','talked','expressed'],'correct'=>'expressed'],
        // Reading (6)
        ['skill'=>'reading','text'=>'"The Prime Minister\'s unexpected resignation came amid mounting pressure from within her own party, following a series of policy U-turns that had alienated key supporters." — Why did the Prime Minister resign? (Inference)',
         'options'=>['She had serious health problems','The opposition formally demanded it','She wanted to pursue another career','Her own party had turned against her'],'correct'=>'Her own party had turned against her'],
        ['skill'=>'reading','text'=>'"I am writing to raise a formal complaint regarding the unacceptable delay in processing my insurance claim, reference number INS-20943, which was submitted over six weeks ago." — What is the main purpose of this letter?',
         'options'=>['To request insurance','To cancel a policy','To provide information','To make a formal complaint'],'correct'=>'To make a formal complaint'],
        ['skill'=>'reading','text'=>'"While AI has undoubtedly streamlined operations across many industries, critics argue that its adoption at scale risks eroding the nuanced human judgment that remains indispensable in fields such as medicine and law." — What is the critics\' main concern about AI?',
         'options'=>['It is too expensive','It slows down operations','It creates more jobs','It may replace essential human judgment'],'correct'=>'It may replace essential human judgment'],
        ['skill'=>'reading','text'=>'"The phenomenon of \'greenwashing\' — where companies exaggerate environmental credentials — has led regulators in several countries to introduce stricter advertising standards." — What has led to stricter advertising standards?',
         'options'=>['Consumer demand for eco-products','A rise in pollution levels','Companies misrepresenting environmental credentials','Government investment in green energy'],'correct'=>'Companies misrepresenting environmental credentials'],
        ['skill'=>'reading','text'=>'"Although early studies appeared to validate the hypothesis, subsequent research revealed significant methodological flaws that cast doubt on the original findings." — What is implied about the early studies?',
         'options'=>['They were carefully conducted','They are now considered unreliable','They proved the hypothesis definitively','They were never published'],'correct'=>'They are now considered unreliable'],
        ['skill'=>'reading','text'=>'"The decline of independent bookshops is symptomatic of a broader trend: the consolidation of retail in the hands of a few global corporations. This not only limits consumer choice but also diminishes the cultural diversity that local businesses provide." — What is the writer\'s main argument?',
         'options'=>['Online shopping is better','Bookshops should sell online','Local businesses are inefficient','Corporate dominance harms local culture'],'correct'=>'Corporate dominance harms local culture'],
    ];
}

// ─── Run seed ─────────────────────────────────────────────────────────────────
seed_placement_exams($pdo);

// ─── Load placement exams ─────────────────────────────────────────────────────
$placementExams = [];
try {
    $stmt = $pdo->query(
        "SELECT e.*, (SELECT COUNT(*) FROM eval_questions WHERE exam_id=e.id) AS q_count
         FROM eval_exams e WHERE e.is_placement=TRUE ORDER BY e.cefr_level ASC"
    );
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $placementExams[$row['cefr_level']] = $row;
    }
} catch (Throwable $e) { /* table may not have is_placement yet */ }

$activeLevel = $_GET['level'] ?? 'A2';
if (!in_array($activeLevel, ['A2','B1','B2'], true)) $activeLevel = 'A2';
$currentExam  = $placementExams[$activeLevel] ?? null;
$currentExamId = (int) ($currentExam['id'] ?? 0);

$msg   = '';
$error = '';

// Flash from GET
if (($_GET['msg'] ?? '') === 'link_created')   $msg   = '✅ Enlace creado correctamente.';
if (($_GET['msg'] ?? '') === 'link_deleted')   $msg   = '🗑️ Enlace eliminado.';
if (($_GET['msg'] ?? '') === 'link_error')     $error = '❌ Error al procesar la solicitud.';

// ─── POST Handlers ────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action  = trim($_POST['action'] ?? '');
    $examId  = (int) ($_POST['exam_id'] ?? 0);
    $level   = trim($_POST['level'] ?? $activeLevel);
    if (!in_array($level, ['A2','B1','B2'], true)) $level = 'A2';

    if ($action === 'generate_group_link' && $examId > 0) {
        $availDate   = trim($_POST['available_date'] ?? '');
        $durationHrs = max(1, (int) ($_POST['duration_hours'] ?? 168));
        $maxUses     = max(1, (int) ($_POST['max_uses'] ?? 999));
        $token       = bin2hex(random_bytes(16));

        if ($availDate !== '') {
            $expiresAt = date('Y-m-d H:i:s',
                strtotime($availDate . ' 00:00:00') + ($durationHrs * 3600));
        } else {
            $expiresAt = null;
        }

        try {
            $pdo->prepare(
                "INSERT INTO eval_links (exam_id, token, link_type, max_uses, expires_at, created_by)
                 VALUES (?,?,'group',?,?,?)"
            )->execute([$examId, $token, $maxUses, $expiresAt,
                $_SESSION['admin_username'] ?? 'admin']);
            header('Location: index.php?level=' . urlencode($level) . '&msg=link_created');
        } catch (Throwable $e) {
            header('Location: index.php?level=' . urlencode($level) . '&msg=link_error');
        }
        exit;
    }

    if ($action === 'add_individual' && $examId > 0) {
        $sName  = trim($_POST['student_name'] ?? '');
        $sDoc   = trim($_POST['student_doc']  ?? '');
        $sPhone = trim($_POST['student_phone'] ?? '');
        $sEmail = trim($_POST['student_email'] ?? '');
        $token  = bin2hex(random_bytes(16));

        if ($sName === '') {
            header('Location: index.php?level=' . urlencode($level) . '&msg=link_error');
            exit;
        }

        try {
            $pdo->prepare(
                "INSERT INTO eval_links
                    (exam_id, token, link_type, student_name, student_doc,
                     student_phone, student_email, max_uses, created_by)
                 VALUES (?,?,'individual',?,?,?,?,1,?)"
            )->execute([$examId, $token, $sName, $sDoc, $sPhone, $sEmail,
                $_SESSION['admin_username'] ?? 'admin']);
            header('Location: index.php?level=' . urlencode($level) . '&msg=link_created');
        } catch (Throwable $e) {
            header('Location: index.php?level=' . urlencode($level) . '&msg=link_error');
        }
        exit;
    }

    if ($action === 'delete_link') {
        $linkId = (int) ($_POST['link_id'] ?? 0);
        if ($linkId > 0) {
            $pdo->prepare("DELETE FROM eval_links WHERE id=?")->execute([$linkId]);
            header('Location: index.php?level=' . urlencode($level) . '&msg=link_deleted');
            exit;
        }
    }
}

// ─── Load links & results for current exam ────────────────────────────────────
$links   = [];
$results = [];

if ($currentExamId > 0) {
    $lStmt = $pdo->prepare(
        "SELECT * FROM eval_links WHERE exam_id=? ORDER BY created_at DESC"
    );
    $lStmt->execute([$currentExamId]);
    $links = $lStmt->fetchAll(PDO::FETCH_ASSOC);

    $rStmt = $pdo->prepare(
        "SELECT student_name, student_doc, student_phone, student_email,
                score, max_score, pct, cefr_suggested, submitted_at, status
         FROM eval_results WHERE exam_id=? AND status='submitted'
         ORDER BY submitted_at DESC"
    );
    $rStmt->execute([$currentExamId]);
    $results = $rStmt->fetchAll(PDO::FETCH_ASSOC);
}

// ─── Base URL for viewer links ────────────────────────────────────────────────
$baseViewerUrl = rtrim(dirname($_SERVER['PHP_SELF']), '/') . '/viewer.php';

$levelColors = ['A2'=>'#22c55e','B1'=>'#3b82f6','B2'=>'#7F77DD'];
$levelNames  = ['A2'=>'A2 — Básico','B1'=>'B1 — Intermedio','B2'=>'B2 — Intermedio Alto'];
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Placement Tests — ONES Admin</title>
<link rel="stylesheet" href="placement.css">
<style>
.admin-topbar{background:#fff;border-bottom:2px solid #EDE9FA;padding:12px 24px;display:flex;align-items:center;justify-content:space-between;gap:16px;}
.admin-topbar h1{font-family:'Fredoka',sans-serif;font-size:22px;color:#7F77DD;margin:0;}
.admin-topbar a{font-size:13px;font-weight:700;color:#7c7aa0;text-decoration:none;padding:7px 14px;border-radius:10px;border:1.5px solid #EDE9FA;transition:all .2s;}
.admin-topbar a:hover{background:#EDE9FA;color:#7F77DD;}
.section-sep{height:1.5px;background:#EDE9FA;margin:24px 0;}
.link-url{font-size:12px;color:#7c7aa0;word-break:break-all;background:#f8f7ff;padding:6px 10px;border-radius:8px;margin-top:6px;}
.link-url a{color:#7F77DD;font-weight:700;}
.copy-btn{font-size:11px;padding:4px 10px;background:#EDE9FA;color:#7F77DD;border:none;border-radius:6px;cursor:pointer;font-weight:700;}
.copy-btn:hover{background:#7F77DD;color:#fff;}
.collapsible-header{cursor:pointer;display:flex;align-items:center;justify-content:space-between;padding:14px 20px;background:#f8f7ff;border-radius:14px;border:1.5px solid #EDE9FA;margin-bottom:8px;font-weight:800;font-size:14px;color:#7F77DD;}
.collapsible-header:hover{background:#EDE9FA;}
.collapsible-body{display:none;padding:16px 0 4px;}
.collapsible-body.open{display:block;}
</style>
</head>
<body>

<div class="admin-topbar">
    <h1>📋 Placement Tests</h1>
    <div style="display:flex;gap:10px;align-items:center;">
        <a href="../eval/admin_eval.php">🎓 Evaluaciones</a>
        <a href="../../academic/dashboard.php">← Dashboard</a>
    </div>
</div>

<div class="pt-page-wide">

    <?php if ($msg): ?>
        <div class="pt-alert pt-alert-ok"><?= h($msg) ?></div>
    <?php endif; ?>
    <?php if ($error): ?>
        <div class="pt-alert pt-alert-error"><?= h($error) ?></div>
    <?php endif; ?>

    <!-- Level Overview Cards -->
    <div class="pt-level-grid" style="margin-top:20px;">
        <?php foreach (['A2','B1','B2'] as $lv): ?>
            <?php $lExam = $placementExams[$lv] ?? null; ?>
            <div class="pt-level-card pt-level-card-<?= $lv ?>" data-level="<?= $lv ?>">
                <div class="pt-level-card-title"><?= h($levelNames[$lv]) ?></div>
                <div class="pt-level-card-sub">
                    <?= $lExam ? h((int)($lExam['q_count'] ?? 0)) . ' preguntas · 45 min' : 'No encontrado' ?>
                </div>
                <div class="pt-level-card-btns">
                    <a class="pt-btn" href="?level=<?= $lv ?>">Gestionar</a>
                    <?php if ($lExam): ?>
                        <a class="pt-btn" href="viewer.php?level=<?= $lv ?>" target="_blank">Vista previa</a>
                        <a class="pt-btn" href="print.php?level=<?= $lv ?>" target="_blank">Imprimir</a>
                    <?php endif; ?>
                </div>
            </div>
        <?php endforeach; ?>
    </div>

    <!-- Level Tabs -->
    <div class="pt-tabs">
        <?php foreach (['A2'=>'🟢 A2 Básico','B1'=>'🔵 B1 Intermedio','B2'=>'🟣 B2 Intermedio Alto'] as $lv=>$label): ?>
            <a class="pt-tab pt-tab-<?= $lv ?><?= $activeLevel === $lv ? ' active' : '' ?>"
               href="?level=<?= $lv ?>">
                <?= h($label) ?>
            </a>
        <?php endforeach; ?>
    </div>

    <?php if (!$currentExam): ?>
        <div class="pt-alert pt-alert-error">
            No se encontró el examen de nivel <?= h($activeLevel) ?>. El sistema intentará recrearlo al recargar.
        </div>
    <?php else: ?>

    <!-- Quick Actions -->
    <div class="pt-actions">
        <a class="pt-btn pt-btn-primary"
           href="viewer.php?level=<?= h($activeLevel) ?>" target="_blank">
            👁️ Vista Previa Estudiante
        </a>
        <a class="pt-btn pt-btn-ghost"
           href="print.php?level=<?= h($activeLevel) ?>" target="_blank">
            🖨️ Imprimir Examen
        </a>
        <span style="font-size:13px;color:#7c7aa0;align-self:center;">
            ID: <?= $currentExamId ?> · <?= h($currentExam['q_count'] ?? '0') ?> preguntas
        </span>
    </div>

    <!-- ── Generate Group Link ─────────────────────────────────────────── -->
    <div class="pt-card">
        <div class="collapsible-header" onclick="toggleSection('group-link')">
            <span>🔗 Generar Enlace Grupal</span>
            <span id="arrow-group-link">▼</span>
        </div>
        <div class="collapsible-body open" id="section-group-link">
            <p style="font-size:13px;color:#7c7aa0;margin-bottom:16px;">
                Un enlace grupal permite que múltiples estudiantes accedan al test con el mismo URL.
            </p>
            <form method="POST">
                <input type="hidden" name="action" value="generate_group_link">
                <input type="hidden" name="exam_id" value="<?= $currentExamId ?>">
                <input type="hidden" name="level" value="<?= h($activeLevel) ?>">
                <div class="pt-form-row">
                    <div class="pt-form-group">
                        <label>Fecha disponible desde</label>
                        <input type="date" name="available_date"
                               value="<?= date('Y-m-d') ?>">
                    </div>
                    <div class="pt-form-group">
                        <label>Duración (horas)</label>
                        <input type="number" name="duration_hours" value="168" min="1" max="8760">
                    </div>
                    <div class="pt-form-group">
                        <label>Máximo de usos</label>
                        <input type="number" name="max_uses" value="999" min="1">
                    </div>
                </div>
                <button type="submit" class="pt-btn pt-btn-green">
                    ✨ Crear Enlace Grupal
                </button>
            </form>
        </div>
    </div>

    <!-- ── Add Individual Link ────────────────────────────────────────── -->
    <div class="pt-card">
        <div class="collapsible-header" onclick="toggleSection('individual')">
            <span>👤 Agregar Estudiante Individual</span>
            <span id="arrow-individual">▼</span>
        </div>
        <div class="collapsible-body" id="section-individual">
            <p style="font-size:13px;color:#7c7aa0;margin-bottom:16px;">
                Crea un enlace personalizado para un estudiante específico (1 solo uso).
            </p>
            <form method="POST">
                <input type="hidden" name="action" value="add_individual">
                <input type="hidden" name="exam_id" value="<?= $currentExamId ?>">
                <input type="hidden" name="level" value="<?= h($activeLevel) ?>">
                <div class="pt-form-row">
                    <div class="pt-form-group">
                        <label>Nombre completo *</label>
                        <input type="text" name="student_name" required placeholder="Ej: Ana García">
                    </div>
                    <div class="pt-form-group">
                        <label>Cédula (CC)</label>
                        <input type="text" name="student_doc" placeholder="Ej: 1234567890">
                    </div>
                    <div class="pt-form-group">
                        <label>Teléfono</label>
                        <input type="text" name="student_phone" placeholder="Ej: 3001234567">
                    </div>
                    <div class="pt-form-group">
                        <label>Email</label>
                        <input type="email" name="student_email" placeholder="ana@example.com">
                    </div>
                </div>
                <button type="submit" class="pt-btn pt-btn-purple">
                    👤 Crear Enlace Individual
                </button>
            </form>
        </div>
    </div>

    <!-- ── Links Table ────────────────────────────────────────────────── -->
    <div class="pt-card">
        <h3 class="pt-card-title" style="font-size:18px;">🔗 Enlaces Activos</h3>
        <?php if (empty($links)): ?>
            <p style="color:#7c7aa0;font-size:14px;">No hay enlaces creados aún.</p>
        <?php else: ?>
        <div class="pt-table-wrap">
            <table class="pt-table">
                <thead>
                    <tr>
                        <th>Tipo</th>
                        <th>Estudiante</th>
                        <th>Expira</th>
                        <th>Usos</th>
                        <th>Enlace</th>
                        <th>Acción</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($links as $lnk): ?>
                        <?php
                        $viewerUrl = $baseViewerUrl . '?t=' . urlencode($lnk['token']);
                        $isExpired = $lnk['expires_at'] && strtotime($lnk['expires_at']) < time();
                        $isFull    = (int)$lnk['uses_count'] >= (int)$lnk['max_uses'];
                        ?>
                        <tr style="<?= ($isExpired || $isFull) ? 'opacity:.55' : '' ?>">
                            <td>
                                <span class="level-badge <?= $lnk['link_type'] === 'individual' ? 'level-badge-B2' : 'level-badge-B1' ?>">
                                    <?= $lnk['link_type'] === 'individual' ? '👤 Individual' : '👥 Grupal' ?>
                                </span>
                            </td>
                            <td style="font-size:13px;">
                                <?php if ($lnk['student_name']): ?>
                                    <strong><?= h($lnk['student_name']) ?></strong><br>
                                    <small style="color:#7c7aa0;"><?= h($lnk['student_doc'] ?? '') ?></small>
                                <?php else: ?>
                                    <span style="color:#7c7aa0;">— Grupal —</span>
                                <?php endif; ?>
                            </td>
                            <td style="font-size:12px;color:#7c7aa0;">
                                <?= $lnk['expires_at']
                                    ? ($isExpired ? '<span style="color:#dc2626;">⚠️ Expirado</span>' : h(date('d/m/Y H:i', strtotime($lnk['expires_at']))))
                                    : '∞ Sin expirar' ?>
                            </td>
                            <td style="font-size:13px;font-weight:800;">
                                <?= h((string)$lnk['uses_count']) ?> /
                                <?= $lnk['max_uses'] >= 999 ? '∞' : h((string)$lnk['max_uses']) ?>
                            </td>
                            <td style="max-width:240px;">
                                <div class="link-url">
                                    <a href="<?= h($viewerUrl) ?>" target="_blank">
                                        viewer.php?t=<?= h(substr($lnk['token'], 0, 12)) ?>...
                                    </a>
                                </div>
                                <button class="copy-btn" onclick="copyToClipboard('<?= h($viewerUrl) ?>', this)">
                                    📋 Copiar
                                </button>
                            </td>
                            <td>
                                <form method="POST" style="display:inline;"
                                      onsubmit="return confirm('¿Eliminar este enlace?');">
                                    <input type="hidden" name="action" value="delete_link">
                                    <input type="hidden" name="link_id" value="<?= (int)$lnk['id'] ?>">
                                    <input type="hidden" name="level" value="<?= h($activeLevel) ?>">
                                    <button type="submit" class="pt-btn pt-btn-danger pt-btn-sm">
                                        🗑️
                                    </button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
    </div>

    <!-- ── Results Table ──────────────────────────────────────────────── -->
    <div class="pt-card">
        <h3 class="pt-card-title" style="font-size:18px;">📊 Resultados de Estudiantes</h3>
        <?php if (empty($results)): ?>
            <p style="color:#7c7aa0;font-size:14px;">No hay resultados aún para este nivel.</p>
        <?php else: ?>
        <div class="pt-table-wrap">
            <table class="pt-table">
                <thead>
                    <tr>
                        <th>Nombre</th>
                        <th>CC</th>
                        <th>Teléfono</th>
                        <th>Email</th>
                        <th>Puntaje</th>
                        <th>Nivel MCER</th>
                        <th>Fecha</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($results as $r): ?>
                        <tr>
                            <td><strong><?= h($r['student_name'] ?? '—') ?></strong></td>
                            <td style="font-size:13px;"><?= h($r['student_doc'] ?? '—') ?></td>
                            <td style="font-size:13px;"><?= h($r['student_phone'] ?? '—') ?></td>
                            <td style="font-size:13px;"><?= h($r['student_email'] ?? '—') ?></td>
                            <td>
                                <strong style="color:#F97316;font-size:15px;">
                                    <?= round((float)($r['pct'] ?? 0)) ?>%
                                </strong>
                            </td>
                            <td>
                                <?php $cefr = $r['cefr_suggested'] ?? '—'; ?>
                                <span class="level-badge level-badge-<?= h($cefr) ?>">
                                    <?= h($cefr) ?>
                                </span>
                            </td>
                            <td style="font-size:12px;color:#7c7aa0;">
                                <?= $r['submitted_at']
                                    ? h(date('d/m/Y H:i', strtotime($r['submitted_at'])))
                                    : '—' ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
    </div>

    <?php endif; /* end if currentExam */ ?>

</div><!-- /pt-page-wide -->

<script>
function toggleSection(id) {
    const body  = document.getElementById('section-' + id);
    const arrow = document.getElementById('arrow-' + id);
    if (!body) return;
    const isOpen = body.classList.toggle('open');
    if (arrow) arrow.textContent = isOpen ? '▲' : '▼';
}

function copyToClipboard(text, btn) {
    navigator.clipboard.writeText(text).then(() => {
        const orig = btn.textContent;
        btn.textContent = '✅ Copiado';
        btn.style.background = '#22c55e';
        btn.style.color = '#fff';
        setTimeout(() => {
            btn.textContent = orig;
            btn.style.background = '';
            btn.style.color = '';
        }, 2000);
    }).catch(() => {
        prompt('Copia este enlace:', text);
    });
}
</script>
</body>
</html>
