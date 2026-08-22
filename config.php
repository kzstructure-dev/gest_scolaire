<?php
declare(strict_types=1);

define('DB_PATH', getenv('GEST_SCOLAIRE_DB') ?: __DIR__ . DIRECTORY_SEPARATOR . 'data' . DIRECTORY_SEPARATOR . 'gest_scolaire.sqlite');

function database(): PDO
{
    static $pdo = null;

    if ($pdo instanceof PDO) {
        return $pdo;
    }

    $directory = dirname(DB_PATH);
    if (!is_dir($directory)) {
        mkdir($directory, 0775, true);
    }

    $pdo = new PDO('sqlite:' . DB_PATH);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    $pdo->exec('PRAGMA foreign_keys = ON');

    $pdo->exec(<<<'SQL'
        CREATE TABLE IF NOT EXISTS students (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            first_name TEXT NOT NULL,
            last_name TEXT NOT NULL,
            class_name TEXT NOT NULL,
            cycle TEXT NOT NULL DEFAULT 'Secondaire',
            phone TEXT NOT NULL DEFAULT '',
            status TEXT NOT NULL DEFAULT 'Inscrit',
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
        )
    SQL);

    $columns = $pdo->query('PRAGMA table_info(students)')->fetchAll();
    $hasCycle = array_filter($columns, static fn (array $column): bool => $column['name'] === 'cycle');
    if ($hasCycle === []) {
        $pdo->exec("ALTER TABLE students ADD COLUMN cycle TEXT NOT NULL DEFAULT 'Secondaire'");
    }

    $pdo->exec("UPDATE students SET cycle = 'Primaire' WHERE class_name LIKE 'CP%' OR class_name LIKE 'CE%' OR class_name LIKE 'CM%'");
    $pdo->exec("UPDATE students SET cycle = 'Secondaire' WHERE cycle IS NULL OR cycle = ''");

    $pdo->exec(<<<'SQL'
        CREATE TABLE IF NOT EXISTS establishments (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            name TEXT NOT NULL,
            code TEXT UNIQUE,
            type TEXT NOT NULL DEFAULT 'Prive',
            address TEXT NOT NULL DEFAULT '',
            city TEXT NOT NULL DEFAULT 'Abidjan',
            phone TEXT NOT NULL DEFAULT '',
            email TEXT NOT NULL DEFAULT '',
            logo_path TEXT,
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
        );
        CREATE TABLE IF NOT EXISTS sites (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            establishment_id INTEGER NOT NULL REFERENCES establishments(id),
            name TEXT NOT NULL,
            address TEXT NOT NULL DEFAULT '',
            active INTEGER NOT NULL DEFAULT 1,
            UNIQUE(establishment_id, name)
        );
        CREATE TABLE IF NOT EXISTS school_years (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            establishment_id INTEGER NOT NULL REFERENCES establishments(id),
            label TEXT NOT NULL,
            start_date TEXT NOT NULL,
            end_date TEXT NOT NULL,
            status TEXT NOT NULL DEFAULT 'Ouverte',
            UNIQUE(establishment_id, label)
        );
        CREATE TABLE IF NOT EXISTS periods (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            school_year_id INTEGER NOT NULL REFERENCES school_years(id),
            name TEXT NOT NULL,
            start_date TEXT NOT NULL,
            end_date TEXT NOT NULL,
            UNIQUE(school_year_id, name)
        );
        CREATE TABLE IF NOT EXISTS calendars (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            school_year_id INTEGER NOT NULL REFERENCES school_years(id),
            title TEXT NOT NULL,
            event_date TEXT NOT NULL,
            event_type TEXT NOT NULL DEFAULT 'Evenement',
            description TEXT NOT NULL DEFAULT ''
        );
        CREATE TABLE IF NOT EXISTS roles (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            name TEXT NOT NULL UNIQUE,
            description TEXT NOT NULL DEFAULT ''
        );
        CREATE TABLE IF NOT EXISTS permissions (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            code TEXT NOT NULL UNIQUE,
            label TEXT NOT NULL
        );
        CREATE TABLE IF NOT EXISTS role_permissions (
            role_id INTEGER NOT NULL REFERENCES roles(id) ON DELETE CASCADE,
            permission_id INTEGER NOT NULL REFERENCES permissions(id) ON DELETE CASCADE,
            PRIMARY KEY(role_id, permission_id)
        );
        CREATE TABLE IF NOT EXISTS users (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            establishment_id INTEGER NOT NULL REFERENCES establishments(id),
            role_id INTEGER NOT NULL REFERENCES roles(id),
            first_name TEXT NOT NULL,
            last_name TEXT NOT NULL,
            email TEXT NOT NULL UNIQUE,
            password_hash TEXT NOT NULL,
            active INTEGER NOT NULL DEFAULT 1,
            last_login_at TEXT
        );
        CREATE TABLE IF NOT EXISTS audit_logs (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id INTEGER REFERENCES users(id),
            action TEXT NOT NULL,
            entity_type TEXT NOT NULL,
            entity_id INTEGER,
            before_json TEXT,
            after_json TEXT,
            reason TEXT,
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
        );
        CREATE TABLE IF NOT EXISTS cycles (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            name TEXT NOT NULL UNIQUE,
            description TEXT NOT NULL DEFAULT ''
        );
        CREATE TABLE IF NOT EXISTS levels (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            cycle_id INTEGER NOT NULL REFERENCES cycles(id),
            name TEXT NOT NULL,
            code TEXT NOT NULL UNIQUE,
            sort_order INTEGER NOT NULL DEFAULT 0,
            UNIQUE(cycle_id, name)
        );
        CREATE TABLE IF NOT EXISTS rooms (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            site_id INTEGER NOT NULL REFERENCES sites(id),
            name TEXT NOT NULL,
            room_type TEXT NOT NULL DEFAULT 'Classe',
            capacity INTEGER NOT NULL DEFAULT 0,
            UNIQUE(site_id, name)
        );
        CREATE TABLE IF NOT EXISTS subjects (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            name TEXT NOT NULL,
            code TEXT NOT NULL UNIQUE,
            default_coefficient REAL NOT NULL DEFAULT 1,
            active INTEGER NOT NULL DEFAULT 1
        );
        CREATE TABLE IF NOT EXISTS classes (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            site_id INTEGER NOT NULL REFERENCES sites(id),
            school_year_id INTEGER NOT NULL REFERENCES school_years(id),
            level_id INTEGER NOT NULL REFERENCES levels(id),
            room_id INTEGER REFERENCES rooms(id),
            name TEXT NOT NULL,
            capacity INTEGER NOT NULL DEFAULT 40,
            active INTEGER NOT NULL DEFAULT 1,
            UNIQUE(school_year_id, name)
        );
        CREATE TABLE IF NOT EXISTS candidates (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            first_name TEXT NOT NULL,
            last_name TEXT NOT NULL,
            birth_date TEXT,
            phone TEXT NOT NULL DEFAULT '',
            requested_level_id INTEGER REFERENCES levels(id),
            status TEXT NOT NULL DEFAULT 'En attente',
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
        );
        CREATE TABLE IF NOT EXISTS guardians (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            first_name TEXT NOT NULL,
            last_name TEXT NOT NULL,
            relationship TEXT NOT NULL DEFAULT 'Responsable',
            phone TEXT NOT NULL DEFAULT '',
            email TEXT NOT NULL DEFAULT '',
            address TEXT NOT NULL DEFAULT ''
        );
        CREATE TABLE IF NOT EXISTS student_guardians (
            student_id INTEGER NOT NULL REFERENCES students(id) ON DELETE CASCADE,
            guardian_id INTEGER NOT NULL REFERENCES guardians(id) ON DELETE CASCADE,
            is_legal INTEGER NOT NULL DEFAULT 1,
            can_pick_up INTEGER NOT NULL DEFAULT 0,
            PRIMARY KEY(student_id, guardian_id)
        );
        CREATE TABLE IF NOT EXISTS emergency_contacts (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            student_id INTEGER NOT NULL REFERENCES students(id) ON DELETE CASCADE,
            name TEXT NOT NULL,
            relationship TEXT NOT NULL DEFAULT '',
            phone TEXT NOT NULL,
            priority INTEGER NOT NULL DEFAULT 1
        );
        CREATE TABLE IF NOT EXISTS student_documents (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            student_id INTEGER NOT NULL REFERENCES students(id) ON DELETE CASCADE,
            document_type TEXT NOT NULL,
            file_path TEXT,
            status TEXT NOT NULL DEFAULT 'A verifier',
            submitted_at TEXT,
            verified_at TEXT,
            verified_by INTEGER REFERENCES users(id)
        );
        CREATE TABLE IF NOT EXISTS staff (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            establishment_id INTEGER NOT NULL REFERENCES establishments(id),
            first_name TEXT NOT NULL,
            last_name TEXT NOT NULL,
            staff_type TEXT NOT NULL,
            phone TEXT NOT NULL DEFAULT '',
            email TEXT NOT NULL DEFAULT '',
            active INTEGER NOT NULL DEFAULT 1
        );
        CREATE TABLE IF NOT EXISTS teachers (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            staff_id INTEGER NOT NULL UNIQUE REFERENCES staff(id),
            registration_number TEXT UNIQUE,
            specialty TEXT NOT NULL DEFAULT ''
        );
        CREATE TABLE IF NOT EXISTS teacher_assignments (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            teacher_id INTEGER NOT NULL REFERENCES teachers(id),
            class_id INTEGER NOT NULL REFERENCES classes(id),
            subject_id INTEGER NOT NULL REFERENCES subjects(id),
            weekly_hours REAL NOT NULL DEFAULT 0,
            UNIQUE(teacher_id, class_id, subject_id)
        );
        CREATE TABLE IF NOT EXISTS registrations (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            student_id INTEGER NOT NULL REFERENCES students(id),
            site_id INTEGER NOT NULL REFERENCES sites(id),
            school_year_id INTEGER NOT NULL REFERENCES school_years(id),
            class_id INTEGER NOT NULL REFERENCES classes(id),
            registration_number TEXT NOT NULL UNIQUE,
            registration_date TEXT NOT NULL DEFAULT CURRENT_DATE,
            status TEXT NOT NULL DEFAULT 'Validee',
            UNIQUE(student_id, school_year_id)
        );
        CREATE TABLE IF NOT EXISTS transfers (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            student_id INTEGER NOT NULL REFERENCES students(id),
            from_class_id INTEGER REFERENCES classes(id),
            to_class_id INTEGER REFERENCES classes(id),
            transfer_date TEXT NOT NULL DEFAULT CURRENT_DATE,
            reason TEXT NOT NULL DEFAULT '',
            status TEXT NOT NULL DEFAULT 'Valide'
        );
        CREATE TABLE IF NOT EXISTS courses (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            class_id INTEGER NOT NULL REFERENCES classes(id),
            subject_id INTEGER NOT NULL REFERENCES subjects(id),
            teacher_id INTEGER REFERENCES teachers(id),
            title TEXT NOT NULL,
            room_id INTEGER REFERENCES rooms(id),
            start_time TEXT,
            end_time TEXT,
            weekday INTEGER NOT NULL DEFAULT 1
        );
        CREATE TABLE IF NOT EXISTS timetables (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            course_id INTEGER NOT NULL REFERENCES courses(id) ON DELETE CASCADE,
            school_year_id INTEGER NOT NULL REFERENCES school_years(id),
            valid_from TEXT NOT NULL,
            valid_to TEXT,
            status TEXT NOT NULL DEFAULT 'Publie'
        );
        CREATE TABLE IF NOT EXISTS attendance (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            student_id INTEGER NOT NULL REFERENCES students(id),
            course_id INTEGER REFERENCES courses(id),
            class_id INTEGER NOT NULL REFERENCES classes(id),
            attendance_date TEXT NOT NULL,
            status TEXT NOT NULL CHECK(status IN ('Present','Absent','Retard','Dispense','Sortie autorisee')),
            minutes_late INTEGER NOT NULL DEFAULT 0,
            reason TEXT NOT NULL DEFAULT '',
            justified INTEGER NOT NULL DEFAULT 0,
            validated_by INTEGER REFERENCES users(id),
            UNIQUE(student_id, course_id, attendance_date)
        );
        CREATE TABLE IF NOT EXISTS evaluations (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            class_id INTEGER NOT NULL REFERENCES classes(id),
            subject_id INTEGER NOT NULL REFERENCES subjects(id),
            period_id INTEGER REFERENCES periods(id),
            teacher_id INTEGER REFERENCES teachers(id),
            title TEXT NOT NULL,
            evaluation_type TEXT NOT NULL DEFAULT 'Devoir',
            evaluation_date TEXT NOT NULL,
            scale REAL NOT NULL DEFAULT 20,
            coefficient REAL NOT NULL DEFAULT 1,
            status TEXT NOT NULL DEFAULT 'Brouillon'
        );
        CREATE TABLE IF NOT EXISTS grades (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            evaluation_id INTEGER NOT NULL REFERENCES evaluations(id) ON DELETE CASCADE,
            student_id INTEGER NOT NULL REFERENCES students(id),
            value REAL,
            appreciation TEXT NOT NULL DEFAULT '',
            status TEXT NOT NULL DEFAULT 'Saisie',
            validated_by INTEGER REFERENCES users(id),
            UNIQUE(evaluation_id, student_id)
        );
        CREATE TABLE IF NOT EXISTS report_cards (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            student_id INTEGER NOT NULL REFERENCES students(id),
            period_id INTEGER NOT NULL REFERENCES periods(id),
            average REAL,
            rank INTEGER,
            appreciation TEXT NOT NULL DEFAULT '',
            decision TEXT,
            status TEXT NOT NULL DEFAULT 'Brouillon',
            published_at TEXT,
            UNIQUE(student_id, period_id)
        );
        CREATE TABLE IF NOT EXISTS class_councils (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            class_id INTEGER NOT NULL REFERENCES classes(id),
            period_id INTEGER NOT NULL REFERENCES periods(id),
            meeting_date TEXT NOT NULL,
            minutes TEXT NOT NULL DEFAULT '',
            status TEXT NOT NULL DEFAULT 'Prepare'
        );
        CREATE TABLE IF NOT EXISTS tariffs (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            school_year_id INTEGER NOT NULL REFERENCES school_years(id),
            level_id INTEGER REFERENCES levels(id),
            label TEXT NOT NULL,
            amount REAL NOT NULL DEFAULT 0,
            due_date TEXT,
            active INTEGER NOT NULL DEFAULT 1
        );
        CREATE TABLE IF NOT EXISTS invoices (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            student_id INTEGER NOT NULL REFERENCES students(id),
            tariff_id INTEGER NOT NULL REFERENCES tariffs(id),
            invoice_number TEXT NOT NULL UNIQUE,
            amount REAL NOT NULL,
            due_date TEXT,
            status TEXT NOT NULL DEFAULT 'A payer'
        );
        CREATE TABLE IF NOT EXISTS installments (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            invoice_id INTEGER NOT NULL REFERENCES invoices(id) ON DELETE CASCADE,
            due_date TEXT NOT NULL,
            amount REAL NOT NULL,
            status TEXT NOT NULL DEFAULT 'A payer'
        );
        CREATE TABLE IF NOT EXISTS cash_registers (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            establishment_id INTEGER NOT NULL REFERENCES establishments(id),
            opened_by INTEGER REFERENCES users(id),
            opened_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            closed_at TEXT,
            opening_balance REAL NOT NULL DEFAULT 0,
            closing_balance REAL
        );
        CREATE TABLE IF NOT EXISTS payments (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            invoice_id INTEGER NOT NULL REFERENCES invoices(id),
            cash_register_id INTEGER REFERENCES cash_registers(id),
            amount REAL NOT NULL CHECK(amount > 0),
            payment_date TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            method TEXT NOT NULL DEFAULT 'Especes',
            status TEXT NOT NULL DEFAULT 'Valide',
            cancelled_reason TEXT
        );
        CREATE TABLE IF NOT EXISTS receipts (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            payment_id INTEGER NOT NULL UNIQUE REFERENCES payments(id),
            receipt_number TEXT NOT NULL UNIQUE,
            issued_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            issued_by INTEGER REFERENCES users(id)
        );
        CREATE TABLE IF NOT EXISTS messages (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            sender_id INTEGER REFERENCES users(id),
            subject TEXT NOT NULL,
            body TEXT NOT NULL,
            target_type TEXT NOT NULL DEFAULT 'Classe',
            target_id INTEGER,
            sent_at TEXT
        );
        CREATE TABLE IF NOT EXISTS notifications (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id INTEGER REFERENCES users(id),
            student_id INTEGER REFERENCES students(id),
            type TEXT NOT NULL,
            title TEXT NOT NULL,
            body TEXT NOT NULL DEFAULT '',
            channel TEXT NOT NULL DEFAULT 'Portail',
            status TEXT NOT NULL DEFAULT 'A envoyer',
            sent_at TEXT
        );
        CREATE TABLE IF NOT EXISTS incidents (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            student_id INTEGER NOT NULL REFERENCES students(id),
            reported_by INTEGER REFERENCES users(id),
            incident_type TEXT NOT NULL,
            description TEXT NOT NULL,
            incident_date TEXT NOT NULL,
            action_taken TEXT NOT NULL DEFAULT '',
            status TEXT NOT NULL DEFAULT 'Ouvert'
        );
        CREATE TABLE IF NOT EXISTS health_records (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            student_id INTEGER NOT NULL REFERENCES students(id),
            record_type TEXT NOT NULL,
            description TEXT NOT NULL DEFAULT '',
            recorded_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            access_level TEXT NOT NULL DEFAULT 'Infirmier'
        );
        CREATE TABLE IF NOT EXISTS library_books (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            isbn TEXT UNIQUE,
            title TEXT NOT NULL,
            author TEXT NOT NULL DEFAULT '',
            category TEXT NOT NULL DEFAULT '',
            total_copies INTEGER NOT NULL DEFAULT 1,
            available_copies INTEGER NOT NULL DEFAULT 1
        );
        CREATE TABLE IF NOT EXISTS loans (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            book_id INTEGER NOT NULL REFERENCES library_books(id),
            student_id INTEGER REFERENCES students(id),
            staff_id INTEGER REFERENCES staff(id),
            loaned_at TEXT NOT NULL DEFAULT CURRENT_DATE,
            due_date TEXT NOT NULL,
            returned_at TEXT,
            status TEXT NOT NULL DEFAULT 'En cours'
        );
        CREATE TABLE IF NOT EXISTS inventory_items (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            site_id INTEGER NOT NULL REFERENCES sites(id),
            label TEXT NOT NULL,
            category TEXT NOT NULL DEFAULT '',
            quantity INTEGER NOT NULL DEFAULT 1,
            condition_status TEXT NOT NULL DEFAULT 'Bon',
            location TEXT NOT NULL DEFAULT ''
        );
        CREATE INDEX IF NOT EXISTS idx_students_name ON students(last_name, first_name);
        CREATE INDEX IF NOT EXISTS idx_attendance_date ON attendance(attendance_date);
        CREATE INDEX IF NOT EXISTS idx_grades_student ON grades(student_id);
        CREATE INDEX IF NOT EXISTS idx_payments_date ON payments(payment_date);
    SQL);

    seedReferenceData($pdo);

    $count = (int) $pdo->query('SELECT COUNT(*) FROM students')->fetchColumn();
    if ($count === 0) {
        $seed = $pdo->prepare('INSERT INTO students (first_name, last_name, class_name, cycle, phone, status) VALUES (?, ?, ?, ?, ?, ?)');
        $students = [
            ['Aicha', 'Kouassi', '3e A', 'Secondaire', '07 08 22 14 90', 'Inscrite'],
            ['Koffi', 'Marc', 'Terminale C', 'Secondaire', '05 44 19 87 21', 'Inscrit'],
            ['Yao', 'Sarah', '6e B', 'Secondaire', '01 72 63 48 05', 'A verifier'],
            ['Diomande', 'Oumar', 'CM2 A', 'Primaire', '07 09 55 31 64', 'Inscrit'],
            ['Konan', 'Bintou', '4e C', 'Secondaire', '05 66 42 18 73', 'Retard paiement'],
        ];
        foreach ($students as $student) {
            $seed->execute($student);
        }
    }

    seedAcademicData($pdo);

    return $pdo;
}

