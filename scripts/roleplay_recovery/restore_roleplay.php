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
    $sql = "SELECT id, data FROM activities WHERE lower(type) = 'roleplay'";
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
            $rows[(string)$row['id']] = rp_json($row['data'] ?? null);
        }
        return $rows;
    };
    $currentRows = $load($current);
    $historicalRows = $load($historical);
    $candidates = [];
    foreach ($historicalRows as $id => $historicalData) {
        if (!isset($currentRows[$id])) {
            continue;
        }
        $currentSummary = rp_dialogue_summary($currentRows[$id]);
        $historicalSummary = rp_dialogue_summary($historicalData);
        if ($historicalSummary['turn_count'] <= $currentSummary['turn_count']) {
            continue;
        }
        $restored = rp_merge_historical_turns($currentRows[$id], $historicalData);
        $candidates[] = [
            'activity_id' => $id,
            'before_turn_count' => $currentSummary['turn_count'],
            'after_turn_count' => count(rp_turns($restored)),
            'historical_dialogue_sha256' => $historicalSummary['dialogue_sha256'],
            'data' => $restored,
        ];
    }
    $dryRun = !empty($args['dry-run']) || empty($args['apply']);
    if (!$dryRun && $candidates) {
        $update = $current->prepare('UPDATE activities SET data = CAST(:data AS jsonb) WHERE id = :id AND lower(type) = \'roleplay\'');
        $current->beginTransaction();
        try {
            foreach ($candidates as $candidate) {
                $json = json_encode($candidate['data'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
                $update->execute(['id' => $candidate['activity_id'], 'data' => $json]);
            }
            $current->commit();
        } catch (Throwable $e) {
            $current->rollBack();
            throw $e;
        }
    }
    foreach ($candidates as &$candidate) {
        unset($candidate['data']);
    }
    unset($candidate);
    rp_write_json(['dry_run' => $dryRun, 'updated' => $dryRun ? 0 : count($candidates), 'candidates' => $candidates]);
} catch (Throwable $e) {
    fwrite(STDERR, $e->getMessage() . PHP_EOL);
    exit(1);
}
