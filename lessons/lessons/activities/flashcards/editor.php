<?php
require_once __DIR__."/../../config/init_db.php";

$type = "flashcards";

require_once __DIR__."/../../core/_activity_editor_template.php";
?>

<h2>Flashcards Editor</h2>

<br>
<a href="viewer.php?unit=<?php echo $unit; ?>">
    👉 Ir al Viewer
</a>
<hr>


<form method="POST" enctype="multipart/form-data">

    <label>Texto:</label><br>
    <input type="text" name="text" required><br><br>

    <label>Imagen:</label><br>
    <input type="file" name="image" accept="image/*"><br><br>

    <button type="submit" name="add">Guardar Flashcard</button>

</form>

<hr>

<h3>Guardados</h3>

<?php foreach($data as $i=>$item): ?>

<div style="border:1px solid #ccc; padding:10px; margin:10px 0">

    <b><?php echo htmlspecialchars($item["text"] ?? ""); ?></b><br>

    <?php if(!empty($item["image"])): ?>
        <img src="/lessons/lessons/<?php echo $item["image"]; ?>" width="150">
    <?php endif; ?>

    <br>
    <a href="?unit=<?php echo $unit ?>&delete=<?php echo $i ?>">Eliminar</a>

</div>

<?php endforeach; ?>
