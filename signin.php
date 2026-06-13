<?php
$databaseFile = __DIR__ . '/attendance.sqlite';
$point0 =[30.6127675, 31.1096459];

$point1 = [30.6114967, 31.1075899];

$point2 = [30.6096632, 31.1086974];

$point3 = [30.6105612, 31.1105531];
function openDatabase(string $databaseFile): PDO
{
    try {
        $conn = new PDO('sqlite:' . $databaseFile);
        $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        ensureSchema($conn);

        return $conn;
    } catch (PDOException $e) {
        if (file_exists($databaseFile)) {
            unlink($databaseFile);

            $conn = new PDO('sqlite:' . $databaseFile);
            $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            ensureSchema($conn);

            return $conn;
        }

        throw $e;
    }
}

function ensureSchema(PDO $conn): void
{
    $tableInfo = $conn->query('PRAGMA table_info(attendance)')->fetchAll(PDO::FETCH_ASSOC);

    if (!$tableInfo) {
        createAttendanceSchema($conn);
        return;
    }

    $columns = array_column($tableInfo, 'name');
    $academicNumberColumn = null;

    foreach ($tableInfo as $column) {
        if ($column['name'] === 'academic_number') {
            $academicNumberColumn = $column;
            break;
        }
    }

    $needsMigration = in_array('id', $columns, true)
        || in_array('gps', $columns, true)
        || !in_array('latitude', $columns, true)
        || !in_array('longitude', $columns, true)
        || !in_array('responses_json', $columns, true)
        || !in_array('response_count', $columns, true)
        || !in_array('updated_at', $columns, true)
        || $academicNumberColumn === null
        || (int) $academicNumberColumn['pk'] !== 1;

    if ($needsMigration) {
        migrateLegacySchema($conn);
        return;
    }

    createAttendanceSchema($conn);
}

function createAttendanceSchema(PDO $conn): void
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
    updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
)
SQL);
    $conn->exec('CREATE INDEX IF NOT EXISTS idx_attendance_created_at ON attendance (created_at)');
    $conn->exec('CREATE INDEX IF NOT EXISTS idx_attendance_group_name ON attendance (group_name)');
}

