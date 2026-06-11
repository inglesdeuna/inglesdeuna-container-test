<?php
/**
 * exam_usage_examples.php
 *
 * Ejemplos de integración del módulo de evaluaciones.
 * SOLO para referencia — NO se carga en producción.
 */

declare(strict_types=1);

require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/exam_question_selector.php';

// ─── Ejemplo 1: Generar link de grupo ────────────────────────────────────────

function example_generate_group_link(PDO $pdo, string $examId): array
{
    $token = bin2hex(random_bytes(16)); // 32 chars hex

    $stmt = $pdo->prepare(
        "INSERT INTO eval_links
            (exam_id, token, link_type, max_uses, expires_at, created_by)
         VALUES (?, ?, 'group', 999, NOW() + INTERVAL '30 days', 'admin')
         RETURNING id, token"
    );
    $stmt->execute([$examId, $token]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    $url = 'https://tudominio.com/lessons/lessons/activities/eval/eval_viewer.php?t=' . $token;

    return [
        'link_id' => $row['id'],
        'token'   => $row['token'],
        'url'     => $url,
    ];
}

// ─── Ejemplo 2: Estudiante inicia examen ─────────────────────────────────────

function example_student_starts_exam(PDO $pdo, string $token, string $name, string $doc): array
{
    // Validar link
    $stmt = $pdo->prepare(
        "SELECT * FROM eval_links
         WHERE token = ?
           AND (expires_at IS NULL OR expires_at > NOW())
           AND uses_count < max_uses
         LIMIT 1"
    );
    $stmt->execute([$token]);
    $link = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$link) {
        return ['error' => 'Link inválido o expirado'];
    }

    $examId = (int) $link['exam_id'];

    // Obtener historial del estudiante en este examen
    $histStmt = $pdo->prepare(
        "SELECT skill_scores, answers_json FROM eval_results
         WHERE exam_id = ? AND student_doc = ? AND status = 'submitted'
         ORDER BY submitted_at DESC"
    );
    $histStmt->execute([$examId, $doc]);
    $history = $histStmt->fetchAll(PDO::FETCH_ASSOC);

    $attempt = count($history) + 1;

    // Seleccionar preguntas
    $examConfig = [
        'exam_id'         => $examId,
        'total_questions' => 20,
        'quotas'          => DEFAULT_QUOTAS,
        'skills'          => array_keys(DEFAULT_QUOTAS),
    ];

    $questions       = select_exam_questions($pdo, $examConfig, $doc, $attempt, $history);
    $selectionJson   = serialize_exam_selection($questions);

    // Crear registro en eval_results
    $insStmt = $pdo->prepare(
        "INSERT INTO eval_results
            (exam_id, link_id, student_name, student_doc, modality,
             selection_json, status, started_at)
         VALUES (?, ?, ?, ?, 'online', ?, 'started', CURRENT_TIMESTAMP)
         RETURNING id"
    );
    $insStmt->execute([$examId, $link['id'], $name, $doc, $selectionJson]);
    $result = $insStmt->fetch(PDO::FETCH_ASSOC);

    // Incrementar uses_count
    $pdo->prepare("UPDATE eval_links SET uses_count = uses_count + 1 WHERE id = ?")
        ->execute([$link['id']]);

    return [
        'result_id'  => $result['id'],
        'questions'  => $questions,
        'exam_id'    => $examId,
    ];
}

// ─── Ejemplo 3: Enviar respuestas y calificar ─────────────────────────────────

