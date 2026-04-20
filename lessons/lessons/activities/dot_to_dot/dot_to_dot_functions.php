<?php

function default_dot_to_dot_title(): string
{
    return 'Dot to Dot';
}

function normalize_dot_to_dot_title(string $title): string
{
    $title = trim($title);
    return $title !== '' ? $title : default_dot_to_dot_title();
}

function default_dot_to_dot_label_settings(): array
{
    return array(
        'mode' => 'number',
        'start' => 1,
        'step' => 1,
        'end' => 20,
    );
}

function normalize_dot_to_dot_label_settings($rawSettings, int $pointsCount = 0): array
{
    $default = default_dot_to_dot_label_settings();

    $settings = is_array($rawSettings) ? $rawSettings : array();

    $mode = isset($settings['mode']) ? strtolower(trim((string) $settings['mode'])) : $default['mode'];
    if (!in_array($mode, array('number', 'letter', 'word'), true)) {
        $mode = $default['mode'];
    }

    $start = isset($settings['start']) ? (int) $settings['start'] : $default['start'];
    if ($start < 1) {
        $start = 1;
    }

    $step = isset($settings['step']) ? (int) $settings['step'] : $default['step'];
    if ($step < 1) {
        $step = 1;
    }

    $end = isset($settings['end']) ? (int) $settings['end'] : 0;
    if ($end < $start) {
        if ($pointsCount > 0) {
            $end = $start + (($pointsCount - 1) * $step);
        } else {
            $end = max($default['end'], $start);
        }
    }

    return array(
        'mode' => $mode,
        'start' => $start,
        'step' => $step,
        'end' => $end,
    );
}

function dot_to_dot_label_value_by_index(array $settings, int $index): int
{
    $start = (int) ($settings['start'] ?? 1);
    $step = (int) ($settings['step'] ?? 1);

    if ($start < 1) {
        $start = 1;
    }
    if ($step < 1) {
        $step = 1;
    }

    return $start + ($index * $step);
}

function dot_to_dot_number_to_letters(int $value): string
{
    if ($value < 1) {
        return (string) $value;
    }

    $letters = '';
    $n = $value;

    while ($n > 0) {
        $n--;
        $letters = chr(65 + ($n % 26)) . $letters;
        $n = (int) floor($n / 26);
    }

    return $letters;
}

function dot_to_dot_number_to_words_en(int $value): string
{
    if ($value < 0) {
        return 'minus ' . dot_to_dot_number_to_words_en(abs($value));
    }

    $ones = array(
        0 => 'zero', 1 => 'one', 2 => 'two', 3 => 'three', 4 => 'four',
        5 => 'five', 6 => 'six', 7 => 'seven', 8 => 'eight', 9 => 'nine',
        10 => 'ten', 11 => 'eleven', 12 => 'twelve', 13 => 'thirteen', 14 => 'fourteen',
        15 => 'fifteen', 16 => 'sixteen', 17 => 'seventeen', 18 => 'eighteen', 19 => 'nineteen',
    );

    $tens = array(
        2 => 'twenty', 3 => 'thirty', 4 => 'forty', 5 => 'fifty',
        6 => 'sixty', 7 => 'seventy', 8 => 'eighty', 9 => 'ninety',
    );

    if ($value < 20) {
        return $ones[$value];
    }

    if ($value < 100) {
        $ten = (int) floor($value / 10);
        $rest = $value % 10;
        return $rest === 0 ? $tens[$ten] : ($tens[$ten] . '-' . $ones[$rest]);
    }

    if ($value < 1000) {
        $hundred = (int) floor($value / 100);
        $rest = $value % 100;
        if ($rest === 0) {
            return $ones[$hundred] . ' hundred';
        }
        return $ones[$hundred] . ' hundred ' . dot_to_dot_number_to_words_en($rest);
    }

    if ($value < 1000000) {
        $thousand = (int) floor($value / 1000);
        $rest = $value % 1000;
        if ($rest === 0) {
            return dot_to_dot_number_to_words_en($thousand) . ' thousand';
        }
        return dot_to_dot_number_to_words_en($thousand) . ' thousand ' . dot_to_dot_number_to_words_en($rest);
    }

    return (string) $value;
}

function dot_to_dot_label_text(array $settings, int $index): string
{
    $mode = (string) ($settings['mode'] ?? 'number');
    $value = dot_to_dot_label_value_by_index($settings, $index);

    if ($mode === 'letter') {
        return dot_to_dot_number_to_letters($value);
    }

    if ($mode === 'word') {
        return dot_to_dot_number_to_words_en($value);
    }

    return (string) $value;
}

