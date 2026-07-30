<?php
/**
 * admin_eval.php
 * Entry point for the Evaluaciones / Placement module.
 * All logic and UI are in admin_eval_base.php.
 */

// When Results is opened without an exam selected, automatically load the
// exam with the most recent submitted result instead of showing an empty page.
if (($_GET['tab'] ?? '') === 'results' && empty($_GET['exam_id'])) {
    require_once __DIR__ . '/../../config/db.php';
    try {
        $stmt = $pdo->query("SELECT exam_id
            FROM eval_results
            WHERE status = 'submitted'
            ORDER BY submitted_at DESC NULLS LAST, id DESC
            LIMIT 1");
        $latestExamId = (int)($stmt->fetchColumn() ?: 0);
        if ($latestExamId > 0) {
            $_GET['exam_id'] = $latestExamId;
        }
    } catch (Throwable $e) {
        // The base page will keep its normal empty-state behavior.
    }
}

require __DIR__ . '/admin_eval_base.php';
?>
<script>
(function () {
    // Results requires a new request so PHP can select the latest submitted exam.
    document.querySelectorAll('.es-item').forEach(function (button) {
        var handler = button.getAttribute('onclick') || '';
        if (handler.indexOf("showTab('results')") === -1) return;
        button.removeAttribute('onclick');
        button.addEventListener('click', function () {
            var params = new URLSearchParams(window.location.search);
            params.set('tab', 'results');
            window.location.href = 'admin_eval.php?' + params.toString();
        });
    });

    // Individual links only need: student name, school, and grade/level.
    // Keep the existing database columns for compatibility:
    // student_doc = school, student_phone = grade/level.
    var individual = document.getElementById('assign-form-individual');
    if (!individual) return;

    var nameInput = individual.querySelector('input[name="student_name"]');
    var schoolInput = individual.querySelector('input[name="student_doc"]');
    var levelInput = individual.querySelector('input[name="student_phone"]');
    var emailInput = individual.querySelector('input[name="student_email"]');
    var programInput = individual.querySelector('input[name="student_program"]');

    function setField(input, label, placeholder, value) {
        if (!input) return;
        var group = input.closest('.form-group');
        var labelEl = group ? group.querySelector('label') : null;
        if (labelEl) labelEl.textContent = label;
        input.placeholder = placeholder;
        input.required = true;
        if (value && !input.value) input.value = value;
    }

    setField(nameInput, 'Nombre del estudiante', 'Nombre completo', '');
    setField(schoolInput, 'Colegio', 'Nombre del colegio', "LET'S INSTITUTE");
    setField(levelInput, 'Grado o nivel', 'Ej: Segundo grado, A1, Básico 2', '');

    [emailInput, programInput].forEach(function (input) {
        var group = input ? input.closest('.form-group') : null;
        if (group) group.remove();
    });

    var headerNote = individual.querySelector('.card-head span');
    if (headerNote) headerNote.textContent = 'Nombre, colegio y grado o nivel';

    var threeColumnRow = individual.querySelector('.form-row-3');
    if (threeColumnRow) {
        threeColumnRow.style.gridTemplateColumns = 'repeat(2, minmax(0, 1fr))';
    }
})();
</script>
