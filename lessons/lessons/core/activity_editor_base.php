<?php
session_start();

/* ========= KEY ========= */

function activity_get_draft_key($activity_type, $unit){
    return "draft_".$activity_type."_".$unit;
}

/* ========= INIT ========= */

function activity_init_draft($activity_type, $unit){
    $key = activity_get_draft_key($activity_type, $unit);
    if(!isset($_SESSION[$key])){
        $_SESSION[$key] = [];
    }
}

/* ========= GET ========= */

function activity_get_draft($activity_type, $unit){
    $key = activity_get_draft_key($activity_type, $unit);
    return $_SESSION[$key] ?? [];
}

/* ========= ADD ========= */

function activity_add_item($activity_type, $unit, $item){
    $key = activity_get_draft_key($activity_type, $unit);
    $_SESSION[$key][] = $item;
}

/* ========= DELETE ========= */

function activity_delete_item($activity_type, $unit, $index){
    $key = activity_get_draft_key($activity_type, $unit);
    if(isset($_SESSION[$key][$index])){
        array_splice($_SESSION[$key], $index, 1);
    }
}

/* ========= CLEAR ========= */

function activity_clear_draft($activity_type, $unit){
    $key = activity_get_draft_key($activity_type, $unit);
    $_SESSION[$key] = [];
}

/* ========= SAVE DB ========= */

function activity_save_to_db($pdo, $activity_type, $unit){

    $draft = activity_get_draft($activity_type, $unit);

    if(empty($draft)) return;

    $stmt = $pdo->prepare("
        INSERT INTO activities(unit_id, activity_type, content_json)
        VALUES(:unit, :type, :json)
    ");

    $stmt->execute([
        ":unit"=>$unit,
        ":type"=>$activity_type,
        ":json"=>json_encode($draft, JSON_UNESCAPED_UNICODE)
    ]);

    activity_clear_draft($activity_type, $unit);
}
