<?php
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../core/_activity_editor_template.php';

$activityId = isset($_GET['id']) ? $_GET['id'] : null;
$unit = isset($_GET['unit']) ? $_GET['unit'] : null;

if ($activityId && !$unit) {
    $stmt = $pdo->prepare(
        "SELECT unit_id
         FROM activities
         WHERE id = :id
         LIMIT 1"
    );
    $stmt->execute(array('id' => $activityId));
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$row) {
        die('Actividad no encontrada');
    }

    $unit = $row['unit_id'];
}

if (!$unit) {
    die('Unidad no especificada');
}

function load_drag_drop_blocks($pdo, $unit)
{
    $stmt = $pdo->prepare(
        "SELECT data
         FROM activities
         WHERE unit_id = :unit
           AND type = 'drag_drop'
         LIMIT 1"
    );
    $stmt->execute(array('unit' => $unit));

    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    $raw = isset($row['data']) ? $row['data'] : '[]';
    $decoded = json_decode($raw, true);

    return is_array($decoded) ? $decoded : array();
}

function save_drag_drop_blocks($pdo, $unit, $blocks)
{
    $json = json_encode($blocks, JSON_UNESCAPED_UNICODE);

    $check = $pdo->prepare(
        "SELECT id
         FROM activities
         WHERE unit_id = :unit
           AND type = 'drag_drop'
         LIMIT 1"
    );
    $check->execute(array('unit' => $unit));

    if ($check->fetch()) {
        $stmt = $pdo->prepare(
            "UPDATE activities
             SET data = :data
             WHERE unit_id = :unit
               AND type = 'drag_drop'"
        );
        $stmt->execute(array(
            'data' => $json,
            'unit' => $unit,
        ));
    } else {
        $stmt = $pdo->prepare(
            "INSERT INTO activities (id, unit_id, type, data)
             VALUES (:id, :unit, 'drag_drop', :data)"
        );
        $stmt->execute(array(
            'id' => md5(random_bytes(16)),
            'unit' => $unit,
            'data' => $json,
        ));
    }
}

function normalize_words($rawWords)
{
    $parts = preg_split('/[,\n]/', (string) $rawWords);
    $clean = array();

    foreach ($parts as $word) {
        $trimmed = trim($word);
        if ($trimmed !== '') {
            $clean[] = $trimmed;
        }
    }

    return array_values(array_unique($clean));
}

$blocks = load_drag_drop_blocks($pdo, $unit);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['delete_index'])) {
        $deleteIndex = (int) $_POST['delete_index'];

        if (isset($blocks[$deleteIndex])) {
            array_splice($blocks, $deleteIndex, 1);
            save_drag_drop_blocks($pdo, $unit, $blocks);
        }

        header('Location: editor.php?unit=' . urlencode((string) $unit) . '&saved=1');
        exit;
    }

    $text = isset($_POST['text']) ? trim($_POST['text']) : '';
    $missingWordsRaw = isset($_POST['missing_words']) ? trim($_POST['missing_words']) : '';
    $missingWords = normalize_words($missingWordsRaw);

    if ($text !== '' && count($missingWords) > 0) {
        $blocks[] = array(
            'id' => uniqid('drag_drop_'),
            'text' => $text,
            'missing_words' => $missingWords,
        );

        save_drag_drop_blocks($pdo, $unit, $blocks);
    }

    header('Location: editor.php?unit=' . urlencode((string) $unit) . '&saved=1');
    exit;
}

ob_start();

if (isset($_GET['saved'])) {
    echo '<p style="color:green;font-weight:bold;margin-bottom:15px;">✔ Guardado correctamente</p>';
}
?>

<form method="post" style="text-align:left; max-width:780px; margin:0 auto 20px auto;">
    <h3 style="margin:0 0 12px 0;">➕ Nuevo bloque Drag &amp; Drop</h3>

    <label style="font-weight:bold;display:block;margin-bottom:6px;">Oración o párrafo completo</label>
    <textarea
        name="text"
        required
        placeholder="Ej: I usually wake up early and drink coffee before work."
        style="width:100%;min-height:90px;padding:10px;margin:0 0 14px 0;border:1px solid #ccc;border-radius:8px;resize:vertical;"
    ></textarea>

    <label style="font-weight:bold;display:block;margin-bottom:6px;">Palabras para arrastrar (separadas por coma)</label>
    <input
        type="text"
        name="missing_words"
        required
        placeholder="Ej: usually, early, coffee"
        style="width:100%;padding:10px;margin:0 0 10px 0;border:1px solid #ccc;border-radius:8px;"
    >

    <p style="margin:0 0 16px 0;color:#6b7280;font-size:14px;">
        El viewer puede usar este texto para mostrar espacios en blanco y este banco de palabras para el drag &amp; drop.
    </p>

    <button type="submit" style="background:#0b5ed7;color:#fff;border:none;padding:10px 16px;border-radius:8px;cursor:pointer;">
        💾 Guardar bloque
    </button>
</form>

<hr style="margin:24px 0; border:none; border-top:1px solid #e5e7eb;">

<div style="max-width:780px; margin:0 auto; text-align:left;">
    <h3 style="margin:0 0 12px 0;">📦 Bloques guardados</h3>

    <?php if (empty($blocks)) { ?>
        <p style="color:#6b7280;">No hay bloques guardados todavía.</p>
    <?php } else { ?>
        <?php foreach ($blocks as $i => $block) { ?>
            <?php
            $textValue = '';
            if (isset($block['text']) && is_string($block['text'])) {
                $textValue = $block['text'];
            } elseif (isset($block['sentence']) && is_string($block['sentence'])) {
                $textValue = $block['sentence'];
            }

            $wordsValue = array();
            if (isset($block['missing_words']) && is_array($block['missing_words'])) {
                $wordsValue = $block['missing_words'];
            }
            ?>
            <div style="border:1px solid #e5e7eb;border-radius:12px;padding:12px;margin-bottom:12px;background:#f9fafb;">
                <div style="font-weight:bold; margin-bottom:8px;">📝 <?php echo htmlspecialchars($textValue); ?></div>

                <div style="margin-bottom:10px;">
                    <span style="font-size:13px;color:#6b7280;font-weight:bold;">Palabras drag:</span>
                    <?php if (empty($wordsValue)) { ?>
                        <span style="font-size:13px;color:#9ca3af;">(sin palabras definidas)</span>
                    <?php } else { ?>
                        <div style="display:flex;flex-wrap:wrap;gap:8px;margin-top:6px;">
                            <?php foreach ($wordsValue as $w) { ?>
                                <span style="background:#dbeafe;color:#1e3a8a;padding:5px 9px;border-radius:999px;font-size:13px;">
                                    <?php echo htmlspecialchars($w); ?>
                                </span>
                            <?php } ?>
                        </div>
                    <?php } ?>
                </div>

                <form method="post" style="margin:0;">
                    <input type="hidden" name="delete_index" value="<?php echo $i; ?>">
                    <button
                        type="submit"
                        style="background:#ef4444;color:#fff;border:none;padding:8px 12px;border-radius:8px;cursor:pointer;"
                    >
                        ✖ Eliminar
                    </button>
                </form>
            </div>
        <?php } ?>
    <?php } ?>
</div>

<?php
$content = ob_get_clean();
render_activity_editor('✏ Drag & Drop Editor', '✏', $content);
