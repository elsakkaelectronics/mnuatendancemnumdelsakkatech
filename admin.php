<?php
require_once __DIR__ . "/db.php";
session_start();

try {
    $conn = db_openDatabase();
} catch (PDOException $e) {
    http_response_code(500);
    die("Database connection failed: " . $e->getMessage());
}

// Simple admin credentials (change as needed)
$ADMIN_USER = 'admin';
$ADMIN_PASS = 'admin123';

function renderLoginForm($error = '') {
    $errHtml = $error ? "<div class=\"alert\">" . htmlspecialchars($error, ENT_QUOTES) . "</div>" : '';
    echo <<<HTML
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Admin Login</title>
  <style>
    body{font-family:Arial,Helvetica,sans-serif;background:#f3f4f6;padding:40px}
    .box{max-width:420px;margin:0 auto;background:#fff;padding:24px;border-radius:8px;border:1px solid #e5e7eb}
    input{width:100%;padding:10px;margin:8px 0;border:1px solid #d1d5db;border-radius:6px}
    button{padding:10px 14px;border:none;background:#2563eb;color:#fff;border-radius:6px;cursor:pointer}
    .alert{background:#fee2e2;color:#991b1b;padding:10px;border-radius:6px;margin-bottom:10px}
  </style>
</head>
<body>
  <div class="box">
    <h2>Admin Login</h2>
    {$errHtml}
    <form method="post" action="admin.php">
      <input type="text" name="username" placeholder="Username" required>
      <input type="password" name="password" placeholder="Password" required>
      <div style="display:flex;gap:8px;margin-top:12px">
        <button type="submit">Login</button>
      </div>
    </form>
  </div>
</body>
</html>
HTML;
    exit;
}

// Handle login/logout
if (isset($_POST['logout'])) {
    unset($_SESSION['admin']);
    header('Location: admin.php');
    exit;
}

if (!isset($_SESSION['admin'])) {
    if (isset($_POST['username'], $_POST['password'])) {
        if ($_POST['username'] === $ADMIN_USER && $_POST['password'] === $ADMIN_PASS) {
            $_SESSION['admin'] = true;
            // continue to admin page
        } else {
            renderLoginForm('Invalid username or password.');
        }
    } else {
        renderLoginForm();
    }
}

$modules = db_getAllModules($conn);
$rows = $conn->query("SELECT academic_number, name, mobile, group_name, latitude, longitude, code, responses_json, response_count, isinrange, is_code_valid, created_at, updated_at FROM attendance ORDER BY updated_at DESC, academic_number ASC")->fetchAll(PDO::FETCH_ASSOC);

if (isset($_GET["export"]) && $_GET["export"] === "csv") {
    header("Content-Type: text/csv; charset=UTF-8");
    header('Content-Disposition: attachment; filename="attendance-export-' . date('Y-m-d') . '.csv"');

    $output = fopen("php://output", "w");
    fputcsv($output, ["Academic Number", "Name", "Mobile", "Group", "Latitude", "Longitude", "Code", "Responses Count", "Is In Range", "Code Valid", "Responses JSON", "Missed Lectures", "Missed Count", "Created At", "Updated At"]);

    foreach ($rows as $row) {
        // compute missed lectures across enrolled modules for CSV
        $enrolledModulesCsv = db_getStudentEnrolledModules($conn, $row["academic_number"]);
        $missedList = [];
        foreach ($enrolledModulesCsv as $mcode) {
            $st = db_getStudentAttendanceStatus($conn, $row["academic_number"], $mcode);
            foreach ($st['missed'] ?? [] as $ms) {
                $missedList[] = $ms['code'] . (empty($ms['lecture_date']) ? '' : ' (' . $ms['lecture_date'] . ')');
            }
        }
        $missedCsv = implode('; ', $missedList);

        fputcsv($output, [
            $row["academic_number"],
            $row["name"],
            $row["mobile"],
            $row["group_name"],
            $row["latitude"],
            $row["longitude"],
            $row["code"],
            $row["response_count"],
            $row["isinrange"] ?? 0,
            $row["is_code_valid"] ? "Yes" : "No",
            $row["responses_json"],
            $missedCsv,
            count($missedList),
            $row["created_at"],
            $row["updated_at"] ?? $row["created_at"],
        ]);
    }

    fclose($output);
    exit;
}

$total = count($rows);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Attendance Admin</title>
    <style>
        :root {
            color-scheme: light;
            --bg: #0f172a;
            --panel: #ffffff;
            --panel-alt: #f8fafc;
            --text: #0f172a;
            --muted: #64748b;
            --accent: #2563eb;
            --accent-dark: #1d4ed8;
            --border: #e2e8f0;
        }

        * { box-sizing: border-box; }

        body {
            margin: 0;
            font-family: Arial, Helvetica, sans-serif;
            background: linear-gradient(135deg, #0f172a 0%, #1e293b 45%, #334155 100%);
            color: var(--text);
            min-height: 100vh;
            padding: 32px;
        }

        .shell {
            max-width: 1200px;
            margin: 0 auto;
        }

        .hero {
            color: white;
            margin-bottom: 24px;
        }

        .hero h1 {
            margin: 0 0 8px;
            font-size: clamp(28px, 4vw, 42px);
        }

        .hero p {
            margin: 0;
            color: rgba(255,255,255,0.8);
        }

        .toolbar {
            display: flex;
            gap: 12px;
            flex-wrap: wrap;
            align-items: center;
            justify-content: space-between;
            background: rgba(255,255,255,0.08);
            border: 1px solid rgba(255,255,255,0.12);
            backdrop-filter: blur(10px);
            border-radius: 18px;
            padding: 16px 18px;
            margin-bottom: 18px;
        }

        .count {
            color: white;
            font-weight: 700;
        }

        .actions a {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            padding: 12px 16px;
            border-radius: 12px;
            text-decoration: none;
            font-weight: 700;
            background: var(--accent);
            color: white;
            transition: transform 0.2s ease, background 0.2s ease;
        }

        .actions a:hover {
            background: var(--accent-dark);
            transform: translateY(-1px);
        }

        .alert {
            background: #f8fafc;
            border: 1px solid #c7d2fe;
            color: #1d4ed8;
            border-radius: 12px;
            padding: 14px 18px;
            margin-bottom: 18px;
        }

        .module-form {
            background: #fff;
            border: 1px solid var(--border);
            border-radius: 18px;
            padding: 18px;
            margin-bottom: 18px;
        }

        .module-form h2 {
            margin: 0 0 12px;
            font-size: 18px;
            color: var(--text);
        }

        .module-form label {
            display: block;
            margin-bottom: 8px;
            color: var(--text);
            font-weight: 700;
            font-size: 13px;
        }

        .module-form input,
        .module-form textarea {
            width: 100%;
            padding: 12px 14px;
            margin-bottom: 14px;
            border-radius: 12px;
            border: 1px solid #e2e8f0;
            font-size: 14px;
        }

        .module-form textarea {
            min-height: 100px;
            resize: vertical;
        }

        .lecture-table {
            display: grid;
            gap: 10px;
            margin-bottom: 14px;
        }

        .lecture-row {
            display: grid;
            grid-template-columns: 2fr 1fr auto;
            gap: 10px;
            align-items: center;
        }

        .lecture-row.header-row {
            font-weight: 700;
            color: #334155;
        }

        .lecture-row input {
            padding: 10px 12px;
            border: 1px solid #e2e8f0;
            border-radius: 10px;
            font-size: 14px;
            width: 100%;
        }

        .remove-lecture {
            background: #ef4444;
            color: white;
            border: none;
            border-radius: 10px;
            width: 44px;
            height: 44px;
            cursor: pointer;
        }

        .module-form button,
        #addLectureRow {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            padding: 12px 18px;
            border-radius: 12px;
            border: none;
            background: var(--accent);
            color: white;
            font-weight: 700;
            cursor: pointer;
        }

        .module-form .button-row {
            display: flex;
            flex-wrap: wrap;
            gap: 10px;
        }

        .module-list {
            background: #fff;
            border: 1px solid var(--border);
            border-radius: 18px;
            padding: 18px;
            margin-bottom: 18px;
        }

        .module-card {
            border: 1px solid #e2e8f0;
            border-radius: 18px;
            padding: 16px;
            margin-bottom: 16px;
            background: #f8fafc;
        }

        .module-card h3 {
            margin: 0 0 8px;
            font-size: 16px;
        }

        .module-card table,
        .student-table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 10px;
        }

        .module-card th,
        .module-card td,
        .student-table th,
        .student-table td {
            padding: 10px;
            border: 1px solid #e2e8f0;
            text-align: left;
        }

        .module-card th,
        .student-table th {
            background: #e2e8f0;
        }

        .module-card-actions,
        .manual-enroll {
            display: grid;
            gap: 10px;
            margin-top: 14px;
        }

        .edit-module {
            background: #fbbf24;
            color: #0f172a;
            border: none;
            padding: 10px 14px;
            border-radius: 12px;
            cursor: pointer;
        }

        .secondary {
            background: #94a3b8;
        }

        .manual-enroll {
            background: #fff;
            border: 1px solid var(--border);
            border-radius: 18px;
            padding: 18px;
            margin-bottom: 18px;
        }

        .manual-enroll input,
        .manual-enroll select {
            width: 100%;
            padding: 12px 14px;
            border: 1px solid #e2e8f0;
            border-radius: 12px;
            font-size: 14px;
        }

        .manual-enroll button {
            width: fit-content;
            margin-top: 0;
        }

        .module-actions form {
            display: inline-block;
            margin-right: 8px;
        }

        .module-status table {
            width: 100%;
            border-collapse: collapse;
            font-size: 0.9rem;
            margin-top: 0.75rem;
        }

        .module-status th,
        .module-status td {
            padding: 0.65rem 0.9rem;
            text-align: left;
            border-bottom: 1px solid #e2e8f0;
        }

        .module-status .dismissed {
            color: #b91c1c;
            font-weight: 700;
        }

        .module-status .active {
            color: #047857;
            font-weight: 700;
        }

        .searchbar {
            background: rgba(255,255,255,0.08);
            border: 1px solid rgba(255,255,255,0.12);
            backdrop-filter: blur(10px);
            border-radius: 18px;
            padding: 16px 18px;
            margin-bottom: 18px;
        }

        .searchbar label {
            display: block;
            color: rgba(255,255,255,0.8);
            font-size: 12px;
            text-transform: uppercase;
            letter-spacing: 0.08em;
            margin-bottom: 8px;
            font-weight: 700;
        }

        .searchbar input {
            width: 100%;
            border: 0;
            outline: none;
            border-radius: 14px;
            padding: 14px 16px;
            font-size: 15px;
            background: rgba(255,255,255,0.96);
            color: var(--text);
        }

        .card {
            background: var(--panel);
            border-radius: 20px;
            overflow: hidden;
            box-shadow: 0 24px 80px rgba(15, 23, 42, 0.35);
            border: 1px solid rgba(255,255,255,0.08);
        }

        .cards {
            display: grid;
            gap: 14px;
            padding: 16px;
        }

        details.user-card {
            border: 1px solid var(--border);
            border-radius: 18px;
            background: #fff;
            overflow: hidden;
            box-shadow: 0 12px 30px rgba(15, 23, 42, 0.08);
        }

        details.user-card[open] {
            border-color: #bfdbfe;
        }

        summary.user-summary {
            list-style: none;
            cursor: pointer;
            padding: 18px;
            display: flex;
            flex-wrap: wrap;
            gap: 12px;
            align-items: center;
            justify-content: space-between;
        }

        summary.user-summary::-webkit-details-marker {
            display: none;
        }

        .summary-main {
            min-width: 0;
        }

        .summary-main h2 {
            margin: 0 0 6px;
            font-size: 18px;
            color: var(--text);
        }

        .summary-meta {
            color: var(--muted);
            font-size: 13px;
            display: flex;
            flex-wrap: wrap;
            gap: 8px 14px;
        }

        .summary-chevron {
            color: var(--accent);
            font-weight: 900;
            font-size: 18px;
        }

        .lec-table {
            margin-bottom: 1rem;
            overflow-x: auto;
            background: #fff;
            border: 1px solid #e2e8f0;
            border-radius: 0.75rem;
            padding: 0.75rem;
        }

        .response-list { background: var(--panel-alt); padding: 16px; display: grid; gap: 12px; }
        .response-item { background: #fff; border: 1px solid var(--border); border-radius: 14px; padding: 14px; }
        .response-grid{ display:grid; grid-template-columns:repeat(2,minmax(0,1fr)); gap:8px 14px; font-size:13px; color:var(--muted);}        

        @media (max-width: 640px) {
            body { padding: 16px; }
            .toolbar { align-items: flex-start; }
            .response-grid { grid-template-columns: 1fr; }
        }
    </style>
</head>
<body>
    <div class="shell">
        <div class="hero">
            <h1>Attendance Admin</h1>
            <p>View every recorded attendance entry and export the dataset as CSV.</p>
        </div>

        <div class="module-list">
            <h2>All Module Lectures</h2>
            <div class="module-card">
                <table>
                    <thead>
                        <tr>
                            <th>Module Code</th>
                            <th>Module Name</th>
                            <th>Lecture Code</th>
                            <th>Lecture Date</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($modules as $module):
                            $schedule = db_getLectureScheduleByModule($conn, $module['module_code']);
                            if (empty($schedule)) {
                                echo '<tr><td>' . htmlspecialchars($module['module_code'], ENT_QUOTES) . '</td><td>' . htmlspecialchars($module['module_name'], ENT_QUOTES) . '</td><td colspan="2">No lectures configured</td></tr>';
                            } else {
                                foreach ($schedule as $lec) {
                                    echo '<tr>';
                                    echo '<td>' . htmlspecialchars($module['module_code'], ENT_QUOTES) . '</td>';
                                    echo '<td>' . htmlspecialchars($module['module_name'], ENT_QUOTES) . '</td>';
                                    echo '<td>' . htmlspecialchars($lec['code'] ?? '', ENT_QUOTES) . '</td>';
                                    echo '<td>' . htmlspecialchars($lec['lecture_date'] ?: 'None', ENT_QUOTES) . '</td>';
                                    echo '</tr>';
                                }
                            }
                        endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <div class="toolbar">
            <div class="count"><?php echo htmlspecialchars((string) $total, ENT_QUOTES); ?> total records</div>
            <div class="actions">
                <a href="admin.php?export=csv">Export CSV</a>
                <?php if (isset($_GET['filter']) && $_GET['filter'] === 'dismissed'): ?>
                    <a href="admin.php">Show All</a>
                <?php else: ?>
                    <a href="admin.php?filter=dismissed">Show Dismissed</a>
                <?php endif; ?>
                <form method="post" style="display:inline-block;margin-left:8px"><button type="submit" name="logout" value="1" style="background:#ef4444;padding:10px 12px;border-radius:8px;border:none;color:white">Logout</button></form>
            </div>
        </div>

        <?php if ($message !== ""): ?>
            <div class="alert"><?php echo htmlspecialchars($message, ENT_QUOTES); ?></div>
        <?php endif; ?>

        <div class="module-form">
            <h2>Create or update a module</h2>
            <form method="post" action="admin.php">
                <input type="hidden" id="module_action" name="action" value="create_module">
                <label for="module_code">Module Code</label>
                <input id="module_code" name="module_code" placeholder="e.g. CORE" required>

                <label for="module_name">Module Name</label>
                <input id="module_name" name="module_name" placeholder="e.g. Core Lectures" required>

                <label>Lecture schedule</label>
                <div class="lecture-table" id="lectureTable">
                    <div class="lecture-row header-row">
                        <span>Lecture Code</span>
                        <span>Date</span>
                        <span></span>
                    </div>
                    <div class="lecture-row">
                        <input type="text" name="lecture_codes[]" placeholder="11" required>
                        <input type="date" name="lecture_dates[]">
                        <button type="button" class="remove-lecture" aria-label="Remove lecture">✕</button>
                    </div>
                </div>
                <div class="module-form button-row">
                    <button type="button" id="addLectureRow">Add lecture</button>
                    <button type="submit">Save module</button>
                    <button type="button" id="resetModuleForm" class="secondary">Reset</button>
                </div>
            </form>
        </div>

        <div class="module-list">
            <h2>Current Modules</h2>
            <?php if (empty($modules)): ?>
                <div class="empty">No modules configured yet.</div>
            <?php else: ?>
                <?php foreach ($modules as $module): ?>
                    <?php $schedule = db_getLectureScheduleByModule($conn, $module["module_code"]); ?>
                    <?php $students = db_getStudentsInModule($conn, $module["module_code"]); ?>
                    <div class="module-card">
                        <div class="module-card-header">
                            <h3><?php echo htmlspecialchars($module["module_name"], ENT_QUOTES); ?> (<?php echo htmlspecialchars($module["module_code"], ENT_QUOTES); ?>)</h3>
                            <div class="module-actions">
                                <button type="button" class="edit-module" data-module-code="<?php echo htmlspecialchars($module["module_code"], ENT_QUOTES); ?>" data-module-name="<?php echo htmlspecialchars($module["module_name"], ENT_QUOTES); ?>" data-schedule="<?php echo htmlspecialchars(json_encode($schedule, JSON_UNESCAPED_UNICODE), ENT_QUOTES); ?>">Edit</button>
                                <form method="post" action="admin.php" onsubmit="return confirm('Delete module <?php echo htmlspecialchars($module["module_code"], ENT_QUOTES); ?>?');">
                                    <input type="hidden" name="action" value="delete_module">
                                    <input type="hidden" name="module_code" value="<?php echo htmlspecialchars($module["module_code"], ENT_QUOTES); ?>">
                                    <button type="submit" class="secondary">Delete</button>
                                </form>
                            </div>
                        </div>
                        <table>
                            <thead>
                                <tr>
                                    <th>Lecture Code</th>
                                    <th>Date</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($schedule)): ?>
                                    <tr><td colspan="2">No lecture schedule configured.</td></tr>
                                <?php else: ?>
                                    <?php foreach ($schedule as $item): ?>
                                        <tr>
                                            <td><?php echo htmlspecialchars($item["code"], ENT_QUOTES); ?></td>
                                            <td><?php echo htmlspecialchars($item["lecture_date"] ?: "None", ENT_QUOTES); ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>

                        <h4>Students Enrolled</h4>
                        <table class="student-table">
                            <thead>
                                <tr>
                                    <th>Name</th>
                                    <th>Academic #</th>
                                    <th>Mobile</th>
                                    <th>Group</th>
                                    <th>Action</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($students)): ?>
                                    <tr><td colspan="5">No students enrolled.</td></tr>
                                <?php else: ?>
                                    <?php foreach ($students as $student): ?>
                                        <tr>
                                            <td><?php echo htmlspecialchars($student["name"], ENT_QUOTES); ?></td>
                                            <td><?php echo htmlspecialchars($student["academic_number"], ENT_QUOTES); ?></td>
                                            <td><?php echo htmlspecialchars($student["mobile"], ENT_QUOTES); ?></td>
                                            <td><?php echo htmlspecialchars($student["group_name"], ENT_QUOTES); ?></td>
                                            <td>
                                                <form method="post" action="admin.php" style="display:inline-block;">
                                                    <input type="hidden" name="action" value="unenroll_student">
                                                    <input type="hidden" name="module_code" value="<?php echo htmlspecialchars($module["module_code"], ENT_QUOTES); ?>">
                                                    <input type="hidden" name="academic_number" value="<?php echo htmlspecialchars($student["academic_number"], ENT_QUOTES); ?>">
                                                    <button type="submit" class="secondary">Remove</button>
                                                </form>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>

        <div class="manual-enroll">
            <h2>Manual Student Enrollment</h2>
            <form method="post" action="admin.php">
                <input type="hidden" name="action" value="enroll_student">
                <label for="enroll_module_code">Module</label>
                <select id="enroll_module_code" name="module_code" required>
                    <option value="">Select a module</option>
                    <?php foreach ($modules as $module): ?>
                        <option value="<?php echo htmlspecialchars($module["module_code"], ENT_QUOTES); ?>"><?php echo htmlspecialchars($module["module_name"] . " (" . $module["module_code"] . ")", ENT_QUOTES); ?></option>
                    <?php endforeach; ?>
                </select>

                <label for="enroll_academic_number">Academic Number</label>
                <input id="enroll_academic_number" name="academic_number" placeholder="123456" required>

                <label for="enroll_name">Full Name</label>
                <input id="enroll_name" name="name" placeholder="Student Name" required>

                <label for="enroll_mobile">Mobile Number</label>
                <input id="enroll_mobile" name="mobile" placeholder="0123456789" required>

                <label for="enroll_group">Group</label>
                <input id="enroll_group" name="group" placeholder="Group A" required>

                <button type="submit">Enroll Student</button>
            </form>
        </div>

        <div class="searchbar">
            <label for="searchInput">Search users</label>
            <input type="search" id="searchInput" placeholder="Search by academic number, name, mobile, group, or code">
        </div>

        <div class="card">
            <?php if ($total === 0): ?>
                <div class="empty">No attendance records found yet.</div>
            <?php else: ?>
                <div class="cards" id="userCards">
                    <?php foreach ($rows as $row): ?>
                        <?php
                            $responses = json_decode((string) ($row["responses_json"] ?? "[]"), true);
                            if (!is_array($responses)) {
                                $responses = [];
                            }
                            $searchText = strtolower(trim(implode(" ", [
                                (string) $row["academic_number"],
                                (string) $row["name"],
                                (string) $row["mobile"],
                                (string) $row["group_name"],
                                (string) $row["code"],
                            ])));
                            // prepare attendance/module summary for this student
                            $attendedCodes = [];
                            foreach ($responses as $response) {
                                $code = trim((string) ($response["code"] ?? ""));
                                if ($code !== "") {
                                    $attendedCodes[$code] = true;
                                }
                            }

                            $acceptedCodesRows = $conn->query('SELECT code, lecture_date FROM lecture_codes ORDER BY code ASC')->fetchAll(PDO::FETCH_ASSOC);
                            if (!$acceptedCodesRows) {
                                $acceptedCodesRows = array_map(function($c){ return ['code'=>$c, 'lecture_date'=>null]; }, db_getAcceptedLecCodes($conn));
                            }

                            $enrolledModules = db_getStudentEnrolledModules($conn, $row["academic_number"]);
                            $moduleStatuses = [];
                            $pastTotalLectures = 0;
                            $pastAttended = 0;
                            $pastAbsent = 0;
                            $allTotalLectures = 0;
                            $allAttended = 0;
                            $allAbsent = 0;

                            foreach ($enrolledModules as $moduleCode) {
                                $status = db_getStudentAttendanceStatus($conn, $row["academic_number"], $moduleCode);
                                    $moduleStatuses[] = [
                                        "module_code" => $moduleCode,
                                        "module_name" => db_getModuleName($conn, $moduleCode) ?? $moduleCode,
                                        // past-only metrics
                                        "attended" => $status["attended"],
                                        "total" => $status["total"],
                                        "absent" => $status["absent"],
                                        "attendance_rate" => $status["attendance_rate"],
                                        "missed" => $status["missed"] ?? [],
                                        // all-lectures metrics
                                        "total_all" => $status["total_all"] ?? 0,
                                        "attended_all" => $status["attended_all"] ?? 0,
                                        "absent_all" => $status["absent_all"] ?? 0,
                                        "attendance_rate_all" => $status["attendance_rate_all"] ?? 1.0,
                                        "dismissed" => $status["dismissed"],
                                    ];

                                    $pastTotalLectures += ($status["total"] ?? 0);
                                    $pastAttended += ($status["attended"] ?? 0);
                                    $pastAbsent += ($status["absent"] ?? 0);
                                    $allTotalLectures += ($status["total_all"] ?? 0);
                                    $allAttended += ($status["attended_all"] ?? 0);
                                    $allAbsent += ($status["absent_all"] ?? 0);
                            }
                                // compute past-only overall rate (for display)
                                $overallAttendanceRatePast = $pastTotalLectures > 0 ? ($pastAttended / $pastTotalLectures) : 1.0;
                                $overallAbsenceRatePast = $pastTotalLectures > 0 ? ($pastAbsent / $pastTotalLectures) : 0;
                                // compute all-lectures overall rate (for dismissal filter)
                                $overallAttendanceRateAll = $allTotalLectures > 0 ? ($allAttended / $allTotalLectures) : 1.0;
                                $overallDismissedCheck = ($allTotalLectures > 0) ? (($allAbsent / $allTotalLectures) > 0.25) : false;
                                if (isset($_GET['filter']) && $_GET['filter'] === 'dismissed' && !$overallDismissedCheck) {
                                    continue;
                                }
                        ?>
                        <details class="user-card" data-search="<?php echo htmlspecialchars($searchText, ENT_QUOTES); ?>">
                            <summary class="user-summary">
                                <div class="summary-main">
                                    <h2><?php echo htmlspecialchars($row["name"], ENT_QUOTES); ?></h2>
                                    <div class="summary-meta">
                                        <span><strong>Academic:</strong> <?php echo htmlspecialchars($row["academic_number"], ENT_QUOTES); ?></span>
                                        <span><strong>Mobile:</strong> <?php echo htmlspecialchars($row["mobile"], ENT_QUOTES); ?></span>
                                        <span><strong>Group:</strong> <?php echo htmlspecialchars($row["group_name"], ENT_QUOTES); ?></span>
                                        <span><strong>In Range:</strong> <?php echo $row["isinrange"] ? "Yes" : "No"; ?></span>
                                        <span><strong>Code Valid:</strong> <?php echo $row["is_code_valid"] ? "Yes" : "No"; ?></span>
                                        <span><strong>Responses:</strong> <?php echo htmlspecialchars((string) $row["response_count"], ENT_QUOTES); ?></span>
                                        <span><strong>Total Absent:</strong> <?php echo htmlspecialchars((string) $pastAbsent ?? 0, ENT_QUOTES); ?></span>
                                        <span><strong>Overall % (past):</strong> <?php echo htmlspecialchars(number_format(($overallAttendanceRatePast ?? 1.0) * 100, 1) . "%", ENT_QUOTES); ?></span>
                                        <span><strong>Absence Rate (past):</strong> <?php echo htmlspecialchars(number_format($overallAbsenceRatePast * 100, 1) . "%", ENT_QUOTES); ?></span>
                                        <span><strong>Dismissed (all lectures):</strong> <?php echo $overallDismissedCheck ? 'Yes' : 'No'; ?></span>
                                    </div>
                                </div>
                                <span class="summary-chevron">▾</span>
                            </summary>

                            <?php
                            $attendedCodes = [];
                            foreach ($responses as $response) {
                                $code = trim((string) ($response["code"] ?? ""));
                                if ($code !== "") {
                                    $attendedCodes[$code] = true;
                                }
                            }
                            // fetch accepted lecture codes with their dates
                            $acceptedCodesRows = $conn->query('SELECT code, lecture_date FROM lecture_codes ORDER BY code ASC')->fetchAll(PDO::FETCH_ASSOC);
                            if (!$acceptedCodesRows) {
                                $acceptedCodesRows = array_map(function($c){ return ['code'=>$c, 'lecture_date'=>null]; }, db_getAcceptedLecCodes($conn));
                            }
                            $enrolledModules = db_getStudentEnrolledModules($conn, $row["academic_number"]);
                            $moduleStatuses = [];

                            foreach ($enrolledModules as $moduleCode) {
                                $status = db_getStudentAttendanceStatus($conn, $row["academic_number"], $moduleCode);
                                $moduleStatuses[] = [
                                    "module_code" => $moduleCode,
                                    "module_name" => db_getModuleName($conn, $moduleCode) ?? $moduleCode,
                                    "attended" => $status["attended"],
                                    "total" => $status["total"],
                                    "absent" => $status["absent"],
                                    "attendance_rate" => $status["attendance_rate"],
                                    "dismissed" => $status["dismissed"],
                                ];
                            }
                            ?>

                            <div class="lec-table">
                                <h3>Lecture Codes Attended</h3>
                                <table>
                                    <thead>
                                        <tr>
                                            <th>Lecture Code</th>
                                            <th>Attended</th>
                                            <th>Date</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                            <?php foreach ($acceptedCodesRows as $item): ?>
                                                <tr>
                                                    <td><?php echo htmlspecialchars($item['code'], ENT_QUOTES); ?></td>
                                                    <td><?php echo isset($attendedCodes[$item['code']]) ? "Yes" : "No"; ?></td>
                                                    <td><?php echo htmlspecialchars($item['lecture_date'] ?? 'None', ENT_QUOTES); ?></td>
                                                </tr>
                                            <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>

                            <div class="module-status">
                                <h3>Enrolled Modules</h3>
                                <?php if (empty($moduleStatuses)): ?>
                                    <div class="response-empty">No module enrollments found for this student.</div>
                                <?php else: ?>
                                    <table>
                                        <thead>
                                            <tr>
                                                <th>Module</th>
                                                <th>Attended</th>
                                                <th>Absent</th>
                                                <th>Attendance %</th>
                                                <th>Lectures</th>
                                                <th>Missed</th>
                                                <th>Status</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($moduleStatuses as $module): ?>
                                                <tr>
                                                    <td><?php echo htmlspecialchars($module["module_name"] . " (" . $module["module_code"] . ")", ENT_QUOTES); ?></td>
                                                    <td><?php echo htmlspecialchars((string) $module["attended"], ENT_QUOTES); ?></td>
                                                    <td><?php echo htmlspecialchars((string) $module["absent"], ENT_QUOTES); ?></td>
                                                    <td><?php echo htmlspecialchars(number_format($module["attendance_rate"] * 100, 1) . "%", ENT_QUOTES); ?></td>
                                                    <?php
                                                        $schedule = db_getLectureScheduleByModule($conn, $module["module_code"]);
                                                        if (!is_array($schedule)) $schedule = [];
                                                        $lectArr = [];
                                                        foreach ($schedule as $lec) {
                                                            $code = $lec['code'] ?? '';
                                                            $date = !empty($lec['lecture_date']) ? (' (' . $lec['lecture_date'] . ')') : '';
                                                            $att = isset($attendedCodes[$code]) ? 'Yes' : 'No';
                                                            $lectArr[] = $code . $date . ' — ' . $att;
                                                        }
                                                    ?>
                                                    <td><?php echo htmlspecialchars(implode('; ', $lectArr) ?: '—', ENT_QUOTES); ?></td>
                                                    <td><?php echo htmlspecialchars(implode(', ', array_map(function($m){ return $m['code'] . (empty($m['lecture_date']) ? '' : ' (' . $m['lecture_date'] . ')'); }, $module['missed'] ?? [])) ?: '—', ENT_QUOTES); ?></td>
                                                    <td class="<?php echo $module["dismissed"] ? 'dismissed' : 'active'; ?>">
                                                        <?php echo $module["dismissed"] ? "Dismissed" : "Active"; ?>
                                                    </td>
                                                </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                <?php endif; ?>
                            </div>

                            <div class="response-list">
                                <?php if (empty($responses)): ?>
                                    <div class="response-empty">No stored responses for this user yet.</div>
                                <?php else: ?>
                                    <?php foreach ($responses as $index => $response): ?>
                                        <div class="response-item">
                                            <h3>Response <?php echo htmlspecialchars((string) ($index + 1), ENT_QUOTES); ?></h3>
                                            <div class="response-grid">
                                                <div><strong>Name:</strong> <?php echo htmlspecialchars((string) ($response["name"] ?? ""), ENT_QUOTES); ?></div>
                                                <div><strong>Mobile:</strong> <?php echo htmlspecialchars((string) ($response["mobile"] ?? ""), ENT_QUOTES); ?></div>
                                                <div><strong>Group:</strong> <?php echo htmlspecialchars((string) ($response["group_name"] ?? ""), ENT_QUOTES); ?></div>
                                                <div><strong>Code:</strong> <?php echo htmlspecialchars((string) ($response["code"] ?? ""), ENT_QUOTES); ?></div>
                                                <div><strong>Code Valid:</strong> <?php echo isset($response["code_valid"]) ? ($response["code_valid"] ? "Yes" : "No") : "Unknown"; ?></div>
                                                <div><strong>In Range:</strong> <?php echo isset($response["is_in_range"]) ? ($response["is_in_range"] ? "Yes" : "No") : "Unknown"; ?></div>
                                                <div><strong>Latitude:</strong> <?php echo htmlspecialchars((string) ($response["latitude"] ?? ""), ENT_QUOTES); ?></div>
                                                <div><strong>Longitude:</strong> <?php echo htmlspecialchars((string) ($response["longitude"] ?? ""), ENT_QUOTES); ?></div>
                                                <div><strong>Created At:</strong> <?php echo htmlspecialchars((string) ($response["created_at"] ?? ""), ENT_QUOTES); ?></div>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </div>
                        </details>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>
    <script>
        const searchInput = document.getElementById("searchInput");
        const userCards = Array.from(document.querySelectorAll(".user-card"));

        if (searchInput) {
            searchInput.addEventListener("input", function () {
                const query = this.value.trim().toLowerCase();

                userCards.forEach(function (card) {
                    const searchable = (card.getAttribute("data-search") || "");
                    const matches = searchable.includes(query);
                    card.style.display = matches ? "" : "none";
                });
            });
        }
    </script>
    <script>
        const lectureTable = document.getElementById("lectureTable");
        const addLectureRowButton = document.getElementById("addLectureRow");
        const resetModuleFormButton = document.getElementById("resetModuleForm");
        const moduleAction = document.getElementById("module_action");
        const moduleCodeInput = document.getElementById("module_code");
        const moduleNameInput = document.getElementById("module_name");

        function createLectureRow(code = "", date = "") {
            const row = document.createElement("div");
            row.className = "lecture-row";
            row.innerHTML = `
                <input type="text" name="lecture_codes[]" placeholder="11" value="${code}" required>
                <input type="date" name="lecture_dates[]" value="${date}">
                <button type="button" class="remove-lecture" aria-label="Remove lecture">✕</button>
            `;

            const removeButton = row.querySelector(".remove-lecture");
            removeButton.addEventListener("click", () => {
                row.remove();
            });

            return row;
        }

        function resetModuleForm() {
            moduleAction.value = "create_module";
            moduleCodeInput.readOnly = false;
            moduleNameInput.value = "";
            moduleCodeInput.value = "";
            lectureTable.querySelectorAll(".lecture-row:not(.header-row)").forEach(row => row.remove());
            lectureTable.appendChild(createLectureRow());
        }

        function populateModuleForm(moduleCode, moduleName, schedule) {
            moduleAction.value = "update_module";
            moduleCodeInput.readOnly = true;
            moduleNameInput.value = moduleName;
            moduleCodeInput.value = moduleCode;
            lectureTable.querySelectorAll(".lecture-row:not(.header-row)").forEach(row => row.remove());

            if (Array.isArray(schedule) && schedule.length) {
                schedule.forEach(item => {
                    lectureTable.appendChild(createLectureRow(item.code ?? "", item.lecture_date ?? ""));
                });
            } else {
                lectureTable.appendChild(createLectureRow());
            }
        }

        if (addLectureRowButton) {
            addLectureRowButton.addEventListener("click", () => {
                lectureTable.appendChild(createLectureRow());
            });
        }

        if (resetModuleFormButton) {
            resetModuleFormButton.addEventListener("click", resetModuleForm);
        }

        document.querySelectorAll(".remove-lecture").forEach(button => {
            button.addEventListener("click", event => {
                const row = event.target.closest(".lecture-row");
                if (row && !row.classList.contains("header-row")) {
                    row.remove();
                }
            });
        });

        document.querySelectorAll(".edit-module").forEach(button => {
            button.addEventListener("click", event => {
                const moduleCode = event.target.dataset.moduleCode;
                const moduleName = event.target.dataset.moduleName;
                const schedule = JSON.parse(event.target.dataset.schedule || "[]");
                populateModuleForm(moduleCode, moduleName, schedule);
                window.scrollTo({ top: 0, behavior: "smooth" });
            });
        });
    </script>
</body>
</html>
