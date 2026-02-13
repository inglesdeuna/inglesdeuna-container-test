<?php

$unit = $_GET["unit"] ?? "";
$course_id = $_GET["course"] ?? "";

?>

<style>
.activity-nav-bar{
    display:flex;
    gap:10px;
    margin-bottom:20px;
    flex-wrap:wrap;
}

.btn-nav{
    border:none;
    padding:10px 18px;
    border-radius:8px;
    cursor:pointer;
    font-weight:bold;
    font-size:14px;
    box-shadow:0 2px 6px rgba(0,0,0,0.15);
}

.btn-hub{ background:#1e88e5; color:white; }
.btn-course{ background:#43a047; color:white; }
.btn-dashboard{ background:#546e7a; color:white; }
</style>

<div class="activity-nav-bar">

    <button class="btn-nav btn-hub" onclick="goToHub()">
        ⬅ Volver al Hub
    </button>

    <button class="btn-nav btn-course" onclick="goToCourse()">
        📚 Volver al Curso
    </button>

    <button class="btn-nav btn-dashboard" onclick="goToDashboard()">
        🏠 Dashboard
    </button>

</div>

<script>

function goToHub(){
    const unit = "<?php echo htmlspecialchars($unit); ?>";
    window.location.href =
    "/lessons/lessons/academic/activities_hub.php?unit=" + unit;
}

function goToCourse(){
    const course = "<?php echo htmlspecialchars($course_id); ?>";

    if(course){
        window.location.href =
        "/lessons/lessons/academic/course_view.php?course=" + course;
    }else{
        alert("Curso no especificado");
    }
}

function goToDashboard(){
    window.location.href =
    "/lessons/lessons/academic/dashboard.php";
}

</script>
