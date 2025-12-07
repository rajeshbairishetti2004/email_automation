<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);
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
                (name, as_on, total_amount, profit, cagr, xirr,
                 total_goal_current, total_goal_target, total_sip,
                 greeting_prefix, intro_text, closing_text, rationale_text,
                 is_older_than_1_year, absolute_return)
            VALUES
                (:name, :as_on, :total_amount, :profit, :cagr, :xirr,
                 :total_goal_current, :total_goal_target, :total_sip,
                 :greeting_prefix, :intro_text, :closing_text, :rationale_text,
                 :is_older_than_1_year, :absolute_return)
        ");

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
            <body>
            <div class='flash flash-error'>No client data could be extracted. Please check that the correct files were uploaded.</div>
            <a href="upload.php" class="nav-button">Back to Upload</a>
            </body>
            </html>
            <?php
            exit;
        }

        foreach ($allClientReports as $client => $data) {
            if (!empty($validSet) && !isset($validSet[$data['name']])) continue;

            $name       = $data['name'];
            $asOn       = $data['as_on'] ?? '';
            $allocation = $data['allocation'] ?? [];
            $schemes    = $data['schemes'] ?? [];
            $goals      = $data['goals'] ?? [];

            $totals  = $data['current']['totals'] ?? [
                'purchase'      => 0,
                'current'       => 0,
                'profit'        => 0,
                'cagr_weighted' => 0,
                'xirr_weighted' => 0
            ];
            $summary = $data['current']['summary'] ?? null;

            $totalAmount = $totals['current'];
            $profit      = $summary['profit'] ?? $totals['profit'];
            $cagr        = $totals['cagr_weighted'];
            $xirr        = $summary['xirr'] ?? $totals['xirr_weighted'];
            $absoluteReturn = isset($data['absolute_return']) ? $data['absolute_return'] : null;

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

            // --- DUPLICATE CLEANUP: remove existing rows for same name + as_on ---
            if ($asOn !== '') {
                $delStmt = $pdo->prepare("DELETE FROM clients WHERE name = :name AND as_on = :as_on");
                $delStmt->execute([':name' => $name, ':as_on' => $asOn]);
            }

            // ----- SAVE MASTER ROW -----
            $stmtClient->execute([
                ':name'               => $name,
                ':as_on'              => $asOn,
                ':total_amount'       => $totalAmount,
                ':profit'             => $profit,
                ':cagr'               => $cagr,
                ':xirr'               => $xirr,
                ':total_goal_current' => $totalGoalCurrent,
                ':total_goal_target'  => $totalGoalTarget,
                ':total_sip'          => $totalSip,
                ':greeting_prefix'    => $DEFAULT_GREETING,
                ':intro_text'         => $DEFAULT_INTRO,
                ':closing_text'       => $DEFAULT_CLOSING,
                ':rationale_text'     => $DEFAULT_RATIONALE,
                ':is_older_than_1_year' => (isset($_POST['portfolio_older_than_1_year']) && $_POST['portfolio_older_than_1_year'] === 'no') ? 0 : 1,
                ':absolute_return'      => $absoluteReturn,
            ]);

            $clientId = (int)$pdo->lastInsertId();
            
            if ($firstClientId === 0) { // Track the first generated ID
                $firstClientId = $clientId;
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

/* ---------- INITIAL UPLOAD PAGE (GET) ---------- */
?>
<!DOCTYPE html>
<html>
<head>
    <title>Upload Client Files</title>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;600;700&family=Inter:wght@400;500;600&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="public/css/styles.css">
    <link rel="stylesheet" href="public/css/upload.css">
</head>
<body>

<div class="full-width-header-bar">
    <header class="header">
        <div class="header-left">
            <img src="image.png" alt="Company Logo">
            <span class="greeting">Hi <?= $displayName ?>!</span>
        </div>
        
        <div class="header-right">
            <div class="profile-pic" onclick="toggleDropdown()">
                <?= $initials ?>
            </div>

            <div id="profileDropdown" class="profile-dropdown">
                <a href="logout.php" class="logout-link">Logout</a>
            </div>
        </div>
    </header>
</div>

<div class="main-content">
    
    <div class="nav-bar">
        <a href="upload.php" class="nav-button">Upload New Files</a>
        <a href="view_saved_reports.php" class="nav-button">View Saved Reports</a>
    </div>

    <h1>Upload Client Data Files</h1>

    <div class="form-section">
        <form method="post" enctype="multipart/form-data">
            <label for="client_files">Select Excel &amp; PDF files (multiple allowed):</label>
            <input type="file" name="client_files[]" id="client_files" multiple required>
            
            <!-- Radio just above the "Create Reports" button -->
            <div class="form-group">
                <label>Is client portfolio older than 1 year?</label>
                <div style="display: flex; gap: 24px; align-items: center; margin-top: 8px;">
                    <input type="radio" id="portfolio_yes" name="portfolio_older_than_1_year" value="yes" checked style="width:22px; height:22px; accent-color:#0288D1;">
                    <label for="portfolio_yes" style="font-size: 16px; margin-right: 10px;">Yes</label>
                    <input type="radio" id="portfolio_no" name="portfolio_older_than_1_year" value="no" style="width:22px; height:22px; accent-color:#0288D1;">
                    <label for="portfolio_no" style="font-size: 16px;">No</label>
                </div>
            </div>

            <button type="submit">Create Reports</button>
        </form>
    </div>
</div>

<script>
    function toggleDropdown() {
        const dropdown = document.getElementById('profileDropdown');
        const isVisible = dropdown.style.display === 'block';
        
        // Hide all open dropdowns first (if any)
        document.querySelectorAll('.profile-dropdown').forEach(d => {
            d.style.display = 'none';
        });

        // Toggle visibility of the current dropdown
        if (!isVisible) {
            dropdown.style.display = 'block';
        }
    }

    // Close the dropdown if the user clicks anywhere outside of it
    document.addEventListener('click', function(event) {
        const profilePic = document.querySelector('.profile-pic');
        const dropdown = document.getElementById('profileDropdown');

        if (profilePic && dropdown) {
            const isClickInsidePic = profilePic.contains(event.target);
            const isClickInsideDropdown = dropdown.contains(event.target);

            if (!isClickInsidePic && !isClickInsideDropdown) {
                dropdown.style.display = 'none';
            }
        }
    });
</script>

</body>
</html>