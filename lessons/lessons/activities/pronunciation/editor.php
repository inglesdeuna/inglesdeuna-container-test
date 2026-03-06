<?php
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../core/cloudinary_upload.php';
require_once __DIR__ . '/../../core/_activity_editor_template.php';

$activityId = isset($_GET['id']) ? trim((string) $_GET['id']) : '';
$unit = isset($_GET['unit']) ? trim((string) $_GET['unit']) : '';
$source = isset($_GET['source']) ? trim((string) $_GET['source']) : '';

function activities_columns(PDO $pdo): array
{
    static $cache = null;

    if (is_array($cache)) {
        return $cache;
    }

    $cache = array();

    $stmt = $pdo->query(
        "SELECT column_name
         FROM information_schema.columns
         WHERE table_schema = 'public'
           AND table_name = 'activities'"
    );

    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        if (isset($row['column_name'])) {
            $cache[] = (string) $row['column_name'];
        }
    }

    return $cache;
}

function resolve_unit_from_activity(PDO $pdo, string $activityId): string
{
    if ($activityId === '') {
        return '';
    }

    $columns = activities_columns($pdo);

    if (in_array('unit_id', $columns, true)) {
        $stmt = $pdo->prepare(
            "SELECT unit_id
             FROM activities
             WHERE id = :id
             LIMIT 1"
        );
        $stmt->execute(array('id' => $activityId));
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($row && isset($row['unit_id'])) {
            return (string) $row['unit_id'];
        }
    }

    if (in_array('unit', $columns, true)) {
        $stmt = $pdo->prepare(
            "SELECT unit
             FROM activities
             WHERE id = :id
             LIMIT 1"
        );
        $stmt->execute(array('id' => $activityId));
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($row && isset($row['unit'])) {
            return (string) $row['unit'];
        }
    }

    return '';
}

function normalize_pronunciation_items($rawData): array
{
    if ($rawData === null || $rawData === '') {
        return array();
    }

    $decoded = is_string($rawData) ? json_decode($rawData, true) : $rawData;

    if (json_last_error() !== JSON_ERROR_NONE || !is_array($decoded)) {
        return array();
    }

    if (isset($decoded['items']) && is_array($decoded['items'])) {
        $decoded = $decoded['items'];
    } elseif (isset($decoded['data']) && is_array($decoded['data'])) {
        $decoded = $decoded['data'];
    } elseif (isset($decoded['words']) && is_array($decoded['words'])) {
        $decoded = $decoded['words'];
    }

    $normalized = array();

    foreach ($decoded as $item) {
        if (!is_array($item)) {
            continue;
        }

        $en = isset($item['en']) ? trim((string) $item['en']) : '';
        if ($en === '' && isset($item['word'])) {
            $en = trim((string) $item['word']);
        }

        $normalized[] = array(
            'img' => isset($item['img']) ? trim((string) $item['img']) : (isset($item['image']) ? trim((string) $item['image']) : ''),
            'en' => $en,
            'ph' => isset($item['ph']) ? trim((string) $item['ph']) : '',
            'es' => isset($item['es']) ? trim((string) $item['es']) : '',
            'audio' => isset($item['audio']) ? trim((string) $item['audio']) : '',
        );
    }

    return $normalized;
}

function load_pronunciation_items(PDO $pdo, string $unit, string $activityId): array
{
    $columns = activities_columns($pdo);

    if ($activityId !== '' && in_array('data', $columns, true)) {
        $stmt = $pdo->prepare(
            "SELECT data
             FROM activities
             WHERE id = :id
               AND type = 'pronunciation'
             LIMIT 1"
        );
        $stmt->execute(array('id' => $activityId));
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($row && isset($row['data'])) {
            return normalize_pronunciation_items($row['data']);
        }
    }

    if ($unit !== '' && in_array('unit_id', $columns, true) && in_array('data', $columns, true)) {
        $stmt = $pdo->prepare(
            "SELECT data
             FROM activities
             WHERE unit_id = :unit
               AND type = 'pronunciation'
             LIMIT 1"
        );
        $stmt->execute(array('unit' => $unit));
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($row && isset($row['data'])) {
            return normalize_pronunciation_items($row['data']);
        }
    }

    if ($unit !== '' && in_array('unit', $columns, true) && in_array('content_json', $columns, true)) {
        $stmt = $pdo->prepare(
            "SELECT content_json
             FROM activities
             WHERE unit = :unit
               AND type = 'pronunciation'
             LIMIT 1"
        );
        $stmt->execute(array('unit' => $unit));
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($row && isset($row['content_json'])) {
            return normalize_pronunciation_items($row['content_json']);
        }
    }

    return array();
}

