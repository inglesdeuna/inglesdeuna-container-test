<?php
declare(strict_types=1);
if(session_status()===PHP_SESSION_NONE)session_start();
require_once __DIR__.'/../../config/db.php';
require_once __DIR__.'/_quiz_lib.php';
if(!isset($_SESSION['academic_logged'])||$_SESSION['academic_logged']!==true){header('Location: /lessons/lessons/academic/login.php');exit;}
function tq_h(string $v):