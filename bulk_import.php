<?php
// bulk_import.php
// Upload an .xlsx file to bulk-create/update clients and assign them to RMs.

require_once 'auth.php';
require_once 'db_config.php';
require_once 'env_loader.php';
require_once __DIR__ . '/vendor/autoload.php';

use PhpOffice\PhpSpreadsheet\IOFactory;

requireAuth();

$pdo = getPdo();
$currentUser = getCurrentUser();
$currentUserId = (int)($_SESSION['user_id'] ?? 1);

$summary = [
    'processed'   => 0,
    'assigned'    => 0,
    'unassigned'  => 0,
    'inserted'    => 0,
    'updated'     => 0,
    'errors'      => [],
];

function parsePriority(?string $raw): ?string {
    $val = strtolower(trim((string)$raw));
    if ($val === 'high' || $val === 'medium' || $val === 'low') {
        return ucfirst($val);
    }
    return null;
}

function parseAmount($raw): float {
    $clean = preg_replace('/[^0-9\.-]/', '', (string)$raw);
    return (float)$clean;
}

function findUserIdByUsername(PDO $pdo, ?string $username): ?int {
    $name = trim((string)$username);
    if ($name === '') {
        return null;
    }
    $stmt = $pdo->prepare("SELECT id FROM users WHERE LOWER(username) LIKE LOWER(:uname) LIMIT 1");
    $stmt->execute([':uname' => $name]);
    $id = $stmt->fetchColumn();
    return $id ? (int)$id : null;
}

function upsertClient(PDO $pdo, string $clientName, ?int $assignedTo, float $totalAmount, ?string $priority, int $createdBy): bool {
    // Check if client exists (case-insensitive match on name)
    $stmt = $pdo->prepare("SELECT id FROM clients WHERE name = :name LIMIT 1");
    $stmt->execute([':name' => $clientName]);
    $existingId = $stmt->fetchColumn();

    if ($existingId) {
        $stmtUpdate = $pdo->prepare("UPDATE clients SET assigned_to = :assigned_to, total_amount = :total_amount, priority = :priority WHERE id = :id");
        return $stmtUpdate->execute([
            ':assigned_to'  => $assignedTo,
            ':total_amount' => $totalAmount,
            ':priority'     => $priority,
            ':id'           => (int)$existingId,
        ]);
    }

    // Set default state to 'pending' so it doesn't show in Saved Reports yet
    $stmtInsert = $pdo->prepare("INSERT INTO clients (name, assigned_to, total_amount, priority, report_state, created_at) VALUES (:name, :assigned, :amount, :priority, 'pending', NOW())");

    return $stmtInsert->execute([
        ':name'     => $clientName,
        ':assigned' => $assignedTo,
        ':amount'   => $totalAmount,
        ':priority' => $priority,
    ]);
}