function save_pronunciation_items(PDO $pdo, string $unit, array $items): void
{
    $columns = activities_columns($pdo);
    $json = json_encode($items, JSON_UNESCAPED_UNICODE);

    if (in_array('unit_id', $columns, true) && in_array('data', $columns, true)) {
        $check = $pdo->prepare(
            "SELECT id
             FROM activities
             WHERE unit_id = :unit
               AND type = 'pronunciation'
             LIMIT 1"
        );
        $check->execute(array('unit' => $unit));

        if ($check->fetch()) {
            $stmt = $pdo->prepare(
                "UPDATE activities
                 SET data = :data
                 WHERE unit_id = :unit
                   AND type = 'pronunciation'"
            );
            $stmt->execute(array('data' => $json, 'unit' => $unit));
            return;
        }

        try {
            $stmt = $pdo->prepare(
                "INSERT INTO activities (unit_id, type, data)
                 VALUES (:unit, 'pronunciation', :data)"
            );
            $stmt->execute(array('unit' => $unit, 'data' => $json));
            return;
        } catch (Exception $e) {
            if (in_array('id', $columns, true)) {
                $stmt = $pdo->prepare(
                    "INSERT INTO activities (id, unit_id, type, data)
                     VALUES (:id, :unit, 'pronunciation', :data)"
                );
                $stmt->execute(array('id' => md5(random_bytes(16)), 'unit' => $unit, 'data' => $json));
                return;
            }

            throw $e;
        }
    }

    if (in_array('unit', $columns, true) && in_array('content_json', $columns, true)) {
        $check = $pdo->prepare(
            "SELECT id
             FROM activities
             WHERE unit = :unit
               AND type = 'pronunciation'
             LIMIT 1"
        );
        $check->execute(array('unit' => $unit));

        if ($check->fetch()) {
            $stmt = $pdo->prepare(
                "UPDATE activities
                 SET content_json = :data
                 WHERE unit = :unit
                   AND type = 'pronunciation'"
            );
            $stmt->execute(array('data' => $json, 'unit' => $unit));
            return;
        }

        $stmt = $pdo->prepare(
            "INSERT INTO activities (unit, type, content_json)
             VALUES (:unit, 'pronunciation', :data)"
        );
        $stmt->execute(array('unit' => $unit, 'data' => $json));
    }
}

if ($unit === '' && $activityId !== '') {
    $unit = resolve_unit_from_activity($pdo, $activityId);
}

if ($unit === '') {
    die('Unidad no especificada');
}

$items = load_pronunciation_items($pdo, $unit, $activityId);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $ens = isset($_POST['en']) && is_array($_POST['en']) ? $_POST['en'] : array();
    $phs = isset($_POST['ph']) && is_array($_POST['ph']) ? $_POST['ph'] : array();
    $ess = isset($_POST['es']) && is_array($_POST['es']) ? $_POST['es'] : array();
    $imgs = isset($_POST['img']) && is_array($_POST['img']) ? $_POST['img'] : array();
    $audios = isset($_POST['audio']) && is_array($_POST['audio']) ? $_POST['audio'] : array();

    $imageFiles = isset($_FILES['img_file']) ? $_FILES['img_file'] : null;

    $sanitized = array();

    foreach ($ens as $i => $enRaw) {
        $en = trim((string) $enRaw);
        $ph = isset($phs[$i]) ? trim((string) $phs[$i]) : '';
        $es = isset($ess[$i]) ? trim((string) $ess[$i]) : '';

        $img = isset($imgs[$i]) ? trim((string) $imgs[$i]) : '';
        $audio = isset($audios[$i]) ? trim((string) $audios[$i]) : '';

        if (
            $imageFiles
            && isset($imageFiles['name'][$i])
            && $imageFiles['name'][$i] !== ''
            && isset($imageFiles['tmp_name'][$i])
            && $imageFiles['tmp_name'][$i] !== ''
        ) {
            $uploadedImage = upload_to_cloudinary($imageFiles['tmp_name'][$i]);
            if ($uploadedImage) {
                $img = $uploadedImage;
            }
        }

        if ($en === '' && $img === '' && $ph === '' && $es === '') {
            continue;
        }

        $sanitized[] = array(
            'img' => $img,
            'en' => $en,
            'ph' => $ph,
            'es' => $es,
            'audio' => $audio,
        );
    }

    save_pronunciation_items($pdo, $unit, $sanitized);

    $redirectParams = array('unit=' . urlencode($unit), 'saved=1');
    if ($activityId !== '') {
        $redirectParams[] = 'id=' . urlencode($activityId);
    }
    if ($source !== '') {
        $redirectParams[] = 'source=' . urlencode($source);
    }

    header('Location: editor.php?' . implode('&', $redirectParams));
    exit;
}

