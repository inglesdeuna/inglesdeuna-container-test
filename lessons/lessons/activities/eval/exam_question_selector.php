<?php
/**
 * exam_question_selector.php
 * Selecciona y normaliza preguntas para exámenes de evaluación.
 */
require_once __DIR__ . '/../quiz/_quiz_lib.php';

const SKILL_MAP = [
    'multiple_choice'       => 'grammar',
    'fillblank'             => 'grammar',
    'fill_in_blank'         => 'grammar',
    'unscramble'            => 'grammar',
    'order_sentences'       => 'grammar',
    'build_sentence'        => 'grammar',
    'match'                 => 'grammar',
    'drag_drop'             => 'grammar',
    'drag_drop_kids'        => 'grammar',
    'flashcards'            => 'vocabulary',
    'memory_cards'          => 'vocabulary',
    'crossword'             => 'vocabulary',
    'hangman'               => 'vocabulary',
    'lets_classify'         => 'vocabulary',
    'listen_order'          => 'listening',
    'dictation'             => 'listening',
    'pronunciation'         => 'listening',
    'video_comprehension'   => 'listening',
    'reading_comprehension' => 'reading',
    'question_answer'       => 'reading',
    'flipbooks'             => 'reading',
    'writing_practice'      => 'writing',
    'roleplay'              => 'speaking',
    'free_conversation'     => 'speaking',
];

const DEFAULT_QUOTAS = [
    'grammar'    => 35,
    'vocabulary' => 25,
    'listening'  => 25,
    'reading'    => 15,
];

const MAX_QUESTIONS_PER_ACTIVITY = 3;

function select_exam_questions(PDO $pdo, array $examConfig, string $studentId, int $attempt, array $history = []): array
{
    $totalQuestions = (int) ($examConfig['total_questions'] ?? 20);
    $quotas         = $examConfig['quotas'] ?? DEFAULT_QUOTAS;
    $skills         = $examConfig['skills'] ?? array_keys(DEFAULT_QUOTAS);
    $examId         = $examConfig['exam_id'] ?? null;
    $unitIds        = $examConfig['unit_ids'] ?? [];

    if (count($history) >= 3) {
        try {
            $selected = _phase2_claude_selection($pdo, $examConfig, $studentId, $attempt, $history, $totalQuestions, $quotas, $skills, $examId, $unitIds);
            if (!empty($selected)) return _assign_points($selected, 100);
        } catch (Throwable $e) {}
    }

    return _phase1_random_selection($pdo, $examConfig, $studentId, $attempt, $totalQuestions, $quotas, $skills, $examId, $unitIds);
}

function _resolve_unit_ids_for_exam(PDO $pdo, ?int $examId, array $unitIds): array
{
    $clean = [];
    foreach ($unitIds as $uid) {
        $uid = trim((string)$uid);
        if ($uid !== '' && $uid !== '0') $clean[] = $uid;
    }
    if (!$clean && $examId !== null && $examId > 0) {
        try {
            $stmt = $pdo->prepare('SELECT unit_id FROM eval_exams WHERE id=? LIMIT 1');
            $stmt->execute([$examId]);
            $uid = trim((string)$stmt->fetchColumn());
            if ($uid !== '') $clean[] = $uid;
        } catch (Throwable $e) {}
    }
    return array_values(array_unique($clean));
}

function _phase1_random_selection(PDO $pdo, array $examConfig, string $studentId, int $attempt, int $totalQuestions, array $quotas, array $skills, ?int $examId, array $unitIds): array
{
    $unitIds = _resolve_unit_ids_for_exam($pdo, $examId, $unitIds);
    $all = _load_all_questions($pdo, $examId, $unitIds);
    if (empty($all)) return [];

    $unitSeed = !empty($unitIds) ? crc32((string)reset($unitIds)) : 0;
    $assignment = trim((string) ($examConfig['assignment_id'] ?? $examConfig['exam_id'] ?? '0'));
    $built = qz_build($all, (int)$unitSeed, $assignment, $attempt);
    return _assign_points($built, 100);
}

