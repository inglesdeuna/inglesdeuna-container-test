<?php
$file = __DIR__ . "/flashcards.json";

if ($_SERVER["REQUEST_METHOD"] === "POST") {

    $front = $_POST["front"] ?? "";
    $back  = $_POST["back"] ?? "";

    $data = [];
    if (file_exists($file)) {
        $data = json_decode(file_get_contents($file), true) ?? [];
    }

    $data[] = [
        "front" => $front,
        "back"  => $back
    ];

    file_put_contents($file, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<title>Flashcards Editor – TEST</title>
</head>
<body>

<h2>TEST Flashcards</h2>

<form method="post">
  <input type="text" name="front" placeholder="Front text" required><br><br>
  <input type="text" name="back" placeholder="Back text" required><br><br>
  <button type="submit">Guardar</button>
</form>

</body>
</html>

