<?php
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../core/_activity_viewer_template.php';

$activityId = isset($_GET['id'])        ? trim((string) $_GET['id'])        : '';
$unit       = isset($_GET['unit'])      ? trim((string) $_GET['unit'])      : '';
$returnTo   = isset($_GET['return_to']) ? trim((string) $_GET['return_to']) : '';

function ddk_default_title(): string { return 'Drag & Drop'; }

function ddk_normalize_payload($raw): array
{
    $default = [
        'title'            => ddk_default_title(),
        'instructions'     => '',
        'background_image' => '',
        'pairs'            => [],
    ];

    if ($raw === null || $raw === '') return $default;
    $d = is_string($raw) ? json_decode($raw, true) : $raw;
    if (!is_array($d)) return $default;

    $pairs = [];
    foreach ($d['pairs'] ?? [] as $p) {
        if (!is_array($p)) continue;
        $id    = (int)($p['id'] ?? 0);
        $label = trim((string)($p['label'] ?? ''));
        if ($id <= 0 || $label === '') continue;
        $pairs[] = [
            'id'    => $id,
            'label' => $label,
            'x'     => (float)($p['x'] ?? 10),
            'y'     => (float)($p['y'] ?? 10),
            'w'     => (float)($p['w'] ?? 12),
            'h'     => (float)($p['h'] ?? 8),
        ];
    }

    return [
        'title'            => trim((string)($d['title'] ?? '')) ?: ddk_default_title(),
        'instructions'     => trim((string)($d['instructions'] ?? '')),
        'background_image' => trim((string)($d['background_image'] ?? '')),
        'pairs'            => $pairs,
    ];
}

function ddk_resolve_unit(PDO $pdo, string $activityId): string
{
    $stmt = $pdo->prepare('SELECT unit_id FROM activities WHERE id = :id LIMIT 1');
    $stmt->execute(['id' => $activityId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ? (string)($row['unit_id'] ?? '') : '';
}

function ddk_load(PDO $pdo, string $activityId, string $unit): array
{
    $fallback = [
        'id'               => '',
        'title'            => ddk_default_title(),
        'instructions'     => '',
        'background_image' => '',
        'pairs'            => [],
    ];

    $row = null;
    if ($activityId !== '') {
        $stmt = $pdo->prepare("SELECT id, data FROM activities WHERE id = :id AND type = 'drag_drop_kids' LIMIT 1");
        $stmt->execute(['id' => $activityId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
    }
    if (!$row && $unit !== '') {
        $stmt = $pdo->prepare("SELECT id, data FROM activities WHERE unit_id = :unit AND type = 'drag_drop_kids' ORDER BY id ASC LIMIT 1");
        $stmt->execute(['unit' => $unit]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
    }
    if (!$row) return $fallback;

    $payload = ddk_normalize_payload($row['data'] ?? null);
    return array_merge($payload, ['id' => (string)($row['id'] ?? '')]);
}

if ($unit === '' && $activityId !== '') {
    $unit = ddk_resolve_unit($pdo, $activityId);
}

$activity     = ddk_load($pdo, $activityId, $unit);
$title        = $activity['title'];
$pairs        = $activity['pairs'];
$bgImage      = $activity['background_image'];
$instructions = $activity['instructions'];

if ($activityId === '' && !empty($activity['id'])) {
    $activityId = $activity['id'];
}

if (empty($pairs) || $bgImage === '') {
    die('Activity not configured yet.');
}

ob_start();
require __DIR__ . '/_drag_drop_kids_view.php';
$content = ob_get_clean();
render_activity_viewer($title, '🖼️', $content);

