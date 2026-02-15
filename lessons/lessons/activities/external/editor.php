<?php
require_once __DIR__."/../../config/db.php";

$unit = $_GET['unit'] ?? null;
if (!$unit) die("Unit not specified");

/* SAVE */
if ($_SERVER["REQUEST_METHOD"] === "POST") {

    $url = trim($_POST["url"] ?? "");

    if ($url !== "") {

        $json = json_encode(["url"=>$url]);

        // Check if exists
        $check = $pdo->prepare("
            SELECT id FROM activities
            WHERE unit_id = :unit
            AND type = 'external'
        ");
        $check->execute(["unit"=>$unit]);

        if ($check->fetch()) {
            // UPDATE
            $stmt = $pdo->prepare("
                UPDATE activities
                SET data = :data
                WHERE unit_id = :unit
                AND type = 'external'
            ");
            $stmt->execute([
                "data"=>$json,
                "unit"=>$unit
            ]);
        } else {
            // INSERT
            $stmt = $pdo->prepare("
                INSERT INTO activities (id, unit_id, type, data)
                VALUES (:id, :unit, 'external', :data)
            ");
            $stmt->execute([
                "id"=>md5(random_bytes(16)),
                "unit"=>$unit,
                "data"=>$json
            ]);
        }
    }

    header("Location: editor.php?unit=".$unit."&saved=1");
    exit;
}

/* LOAD */
$stmt = $pdo->prepare("
    SELECT data
    FROM activities
    WHERE unit_id = :unit
    AND type = 'external'
");
$stmt->execute(["unit"=>$unit]);
$row = $stmt->fetch(PDO::FETCH_ASSOC);

$data = json_decode($row["data"] ?? "{}", true);
$currentUrl = $data["url"] ?? "";
?>

<!DOCTYPE html>
<html>
<head>
<meta charset="UTF-8">
<title>External Resource Editor</title>

<style>
body{
    font-family: Arial, sans-serif;
    background:#eef6ff;
    padding:40px 20px;
    text-align:center;
}

/* Title unified */
.title{
    color:#0b5ed7;
    font-size:28px;
    font-weight:bold;
    margin-bottom:12px;
}

/* Container */
.box{
    background:white;
    padding:30px;
    border-radius:18px;
    max-width:600px;
    margin:20px auto;
    box-shadow:0 4px 10px rgba(0,0,0,.1);
}

/* Input */
input{
    width:100%;
    padding:12px;
    border-radius:12px;
    border:1px solid #ccc;
    margin-top:10px;
    font-size:14px;
}

/* Primary button */
.primary-btn{
    background:#0b5ed7;
    color:white;
    border:none;
    padding:12px 26px;
    border-radius:16px;
    font-weight:bold;
    cursor:pointer;
    font-size:15px;
    transition:0.2s ease;
    margin-top:20px;
}

.primary-btn:hover{
    background:#084298;
}

/* Back button - same as viewer */
.back-btn{
    background:#16a34a;
    color:white;
    border:none;
    padding:12px 28px;
    border-radius:16px;
    font-weight:bold;
    cursor:pointer;
    font-size:15px;
    min-width:120px;
    transition:0.2s ease;
    margin-top:15px;
}

.back-btn:hover{
    background:#15803d;
}

.success{
    color:green;
    font-weight:bold;
    margin-bottom:10px;
}
</style>
</head>

<body>

<h1 class="title">🌐 External Resource Editor</h1>

<div class="box">

<?php if(isset($_GET["saved"])): ?>
<p class="success">✔ Saved successfully</p>
<?php endif; ?>

<form method="POST">

<label><strong>Resource URL</strong></label>
<input type="text" name="url" value="<?= htmlspecialchars($currentUrl) ?>" placeholder="https://example.com">

<button type="submit" class="primary-btn">
💾 Save
</button>

</form>

<button 
class="back-btn"
onclick="window.location.href='../hub/index.php?unit=<?= urlencode($unit) ?>'">
↩ Back
</button>

</div>

</body>
</html>
