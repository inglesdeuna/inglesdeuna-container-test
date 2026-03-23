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
       ADMIN USERS
       =============================== */
    $pdo->exec("
    CREATE TABLE IF NOT EXISTS admin_users (
        id TEXT PRIMARY KEY,
        email TEXT UNIQUE NOT NULL,
        password_hash TEXT NOT NULL,
        role TEXT DEFAULT 'admin',
        is_active BOOLEAN DEFAULT TRUE,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    );
    ");

    // Seed default admin user if none exists
    try {
        $stmt = $pdo->query("SELECT COUNT(*) FROM admin_users WHERE email = 'admin@lets.com'");
        $count = $stmt->fetchColumn();
        
        if ($count == 0) {
            // Hash the password using bcrypt
            require_once __DIR__ . '/security.php';
            $hashedPassword = Security::hashPassword('1234');
            
            $insertStmt = $pdo->prepare("
                INSERT INTO admin_users (id, email, password_hash, role, is_active)
                VALUES (?, ?, ?, 'admin', TRUE)
            ");
            $insertStmt->execute([
                'admin_1',
                'admin@lets.com',
                $hashedPassword
            ]);
        }
    } catch (Exception $e) {
        // Table might not exist yet or other error - not critical on init
    }

} catch (Exception $e) {
    die("DB INIT ERROR: " . $e->getMessage());
}
