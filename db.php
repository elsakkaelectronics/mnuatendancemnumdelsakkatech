<?php

declare(strict_types=1);

const DATABASE_FILE = __DIR__ . '/attendance.sqlite';

function db_openDatabase(): PDO
{
    try {
        $conn = new PDO('sqlite:' . DATABASE_FILE);
        $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        db_ensureSchema($conn);

        return $conn;
    } catch (PDOException $e) {
        if (file_exists(DATABASE_FILE)) {
            unlink(DATABASE_FILE);
            $conn = new PDO('sqlite:' . DATABASE_FILE);
            $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            db_ensureSchema($conn);

            return $conn;
        }

        throw $e;
    }
}

function db_ensureSchema(PDO $conn): void
{
    $tableInfo = $conn->query('PRAGMA table_info(attendance)')->fetchAll(PDO::FETCH_ASSOC);

    if (!$tableInfo) {
        db_createAttendanceSchema($conn);
    } else {
        $columns = array_column($tableInfo, 'name');

        if (!in_array('isinrange', $columns, true)) {
            $conn->exec('ALTER TABLE attendance ADD COLUMN isinrange INTEGER NOT NULL DEFAULT 0');
        }

        if (!in_array('is_code_valid', $columns, true)) {
            $conn->exec('ALTER TABLE attendance ADD COLUMN is_code_valid INTEGER NOT NULL DEFAULT 0');
        }

        if (!in_array('password_hash', $columns, true)) {
            $conn->exec('ALTER TABLE attendance ADD COLUMN password_hash TEXT');
        }
    }

    db_createModuleSchema($conn);

    $lectureCodeInfo = $conn->query('PRAGMA table_info(lecture_codes)')->fetchAll(PDO::FETCH_ASSOC);
    $lectureCodeColumns = $lectureCodeInfo ? array_column($lectureCodeInfo, 'name') : [];
    if (!in_array('lecture_date', $lectureCodeColumns, true)) {
        $conn->exec('ALTER TABLE lecture_codes ADD COLUMN lecture_date TEXT');
    }

    $conn->exec('CREATE INDEX IF NOT EXISTS idx_attendance_created_at ON attendance (created_at)');
    $conn->exec('CREATE INDEX IF NOT EXISTS idx_attendance_group_name ON attendance (group_name)');
    $conn->exec('CREATE INDEX IF NOT EXISTS idx_lecture_codes_module ON lecture_codes (module_code)');
    $conn->exec('CREATE INDEX IF NOT EXISTS idx_student_modules_module ON student_modules (module_code)');
    $conn->exec('CREATE INDEX IF NOT EXISTS idx_student_modules_academic_number ON student_modules (academic_number)');

    db_seedDefaultModuleData($conn);
}

function db_createAttendanceSchema(PDO $conn): void
{
    $conn->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS attendance (
    academic_number TEXT NOT NULL PRIMARY KEY,
    name TEXT NOT NULL,
    mobile TEXT NOT NULL,
    group_name TEXT NOT NULL,
    latitude REAL NOT NULL,
    longitude REAL NOT NULL,
    code TEXT NOT NULL,
    responses_json TEXT NOT NULL DEFAULT '[]',
    response_count INTEGER NOT NULL DEFAULT 0,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    isinrange INTEGER NOT NULL DEFAULT 0,
    is_code_valid INTEGER NOT NULL DEFAULT 0
)
SQL
    );
}

function db_createModuleSchema(PDO $conn): void
{
    $conn->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS modules (
    module_code TEXT NOT NULL PRIMARY KEY,
    module_name TEXT NOT NULL,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
)
SQL
    );

    $conn->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS lecture_codes (
    code TEXT NOT NULL PRIMARY KEY,
    module_code TEXT NOT NULL,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY(module_code) REFERENCES modules(module_code)
)
SQL
    );

    $conn->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS student_modules (
    academic_number TEXT NOT NULL,
    module_code TEXT NOT NULL,
    enrolled_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (academic_number, module_code),
    FOREIGN KEY(module_code) REFERENCES modules(module_code)
)
SQL
    );
}

