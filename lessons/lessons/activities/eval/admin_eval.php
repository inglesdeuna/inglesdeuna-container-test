<?php
/*
 * Patched wrapper for the evaluation admin module.
 * The original implementation is preserved in admin_eval_base.php.
 */

$basePath = __DIR__ . '/admin_eval_base.php';
$source = file_get_contents($basePath);
if ($source === false) {
    die('Evaluation admin base file not found.');
}

$source = str_replace(
    "if (($_GET['msg'] ?? '') === 'deleted') $msg = 'Examen eliminado.';",
    "if ((\$_GET['msg'] ?? '') === 'deleted') \$msg = 'Examen eliminado.';\nif ((\$_GET['msg'] ?? '') === 'link_saved') \$msg = 'Link generado correctamente.';",
    $source
);

$source = str_replace(
    "        \$msg = 'Link de grupo generado.';\n        \$tab = 'links';",
    "        header('Location: admin_eval.php?tab=links&exam_id=' . \$examId . '&msg=link_saved');\n        exit;",
    $source
);

$source = str_replace(
    "        \$msg = 'Link individual generado.';\n        \$tab = 'links';",
    "        header('Location: admin_eval.php?tab=links&exam_id=' . \$examId . '&msg=link_saved');\n        exit;",
    $source
);

$source = str_replace(
    "\$statsLinks       = (int) \$pdo->query(\"SELECT COUNT(*) FROM eval_links WHERE (expires_at IS NULL OR expires_at > NOW()) AND uses_count < max_uses\")->fetchColumn();",
    "\$statsLinks       = (int) \$pdo->query(\"SELECT COUNT(*) FROM eval_links WHERE (expires_at IS NULL OR expires_at > NOW()) AND use_count < max_uses\")->fetchColumn();",
    $source
);

$sidebarNeedle = <<<'HTML'
    <button class="es-item <?= $tab==='list'?'active':'' ?>" onclick="showTab('list')">
      <i class="ti ti-clipboard-list" aria-hidden="true"></i>Todos los exámenes</button>
    <button class="es-item <?= $tab==='editor'?'active':'' ?>" onclick="showTab('editor')">
      <i class="ti ti-plus" aria-hidden="true"></i>Crear examen</button>
    <button class="es-item <?= $tab==='links'?'active':'' ?>" onclick="showTab('links')">
      <i class="ti ti-link" aria-hidden="true"></i>Links activos</button>
    <button class="es-item <?= $tab==='results'?'active':'' ?>" onclick="showTab('results')">
      <i class="ti ti-chart-bar" aria-hidden="true"></i>Resultados</button>
    <button class="es-item <?= $tab==='cefr'?'active':'' ?>" onclick="showTab('cefr')">
      <i class="ti ti-certificate" aria-hidden="true"></i>Rangos MCER</button>
HTML;
$sidebarReplacement = <<<'HTML'
    <?php
    $eidParam = $currentExamId > 0 ? '&exam_id=' . $currentExamId : '';
    $navTabs = [
        'list'    => ['ti-clipboard-list', 'Todos los exámenes'],
        'editor'  => ['ti-plus',           'Crear examen'],
        'links'   => ['ti-link',           'Links activos'],
        'results' => ['ti-chart-bar',      'Resultados'],
        'cefr'    => ['ti-certificate',    'Rangos MCER'],
    ];
    foreach ($navTabs as $tKey => [$tIcon, $tLabel]): ?>
    <a class="es-item <?= $tab===$tKey?'active':'' ?>"
       href="admin_eval.php?tab=<?= $tKey . $eidParam ?>">
      <i class="ti <?= $tIcon ?>" aria-hidden="true"></i><?= $tLabel ?></a>
    <?php endforeach; ?>
HTML;
$source = str_replace($sidebarNeedle, $sidebarReplacement, $source);

$source = str_replace(
    '<div class="card-head"><h3>Exámenes</h3><button class="btn btn-primary" onclick="showTab(\'editor\')">+ Crear examen</button></div>',
    '<div class="card-head"><h3>Exámenes</h3><a class="btn btn-primary" href="admin_eval.php?tab=editor">+ Crear examen</a></div>',
    $source
);

$source = str_replace(
    '<a class="btn btn-secondary btn-sm" href="eval_results.php?exam_id=<?= $ex[\'id\'] ?>">Resultados</a>',
    '<a class="btn btn-secondary btn-sm" href="admin_eval.php?tab=results&exam_id=<?= $ex[\'id\'] ?>">Resultados</a>',
    $source
);

$source = str_replace(
    '<form method="POST">\n          <input type="hidden" name="action" value="save_exam">\n          <input type="hidden" name="exam_id" value="<?= $currentExamId ?>">',
    '<form method="POST" action="admin_eval.php?tab=editor<?= $currentExamId > 0 ? \'&exam_id=\'.$currentExamId : \'\' ?>">\n          <input type="hidden" name="action" value="save_exam">\n          <input type="hidden" name="exam_id" value="<?= $currentExamId ?>">',
    $source
);

eval('?>' . $source);
