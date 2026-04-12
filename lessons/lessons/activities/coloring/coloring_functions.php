<?php

function default_coloring_title(): string { return 'Coloring Page'; }

function normalize_coloring_title(string $title): string {
    $title = trim($title);
    return $title !== '' ? $title : default_coloring_title();
}

function normalize_coloring_payload($rawData): array {
    $default = array('title' => default_coloring_title(), 'images' => array());
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
                'id' => isset($item['id']) ? trim((string) $item['id']) : uniqid('coloring_'),
                'image' => isset($item['image']) ? trim((string) $item['image']) : '',
            );
        }
    }
    return array('title' => normalize_coloring_title($title), 'images' => $images);
}

function coloring_activities_columns(PDO $pdo): array {
    static $cache = null;
    if (is_array($cache)) return $cache;
    $cache = array();
    $stmt = $pdo->query("SELECT column_name FROM information_schema.columns WHERE table_schema = 'public' AND table_name = 'activities'");
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        if (isset($row['column_name'])) $cache[] = (string) $row['column_name'];
    }
    return $cache;
}

function load_coloring_activity(PDO $pdo, string $unit, string $activityId): array {
    $columns = coloring_activities_columns($pdo);
    $selectFields = array('id');
    if (in_array('data', $columns, true)) $selectFields[] = 'data';
    if (in_array('content_json', $columns, true)) $selectFields[] = 'content_json';
    if (in_array('title', $columns, true)) $selectFields[] = 'title';
    if (in_array('name', $columns, true)) $selectFields[] = 'name';
    $fallback = array('id' => '', 'title' => default_coloring_title(), 'images' => array());
    $row = null;
    if ($activityId !== '') {
        $stmt = $pdo->prepare("SELECT " . implode(', ', $selectFields) . " FROM activities WHERE id = :id AND type = 'coloring' LIMIT 1");
        $stmt->execute(array('id' => $activityId));
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
    }
    if (!$row && in_array('unit_id', $columns, true)) {
        $stmt = $pdo->prepare("SELECT " . implode(', ', $selectFields) . " FROM activities WHERE unit_id = :unit AND type = 'coloring' ORDER BY id ASC LIMIT 1");
        $stmt->execute(array('unit' => $unit));
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
    }
    if (!$row && in_array('unit', $columns, true)) {
        $stmt = $pdo->prepare("SELECT " . implode(', ', $selectFields) . " FROM activities WHERE unit = :unit AND type = 'coloring' ORDER BY id ASC LIMIT 1");
        $stmt->execute(array('unit' => $unit));
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
    }
    if (!$row) return $fallback;
    $rawData = null;
    if (isset($row['data'])) $rawData = $row['data'];
    elseif (isset($row['content_json'])) $rawData = $row['content_json'];
    $payload = normalize_coloring_payload($rawData);
    $columnTitle = '';
    if (isset($row['title']) && trim((string) $row['title']) !== '') $columnTitle = trim((string) $row['title']);
    elseif (isset($row['name']) && trim((string) $row['name']) !== '') $columnTitle = trim((string) $row['name']);
    if ($columnTitle !== '') $payload['title'] = $columnTitle;
    return array('id' => isset($row['id']) ? (string) $row['id'] : '', 'title' => normalize_coloring_title((string) $payload['title']), 'images' => isset($payload['images']) && is_array($payload['images']) ? $payload['images'] : array());
}

function save_coloring_activity(PDO $pdo, string $unit, string $activityId, string $title, array $images): string {
    $columns = coloring_activities_columns($pdo);
    $title = normalize_coloring_title($title);
    $json = json_encode([
        'title' => $title,
        'images' => array_values($images),
    ], JSON_UNESCAPED_UNICODE);

    $hasUnitId = in_array('unit_id', $columns, true);
    $hasUnit = in_array('unit', $columns, true);
    $hasData = in_array('data', $columns, true);
    $hasContentJson = in_array('content_json', $columns, true);
    $hasId = in_array('id', $columns, true);
    $hasTitle = in_array('title', $columns, true);
    $hasName = in_array('name', $columns, true);

    $targetId = $activityId;
    if ($targetId === '') {
        if ($hasUnitId) {
            $stmt = $pdo->prepare("SELECT id FROM activities WHERE unit_id = :unit AND type = 'coloring' ORDER BY id ASC LIMIT 1");
            $stmt->execute(['unit' => $unit]);
            $targetId = trim((string) $stmt->fetchColumn());
        }
        if ($targetId === '' && $hasUnit) {
            $stmt = $pdo->prepare("SELECT id FROM activities WHERE unit = :unit AND type = 'coloring' ORDER BY id ASC LIMIT 1");
            $stmt->execute(['unit' => $unit]);
            $targetId = trim((string) $stmt->fetchColumn());
        }
    }

    if ($targetId !== '') {
        $setParts = [];
        $params = ['id' => $targetId];
        if ($hasData) { $setParts[] = 'data = :data'; $params['data'] = $json; }
        if ($hasContentJson) { $setParts[] = 'content_json = :content_json'; $params['content_json'] = $json; }
        if ($hasTitle) { $setParts[] = 'title = :title'; $params['title'] = $title; }
        if ($hasName) { $setParts[] = 'name = :name'; $params['name'] = $title; }
        if (!empty($setParts)) {
            $stmt = $pdo->prepare("UPDATE activities SET " . implode(', ', $setParts) . " WHERE id = :id AND type = 'coloring'");
            $stmt->execute($params);
        }
        return $targetId;
    }

    $insertColumns = [];
    $insertValues = [];
    $params = [];
    $newId = '';
    if ($hasId) {
        $newId = md5(random_bytes(16));
        $insertColumns[] = 'id';
        $insertValues[] = ':id';
        $params['id'] = $newId;
    }
    if ($hasUnitId) {
        $insertColumns[] = 'unit_id';
        $insertValues[] = ':unit_id';
        $params['unit_id'] = $unit;
    } elseif ($hasUnit) {
        $insertColumns[] = 'unit';
        $insertValues[] = ':unit';
        $params['unit'] = $unit;
    }
    $insertColumns[] = 'type';
    $insertValues[] = "'coloring'";
    if ($hasData) {
        $insertColumns[] = 'data';
        $insertValues[] = ':data';
        $params['data'] = $json;
    }
    if ($hasContentJson) {
        $insertColumns[] = 'content_json';
        $insertValues[] = ':content_json';
        $params['content_json'] = $json;
    }
    if ($hasTitle) {
        $insertColumns[] = 'title';
        $insertValues[] = ':title';
        $params['title'] = $title;
    }
    if ($hasName) {
        $insertColumns[] = 'name';
        $insertValues[] = ':name';
        $params['name'] = $title;
    }
    $stmt = $pdo->prepare(
        "INSERT INTO activities (" . implode(', ', $insertColumns) . ") VALUES (" . implode(', ', $insertValues) . ") RETURNING id"
    );
    $stmt->execute($params);
    $insertedId = $stmt->fetchColumn();
    return $insertedId ? (string)$insertedId : $newId;
}