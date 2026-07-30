<?php
/**
 * admin_eval.php
 * Entry point for the Evaluaciones / Placement module.
 * All logic and UI are in admin_eval_base.php.
 */

// When Results is opened without an exam selected, automatically load the
// exam with the most recent submitted result instead of showing an empty page.
if (($_GET['tab'] ?? '') === 'results' && empty($_GET['exam_id'])) {
    require_once __DIR__ . '/../../config/db.php';
    try {
        $stmt = $pdo->query("SELECT exam_id
            FROM eval_results
            WHERE status = 'submitted'
            ORDER BY submitted_at DESC NULLS LAST, id DESC
            LIMIT 1");
        $latestExamId = (int)($stmt->fetchColumn() ?: 0);
        if ($latestExamId > 0) {
            $_GET['exam_id'] = $latestExamId;
        }
    } catch (Throwable $e) {
        // The base page will keep its normal empty-state behavior.
    }
}

require __DIR__ . '/admin_eval_base.php';