function seedAcademicData(PDO $pdo): void
{
    $levels = $pdo->query('SELECT id, code, name FROM levels')->fetchAll();
    $levelIds = [];
    $levelNames = [];
    foreach ($levels as $level) {
        $levelIds[$level['code']] = (int) $level['id'];
        $levelNames[$level['code']] = $level['name'];
    }

    $classStatement = $pdo->prepare('INSERT OR IGNORE INTO classes (site_id, school_year_id, level_id, name, capacity) VALUES (1, 1, ?, ?, ?)');
    $primaryCodes = ['CP1', 'CP2', 'CE1', 'CE2', 'CM1', 'CM2'];
    $secondaryCodes = ['6E', '5E', '4E', '3E', '2NDE', '1ERE', 'TLE'];
    foreach (array_merge($primaryCodes, $secondaryCodes) as $levelCode) {
        if (!isset($levelIds[$levelCode])) {
            continue;
        }
        $capacity = in_array($levelCode, $primaryCodes, true) ? 30 : 35;
        foreach (['A', 'B', 'C'] as $section) {
            $classStatement->execute([$levelIds[$levelCode], $levelNames[$levelCode] . ' ' . $section, $capacity]);
        }
    }

    // Keep legacy class labels such as 4e C and Terminale C exactly as stored.
    $legacyClasses = [
        ['CM2 A', 'CM2', 30], ['6e B', '6E', 35], ['3e A', '3E', 35], ['4e C', '4E', 35], ['Terminale C', 'TLE', 35],
    ];
    foreach ($legacyClasses as [$name, $levelCode, $capacity]) {
        if (isset($levelIds[$levelCode])) {
            $classStatement->execute([$levelIds[$levelCode], $name, $capacity]);
        }
    }

    $registrationStatement = $pdo->prepare('INSERT OR IGNORE INTO registrations (student_id, site_id, school_year_id, class_id, registration_number, status) SELECT s.id, 1, 1, c.id, ?, ? FROM students s JOIN classes c ON c.name = s.class_name WHERE s.id = ?');
    $students = $pdo->query('SELECT id FROM students ORDER BY id')->fetchAll(PDO::FETCH_COLUMN);
    foreach ($students as $studentId) {
        $registrationStatement->execute(['INS-' . str_pad((string) $studentId, 5, '0', STR_PAD_LEFT), 'Validee', $studentId]);
    }
}

