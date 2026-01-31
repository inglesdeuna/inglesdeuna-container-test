<?php
$file = __DIR__ . "/drag_drop.json";
$data = file_exists($file) ? json_decode(file_get_contents($file), true) : [];
$a = $data[0] ?? null;
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Drag & Drop</title>

<style>
body{
  font-family:Arial, sans-serif;
  background:#eef6ff;
  padding:20px;
  text-align:center;
}

h1{color:#2563eb;}

.container{
  max-width:800px;
  margin:0 auto;
}

img{
  max-width:100%;
  border-radius:12px;
  margin-bottom:15px;
}

.sentence{
  font-size:22px;
  margin:20px 0;
}

.blank{
  display:inline-block;
  min-width:100px;
  padding:6px 10px;
  border-bottom:3px solid #2563eb;
  margin:0 6px;
}

.words{
  display:flex;
  flex-wrap:wrap;
  justify-content:center;
  gap:10px;
}

.word{
  background:#2563eb;
  color:white;
  padding:10px 14px;
  border-radius:10px;
  font-weight:bold;
  cursor:grab;
}

.word.dragging{opacity:.5;}

button{
  margin-top:20px;
  padding:12px 18px;
  border:none;
  border-radius:10px;
  background:#2563eb;
  color:white;
  font-weight:bold;
}

.result{
  margin-top:15px;
  font-size:18px;
  font-weight:bold;
}
.good{color:green;}
.try{color:orange;}
</style>
</head>

<body>

<h1>🧲 Drag & Drop</h1>

<?php if (!$a): ?>
<p>No activity available.</p>
<?php else: ?>

<div class="container">

  <?php if (!empty($a["image"])): ?>
    <img src="<?= htmlspecialchars($a["image"]) ?>">
  <?php endif; ?>

  <div class="sentence" id="sentence"></div>

  <div class="words" id="words"></div>

  <button onclick="check()">✅ Check</button>
  <div id="result" class="result"></div>

</div>

<script>
const sentence = <?= json_encode($a["sentence"]) ?>;
const answers = <?= json_encode($a["answers"]) ?>;
const options = <?= json_encode($a["options"]) ?>;

const s = document.getElementById("sentence");
sentence.split("___").forEach((part,i)=>{
  s.append(part);
  if(i < answers.length){
    const span = document.createElement("span");
    span.className="blank";
    span.dataset.answer = answers[i];
    span.ondragover = e=>e.preventDefault();
    span.ondrop = e=>{
      const w = document.querySelector(".dragging");
      if(w){
        span.textContent = w.textContent;
        w.remove();
      }
    };
    s.append(span);
  }
});

const words = document.getElementById("words");
options.sort(()=>Math.random()-0.5).forEach(t=>{
  const d = document.createElement("div");
  d.className="word";
  d.textContent=t;
  d.draggable=true;
  d.ondragstart=()=>d.classList.add("dragging");
  d.ondragend=()=>d.classList.remove("dragging");
  words.appendChild(d);
});

function check(){
  let ok = true;
  document.querySelectorAll(".blank").forEach(b=>{
    if(b.textContent.trim() !== b.dataset.answer){
      ok = false;
    }
  });
  const r = document.getElementById("result");
  if(ok){
    r.textContent="🌟 Correct!";
    r.className="result good";
  }else{
    r.textContent="🔁 Try again!";
    r.className="result try";
  }
}
</script>

<?php endif; ?>

</body>
</html>
