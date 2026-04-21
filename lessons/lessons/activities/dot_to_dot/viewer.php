<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Dot to Dot</title>

<style>
  body {
    margin: 0;
    background: #f5f5f5;
  }

  #game {
    position: relative;
    width: 400px;
    height: 400px;
    margin: 50px auto;
    background: white;
    border: 1px solid #ccc;
  }

  .dot {
    width: 10px;
    height: 10px;
    background: black;
    border-radius: 50%;
    position: absolute;
  }

  .number {
    position: absolute;
    font-size: 12px;
    color: red;
  }
</style>
</head>

<body>

<div id="game">

  <!-- DOTS -->
  <div class="dot" style="left: 100px; top: 50px;"></div>
  <div class="number" style="left: 100px; top: 30px;">1</div>

  <div class="dot" style="left: 150px; top: 100px;"></div>
  <div class="number" style="left: 150px; top: 80px;">2</div>

  <div class="dot" style="left: 200px; top: 150px;"></div>
  <div class="number" style="left: 200px; top: 130px;">3</div>

  <div class="dot" style="left: 250px; top: 200px;"></div>
  <div class="number" style="left: 250px; top: 180px;">4</div>

</div>

</body>
</html>
