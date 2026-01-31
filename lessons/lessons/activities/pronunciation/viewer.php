<?php
$file = __DIR__ . "/pronunciation.json";
$data = file_exists($file) ? json_decode(file_get_contents($file), true) : [];
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Listen & Speak</title>

<style>
body{
  font-family: Arial, sans-serif;
  background:#eef6ff;
  padding:20px;
}
h1{text-align:center;color:#2563eb;}

.grid{
  display:grid;
  grid-template-columns: repeat(auto-fit, minmax(220px,1fr));
  gap:16px;
}

.card{
  background:white;
  border-radius:18px;
  padding:14px;
  text-align:center;
  box-shadow:0 4px 8px rgba(0,0,0,0.1);
}

.image{
  width:100%;
  height:130px;
  object-fit:contain;
  margin-bottom:6px;
}

.command{font-size:18px;font-weight:bold;}
.phonetic{font-size:14px;color:#555;}
.spanish{font-size:14px;margin-bottom:6px;}

button{
  margin:4px;
  padding:7px 12px;
  border:none;
  border-radius:10px;
  background:#2563eb;
  color:white;
  cursor:pointer;
  font-size:13px;
}

.feedback{font-size:15px;font-weight:bold;}
.good{color:green;}
.try{color:orange;}
</style>
</head>

<body>

<h1>🎧 Listen & Speak</h1>

<?php if (empty($data)): ?>
  <p style="text-align:center">No hay actividades de pronunciación.</p>
<?php else: ?>

<div class="grid" id="cards">

<?php foreach ($data as $i => $item): ?>
  <div class="card">
    <?php if (!empty($item["image"])): ?>
      <img class="image" src="<?= htmlspecialchars($item["image"]) ?>">
    <?php endif; ?>

    <div class="command"><?= htmlspecialchars($item["en"]) ?></div>
    <div class="phonetic"><?= htmlspecialchars($item["ph"]) ?></div>
    <div class="spanish"><?= htmlspecialchars($item["es"]) ?></div>

    <button onclick="speak('<?= htmlspecialchars($item["en"]) ?>')">🔊 Listen</button>
    <button onclick="record(<?= $i ?>)">🎤 Speak</button>
    <div id="f<?= $i ?>" class="feedback"></div>
  </div>
<?php endforeach; ?>

</div>
<?php endif; ?>

<script>
const data = <?= json_encode($data) ?>;

function speak(text){
  const u = new SpeechSynthesisUtterance(text);
  u.lang="en-US";
  u.rate=0.9;
  speechSynthesis.cancel();
  speechSynthesis.speak(u);
}

let recognition;
if ('webkitSpeechRecognition' in window) {
  recognition = new webkitSpeechRecognition();
  recognition.lang = "en-US";
}

function record(i){
  if(!recognition) return;
  recognition.start();
  recognition.onresult = e=>{
    const said = e.results[0][0].transcript.toLowerCase();
    const correct = data[i].en.toLowerCase();
    const fb = document.getElementById("f"+i);
    if(said.includes(correct.split(" ")[0])){
      fb.innerHTML="🌟 Good job!";
      fb.className="feedback good";
    }else{
      fb.innerHTML="🔁 Try again!";
      fb.className="feedback try";
    }
  };
}
</script>

</body>
</html>