function _phase2_claude_selection(PDO $pdo, array $examConfig, string $studentId, int $attempt, array $history, int $totalQuestions, array $quotas, array $skills, ?int $examId, array $unitIds): array
{
    $skillTotals = [];
    $skillScores = [];
    foreach ($history as $h) {
        $ss = is_string($h['skill_scores'] ?? null) ? json_decode($h['skill_scores'], true) : ($h['skill_scores'] ?? []);
        if (!is_array($ss)) continue;
        foreach ($ss as $sk => $val) {
            $skillScores[$sk] = ($skillScores[$sk] ?? 0) + (float)($val['score'] ?? 0);
            $skillTotals[$sk] = ($skillTotals[$sk] ?? 0) + (float)($val['total'] ?? 1);
        }
    }
    $gaps = [];
    foreach ($skillScores as $sk => $total) {
        $max = $skillTotals[$sk] ?? 1;
        $gaps[$sk] = $max > 0 ? round((1 - $total / $max) * 100, 1) : 100;
    }

    $claudeApiKey = getenv('ANTHROPIC_API_KEY') ?: '';
    if (empty($claudeApiKey)) throw new RuntimeException('No ANTHROPIC_API_KEY');

    $prompt = "You are an English test advisor. Given the student's skill gaps (0=mastered, 100=weakest), return a JSON object with skill names as keys and integer quota percentages as values that sum to 100. Allocate more questions to weaker skills.\nStudent gaps: " . json_encode($gaps) . "\nAvailable skills: " . implode(', ', $skills) . "\nRespond ONLY with the JSON object.";
    $payload = json_encode(['model'=>'claude-haiku-4-5-20251001','max_tokens'=>256,'messages'=>[['role'=>'user','content'=>$prompt]]]);
    $ch = curl_init('https://api.anthropic.com/v1/messages');
    curl_setopt_array($ch, [CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>$payload,CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>10,CURLOPT_HTTPHEADER=>['Content-Type: application/json','x-api-key: '.$claudeApiKey,'anthropic-version: 2023-06-01']]);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($httpCode !== 200 || !$response) throw new RuntimeException('Claude API error: '.$httpCode);
    $body = json_decode($response, true);
    $text = $body['content'][0]['text'] ?? '';
    preg_match('/\{[^}]+\}/s', $text, $matches);
    $adaptedQuotas = json_decode($matches[0] ?? '{}', true);
    if (!is_array($adaptedQuotas) || empty($adaptedQuotas)) throw new RuntimeException('Claude returned invalid quotas');
    $sum = array_sum($adaptedQuotas);
    if ($sum <= 0) throw new RuntimeException('Claude quotas sum to zero');
    foreach ($adaptedQuotas as $sk => $v) $adaptedQuotas[$sk] = (int)round($v / $sum * 100);

    $unitIds = _resolve_unit_ids_for_exam($pdo, $examId, $unitIds);
    $seed = md5('ones_exam_v1_' . $studentId . '_attempt_' . $attempt);
    $bySkill = _load_questions_by_skill($pdo, $examId, $unitIds, $skills);
    $quotaCount = _calculate_quotas($totalQuestions, $adaptedQuotas, $skills);
    $selected = [];
    foreach ($quotaCount as $skill => $count) {
        $pool = _seeded_shuffle($bySkill[$skill] ?? [], $seed . $skill);
        $selected = array_merge($selected, array_slice($pool, 0, $count));
    }
    return _seeded_shuffle($selected, $seed . '_final');
}

function _load_all_questions(PDO $pdo, ?int $examId, array $unitIds): array
{
    $all = [];
    if ($examId !== null) {
        $stmt = $pdo->prepare("SELECT eq.*, ea.answer_text, ea.is_correct, ea.order_index FROM eval_questions eq LEFT JOIN eval_answers ea ON ea.question_id = eq.id WHERE eq.exam_id = ? ORDER BY eq.position, eq.id, ea.order_index");
        $stmt->execute([$examId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $grouped = [];
        foreach ($rows as $row) {
            $qid = $row['id'];
            if (!isset($grouped[$qid])) {
                $grouped[$qid] = ['source'=>'eval_questions','id'=>'eq_'.$qid,'type'=>$row['type'],'skill'=>$row['skill'] ?? 'grammar','text'=>$row['question_text'],'question'=>$row['question_text'],'audio'=>$row['audio_url'],'image'=>$row['image_url'],'points'=>(float)($row['points'] ?? 1),'options'=>[],'correct'=>null,'pairs'=>[],'data'=>is_string($row['data'] ?? null) ? json_decode($row['data'], true) : ($row['data'] ?? [])];
            }
            if ($row['answer_text'] !== null) {
                $grouped[$qid]['options'][] = $row['answer_text'];
                if ($row['is_correct']) $grouped[$qid]['correct'] = $row['answer_text'];
            }
        }
        foreach ($grouped as $q) $all[] = $q;
        if (!empty($all)) return $all;
    }

    $unitIds = _resolve_unit_ids_for_exam($pdo, $examId, $unitIds);
    if (!empty($unitIds)) {
        $placeholders = implode(',', array_fill(0, count($unitIds), '?'));
        $stmt = $pdo->prepare("SELECT * FROM activities WHERE unit_id IN ({$placeholders}) ORDER BY id ASC");
        $stmt->execute(array_values($unitIds));
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $act) {
            foreach (qz_normalize_activity($act) as $q) {
                $q['source'] = 'activities';
                $q['activity_id'] = (int)$act['id'];
                $all[] = $q;
            }
        }
    }
    return $all;
}

function _load_questions_by_skill(PDO $pdo, ?int $examId, array $unitIds, array $skills): array
{
    $all = _load_all_questions($pdo, $examId, $unitIds);
    $bySkill = array_fill_keys($skills, []);
    foreach ($all as $q) {
        $skill = $q['skill'] ?? (SKILL_MAP[$q['type'] ?? ''] ?? 'grammar');
        if (isset($bySkill[$skill])) $bySkill[$skill][] = $q;
    }
    return $bySkill;
}

function _assign_points(array $questions, int $totalPoints): array
{
    $n = count($questions);
    if ($n === 0) return [];
    $per = $totalPoints / $n;
    foreach ($questions as &$q) $q['points'] = round($per, 2);
    unset($q);
    return $questions;
}

function _calculate_quotas(int $totalQuestions, array $quotas, array $skills): array
{
    $counts = [];
    $remaining = $totalQuestions;
    foreach ($skills as $skill) {
        $count = (int)floor($totalQuestions * (($quotas[$skill] ?? 0) / 100));
        $counts[$skill] = $count;
        $remaining -= $count;
    }
    $i = 0;
    while ($remaining > 0 && !empty($skills)) {
        $counts[$skills[$i % count($skills)]]++;
        $remaining--;
        $i++;
    }
    return $counts;
}

function _seeded_shuffle(array $items, string $seed): array
{
    usort($items, function($a, $b) use ($seed) {
        $ha = md5($seed . json_encode($a));
        $hb = md5($seed . json_encode($b));
        return strcmp($ha, $hb);
    });
    return $items;
}

function serialize_exam_selection(array $questions): string
{
    return json_encode($questions, JSON_UNESCAPED_UNICODE);
}

function load_exam_questions_from_selection(PDO $pdo, string $selectionJson): array
{
    $decoded = json_decode($selectionJson, true);
    return is_array($decoded) ? $decoded : [];
}
?>
