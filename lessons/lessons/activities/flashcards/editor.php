<?php
require_once __DIR__."/../../config/init_db.php";

$type = "flashcards";

require_once __DIR__."/../../core/_activity_viewer_template.php";
?>

<h2>Flashcards Viewer</h2>

<?php if(empty($data)): ?>
    <p>No hay flashcards para esta unidad.</p>
<?php else: ?>

    <?php foreach($data as $item): ?>

        <div style="border:1px solid #ccc; padding:10px; margin:10px 0">

            <strong><?php echo htmlspecialchars($item["text"] ?? ""); ?></strong><br>

            <?php if(!empty($item["image"])): ?>
                <img src="/<?php echo $item["image"]; ?>" width="200">
            <?php endif; ?>

        </div>

    <?php endforeach; ?>

<?php endif; ?>