function seedReferenceData(PDO $pdo): void
{
    $pdo->exec("INSERT OR IGNORE INTO establishments (id, name, code, type, city) VALUES (1, 'Ecole Horizon', 'EH-ABJ', 'Prive', 'Abidjan')");
    $pdo->exec("INSERT OR IGNORE INTO sites (id, establishment_id, name, address) VALUES (1, 1, 'Site principal', 'Cocody, Abidjan')");
    $pdo->exec("INSERT OR IGNORE INTO school_years (id, establishment_id, label, start_date, end_date, status) VALUES (1, 1, '2025 - 2026', '2025-09-01', '2026-07-31', 'Ouverte')");
    $pdo->exec("INSERT OR IGNORE INTO periods (id, school_year_id, name, start_date, end_date) VALUES (1, 1, 'Trimestre 1', '2025-09-01', '2025-12-20'), (2, 1, 'Trimestre 2', '2026-01-05', '2026-03-31'), (3, 1, 'Trimestre 3', '2026-04-01', '2026-07-31')");
    $roles = ['Administrateur', 'Direction', 'Scolarite', 'Enseignant', 'Educateur', 'Comptable', 'Parent', 'Eleve'];
    $roleStatement = $pdo->prepare('INSERT OR IGNORE INTO roles (name) VALUES (?)');
    foreach ($roles as $role) {
        $roleStatement->execute([$role]);
    }
    $cycles = [['Primaire', 'Prescolaire et primaire'], ['Secondaire', 'College et lycee'], ['Technique et professionnel', 'Enseignement technique et professionnel']];
    $cycleStatement = $pdo->prepare('INSERT OR IGNORE INTO cycles (name, description) VALUES (?, ?)');
    foreach ($cycles as $cycle) {
        $cycleStatement->execute($cycle);
    }
    $levels = [
        ['Primaire', 'CP1', 'CP1'], ['Primaire', 'CP2', 'CP2'], ['Primaire', 'CE1', 'CE1'], ['Primaire', 'CE2', 'CE2'], ['Primaire', 'CM1', 'CM1'], ['Primaire', 'CM2', 'CM2'],
        ['Secondaire', '6e', '6E'], ['Secondaire', '5e', '5E'], ['Secondaire', '4e', '4E'], ['Secondaire', '3e', '3E'], ['Secondaire', '2nde', '2NDE'], ['Secondaire', '1ere', '1ERE'], ['Secondaire', 'Terminale', 'TLE'],
    ];
    $levelStatement = $pdo->prepare('INSERT OR IGNORE INTO levels (cycle_id, name, code, sort_order) SELECT id, ?, ?, ? FROM cycles WHERE name = ?');
    foreach ($levels as $order => [$cycle, $name, $code]) {
        $levelStatement->execute([$name, $code, $order + 1, $cycle]);
    }
    $subjects = [['Francais', 'FR'], ['Mathematiques', 'MATH'], ['Anglais', 'ANG'], ['Sciences', 'SCI'], ['Histoire-Geographie', 'HG'], ['Education physique', 'EPS'], ['Informatique', 'INFO']];
    $subjectStatement = $pdo->prepare('INSERT OR IGNORE INTO subjects (name, code) VALUES (?, ?)');
    foreach ($subjects as $subject) {
        $subjectStatement->execute($subject);
    }
}

function jsonResponse(array $payload, int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function initials(string $firstName, string $lastName): string
{
    return strtoupper(substr($firstName, 0, 1) . substr($lastName, 0, 1));
}