function db_seedDefaultModuleData(PDO $conn): void
{
    // Do not seed a default module automatically.
    // Modules should be created explicitly by the admin interface.
}

function db_parseLectureCodeEntry(string $entry): array
{
    $entry = trim($entry);
    if ($entry === '') {
        return [];
    }

    $parts = preg_split('/\s*[:|]\s*/', $entry, 2);
    $code = trim((string) ($parts[0] ?? ''));
    $lectureDate = isset($parts[1]) ? trim((string) $parts[1]) : '';

    if ($code === '') {
        return [];
    }

    if ($lectureDate !== '') {
        $timestamp = strtotime($lectureDate);
        if ($timestamp !== false) {
            $lectureDate = date('Y-m-d', $timestamp);
        } else {
            $lectureDate = '';
        }
    }

    return ['code' => $code, 'lecture_date' => $lectureDate === '' ? null : $lectureDate];
}

function db_saveModuleWithCodes(PDO $conn, string $moduleCode, string $moduleName, array $codes, bool $replaceExisting = false): void
{
    $moduleCode = trim((string) $moduleCode);
    if ($moduleCode === '') {
        throw new InvalidArgumentException('Module code is required.');
    }

    $conn->beginTransaction();
    $insertModule = $conn->prepare('INSERT OR REPLACE INTO modules (module_code, module_name, created_at) VALUES (:module_code, :module_name, CURRENT_TIMESTAMP)');
    $insertModule->execute([
        ':module_code' => $moduleCode,
        ':module_name' => trim((string) $moduleName),
    ]);

    if ($replaceExisting) {
        $deleteCodes = $conn->prepare('DELETE FROM lecture_codes WHERE module_code = :module_code');
        $deleteCodes->execute([':module_code' => $moduleCode]);
    }

    $insertCode = $conn->prepare('INSERT OR REPLACE INTO lecture_codes (code, module_code, lecture_date, created_at) VALUES (:code, :module_code, :lecture_date, CURRENT_TIMESTAMP)');

    foreach ($codes as $codeEntry) {
        if (is_array($codeEntry)) {
            $parsed = $codeEntry;
        } else {
            $parsed = db_parseLectureCodeEntry((string) $codeEntry);
        }

        if (empty($parsed['code'])) {
            continue;
        }

        $insertCode->execute([
            ':code' => trim((string) $parsed['code']),
            ':module_code' => $moduleCode,
            ':lecture_date' => $parsed['lecture_date'],
        ]);
    }

    $conn->commit();
}

function db_addModuleWithCodes(PDO $conn, string $moduleCode, string $moduleName, array $codes): void
{
    db_saveModuleWithCodes($conn, $moduleCode, $moduleName, $codes, true);
}

function db_updateModuleWithCodes(PDO $conn, string $moduleCode, string $moduleName, array $codes): void
{
    db_saveModuleWithCodes($conn, $moduleCode, $moduleName, $codes, true);
}

function db_deleteModule(PDO $conn, string $moduleCode): void
{
    $moduleCode = trim((string) $moduleCode);
    if ($moduleCode === '') {
        return;
    }

    $conn->beginTransaction();
    $deleteStudents = $conn->prepare('DELETE FROM student_modules WHERE module_code = :module_code');
    $deleteStudents->execute([':module_code' => $moduleCode]);

    $deleteCodes = $conn->prepare('DELETE FROM lecture_codes WHERE module_code = :module_code');
    $deleteCodes->execute([':module_code' => $moduleCode]);

    $deleteModule = $conn->prepare('DELETE FROM modules WHERE module_code = :module_code');
    $deleteModule->execute([':module_code' => $moduleCode]);

    $conn->commit();
}

