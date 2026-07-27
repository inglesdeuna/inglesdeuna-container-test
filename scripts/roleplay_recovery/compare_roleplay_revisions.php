<?php

declare(strict_types=1);
require __DIR__ . '/roleplay_recovery_lib.php';

try {
    $pdo = rp_recovery_pdo();
    $args = rp_args($argv);
    $ids = rp_selected_ids($args);
    $sql = "SELECT a.id, a.data AS current_data, r.id AS revision_id, r.data AS revision_data,
                   r.created_at AS revision_created_at
            FROM activities a
            LEFT JOIN roleplay_revisions r ON r.activity_id = a.id
            WHERE lower(a.type) = 'roleplay'";
    $params = [];
    if ($ids !== null) {
        $sql .= ' AND a.id = ANY(CAST(:ids AS integer[]))';
        $params['ids'] = '{' . implode(',', array_map(static fn(string $id): string => '"' . str_replace('"', '\"', $id) . '"', $ids)) . '}';
    }
    $sql .= ' ORDER BY a.id, r.created_at, r.id';
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $result = [];
    foreach ($stmt as $row) {
        $current = rp_dialogue_summary(rp_json($row['current_data'] ?? null));
        $revision = $row['revision_id'] === null ? null : rp_dialogue_summary(rp_json($row['revision_data'] ?? null));
        $result[] = [
            'activity_id' => (string)$row['id'],
            'revision_id' => $row['revision_id'] === null ? null : (string)$row['revision_id'],
            'revision_created_at' => $row['revision_created_at'],
            'current_turn_count' => $current['turn_count'],
            'revision_turn_count' => $revision['turn_count'] ?? null,
            'likely_loss' => $revision !== null && $revision['turn_count'] > $current['turn_count'],
            'current_dialogue_sha256' => $current['dialogue_sha256'],
            'revision_dialogue_sha256' => $revision['dialogue_sha256'] ?? null,
        ];
    }
    rp_write_json(['read_only' => true, 'comparisons' => $result]);
} catch (Throwable $e) {
    fwrite(STDERR, $e->getMessage() . PHP_EOL);
    exit(1);
}
