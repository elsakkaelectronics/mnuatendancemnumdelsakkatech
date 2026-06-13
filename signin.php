<?php
require_once __DIR__ . '/db.php';
$point0 =[30.6127675, 31.1096459];

$point1 = [30.6114967, 31.1075899];

$point2 = [30.6096632, 31.1086974];

$point3 = [30.6105612, 31.1105531];
function isintherange($latitude, $longitude, $point0, $point1, $point2, $point3) {
    $polygon = [$point0, $point1, $point2, $point3];
    $numPoints = count($polygon);
    $inside = false;

    for ($i = 0, $j = $numPoints - 1; $i < $numPoints; $j = $i++) {
        if ((($polygon[$i][1] > $longitude) != ($polygon[$j][1] > $longitude)) &&
            ($latitude < ($polygon[$j][0] - $polygon[$i][0]) * ($longitude - $polygon[$i][1]) / ($polygon[$j][1] - $polygon[$i][1]) + $polygon[$i][0])) {
            $inside = !$inside;
        }
    }

    return $inside;
}   

function isValidLecCode(PDO $conn, string $code): bool
{
    return db_getModuleForCode($conn, (string) $code) !== null;
}

try {
    $conn = db_openDatabase();
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
        $isInRange = isintherange((float) $latitude, (float) $longitude, $point0, $point1, $point2, $point3) ? 1 : 0;
        $codeValid = isValidLecCode($conn, $code);
        $responseObject = [
            'name' => $name,
            'mobile' => $mobile,
            'group_name' => $group,
            'latitude' => (float) $latitude,
            'longitude' => (float) $longitude,
            'code' => $code,
            'code_valid' => $codeValid,
            'is_in_range' => $isInRange,
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

                $update = $conn->prepare('UPDATE attendance SET name = :name, mobile = :mobile, group_name = :group_name, latitude = :latitude, longitude = :longitude, code = :code, responses_json = :responses_json, response_count = :response_count, isinrange = :isinrange, is_code_valid = :is_code_valid, updated_at = CURRENT_TIMESTAMP WHERE academic_number = :academic_number');
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
                    ':isinrange' => $isInRange,
                    ':is_code_valid' => $codeValid ? 1 : 0,
                ]);
            } else {
                $responsesJson = json_encode([$responseObject], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                $insert = $conn->prepare('INSERT INTO attendance (academic_number, name, mobile, group_name, latitude, longitude, code, responses_json, response_count, isinrange, is_code_valid, created_at, updated_at) VALUES (:academic_number, :name, :mobile, :group_name, :latitude, :longitude, :code, :responses_json, :response_count, :isinrange, :is_code_valid, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)');
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
                    ':isinrange' => $isInRange,
                    ':is_code_valid' => $codeValid ? 1 : 0,
                ]);
            }
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