function db_getLectureScheduleByModule(PDO $conn, string $moduleCode): array
{
    $select = $conn->prepare('SELECT code, lecture_date FROM lecture_codes WHERE module_code = :module_code ORDER BY COALESCE(lecture_date, date(\'now\')), code ASC');
    $select->execute([':module_code' => $moduleCode]);

    return $select->fetchAll(PDO::FETCH_ASSOC);
}

function db_getStudentsInModule(PDO $conn, string $moduleCode): array
{
    $select = $conn->prepare(<<<'SQL'
SELECT a.academic_number, a.name, a.mobile, a.group_name
FROM student_modules sm
JOIN attendance a ON sm.academic_number = a.academic_number
WHERE sm.module_code = :module_code
ORDER BY a.name ASC
SQL
    );
    $select->execute([':module_code' => $moduleCode]);

    return $select->fetchAll(PDO::FETCH_ASSOC);
}

function db_removeStudentFromModule(PDO $conn, string $academicNumber, string $moduleCode): void
{
    $select = $conn->prepare('DELETE FROM student_modules WHERE academic_number = :academic_number AND module_code = :module_code');
    $select->execute([
        ':academic_number' => trim((string) $academicNumber),
        ':module_code' => trim((string) $moduleCode),
    ]);
}

function db_getAllModules(PDO $conn): array
{
    return $conn->query('SELECT module_code, module_name FROM modules ORDER BY module_name ASC')->fetchAll(PDO::FETCH_ASSOC);
}

function db_getLectureCodesByModule(PDO $conn, string $moduleCode, bool $pastOnly = false): array
{
    $sql = 'SELECT code FROM lecture_codes WHERE module_code = :module_code';
    if ($pastOnly) {
        $sql .= ' AND (lecture_date IS NULL OR date(lecture_date) <= date(\'now\'))';
    }
    $sql .= ' ORDER BY COALESCE(lecture_date, date(\'now\')), code ASC';

    $select = $conn->prepare($sql);
    $select->execute([':module_code' => $moduleCode]);

    return array_column($select->fetchAll(PDO::FETCH_ASSOC), 'code');
}

function db_getModuleForCode(PDO $conn, string $code): ?string
{
    $code = trim((string) $code);
    if ($code === '') {
        return null;
    }

    $select = $conn->prepare('SELECT module_code FROM lecture_codes WHERE code = :code LIMIT 1');
    $select->execute([':code' => $code]);

    $result = $select->fetch(PDO::FETCH_ASSOC);
    return $result['module_code'] ?? null;
}

function db_getModuleName(PDO $conn, string $moduleCode): ?string
{
    $select = $conn->prepare('SELECT module_name FROM modules WHERE module_code = :module_code LIMIT 1');
    $select->execute([':module_code' => $moduleCode]);

    $result = $select->fetch(PDO::FETCH_ASSOC);
    return $result['module_name'] ?? null;
}

function db_getAcceptedLecCodes(PDO $conn): array
{
    $codes = $conn->query('SELECT code FROM lecture_codes ORDER BY code ASC')->fetchAll(PDO::FETCH_ASSOC);
    if (!$codes) {
        return ['11', '112', '123', '1234', '122345'];
    }

    return array_column($codes, 'code');
}

function db_isStudentEnrolled(PDO $conn, string $academicNumber, string $moduleCode): bool
{
    $select = $conn->prepare('SELECT 1 FROM student_modules WHERE academic_number = :academic_number AND module_code = :module_code LIMIT 1');
    $select->execute([
        ':academic_number' => trim((string) $academicNumber),
        ':module_code' => trim((string) $moduleCode),
    ]);

    return $select->fetchColumn() !== false;
}