function migrateLegacySchema(PDO $conn): void
{
    $conn->exec('ALTER TABLE attendance RENAME TO attendance_legacy');

    createAttendanceSchema($conn);

    $rows = $conn->query('SELECT * FROM attendance_legacy ORDER BY created_at ASC, rowid ASC')->fetchAll(PDO::FETCH_ASSOC);
    $latestByAcademicNumber = [];

    foreach ($rows as $row) {
        $academicNumber = trim((string) ($row['academic_number'] ?? ''));

        if ($academicNumber === '') {
            continue;
        }

        $latitude = null;
        $longitude = null;

        if (array_key_exists('latitude', $row) && array_key_exists('longitude', $row) && $row['latitude'] !== null && $row['longitude'] !== null) {
            $latitude = (float) $row['latitude'];
            $longitude = (float) $row['longitude'];
        } elseif (!empty($row['gps'])) {
            $gps = json_decode((string) $row['gps'], true);
            if (is_array($gps)) {
                $latitude = isset($gps['latitude']) ? (float) $gps['latitude'] : null;
                $longitude = isset($gps['longitude']) ? (float) $gps['longitude'] : null;
            }
        }

        if ($latitude === null || $longitude === null) {
            continue;
        }

        $responseObject = [
            'name' => (string) ($row['name'] ?? ''),
            'mobile' => (string) ($row['mobile'] ?? ''),
            'group_name' => (string) ($row['group_name'] ?? ''),
            'latitude' => $latitude,
            'longitude' => $longitude,
            'code' => (string) ($row['code'] ?? ''),
            'created_at' => (string) ($row['created_at'] ?? date('Y-m-d H:i:s')),
        ];

        if (!isset($latestByAcademicNumber[$academicNumber])) {
            $latestByAcademicNumber[$academicNumber] = [
                'academic_number' => $academicNumber,
                'name' => (string) ($row['name'] ?? ''),
                'mobile' => (string) ($row['mobile'] ?? ''),
                'group_name' => (string) ($row['group_name'] ?? ''),
                'latitude' => $latitude,
                'longitude' => $longitude,
                'code' => (string) ($row['code'] ?? ''),
                'responses_json' => json_encode([$responseObject], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'response_count' => 1,
                'created_at' => (string) ($row['created_at'] ?? date('Y-m-d H:i:s')),
                'updated_at' => (string) ($row['created_at'] ?? date('Y-m-d H:i:s')),
            ];
            continue;
        }

        $existingResponses = json_decode((string) $latestByAcademicNumber[$academicNumber]['responses_json'], true);
        if (!is_array($existingResponses)) {
            $existingResponses = [];
        }

        $existingResponses[] = $responseObject;
        $latestByAcademicNumber[$academicNumber]['responses_json'] = json_encode($existingResponses, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $latestByAcademicNumber[$academicNumber]['response_count'] = count($existingResponses);
        $latestByAcademicNumber[$academicNumber]['name'] = $responseObject['name'];
        $latestByAcademicNumber[$academicNumber]['mobile'] = $responseObject['mobile'];
        $latestByAcademicNumber[$academicNumber]['group_name'] = $responseObject['group_name'];
        $latestByAcademicNumber[$academicNumber]['latitude'] = $responseObject['latitude'];
        $latestByAcademicNumber[$academicNumber]['longitude'] = $responseObject['longitude'];
        $latestByAcademicNumber[$academicNumber]['code'] = $responseObject['code'];
        $latestByAcademicNumber[$academicNumber]['updated_at'] = $responseObject['created_at'];
    }

    $insert = $conn->prepare('INSERT INTO attendance (academic_number, name, mobile, group_name, latitude, longitude, code, responses_json, response_count, created_at, updated_at) VALUES (:academic_number, :name, :mobile, :group_name, :latitude, :longitude, :code, :responses_json, :response_count, :created_at, :updated_at)');

    foreach ($latestByAcademicNumber as $row) {
        $insert->execute([
            ':academic_number' => $row['academic_number'],
            ':name' => $row['name'],
            ':mobile' => $row['mobile'],
            ':group_name' => $row['group_name'],
            ':latitude' => $row['latitude'],
            ':longitude' => $row['longitude'],
            ':code' => $row['code'],
            ':responses_json' => $row['responses_json'],
            ':response_count' => $row['response_count'],
            ':created_at' => $row['created_at'],
            ':updated_at' => $row['updated_at'],
        ]);
    }

    $conn->exec('DROP TABLE attendance_legacy');
}

try {
    $conn = openDatabase($databaseFile);
} catch (PDOException $e) {
    http_response_code(500);
    die('Database connection failed: ' . $e->getMessage());
}

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $academic_number = $_POST["acadimicnumber"] ?? '';
    $name = $_POST["name"] ?? '';
    $mobile = $_POST["mobile"] ?? '';
    $group = $_POST["group"] ?? '';
    $code = $_POST["code"] ?? '';
    $longitude = $_POST["longitude"] ?? '';
    $latitude = $_POST["latitude"] ?? '';

    if ($longitude !== '' && $latitude !== '') {
        $responseObject = [
            'name' => $name,
            'mobile' => $mobile,
            'group_name' => $group,
            'latitude' => (float) $latitude,
            'longitude' => (float) $longitude,
            'code' => $code,
            'created_at' => date('Y-m-d H:i:s'),
        ];

        try {
            $select = $conn->prepare('SELECT name, mobile, group_name, latitude, longitude, code, responses_json, response_count, created_at FROM attendance WHERE academic_number = :academic_number');
            $select->execute([':academic_number' => $academic_number]);
            $existing = $select->fetch(PDO::FETCH_ASSOC);

            if ($existing) {
                $responses = json_decode((string) ($existing['responses_json'] ?? '[]'), true);
                if (!is_array($responses)) {
                    $responses = [];
                }
                $responses[] = $responseObject;
                $responsesJson = json_encode($responses, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                $responseCount = count($responses);

                $update = $conn->prepare('UPDATE attendance SET name = :name, mobile = :mobile, group_name = :group_name, latitude = :latitude, longitude = :longitude, code = :code, responses_json = :responses_json, response_count = :response_count, updated_at = CURRENT_TIMESTAMP WHERE academic_number = :academic_number');
                $update->execute([
                    ':academic_number' => $academic_number,
                    ':name' => $name,
                    ':mobile' => $mobile,
                    ':group_name' => $group,
                    ':latitude' => (float) $latitude,
                    ':longitude' => (float) $longitude,
                    ':code' => $code,
                    ':responses_json' => $responsesJson,
                    ':response_count' => $responseCount,
                ]);
            } else {
                $responsesJson = json_encode([$responseObject], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                $insert = $conn->prepare('INSERT INTO attendance (academic_number, name, mobile, group_name, latitude, longitude, code, responses_json, response_count, created_at, updated_at) VALUES (:academic_number, :name, :mobile, :group_name, :latitude, :longitude, :code, :responses_json, :response_count, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)');
                $insert->execute([
                    ':academic_number' => $academic_number,
                    ':name' => $name,
                    ':mobile' => $mobile,
                    ':group_name' => $group,
                    ':latitude' => (float) $latitude,
                    ':longitude' => (float) $longitude,
                    ':code' => $code,
                    ':responses_json' => $responsesJson,
                    ':response_count' => 1,
                ]);
            }

            $data = $academic_number . "," . $name . "," . $mobile . "," . $group . "," . $latitude . "," . $longitude . "," . $code . "\n";
            file_put_contents(__DIR__ . '/attendance.txt', $data, FILE_APPEND);
            echo 'New record created successfully';
        } catch (PDOException $e) {
            http_response_code(500);
            echo 'Error: ' . $e->getMessage();
        }
    } else {
        http_response_code(400);
        echo 'Please click Get Location before signing in.';
    }
}

$conn = null;
