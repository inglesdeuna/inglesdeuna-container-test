<?php
// One-off migration: replace legacy pronunciation voice IDs with new voices.
// Run in an environment where DATABASE_URL is available (e.g., Render shell).

require __DIR__ . '/../lessons/lessons/config/db.php';

$oldToNew = [
    'JBFqnCBsd6RMkjVDRZzb' => 'nzFihrBIvB34imQBuxub', // George -> Josh
    '21m00Tcm4TlvDq8ikWAM' => 'NoOVOzCQFLOvtsMoNcdT', // Rachel -> Lily
    'pFZP5JQG7iQjIQuC4Bku' => 'Nggzl2QAXh3OijoXD116', // old child -> Candy
];

$types = ['pronunciation'];
$placeholders = implode(',', array_fill(0, count($types), '?'));

$selectSql = "SELECT id, data FROM activities WHERE type IN ($placeholders)";
$select = $pdo->prepare($selectSql);
$select->execute($types);
$rows = $select->fetchAll(PDO::FETCH_ASSOC);

$totalActivities = count($rows);
$changedActivities = 0;
$changedItems = 0;

$update = $pdo->prepare('UPDATE activities SET data = :data WHERE id = :id');

$pdo->beginTransaction();
try {
    foreach ($rows as $row) {
        $id = (string)($row['id'] ?? '');
        $raw = $row['data'] ?? null;
        if (!is_string($raw) || trim($raw) === '') {
            continue;
        }

        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            continue;
        }

        $itemsRef = null;
        if (isset($decoded['items']) && is_array($decoded['items'])) {
            $itemsRef = &$decoded['items'];
        } elseif (isset($decoded['data']) && is_array($decoded['data'])) {
            $itemsRef = &$decoded['data'];
        } elseif (isset($decoded['words']) && is_array($decoded['words'])) {
            $itemsRef = &$decoded['words'];
        } elseif (array_values($decoded) === $decoded) {
            // Legacy format where root is an array of items.
            $itemsRef = &$decoded;
        }

        if (!is_array($itemsRef)) {
            continue;
        }

        $activityChanged = false;
        foreach ($itemsRef as &$item) {
            if (!is_array($item)) {
                continue;
            }

            $current = isset($item['voice_id']) ? trim((string)$item['voice_id']) : '';
            if ($current !== '' && isset($oldToNew[$current])) {
                $item['voice_id'] = $oldToNew[$current];
                $activityChanged = true;
                $changedItems++;
            }
        }
        unset($item);

        if ($activityChanged) {
            $newData = json_encode($decoded, JSON_UNESCAPED_UNICODE);
            if (is_string($newData) && $newData !== '') {
                $update->execute([
                    'data' => $newData,
                    'id' => $id,
                ]);
                $changedActivities++;
            }
        }
    }

    $pdo->commit();
} catch (Throwable $e) {
    $pdo->rollBack();
    fwrite(STDERR, 'Migration failed: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}

echo 'Pronunciation voice migration complete' . PHP_EOL;
echo 'Activities scanned: ' . $totalActivities . PHP_EOL;
echo 'Activities updated: ' . $changedActivities . PHP_EOL;
echo 'Items updated: ' . $changedItems . PHP_EOL;
