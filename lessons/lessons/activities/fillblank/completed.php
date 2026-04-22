<?php
require_once __DIR__ . '/../../core/_activity_viewer_template.php';
require_once __DIR__ . '/../../core/db.php';

$activityId = isset($_GET['id']) ? trim((string)$_GET['id']) : '';
$unit = isset($_GET['unit']) ? trim((string)$_GET['unit']) : '';

function load_fillblank_activity(PDO $pdo, string $unit, string $activityId): array {
	$fallback = [
		'id' => '',
		'instructions' => 'Write the missing words in the blanks.',
		'blocks' => [],
		'wordbank' => '',
	];
	$row = null;
	if ($activityId !== '') {
		$stmt = $pdo->prepare("SELECT id, data FROM activities WHERE id = :id AND type = 'fillblank' LIMIT 1");
		$stmt->execute(['id' => $activityId]);
		$row = $stmt->fetch(PDO::FETCH_ASSOC);
	}
	if (!$row && $unit !== '') {
		$stmt = $pdo->prepare("SELECT id, data FROM activities WHERE unit_id = :unit AND type = 'fillblank' ORDER BY id ASC LIMIT 1");
		$stmt->execute(['unit' => $unit]);
		$row = $stmt->fetch(PDO::FETCH_ASSOC);
	}
	if (!$row) return $fallback;
	$data = json_decode($row['data'] ?? '', true);
	if (!isset($data['blocks']) && isset($data['text'])) {
		$blocks = [[
			'text' => $data['text'],
			'answers' => array_map('trim', explode(',', $data['answerkey'] ?? '')),
		]];
	} else {
		$blocks = $data['blocks'] ?? [];
	}
	return [
		'id' => (string)($row['id'] ?? ''),
		'instructions' => $data['instructions'] ?? $fallback['instructions'],
		'blocks' => $blocks,
		'wordbank' => $data['wordbank'] ?? '',
	];
}

$activity = load_fillblank_activity($pdo, $unit, $activityId);


// Leer score real de GET
$correct = isset($_GET['correct']) ? intval($_GET['correct']) : 0;
$total = isset($_GET['total']) ? intval($_GET['total']) : 0;
$percent = $total > 0 ? round($correct * 100 / $total) : 0;

ob_start();
?>
<style>
.completed-card {
	max-width: 520px;
	margin: 48px auto 0 auto;
	background: linear-gradient(135deg, #ede9fe 0%, #f8fafc 100%);
	border-radius: 22px;
	box-shadow: 0 6px 32px rgba(124,58,237,.07);
	padding: 38px 28px 32px 28px;
	display: flex;
	flex-direction: column;
	align-items: center;
	gap: 18px;
}
.completed-icon {
	font-size: 3.8rem;
	color: #14b8a6;
	margin-bottom: 8px;
}
.completed-title {
	font-family: 'Fredoka', 'Trebuchet MS', sans-serif;
	font-size: 2rem;
	color: #334155;
	margin-bottom: 0;
	text-align: center;
}
.completed-score {
	font-size: 1.2rem;
	color: #6366f1;
	font-weight: bold;
	margin: 10px 0 0 0;
}
.completed-percent {
	font-size: 1.7rem;
	color: #14b8a6;
	font-weight: 800;
	margin: 0 0 10px 0;
}
.completed-msg {
	color: #7c3aed;
	font-size: 1.1rem;
	margin-bottom: 18px;
	text-align: center;
}
.completed-btns {
	display: flex;
	flex-wrap: wrap;
	gap: 12px;
	justify-content: center;
}
.us-btn {
	padding: 11px 18px;
	border: none;
	border-radius: 999px;
	color: white;
	cursor: pointer;
	min-width: 148px;
	font-weight: 800;
	font-family: 'Nunito','Segoe UI',sans-serif;
	font-size: 14px;
	box-shadow: 0 10px 22px rgba(15,23,42,.12);
	transition: transform .15s ease, filter .15s ease;
}
.us-btn:hover {
	filter: brightness(1.04);
	transform: translateY(-1px);
}
.us-btn-main { background: linear-gradient(180deg,#818cf8 0%,#6366f1 100%); }
@media (max-width: 600px) {
	.completed-card { padding: 12px 4vw; }
	.completed-title { font-size: 1.3rem; }
}
</style>
<div class="completed-card">
	<div class="completed-icon">🎉</div>
	<div class="completed-title">Fill-in-the-Blank Activity</div>
	<div class="completed-score">Score: <?= $correct ?> / <?= $total ?> (<?= $percent ?>%)</div>
	<div class="completed-msg">You’ve completed Fill-in-the-Blank. Great job practicing.</div>
	<div class="completed-btns">
		<a href="viewer.php?id=<?= urlencode($activityId) ?>&unit=<?= urlencode($unit) ?>" class="us-btn us-btn-main">Reintentar actividad</a>
	</div>
</div>
<?php
$content = ob_get_clean();
render_activity_viewer('Actividad completada', 'fa-solid fa-circle-check', $content);
?>
