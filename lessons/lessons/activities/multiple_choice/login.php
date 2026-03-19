<?php
// Fallback route for legacy/broken relative redirects from this activity folder.
// If activity params are present, recover by forwarding to the viewer.
$query = isset($_SERVER['QUERY_STRING']) ? trim((string) $_SERVER['QUERY_STRING']) : '';

if ($query !== '') {
    header('Location: /lessons/lessons/activities/multiple_choice/viewer.php?' . $query);
    exit;
}

header('Location: /lessons/lessons/academic/login.php');
exit;