function db_enrollStudentInModules(PDO $conn, string $academicNumber, array $moduleCodes, string $name, string $mobile, string $groupName): void
{
    $academicNumber = trim((string) $academicNumber);
    if ($academicNumber === '') {
        throw new InvalidArgumentException('Academic number is required.');
    }

    $conn->beginTransaction();

    $selectStudent = $conn->prepare('SELECT academic_number FROM attendance WHERE academic_number = :academic_number LIMIT 1');
    $selectStudent->execute([':academic_number' => $academicNumber]);
    if ($selectStudent->fetchColumn() === false) {
        $insertStudent = $conn->prepare('INSERT INTO attendance (academic_number, name, mobile, group_name, latitude, longitude, code, responses_json, response_count, isinrange, is_code_valid, created_at, updated_at) VALUES (:academic_number, :name, :mobile, :group_name, 0, 0, \'\', :responses_json, 0, 0, 0, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)');
        $insertStudent->execute([
            ':academic_number' => $academicNumber,
            ':name' => trim((string) $name),
            ':mobile' => trim((string) $mobile),
            ':group_name' => trim((string) $groupName),
            ':responses_json' => json_encode([], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ]);
    } else {
        $updateStudent = $conn->prepare('UPDATE attendance SET name = :name, mobile = :mobile, group_name = :group_name, updated_at = CURRENT_TIMESTAMP WHERE academic_number = :academic_number');
        $updateStudent->execute([
            ':academic_number' => $academicNumber,
            ':name' => trim((string) $name),
            ':mobile' => trim((string) $mobile),
            ':group_name' => trim((string) $groupName),
        ]);
    }

    $insertEnrollment = $conn->prepare('INSERT OR IGNORE INTO student_modules (academic_number, module_code, enrolled_at) VALUES (:academic_number, :module_code, CURRENT_TIMESTAMP)');
    foreach ($moduleCodes as $moduleCode) {
        $moduleCode = trim((string) $moduleCode);
        if ($moduleCode === '') {
            continue;
        }
        $insertEnrollment->execute([
            ':academic_number' => $academicNumber,
            ':module_code' => $moduleCode,
        ]);
    }

    $conn->commit();
}

function db_getStudentEnrolledModules(PDO $conn, string $academicNumber): array
{
    $select = $conn->prepare('SELECT module_code FROM student_modules WHERE academic_number = :academic_number ORDER BY module_code ASC');
    $select->execute([':academic_number' => trim((string) $academicNumber)]);

    return array_column($select->fetchAll(PDO::FETCH_ASSOC), 'module_code');
}

function db_getStudentByAcademicNumber(PDO $conn, string $academicNumber): ?array
{
    $select = $conn->prepare('SELECT * FROM attendance WHERE academic_number = :academic_number LIMIT 1');
    $select->execute([':academic_number' => trim((string) $academicNumber)]);
    $row = $select->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

function db_setStudentPassword(PDO $conn, string $academicNumber, string $password): void
{
    $hash = password_hash($password, PASSWORD_BCRYPT);
    $update = $conn->prepare('UPDATE attendance SET password_hash = :password_hash, updated_at = CURRENT_TIMESTAMP WHERE academic_number = :academic_number');
    $update->execute([
        ':password_hash' => $hash,
        ':academic_number' => trim((string) $academicNumber),
    ]);
}

function db_verifyStudentPassword(PDO $conn, string $academicNumber, string $password): bool
{
    $row = db_getStudentByAcademicNumber($conn, $academicNumber);
    if (!$row) {
        return false;
    }
    $hash = $row['password_hash'] ?? '';
    if ($hash === '') {
        return false;
    }
    return password_verify($password, $hash);
}

function db_getStudentsEnrolledByModule(PDO $conn, string $moduleCode): array
{
    $select = $conn->prepare('SELECT academic_number FROM student_modules WHERE module_code = :module_code ORDER BY academic_number ASC');
    $select->execute([':module_code' => trim((string) $moduleCode)]);

    return array_column($select->fetchAll(PDO::FETCH_ASSOC), 'academic_number');
}

function db_countModuleLectures(PDO $conn, string $moduleCode, bool $pastOnly = false): int
{
    $sql = 'SELECT COUNT(1) FROM lecture_codes WHERE module_code = :module_code';
    if ($pastOnly) {
        $sql .= ' AND (lecture_date IS NULL OR date(lecture_date) <= date(\'now\'))';
    }

    $select = $conn->prepare($sql);
    $select->execute([':module_code' => trim((string) $moduleCode)]);

    return (int) $select->fetchColumn();
}

function db_getStudentAttendedLectureCodes(PDO $conn, string $academicNumber, string $moduleCode): array
{
    $select = $conn->prepare('SELECT responses_json FROM attendance WHERE academic_number = :academic_number LIMIT 1');
    $select->execute([':academic_number' => trim((string) $academicNumber)]);
    $row = $select->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        return [];
    }

    $responses = json_decode((string) ($row['responses_json'] ?? '[]'), true);
    if (!is_array($responses)) {
        return [];
    }

    $attendedCodes = [];
    foreach ($responses as $response) {
        $code = trim((string) ($response['code'] ?? ''));
        if ($code === '') {
            continue;
        }

        $responseModuleCode = db_getModuleForCode($conn, $code);
        if ($responseModuleCode !== $moduleCode) {
            continue;
        }

        $attendedCodes[$code] = true;
    }

    return array_keys($attendedCodes);
}

function db_getStudentAttendanceStatus(PDO $conn, string $academicNumber, string $moduleCode): array
{
    // Get lecture schedule for the module
    $schedule = db_getLectureScheduleByModule($conn, $moduleCode);

    // Past lectures: those with a lecture_date and date <= today
    $pastLectures = [];
    foreach ($schedule as $row) {
        $code = trim((string) ($row['code'] ?? ''));
        $ld = isset($row['lecture_date']) ? trim((string) $row['lecture_date']) : null;
        if ($code === '') {
            continue;
        }
        if ($ld !== null && $ld !== '') {
            $ts = strtotime($ld);
            if ($ts !== false && date('Y-m-d', $ts) <= date('Y-m-d')) {
                $pastLectures[$code] = date('Y-m-d', $ts);
            }
        }
    }

    $pastLectureCodes = array_keys($pastLectures);
    $totalPast = count($pastLectureCodes);

    // All lectures (including future / undated)
    $allLectureCodes = array_map(function($r){ return trim((string) ($r['code'] ?? '')); }, $schedule);
    $allLectureCodes = array_filter($allLectureCodes, function($c){ return $c !== ''; });
    $allLectureCodes = array_values($allLectureCodes);
    $totalAll = count($allLectureCodes);

    $attendedCodes = db_getStudentAttendedLectureCodes($conn, $academicNumber, $moduleCode);
    $attendedPast = count(array_intersect($pastLectureCodes, $attendedCodes));
    $attendedAll = $totalAll > 0 ? count(array_intersect($allLectureCodes, $attendedCodes)) : 0;

    // determine missed past codes and include lecture dates
    $missed = [];
    foreach ($pastLectureCodes as $code) {
        if (!in_array($code, $attendedCodes, true)) {
            $missed[] = ['code' => $code, 'lecture_date' => $pastLectures[$code] ?? null];
        }
    }

    // Attendance rates
    $attendanceRatePast = $totalPast === 0 ? 1.0 : ($attendedPast / $totalPast);
    $attendanceRateAll = $totalAll === 0 ? 1.0 : ($attendedAll / $totalAll);

    // Dismissal: count absences (not attendances) and dismiss only when absences > 25% of all lectures
    $absentPast = $totalPast - $attendedPast;
    $absentAll = $totalAll - $attendedAll;
    $dismissed = ($totalAll > 0) ? (($absentAll / $totalAll) > 0.25) : false;

    return [
        'total' => $totalPast,
        'attended' => $attendedPast,
        'attendance_rate' => $attendanceRatePast,
        'absent' => $absentPast,
        'total_all' => $totalAll,
        'attended_all' => $attendedAll,
        'attendance_rate_all' => $attendanceRateAll,
        'absent_all' => $absentAll,
        'dismissed' => $dismissed,
        'missed' => $missed,
    ];
}