function example_submit_exam(PDO $pdo, int $resultId, array $answers): array
{
    // Cargar resultado
    $stmt = $pdo->prepare("SELECT * FROM eval_results WHERE id = ? LIMIT 1");
    $stmt->execute([$resultId]);
    $result = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$result) {
        return ['error' => 'Resultado no encontrado'];
    }

    // Recargar preguntas desde selección serializada
    $questions = load_exam_questions_from_selection($pdo, $result['selection_json'] ?? '');

    // Calificar
    $totalScore   = 0.0;
    $maxScore     = 0.0;
    $skillScores  = [];
    $answersLog   = [];

    foreach ($questions as $i => $q) {
        $qKey     = $q['type'] . '_' . $i;
        $given    = $answers[$i] ?? $answers[$qKey] ?? null;
        $correct  = $q['correct'] ?? null;
        $pts      = (float) ($q['points'] ?? 1);
        $skill    = $q['skill'] ?? 'grammar';

        $isCorrect = ($given !== null && $correct !== null
            && mb_strtolower(trim((string) $given)) === mb_strtolower(trim((string) $correct)));

        $earned = $isCorrect ? $pts : 0.0;

        $skillScores[$skill] = $skillScores[$skill] ?? ['score' => 0, 'total' => 0];
        $skillScores[$skill]['score'] += $earned;
        $skillScores[$skill]['total'] += $pts;

        $totalScore += $earned;
        $maxScore   += $pts;

        $answersLog[] = [
            'q'          => $i,
            'type'       => $q['type'],
            'skill'      => $skill,
            'given'      => $given,
            'correct'    => $correct,
            'is_correct' => $isCorrect,
            'pts_earned' => $earned,
            'pts_max'    => $pts,
        ];
    }

    $pct          = $maxScore > 0 ? round($totalScore / $maxScore * 100, 2) : 0;
    $cefrSuggested = _suggest_cefr($pdo, (int) ($result['exam_id'] ?? 0), $pct);

    // Actualizar eval_results
    $upd = $pdo->prepare(
        "UPDATE eval_results SET
            score = ?, max_score = ?, pct = ?, cefr_suggested = ?,
            answers_json = ?, skill_scores = ?,
            status = 'submitted', submitted_at = CURRENT_TIMESTAMP
         WHERE id = ?"
    );
    $upd->execute([
        $totalScore,
        $maxScore,
        $pct,
        $cefrSuggested,
        json_encode($answersLog),
        json_encode($skillScores),
        $resultId,
    ]);

    return [
        'score'          => $totalScore,
        'max_score'      => $maxScore,
        'pct'            => $pct,
        'cefr_suggested' => $cefrSuggested,
        'skill_scores'   => $skillScores,
    ];
}

// ─── Ejemplo 4: Registrar nota impresa ───────────────────────────────────────

function example_register_printed_score(
    PDO $pdo,
    int $examId,
    string $name,
    string $doc,
    float $score,
    float $max
): array {
    $pct          = $max > 0 ? round($score / $max * 100, 2) : 0;
    $cefrSuggested = _suggest_cefr($pdo, $examId, $pct);

    $stmt = $pdo->prepare(
        "INSERT INTO eval_results
            (exam_id, student_name, student_doc, modality,
             score, max_score, pct, cefr_suggested,
             status, submitted_at)
         VALUES (?, ?, ?, 'printed', ?, ?, ?, ?, 'submitted', CURRENT_TIMESTAMP)
         RETURNING id"
    );
    $stmt->execute([$examId, $name, $doc, $score, $max, $pct, $cefrSuggested]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    return [
        'result_id'      => $row['id'],
        'pct'            => $pct,
        'cefr_suggested' => $cefrSuggested,
    ];
}

// ─── Helper: sugerir nivel MCER ──────────────────────────────────────────────

function _suggest_cefr(PDO $pdo, int $examId, float $pct): string
{
    // Primero buscar rangos específicos del examen
    if ($examId > 0) {
        $stmt = $pdo->prepare(
            "SELECT cefr_level FROM eval_cefr_ranges
             WHERE exam_id = ? AND ? BETWEEN min_pct AND max_pct
             ORDER BY min_pct LIMIT 1"
        );
        $stmt->execute([$examId, $pct]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row) {
            return $row['cefr_level'];
        }
    }

    // Fallback a rangos globales
    $stmt = $pdo->prepare(
        "SELECT cefr_level FROM eval_cefr_ranges
         WHERE is_global = TRUE AND ? BETWEEN min_pct AND max_pct
         ORDER BY min_pct LIMIT 1"
    );
    $stmt->execute([$pct]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ? $row['cefr_level'] : 'A1';
}
