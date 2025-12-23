<?php
// bulk_import.php
// - Uploads "Sample Customer List" format
// - Filters by "Tag/Quarter"
// - Case-Sensitive RM Assignment

require_once 'auth.php';
require_once 'db_config.php';
require_once 'env_loader.php';
require_once __DIR__ . '/vendor/autoload.php';

use PhpOffice\PhpSpreadsheet\IOFactory;

requireAuth();

$pdo = getPdo();
$currentUserId = (int)($_SESSION['user_id'] ?? 1);

// Initialize Summary Stats
$summary = [
    'processed'   => 0,
    'assigned'    => 0,
    'unassigned'  => 0,
    'inserted'    => 0,
    'updated'     => 0,
    'skipped'     => 0,
    'errors'      => [],
];

// 1. FETCH USERS (Smart Matching)
$allUsers = [];
$uStmt = $pdo->query("SELECT id, username, name FROM users");
while ($uRow = $uStmt->fetch(PDO::FETCH_ASSOC)) {
    // Map BOTH "username" and "name" to the User ID (Lowercase for flexibility)
    $usernameKey = strtolower(trim($uRow['username']));
    $fullnameKey = strtolower(trim($uRow['name']));
    
    $allUsers[$usernameKey] = $uRow['id'];
    if (!empty($fullnameKey)) {
        $allUsers[$fullnameKey] = $uRow['id'];
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['allocation_file'])) {
    
    // Get the Target Tag from input (e.g., "RJ", "RM")
    $targetTag = trim($_POST['target_tag'] ?? '');
    
    if (empty($targetTag)) {
        $summary['errors'][] = "Please specify a Target Quarter/Tag (e.g., RJ) to import.";
    } else {
        $file = $_FILES['allocation_file']['tmp_name'];
        
        try {
            $spreadsheet = IOFactory::load($file);
            $sheet = $spreadsheet->getActiveSheet();
            $rows = $sheet->toArray(null, true, true, true); // Returns A, B, C indexed array

            // Loop through rows (Start from Row 2 to skip headers)
            foreach ($rows as $rowIndex => $row) {
                if ($rowIndex < 2) continue; // Skip Header

                // --- 1. PARSE COLUMNS BASED ON NEW FORMAT ---
                // Col A: Priority
                // Col B: Name
                // Col E: Tags (Quarter)
                // Col G: AUM
                // Col H: Relationship Manager
                    // Col I: Reviewer (Review Assigned To)

                $rawName     = trim($row['B'] ?? '');
                    $rawRM       = trim($row['H'] ?? ''); // Relationship Manager name
                    $rawReviewer = trim($row['I'] ?? ''); // Reviewer name
                $rawTag      = trim($row['E'] ?? ''); // The Quarter/Tag
                $rawAum      = $row['G'] ?? 0;
                $rawPriority = trim($row['A'] ?? '');

                // Skip empty rows
                if (empty($rawName)) continue;

                // --- 2. FILTER BY TAG/QUARTER ---
                // Only process if the Excel Tag matches the Input Tag
                // Using stripos for the Tag itself to be user-friendly (case-insensitive tag check)
                if (stripos($rawTag, $targetTag) === false) {
                    $summary['skipped']++;
                    continue; 
                }

                $summary['processed']++;

                // --- 3. SMART LOOKUP ---
                $assignedToId = null;
                $lookupKey = strtolower($rawRM); // Convert Excel name to lowercase
                    $reviewerId   = null;

                    $rmKey = strtolower($rawRM);
                    $revKey = strtolower($rawReviewer);

                if (!empty($lookupKey) && isset($allUsers[$lookupKey])) {
                    $assignedToId = $allUsers[$lookupKey];
                    $summary['assigned']++;
                } else {
                    $summary['unassigned']++;
                }
                
                    if (!empty($revKey) && isset($allUsers[$revKey])) {
                        $reviewerId = $allUsers[$revKey];
                    }

                // Clean Amount
                $cleanAum = (float)preg_replace('/[^0-9\.-]/', '', (string)$rawAum);

                // --- 4. DB UPSERT (Insert or Update) ---
                
                // Check if client exists
                $chk = $pdo->prepare("SELECT id FROM clients WHERE name = :name LIMIT 1");
                $chk->execute([':name' => $rawName]);
                $exists = $chk->fetchColumn();

                if ($exists) {
                    // UPDATE
                    $upd = $pdo->prepare("
                        UPDATE clients SET 
                            assigned_to = :assign, 
                                review_assigned_to = :reviewer,
                            total_amount = :aum,
                            priority = :prio,
                            updated_at = NOW()
                        WHERE id = :id
                    ");
                    $upd->execute([
                        ':assign'   => $assignedToId,
                        ':reviewer' => $reviewerId,
                        ':aum'      => $cleanAum,
                        ':prio'     => $rawPriority,
                        ':id'       => (int)$exists
                    ]);
                    $summary['updated']++;
                } else {
                    // INSERT (Default state: 'pending')
                    $ins = $pdo->prepare("
                        INSERT INTO clients 
                        (name, assigned_to, review_assigned_to, total_amount, priority, report_state, created_at, created_by) 
                        VALUES 
                        (:name, :assign, :reviewer, :aum, :prio, 'pending', NOW(), :creator)
                    ");
                    $ins->execute([
                        ':name'     => $rawName,
                        ':assign'   => $assignedToId,
                        ':reviewer' => $reviewerId,
                        ':aum'      => $cleanAum,
                        ':prio'     => $rawPriority,
                        ':creator'  => $currentUserId
                    ]);
                    $summary['inserted']++;
                }
            }

        } catch (Exception $e) {
            $summary['errors'][] = "Error processing file: " . $e->getMessage();
        }
    }
}
?>
<!DOCTYPE html>
<html>
<head>
    <title>Bulk Allocation</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600&display=swap" rel="stylesheet">
    <style>
        body { font-family: 'Inter', sans-serif; background: #f7f9fb; padding: 20px; }
        
        /* Navigation */
        .navbar { background: #fff; padding: 15px 30px; border-bottom: 1px solid #e0e0e0; display: flex; justify-content: space-between; align-items: center; margin-bottom: 30px; border-radius: 8px; }
        .nav-brand { font-weight: 700; color: #2c3e50; text-decoration: none; font-size: 1.2rem; }
        .nav-links a { margin-right: 20px; text-decoration: none; color: #555; }
        .nav-links a:hover { color: #1565c0; }
        
        .container { max-width: 600px; margin: 0 auto; background: white; padding: 40px; border-radius: 8px; box-shadow: 0 4px 12px rgba(0,0,0,0.05); }
        h2 { margin-top: 0; color: #333; }
        
        .form-group { margin-bottom: 20px; }
        label { display: block; margin-bottom: 8px; font-weight: 600; color: #555; }
        input[type="text"], input[type="file"] { width: 100%; padding: 10px; border: 1px solid #ccc; border-radius: 4px; }
        
        button { background: #1565c0; color: white; padding: 12px 20px; border: none; border-radius: 4px; cursor: pointer; font-weight: 600; width: 100%; }
        button:hover { background: #0d47a1; }
        
        .summary { margin-top: 20px; padding: 15px; background: #f1f8e9; border: 1px solid #c5e1a5; border-radius: 4px; color: #33691e; }
        .error { color: #c62828; margin-top: 10px; }
        .info-box { background: #e3f2fd; padding: 15px; border-radius: 4px; margin-top: 20px; font-size: 14px; color: #0d47a1; }
    </style>
</head>
<body>

    <nav class="navbar">
        <div style="display:flex; align-items:center">
            <img src="image.png" alt="Logo" style="height:40px;vertical-align:middle;margin-right:10px;">
            <a href="upload.php" class="nav-brand">Finance Doctor</a>
            <div class="nav-links" style="margin-left: 40px;">
                <a href="upload.php">Dashboard</a>
                <a href="view_saved_reports.php">All Reports</a>
                <a href="bulk_import.php" style="color: #1565c0; font-weight: bold;">Bulk Allocate</a>
            </div>
        </div>
        <div>
            <a href="logout.php" style="color: #c62828; text-decoration: none; font-size: 14px;">Logout</a>
        </div>
    </nav>

    <div class="container">
        <h2>Bulk Client Allocation</h2>
        <p style="color:#666; margin-bottom: 25px;">Upload the "Dec 2025" Customer List format to assign tasks.</p>

        <form method="post" enctype="multipart/form-data">
            
            <div class="form-group">
                <label>1. Enter Quarter/Tag to Import (Required)</label>
                <input type="text" name="target_tag" placeholder="e.g. RJ, RM, or RF" required>
                <small style="color:#888;">Only clients with this exact tag in Column E will be imported.</small>
            </div>

            <div class="form-group">
                <label>2. Select Excel File (.xlsx)</label>
                <input type="file" name="allocation_file" accept=".xlsx, .xls" required>
            </div>

            <button type="submit">Import & Allocate</button>
        </form>

        <?php if ($_SERVER['REQUEST_METHOD'] === 'POST'): ?>
            <div class="summary">
                <h4>Import Result for Tag: "<?php echo htmlspecialchars($targetTag); ?>"</h4>
                <div><strong>Processed:</strong> <?php echo (int)$summary['processed']; ?> (Skipped: <?php echo (int)$summary['skipped']; ?>)</div>
                <div style="margin-top:5px;"><strong>Assigned:</strong> <?php echo (int)$summary['assigned']; ?> | <strong>Unassigned:</strong> <?php echo (int)$summary['unassigned']; ?></div>
                <div style="margin-top:5px;"><strong>New Clients:</strong> <?php echo (int)$summary['inserted']; ?> | <strong>Updated:</strong> <?php echo (int)$summary['updated']; ?></div>
                
                <?php if (!empty($summary['errors'])): ?>
                    <div class="error" style="margin-top:15px;">
                        <strong>Errors:</strong>
                        <ul>
                            <?php foreach ($summary['errors'] as $err): ?>
                                <li><?php echo htmlspecialchars($err); ?></li>
                            <?php endforeach; ?>
                        </ul>
                    </div>
                <?php endif; ?>
            </div>
        <?php endif; ?>

        <div class="info-box">
            <strong>Formatting Rules:</strong>
            <ul style="padding-left: 20px; margin: 5px 0;">
                <li><strong>Column E (Tags):</strong> Must match the tag you entered above (e.g., 'RJ').</li>
                <li><strong>Column H (RM Name):</strong> Case-insensitive; matches either a username or full name.</li>
                <li><strong>Column I (Reviewer Name):</strong> Case-insensitive; matches either a username or full name.</li>
                <li><strong>Column B:</strong> Client Name.</li>
                <li><strong>Column A:</strong> Priority.</li>
            </ul>
        </div>
    </div>

</body>
</html>