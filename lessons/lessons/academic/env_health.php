<?php
session_start();

if (!isset($_SESSION['admin_logged']) || $_SESSION['admin_logged'] !== true) {
    header('Location: ../admin/login.php');
    exit;
}

function env_lookup(string $key): string
{
    $v = $_ENV[$key] ?? getenv($key) ?? ($_SERVER[$key] ?? '');
    if ((!is_string($v) || trim($v) === '') && function_exists('apache_getenv')) {
        $ap = apache_getenv($key, true);
        if (is_string($ap) && trim($ap) !== '') {
            $v = $ap;
        }
    }
    return is_string($v) ? trim($v) : '';
}

$checks = [
    'ELEVENLABS_API_KEY' => [
        'label' => 'ElevenLabs API key',
        'required_for' => 'TTS generation',
    ],
    'CLOUDINARY_CLOUD_NAME' => [
        'label' => 'Cloudinary cloud name',
        'required_for' => 'Audio upload storage',
    ],
    'CLOUDINARY_API_KEY' => [
        'label' => 'Cloudinary API key',
        'required_for' => 'Audio upload storage',
    ],
    'CLOUDINARY_API_SECRET' => [
        'label' => 'Cloudinary API secret',
        'required_for' => 'Audio upload storage',
    ],
    'DATABASE_URL' => [
        'label' => 'Database URL',
        'required_for' => 'Database connectivity',
    ],
];

$rows = [];
foreach ($checks as $key => $meta) {
    $value = env_lookup($key);
    $rows[] = [
        'key' => $key,
        'label' => $meta['label'],
        'required_for' => $meta['required_for'],
        'status' => $value !== '' ? 'present' : 'missing',
    ];
}

$format = strtolower(trim((string)($_GET['format'] ?? 'html')));
if ($format === 'json') {
    header('Content-Type: application/json; charset=UTF-8');
    echo json_encode([
        'ok' => count(array_filter($rows, static function ($r) { return $r['status'] === 'missing'; })) === 0,
        'rows' => $rows,
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

function h(string $v): string
{
    return htmlspecialchars($v, ENT_QUOTES, 'UTF-8');
}

$missingCount = count(array_filter($rows, static function ($r) { return $r['status'] === 'missing'; }));
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Environment Health</title>
<style>
body {
    margin: 0;
    padding: 24px;
    font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
    background: #f5f7fb;
    color: #0f172a;
}
.container {
    max-width: 980px;
    margin: 0 auto;
    background: #fff;
    border: 1px solid #e2e8f0;
    border-radius: 14px;
    box-shadow: 0 8px 22px rgba(15, 23, 42, 0.06);
    overflow: hidden;
}
.header {
    padding: 18px 20px;
    border-bottom: 1px solid #e2e8f0;
    display: flex;
    gap: 10px;
    align-items: center;
    justify-content: space-between;
    flex-wrap: wrap;
}
.header h1 {
    margin: 0;
    font-size: 20px;
}
.badge {
    display: inline-flex;
    align-items: center;
    font-size: 12px;
    font-weight: 700;
    border-radius: 999px;
    padding: 6px 10px;
}
.badge.ok { background: #dcfce7; color: #166534; }
.badge.err { background: #fee2e2; color: #991b1b; }
.table-wrap { overflow-x: auto; }
table {
    width: 100%;
    border-collapse: collapse;
}
th, td {
    text-align: left;
    padding: 12px 14px;
    border-bottom: 1px solid #f1f5f9;
    font-size: 14px;
}
th {
    font-size: 12px;
    text-transform: uppercase;
    letter-spacing: .06em;
    color: #475569;
    background: #f8fafc;
}
.status {
    font-weight: 700;
}
.status.present { color: #166534; }
.status.missing { color: #b91c1c; }
.foot {
    padding: 14px 20px;
    font-size: 12px;
    color: #64748b;
    display: flex;
    justify-content: space-between;
    gap: 8px;
    flex-wrap: wrap;
}
.code {
    font-family: ui-monospace, SFMono-Regular, Menlo, monospace;
    background: #f1f5f9;
    padding: 2px 6px;
    border-radius: 6px;
}
</style>
</head>
<body>
<div class="container">
    <div class="header">
        <h1>Environment Health Check</h1>
        <?php if ($missingCount === 0): ?>
            <span class="badge ok">All required variables are present</span>
        <?php else: ?>
            <span class="badge err"><?= (int)$missingCount ?> missing variable(s)</span>
        <?php endif; ?>
    </div>

    <div class="table-wrap">
        <table>
            <thead>
                <tr>
                    <th>Variable</th>
                    <th>Description</th>
                    <th>Used for</th>
                    <th>Status</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($rows as $row): ?>
                <tr>
                    <td><span class="code"><?= h($row['key']) ?></span></td>
                    <td><?= h($row['label']) ?></td>
                    <td><?= h($row['required_for']) ?></td>
                    <td>
                        <span class="status <?= h($row['status']) ?>">
                            <?= h(strtoupper($row['status'])) ?>
                        </span>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <div class="foot">
        <div>Values are never displayed, only present/missing status.</div>
        <div>JSON: <span class="code">?format=json</span></div>
    </div>
</div>
</body>
</html>
