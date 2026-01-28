<?php
$file = __DIR__ . "/questions.json";
$questions = file_exists($file) ? json_decode(file_get_contents($file), true) : [];

if (!$questions || count($questions) === 0) {
    die("No hay preguntas configuradas.");
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<title>Multiple Choice</title>
<style>
body{
  font-family:Arial;
  background:#f5f7fb;
  padding:20px;
}
.card{
  background:#fff;
  padding:25px;
  border-radius:14px;
  max-width:800px;
  margin:auto;
}
h2{margin-bottom:10px}
.option{
  background:#f1f5f9;
  padding:12px;
  border-radius:8px;
  margin:8px 0;
  cursor:pointer;
}
.option:hover{background:#e2e8f0}
.correct{background:#bbf7d0}
.wrong{background:#fecaca}
button{
  margin-top:15px;
  padding:10px 20px;
  border:none;
  background:#2563eb;
  color:#fff;
  border-radius:6px;
  cursor:pointer;
}
img{max-width:100%;border-radius:10px;margin:10px 0}
audio{margin:10px 0;width:100%}
.hidden{display:none}
</style>
</head>

<body>

<div class="card">
<h2 id="question"></h2>

<img id="image" class="hidden">
<audio id="audio" class="hidden" controls></audio>

<div id="options"></div>

<button id="next" class="hidden">Siguiente</button>
</div>

<script>
const questions = <?= json_encode($questions) ?>;
let current = 0;

const qEl = document.getElementById("question");
const imgEl = document.getElementById("image");
const audioEl = document.getElementById("audio");
const optEl = document.getElementById("options");
const nextBtn = document.getElementById("next");

function loadQuestion(){
  nextBtn.classList.add("hidden");
  optEl.innerHTML = "";

  const q = questions[current];
  qEl.textContent = q.question;

  // Image
  if(q.image){
    imgEl.src = q.image;
    imgEl.classList.remove("hidden");
  } else imgEl.classList.add("hidden");

  // Audio
  if(q.audio){
    audioEl.src = q.audio;
    audioEl.classList.remove("hidden");
  } else audioEl.classList.add("hidden");

  q.options.forEach((opt, i)=>{
    const div = document.createElement("div");
    div.className = "option";
    div.textContent = opt;
    div.onclick = ()=>checkAnswer(div, i);
    optEl.appendChild(div);
  });
}

function checkAnswer(el, i){
  const q = questions[current];
  document.querySelectorAll(".option").forEach(o=>o.onclick=null);

  if(i === q.answer){
    el.classList.add("correct");
  } else {
    el.classList.add("wrong");
    optEl.children[q.answer].classList.add("correct");
  }
  nextBtn.classList.remove("hidden");
}

nextBtn.onclick = ()=>{
  current++;
  if(current < questions.length){
    loadQuestion();
  } else {
    qEl.textContent = "🎉 ¡Actividad completada!";
    optEl.innerHTML = "";
    nextBtn.classList.add("hidden");
    imgEl.classList.add("hidden");
    audioEl.classList.add("hidden");
  }
};

loadQuestion();
</script>

</body>
</html>