$navUser = $_SESSION['username'] ?? ($currentUser['username'] ?? 'User');
$currentPage = basename($_SERVER['PHP_SELF']);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['bulk_file'])) {
    $file = $_FILES['bulk_file'];
    if ($file['error'] !== UPLOAD_ERR_OK) {
        $summary['errors'][] = 'File upload failed.';
    } else {
        $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        if ($ext !== 'xlsx') {
            $summary['errors'][] = 'Only .xlsx files are supported.';
        } else {
            try {
                $spreadsheet = IOFactory::load($file['tmp_name']);
                $sheet = $spreadsheet->getActiveSheet();
                $rows = $sheet->toArray(null, true, true, true);

                // Expect header in first row: A=Client Name, B=RM, C=AUM, D=Priority
                foreach ($rows as $index => $row) {
                    if ($index === 1) {
                        continue; // skip header
                    }

                    $clientName = trim((string)($row['A'] ?? ''));
                    $rmName     = trim((string)($row['B'] ?? ''));
                    $aumRaw     = $row['C'] ?? '0';
                    $priority   = parsePriority($row['D'] ?? '');

                    if ($clientName === '') {
                        continue;
                    }

                    $summary['processed']++;

                    $assignedTo = findUserIdByUsername($pdo, $rmName);
                    if ($assignedTo) {
                        $summary['assigned']++;
                    } else {
                        $summary['unassigned']++;
                    }

                    $totalAmount = parseAmount($aumRaw);

                    $existsStmt = $pdo->prepare("SELECT id FROM clients WHERE name = :name LIMIT 1");
                    $existsStmt->execute([':name' => $clientName]);
                    $existingId = $existsStmt->fetchColumn();

                    $ok = upsertClient($pdo, $clientName, $assignedTo, $totalAmount, $priority, $currentUserId);

                    if (!$ok) {
                        $summary['errors'][] = "Row {$index}: DB write failed for client {$clientName}";
                        continue;
                    }

                    if ($existingId) {
                        $summary['updated']++;
                    } else {
                        $summary['inserted']++;
                    }
                }
            } catch (Throwable $e) {
                $summary['errors'][] = 'Import failed: ' . $e->getMessage();
            }
        }
    }
}
?>
<!DOCTYPE html>
<html>
<head>
    <title>Bulk Import Clients</title>
    <link rel="stylesheet" href="public/css/styles.css">
    <style>
        /* Navigation Bar Styles */
        .navbar {
            background-color: #ffffff;
            border-bottom: 1px solid #e0e0e0;
            padding: 15px 30px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            box-shadow: 0 2px 4px rgba(0,0,0,0.05);
            margin-bottom: 25px;
            font-family: 'Segoe UI', system-ui, sans-serif;
        }
        .nav-brand {
            font-size: 1.25rem;
            font-weight: 700;
            color: #2c3e50;
            text-decoration: none;
            margin-right: 40px;
        }
        .nav-links a {
            text-decoration: none;
            color: #555;
            font-weight: 500;
            margin-right: 25px;
            transition: color 0.2s;
        }
        .nav-links a:hover, .nav-links a.active {
            color: #1565c0; /* Primary Blue */
        }
        .nav-user {
            display: flex;
            align-items: center;
            gap: 15px;
            font-size: 0.9rem;
            color: #777;
        }
        .btn-logout {
            text-decoration: none;
            padding: 6px 16px;
            background-color: #ffebee;
            color: #c62828;
            border-radius: 6px;
            font-weight: 600;
            font-size: 0.85rem;
            transition: background 0.2s;
        }
        .btn-logout:hover {
            background-color: #ffcdd2;
        }
        body { font-family: Arial, sans-serif; margin: 20px; }
        .card { border: 1px solid #ccc; padding: 16px; border-radius: 6px; max-width: 720px; }
        .summary { margin-top: 20px; padding: 12px; border: 1px solid #e0e0e0; background: #f8f8f8; }
        table { width: 100%; border-collapse: collapse; margin-top: 12px; }
        th, td { border: 1px solid #ddd; padding: 8px; text-align: left; }
        th { background: #f0f6ff; }
        .error { color: #b00020; }
        .success { color: #1b5e20; }
    </style>
</head>
<body>
    <?php 
    // Ensure we have user details for the header
    $navUser = $_SESSION['username'] ?? ($currentUser['username'] ?? 'User');
    $currentPage = basename($_SERVER['PHP_SELF']); 
    ?>

    <nav class="navbar">
        <div style="display: flex; align-items: center;">
            <a href="upload.php" class="nav-brand">Finance Doctor</a>
            <div class="nav-links">
                <a href="upload.php" class="<?php echo $currentPage == 'upload.php' ? 'active' : ''; ?>">Dashboard</a>
                <a href="view_saved_reports.php" class="<?php echo $currentPage == 'view_saved_reports.php' ? 'active' : ''; ?>">All Reports</a>
                <a href="bulk_import.php" class="<?php echo $currentPage == 'bulk_import.php' ? 'active' : ''; ?>">Bulk Allocate</a>
            </div>
        </div>
        
        <div class="nav-user">
            <span><i class="stat-icon">👤</i> <?php echo htmlspecialchars($navUser); ?></span>
            <a href="logout.php" class="btn-logout">Logout</a>
        </div>
    </nav>

    <div class="card">
        <h2>Bulk Import Clients (.xlsx)</h2>
        <form method="post" enctype="multipart/form-data">
            <div style="margin-bottom: 10px;">
                <label for="bulk_file"><strong>Select Excel file (.xlsx)</strong></label><br>
                <input type="file" name="bulk_file" id="bulk_file" accept=".xlsx" required>
            </div>
            <button type="submit">Upload and Import</button>
        </form>

        <?php if ($_SERVER['REQUEST_METHOD'] === 'POST'): ?>
            <div class="summary">
                <div><strong>Processed:</strong> <?php echo (int)$summary['processed']; ?></div>
                <div><strong>Inserted:</strong> <?php echo (int)$summary['inserted']; ?> | <strong>Updated:</strong> <?php echo (int)$summary['updated']; ?></div>
                <div><strong>Assigned:</strong> <?php echo (int)$summary['assigned']; ?> | <strong>Unassigned:</strong> <?php echo (int)$summary['unassigned']; ?></div>
                <?php if (!empty($summary['errors'])): ?>
                    <div class="error"><strong>Errors:</strong>
                        <ul>
                            <?php foreach ($summary['errors'] as $err): ?>
                                <li><?php echo htmlspecialchars($err); ?></li>
                            <?php endforeach; ?>
                        </ul>
                    </div>
                <?php else: ?>
                    <div class="success">Import completed.</div>
                <?php endif; ?>
            </div>
        <?php endif; ?>

        <div style="margin-top: 15px;">
            <strong>Expected Columns (Row 1 headers):</strong>
            <ul>
                <li>Column A: Client Name</li>
                <li>Column B: Relationship Manager / ARM (matches username)</li>
                <li>Column C: AUM</li>
                <li>Column D: Priority (High / Medium / Low)</li>
            </ul>
        </div>
    </div>
</body>
</html>
