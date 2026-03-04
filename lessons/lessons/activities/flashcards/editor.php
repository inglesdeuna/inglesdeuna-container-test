<?php
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../core/cloudinary_upload.php';
require_once __DIR__ . '/../../core/_activity_editor_template.php';

$unit = isset($_GET['unit']) ? $_GET['unit'] : null;
if (!$unit) {
    die('Unit missing');
}

function load_flashcards($pdo, $unit)
{
    $stmt = $pdo->prepare(
        "SELECT data
         FROM activities
         WHERE unit_id = :unit
           AND type = 'flashcards'
         LIMIT 1"
    );
    $stmt->execute(array('unit' => $unit));

    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    $raw = isset($row['data']) ? $row['data'] : '[]';
    $decoded = json_decode($raw, true);

    return is_array($decoded) ? $decoded : array();
}

function save_flashcards($pdo, $unit, $cards)
{
    $json = json_encode($cards, JSON_UNESCAPED_UNICODE);

    $check = $pdo->prepare(
        "SELECT id
         FROM activities
         WHERE unit_id = :unit
           AND type = 'flashcards'
         LIMIT 1"
    );
    $check->execute(array('unit' => $unit));

    if ($check->fetch()) {
        $stmt = $pdo->prepare(
            "UPDATE activities
             SET data = :data
             WHERE unit_id = :unit
               AND type = 'flashcards'"
        );
        $stmt->execute(array(
            'data' => $json,
            'unit' => $unit,
        ));
    } else {
        $stmt = $pdo->prepare(
            "INSERT INTO activities (id, unit_id, type, data)
             VALUES (:id, :unit, 'flashcards', :data)"
        );
        $stmt->execute(array(
            'id' => md5(random_bytes(16)),
            'unit' => $unit,
            'data' => $json,
        ));
    }
}

$data = load_flashcards($pdo, $unit);

if (isset($_GET['delete'])) {
    $i = (int) $_GET['delete'];

    if (isset($data[$i])) {
        array_splice($data, $i, 1);
        save_flashcards($pdo, $unit, $data);
    }

    header('Location: editor.php?unit=' . urlencode((string) $unit) . '&saved=1');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $text = isset($_POST['text']) ? trim($_POST['text']) : '';

    if ($text !== '') {
        $imgPath = '';

        if (isset($_FILES['image']) && !empty($_FILES['image']['tmp_name'])) {
            $url = upload_to_cloudinary($_FILES['image']['tmp_name']);
            if ($url) {
                $imgPath = $url;
            }
        }

        $data[] = array(
            'id' => uniqid('flashcard_'),
            'text' => $text,
            'image' => $imgPath,
        );

        save_flashcards($pdo, $unit, $data);
    }

    header('Location: editor.php?unit=' . urlencode((string) $unit) . '&saved=1');
    exit;
}

ob_start();
?>
<style>
.flashcards-form{
    max-width:760px;
    margin:0 auto;
    text-align:left;
}

.flashcards-form input[type="text"],
.flashcards-form input[type="file"]{
    width:100%;
    padding:10px;
    border:1px solid #d1d5db;
    border-radius:8px;
    margin:6px 0 12px 0;
    box-sizing:border-box;
}

.list{ margin-top:20px; max-width:760px; margin-left:auto; margin-right:auto; text-align:left; }

.item{
    display:flex;
    align-items:center;
    justify-content:space-between;
    background:#f7f7f7;
    padding:12px;
    border-radius:12px;
    margin-bottom:10px;
    border:1px solid #e5e7eb;
}

.item img{
    width:56px;
    height:56px;
    object-fit:contain;
    border-radius:8px;
    background:white;
}

.left{
    display:flex;
    align-items:center;
    gap:10px;
}

.delete{
    color:#dc2626;
    font-weight:bold;
    text-decoration:none;
    font-size:18px;
}
</style>

<?php if (isset($_GET['saved'])) { ?>
    <p style="color:green;font-weight:bold;margin-bottom:15px;">✔ Guardado correctamente</p>
<?php } ?>

<form class="flashcards-form" method="post" enctype="multipart/form-data">
    <label style="font-weight:bold;display:block;">Word / text</label>
    <input name="text" placeholder="Write the word" required>

    <label style="font-weight:bold;display:block;">Image (optional)</label>
    <input type="file" name="image" accept="image/*">

    <button type="submit" class="save-btn">💾 Save</button>
</form>

<div class="list">
    <h3>📚 Flashcards</h3>

    <?php if (empty($data)) { ?>
        <p>No flashcards yet.</p>
    <?php } ?>

    <?php foreach ($data as $i => $item) { ?>
        <div class="item">
            <div class="left">
                <?php if (!empty($item['image'])) { ?>
                    <img src="<?= htmlspecialchars($item['image']) ?>" alt="flashcard-image">
                <?php } ?>
                <strong><?= htmlspecialchars(isset($item['text']) ? $item['text'] : '') ?></strong>
            </div>

            <a class="delete" href="?unit=<?= urlencode((string) $unit) ?>&delete=<?= $i ?>">❌</a>
        </div>
    <?php } ?>
</div>

<?php
$content = ob_get_clean();
render_activity_editor('🃏 Flashcards Editor', '🃏', $content);
