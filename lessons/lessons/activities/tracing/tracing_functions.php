<?php
// Funciones compartidas para actividades de tracing

function default_tracing_title(): string { return 'Tracing'; }

function normalize_tracing_title(string $title): string {
    $title = trim($title);
    return $title !== '' ? $title : default_tracing_title();
}

function normalize_tracing_payload($rawData): array {
    $default = array('title' => default_tracing_title(), 'images' => array());
    if ($rawData === null || $rawData === '') return $default;
    $decoded = is_string($rawData) ? json_decode($rawData, true) : $rawData;
    if (!is_array($decoded)) return $default;
    $title = '';
    $imagesSource = $decoded;
    if (isset($decoded['title'])) $title = trim((string) $decoded['title']);
    if (isset($decoded['images']) && is_array($decoded['images'])) $imagesSource = $decoded['images'];
    $images = array();
    if (is_array($imagesSource)) {
        foreach ($imagesSource as $item) {
            if (!is_array($item)) continue;
            $images[] = array(
                'id' => isset($item['id']) ? trim((string) $item['id']) : uniqid('tracing_'),
                'image' => isset($item['image']) ? trim((string) $item['image']) : '',
            );
        }
    }
    return array('title' => normalize_tracing_title($title), 'images' => $images);
}

function activities_columns(PDO $pdo): array {
    static $cache = null;
    if (is_array($cache)) return $cache;
    $cache = array();
    $stmt = $pdo->query("SELECT column_name FROM information_schema.columns WHERE table_schema = 'public' AND table_name = 'activities'");
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        if (isset($row['column_name'])) $cache[] = (string) $row['column_name'];
    }
    return $cache;
}

function load_tracing_activity(PDO $pdo, string $unit, string $activityId): array {
    $columns = activities_columns($pdo);
    $selectFields = array('id');
    if (in_array('data', $columns, true)) $selectFields[] = 'data';
    if (in_array('content_json', $columns, true)) $selectFields[] = 'content_json';
    if (in_array('title', $columns, true)) $selectFields[] = 'title';
    if (in_array('name', $columns, true)) $selectFields[] = 'name';
    $fallback = array('id' => '', 'title' => default_tracing_title(), 'images' => array());
    $row = null;
    if ($activityId !== '') {
        $stmt = $pdo->prepare("SELECT " . implode(', ', $selectFields) . " FROM activities WHERE id = :id AND type = 'tracing' LIMIT 1");
        $stmt->execute(array('id' => $activityId));
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
    }
    if (!$row && in_array('unit_id', $columns, true)) {
        $stmt = $pdo->prepare("SELECT " . implode(', ', $selectFields) . " FROM activities WHERE unit_id = :unit AND type = 'tracing' ORDER BY id ASC LIMIT 1");
        $stmt->execute(array('unit' => $unit));
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
    }
    if (!$row && in_array('unit', $columns, true)) {
        $stmt = $pdo->prepare("SELECT " . implode(', ', $selectFields) . " FROM activities WHERE unit = :unit AND type = 'tracing' ORDER BY id ASC LIMIT 1");
        $stmt->execute(array('unit' => $unit));
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
    }
    if (!$row) return $fallback;
    $rawData = null;
    if (isset($row['data'])) $rawData = $row['data'];
    elseif (isset($row['content_json'])) $rawData = $row['content_json'];
    $payload = normalize_tracing_payload($rawData);
    $columnTitle = '';
    if (isset($row['title']) && trim((string) $row['title']) !== '') $columnTitle = trim((string) $row['title']);
    elseif (isset($row['name']) && trim((string) $row['name']) !== '') $columnTitle = trim((string) $row['name']);
    if ($columnTitle !== '') $payload['title'] = $columnTitle;
    return array('id' => isset($row['id']) ? (string) $row['id'] : '', 'title' => normalize_tracing_title((string) $payload['title']), 'images' => isset($payload['images']) && is_array($payload['images']) ? $payload['images'] : array());
}
