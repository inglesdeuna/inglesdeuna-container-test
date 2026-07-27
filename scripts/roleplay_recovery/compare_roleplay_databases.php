<?php

declare(strict_types=1);
require __DIR__ . '/roleplay_recovery_lib.php';

try {
    $args = rp_args($argv);
    $historicalUrl = $args['historical-url'] ?? getenv('ROLEPLAY_HISTORICAL_DATABASE_URL');
    if (!$historicalUrl) {
        throw new RuntimeException('Use --historical-url=URL or ROLEPLAY_HISTORICAL_DATABASE_URL.');
    }
    $current = rp_recovery_pdo();
    $historical = rp_recovery_pdo($historicalUrl);
    $ids = rp_selected_ids($args);
    $sql = "SELECT id, unit_id, data FROM activities WHERE lower(type) = 'roleplay'";
    $params = [];
    if ($ids !== null) {
        $sql .= ' AND id = ANY(:ids)';
        $params['ids'] = '{' . implode(',', array_map(static fn(string $id): string => '"' . str_replace('"', '\"', $id) . '"', $ids)) . '}';
    }
    $sql .= ' ORDER BY id';
    $load = static function (PDO $db) use ($sql, $params): array {
        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        $rows = [];
        foreach ($stmt as $row) {
            $rows[(string)$row['id']] = [
                'unit_id' => $row['unit_id'],
                'data' => rp_json($row['data'] ?? null),
            ];
        }
        return $rows;
    };
    $currentRows = $load($current);
    $historicalRows = $load($historical);
    $idsToCompare = array_values(array_unique(array_merge(array_keys($currentRows), array_keys($historicalRows))));
    $comparisons = [];
    foreach ($idsToCompare as $id) {
        $c = $currentRows[$id] ?? null;
        $h = $historicalRows[$id] ?? null;
        $cs = $c ? rp_dialogue_summary($c['data']) : null;
        $hs = $h ? rp_dialogue_summary($h['data']) : null;
        $comparisons[] = [
            'activity_id' => $id,
            'current_exists' => $c !== null,
            'historical_exists' => $h !== null,
            'current_turn_count' => $cs['turn_count'] ?? null,
            'historical_turn_count' => $hs['turn_count'] ?? null,
            'likely_loss' => $cs !== null && $hs !== null && $hs['turn_count'] > $cs['turn_count'],
            'current_dialogue_sha256' => $cs['dialogue_sha256'] ?? null,
            'historical_dialogue_sha256' => $hs['dialogue_sha256'] ?? null,
        ];
    }
    rp_write_json(['read_only' => true, 'current_count' => count($currentRows), 'historical_count' => count($historicalRows), 'comparisons' => $comparisons]);
} catch (Throwable $e) {
    fwrite(STDERR, $e->getMessage() . PHP_EOL);
    exit(1);
}
