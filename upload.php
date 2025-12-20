<?php
// upload.php
// - Upload Excel/PDF files
// - Parse and build per-client reports
// - Store everything into DB

require_once 'auth.php'; 
require_once 'db_config.php';
// Assuming parsers.php, renderers.php, and env_loader.php exist
require_once 'parsers.php'; 
require_once 'renderers.php'; 
require_once 'env_loader.php'; 

requireAuth(); // Enforce login

// Fetch current user details
$currentUser = getCurrentUser();
// --- FIX: Capture Creator ID from session (fallback to admin ID 1) ---
$currentUserId = $_SESSION['user_id'] ?? 1;

$pdo = getPdo();

// My workload stats (current user)
$myId = (int)($_SESSION['user_id'] ?? 0);
$myStatsStmt = $pdo->prepare("
    SELECT 
        COUNT(*) as total,
        SUM(CASE WHEN report_state = 'pending' THEN 1 ELSE 0 END) as count_pending,
        SUM(CASE WHEN report_state = 'draft' THEN 1 ELSE 0 END) as count_draft,
        SUM(CASE WHEN report_state = 'ready' THEN 1 ELSE 0 END) as count_ready,
        SUM(CASE WHEN report_state = 'reviewed' THEN 1 ELSE 0 END) as count_reviewed,
        SUM(CASE WHEN report_state = 'sent' THEN 1 ELSE 0 END) as count_sent
    FROM clients 
    WHERE assigned_to = :uid
");
$myStatsStmt->execute([':uid' => $currentUserId]);
$myStats = $myStatsStmt->fetch(PDO::FETCH_ASSOC) ?: [
    'total' => 0,
    'count_pending' => 0,
    'count_draft' => 0,
    'count_ready' => 0,
    'count_reviewed' => 0,
    'count_sent' => 0,
];

// Team leaderboard stats
$teamQuery = $pdo->query("
    SELECT 
        u.id,
        u.username, 
        u.designation,
        COUNT(c.id) as total_assigned,
        SUM(CASE WHEN c.report_state = 'sent' THEN 1 ELSE 0 END) as sent_count,
        SUM(CASE WHEN c.report_state != 'sent' THEN 1 ELSE 0 END) as pending_count,
        SUM(CASE WHEN c.priority = 'High' AND c.report_state != 'sent' THEN 1 ELSE 0 END) as `high_priority`
    FROM users u
    LEFT JOIN clients c ON u.id = c.assigned_to
    GROUP BY u.id
    HAVING total_assigned > 0
    ORDER BY pending_count DESC
");
$teamStats = $teamQuery->fetchAll(PDO::FETCH_ASSOC);

function safePercent(int $part, int $whole): int {
    if ($whole <= 0) {
        return 0;
    }
    return (int)round(($part / $whole) * 100);
}

function fetchDashboardStats(PDO $pdo): array {
    $baseStats = [
        'pending' => 0,
        'draft' => 0,
        'ready' => 0,
        'reviewed' => 0,
        'sent' => 0,
    ];

    $query = $pdo->query("
        SELECT report_state, COUNT(*) AS total
        FROM clients
        GROUP BY report_state
    ");

    while ($row = $query->fetch(PDO::FETCH_ASSOC)) {
        if (isset($baseStats[$row['report_state']])) {
            $baseStats[$row['report_state']] = (int)$row['total'];
        }
    }

    return $baseStats;
}

$stats = fetchDashboardStats($pdo);

// Determine the correct name to display.
$displayName = 'User';
$nameForInitials = 'U';

if ($currentUser) {
    // Priority 1: Use the formal 'name' if available and not empty.
    if (!empty($currentUser['name'])) {
        $displayName = htmlspecialchars($currentUser['name']);
        $nameForInitials = $currentUser['name'];
    } 
    // Priority 2: Fallback to 'username' if 'name' is empty/null.
    elseif (!empty($currentUser['username'])) {
        $displayName = htmlspecialchars($currentUser['username']);
        $nameForInitials = $currentUser['username'];
    }
}

$initials = strtoupper(substr($nameForInitials, 0, 1));


/* ---------- CONFIG: DEFAULT TEXTS (fallbacks only) ---------- */
/* ---------- CONFIG: DEFAULT TEXTS (fallbacks only) ---------- */
$DEFAULT_GREETING  = $_POST['greeting']       ?? 'Dear Mr.';
$DEFAULT_INTRO     = $_POST['intro_text']     ?? 'Introduction';
$DEFAULT_CLOSING   = $_POST['closing_text']   ?? 'Closing remarks';
$DEFAULT_RATIONALE = $_POST['rationale_text'] ?? 'Rationale for recommendations';

/* ---------- HANDLE FILE UPLOAD AND PROCESSING ---------- */

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !isset($_POST['ajax'])) {

    $greetingBase  = $_POST['greeting']       ?? $DEFAULT_GREETING;
    $introText     = $_POST['intro_text']     ?? $DEFAULT_INTRO;
    $closingText   = $_POST['closing_text']   ?? $DEFAULT_CLOSING;
    $rationaleText = $_POST['rationale_text'] ?? $DEFAULT_RATIONALE;

    // Get upload configuration from environment variables
    $uploadDir = __DIR__ . '/uploads';
    $maxFileSize = $_ENV['UPLOAD_MAX_SIZE'] ?? (10 * 1024 * 1024); // Default 10MB
    $allowedExt = explode(',', $_ENV['ALLOWED_EXTENSIONS'] ?? 'xlsx,xls,pdf');

    // Ensure upload directory exists
    if (!is_dir($uploadDir)) {
        mkdir($uploadDir, 0777, true);
    }

    $pvFiles  = [];
    $aaFiles  = [];
    $rstFiles = [];
    $psFiles  = [];
    $pdfFiles = [];
    $fileErrors = [];

    try {
        foreach ($_FILES['client_files']['name'] as $i => $name) {
            if ($_FILES['client_files']['error'][$i] !== UPLOAD_ERR_OK) {
                $fileErrors[] = "Error uploading file: " . htmlspecialchars($name);
                continue;
            }

            $size = $_FILES['client_files']['size'][$i];
            if ($size > $maxFileSize) {
                $maxSizeMB = round($maxFileSize / (1024 * 1024), 1);
                $fileErrors[] = "File too large (max {$maxSizeMB}MB): " . htmlspecialchars($name);
                continue;
            }

            $tmp    = $_FILES['client_files']['tmp_name'][$i];
            $ext    = strtolower(pathinfo($name, PATHINFO_EXTENSION));

            if (!in_array($ext, $allowedExt, true)) {
                $allowedExtList = implode(', ', $allowedExt);
                $fileErrors[] = "Unsupported file type. Allowed: {$allowedExtList} - " . htmlspecialchars($name);
                continue;
            }

            $target = $uploadDir . '/' . uniqid() . '_' . basename($name);
            if (!move_uploaded_file($tmp, $target)) {
                $fileErrors[] = "Failed to move uploaded file: " . htmlspecialchars($name);
                continue;
            }

            if ($ext === 'xlsx' || $ext === 'xls') {
                if (stripos($name, 'PortfolioValuation') !== false) {
                    $pvFiles[] = $target;
                } elseif (stripos($name, 'Allocation Analysis') !== false || stripos($name, 'Allocation_Analysis') !== false) {
                    $aaFiles[] = $target;
                } elseif (stripos($name, 'Running Systematic Transactions') !== false) {
                    $rstFiles[] = $target;
                } elseif (stripos($name, 'Portfolio Summary') !== false) {
                    $psFiles[] = $target;
                }
            } elseif ($ext === 'pdf') {
                // Store all PDF files with their original names for annexures
                $pdfFiles[] = ['path' => $target, 'name' => basename($name)];
            }
        }

        $pvAll = [];
        foreach ($pvFiles as $f) {
            $tmp   = parsePortfolioValuation($f);
            $pvAll = array_replace_recursive($pvAll, $tmp);
        }

        $aaAll = [];
        foreach ($aaFiles as $f) {
            $tmp   = parseAllocationAnalysis($f);
            $aaAll = array_replace_recursive($aaAll, $tmp);
        }

        $rstAll = [];
        foreach ($rstFiles as $f) {
            $tmp = parseRunningSystematicTransactions($f);
            foreach ($tmp as $client => $schemes) {
                if (!isset($rstAll[$client])) $rstAll[$client] = [];
                foreach ($schemes as $scheme => $amt) {
                    if (!isset($rstAll[$client][$scheme])) $rstAll[$client][$scheme] = 0;
                    $rstAll[$client][$scheme] += $amt;
                }
            }
        }

        $psAll = [];
        foreach ($psFiles as $f) {
            $tmp   = parsePortfolioSummary($f);
            $psAll = array_replace_recursive($psAll, $tmp);
        }

        $allClientReports = [];
        $validClientNames = [];

        if ($pdfFiles) {
            foreach ($pdfFiles as $pdfFile) {
                // Extract the path from the array structure
                $pdfPath = is_array($pdfFile) ? $pdfFile['path'] : $pdfFile;
                $pdfGoal = parseGoalStatusPdf($pdfPath);
                if (!empty($pdfGoal['client_name'])) {
                    $validClientNames[] = $pdfGoal['client_name'];
                }
                $reports = buildClientReports($pvAll, $aaAll, $rstAll, $psAll, $pdfGoal);
                $allClientReports = array_replace_recursive($allClientReports, $reports);
            }
        } else {
            $reports = buildClientReports($pvAll, $aaAll, $rstAll, $psAll, ['client_name'=>'','as_on'=>'','goals'=>[]]);
            $allClientReports = array_replace_recursive($allClientReports, $reports);
        }

        $validSet = array_flip($validClientNames);

        $pdo = getPdo();

        $stmtClient = $pdo->prepare("
                    INSERT INTO clients
                        (name, as_on, total_amount, profit, cagr, xirr, absolute_return,
                         total_goal_current, total_goal_target, total_sip,
                         greeting_prefix, intro_text, closing_text, rationale_text, created_by, report_state)
                    VALUES
                        (:name, :as_on, :total_amount, :profit, :cagr, :xirr, :absolute_return,
                         :total_goal_current, :total_goal_target, :total_sip,
                         :greeting_prefix, :intro_text, :closing_text, :rationale_text, :created_by, :report_state)
                ");

        $checkClient = $pdo->prepare("SELECT id, report_state FROM clients WHERE name = :name LIMIT 1");
        $updateClient = $pdo->prepare("
            UPDATE clients
            SET report_state = 'ready',
                as_on = :as_on,
                total_amount = :total_amount,
                profit = :profit,
                cagr = :cagr,
                xirr = :xirr,
                absolute_return = :absolute_return,
                total_goal_current = :total_goal_current,
                total_goal_target = :total_goal_target,
                total_sip = :total_sip,
                greeting_prefix = :greeting_prefix,
                intro_text = :intro_text,
                closing_text = :closing_text,
                rationale_text = :rationale_text,
                updated_at = NOW()
            WHERE id = :id
        ");

        $wipeGoals  = $pdo->prepare("DELETE FROM client_goals WHERE client_id = :cid");
        $wipeAlloc  = $pdo->prepare("DELETE FROM client_allocations WHERE client_id = :cid");
        $wipeSchemes= $pdo->prepare("DELETE FROM client_schemes WHERE client_id = :cid");
        $wipeAnnex  = $pdo->prepare("DELETE FROM client_annexures WHERE client_id = :cid");

$stmtGoal = $pdo->prepare("
    INSERT INTO client_goals
        (client_id, goal, goal_date, current_amount, sip_swp,
         target_amount, projected, shortfall, completion, status)
    VALUES
        (:client_id, :goal, :goal_date, :current_amount, :sip_swp,
         :target_amount, :projected, :shortfall, :completion, :status)
");

        $stmtAlloc = $pdo->prepare("
            INSERT INTO client_allocations
                (client_id, asset, share_pct)
            VALUES
                (:client_id, :asset, :share_pct)
        ");

        $stmtScheme = $pdo->prepare("
            INSERT INTO client_schemes
                (client_id, scheme_name, sip_swp, current_value,
                 action_step, recommended_scheme, recommended_amount)
            VALUES
                (:client_id, :scheme_name, :sip_swp, :current_value,
                 :action_step, :recommended_scheme, :recommended_amount)
        ");

        $stmtAnnex = $pdo->prepare("
            INSERT INTO client_annexures
                (client_id, line_text)
            VALUES
                (:client_id, :line_text)
        ");

        $savedCount = 0;
        $firstClientId = 0; // Track the first client ID for redirect

        if (!$allClientReports) {
            ?>
            <!DOCTYPE html>
            <html>
            <head>
                <title>No Data</title>
                <link rel="stylesheet" href="public/css/styles.css">
                <style>body { font-family: Arial, sans-serif; margin: 20px; }</style>
            </head>
            <?php
            exit;
        }

        foreach ($allClientReports as $client => $data) {
            if (!empty($validSet) && !isset($validSet[$data['name']])) continue;

            $name       = $data['name'];
            $clientName = trim((string)$name);
            $asOn       = $data['as_on'] ?? '';
            $allocation = $data['allocation'] ?? [];
            $schemes    = $data['schemes'] ?? [];
            $goals      = $data['goals'] ?? [];

            $totals  = $data['current']['totals'] ?? [
                'purchase'        => 0,
                'current'         => 0,
                'profit'          => 0,
                'cagr_weighted'   => 0,
                'xirr_weighted'   => 0,
                'absolute_return' => 0,
            ];
            $summary = $data['current']['summary'] ?? null;

            $totalAmount = $totals['current'];
            $profit      = $summary['profit'] ?? $totals['profit'];
            $cagr        = $totals['cagr_weighted'];
            $xirr        = $summary['xirr'] ?? $totals['xirr_weighted'];
            $absoluteReturn = $totals['absolute_return'] ?? 0;

            $totalSip         = 0;
            $totalGoalCurrent = 0;
            $totalGoalTarget  = 0;
            foreach ($goals as $g) {
                $totalSip         += $g['running_sip']   ?? 0;
                $totalGoalCurrent += $g['current_value'] ?? 0;
                $totalGoalTarget  += $g['target_amount'] ?? 0;
            }

            // Build annexures list from uploaded PDF files
            $annexureLinesForClient = [];
            foreach ($pdfFiles as $pdfInfo) {
                if (is_array($pdfInfo)) {
                    $annexureLinesForClient[] = $pdfInfo['name'];
                }
            }

            // ----- UPSERT MASTER ROW -----
            $checkClient->execute([':name' => $clientName]);
            $existingRow = $checkClient->fetch(PDO::FETCH_ASSOC) ?: null;
            $existingId = $existingRow['id'] ?? null;

            if ($existingId) {
                $clientId = (int)$existingId;

                // Replace details and mark as ready when a pending allocation receives data
                $updateClient->execute([
                    ':as_on'              => $asOn,
                    ':total_amount'       => $totalAmount,
                    ':profit'             => $profit,
                    ':cagr'               => $cagr,
                    ':xirr'               => $xirr,
                    ':absolute_return'    => $absoluteReturn,
                    ':total_goal_current' => $totalGoalCurrent,
                    ':total_goal_target'  => $totalGoalTarget,
                    ':total_sip'          => $totalSip,
                    ':greeting_prefix'    => $DEFAULT_GREETING,
                    ':intro_text'         => $DEFAULT_INTRO,
                    ':closing_text'       => $DEFAULT_CLOSING,
                    ':rationale_text'     => $DEFAULT_RATIONALE,
                    ':id'                 => $clientId,
                ]);

                if ($firstClientId === 0) {
                    $firstClientId = $clientId;
                }

                // Clear old children to avoid duplicates before reinserting fresh data
                $wipeGoals->execute([':cid' => $clientId]);
                $wipeAlloc->execute([':cid' => $clientId]);
                $wipeSchemes->execute([':cid' => $clientId]);
                $wipeAnnex->execute([':cid' => $clientId]);
            } else {
                $userId = $currentUserId; // Use session user ID (fallback 1) to track creator
                $stmtClient->execute([
                    ':name'               => $clientName,
                    ':as_on'              => $asOn,
                    ':total_amount'       => $totalAmount,
                    ':profit'             => $profit,
                    ':cagr'               => $cagr,
                    ':xirr'               => $xirr,
                    ':absolute_return'    => $absoluteReturn,
                    ':total_goal_current' => $totalGoalCurrent,
                    ':total_goal_target'  => $totalGoalTarget,
                    ':total_sip'          => $totalSip,
                    ':greeting_prefix'    => $DEFAULT_GREETING,
                    ':intro_text'         => $DEFAULT_INTRO,
                    ':closing_text'       => $DEFAULT_CLOSING,
                    ':rationale_text'     => $DEFAULT_RATIONALE,
                    ':created_by'         => $userId,
                    ':report_state'       => 'draft',
                ]);

                $clientId = (int)$pdo->lastInsertId();

                if ($firstClientId === 0) { // Track the first generated ID
                    $firstClientId = $clientId;
                }
            }

            $savedCount++;

          foreach ($goals as $g) {
                // --- CALCULATE INITIAL STATUS (Projected vs Target) ---
                $projectedVal = (float)($g['projected'] ?? 0);
                $targetVal    = (float)($g['target_amount'] ?? 0);

                // If projected value is below target, flag as Invest More; else On Track
                $calculatedStatus = ($projectedVal < $targetVal) ? 'Invest More' : 'On Track';
                // ------------------------------------------

                $stmtGoal->execute([
                    ':client_id'      => $clientId,
                    ':goal'           => $g['goal']          ?? '',
                    ':goal_date'      => $g['goal_date']     ?? '',
                    ':current_amount' => $g['current_value'] ?? 0,
                    ':sip_swp'        => $g['running_sip']   ?? 0,
                    ':target_amount'  => $targetVal,
                    ':projected'      => $projectedVal,
                    ':shortfall'      => $g['shortfall']     ?? 0,
                    ':completion'     => $g['completion']    ?? 0,
                    ':status'         => $calculatedStatus, // Use projected vs target status
                ]);
            }

            // ----- SAVE ALLOCATION -----
            foreach ($allocation as $asset => $share) {
                $stmtAlloc->execute([
                    ':client_id' => $clientId,
                    ':asset'     => $asset,
                    ':share_pct' => $share,
                ]);
            }

            // ----- SAVE SCHEMES -----
            foreach ($schemes as $s) {
                $stmtScheme->execute([
                    ':client_id'          => $clientId,
                    ':scheme_name'        => $s['scheme']        ?? '',
                    ':sip_swp'            => $s['sip_swp']       ?? 0,
                    ':current_value'      => $s['current_value'] ?? 0,
                    ':action_step'        => 'Continue',
                    ':recommended_scheme' => null,
                    ':recommended_amount' => 0,
                ]);
            }

            // ----- SAVE ANNEXURES -----
            foreach ($annexureLinesForClient as $line) {
                $line = trim($line);
                if ($line === '') continue;
                $stmtAnnex->execute([
                    ':client_id' => $clientId,
                    ':line_text' => $line,
                ]);
            }

            // --- MOVE ATTACHMENTS TO CLIENT SPECIFIC DIRECTORY ---
            $clientAttachmentsDir = __DIR__ . '/uploads/attachments/client_' . $clientId;
            if (!is_dir($clientAttachmentsDir)) {
                mkdir($clientAttachmentsDir, 0777, true);
            }

            // Move each PDF file to the client-specific directory
            foreach ($pdfFiles as $pdfInfo) {
                if (is_array($pdfInfo)) {
                    $originalPath = $pdfInfo['path'];
                    $fileName = basename($originalPath);

                    // Keep GoalStatusReport PDFs in the general uploads folder (not under attachments)
                    if (stripos($fileName, 'GoalStatusReport') !== false) {
                        continue;
                    }

                    $newPath = $clientAttachmentsDir . '/' . $fileName;

                    // Rename the file to avoid conflicts, if needed
                    $counter = 1;
                    while (file_exists($newPath)) {
                        $newPath = $clientAttachmentsDir . '/' . $counter++ . '_' . $fileName;
                    }

                    // Move the file
                    rename($originalPath, $newPath);
                }
            }
        }
        
        // NEW REDIRECT: Redirect to the first generated report for editing
        if ($firstClientId > 0) {
            header('Location: view_report.php?id=' . $firstClientId . '&initial_save=1');
            exit;
        } 
        
        // --- ADDED FALLBACK FIX --- 
        // If files were processed but no clients were found/saved ($firstClientId == 0), 
        // redirect to the list of saved reports.
        header('Location: view_saved_reports.php?status=no_new_clients');
        exit;
        // ------------------

    } catch (Throwable $e) {
        // ... (Error handling block remains the same) ...
        ?>
        <!DOCTYPE html>
        <html>
        <head>
            <title>Error - Client Reports</title>
            <style>
                body { font-family: Arial, sans-serif; margin: 20px; }
                .flash-error { padding: 10px; border-radius: 4px; background: #ffe6e6; border: 1px solid #b30000; }
                .nav-button {
                    display: inline-block;
                    margin-top: 10px;
                    padding: 6px 12px;
                    background-color: #0056b3;
                    color: #fff;
                    border-radius: 4px;
                    text-decoration: none;
                    font-size: 13px;
                }
            </style>
        </head>
        <body>
        <div class="flash-error">
            <strong>Unexpected error:</strong><br>
            <?php echo htmlspecialchars($e->getMessage()); ?>
        </div>
        <a href="upload.php" class="nav-button">Back to Upload</a>
        </body>
        </html>
        <?php
        exit;
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !isset($_POST['ajax'])) {
    $stats = fetchDashboardStats($pdo);
}

/* ---------- INITIAL UPLOAD PAGE (GET) ---------- */
?>
<?php 
$navUser = $_SESSION['username'] ?? ($currentUser['username'] ?? 'User');
$currentPage = basename($_SERVER['PHP_SELF']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Command Center</title>
    <link rel="stylesheet" href="public/css/styles.css">
    <style>
        /* Navigation */
        body { margin: 0; background: #f4f6fb; font-family: 'Inter', sans-serif; color: #0f172a; }
        .navbar { background: #fff; border-bottom: 1px solid #e5e7eb; padding: 15px 30px; display: flex; align-items: center; justify-content: space-between; box-shadow: 0 2px 6px rgba(15,23,42,0.08); }
        .nav-left { display: flex; align-items: center; gap: 28px; }
        .nav-brand { font-size: 1.2rem; font-weight: 700; color: #1e293b; text-decoration: none; letter-spacing: 0.01em; }
        .nav-links a { margin-right: 18px; text-decoration: none; font-weight: 600; color: #5b6475; padding-bottom: 3px; border-bottom: 2px solid transparent; }
        .nav-links a.active { color: #1565c0; border-color: #1565c0; }
        .nav-links a:last-child { margin-right: 0; }
        .nav-user { display: flex; align-items: center; gap: 12px; font-size: 0.95rem; color: #475569; }
        .btn-logout { text-decoration: none; padding: 8px 14px; background: #ffebee; color: #c62828; border-radius: 8px; font-weight: 700; font-size: 0.85rem; }
        .btn-logout:hover { background: #ffcdd2; }

        .wrap { max-width: 1200px; margin: 0 auto; padding: 26px 20px 60px; }
        h1 { margin: 8px 0 10px; font-family: 'Poppins', sans-serif; font-size: 30px; color: #0f172a; }
        p.lead { margin: 0 0 24px; color: #6b7280; font-size: 15px; }

        /* My stats cards (5-col) */
        .row { width: 100%; display: flex; flex-wrap: wrap; margin: 0 -10px; }
        .col-md-20p { width: 20%; padding: 0 10px; box-sizing: border-box; }
        .stats-card { display: block; padding: 20px; border-radius: 8px; text-decoration: none; color: #333; transition: transform 0.2s, box-shadow 0.2s; box-shadow: 0 2px 5px rgba(0,0,0,0.05); background: white; border-left: 4px solid #ccc; }
        .stats-card:hover { transform: translateY(-3px); box-shadow: 0 5px 15px rgba(0,0,0,0.1); }
        .stats-card .number { font-size: 24px; font-weight: 700; margin-top: 5px; }
        .stats-card .label { font-size: 12px; text-transform: uppercase; color: #777; font-weight: 600; letter-spacing: 0.12em; }
        .card-blue { border-left-color: #1565c0; }
        .card-grey { border-left-color: #78909c; }
        .card-yellow { border-left-color: #ffb300; }
        .card-teal { border-left-color: #00897b; }
        .card-green { border-left-color: #43a047; }

        /* Team table */
        .section { background: #fff; border: 1px solid #e5e7eb; border-radius: 16px; box-shadow: 0 12px 30px rgba(15,23,42,0.06); padding: 20px; margin-top: 20px; }
        .section h2 { margin: 0 0 14px; font-family: 'Poppins', sans-serif; font-size: 22px; color: #0f172a; }
        table { width: 100%; border-collapse: collapse; }
        th, td { padding: 12px 10px; text-align: left; font-size: 14px; }
        th { background: #f8fafc; color: #6b7280; font-weight: 700; border-bottom: 1px solid #e5e7eb; }
        tr + tr td { border-top: 1px solid #e5e7eb; }
        .progress { width: 100%; height: 12px; background: #e5e7eb; border-radius: 999px; overflow: hidden; }
        .progress-bar { height: 100%; background: linear-gradient(135deg, #4ade80, #16a34a); }
        .badge { display: inline-block; padding: 6px 10px; border-radius: 999px; font-size: 12px; font-weight: 700; border: 1px solid transparent; }
        .badge-red { background: #fee2e2; color: #b91c1c; border-color: #fecaca; }
        .badge-ghost { background: #eef2ff; color: #4338ca; border-color: #c7d2fe; }

        /* Action center */
        .action-card { border: 2px dashed #b0c4de; background: #f8fbff; border-radius: 14px; box-shadow: 0 8px 18px rgba(15,23,42,0.05); padding: 26px; margin-top: 26px; }
        .action-card h3 { margin: 0 0 10px; font-family: 'Poppins', sans-serif; color: #1565c0; }
        .action-card label { display: block; margin-bottom: 8px; font-size: 12px; font-weight: 700; color: #0f172a; }
        .action-card input[type="file"] { width: 100%; padding: 10px; border: 1px solid #cbd5e1; border-radius: 8px; }
        .action-card button { padding: 12px 32px; background: linear-gradient(135deg, #4FC3F7 0%, #29B6F6 100%); color: #fff; border: none; border-radius: 10px; font-weight: 700; cursor: pointer; box-shadow: 0 8px 20px rgba(41,182,246,0.28); }
        .action-card button:hover { transform: translateY(-1px); }
    </style>
</head>
<body>
    <nav class="navbar">
        <div class="nav-left">
            <a href="upload.php" class="nav-brand">Finance Doctor</a>
            <div class="nav-links">
                <a href="upload.php" class="<?php echo $currentPage === 'upload.php' ? 'active' : ''; ?>">Dashboard</a>
                <a href="view_saved_reports.php" class="<?php echo $currentPage === 'view_saved_reports.php' ? 'active' : ''; ?>">All Reports</a>
                <a href="bulk_import.php" class="<?php echo $currentPage === 'bulk_import.php' ? 'active' : ''; ?>">Bulk Allocate</a>
            </div>
        </div>
        <div class="nav-user">
            <span>👤 <?php echo htmlspecialchars($navUser); ?></span>
            <a href="logout.php" class="btn-logout">Logout</a>
        </div>
    </nav>

    <div class="wrap">
        <h1>Command Center</h1>
        <p class="lead">Your workload, team performance, and uploads — all in one view.</p>

        <div class="row" style="margin-bottom:28px;">
            <div class="col-md-20p">
                <a href="view_saved_reports.php?owner_filter=mine" class="stats-card card-blue">
                    <div class="label">Total Assigned</div>
                    <div class="number"><?php echo (int)$myStats['total']; ?></div>
                </a>
            </div>

            <div class="col-md-20p">
                <a href="view_saved_reports.php?owner_filter=mine&filter=draft" class="stats-card card-grey">
                    <div class="label">Drafts</div>
                    <div class="number"><?php echo (int)$myStats['count_draft']; ?></div>
                </a>
            </div>

            <div class="col-md-20p">
                <a href="view_saved_reports.php?owner_filter=mine&filter=ready" class="stats-card card-yellow">
                    <div class="label">Ready for Review</div>
                    <div class="number"><?php echo (int)$myStats['count_ready']; ?></div>
                </a>
            </div>

            <div class="col-md-20p">
                <a href="view_saved_reports.php?owner_filter=mine&filter=reviewed" class="stats-card card-teal">
                    <div class="label">Reviewed</div>
                    <div class="number"><?php echo (int)$myStats['count_reviewed']; ?></div>
                </a>
            </div>

            <div class="col-md-20p">
                <a href="view_saved_reports.php?owner_filter=mine&filter=sent" class="stats-card card-green">
                    <div class="label">Emails Sent</div>
                    <div class="number"><?php echo (int)$myStats['count_sent']; ?></div>
                </a>
            </div>
        </div>

        <div class="section">
            <h2>Team Performance</h2>
            <table>
                <tr>
                    <th style="width: 28%;">Employee</th>
                    <th style="width: 32%;">Progress</th>
                    <th style="width: 14%;">Pending</th>
                    <th style="width: 14%;">Total</th>
                    <th style="width: 12%;">Priority</th>
                </tr>
                <?php foreach ($teamStats as $row):
                    $total = (int)($row['total_assigned'] ?? 0);
                    $sent = (int)($row['sent_count'] ?? 0);
                    $pending = (int)($row['pending_count'] ?? 0);
                    $hi = (int)($row['high_priority'] ?? 0);
                    $pct = safePercent($sent, max($total, $pending + $sent));
                ?>
                <tr>
                    <td>
                        <a href="view_saved_reports.php?owner_filter=<?php echo (int)$row['id']; ?>" style="font-weight: 600; color: #333; text-decoration: none; display: inline-flex; align-items: center;">
                            <div style="width: 30px; height: 30px; background: #e3f2fd; color: #1565c0; border-radius: 50%; display: inline-flex; align-items: center; justify-content: center; font-size: 12px; margin-right: 8px;">
                                <?php echo strtoupper(substr($row['username'], 0, 1)); ?>
                            </div>
                            <div>
                                <div style="font-weight:700; color:#0f172a;">&nbsp;<?php echo htmlspecialchars($row['username']); ?></div>
                                <div style="color:#6b7280; font-size:12px;">&nbsp;<?php echo htmlspecialchars($row['designation'] ?? ''); ?></div>
                            </div>
                        </a>
                    </td>
                    <td>
                        <div class="progress" aria-label="Completion">
                            <div class="progress-bar" style="width: <?php echo $pct; ?>%;"></div>
                        </div>
                        <div style="font-size:12px; color:#6b7280; margin-top:6px;"><?php echo $pct; ?>% sent</div>
                    </td>
                    <td style="text-align: center;">
                        <?php if ($pending > 0): ?>
                            <a href="view_saved_reports.php?owner_filter=<?php echo (int)$row['id']; ?>&filter=pending" style="color: #d32f2f; font-weight: bold; text-decoration: underline;">
                                <?php echo $pending; ?>
                            </a>
                        <?php else: ?>
                            <span style="color: #ccc;">0</span>
                        <?php endif; ?>
                    </td>
                    <td><?php echo $total; ?></td>
                    <td>
                        <?php if ($hi > 0): ?>
                            <span class="badge badge-red"><?php echo $hi; ?> High</span>
                        <?php else: ?>
                            <span class="badge badge-ghost">Clear</span>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
            </table>
        </div>

        <div class="section" style="margin-top: 32px;">
            <h2>Action Center</h2>
            <div class="action-card">
                <div style="text-align:center; margin-bottom: 16px;">
                    <h3>Upload New Client Data</h3>
                    <p style="color:#6b7280; margin:0; font-size:14px;">Attach Excel and PDF files to generate client reports.</p>
                </div>
                <form method="post" enctype="multipart/form-data" style="max-width: 760px; margin: 0 auto;">
                    <div style="display:flex; gap:16px; flex-wrap: wrap;">
                        <div style="flex:1; min-width:260px;">
                            <label for="client_files">Select Excel &amp; PDF files (multiple allowed)</label>
                            <input type="file" name="client_files[]" id="client_files" multiple required>
                        </div>
                    </div>
                    <div style="margin-top: 18px; text-align:center;">
                        <button type="submit">Upload &amp; Generate Reports</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</body>
</html>