ob_start();
?>

<?php if (isset($_GET['saved'])) { ?>
<p style="color:green;font-weight:bold;margin-bottom:15px;">✔ Guardado correctamente</p>
<?php } ?>

<form method="post" enctype="multipart/form-data" style="text-align:left;max-width:980px;margin:0 auto;">
    <div id="items">
        <?php foreach ($items as $item) { ?>
            <div class="pron-block">
                <label>Command (English)</label>
                <input type="text" name="en[]" value="<?php echo htmlspecialchars(isset($item['en']) ? $item['en'] : ''); ?>" placeholder="Stand up" required>

                <label>Phonetic</label>
                <input type="text" name="ph[]" value="<?php echo htmlspecialchars(isset($item['ph']) ? $item['ph'] : ''); ?>" placeholder="stánd ap">

                <label>Spanish</label>
                <input type="text" name="es[]" value="<?php echo htmlspecialchars(isset($item['es']) ? $item['es'] : ''); ?>" placeholder="Levántate / Levántense">

                <label>Image (optional)</label>
                <input type="file" name="img_file[]" accept="image/*">
                <input type="hidden" name="img[]" value="<?php echo htmlspecialchars(isset($item['img']) ? $item['img'] : ''); ?>">

                <input type="hidden" name="audio[]" value="<?php echo htmlspecialchars(isset($item['audio']) ? $item['audio'] : ''); ?>">

                <button type="button" onclick="removeItem(this)" class="btn-remove">✖ Remove</button>
            </div>
        <?php } ?>
    </div>

    <div class="actions-row">
        <button type="button" onclick="addItem()" class="btn-add">+ Add Card</button>
        <button type="submit" class="btn-save">💾 Save</button>
    </div>
</form>

<style>
.pron-block{background:#f9fafb;padding:14px;margin-bottom:12px;border-radius:12px;border:1px solid #e5e7eb;display:grid;grid-template-columns:1fr 1fr;gap:8px 10px}
.pron-block label{font-weight:700;grid-column:span 2}
.pron-block input{padding:8px 10px;border-radius:8px;border:1px solid #ccc;font-size:14px;grid-column:span 2}
.actions-row{display:flex;gap:10px;justify-content:center;margin-top:8px}
.btn-add{background:#16a34a;color:#fff;padding:9px 14px;border:none;border-radius:8px;cursor:pointer}
.btn-save{background:#0b5ed7;color:#fff;padding:9px 14px;border:none;border-radius:8px;cursor:pointer}
.btn-remove{background:#ef4444;color:#fff;border:none;padding:6px 10px;border-radius:6px;cursor:pointer;justify-self:end;grid-column:span 2}
@media (max-width:680px){.pron-block{display:flex;flex-direction:column}}
</style>

<script>
function addItem() {
    var container = document.getElementById('items');
    var div = document.createElement('div');
    div.className = 'pron-block';
    div.innerHTML = '' +
      '<label>Command (English)</label>' +
      '<input type="text" name="en[]" placeholder="Stand up" required>' +
      '<label>Phonetic</label>' +
      '<input type="text" name="ph[]" placeholder="stánd ap">' +
      '<label>Spanish</label>' +
      '<input type="text" name="es[]" placeholder="Levántate / Levántense">' +
      '<label>Image (optional)</label>' +
      '<input type="file" name="img_file[]" accept="image/*">' +
      '<input type="hidden" name="img[]" value="">' +
      '<input type="hidden" name="audio[]" value="">' +
      '<button type="button" onclick="removeItem(this)" class="btn-remove">✖ Remove</button>';
    container.appendChild(div);
}

function removeItem(btn) {
    var block = btn.closest('.pron-block');
    if (block) {
        block.remove();
    }
}
</script>

<?php
$content = ob_get_clean();
render_activity_editor('Pronunciation Editor', '🔊', $content);
