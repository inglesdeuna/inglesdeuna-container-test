<?php
/**
 * admin_eval.php
 * Thin wrapper around the stable admin_eval_base.php view.
 * It removes the create-exam shortcut from the "Todos los examenes" list tab
 * so that tab only shows existing exams.
 */
ob_start();
require __DIR__ . '/admin_eval_base.php';
$html = ob_get_clean();

$html = str_replace(
    '<div class="card-head"><h3>Exámenes</h3><button class="btn btn-primary" onclick="showTab(\'editor\')">+ Crear examen</button></div>',
    '<div class="card-head"><h3>Exámenes</h3></div>',
    $html
);

$html = str_replace(
    '<div class="card-head"><h3>Examenes</h3><button class="btn btn-primary" onclick="showTab(\'editor\')">+ Crear examen</button></div>',
    '<div class="card-head"><h3>Examenes</h3></div>',
    $html
);

echo $html;
