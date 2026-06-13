<?php
require_once __DIR__ . '/db.php';

try {
    $conn = db_openDatabase();
} catch (PDOException $e) {
    http_response_code(500);
    die('Database connection failed: ' . $e->getMessage());
}

$message = '';
$modules = db_getAllModules($conn);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $academicNumber = trim((string) ($_POST['academic_number'] ?? ''));
    $name = trim((string) ($_POST['name'] ?? ''));
    $mobile = trim((string) ($_POST['mobile'] ?? ''));
    $group = trim((string) ($_POST['group'] ?? ''));
    $password = trim((string) ($_POST['password'] ?? ''));
    $selectedModules = array_filter(array_map('trim', (array) ($_POST['modules'] ?? [])));

    if ($academicNumber === '' || $name === '' || $mobile === '' || $group === '' || $password === '') {
        $message = 'All fields are required, including a password.';
    } elseif (empty($selectedModules)) {
        $message = 'Please select at least one module.';
    } else {
        db_enrollStudentInModules($conn, $academicNumber, $selectedModules, $name, $mobile, $group);
        db_setStudentPassword($conn, $academicNumber, $password);
        $message = 'Student signed up successfully for selected modules. Password set.';
    }
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Student Signup</title>
    <style>
        body {
            margin: 0;
            font-family: Arial, Helvetica, sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: #111827;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 24px;
        }
        .container {
            width: 100%;
            max-width: 520px;
            background: #ffffff;
            border-radius: 18px;
            padding: 28px;
            box-shadow: 0 24px 80px rgba(0, 0, 0, 0.12);
        }
        h1 {
            margin: 0 0 18px;
            font-size: 26px;
            color: #111827;
        }
        .field {
            margin-bottom: 16px;
        }
        label {
            display: block;
            margin-bottom: 8px;
            font-weight: 700;
            color: #334155;
        }
        input[type="text"], input[type="tel"], select, .checkbox-group {
            width: 100%;
            padding: 12px 14px;
            border: 1px solid #cbd5e1;
            border-radius: 12px;
            font-size: 14px;
        }
        .checkbox-group {
            display: grid;
            gap: 10px;
            background: #f8fafc;
            border: 1px solid #cbd5e1;
            border-radius: 12px;
            padding: 14px;
        }
        .checkbox-item {
            display: flex;
            align-items: center;
            gap: 10px;
        }
        .message {
            margin-bottom: 18px;
            padding: 14px 16px;
            border-radius: 14px;
            background: #e0f2fe;
            color: #0369a1;
        }
        button {
            width: 100%;
            padding: 14px;
            border: none;
            border-radius: 12px;
            background: #2563eb;
            color: white;
            font-weight: 700;
            cursor: pointer;
            font-size: 15px;
        }
        button:hover {
            background: #1d4ed8;
        }
        .note {
            margin-top: 18px;
            font-size: 13px;
            color: #475569;
        }
    </style>
</head>
<body>
    <div class="container">
        <h1>Student Signup</h1>
        <?php if ($message !== ''): ?>
            <div class="message"><?php echo htmlspecialchars($message, ENT_QUOTES, 'UTF-8'); ?></div>
        <?php endif; ?>
        <form method="post" action="signup.php">
            <div class="field">
                <label for="academic_number">Academic Number</label>
                <input type="text" id="academic_number" name="academic_number" required>
            </div>
            <div class="field">
                <label for="name">Full Name</label>
                <input type="text" id="name" name="name" required>
            </div>
            <div class="field">
                <label for="mobile">Mobile Number</label>
                <input type="tel" id="mobile" name="mobile" required>
            </div>
            <div class="field">
                <label for="group">Group</label>
                <select id="group" name="group" required>
                    <option value="">Select a group</option>
                    <option value="A">Group A</option>
                    <option value="B">Group B</option>
                </select>
            </div>
            <div class="field">
                <label>Enroll in Modules</label>
                <div class="checkbox-group">
                    <?php foreach ($modules as $module): ?>
                        <label class="checkbox-item">
                            <input type="checkbox" name="modules[]" value="<?php echo htmlspecialchars($module['module_code'], ENT_QUOTES, 'UTF-8'); ?>">
                            <?php echo htmlspecialchars($module['module_name'] . ' (' . $module['module_code'] . ')', ENT_QUOTES, 'UTF-8'); ?>
                        </label>
                    <?php endforeach; ?>
                </div>
            </div>
            <div class="field">
                <label for="password">Create Password (for student dashboard)</label>
                <input type="password" id="password" name="password" placeholder="Choose a password" required>
            </div>
            <button type="submit">Sign Up</button>
        </form>
        <div class="note">Create modules first from the admin page, then students can select them here.</div>
    </div>
</body>
</html>
