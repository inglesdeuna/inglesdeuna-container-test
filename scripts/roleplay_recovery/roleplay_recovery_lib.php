<?php

declare(strict_types=1);

function rp_recovery_pdo(?string $url = null): PDO
{
    $url = $url ?: getenv('DATABASE_URL');
    if (!$url) {
        throw new RuntimeException('DATABASE_URL (or an explicit database URL) is required.');
    }

    $parts = parse_url($url);
    if (!is_array($parts) || empty($parts['host']) || empty($parts['user']) || !isset($parts['pass'])) {
        throw new RuntimeException('Invalid PostgreSQL DATABASE_URL.');
    }

    $db = ltrim((string)($parts['path'] ?? ''), '/');
    if ($db === '') {
        throw new RuntimeException('DATABASE_URL has no database name.');
    }

    $dsn = sprintf(
        'pgsql:host=%s;port=%d;dbname=%s;sslmode=require',
        $parts['host'],
        (int)($parts['port'] ?? 5432),
        $db
    );
    return new PDO($dsn, rawurldecode((string)$parts['user']), rawurldecode((string)$parts['pass']), [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
}

function rp_json(mixed $value): array
{
    if (is_array($value)) {
        return $value;
    }
    if ($value === null || trim((string)$value) === '') {
        return [];
    }
    $decoded = json_decode((string)$value, true);
    return is_array($decoded) ? $decoded : [];
}

function rp_turns(array $data): array
{
    foreach (['turns', 'dialogue', 'dialogs', 'lines', 'items'] as $key) {
        if (isset($data[$key]) && is_array($data[$key])) {
            return array_values(array_filter($data[$key], 'is_array'));
        }
    }
    return [];
}

function rp_dialogue_summary(array $data): array
{
    $turns = rp_turns($data);
    $texts = [];
    foreach ($turns as $turn) {
        $texts[] = [
            'agent' => (string)($turn['agent'] ?? $turn['teacherLine'] ?? ''),
            'hint' => (string)($turn['hint'] ?? $turn['studentLine'] ?? ''),
            'ideal' => (string)($turn['ideal'] ?? $turn['studentLine'] ?? ''),
        ];
    }
    return [
        'turn_count' => count($turns),
        'dialogue_sha256' => hash('sha256', (string)json_encode($texts, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)),
        'turns' => $texts,
    ];
}

function rp_merge_historical_turns(array $current, array $historical): array
{
    $historicalTurns = rp_turns($historical);
    if (count($historicalTurns) <= count(rp_turns($current))) {
        return $current;
    }
    $current['turns'] = $historicalTurns;
    return $current;
}

function rp_args(array $argv): array
{
    $args = [];
    foreach (array_slice($argv, 1) as $arg) {
        if (str_starts_with($arg, '--') && str_contains($arg, '=')) {
            [$key, $value] = explode('=', substr($arg, 2), 2);
            $args[$key] = $value;
        } elseif (str_starts_with($arg, '--')) {
            $args[substr($arg, 2)] = true;
        }
    }
    return $args;
}

function rp_selected_ids(array $args): ?array
{
    if (empty($args['ids'])) {
        return null;
    }
    return array_values(array_filter(array_map('trim', explode(',', (string)$args['ids'])), static fn(string $id): bool => $id !== ''));
}

function rp_write_json(mixed $value): void
{
    echo json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
}
