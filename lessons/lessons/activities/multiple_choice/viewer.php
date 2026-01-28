<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Multiple Choice</title>

<style>
body{
  font-family: Arial, sans-serif;
  background:#f5f7fb;
  padding:20px;
}
.card{
  background:#fff;
  max-width:700px;
  margin:40px auto;
  padding:25px;
  border-radius:12px;
  box-shadow:0 10px 30px rgba(0,0,0,.08);
}
.question{
  font-size:20px;
  margin-bottom:15px;
}
.media img{
  max-width:100%;
  border-radius:10px;
  margin:10px 0;
}
.media audio{
  width:100%;
  margin:10px 0;
}
.options button{
  display:block;
  width:100%;
  margin:10px 0;
  padding:12px;
  border-radius:8px;
  border:1px solid #ddd;
  background:#f9fafb;
  cursor:pointer;
  font-size:16px;
}
.options button:hover{
  background:#eef2ff;
}
.correct{background:#dcfce7;border-color:#22c55e}
.wrong{background:#fee2e2;border-color:#ef4444}
</style>
</head>

<body>

<div class="card">
  <div class="question">
    What animal is this?
  </div>

  <!-- MEDIA (puede haber ninguno, uno o ambos) -->
  <div class="media">
    <!-- imagen opcional -->
    <img src="https://images.unsplash.com/photo-1552410260-0fd9b577afa6?w=800" alt="">
    
    <!-- audio opcional -->
    <audio controls>
      <source src="https://www.soundhelix.com/examples/mp3/SoundHelix-Song-1.mp3">
    </audio>
  </div>

  <div class="options">
    <button onclick="check(this,false)">Cat</button>
    <button onclick="check(this,true)">Dog</button>
    <button onclick="check(this,false)">Bird</button>
  </div>
</div>

<script>
function check(btn, correct){
  document.querySelectorAll("button").forEach(b=>b.disabled=true);
  btn.classList.add(correct ? "correct" : "wrong");
}
</script>

</body>
</html>
