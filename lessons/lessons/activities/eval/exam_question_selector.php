<?php
/**
 * exam_question_selector.php
 * Selecciona y normaliza preguntas para exámenes de evaluación.
 */

// ─── Mapeo activity_type → skill ────────────────────────────────────────────
const SKILL_MAP = [
    'multiple_choice'       => 'grammar',
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

// ─── Función principal ───────────────────────────────────────────────────────

/**
 * Selecciona preguntas para un examen.
 *
 * @param PDO    $pdo
 * @param array  $examConfig  { exam_id, unit_ids[], total_questions, quotas?, skills? }
 * @param string $studentId
 * @param int    $attempt     Número de intento (1-based)
 * @param array  $history     Intentos previos [{ skill_scores, answers_json }]
 * @return array  Lista de preguntas normalizadas con puntos asignados
 */
function select_exam_questions(PDO $pdo, array $examConfig, string $studentId, int $attempt, array $history = []): array
{
    $totalQuestions = (int) ($examConfig['total_questions'] ?? 20);
    $quotas         = $examConfig['quotas'] ?? DEFAULT_QUOTAS;
    $skills         = $examConfig['skills'] ?? array_keys(DEFAULT_QUOTAS);
    $examId         = $examConfig['exam_id'] ?? null;
    $unitIds        = $examConfig['unit_ids'] ?? [];

    // Fase 2: >= 3 exámenes previos → Claude ranking
    if (count($history) >= 3) {
        try {
            $selected = _phase2_claude_selection($pdo, $examConfig, $studentId, $attempt, $history, $totalQuestions, $quotas, $skills, $examId, $unitIds);
            if (!empty($selected)) {
                return _assign_points($selected, 100);
            }
        } catch (Throwable $e) {
            // Fallback a fase 1
        }
    }

    // Fase 1: Aleatorio determinista por seed
    return _phase1_random_selection($pdo, $examConfig, $studentId, $attempt, $totalQuestions, $quotas, $skills, $examId, $unitIds);
}

// ─── Fase 1: Selección aleatoria con seed determinista ───────────────────────

function _phase1_random_selection(
    PDO $pdo,
    array $examConfig,
    string $studentId,
    int $attempt,
    int $totalQuestions,
    array $quotas,
    array $skills,
    ?int $examId,
    array $unitIds
): array {
    $seed = md5('ones_exam_v1_' . $studentId . '_attempt_' . $attempt);

    // Cargar preguntas de la BD agrupadas por skill
    $bySkill = _load_questions_by_skill($pdo, $examId, $unitIds, $skills);

    // Calcular cuotas exactas con distribución del residuo al skill dominante
    $quotaCount = _calculate_quotas($totalQuestions, $quotas, $skills);

    $selected = [];
    foreach ($quotaCount as $skill => $count) {
        $pool = $bySkill[$skill] ?? [];
        $pool = _seeded_shuffle($pool, $seed . $skill);
        $selected = array_merge($selected, array_slice($pool, 0, $count));
    }

    // Mezclar el resultado final
    $selected = _seeded_shuffle($selected, $seed . '_final');

    return _assign_points($selected, 100);
}

// ─── Fase 2: Claude ranking ───────────────────────────────────────────────────

function _phase2_claude_selection(
    PDO $pdo,
    array $examConfig,
    string $studentId,
    int $attempt,
    array $history,
    int $totalQuestions,
    array $quotas,
    array $skills,
    ?int $examId,
    array $unitIds
): array {
    // Calcular gaps del estudiante a partir del historial
    $skillTotals  = [];
    $skillScores  = [];
    foreach ($history as $h) {
        $ss = is_string($h['skill_scores'] ?? null)
            ? json_decode($h['skill_scores'], true)
            : ($h['skill_scores'] ?? []);
        if (!is_array($ss)) {
            continue;
        }
        foreach ($ss as $sk => $val) {
            $skillScores[$sk]  = ($skillScores[$sk] ?? 0) + (float) ($val['score'] ?? 0);
            $skillTotals[$sk]  = ($skillTotals[$sk] ?? 0) + (float) ($val['total'] ?? 1);
        }
    }

    $gaps = [];
    foreach ($skillScores as $sk => $total) {
        $max = $skillTotals[$sk] ?? 1;
        $gaps[$sk] = $max > 0 ? round((1 - $total / $max) * 100, 1) : 100;
    }

    // Llamar Claude para ranking
    $claudeApiKey = getenv('ANTHROPIC_API_KEY') ?: '';
    if (empty($claudeApiKey)) {
        throw new RuntimeException('No ANTHROPIC_API_KEY');
    }

    $prompt = "You are an English test advisor. Given the student's skill gaps (0=mastered, 100=weakest), "
            . "return a JSON object with skill names as keys and integer quota percentages as values that sum to 100. "
            . "Allocate more questions to weaker skills.\n"
            . "Student gaps: " . json_encode($gaps) . "\n"
            . "Available skills: " . implode(', ', $skills) . "\n"
            . "Respond ONLY with the JSON object.";

    $payload = json_encode([
        'model'      => 'claude-haiku-4-5-20251001',
        'max_tokens' => 256,
        'messages'   => [['role' => 'user', 'content' => $prompt]],
    ]);

    $ch = curl_init('https://api.anthropic.com/v1/messages');
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $payload,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 10,
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            'x-api-key: ' . $claudeApiKey,
            'anthropic-version: 2023-06-01',
        ],
    ]);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode !== 200 || !$response) {
        throw new RuntimeException('Claude API error: ' . $httpCode);
    }

    $body = json_decode($response, true);
    $text = $body['content'][0]['text'] ?? '';

    // Extraer JSON del texto
    preg_match('/\{[^}]+\}/s', $text, $matches);
    $adaptedQuotas = json_decode($matches[0] ?? '{}', true);

    if (!is_array($adaptedQuotas) || empty($adaptedQuotas)) {
        throw new RuntimeException('Claude returned invalid quotas');
    }

    // Normalizar a porcentajes que sumen 100
    $sum = array_sum($adaptedQuotas);
    if ($sum <= 0) {
        throw new RuntimeException('Claude quotas sum to zero');
    }
    foreach ($adaptedQuotas as $sk => $v) {
        $adaptedQuotas[$sk] = (int) round($v / $sum * 100);
    }

    $seed    = md5('ones_exam_v1_' . $studentId . '_attempt_' . $attempt);
    $bySkill = _load_questions_by_skill($pdo, $examId, $unitIds, $skills);
    $quotaCount = _calculate_quotas($totalQuestions, $adaptedQuotas, $skills);

    $selected = [];
    foreach ($quotaCount as $skill => $count) {
        $pool = $bySkill[$skill] ?? [];
        $pool = _seeded_shuffle($pool, $seed . $skill);
        $selected = array_merge($selected, array_slice($pool, 0, $count));
    }

    return _seeded_shuffle($selected, $seed . '_final');
}

