<?php
require_once __DIR__ . '/db.php';
session_start();

try {
    $conn = db_openDatabase();
} catch (PDOException $e) {
    http_response_code(500);
    die('Database connection failed: ' . $e->getMessage());
}

$message = '';

// handle login/logout
if (isset($_POST['logout'])) {
    unset($_SESSION['student_academic_number']);
    header('Location: dashboard.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'login') {
    $academic = trim((string) ($_POST['academic_number'] ?? ''));
    $password = trim((string) ($_POST['password'] ?? ''));

    if ($academic === '' || $password === '') {
        $message = 'Academic number and password are required.';
    } else {
        if (db_verifyStudentPassword($conn, $academic, $password)) {
            $_SESSION['student_academic_number'] = $academic;
        } else {
            $message = 'Invalid academic number or password.';
        }
    }
}

$loggedIn = isset($_SESSION['student_academic_number']);

if (!$loggedIn) {
    // show login form
    ?>
    <!doctype html>
    <html>
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width,initial-scale=1">
        <title>Student Dashboard Login</title>
        <style>body{font-family:Arial,Helvetica,sans-serif;padding:24px;background:#f3f4f6} .box{max-width:420px;margin:0 auto;background:#fff;padding:18px;border-radius:8px} input{width:100%;padding:10px;margin:8px 0;border:1px solid #ddd;border-radius:6px} button{padding:10px 12px;background:#2563eb;color:#fff;border:none;border-radius:6px}</style>
    </head>
    <body>
    <div class="box">
        <h2>Student Sign In</h2>
        <?php if ($message !== ''): ?>
            <div style="background:#fee2e2;padding:8px;border-radius:6px;margin-bottom:8px;color:#991b1b"><?php echo htmlspecialchars($message, ENT_QUOTES); ?></div>
        <?php endif; ?>
        <form method="post" action="dashboard.php">
            <input type="hidden" name="action" value="login">
            <label>Academic Number</label>
            <input type="text" name="academic_number" required>
            <label>Password</label>
            <input type="password" name="password" required>
            <div style="margin-top:12px"><button type="submit">Sign In</button></div>
        </form>
        <p style="margin-top:12px">If you don't have a password, sign up at <a href="signup.php">Sign Up</a> and create one.</p>
    </div>
    </body>
    </html>
    <?php
    exit;
}

// student dashboard
$academic = $_SESSION['student_academic_number'];
$student = db_getStudentByAcademicNumber($conn, $academic);
if (!$student) {
    echo "Student record not found.";
    exit;
}

$enrolled = db_getStudentEnrolledModules($conn, $academic);
$totalLecturesAll = 0;
$totalAttendedAll = 0;
$totalAbsentAll = 0;
$moduleDetails = [];
foreach ($enrolled as $moduleCode) {
    $status = db_getStudentAttendanceStatus($conn, $academic, $moduleCode);
    $moduleDetails[] = [
        'module_code' => $moduleCode,
        'module_name' => db_getModuleName($conn, $moduleCode) ?? $moduleCode,
        'status' => $status,
    ];
    // For student-visible totals, count absences only from past (done) lectures
    $totalLecturesAll += ($status['total'] ?? 0);
    $totalAttendedAll += ($status['attended'] ?? 0);
    $totalAbsentAll += ($status['absent'] ?? 0);
}
$overallRate = $totalLecturesAll > 0 ? ($totalAttendedAll / $totalLecturesAll) : 1.0;

$responses = json_decode((string) ($student['responses_json'] ?? '[]'), true);
if (!is_array($responses)) {
    $responses = [];
}

?>
<!doctype html>
<html>
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Student Dashboard</title>
    <style>body{font-family:Arial,Helvetica,sans-serif;padding:18px;background:#f8fafc} .card{background:#fff;padding:16px;border-radius:8px;margin-bottom:12px;border:1px solid #e6e6e6} table{width:100%;border-collapse:collapse} th,td{padding:8px;border:1px solid #eee;text-align:left} .future-lecture{color:#9ca3af;background:#fbfbfb}</style>
</head>
<body>
    <div style="max-width:900px;margin:0 auto">
        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:12px">
            <h2>Welcome, <?php echo htmlspecialchars($student['name'], ENT_QUOTES); ?></h2>
            <form method="post" style="margin:0"><button type="submit" name="logout">Logout</button></form>
        </div>

        <div class="card">
            <h3>Enrollment & Attendance Summary</h3>
            <p><strong>Academic #:</strong> <?php echo htmlspecialchars($student['academic_number'], ENT_QUOTES); ?></p>
            <p><strong>Enrolled modules:</strong> <?php echo htmlspecialchars(implode(', ', $enrolled) ?: 'None', ENT_QUOTES); ?></p>
            <p><strong>Total lectures (past):</strong> <?php echo htmlspecialchars((string)$totalLecturesAll, ENT_QUOTES); ?> — <strong>Attended:</strong> <?php echo htmlspecialchars((string)$totalAttendedAll, ENT_QUOTES); ?> — <strong>Absent:</strong> <?php echo htmlspecialchars((string)$totalAbsentAll, ENT_QUOTES); ?> — <strong>Overall %:</strong> <?php echo htmlspecialchars(number_format($overallRate*100,1) . '%', ENT_QUOTES); ?></p>
        </div>

        <div class="card">
            <h3>Modules</h3>
            <?php if (empty($moduleDetails)): ?>
                <div>No enrolled modules.</div>
            <?php else: ?>
                <table>
                    <thead><tr><th>Module</th><th>Attended</th><th>Absent</th><th>Attendance %</th><th>Missed (code & date)</th></tr></thead>
                    <tbody>
                        <?php foreach ($moduleDetails as $m): $s = $m['status']; ?>
                            <tr>
                                <td><?php echo htmlspecialchars($m['module_name'] . ' (' . $m['module_code'] . ')', ENT_QUOTES); ?></td>
                                <td><?php echo htmlspecialchars((string)$s['attended'], ENT_QUOTES); ?></td>
                                <td><?php echo htmlspecialchars((string)$s['absent'], ENT_QUOTES); ?></td>
                                <td><?php echo htmlspecialchars(number_format($s['attendance_rate']*100,1) . '%', ENT_QUOTES); ?></td>
                                <td><?php echo htmlspecialchars(implode(', ', array_map(function($it){ return $it['code'] . (empty($it['lecture_date']) ? '' : ' ('. $it['lecture_date'] .')'); }, $s['missed'] ?? [])) ?: '—', ENT_QUOTES); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>

        <div class="card">
            <h3>All Lectures (per module)</h3>
            <?php if (empty($moduleDetails)): ?>
                <div>No enrolled modules.</div>
            <?php else: ?>
                <?php foreach ($moduleDetails as $m):
                    $schedule = db_getLectureScheduleByModule($conn, $m['module_code']);
                    $attendedCodes = db_getStudentAttendedLectureCodes($conn, $academic, $m['module_code']);
                ?>
                    <div class="card" style="margin-bottom:10px;padding:12px">
                        <h4><?php echo htmlspecialchars($m['module_name'] . ' (' . $m['module_code'] . ')', ENT_QUOTES); ?></h4>
                        <?php if (empty($schedule)): ?>
                            <div> No lectures configured for this module.</div>
                        <?php else: ?>
                            <table>
                                <thead><tr><th>Lecture Code</th><th>Date</th><th>Attended</th></tr></thead>
                                <tbody>
                                    <?php foreach ($schedule as $lec):
                                        $code = $lec['code'] ?? '';
                                        $date = !empty($lec['lecture_date']) ? $lec['lecture_date'] : null;
                                        $att = in_array($code, $attendedCodes, true) ? 'Yes' : 'No';
                                        $isFuture = false;
                                        if (!empty($date)) {
                                            $ts = strtotime($date);
                                            if ($ts !== false && date('Y-m-d', $ts) > date('Y-m-d')) {
                                                $isFuture = true;
                                            }
                                        }
                                    ?>
                                        <tr class="<?php echo $isFuture ? 'future-lecture' : ''; ?>">
                                            <td><?php echo htmlspecialchars($code, ENT_QUOTES); ?></td>
                                            <td><?php echo htmlspecialchars($date ?? 'None', ENT_QUOTES); ?></td>
                                            <td><?php echo $att; ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>

        <div class="card">
            <h3>Detailed Responses</h3>
            <?php if (empty($responses)): ?>
                <div>No responses recorded yet.</div>
            <?php else: ?>
                <table>
                    <thead><tr><th>#</th><th>Code</th><th>Date</th><th>In Range</th><th>Code Valid</th><th>Accepted</th><th>Reason</th></tr></thead>
                    <tbody>
                        <?php foreach ($responses as $i => $r):
                            $code = htmlspecialchars((string)($r['code'] ?? ''), ENT_QUOTES);
                            $created = htmlspecialchars((string)($r['created_at'] ?? ''), ENT_QUOTES);
                            $inrange = !empty($r['is_in_range']);
                            $codevalid = !empty($r['code_valid']);
                            $accepted = $inrange && $codevalid;
                            if (!$codevalid) $reason = 'Invalid code';
                            elseif (!$inrange) $reason = 'Out of range';
                            else $reason = 'Accepted';
                        ?>
                            <tr>
                                <td><?php echo $i+1; ?></td>
                                <td><?php echo $code; ?></td>
                                <td><?php echo $created; ?></td>
                                <td><?php echo $inrange ? 'Yes' : 'No'; ?></td>
                                <td><?php echo $codevalid ? 'Yes' : 'No'; ?></td>
                                <td><?php echo $accepted ? 'Yes' : 'No'; ?></td>
                                <td><?php echo $reason; ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>

    </div>
</body>
</html>
