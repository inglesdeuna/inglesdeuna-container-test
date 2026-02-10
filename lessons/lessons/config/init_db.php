<?php

require __DIR__ . "/db.php";

$sql = "

CREATE TABLE IF NOT EXISTS programs (
    id TEXT PRIMARY KEY,
    name TEXT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS courses (
    id TEXT PRIMARY KEY,
    program_id TEXT REFERENCES programs(id) ON DELETE CASCADE,
    name TEXT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS units (
    id TEXT PRIMARY KEY,
    course_id TEXT REFERENCES courses(id) ON DELETE CASCADE,
    name TEXT NOT NULL,
    position INT DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

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
    echo "⚠ UNIQUE activities ya existe<br>";
}


CREATE TABLE IF NOT EXISTS activities (
    id TEXT PRIMARY KEY,
    unit_id TEXT REFERENCES units(id) ON DELETE CASCADE,
    type TEXT NOT NULL,
    data JSONB,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

";

try {
    $pdo->exec($sql);
    echo "✅ DB INIT OK";
} catch (PDOException $e) {
    echo "❌ ERROR: " . $e->getMessage();
}
