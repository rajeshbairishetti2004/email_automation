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
                 greeting_prefix, intro_text, closing_text, rationale_text)
            VALUES
                (:name, :as_on, :total_amount, :profit, :cagr, :xirr,
                 :total_goal_current, :total_goal_target, :total_sip,
                 :greeting_prefix, :intro_text, :closing_text, :rationale_text)
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
            ]);

            $clientId = (int)$pdo->lastInsertId();
            
            if ($firstClientId === 0) { // Track the first generated ID
                $firstClientId = $clientId;
            }

            $savedCount++;

          foreach ($goals as $g) {
                // --- CALCULATE INITIAL STATUS (Formula) ---
                $shortfallVal = (float)($g['shortfall'] ?? 0);
                $targetVal = (float)($g['target_amount'] ?? 0);
                $threshold = $targetVal * 0.01;

                // Default logic: If shortfall > 1% of target, it's Invest More. Otherwise On Track.
                $calculatedStatus = ($shortfallVal > 0 && $shortfallVal > $threshold) ? 'Invest More' : 'On Track';
                // ------------------------------------------

                $stmtGoal->execute([
                    ':client_id'      => $clientId,
                    ':goal'           => $g['goal']          ?? '',
                    ':goal_date'      => $g['goal_date']     ?? '',
                    ':current_amount' => $g['current_value'] ?? 0,
                    ':sip_swp'        => $g['running_sip']   ?? 0,
                    ':target_amount'  => $g['target_amount'] ?? 0,
                    ':projected'      => $g['projected']     ?? 0,
                    ':shortfall'      => $g['shortfall']     ?? 0,
                    ':completion'     => $g['completion']    ?? 0,
                    ':status'         => $calculatedStatus, // Use our calculated status
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
    
    <style>
        /* General layout adjustments */
        body { 
            margin: 0; 
            padding: 0; 
            background-color: #f7f9fb;
            font-family: 'Inter', sans-serif;
        }

        /* --- FULL WIDTH HEADER BAR --- */
        .full-width-header-bar {
            width: 100%;
            background-color: white; 
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.05);
        }

        /* Header content constrained to max-width */
        .header {
            max-width: 1200px; 
            margin: 0 auto;
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 15px 20px; 
        }
        
        .header-left {
            display: flex;
            align-items: center;
        }
        .header-left img {
            width: 50px; 
            height: 50px;
            margin-right: 15px;
            object-fit: contain;
        }
        .header-left .greeting {
            font-size: 24px; 
            font-weight: 700; 
            color: #0288D1; 
            font-family: 'Poppins', sans-serif;
        }

        /* Profile/Logout section styles */
        .header-right {
            position: relative; /* Essential for positioning the dropdown */
            display: flex;
            align-items: center;
        }
        
        .profile-pic {
            width: 38px;
            height: 38px;
            border-radius: 50%;
            background-color: #4FC3F7; 
            display: flex;
            justify-content: center;
            align-items: center;
            color: white;
            font-weight: bold;
            font-size: 16px;
            border: 2px solid #0288D1;
            cursor: pointer; 
            z-index: 20; /* Ensure it stays on top */
        }

        /* Dropdown container */
        .profile-dropdown {
            position: absolute;
            top: 100%; /* Position below the profile icon */
            right: 0;
            margin-top: 10px; /* Space below icon */
            width: auto;
            min-width: 120px;
            background: white;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.1);
            border-radius: 6px;
            overflow: hidden;
            display: none; /* Initially hidden */
            z-index: 10;
        }

        /* Dropdown link styling */
        .profile-dropdown a {
            padding: 10px 15px;
            text-decoration: none;
            display: block;
            text-align: right;
            font-size: 15px;
            color: #333;
            transition: background-color 0.1s;
        }
        .profile-dropdown a:hover {
            background-color: #f0f0f0;
        }

        /* Specific style for Logout link (Red Text) */
        .profile-dropdown a.logout-link {
            color: #F44336; /* Red text for logout */
            font-weight: 600;
        }
        
        /* --- MAIN PAGE CONTENT --- */
        .main-content {
            max-width: 800px;
            margin: 20px auto 40px auto; 
            padding: 0 20px; 
            text-align: center; 
        }

        h1 {
            color: #0288D1;
            font-family: 'Poppins', sans-serif;
            font-size: 24px;
            margin-top: 20px;
            margin-bottom: 30px;
        }
        
        .nav-bar {
            margin-bottom: 30px;
            display: flex;
            justify-content: center; 
            gap: 10px;
        }
        .nav-button {
            display: inline-block;
            padding: 10px 20px;
            background-color: #0288D1;
            color: #fff;
            border-radius: 8px;
            text-decoration: none;
            font-size: 15px; 
            font-weight: 600;
            transition: background-color 0.2s;
        }
        .nav-button:hover {
            background-color: #01579B;
        }
        
        label {
            display: block;
            margin-top: 20px;
            font-weight: 600;
            color: #0288D1;
            font-size: 14px;
            text-align: left; 
        }
        
        input[type="file"],
        input[type="text"],
        textarea {
            width: 100%;
            padding: 12px;
            border: 2px solid #E3F2FD;
            border-radius: 8px;
            font-family: 'Inter', sans-serif;
            margin-top: 8px;
            font-size: 14px;
        }
        
        button[type="submit"] {
            margin-top: 25px;
            padding: 12px 30px;
            background: linear-gradient(135deg, #4FC3F7 0%, #29B6F6 100%);
            color: white;
            border: none;
            border-radius: 8px;
            font-size: 16px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
            box-shadow: 0 4px 12px rgba(41, 182, 246, 0.3);
        }
        
        .form-section {
            background: white;
            padding: 30px;
            border-radius: 12px;
            box-shadow: 0 4px 24px rgba(0, 0, 0, 0.06);
            margin-bottom: 20px;
            text-align: left; 
        }
    </style>
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