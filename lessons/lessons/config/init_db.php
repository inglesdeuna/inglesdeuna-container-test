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

    /* Columna dedicada para binarios grandes (ej. PDFs de flipbooks) que no
       deben almacenarse dentro del JSONB 'data' — insertar un base64 grande
       ahi obliga a Postgres a construir/parsear el arbol jsonb completo en
       memoria, lo que puede tumbar la conexion en instancias pequenas. */
    $pdo->exec("ALTER TABLE activities ADD COLUMN IF NOT EXISTS pdf_data BYTEA");

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
        $pdo->exec("CREATE UNIQUE INDEX IF NOT EXISTS student_assignments_unique_enrollment ON student_assignments (student_id, program, course_id, COALESCE(level_id,''), unit_id);");
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

    /* =========================================================
       MIGRACIÓN DE TIPOS: units.id y activities.unit_id deben
       ser TEXT. Las bases creadas con el esquema antiguo usaban
       INTEGER (units.id SERIAL, activities.unit_id INT), lo que
       hace fallar la FK con SQLSTATE 42804 (tipos incompatibles).
       La migración es idempotente y atómica (transacción).
       ========================================================= */
    $textTypes = ['text', 'character varying'];

    $colTypeStmt = $pdo->prepare("
        SELECT data_type FROM information_schema.columns
        WHERE table_schema = 'public' AND table_name = :tbl AND column_name = :col
    ");

    $colTypeStmt->execute([':tbl' => 'units', ':col' => 'id']);
    $unitsIdType = strtolower((string)$colTypeStmt->fetchColumn());

    $colTypeStmt->execute([':tbl' => 'activities', ':col' => 'unit_id']);
    $activitiesUnitIdType = strtolower((string)$colTypeStmt->fetchColumn());

    $colTypeStmt->execute([':tbl' => 'eval_exams', ':col' => 'unit_id']);
    $evalExamsUnitIdType = strtolower((string)$colTypeStmt->fetchColumn());

    $needsTypeMigration =
        !in_array($unitsIdType, $textTypes, true)
        || !in_array($activitiesUnitIdType, $textTypes, true)
        || ($evalExamsUnitIdType !== '' && !in_array($evalExamsUnitIdType, $textTypes, true));

    if ($needsTypeMigration) {
        $pdo->beginTransaction();
        try {
            /* Eliminar TODAS las FK que referencian a units, sin importar
               su nombre (activities_unit_id_fkey, fk_activities_unit,
               eval_exams_unit_id_fkey, etc.). Si quedara alguna, ALTER
               COLUMN TYPE intentaría reconstruirla y fallaría con 42804. */
            $dropFkStmts = $pdo->query("
                SELECT format('ALTER TABLE %I.%I DROP CONSTRAINT %I',
                              nsp.nspname, rel.relname, con.conname)
                FROM pg_constraint con
                JOIN pg_class rel ON rel.oid = con.conrelid
                JOIN pg_namespace nsp ON nsp.oid = rel.relnamespace
                WHERE con.contype = 'f'
                  AND con.confrelid = 'public.units'::regclass
            ")->fetchAll(PDO::FETCH_COLUMN);
            foreach ($dropFkStmts as $dropFkStmt) {
                $pdo->exec($dropFkStmt);
            }

            /* Convertir las columnas para que todas queden en TEXT. */
            if (!in_array($unitsIdType, $textTypes, true)) {
                $pdo->exec("ALTER TABLE units ALTER COLUMN id DROP DEFAULT");
                $pdo->exec("ALTER TABLE units ALTER COLUMN id TYPE TEXT USING id::text");
            }
            if (!in_array($activitiesUnitIdType, $textTypes, true)) {
                $pdo->exec("ALTER TABLE activities ALTER COLUMN unit_id DROP DEFAULT");
                $pdo->exec("ALTER TABLE activities ALTER COLUMN unit_id TYPE TEXT USING unit_id::text");
            }
            if ($evalExamsUnitIdType !== '' && !in_array($evalExamsUnitIdType, $textTypes, true)) {
                $pdo->exec("ALTER TABLE eval_exams ALTER COLUMN unit_id DROP DEFAULT");
                $pdo->exec("ALTER TABLE eval_exams ALTER COLUMN unit_id TYPE TEXT USING unit_id::text");
            }

            $pdo->commit();
        } catch (Exception $e) {
            $pdo->rollBack();
            throw $e;
        }
    }

    // Agregar unit_id a eval_exams si no existe (migración).
    $pdo->exec("ALTER TABLE eval_exams ADD COLUMN IF NOT EXISTS unit_id TEXT");

    /* Restaurar la FK activities.unit_id -> units.id si falta
       (ahora ambas columnas son TEXT, por lo que es válida). */
    $fkActivitiesExists = $pdo->query("
        SELECT 1 FROM pg_constraint
        WHERE contype = 'f'
          AND conrelid  = 'public.activities'::regclass
          AND confrelid = 'public.units'::regclass
        LIMIT 1
    ")->fetchColumn();
    if (!$fkActivitiesExists) {
        /* Eliminar huérfanos que impedirían validar la FK
           (equivale al ON DELETE CASCADE que estuvo ausente). */
        $pdo->exec("
            DELETE FROM activities a
            WHERE a.unit_id IS NOT NULL
              AND NOT EXISTS (SELECT 1 FROM units u WHERE u.id = a.unit_id)
        ");
        $pdo->exec("
            ALTER TABLE activities
            ADD CONSTRAINT activities_unit_id_fkey
            FOREIGN KEY (unit_id) REFERENCES units(id) ON DELETE CASCADE
        ");
    }

    /* Restaurar la FK eval_exams.unit_id -> units.id si falta. */
    $fkEvalExamsExists = $pdo->query("
        SELECT 1 FROM pg_constraint
        WHERE contype = 'f'
          AND conrelid  = 'public.eval_exams'::regclass
          AND confrelid = 'public.units'::regclass
        LIMIT 1
    ")->fetchColumn();
    if (!$fkEvalExamsExists) {
        $pdo->exec("
            UPDATE eval_exams e
            SET unit_id = NULL
            WHERE e.unit_id IS NOT NULL
              AND NOT EXISTS (SELECT 1 FROM units u WHERE u.id = e.unit_id)
        ");
        try {
            $pdo->exec("
                ALTER TABLE eval_exams
                ADD CONSTRAINT eval_exams_unit_id_fkey
                FOREIGN KEY (unit_id) REFERENCES units(id) ON DELETE SET NULL
            ");
        } catch (Exception $e) {
            // FK no pudo agregarse — se continúa sin FK
        }
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

    /* ===============================
       PLACEMENT TEST MIGRATIONS
       Idempotent — safe to run on every request.
       =============================== */
    $pdo->exec("ALTER TABLE eval_exams ADD COLUMN IF NOT EXISTS is_placement BOOLEAN DEFAULT FALSE");
    $pdo->exec("ALTER TABLE eval_links ADD COLUMN IF NOT EXISTS student_program TEXT");

    /* ===============================
       QUIZ_SHARE_LINKS
       Enlaces únicos para compartir un quiz existente (por unidad)
       =============================== */
    $pdo->exec("
    CREATE TABLE IF NOT EXISTS quiz_share_links (
        id SERIAL PRIMARY KEY,
        unit_id TEXT NOT NULL,
        token VARCHAR(32) UNIQUE NOT NULL,
        is_active BOOLEAN NOT NULL DEFAULT TRUE,
        created_by TEXT,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    );
    ");

    /* ===============================
       QUIZ_SHARE_RESPONSES
       Respuestas de estudiantes a quizzes compartidos por enlace
       =============================== */
    $pdo->exec("
    CREATE TABLE IF NOT EXISTS quiz_share_responses (
        id SERIAL PRIMARY KEY,
        link_id INT REFERENCES quiz_share_links(id) ON DELETE CASCADE,
        unit_id TEXT NOT NULL,
        student_name TEXT NOT NULL,
        quiz_set_json TEXT NOT NULL DEFAULT '[]',
        answers_json TEXT NOT NULL DEFAULT '[]',
        score_percent INT NOT NULL DEFAULT 0,
        correct_count INT NOT NULL DEFAULT 0,
        wrong_count INT NOT NULL DEFAULT 0,
        total_count INT NOT NULL DEFAULT 0,
        submitted_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    );
    ");

} catch (Exception $e) {
    die("DB INIT ERROR: " . $e->getMessage());
}
