<?php

require_once __DIR__ . "/db.php";

/*
DB INIT
Este archivo:
- Crea tablas si no existen
- NO imprime nada
- NO rompe headers
*/

try {

    /* ===============================
       PROGRAMS
       =============================== */
    $pdo->exec("
    CREATE TABLE IF NOT EXISTS programs (
        id TEXT PRIMARY KEY,
        name TEXT NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    );
    ");

    /* ===============================
       COURSES
       =============================== */
    $pdo->exec("
    CREATE TABLE IF NOT EXISTS courses (
        id TEXT PRIMARY KEY,
        program_id TEXT REFERENCES programs(id) ON DELETE CASCADE,
        name TEXT NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    );
    ");

    /* ===============================
       UNITS
       =============================== */
    $pdo->exec("
    CREATE TABLE IF NOT EXISTS units (
        id TEXT PRIMARY KEY,
        course_id TEXT REFERENCES courses(id) ON DELETE CASCADE,
        name TEXT NOT NULL,
        position INT DEFAULT 0,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    );
    ");

    /* ===============================
       ACTIVITIES
       =============================== */
    $pdo->exec("
    CREATE TABLE IF NOT EXISTS activities (
        id SERIAL PRIMARY KEY,
        unit_id TEXT REFERENCES units(id) ON DELETE CASCADE,
        type TEXT NOT NULL,
        data JSONB,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    );
    ");

    /* ===============================
       UNIQUE (unit + type)
       =============================== */
    try {
        $pdo->exec("
            ALTER TABLE activities
            ADD CONSTRAINT activities_unit_type_unique
            UNIQUE (unit_id, type);
        ");
    } catch (Exception $e) {
        // Ya existe, no hacemos nada
    }

} catch (Exception $e) {
    die("DB INIT ERROR: " . $e->getMessage());
}
