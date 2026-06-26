<?php
// core/save_activity.php
// Generic JSON activity saver compatible with both legacy and current activities schemas.

require_once __DIR__ . "/../config/db.php";

header("Content-Type: application/json");

if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    echo json_encode(["status" => "error", "message" => "Invalid request"]);
    exit;
}

$activityId = isset($_POST["activity_id"]) ? trim((string) $_POST["activity_id"]) : '';
$unit = isset($_POST["unit"]) ? trim((string) $_POST["unit"]) : '';
$type = isset($_POST["type"]) ? trim((string) $_POST["type"]) : '';
$content = $_POST["content_json"] ?? null;

if ($type === '') {
    echo json_encode(["status" => "error", "message" => "Missing activity type"]);
    exit;
}

if ($content === null) {
    echo json_encode(["status" => "error", "message" => "Missing activity content"]);
    exit;
}

function sa_activity_columns(PDO $pdo): array
{
    static $columns = null;
    if (is_array($columns)) {
        return $columns;
    }

    $columns = [];
    $stmt = $pdo->query("SELECT column_name FROM information_schema.columns WHERE table_schema='public' AND table_name='activities'");
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        if (isset($row['column_name'])) {
            $columns[] = (string) $row['column_name'];
        }
    }
    return $columns;
}

function sa_has(array $columns, string $column): bool
{
    return in_array($column, $columns, true);
}

function sa_json_title(string $json): string
{
    $decoded = json_decode($json, true);
    if (!is_array($decoded)) {
        return '';
    }
    if (isset($decoded['title']) && trim((string) $decoded['title']) !== '') {
        return trim((string) $decoded['title']);
    }
    if (isset($decoded['texts'][0]['title']) && trim((string) $decoded['texts'][0]['title']) !== '') {
        return trim((string) $decoded['texts'][0]['title']);
    }
    return '';
}

try {
    $columns = sa_activity_columns($pdo);

    $hasUnitId = sa_has($columns, 'unit_id');
    $hasUnit = sa_has($columns, 'unit');
    $hasData = sa_has($columns, 'data');
    $hasContentJson = sa_has($columns, 'content_json');
    $hasTitle = sa_has($columns, 'title');
    $hasName = sa_has($columns, 'name');
    $hasUpdatedAt = sa_has($columns, 'updated_at');
    $hasCreatedAt = sa_has($columns, 'created_at');

    if (!$hasData && !$hasContentJson) {
        throw new RuntimeException('No JSON content column found in activities table');
    }

    $targetId = '';

    if ($activityId !== '') {
        $stmt = $pdo->prepare("SELECT id FROM activities WHERE id = :id AND type = :type LIMIT 1");
        $stmt->execute(['id' => $activityId, 'type' => $type]);
        $targetId = trim((string) $stmt->fetchColumn());
    }

    if ($targetId === '' && $unit !== '') {
        if ($hasUnitId) {
            $stmt = $pdo->prepare("SELECT id FROM activities WHERE unit_id = :unit AND type = :type ORDER BY id ASC LIMIT 1");
            $stmt->execute(['unit' => $unit, 'type' => $type]);
            $targetId = trim((string) $stmt->fetchColumn());
        }

        if ($targetId === '' && $hasUnit) {
            $stmt = $pdo->prepare("SELECT id FROM activities WHERE unit = :unit AND type = :type ORDER BY id ASC LIMIT 1");
            $stmt->execute(['unit' => $unit, 'type' => $type]);
            $targetId = trim((string) $stmt->fetchColumn());
        }
    }

    $title = sa_json_title((string) $content);

    if ($targetId !== '') {
        $setParts = [];
        $params = ['id' => $targetId, 'type' => $type];

        if ($hasData) {
            $setParts[] = 'data = :data';
            $params['data'] = $content;
        }
        if ($hasContentJson) {
            $setParts[] = 'content_json = :content_json';
            $params['content_json'] = $content;
        }
        if ($hasTitle && $title !== '') {
            $setParts[] = 'title = :title';
            $params['title'] = $title;
        }
        if ($hasName && $title !== '') {
            $setParts[] = 'name = :name';
            $params['name'] = $title;
        }
        if ($hasUpdatedAt) {
            $setParts[] = 'updated_at = NOW()';
        }

        if (empty($setParts)) {
            throw new RuntimeException('No writable activity columns found');
        }

        $stmt = $pdo->prepare("UPDATE activities SET " . implode(', ', $setParts) . " WHERE id = :id AND type = :type");
        $stmt->execute($params);

        echo json_encode(["status" => "success", "id" => $targetId, "mode" => "updated"]);
        exit;
    }

    if ($unit === '') {
        echo json_encode(["status" => "error", "message" => "Missing unit for new activity"]);
        exit;
    }

    $insertColumns = [];
    $insertValues = [];
    $params = [];

    if ($hasUnitId) {
        $insertColumns[] = 'unit_id';
        $insertValues[] = ':unit_id';
        $params['unit_id'] = $unit;
    } elseif ($hasUnit) {
        $insertColumns[] = 'unit';
        $insertValues[] = ':unit';
        $params['unit'] = $unit;
    } else {
        throw new RuntimeException('No unit column found in activities table');
    }

    $insertColumns[] = 'type';
    $insertValues[] = ':type';
    $params['type'] = $type;

    if ($hasData) {
        $insertColumns[] = 'data';
        $insertValues[] = ':data';
        $params['data'] = $content;
    }
    if ($hasContentJson) {
        $insertColumns[] = 'content_json';
        $insertValues[] = ':content_json';
        $params['content_json'] = $content;
    }
    if ($hasTitle && $title !== '') {
        $insertColumns[] = 'title';
        $insertValues[] = ':title';
        $params['title'] = $title;
    }
    if ($hasName && $title !== '') {
        $insertColumns[] = 'name';
        $insertValues[] = ':name';
        $params['name'] = $title;
    }
    if ($hasUpdatedAt) {
        $insertColumns[] = 'updated_at';
        $insertValues[] = 'NOW()';
    }
    if ($hasCreatedAt) {
        // created_at has a default in current schema; leave it to the database.
    }

    $stmt = $pdo->prepare("INSERT INTO activities (" . implode(', ', $insertColumns) . ") VALUES (" . implode(', ', $insertValues) . ") RETURNING id");
    $stmt->execute($params);
    $newId = trim((string) $stmt->fetchColumn());

    echo json_encode(["status" => "success", "id" => $newId, "mode" => "inserted"]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(["status" => "error", "message" => $e->getMessage()]);
}