function dot_to_dot_apply_labels(array $points, array $settings): array
{
    $labeled = array();

    foreach (array_values($points) as $index => $point) {
        if (!is_array($point)) {
            continue;
        }

        $x = isset($point['x']) ? (float) $point['x'] : -1;
        $y = isset($point['y']) ? (float) $point['y'] : -1;
        if ($x < 0 || $x > 1 || $y < 0 || $y > 1) {
            continue;
        }

        $labeled[] = array(
            'x' => round($x, 6),
            'y' => round($y, 6),
            'label' => dot_to_dot_label_text($settings, (int) $index),
        );
    }

    return $labeled;
}

function normalize_dot_to_dot_payload($rawData): array
{
    $default = array(
        'title' => default_dot_to_dot_title(),
        'instruction' => 'Connect the dots in order to reveal the picture.',
        'image' => '',
        'label_settings' => default_dot_to_dot_label_settings(),
        'points' => array(),
    );

    if ($rawData === null || $rawData === '') {
        return $default;
    }

    $decoded = is_string($rawData) ? json_decode($rawData, true) : $rawData;
    if (!is_array($decoded)) {
        return $default;
    }

    $title = isset($decoded['title']) ? trim((string) $decoded['title']) : '';
    $instruction = isset($decoded['instruction'])
        ? trim((string) $decoded['instruction'])
        : $default['instruction'];
    $image = isset($decoded['image']) ? trim((string) $decoded['image']) : '';
    $labelSettings = normalize_dot_to_dot_label_settings(
        isset($decoded['label_settings']) ? $decoded['label_settings'] : array(),
        isset($decoded['points']) && is_array($decoded['points']) ? count($decoded['points']) : 0
    );

    $pointsSource = isset($decoded['points']) && is_array($decoded['points'])
        ? $decoded['points']
        : array();

    $points = array();
    foreach ($pointsSource as $point) {
        if (!is_array($point)) {
            continue;
        }

        $x = isset($point['x']) ? (float) $point['x'] : -1;
        $y = isset($point['y']) ? (float) $point['y'] : -1;

        if ($x < 0 || $x > 1 || $y < 0 || $y > 1) {
            continue;
        }

        $points[] = array('x' => round($x, 6), 'y' => round($y, 6));
    }

    $points = dot_to_dot_apply_labels($points, $labelSettings);

    return array(
        'title' => normalize_dot_to_dot_title($title),
        'instruction' => $instruction !== '' ? $instruction : $default['instruction'],
        'image' => $image,
        'label_settings' => $labelSettings,
        'points' => $points,
    );
}

function dot_to_dot_activities_columns(PDO $pdo): array
{
    static $cache = null;

    if (is_array($cache)) {
        return $cache;
    }

    $cache = array();

    $stmt = $pdo->query(
        "SELECT column_name
         FROM information_schema.columns
         WHERE table_schema = 'public'
           AND table_name = 'activities'"
    );

    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        if (isset($row['column_name'])) {
            $cache[] = (string) $row['column_name'];
        }
    }

    return $cache;
}

function dot_to_dot_resolve_unit_from_activity(PDO $pdo, string $activityId): string
{
    if ($activityId === '') {
        return '';
    }

    $columns = dot_to_dot_activities_columns($pdo);

    if (in_array('unit_id', $columns, true)) {
        $stmt = $pdo->prepare(
            "SELECT unit_id
             FROM activities
             WHERE id = :id
             LIMIT 1"
        );
        $stmt->execute(array('id' => $activityId));
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($row && isset($row['unit_id'])) {
            return (string) $row['unit_id'];
        }
    }

    if (in_array('unit', $columns, true)) {
        $stmt = $pdo->prepare(
            "SELECT unit
             FROM activities
             WHERE id = :id
             LIMIT 1"
        );
        $stmt->execute(array('id' => $activityId));
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($row && isset($row['unit'])) {
            return (string) $row['unit'];
        }
    }

    return '';
}

