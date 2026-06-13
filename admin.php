<?php
$databaseFile = __DIR__ . '/attendance.sqlite';

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

try {
    $conn = openDatabase($databaseFile);
} catch (PDOException $e) {
    http_response_code(500);
    die('Database connection failed: ' . $e->getMessage());
}

$rows = $conn->query('SELECT academic_number, name, mobile, group_name, latitude, longitude, code, responses_json, response_count, created_at, updated_at FROM attendance ORDER BY updated_at DESC, academic_number ASC')->fetchAll(PDO::FETCH_ASSOC);

if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="attendance-export-' . date('Y-m-d') . '.csv"');

    $output = fopen('php://output', 'w');
    fputcsv($output, ['Academic Number', 'Name', 'Mobile', 'Group', 'Latitude', 'Longitude', 'Code', 'Responses Count', 'Responses JSON', 'Created At', 'Updated At']);

    foreach ($rows as $row) {
        fputcsv($output, [
            $row['academic_number'],
            $row['name'],
            $row['mobile'],
            $row['group_name'],
            $row['latitude'],
            $row['longitude'],
            $row['code'],
            $row['response_count'],
            $row['responses_json'],
            $row['created_at'],
            $row['updated_at'] ?? $row['created_at'],
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

        .response-list {
            border-top: 1px solid var(--border);
            background: var(--panel-alt);
            padding: 16px;
            display: grid;
            gap: 12px;
        }

        .response-item {
            background: #fff;
            border: 1px solid var(--border);
            border-radius: 14px;
            padding: 14px;
        }

        .response-item h3 {
            margin: 0 0 10px;
            font-size: 14px;
            color: var(--text);
        }

        .response-grid {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 8px 14px;
            font-size: 13px;
            color: var(--muted);
        }

        .response-grid strong {
            color: var(--text);
        }

        .response-empty {
            color: var(--muted);
            font-size: 13px;
            padding: 8px 0 0;
        }

        .empty {
            padding: 40px;
            text-align: center;
            color: var(--muted);
        }

        .badge {
            display: inline-block;
            padding: 6px 10px;
            border-radius: 999px;
            background: #dbeafe;
            color: #1d4ed8;
            font-weight: 700;
            font-size: 12px;
        }

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

        <div class="toolbar">
            <div class="count"><?php echo htmlspecialchars((string) $total, ENT_QUOTES, 'UTF-8'); ?> total records</div>
            <div class="actions">
                <a href="admin.php?export=csv">Export CSV</a>
            </div>
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
                            $responses = json_decode((string) ($row['responses_json'] ?? '[]'), true);
                            if (!is_array($responses)) {
                                $responses = [];
                            }
                            $searchText = strtolower(trim(implode(' ', [
                                (string) $row['academic_number'],
                                (string) $row['name'],
                                (string) $row['mobile'],
                                (string) $row['group_name'],
                                (string) $row['code'],
                            ])));
                        ?>
                        <details class="user-card" data-search="<?php echo htmlspecialchars($searchText, ENT_QUOTES, 'UTF-8'); ?>">
                            <summary class="user-summary">
                                <div class="summary-main">
                                    <h2><?php echo htmlspecialchars($row['name'], ENT_QUOTES, 'UTF-8'); ?></h2>
                                    <div class="summary-meta">
                                        <span><strong>Academic:</strong> <?php echo htmlspecialchars($row['academic_number'], ENT_QUOTES, 'UTF-8'); ?></span>
                                        <span><strong>Mobile:</strong> <?php echo htmlspecialchars($row['mobile'], ENT_QUOTES, 'UTF-8'); ?></span>
                                        <span><strong>Group:</strong> <?php echo htmlspecialchars($row['group_name'], ENT_QUOTES, 'UTF-8'); ?></span>
                                        <span><strong>Responses:</strong> <?php echo htmlspecialchars((string) $row['response_count'], ENT_QUOTES, 'UTF-8'); ?></span>
                                    </div>
                                </div>
                                <span class="summary-chevron">▾</span>
                            </summary>

                            <div class="response-list">
                                <?php if (empty($responses)): ?>
                                    <div class="response-empty">No stored responses for this user yet.</div>
                                <?php else: ?>
                                    <?php foreach ($responses as $index => $response): ?>
                                        <div class="response-item">
                                            <h3>Response <?php echo htmlspecialchars((string) ($index + 1), ENT_QUOTES, 'UTF-8'); ?></h3>
                                            <div class="response-grid">
                                                <div><strong>Name:</strong> <?php echo htmlspecialchars((string) ($response['name'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></div>
                                                <div><strong>Mobile:</strong> <?php echo htmlspecialchars((string) ($response['mobile'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></div>
                                                <div><strong>Group:</strong> <?php echo htmlspecialchars((string) ($response['group_name'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></div>
                                                <div><strong>Code:</strong> <?php echo htmlspecialchars((string) ($response['code'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></div>
                                                <div><strong>Latitude:</strong> <?php echo htmlspecialchars((string) ($response['latitude'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></div>
                                                <div><strong>Longitude:</strong> <?php echo htmlspecialchars((string) ($response['longitude'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></div>
                                                <div><strong>Created At:</strong> <?php echo htmlspecialchars((string) ($response['created_at'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></div>
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
        const searchInput = document.getElementById('searchInput');
        const userCards = Array.from(document.querySelectorAll('.user-card'));

        if (searchInput) {
            searchInput.addEventListener('input', function () {
                const query = this.value.trim().toLowerCase();

                userCards.forEach(function (card) {
                    const searchable = (card.getAttribute('data-search') || '');
                    const matches = searchable.includes(query);
                    card.style.display = matches ? '' : 'none';
                });
            });
        }
    </script>
</body>
</html>
