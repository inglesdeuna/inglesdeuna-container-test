<?php
// core/activity_helpers.php

function validate_unit($unit) {
    return preg_match('/^[a-zA-Z0-9_\-]+$/', $unit);
}

function sanitize_json($json) {
    return json_encode(json_decode($json), JSON_UNESCAPED_UNICODE);
}

function require_admin_session() {
    session_start();
    if (!isset($_SESSION["admin_logged"])) {
        header("Location: ../admin/login.php");
        exit;
    }
}
