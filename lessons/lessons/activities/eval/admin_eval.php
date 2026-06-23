<?php
/**
 * admin_eval.php
 * Thin wrapper around the stable admin_eval_base.php view.
 * - Removes the create-exam shortcut from the "Todos los examenes" list tab.
 * - Makes the exam configuration card collapsible in the editor tab.
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
.config-collapsible-card .card-head{
  cursor:pointer;
  user-select:none;
}
.config-collapsible-card .card-head h3{
  display:flex;
  align-items:center;
  gap:8px;
}
.config-collapsible-card .card-head h3::before{
  content:'▾';
  display:inline-flex;
  align-items:center;
  justify-content:center;
  width:20px;
  height:20px;
  border-radius:7px;
  background:#EEEDFE;
  color:#534AB7;
  font-size:13px;
  transition:transform .18s ease;
}
.config-collapsible-card.is-collapsed .card-head h3::before{
  transform:rotate(-90deg);
}
.config-collapsible-card.is-collapsed .card-body{
  display:none;
}
.config-toggle-btn{
  margin-left:auto;
  border:1.5px solid #EDE9FA;
  background:#fff;
  color:#534AB7;
  border-radius:9px;
  padding:6px 10px;
  font-size:11px;
  font-weight:800;
  cursor:pointer;
  font-family:'Nunito',sans-serif;
}
.config-collapsible-card.is-collapsed .config-toggle-btn .open-label{display:inline;}
.config-collapsible-card.is-collapsed .config-toggle-btn .close-label{display:none;}
.config-collapsible-card:not(.is-collapsed) .config-toggle-btn .open-label{display:none;}
.config-collapsible-card:not(.is-collapsed) .config-toggle-btn .close-label{display:inline;}
</style>
<script>
document.addEventListener('DOMContentLoaded', function(){
  var editor = document.getElementById('tab-editor');
  if (!editor) return;
  var cards = Array.prototype.slice.call(editor.querySelectorAll('.card'));
  var configCard = cards.find(function(card){
    var title = card.querySelector('.card-head h3');
    if (!title) return false;
    var text = (title.textContent || '').trim().toLowerCase();
    return text.indexOf('configuración rápida') !== -1 || text.indexOf('configuracion rapida') !== -1 || text.indexOf('editar examen') !== -1;
  });
  if (!configCard) return;
  configCard.classList.add('config-collapsible-card');
  var head = configCard.querySelector('.card-head');
  if (!head) return;
  var existingBtn = head.querySelector('.config-toggle-btn');
  if (!existingBtn) {
    var btn = document.createElement('button');
    btn.type = 'button';
    btn.className = 'config-toggle-btn';
    btn.innerHTML = '<span class="open-label">Abrir configuración</span><span class="close-label">Cerrar configuración</span>';
    head.appendChild(btn);
  }
  var storageKey = 'ones_eval_config_collapsed';
  var saved = localStorage.getItem(storageKey);
  if (saved === null || saved === '1') {
    configCard.classList.add('is-collapsed');
  }
  function toggleConfig(e){
    if (e) e.preventDefault();
    configCard.classList.toggle('is-collapsed');
    localStorage.setItem(storageKey, configCard.classList.contains('is-collapsed') ? '1' : '0');
  }
  head.addEventListener('click', function(e){
    if (e.target && (e.target.closest('input') || e.target.closest('select') || e.target.closest('textarea') || e.target.closest('a'))) return;
    toggleConfig(e);
  });
});
</script>
HTML;

$html = str_replace('</body>', $collapseAssets . "\n</body>", $html);

echo $html;
