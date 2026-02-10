<?php

require_once __DIR__ . "/db.php";

echo "<h2>DB INIT</h2>";

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

echo "✅ programs OK<br>";

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

echo "✅ courses OK<br>";

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

echo "✅ units OK<br>";

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

echo "✅ activities OK<br>";

/* ===============================
   UNIQUE activities (unit + type)
   =============================== */

try {

    $pdo->exec("
        ALTER TABLE activities
        ADD CONSTRAINT activities_unit_type_unique
        UNIQUE (unit_id, type);
    ");

    echo "✅ UNIQUE activities constraint OK<br>";

} catch (Exception $e) {
    echo "⚠ UNIQUE constraint ya existe<br>";
}

/* ===============================
   DONE
   =============================== */

echo "<h3>✅ DB INIT OK</h3>";