function load_dot_to_dot_activity(PDO $pdo, string $unit, string $activityId): array
{
    $columns = dot_to_dot_activities_columns($pdo);

    $selectFields = array('id');
    if (in_array('data', $columns, true)) {
        $selectFields[] = 'data';
    }
    if (in_array('content_json', $columns, true)) {
        $selectFields[] = 'content_json';
    }
    if (in_array('title', $columns, true)) {
        $selectFields[] = 'title';
    }
    if (in_array('name', $columns, true)) {
        $selectFields[] = 'name';
    }

    $fallback = array(
        'id' => '',
        'title' => default_dot_to_dot_title(),
        'instruction' => 'Connect the dots in order to reveal the picture.',
        'image' => '',
        'label_settings' => default_dot_to_dot_label_settings(),
        'points' => array(),
    );

    $row = null;

    if ($activityId !== '') {
        $stmt = $pdo->prepare(
            "SELECT " . implode(', ', $selectFields) . "
             FROM activities
             WHERE id = :id
               AND type = 'dot_to_dot'
             LIMIT 1"
        );
        $stmt->execute(array('id' => $activityId));
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
    }

    if (!$row && in_array('unit_id', $columns, true)) {
        $stmt = $pdo->prepare(
            "SELECT " . implode(', ', $selectFields) . "
             FROM activities
             WHERE unit_id = :unit
               AND type = 'dot_to_dot'
             ORDER BY id ASC
             LIMIT 1"
        );
        $stmt->execute(array('unit' => $unit));
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
    }

    if (!$row && in_array('unit', $columns, true)) {
        $stmt = $pdo->prepare(
            "SELECT " . implode(', ', $selectFields) . "
             FROM activities
             WHERE unit = :unit
               AND type = 'dot_to_dot'
             ORDER BY id ASC
             LIMIT 1"
        );
        $stmt->execute(array('unit' => $unit));
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
    }

    if (!$row) {
        return $fallback;
    }

    $rawData = null;
    if (isset($row['data'])) {
        $rawData = $row['data'];
    } elseif (isset($row['content_json'])) {
        $rawData = $row['content_json'];
    }

    $payload = normalize_dot_to_dot_payload($rawData);

    $columnTitle = '';
    if (isset($row['title']) && trim((string) $row['title']) !== '') {
        $columnTitle = trim((string) $row['title']);
    } elseif (isset($row['name']) && trim((string) $row['name']) !== '') {
        $columnTitle = trim((string) $row['name']);
    }

    if ($columnTitle !== '') {
        $payload['title'] = $columnTitle;
    }

    return array(
        'id' => isset($row['id']) ? (string) $row['id'] : '',
        'title' => normalize_dot_to_dot_title((string) ($payload['title'] ?? '')),
        'instruction' => (string) ($payload['instruction'] ?? 'Connect the dots in order to reveal the picture.'),
        'image' => (string) ($payload['image'] ?? ''),
        'label_settings' => isset($payload['label_settings']) && is_array($payload['label_settings'])
            ? $payload['label_settings']
            : default_dot_to_dot_label_settings(),
        'points' => isset($payload['points']) && is_array($payload['points']) ? $payload['points'] : array(),
    );
}

function save_dot_to_dot_activity(PDO $pdo, string $unit, string $activityId, string $title, string $instruction, string $image, array $points, array $labelSettings): string
{
    $columns = dot_to_dot_activities_columns($pdo);

    $title = normalize_dot_to_dot_title($title);
    $instruction = trim($instruction) !== ''
        ? trim($instruction)
        : 'Connect the dots in order to reveal the picture.';
    $labelSettings = normalize_dot_to_dot_label_settings($labelSettings, count($points));
    $points = dot_to_dot_apply_labels($points, $labelSettings);

    $json = json_encode(
        array(
            'title' => $title,
            'instruction' => $instruction,
            'image' => trim($image),
            'label_settings' => $labelSettings,
            'points' => array_values($points),
        ),
        JSON_UNESCAPED_UNICODE
    );

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
            $stmt = $pdo->prepare(
                "SELECT id
                 FROM activities
                 WHERE unit_id = :unit
                   AND type = 'dot_to_dot'
                 ORDER BY id ASC
                 LIMIT 1"
            );
            $stmt->execute(array('unit' => $unit));
            $targetId = trim((string) $stmt->fetchColumn());
        }

        if ($targetId === '' && $hasUnit) {
            $stmt = $pdo->prepare(
                "SELECT id
                 FROM activities
                 WHERE unit = :unit
                   AND type = 'dot_to_dot'
                 ORDER BY id ASC
                 LIMIT 1"
            );
            $stmt->execute(array('unit' => $unit));
            $targetId = trim((string) $stmt->fetchColumn());
        }
    }

    if ($targetId !== '') {
        $setParts = array();
        $params = array('id' => $targetId);

        if ($hasData) {
            $setParts[] = 'data = :data';
            $params['data'] = $json;
        }

        if ($hasContentJson) {
            $setParts[] = 'content_json = :content_json';
            $params['content_json'] = $json;
        }

        if ($hasTitle) {
            $setParts[] = 'title = :title';
            $params['title'] = $title;
        }

        if ($hasName) {
            $setParts[] = 'name = :name';
            $params['name'] = $title;
        }

        if (!empty($setParts)) {
            $stmt = $pdo->prepare(
                "UPDATE activities
                 SET " . implode(', ', $setParts) . "
                 WHERE id = :id
                   AND type = 'dot_to_dot'"
            );
            $stmt->execute($params);
        }

        return $targetId;
    }

    $insertColumns = array();
    $insertValues = array();
    $params = array();

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
    $insertValues[] = "'dot_to_dot'";

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
        "INSERT INTO activities (" . implode(', ', $insertColumns) . ")
         VALUES (" . implode(', ', $insertValues) . ") RETURNING id"
    );
    $stmt->execute($params);

    $insertedId = $stmt->fetchColumn();
    return $insertedId ? (string) $insertedId : $newId;
}