// ─── Carga de preguntas desde BD ─────────────────────────────────────────────

function _load_questions_by_skill(PDO $pdo, ?int $examId, array $unitIds, array $skills): array
{
    $bySkill = array_fill_keys($skills, []);

    // 1. Preguntas de eval_questions (examen específico)
    if ($examId !== null) {
        $stmt = $pdo->prepare(
            "SELECT eq.*, ea.answer_text, ea.is_correct, ea.order_index
             FROM eval_questions eq
             LEFT JOIN eval_answers ea ON ea.question_id = eq.id
             WHERE eq.exam_id = ?
             ORDER BY eq.position, eq.id, ea.order_index"
        );
        $stmt->execute([$examId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $questions = [];
        foreach ($rows as $row) {
            $qid = $row['id'];
            if (!isset($questions[$qid])) {
                $questions[$qid] = [
                    'source'   => 'eval_questions',
                    'id'       => $qid,
                    'exam_id'  => $row['exam_id'],
                    'type'     => $row['type'],
                    'skill'    => $row['skill'],
                    'text'     => $row['question_text'],
                    'audio'    => $row['audio_url'],
                    'image'    => $row['image_url'],
                    'points'   => (float) $row['points'],
                    'options'  => [],
                    'correct'  => null,
                    'data'     => is_string($row['data']) ? json_decode($row['data'], true) : ($row['data'] ?? []),
                ];
            }
            if ($row['answer_text'] !== null) {
                $questions[$qid]['options'][] = $row['answer_text'];
                if ($row['is_correct']) {
                    $questions[$qid]['correct'] = $row['answer_text'];
                }
            }
        }

        foreach ($questions as $q) {
            $skill = $q['skill'] ?? 'grammar';
            if (isset($bySkill[$skill])) {
                $bySkill[$skill][] = $q;
            }
        }

        // If eval_questions are defined, use them exclusively
        $totalFound = array_sum(array_map('count', $bySkill));
        if ($totalFound > 0) {
            return $bySkill;
        }
        // No eval_questions yet — fall through to activity-based questions below
        // (use the exam's associated unit_id if available)
        try {
            $unitStmt = $pdo->prepare("SELECT unit_id FROM eval_exams WHERE id=? LIMIT 1");
            $unitStmt->execute([$examId]);
            $unitRow  = $unitStmt->fetch(PDO::FETCH_ASSOC);
            if ($unitRow && $unitRow['unit_id']) {
                $unitIds = [$unitRow['unit_id']];
            }
        } catch (Throwable $_e) {}
    }

    // 2. Preguntas de actividades existentes (activities table)
    if (!empty($unitIds)) {
        $placeholders = implode(',', array_fill(0, count($unitIds), '?'));
        $stmt = $pdo->prepare(
            "SELECT id, type, data FROM activities WHERE unit_id IN ({$placeholders})"
        );
        $stmt->execute($unitIds);
        $activities = $stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($activities as $act) {
            $type  = $act['type'];
            $skill = SKILL_MAP[$type] ?? null;
            if ($skill === null || !isset($bySkill[$skill])) {
                continue;
            }
            $data = is_string($act['data']) ? json_decode($act['data'], true) : ($act['data'] ?? []);
            $questions = _extract_questions_from_activity($act['id'], $type, $skill, $data);
            $bySkill[$skill] = array_merge($bySkill[$skill], $questions);
        }
    }

    return $bySkill;
}

// ─── Extracción de preguntas desde data JSONB de actividades ─────────────────

function _extract_questions_from_activity(int $activityId, string $type, string $skill, ?array $data): array
{
    if (!is_array($data)) {
        return [];
    }

    $questions = [];
    $limit     = MAX_QUESTIONS_PER_ACTIVITY;

    // Determinar la clave que contiene los ítems
    $items = null;
    foreach (['questions', 'items', 'pairs', 'sentences', 'words', 'cards'] as $key) {
        if (isset($data[$key]) && is_array($data[$key])) {
            $items = $data[$key];
            break;
        }
    }

    if (empty($items)) {
        return [];
    }

    $count = 0;
    foreach ($items as $item) {
        if ($count >= $limit || !is_array($item)) {
            break;
        }

        $q = [
            'source'      => 'activities',
            'activity_id' => $activityId,
            'type'        => $type,
            'skill'       => $skill,
            'text'        => $item['question'] ?? $item['text'] ?? $item['word'] ?? $item['front'] ?? '',
            'audio'       => $item['audio'] ?? $item['audio_url'] ?? null,
            'image'       => $item['image'] ?? $item['image_url'] ?? null,
            'options'     => [],
            'correct'     => $item['answer'] ?? $item['correct'] ?? $item['back'] ?? null,
            'data'        => $item,
        ];

        // Normalizar opciones según tipo
        if (in_array($type, ['multiple_choice'], true)) {
            $q['options'] = $item['options'] ?? array_filter([
                $item['option_a'] ?? null,
                $item['option_b'] ?? null,
                $item['option_c'] ?? null,
            ]);
            $q['correct']  = $item['correct'] ?? $item['answer'] ?? null;
        } elseif (in_array($type, ['match', 'matching_lines'], true)) {
            $q['text']    = $item['left']  ?? $q['text'];
            $q['correct'] = $item['right'] ?? $q['correct'];
        } elseif (in_array($type, ['flashcards', 'memory_cards'], true)) {
            $q['text']    = $item['front'] ?? $q['text'];
            $q['correct'] = $item['back']  ?? $q['correct'];
        }

        $questions[] = $q;
        $count++;
    }

    return $questions;
}

// ─── Cálculo de cuotas exactas ────────────────────────────────────────────────

function _calculate_quotas(int $total, array $quotas, array $skills): array
{
    $result    = [];
    $allocated = 0;
    $dominant  = null;
    $maxPct    = -1;

    // Filtrar solo los skills activos
    $activeQuotas = array_intersect_key($quotas, array_flip($skills));
    if (empty($activeQuotas)) {
        $activeQuotas = array_fill_keys($skills, (int) ceil(100 / max(1, count($skills))));
    }

    $sumPct = array_sum($activeQuotas);
    if ($sumPct <= 0) {
        $sumPct = 100;
    }

    foreach ($activeQuotas as $skill => $pct) {
        $count = (int) floor($total * $pct / $sumPct);
        $result[$skill] = $count;
        $allocated += $count;
        if ($pct > $maxPct) {
            $maxPct   = $pct;
            $dominant = $skill;
        }
    }

    // Residuo al skill dominante
    $residue = $total - $allocated;
    if ($residue > 0 && $dominant !== null) {
        $result[$dominant] += $residue;
    }

    return $result;
}

// ─── Fisher-Yates con seed (sin mt_srand global) ─────────────────────────────

function _seeded_shuffle(array $arr, string $seed): array
{
    $n = count($arr);
    if ($n <= 1) {
        return $arr;
    }

    // Generar secuencia pseudo-aleatoria desde el seed
    $hash  = md5($seed);
    $state = [];
    for ($i = 0; $i < 4; $i++) {
        $state[] = hexdec(substr($hash, $i * 8, 8));
    }

    $random = static function () use (&$state): int {
        // xorshift128
        $t = $state[3];
        $t ^= $t << 11;
        $t ^= $t >> 8;
        $state[3] = $state[2];
        $state[2] = $state[1];
        $state[1] = $state[0];
        $s = $state[0];
        $t ^= $s;
        $t ^= $s >> 19;
        $state[0] = $t;
        return abs($t);
    };

    // Fisher-Yates
    for ($i = $n - 1; $i > 0; $i--) {
        $j = $random() % ($i + 1);
        [$arr[$i], $arr[$j]] = [$arr[$j], $arr[$i]];
    }

    return $arr;
}

// ─── Distribución de puntos ───────────────────────────────────────────────────

/**
 * Distribuye 100 puntos equitativamente entre las preguntas.
 */
function _assign_points(array $questions, int $total): array
{
    $n = count($questions);
    if ($n === 0) {
        return [];
    }

    $base    = (int) floor($total / $n);
    $residue = $total - $base * $n;

    foreach ($questions as $i => &$q) {
        $q['points'] = $base + ($i < $residue ? 1 : 0);
    }
    unset($q);

    return $questions;
}

// ─── Serialización / Deserialización ─────────────────────────────────────────

/**
 * Serializa la selección guardando solo metadata (sin question_data completa).
 */
function serialize_exam_selection(array $questions): string
{
    $meta = [];
    foreach ($questions as $q) {
        $meta[] = [
            'source'      => $q['source']      ?? 'unknown',
            'id'          => $q['id']           ?? null,
            'activity_id' => $q['activity_id']  ?? null,
            'type'        => $q['type']         ?? '',
            'skill'       => $q['skill']        ?? '',
            'points'      => $q['points']       ?? 1,
        ];
    }
    return json_encode($meta);
}

/**
 * Recarga question_data desde BD a partir de la serialización.
 */
function load_exam_questions_from_selection(PDO $pdo, string $serialized): array
{
    $meta = json_decode($serialized, true);
    if (!is_array($meta)) {
        return [];
    }

    $questions = [];

    foreach ($meta as $m) {
        $source = $m['source'] ?? '';
        $points = (float) ($m['points'] ?? 1);

        if ($source === 'eval_questions' && isset($m['id'])) {
            $stmt = $pdo->prepare(
                "SELECT eq.*, ea.answer_text, ea.is_correct, ea.order_index
                 FROM eval_questions eq
                 LEFT JOIN eval_answers ea ON ea.question_id = eq.id
                 WHERE eq.id = ?
                 ORDER BY ea.order_index"
            );
            $stmt->execute([$m['id']]);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

            if (empty($rows)) {
                continue;
            }

            $row = $rows[0];
            $q = [
                'source'  => 'eval_questions',
                'id'      => $row['id'],
                'type'    => $row['type'],
                'skill'   => $row['skill'],
                'text'    => $row['question_text'],
                'audio'   => $row['audio_url'],
                'image'   => $row['image_url'],
                'points'  => $points,
                'options' => [],
                'correct' => null,
                'data'    => is_string($row['data']) ? json_decode($row['data'], true) : ($row['data'] ?? []),
            ];

            foreach ($rows as $r) {
                if ($r['answer_text'] !== null) {
                    $q['options'][] = $r['answer_text'];
                    if ($r['is_correct']) {
                        $q['correct'] = $r['answer_text'];
                    }
                }
            }

            $q['points'] = $points;
            $questions[] = $q;

        } elseif ($source === 'activities' && isset($m['activity_id'])) {
            $stmt = $pdo->prepare("SELECT id, type, data FROM activities WHERE id = ?");
            $stmt->execute([$m['activity_id']]);
            $act = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$act) {
                continue;
            }

            $data = is_string($act['data']) ? json_decode($act['data'], true) : ($act['data'] ?? []);
            $skill = SKILL_MAP[$act['type']] ?? ($m['skill'] ?? 'grammar');
            $extracted = _extract_questions_from_activity($act['id'], $act['type'], $skill, $data);

            foreach ($extracted as &$eq) {
                $eq['points'] = $points;
            }
            unset($eq);

            $questions = array_merge($questions, $extracted);
        }
    }

    return $questions;
}

