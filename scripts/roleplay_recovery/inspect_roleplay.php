<?php

declare(strict_types=1);
require __DIR__ . '/roleplay_recovery_lib.php';

try {
    $pdo = rp_recovery_pdo();
    $args = rp_args($argv);
    $ids = rp_selected_ids($args);
    $sql = "SELECT id, unit_id, type, data, created_at FROM activities WHERE lower(type) = 'roleplay'";
    $params = [];
    if ($ids !== null) {
        $sql .= ' AND id = ANY(CAST(:ids AS integer[]))';
        $params['ids'] = '{' . implode(',', array_map(static fn(string $id): string => '"' . str_replace('"', '\"', $id) . '"', $ids)) . '}';
    }
    $sql .= ' ORDER BY id';
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $result = [];
    foreach ($stmt as $row) {
        $data = rp_json($row['data'] ?? null);
        $summary = rp_dialogue_summary($data);
        if (!empty($args['full'])) {
            $summary['data'] = $data;
        }
        $result[] = [
            'id' => (string)$row['id'],
            'unit_id' => $row['unit_id'],
            'created_at' => $row['created_at'],
            'data_valid' => $data !== [],
        ] + $summary;
    }
    rp_write_json(['read_only' => true, 'count' => count($result), 'activities' => $result]);
} catch (Throwable $e) {
    fwrite(STDERR, $e->getMessage() . PHP_EOL);
    exit(1);
}
