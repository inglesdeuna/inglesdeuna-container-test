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

    /* ===============================
       TEACHERS (inscripciones)
       =============================== */
    $pdo->exec("
    CREATE TABLE IF NOT EXISTS teachers (
        id TEXT PRIMARY KEY,
        name TEXT NOT NULL,
        id_number TEXT,
        phone TEXT,
        bank_account TEXT,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    );
    ");

    /* ===============================
       STUDENTS (inscripciones)
       =============================== */
    $pdo->exec("
    CREATE TABLE IF NOT EXISTS students (
        id TEXT PRIMARY KEY,
        name TEXT NOT NULL,
        guardian TEXT,
        contact TEXT,
        eps TEXT,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    );
    ");

    /* ===============================
       TEACHER ACCOUNTS
       =============================== */
    $pdo->exec("
    CREATE TABLE IF NOT EXISTS teacher_accounts (
        id TEXT PRIMARY KEY,
        teacher_id TEXT NOT NULL,
        teacher_name TEXT NOT NULL,
        username TEXT NOT NULL,
        password TEXT NOT NULL,
        permission TEXT,
        scope TEXT,
        target_id TEXT,
        target_name TEXT,
        must_change_password BOOLEAN DEFAULT FALSE,
        password_updated_at TIMESTAMP,
        is_active BOOLEAN DEFAULT TRUE,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    );
    ");

    try {
        $pdo->exec("CREATE UNIQUE INDEX IF NOT EXISTS teacher_accounts_username_idx ON teacher_accounts (username);");
    } catch (Exception $e) {
        // índice ya existe
    }

    /* ===============================
       TEACHER ASSIGNMENTS (asignaciones)
       =============================== */
    $pdo->exec("
    CREATE TABLE IF NOT EXISTS teacher_assignments (
        id TEXT PRIMARY KEY,
        teacher_id TEXT NOT NULL,
        teacher_name TEXT NOT NULL,
        program_type TEXT NOT NULL,
        course_id TEXT NOT NULL,
        course_name TEXT NOT NULL,
        unit_id TEXT,
        unit_name TEXT,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    );
    ");

    try {
        $pdo->exec("CREATE INDEX IF NOT EXISTS teacher_assignments_teacher_id_idx ON teacher_assignments (teacher_id);");
        $pdo->exec("CREATE INDEX IF NOT EXISTS teacher_assignments_course_id_idx ON teacher_assignments (course_id);");
    } catch (Exception $e) {
        // índice ya existe
    }

    /* ===============================
       STUDENT ASSIGNMENTS (asignaciones)
       =============================== */
    $pdo->exec("
    CREATE TABLE IF NOT EXISTS student_assignments (
        id TEXT PRIMARY KEY,
        student_id TEXT NOT NULL,
        teacher_id TEXT NOT NULL,
        program TEXT,
        course_id TEXT,
        level_id TEXT,
        period TEXT,
        unit_id TEXT,
        student_username TEXT,
        student_temp_password TEXT,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    );
    ");

    try {
        $pdo->exec("CREATE INDEX IF NOT EXISTS student_assignments_student_id_idx ON student_assignments (student_id);");
        $pdo->exec("CREATE INDEX IF NOT EXISTS student_assignments_teacher_id_idx ON student_assignments (teacher_id);");
    } catch (Exception $e) {
        // índice ya existe
    }

    /* ===============================
       EVAL_EXAMS
       =============================== */
    $pdo->exec("
    CREATE TABLE IF NOT EXISTS eval_exams (
        id SERIAL PRIMARY KEY,
        title TEXT NOT NULL,
        cefr_level VARCHAR(3),
        time_limit_min INT DEFAULT 50,
        max_attempts INT DEFAULT 1,
        status VARCHAR(20) DEFAULT 'draft',
        modalities JSONB DEFAULT '[\"online\",\"printed\"]',
        instructions TEXT,
        exam_config_json TEXT,
        created_by TEXT,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    );
    ");

    // Agregar unit_id a eval_exams si no existe (migración).
    // Se agrega la columna primero sin FK para evitar que un error de tipo (units.id INTEGER en DBs antiguas)
    // deje la transacción de PostgreSQL en estado abortado. Luego se intenta agregar la FK con un SAVEPOINT
    // para que un fallo no afecte el resto de la transacción.
    $pdo->exec("ALTER TABLE eval_exams ADD COLUMN IF NOT EXISTS unit_id TEXT");
    try {
        $pdo->exec("SAVEPOINT before_unit_fk");
        $pdo->exec("ALTER TABLE eval_exams ADD CONSTRAINT eval_exams_unit_id_fkey FOREIGN KEY (unit_id) REFERENCES units(id) ON DELETE SET NULL");
        $pdo->exec("RELEASE SAVEPOINT before_unit_fk");
    } catch (Exception $e) {
        $pdo->exec("ROLLBACK TO SAVEPOINT before_unit_fk");
        // FK no pudo agregarse (tipo incompatible o ya existe) — se continúa sin FK
    }

    /* ===============================
       EVAL_QUESTIONS
       =============================== */
    $pdo->exec("
    CREATE TABLE IF NOT EXISTS eval_questions (
        id SERIAL PRIMARY KEY,
        exam_id INT REFERENCES eval_exams(id) ON DELETE CASCADE,
        type VARCHAR(40),
        skill VARCHAR(20),
        question_text TEXT,
        audio_url TEXT,
        image_url TEXT,
        points NUMERIC(5,2) DEFAULT 1,
        position INT DEFAULT 0,
        data JSONB
    );
    ");

    /* ===============================
       EVAL_ANSWERS
       =============================== */
    $pdo->exec("
    CREATE TABLE IF NOT EXISTS eval_answers (
        id SERIAL PRIMARY KEY,
        question_id INT REFERENCES eval_questions(id) ON DELETE CASCADE,
        answer_text TEXT,
        is_correct BOOLEAN DEFAULT FALSE,
        order_index INT DEFAULT 0,
        feedback TEXT
    );
    ");

    /* ===============================
       EVAL_LINKS
       =============================== */
    $pdo->exec("
    CREATE TABLE IF NOT EXISTS eval_links (
        id SERIAL PRIMARY KEY,
        exam_id INT REFERENCES eval_exams(id) ON DELETE CASCADE,
        token VARCHAR(32) UNIQUE NOT NULL,
        link_type VARCHAR(20) DEFAULT 'group',
        student_name TEXT,
        student_doc TEXT,
        student_phone TEXT,
        student_email TEXT,
        student_program TEXT,
        expires_at TIMESTAMP,
        max_uses INT DEFAULT 999,
        uses_count INT DEFAULT 0,
        exam_config_json TEXT,
        created_by TEXT,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    );
    ");

    /* ===============================
       EVAL_RESULTS
       =============================== */
    $pdo->exec("
    CREATE TABLE IF NOT EXISTS eval_results (
        id SERIAL PRIMARY KEY,
        exam_id INT REFERENCES eval_exams(id) ON DELETE SET NULL,
        link_id INT REFERENCES eval_links(id) ON DELETE SET NULL,
        student_name TEXT,
        student_doc TEXT,
        student_phone TEXT,
        student_email TEXT,
        modality VARCHAR(20) DEFAULT 'online',
        score NUMERIC(6,2),
        max_score NUMERIC(6,2),
        pct NUMERIC(5,2),
        cefr_suggested VARCHAR(3),
        answers_json JSONB,
        skill_scores JSONB,
        selection_json TEXT,
        status VARCHAR(20) DEFAULT 'started',
        started_at TIMESTAMP,
        submitted_at TIMESTAMP
    );
    ");

    /* ===============================
       EVAL_CEFR_RANGES
       =============================== */
    $pdo->exec("
    CREATE TABLE IF NOT EXISTS eval_cefr_ranges (
        id SERIAL PRIMARY KEY,
        exam_id INT REFERENCES eval_exams(id) ON DELETE CASCADE,
        cefr_level VARCHAR(5) NOT NULL,
        label TEXT,
        min_pct NUMERIC(5,2),
        max_pct NUMERIC(5,2),
        is_global BOOLEAN DEFAULT FALSE
    );
    ");

    // Rangos globales por defecto
    $pdo->exec("
    INSERT INTO eval_cefr_ranges (cefr_level, label, min_pct, max_pct, is_global)
    SELECT 'A1','Principiante',0,20,TRUE
    WHERE NOT EXISTS (SELECT 1 FROM eval_cefr_ranges WHERE is_global=TRUE LIMIT 1);
    ");
    $pdo->exec("
    INSERT INTO eval_cefr_ranges (cefr_level, label, min_pct, max_pct, is_global)
    SELECT 'A2','Basico',21,40,TRUE
    WHERE NOT EXISTS (SELECT 1 FROM eval_cefr_ranges WHERE is_global=TRUE AND cefr_level='A2' LIMIT 1);
    ");
    $pdo->exec("
    INSERT INTO eval_cefr_ranges (cefr_level, label, min_pct, max_pct, is_global)
    SELECT 'B1','Intermedio',41,60,TRUE
    WHERE NOT EXISTS (SELECT 1 FROM eval_cefr_ranges WHERE is_global=TRUE AND cefr_level='B1' LIMIT 1);
    ");
    $pdo->exec("
    INSERT INTO eval_cefr_ranges (cefr_level, label, min_pct, max_pct, is_global)
    SELECT 'B2','Intermedio alto',61,80,TRUE
    WHERE NOT EXISTS (SELECT 1 FROM eval_cefr_ranges WHERE is_global=TRUE AND cefr_level='B2' LIMIT 1);
    ");
    $pdo->exec("
    INSERT INTO eval_cefr_ranges (cefr_level, label, min_pct, max_pct, is_global)
    SELECT 'C1','Avanzado',81,100,TRUE
    WHERE NOT EXISTS (SELECT 1 FROM eval_cefr_ranges WHERE is_global=TRUE AND cefr_level='C1' LIMIT 1);
    ");

} catch (Exception $e) {
    die("DB INIT ERROR: " . $e->getMessage());
}
