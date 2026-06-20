<?php
if (session_status() === PHP_SESSION_NONE) session_start();
header('Content-Type: application/json');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');

function fc_same_origin_editor_request(): bool {
    $referer = (string)($_SERVER['HTTP_REFERER'] ?? '');
    $host = (string)($_SERVER['HTTP_HOST'] ?? '');
    if ($referer === '' || $host === '') return false;
    $parts = parse_url($referer);
    if (!is_array($parts)) return false;
    return hash_equals($host, (string)($parts['host'] ?? '')) && str_contains((string)($parts['path'] ?? ''), '/lessons/lessons/activities/free_conversation/viewer.php');
}

$isAuth = !empty($_SESSION['admin_logged']) || !empty($_SESSION['admin_id']) || !empty($_SESSION['admin_email']) || !empty($_SESSION['admin_username']) || !empty($_SESSION['admin_role']) || !empty($_SESSION['academic_logged']) || !empty($_SESSION['teacher_logged']) || !empty($_SESSION['teacher_id']) || !empty($_SESSION['teacher_username']) || !empty($_SESSION['academic_id']);
if (!$isAuth && !fc_same_origin_editor_request()) { http_response_code(403); echo json_encode(['error'=>'Unauthorized']); exit; }
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); echo json_encode(['error'=>'Method not allowed']); exit; }
require_once __DIR__ . '/../../config/db.php';

$input = json_decode((string)file_get_contents('php://input'), true);
if (!is_array($input)) { http_response_code(400); echo json_encode(['error'=>'Invalid JSON']); exit; }
$activityId = isset($input['id']) ? trim((string)$input['id']) : '';
if ($activityId === '') { http_response_code(400); echo json_encode(['error'=>'Missing activity id']); exit; }

function fc_str($v, string $default = '', int $max = 1000): string { $s = substr(trim((string)($v ?? $default)), 0, $max); return $s === '' ? $default : $s; }
function fc_bool($v, bool $default = true): bool { return isset($v) ? filter_var($v, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? $default : $default; }

$allowedVoiceIds = ['nzFihrBIvB34imQBuxub','NoOVOzCQFLOvtsMoNcdT','Nggzl2QAXh3OijoXD116'];
$teacherVoiceId = fc_str($input['teacherVoiceId'] ?? '', 'nzFihrBIvB34imQBuxub', 80);
if (!in_array($teacherVoiceId, $allowedVoiceIds, true)) $teacherVoiceId = 'nzFihrBIvB34imQBuxub';

$level = strtoupper(fc_str($input['level'] ?? '', 'B1', 5));
if (!in_array($level, ['A2','B1','B2','C1'], true)) $level = 'B1';
$durationMin = isset($input['duration_min']) ? (int)$input['duration_min'] : (isset($input['timeLimit']) ? (int)$input['timeLimit'] : 8);
if (!in_array($durationMin, [0,5,8,10,15], true)) $durationMin = 8;

$validModes = ['chat_feedback','voice_only','debate','interview'];
$conversationMode = fc_str($input['conversation_mode'] ?? '', 'chat_feedback', 40);
if (!in_array($conversationMode, $validModes, true)) $conversationMode = 'chat_feedback';

$validPersonas = ['Friendly coach','Curious friend','Interviewer','Debate partner'];
$persona = fc_str($input['persona'] ?? '', 'Friendly coach', 80);
if (!in_array($persona, $validPersonas, true)) $persona = 'Friendly coach';

$targetVocab = [];
if (isset($input['targetVocab']) && is_array($input['targetVocab'])) {
    foreach (array_slice($input['targetVocab'], 0, 30) as $v) {
        if (is_array($v)) {
            $word = fc_str($v['word'] ?? $v['term'] ?? '', '', 80);
            $meaning = fc_str($v['meaning'] ?? $v['definition'] ?? '', '', 160);
        } else {
            $word = fc_str($v, '', 80);
            $meaning = '';
        }
        if ($word !== '') $targetVocab[] = ['word'=>$word, 'meaning'=>$meaning];
    }
}

$hints = [];
if (isset($input['hints']) && is_array($input['hints'])) {
    foreach (array_slice($input['hints'], 0, 5) as $h) { $s = fc_str($h, '', 200); if ($s !== '') $hints[] = $s; }
}
if (!$hints) $hints = ['Give a complete answer.','Ask a follow-up question.','Try using a target word.'];

$weightsIn = is_array($input['scoringWeights'] ?? null) ? $input['scoringWeights'] : [];
$weights = [
    'fluency' => max(0, min(100, (int)($weightsIn['fluency'] ?? 40))),
    'grammar' => max(0, min(100, (int)($weightsIn['grammar'] ?? 35))),
    'vocabulary' => max(0, min(100, (int)($weightsIn['vocabulary'] ?? 25))),
];
$sum = array_sum($weights);
if ($sum <= 0) $weights = ['fluency'=>40,'grammar'=>35,'vocabulary'=>25];

$validLanguages = ['English','Spanish','French','Portuguese','German','Italian','Chinese','Japanese','Korean','Arabic'];
$targetLanguage = fc_str($input['targetLanguage'] ?? '', 'English', 30);
if (!in_array($targetLanguage, $validLanguages, true)) $targetLanguage = 'English';

$dataPayload = [
    'title' => fc_str($input['title'] ?? '', 'Free Conversation', 200),
    'topic' => fc_str($input['topic'] ?? '', 'Open Topic', 1000),
    'level' => $level,
    'duration_min' => $durationMin,
    'conversation_mode' => $conversationMode,
    'difficulty' => fc_str($input['difficulty'] ?? '', $level === 'A2' ? 'beginner' : ($level === 'C1' ? 'advanced' : 'intermediate'), 40),
    'agentName' => fc_str($input['agentName'] ?? '', 'Alex', 50),
    'persona' => $persona,
    'teacherVoiceId' => $teacherVoiceId,
    'system_prompt' => fc_str($input['system_prompt'] ?? '', 'Have a friendly open conversation. Ask short follow-up questions.', 3000),
    'targetVocab' => $targetVocab,
    'hints' => $hints,
    'targetLanguage' => $targetLanguage,
    'showFeedback' => fc_bool($input['showFeedback'] ?? null, true),
    'showVocab' => fc_bool($input['showVocab'] ?? null, true),
    'showCoachTips' => fc_bool($input['showCoachTips'] ?? null, true),
    'allowHints' => fc_bool($input['allowHints'] ?? null, true),
    'scoringWeights' => $weights,
];
$dataJson = json_encode($dataPayload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
$stmt = $pdo->prepare('UPDATE activities SET data = :data WHERE id = :id');
$stmt->execute(['data'=>$dataJson, 'id'=>$activityId]);
if ($stmt->rowCount() === 0) {
    $existsStmt = $pdo->prepare('SELECT id FROM activities WHERE id = :id LIMIT 1');
    $existsStmt->execute(['id'=>$activityId]);
    if (!$existsStmt->fetchColumn()) { http_response_code(404); echo json_encode(['error'=>'Activity not found']); exit; }
}
echo json_encode(['ok'=>true,'activity_id'=>$activityId,'save_destination'=>'activities.data']);
