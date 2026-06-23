<?php
/**
 * admin_eval.php
 * Thin wrapper around the stable admin_eval_base.php view.
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

$collapseAssets = <<<'HTML'
<style>
.config-collapsible-card .card-head{cursor:pointer;user-select:none;}
.config-collapsible-card .card-head h3{display:flex;align-items:center;gap:8px;}
.config-collapsible-card .card-head h3::before{content:'▾';display:inline-flex;align-items:center;justify-content:center;width:20px;height:20px;border-radius:7px;background:#EEEDFE;color:#534AB7;font-size:13px;transition:transform .18s ease;}
.config-collapsible-card.is-collapsed .card-head h3::before{transform:rotate(-90deg);}
.config-collapsible-card.is-collapsed .card-body{display:none;}
.config-toggle-btn{margin-left:auto;border:1.5px solid #EDE9FA;background:#fff;color:#534AB7;border-radius:9px;padding:6px 10px;font-size:11px;font-weight:800;cursor:pointer;font-family:'Nunito',sans-serif;}
.config-collapsible-card.is-collapsed .config-toggle-btn .open-label{display:inline;}
.config-collapsible-card.is-collapsed .config-toggle-btn .close-label{display:none;}
.config-collapsible-card:not(.is-collapsed) .config-toggle-btn .open-label{display:none;}
.config-collapsible-card:not(.is-collapsed) .config-toggle-btn .close-label{display:inline;}
.builder-primary-btn{display:inline-flex;align-items:center;gap:7px;background:#F97316!important;color:#fff!important;border-color:#F97316!important;}
.eval-editor-actions{display:flex;gap:8px;flex-wrap:wrap;margin:0 0 14px;}
.eval-editor-actions a{display:inline-flex;align-items:center;gap:7px;padding:9px 14px;border-radius:10px;text-decoration:none;font-weight:800;font-size:12.5px;border:1.5px solid #EDE9FA;background:#fff;color:#534AB7;}
.eval-editor-actions .online{background:#7F77DD;color:#fff;border-color:#7F77DD;}
.eval-editor-actions .print{background:#fff;color:#374151;}
.eval-editor-actions .blocks{background:#F97316;color:#fff;border-color:#F97316;}
</style>
<script>
document.addEventListener('DOMContentLoaded', function(){
  var editor = document.getElementById('tab-editor');
  var params = new URLSearchParams(window.location.search);
  var currentExamId = params.get('exam_id') || '';

  document.querySelectorAll('a[href^="?tab=editor&exam_id="]').forEach(function(a){
    var m = a.getAttribute('href').match(/exam_id=([0-9]+)/);
    if (!m) return;
    a.href = 'quiz_from_scratch.php?mode=edit&exam_id=' + m[1];
    if ((a.textContent || '').trim().toLowerCase() === 'editar') a.textContent = 'Actividades';
    a.classList.add('builder-primary-btn');
  });

  if (editor && currentExamId) {
    if (!editor.querySelector('.eval-editor-actions')) {
      var actions = document.createElement('div');
      actions.className = 'eval-editor-actions';
      actions.innerHTML = '<a class="online" target="_blank" href="eval_viewer.php?preview=1&exam_id=' + currentExamId + '">Preview online</a>' +
                          '<a class="print" target="_blank" href="quiz_print.php?exam_id=' + currentExamId + '&mode=student">Preview impreso</a>' +
                          '<a class="blocks" href="quiz_from_scratch.php?mode=edit&exam_id=' + currentExamId + '">Actividades del examen</a>';
      editor.insertBefore(actions, editor.firstChild);
    }
    editor.querySelectorAll('button').forEach(function(btn){
      var txt = (btn.textContent || '').trim().toLowerCase();
      if (txt.indexOf('agregar pregunta') !== -1 || txt.indexOf('actividades del hub') !== -1) {
        btn.textContent = '+ Actividades del examen';
        btn.onclick = function(e){
          e.preventDefault();
          window.location.href = 'quiz_from_scratch.php?mode=edit&exam_id=' + currentExamId;
        };
        btn.classList.add('builder-primary-btn');
      }
    });
  }

  if (editor) {
    var cards = Array.prototype.slice.call(editor.querySelectorAll('.card'));
    var configCard = cards.find(function(card){
      var title = card.querySelector('.card-head h3');
      if (!title) return false;
      var text = (title.textContent || '').trim().toLowerCase();
      return text.indexOf('configuración rápida') !== -1 || text.indexOf('configuracion rapida') !== -1 || text.indexOf('editar examen') !== -1;
    });
    if (configCard) {
      configCard.classList.add('config-collapsible-card');
      var head = configCard.querySelector('.card-head');
      if (head && !head.querySelector('.config-toggle-btn')) {
        var btn = document.createElement('button');
        btn.type = 'button';
        btn.className = 'config-toggle-btn';
        btn.innerHTML = '<span class="open-label">Abrir configuración</span><span class="close-label">Cerrar configuración</span>';
        head.appendChild(btn);
      }
      var storageKey = 'ones_eval_config_collapsed';
      var saved = localStorage.getItem(storageKey);
      if (saved === null || saved === '1') configCard.classList.add('is-collapsed');
      function toggleConfig(e){
        if (e) e.preventDefault();
        configCard.classList.toggle('is-collapsed');
        localStorage.setItem(storageKey, configCard.classList.contains('is-collapsed') ? '1' : '0');
      }
      if (head) {
        head.addEventListener('click', function(e){
          if (e.target && (e.target.closest('input') || e.target.closest('select') || e.target.closest('textarea') || e.target.closest('a'))) return;
          toggleConfig(e);
        });
      }
    }
  }
});
</script>
HTML;

$html = str_replace('</body>', $collapseAssets . "\n</body>", $html);
echo $html;
