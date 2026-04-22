<?php
// version.php - Diagnóstico de despliegue y opcache
header('Content-Type: text/plain; charset=utf-8');
echo "VERSION FILE - DEPLOY TEST\n";
echo "Fecha y hora del contenedor: ".date('Y-m-d H:i:s')."\n";
echo "viewer.php hash: ".md5_file(__DIR__.'/lessons/lessons/activities/fillblank/viewer.php')."\n";

// Diagnóstico Opcache
if (function_exists('opcache_get_status')) {
    $status = opcache_get_status();
    echo "\nOPCACHE STATUS:\n";
    echo "Enabled: ".($status['opcache_enabled'] ? 'yes' : 'no')."\n";
    if (isset($status['config'])) {
        foreach ($status['config'] as $k => $v) {
            if (strpos($k, 'opcache') !== false) {
                echo "$k: $v\n";
            }
        }
    }
} else {
    echo "\nOPCACHE NOT AVAILABLE\n";
}
?>
