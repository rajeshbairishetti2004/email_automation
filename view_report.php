<?php
// view_report.php
// - Shows single report with next/prev navigation, print button
// - Inline editable fields with AJAX -> DB
// - Send current client report by email using PHPMailer

require_once 'auth.php'; // FIX: Using auth.php instead of login.php
require_once 'db_config.php';
require_once 'email_handler.php';
require_once 'renderers.php';
require_once 'env_loader.php'; // Add this line to load environment variables

// --- IMPORTANT: Include the AJAX handling logic ---
// This file contains the logic for 'load_template', 'delete_template', 'load_rm', 'delete_rm', etc.,
// and will exit immediately if an AJAX POST request is detected.
require_once 'template_actions.php'; 


requireAuth();

$pdo = getPdo();
$clientId = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if ($clientId <= 0) {
    header('Location: view_saved_reports.php');
    exit;
}

// Fetch current user details for the header and defaults
$currentUser = getCurrentUser();

// Determine the correct name and initials for the header
$displayName = 'User';
$nameForInitials = 'U';

if ($currentUser) {
    if (!empty($currentUser['name'])) {
        $displayName = htmlspecialchars($currentUser['name']);
        $nameForInitials = $currentUser['name'];
    } elseif (!empty($currentUser['username'])) {
        $displayName = htmlspecialchars($currentUser['username']);
        $nameForInitials = $currentUser['username'];
    }
}

$initials = strtoupper(substr($nameForInitials, 0, 1));


/* ---------- DATABASE HELPER FUNCTIONS (Local Definitions) ---------- */
// ... (getClientById, getClientGoals, getClientAllocations, etc. remain the same) ...
function getClientById($clientId) {
    $pdo = getPdo();
    $stmt = $pdo->prepare("SELECT * FROM clients WHERE id = :id");
    $stmt->execute([':id' => $clientId]);
    return $stmt->fetch(PDO::FETCH_ASSOC);
}

function getClientGoals($clientId) {
    $pdo = getPdo();
    $stmt = $pdo->prepare("SELECT * FROM client_goals WHERE client_id = :id ORDER BY id ASC");
    $stmt->execute([':id' => $clientId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function getClientAllocations($clientId) {
    $pdo = getPdo();
    $stmt = $pdo->prepare("SELECT * FROM client_allocations WHERE client_id = :id ORDER BY id ASC");
    $stmt->execute([':id' => $clientId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function getClientSchemes($clientId) {
    $pdo = getPdo();
    $stmt = $pdo->prepare("SELECT * FROM client_schemes WHERE client_id = :id ORDER BY id ASC");
    $stmt->execute([':id' => $clientId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function getClientAnnexures($clientId) {
    $pdo = getPdo();
    $stmt = $pdo->prepare("SELECT * FROM client_annexures WHERE client_id = :id ORDER BY id ASC");
    $stmt->execute([':id' => $clientId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function getPrevClientId($clientId) {
    $pdo = getPdo();
    $stmt = $pdo->prepare("SELECT id FROM clients WHERE id < :id ORDER BY id DESC LIMIT 1");
    $stmt->execute([':id' => $clientId]);
    return $stmt->fetchColumn();
}

function getNextClientId($clientId) {
    $pdo = getPdo();
    $stmt = $pdo->prepare("SELECT id FROM clients WHERE id > :id ORDER BY id ASC LIMIT 1");
    $stmt->execute([':id' => $clientId]);
    return $stmt->fetchColumn();
}
// --------------------------------------------------------------------------


/* ---------- HANDLE POST REQUESTS (Non-AJAX, Redirect Only) ---------- */
// NOTE: All AJAX handlers are in template_actions.php

/* ---------- HANDLE ADD NEW RM REQUEST (Existing) ---------- */

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action_add_rm'])) {
    try {
        $name        = trim($_POST['rm_name'] ?? '');
        $designation = trim($_POST['rm_designation'] ?? 'Relationship Manager');
        $mobile      = trim($_POST['rm_mobile'] ?? '');
        $email       = trim($_POST['rm_email'] ?? '');
        
        $rmCount = getRelationshipManagerCount();
        $is_default  = ($rmCount == 0); // Force first RM to be default

        if (empty($name) || empty($email) || empty($mobile)) {
            throw new Exception("Name, Email, and Mobile are required.");
        }

        $newId = addNewRelationshipManager($name, $designation, $mobile, $email, $is_default);

        // Redirect back to the report view with success message
        header('Location: view_report.php?id=' . $clientId . '&rm_added=1');
        exit;

    } catch (Exception $e) {
        header('Location: view_report.php?id=' . $clientId . '&rm_add_error=' . urlencode($e->getMessage()));
        exit;
    }
}

/* ---------- HANDLE ADD TEMPLATE REQUEST (NEW) ---------- */

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action_add_template'])) {
    try {
        $name         = trim($_POST['template_name'] ?? '');
        $section_type = trim($_POST['template_section'] ?? '');
        $content      = trim($_POST['template_content'] ?? '');
        // NEW: Check for an ID to update
        $template_id_to_update = (int)($_POST['template_id_to_update'] ?? 0); 
        
        if (empty($name) || empty($section_type) || empty($content)) {
            throw new Exception("Name, Section, and Content are required.");
        }
        
        if (!in_array($section_type, ['greeting', 'intro', 'closing', 'rationale'])) {
             throw new Exception("Invalid section type provided.");
        }

        $pdo = getPdo();
        
        if ($template_id_to_update > 0) {
            // FIX: PERFORM UPDATE (Editing existing template)
            $stmt = $pdo->prepare("
                UPDATE report_templates 
                SET name = :name, content = :content 
                WHERE id = :id AND section_type = :section_type
            ");
            $stmt->execute([
                ':name' => $name,
                ':content' => $content,
                ':id' => $template_id_to_update,
                ':section_type' => $section_type
            ]);
            $newId = $template_id_to_update;
        } else {
            // PERFORM INSERT (Adding new template)
            $stmt = $pdo->prepare("
                INSERT INTO report_templates (name, section_type, content)
                VALUES (:name, :section_type, :content)
            ");
            $stmt->execute([
                ':name' => $name,
                ':section_type' => $section_type,
                ':content' => $content,
            ]);
            $newId = (int)$pdo->lastInsertId();
        }

        // Redirect back to the report view with success message
        header('Location: view_report.php?id=' . $clientId . '&template_added=1&section=' . $section_type);
        exit;

    } catch (Exception $e) {
        header('Location: view_report.php?id=' . $clientId . '&template_add_error=' . urlencode($e->getMessage()));
        exit;
    }
}


/* ---------- HANDLE "SEND EMAIL" REQUEST (Existing) ---------- */

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['send_email']) && $_POST['send_email'] == '1') {
    handleEmailSending($clientId);
    exit;
}

/* ---------- HANDLE "SAVE REPORT" REQUEST (Existing) ---------- */

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_report'])) {
    $pdo = getPdo();
    $clientId = (int)($_POST['client_id'] ?? 0);

    if ($clientId > 0) {
        try {
            // Get all text fields from POST
            $clientMessage = trim($_POST['client_message'] ?? '');
            $rationale = trim($_POST['rationale'] ?? '');
            $signatureBlock = trim($_POST['signature_block'] ?? '');

            // Parse client message into greeting, intro, closing (using the same logic as AJAX)
            $lines = explode("\n\n", $clientMessage);
            $greeting = isset($lines[0]) ? trim($lines[0]) : '';
            $closing = (count($lines) > 1 && $lines[count($lines) - 1] !== $greeting) ? trim($lines[count($lines) - 1]) : '';
            $introParts = array_slice($lines, 1, count($lines) - (empty($closing) ? 1 : 2));
            $intro = !empty($introParts) ? implode("\n\n", $introParts) : '';
            
            if (strpos(strtolower($greeting), 'dear') === false && strpos($greeting, ',') === false) {
                $intro = $clientMessage;
                $greeting = $closing = '';
            }

            // Update client text fields
            $stmt = $pdo->prepare("
                UPDATE clients 
                SET greeting_prefix = :greeting,
                    intro_text = :intro,
                    closing_text = :closing,
                    rationale_text = :rationale,
                    signature_block = :signature
                WHERE id = :id
            ");
            $stmt->execute([
                ':greeting' => $greeting,
                ':intro' => $intro,
                ':closing' => $closing,
                ':rationale' => $rationale,
                ':signature' => $signatureBlock,
                ':id' => $clientId,
            ]);

            // Save scheme recommendations if provided
            if (isset($_POST['recommended_scheme']) && is_array($_POST['recommended_scheme'])) {
                foreach ($_POST['recommended_scheme'] as $schemeId => $schemeName) {
                    
                    // FIX 1: Validate scheme ID is a positive integer
                    $schemeId = (int)$schemeId;
                    if ($schemeId <= 0) {
                        error_log("Skipping scheme save (recommended_scheme): Invalid scheme ID received in POST: " . $schemeId);
                        continue; 
                    }
                    
                    // FIX 2: Ensure the value is explicitly cast to float
                    $amount = (float)($_POST['recommended_amount'][$schemeId] ?? 0);
                    
                    $stmt = $pdo->prepare("
                        UPDATE client_schemes 
                        SET recommended_scheme = :scheme,
                            recommended_amount = :amount
                        WHERE id = :id
                    ");
                    $stmt->execute([
                        ':scheme' => trim($schemeName),
                        ':amount' => $amount,
                        ':id' => $schemeId,
                    ]);
                }
            }

            // Save action steps if provided
            if (isset($_POST['action_step']) && is_array($_POST['action_step'])) {
                foreach ($_POST['action_step'] as $schemeId => $actionStep) {
                    
                    // FIX 3: Validate scheme ID is a positive integer
                    $schemeId = (int)$schemeId;
                    if ($schemeId <= 0) {
                        error_log("Skipping action step save: Invalid scheme ID received in POST: " . $schemeId);
                        continue; 
                    }
                    
                    $stmt = $pdo->prepare("
                        UPDATE client_schemes 
                        SET action_step = :action_step
                        WHERE id = :id
                    ");
                    $stmt->execute([
                        ':action_step' => $actionStep,
                        ':id' => $schemeId,
                    ]);
                }
            }

            header('Location: view_report.php?id=' . $clientId . '&saved=1');
            exit;
        } catch (Exception $e) {
            error_log("Report Save Error for client ID " . $clientId . ": " . $e->getMessage());
            header('Location: view_report.php?id=' . $clientId . '&save_error=1');
            exit;
        }
    }
}

/* ---------- LOAD AND DISPLAY CLIENT REPORT (View Logic) ---------- */

// Load client data
$client = getClientById($clientId);
if (!$client) {
    ?>
    <!DOCTYPE html>
    <html>
    <head>
        <title>Report Not Found</title>
        <style>
            body { font-family: Arial, sans-serif; margin: 20px; }
            a { text-decoration: none; color: #0056b3; }
        </style>
    </head>
    <body>
        <p>No report found for ID <?php echo htmlspecialchars((string)$clientId); ?>.</p>
        <p><a href="view_saved_reports.php">&larr; Back to list</a></p>
    </body>
    </html>
    <?php
    exit;
}

// Get RM data (used for template lists and fallback signature)
$rm = getDefaultRelationshipManager();

// FIX: Override RM defaults with LOGGED-IN USER details
if ($currentUser) {
    // If the logged-in user is not in the RM table, we construct a virtual RM profile from their user data
    $rmName        = $currentUser['name'] ?? $currentUser['username'] ?? 'Relationship Manager';
    $rmDesignation = $currentUser['designation'] ?? 'System User';
    $rmMobile      = $currentUser['mobile'] ?? 'N/A';
    $rmEmail       = $currentUser['email'] ?? 'N/A';
} else {
    // Fallback to default RM from DB if no user logged in (shouldn't happen due to requireAuth)
    $rmName        = $rm['name'] ?? 'Relationship Manager';
    $rmDesignation = $rm['designation'] ?? 'Relationship Manager';
    $rmMobile      = $rm['mobile'] ?? 'N/A';
    $rmEmail       = $rm['email'] ?? 'N/A';
}


// Get ALL RMs and Templates
$allRMs = getAllRelationshipManagers();
$templates = [
    'greeting' => getReportTemplates('greeting'),
    'intro' => getReportTemplates('intro'),
    'closing' => getReportTemplates('closing'),
    'rationale' => getReportTemplates('rationale'),
];


// Get related data
$goals = getClientGoals($clientId);
$allocations = getClientAllocations($clientId);
$schemes = getClientSchemes($clientId);
$annexures = getClientAnnexures($clientId);

// Navigation
$prevId = getPrevClientId($clientId);
$nextId = getNextClientId($clientId);

$name              = $client['name'];
$asOn              = $client['as_on'] ?? '';
$totalAmount       = (float)($client['total_amount'] ?? 0);
$profit            = (float)($client['profit'] ?? 0);
$cagr              = (float)($client['cagr'] ?? 0);
$xirr              = (float)($client['xirr'] ?? 0);
$totalGoalCurrent  = (float)($client['total_goal_current'] ?? 0);
$totalGoalTarget   = (float)($client['total_goal_target'] ?? 0);
$totalSip          = (float)($client['total_sip'] ?? 0);

$greetingStored    = trim((string)($client['greeting_prefix'] ?? ''));
$introTextStored   = trim((string)($client['intro_text'] ?? ''));
$closingTextStored = trim((string)($client['closing_text'] ?? ''));
$rationaleStored   = trim((string)($client['rationale_text'] ?? ''));
$signatureStored   = trim((string)($client['signature_block'] ?? ''));

$DEFAULT_GREETING  = 'Dear Mr.';
$DEFAULT_INTRO     = 'Introduction';
$DEFAULT_CLOSING   = 'Closing remarks';
$DEFAULT_RATIONALE = 'Rationale for recommendations';

// DYNAMIC DEFAULT SIGNATURE BLOCK (Uses logged-in user details)
$DEFAULT_SIGNATURE = "Regards,\n\n{$rmName},\n{$rmDesignation},\nFinance Doctor Private Limited.\n\nMobile - {$rmMobile}.\nEmail - {$rmEmail}\nUrl: www.financedoctor.in";

// MERGED: Combine greeting, intro, and closing into ONE message
$clientMessageParts = [];

// Add greeting
if ($greetingStored !== '') {
    $clientMessageParts[] = $greetingStored;
} else {
    $clientMessageParts[] = $DEFAULT_GREETING . ' ' . $name . ',';
}

// Add intro
if ($introTextStored !== '') {
    $clientMessageParts[] = $introTextStored;
} else {
    $clientMessageParts[] = "I am sure that all of you are safe and fine. I am pleased to send your quarterly portfolio review. The portfolio is doing well, and the scheme selection is good. Almost all schemes are showing good comparative performance and can be continued.";
}

// Add closing
if ($closingTextStored !== '') {
    $clientMessageParts[] = $closingTextParts[] = $closingTextStored;
} else {
    $clientMessageParts[] = "We are very keen to have a portfolio discussion meeting with you to discuss the portfolio. Please let us know at your convenience.";
}

$clientMessage = implode("\n\n", $clientMessageParts);

$rationaleText = $rationaleStored !== '' ? $rationaleStored : $DEFAULT_RATIONALE;
$signatureBlock = $signatureStored !== '' ? $signatureStored : $DEFAULT_SIGNATURE;

?>
<!DOCTYPE html>
<html>
<head>
    <title>Client Report - <?php echo htmlspecialchars($name); ?></title>
    
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;600&family=Inter:wght@400;500;600&display=swap" rel="stylesheet">
    
    <link rel="stylesheet" href="public/css/styles.css">
    
    <style>
        /* Global CSS included here for convenience and external files */
        body { 
            /* Reset body margins for full-width header */
            margin: 0; 
            padding: 0;
            font-family: Arial, sans-serif; 
        }
        
        /* --- Styles copied from upload.php header --- */
        .full-width-header-bar {
            width: 100%;
            background-color: white; 
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.05);
        }
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
        .header-right {
            position: relative; 
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
            z-index: 20;
        }
        .profile-dropdown {
            position: absolute;
            top: 100%; 
            right: 0;
            margin-top: 10px; 
            width: auto;
            min-width: 120px;
            background: white;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.1);
            border-radius: 6px;
            overflow: hidden;
            display: none; 
            z-index: 10;
        }
        .profile-dropdown a {
            padding: 10px 15px;
            text-decoration: none;
            display: block;
            text-align: right;
            font-size: 15px;
            color: #333;
            transition: background-color 0.1s;
        }
        .profile-dropdown a.logout-link {
            color: #F44336; 
            font-weight: 600;
        }
        
        /* --- Report Specific Styles --- */
        .main-content {
            max-width: 1200px; /* Use wide width for report tables */
            margin: 20px auto 40px auto; 
            padding: 0 20px; 
        }
        
        .report-table {
            width: 100%; /* Use full width in report view */
            margin: 0 auto 20px 0;
        }
        .report-table.small {
            width: 40%;
        }
        
        .client-report {
            page-break-after: always;
        }
        /* ... (rest of the CSS remains the same) ... */
        .flash-message {
            padding: 10px;
            border-radius: 4px;
            margin-bottom: 20px;
            opacity: 1;
            transition: opacity 0.5s ease-out, margin-top 0.5s ease-out;
            margin-top: 0;
            width: 100%;
            box-sizing: border-box;
        }
        .flash-message.flash-success {
            background: #e6ffe6;
            border: 1px solid #00b300;
        }
        .flash-message.flash-error {
            background: #ffe6e6;
            border: 1px solid #b30000;
        }
        /* ... (all button/list styles remain the same) ... */
        .email-attachment-wrapper {
            display: flex;
            gap: 20px;
            margin-bottom: 15px;
            align-items: flex-start;
        }
        .email-recipients-section,
        .file-attachments-section {
            flex: 1 1 48%; 
            padding: 0;
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
        <a href="view_saved_reports.php" class="nav-button">&larr; Back to list</a>
        <a href="upload.php" class="nav-button">Upload New Files</a>
        <?php if ($prevId): ?>
            <a href="view_report.php?id=<?php echo (int)$prevId; ?>" class="nav-button">&larr; Previous</a>
        <?php endif; ?>
        <?php if ($nextId): ?>
            <a href="view_report.php?id=<?php echo (int)$nextId; ?>" class="nav-button">Next &rarr;</a>
        <?php endif; ?>
        <button type="button" onclick="window.print()" class="nav-button">Print</button>
    </div>

    <?php if (isset($_GET['sent']) && $_GET['sent'] == '1'): ?>
        <div class="flash-message flash-success">Email sent successfully.</div>
    <?php elseif (isset($_GET['sent_error']) && $_GET['sent_error'] == '1'): ?>
        <div class="flash-message flash-error">Failed to send email. Please check SMTP settings.</div>
    <?php elseif (isset($_GET['saved']) && $_GET['saved'] == '1'): ?>
        <div class="flash-message flash-success">✅ Report saved successfully!</div>
    <?php elseif (isset($_GET['initial_save']) && $_GET['initial_save'] == '1'): ?>
        <div class="flash-message flash-success">✅ Report created successfully! You can now edit and save the details.</div>
    <?php elseif (isset($_GET['save_error']) && $_GET['save_error'] == '1'): ?>
        <div class="flash-message flash-error">❌ Failed to save report. Please try again.</div>
    <?php endif; ?>

    <div style="margin-bottom: 20px;">
        <?php 
        // Pass the logged-in user's email as the default sender for the email form
        $default_sender_email = $currentUser['email'] ?? '';
        require 'send_email.php'; 
        ?>
    </div>

    <h1>Client Report</h1>
    <h2><?php echo htmlspecialchars($name); ?></h2>

    <div class="client-report" data-client-id="<?php echo (int)$clientId; ?>">

        <form method="POST" id="reportForm">
            <input type="hidden" name="client_id" value="<?php echo (int)$clientId; ?>">

            <?php require_once 'client_communication.php'; ?>
            
            <h3>1. Current Situation</h3>
            <table class="report-table">
                <tr><th colspan="2">Current Situation as of <?php echo htmlspecialchars($asOn); ?></th></tr>
                <tr>
                    <td>Total Amount </td>
                    <td><?php echo formatAmount($totalAmount); ?></td>
                </tr>
                <tr>
                    <td>CAGR of current schemes</td>
                    <td><?php echo formatPercent($cagr); ?></td>
                </tr>
                <?php if ($xirr != 0): ?>
                    <tr>
                        <td>XIRR of all schemes since inception</td>
                        <td><?php echo formatPercent($xirr); ?></td>
                    </tr>
                <?php endif; ?>
                <tr>
                    <td>Profit since inception</td>
                    <td><?php echo formatAmount($profit); ?></td>
                </tr>
            </table>

            <h3>2. Objectives Progress for guiding on appropriate schemes</h3>
            <table class="report-table">
                <tr>
                    <th>Goal/s</th>
                    <th>Target Year</th>
                    <th>Current Amount (Rs)</th>
                    <th>SIP/SWP</th>
                    <th>Target Amount (Rs)</th>
                    <th>Status</th>
                </tr>
                <?php foreach ($goals as $g): ?>
                    <tr>
                        <td><?php echo htmlspecialchars($g['goal']); ?></td>
                        <td><?php
                            $year = '';
                            if (!empty($g['goal_date'])) {
                                $year = substr($g['goal_date'], -4);
                            }
                            echo htmlspecialchars($year);
                        ?></td>
                        <td><?php echo formatAmount((float)$g['current_amount']); ?></td>
                        <td><?php echo formatAmount((float)$g['sip_swp']); ?></td>
                        <td><?php echo formatAmount((float)$g['target_amount']); ?></td>
                        <td class="<?php echo ($g['status'] === 'On Track') ? 'status-on' : 'status-off'; ?>">
                            <?php echo ($g['status'] === 'Needs Attention') ? 'Invest More' : htmlspecialchars($g['status']); ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <tr>
                    <td><strong>Total</strong></td>
                    <td></td>
                    <td><?php echo formatAmount($totalGoalCurrent); ?></td>
                    <td><?php echo formatAmount($totalSip); ?></td>
                    <td><?php echo formatAmount($totalGoalTarget); ?></td>
                    <td></td>
                </tr>
            </table>

            <h3>3. Appropriate Product Selection at a macro level</h3>
            <table class="report-table small">
                <tr>
                    <th>Asset</th>
                    <th>Share%</th>
                </tr>
                <?php
                $sumShare = 0;
                foreach ($allocations as $a):
                    $sumShare += (float)$a['share_pct'];
                    ?>
                    <tr>
                        <td><?php echo htmlspecialchars($a['asset']); ?></td>
                        <td><?php echo number_format((float)$a['share_pct'], 0); ?></td>
                    </tr>
                <?php endforeach; ?>
                <tr>
                    <td><strong>Total</strong></td>
                    <td><strong><?php echo number_format($sumShare, 0); ?></strong></td>
                </tr>
            </table>

            <h3>4. Appropriate Scheme Selection</h3>
            <table class="report-table">
                <tr>
                    <th colspan="3">Present Schemes</th>
                    <th rowspan="2">Action Step</th>
                    <th colspan="2">Recommended Schemes</th>
                </tr>
                <tr>
                    <th>Scheme Name</th>
                    <th>SIP/SWP</th>
                    <th>Value as of <?php echo htmlspecialchars($asOn); ?></th>
                    <th>Scheme Name</th>
                    <th>Amount</th>
                </tr>
                <?php foreach ($schemes as $s): ?>
                    <tr>
                        <td><?php echo htmlspecialchars($s['scheme_name']); ?></td>
                        <td><?php echo formatAmount((float)$s['sip_swp']); ?></td>
                        <td><?php echo formatAmount((float)$s['current_value']); ?></td>
                        <td>
                            <select name="action_step[<?php echo (int)$s['id']; ?>]" 
                                    class="action-dropdown" 
                                    data-scheme-id="<?php echo (int)$s['id']; ?>">
                                <option value="Continue" <?php echo ($s['action_step'] ?? 'Continue') === 'Continue' ? 'selected' : ''; ?>>Continue</option>
                                <option value="Drop" <?php echo ($s['action_step'] ?? '') === 'Drop' ? 'selected' : ''; ?>>Drop</option>
                                <option value="Switch" <?php echo ($s['action_step'] ?? '') === 'Switch' ? 'selected' : ''; ?>>Switch</option>
                                <option value="Redeem" <?php echo ($s['action_step'] ?? '') === 'Redeem' ? 'selected' : ''; ?>>Redeem</option>
                                <option value="Partially Redeem" <?php echo ($s['action_step'] ?? '') === 'Partially Redeem' ? 'selected' : ''; ?>>Partially Redeem</option>
                            </select>
                        </td>
                        <td>
                            <input type="text" 
                                   name="recommended_scheme[<?php echo (int)$s['id']; ?>]"
                                   class="scheme-input" 
                                   data-scheme-id="<?php echo (int)$s['id']; ?>"
                                   data-field="recommended_scheme"
                                   value="<?php echo htmlspecialchars($s['recommended_scheme'] ?? ''); ?>"
                                   placeholder="Enter recommended scheme...">
                        </td>
                        <td>
                            <input type="number" 
                                   name="recommended_amount[<?php echo (int)$s['id']; ?>]"
                                   class="scheme-input" 
                                   data-scheme-id="<?php echo (int)$s['id']; ?>"
                                   data-field="recommended_amount"
                                   value="<?php echo $s['recommended_amount'] ?? ''; ?>"
                                   placeholder="Amount"
                                   step="0.01">
                        </td>
                    </tr>
                <?php endforeach; ?>
            </table>

            <?php require_once 'rationale.php'; ?>

            <?php require_once 'signature.php'; ?>

            <div style="margin-top: 30px; text-align: right; padding-bottom: 20px;">
                <button type="submit" name="save_report" class="btn-primary">
                    💾 Save
                </button>
            </div>

        </form>

    </div>

</div>

<div id="toast" class="toast"></div>

<script>
    // --- Header Dropdown Toggle Script ---
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

    // --- GLOBAL UTILITY FUNCTIONS (Needed by all modules) ---
    function showToast(msg) {
        const toast = document.getElementById('toast');
        toast.textContent = msg;
        toast.classList.add('show');
        setTimeout(() => {
            toast.classList.remove('show');
        }, 2000);
    }

    // Function to display a contextual flash message
    function showContextualFlash(type, message, containerId) {
        const container = document.getElementById(containerId);
        if (!container) {
            showToast(message);
            return;
        }

        container.innerHTML = '';
        
        const div = document.createElement('div');
        div.className = `flash-message flash-${type}`;
        div.style.opacity = '1';
        div.textContent = message;
        
        container.appendChild(div);

        setTimeout(() => {
            div.style.opacity = '0';
            div.style.marginTop = '-50px'; 
        }, 3000); 
        setTimeout(() => {
            div.remove();
        }, 3500); 
    }

    // Function to get the content of a template selector (Needed by Communication and Rationale modules)
    function getTemplateContentById(selectorId) {
        const selector = document.getElementById(selectorId);
        if (!selector || selector.value === '0') return null;
        
        const selectedOption = selector.options[selector.selectedIndex];
        // Use data-content for fast lookup (already loaded in PHP)
        return selectedOption.getAttribute('data-content');
    }

    // Function to build the entire client message from the three template parts (Needed by Communication module)
    function assembleClientMessage() {
        const greeting = getTemplateContentById('greeting_template_selector');
        const intro = getTemplateContentById('intro_template_selector');
        const closing = getTemplateContentById('closing_template_selector');
        
        let messageParts = [];
        if (greeting) {
            messageParts.push(greeting); 
        }
        if (intro) {
            messageParts.push(intro);
        }
        if (closing) {
            messageParts.push(closing);
        }
        
        return messageParts.join('\n\n');
    }

    // --- FILE NAME DISPLAY LOGIC (NEW) ---
    document.getElementById('attachments').addEventListener('change', function(e) {
        const fileListDisplay = document.getElementById('file-list-display');
        fileListDisplay.innerHTML = ''; // Clear previous files

        if (this.files.length > 0) {
            Array.from(this.files).forEach(file => {
                const fileNameSpan = document.createElement('span');
                fileNameSpan.className = 'file-name-item';
                fileNameSpan.textContent = file.name;
                fileListDisplay.appendChild(fileNameSpan);
            });
        }
    });


    // --- GLOBAL LISTENERS (Attached to window, includes modular listeners) ---
    document.addEventListener('DOMContentLoaded', function() {
        // Handle disappearing flash messages (Existing logic remains)
        const flashMessages = document.querySelectorAll('.flash-message');
        flashMessages.forEach(function(message) {
            setTimeout(() => {
                message.style.opacity = '0';
                message.style.marginTop = '-50px'; 
            }, 3000); 
            setTimeout(() => {
                message.remove();
            }, 3500); 
        });

        // --- ATTACH LISTENERS FOR AUTOSAVE AND AJAX ---

        // DELETE TEMPLATE LOGIC (Consolidated for all modules)
        document.querySelectorAll('.delete-template-btn').forEach(btn => {
            btn.addEventListener('click', function(e) {
                e.preventDefault();
                
                const selectorId = this.getAttribute('data-template-id-attr');
                const templateSection = this.getAttribute('data-template-section');
                
                const selector = document.getElementById(selectorId);
                const templateId = selector.value;
                
                if (templateId === '0' || templateId === 0) {
                    showContextualFlash('error', '❌ Please select a template name to delete.', `${templateSection}_flash_container`);
                    return;
                }
                
                const templateName = selector.options[selector.selectedIndex].text;
                const clientId = document.querySelector('input[name="client_id"]').value;

                if (!confirm(`Are you sure you want to delete the template "${templateName}"?`)) return;

                fetch('view_report.php?id=' + encodeURIComponent(clientId), {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
                    body: new URLSearchParams({
                        ajax_action: 'delete_template',
                        template_id: templateId
                    })
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        showContextualFlash('success', `Template "${templateName}" deleted. Reloading...`, `${templateSection}_flash_container`);
                        
                        // FIX: Use window.location.href to force a non-cached reload
                        window.location.href = window.location.href.split('?')[0] + '?id=' + clientId + '&deleted=1'; 

                    } else {
                        showContextualFlash('error', `❌ Failed to delete template: ${data.error}`, `${templateSection}_flash_container`);
                    }
                })
                .catch(err => {
                    showContextualFlash('error', 'Network error during template deletion.', `${templateSection}_flash_container`);
                });
            });
        });
        
        // --- RM LOGIC (Toggle/Delete) ---

        // Toggle Add New RM Form visibility
        const addRmToggleBtn = document.getElementById('add_rm_toggle_btn');
        if (addRmToggleBtn) {
            addRmToggleBtn.addEventListener('click', function(e) {
                e.preventDefault();
                const container = document.getElementById('add_rm_container');
                container.style.display = (container.style.display === 'none' || container.style.display === '') ? 'block' : 'none';
            });
        }

        // Toggle RM Management List visibility
        const viewRmListToggle = document.getElementById('view_rm_list_toggle');
        if (viewRmListToggle) {
            viewRmListToggle.addEventListener('click', function(e) {
                e.preventDefault();
                const list = document.getElementById('rm_management_list');
                if (list.style.display === 'none' || list.style.display === '') {
                    list.style.display = 'block';
                    this.textContent = 'Hide RMs';
                } else {
                    list.style.display = 'none';
                    this.textContent = 'View/Delete RMs';
                }
            });
        }

        // DELETE RM LOGIC
        document.addEventListener('click', function(e) {
            if (e.target && e.target.classList.contains('delete-rm-btn')) {
                e.preventDefault();
                const rmId = e.target.getAttribute('data-rm-id');
                const rmName = e.target.getAttribute('data-rm-name');
                const clientId = document.querySelector('input[name="client_id"]').value;

                if (!confirm(`Are you sure you want to delete Relationship Manager: ${rmName}? This action cannot be undone.`)) {
                    return;
                }

                fetch('view_report.php?id=' + encodeURIComponent(clientId), {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8'
                    },
                    body: new URLSearchParams({
                        ajax_action: 'delete_rm',
                        rm_id: rmId
                    })
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        showContextualFlash('success', `✅ RM ${rmName} deleted. Reloading list...`, 'signature_flash_container');
                        window.location.reload(); 
                    } else {
                        showContextualFlash('error', `❌ Failed to delete RM: ${data.error}`, 'signature_flash_container');
                    }
                })
                .catch(err => {
                    showContextualFlash('error', 'Network error during deletion.', 'signature_flash_container');
                    console.error('Delete Error:', err);
                });
            }
        });

        // RM Selector Change (Loads RM signature into the textarea)
        const rmSelector = document.getElementById('rm_selector');
        if (rmSelector) {
            rmSelector.addEventListener('change', function() {
                const rmId = this.value;
                const textarea = document.getElementById('signature_textarea');
                const clientId = document.querySelector('input[name="client_id"]').value;
                
                if (rmId > 0) {
                    fetch('view_report.php?id=' + encodeURIComponent(clientId), {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
                        body: new URLSearchParams({ ajax_action: 'load_rm', rm_id: rmId })
                    })
                    .then(response => response.json())
                    .then(data => {
                        if (data.success) {
                            textarea.value = data.signature_block;
                            showContextualFlash('success', `✅ Loaded signature for ${data.rm_name}. Auto-saving...`, 'signature_flash_container');
                            
                            // Manually trigger the auto-save mechanism for the signature field
                            textarea.dispatchEvent(new Event('blur'));
                        } else {
                            showContextualFlash('error', `❌ Error loading signature: ${data.error}`, 'signature_flash_container');
                        }
                    })
                    .catch(err => {
                        showContextualFlash('error', 'Network error loading RM data.', 'signature_flash_container');
                        console.error('RM Load Error:', err);
                    });
                } else {
                    // Option "--- Use Saved Text ---" selected (ID 0). 
                    showContextualFlash('success', 'Using client-specific saved signature.', 'signature_flash_container');
                    // Trigger blur to save the text currently in the box (if edited)
                    textarea.dispatchEvent(new Event('blur'));
                }
            });
        }
        
        // Auto-save textareas on blur
        document.querySelectorAll('.large-textarea').forEach(function(textarea) {
            textarea.addEventListener('blur', function() {
                const clientId = textarea.getAttribute('data-client-id');
                const field = textarea.getAttribute('data-field');
                const value = textarea.value.trim();

                if (clientId && field) {
                    fetch('view_report.php', {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8'
                        },
                        body: new URLSearchParams({
                            ajax: '1',
                            client_id: clientId,
                            field: field,
                            value: value
                        })
                    })
                    .then(response => response.json())
                    .then(data => {
                        if (data.success) {
                            if (field !== 'signature' && field !== 'rationale' && field !== 'client_message') {
                                showToast('Saved ' + field); 
                            }
                        } else {
                            alert('Save failed: ' + (data.error || 'Unknown error'));
                        }
                    })
                    .catch(err => {
                        console.error('Save error:', err);
                    });
                }
            });
        });

        // Auto-save dropdowns
        document.querySelectorAll('.action-dropdown').forEach(function(select) {
            select.addEventListener('change', function() {
                const schemeId = select.getAttribute('data-scheme-id');
                const value = select.value;

                if (schemeId) {
                    fetch('view_report.php', {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8'
                        },
                        body: new URLSearchParams({
                            ajax_scheme: '1',
                            scheme_id: schemeId,
                            action_step: value
                        })
                    })
                    .then(response => response.json())
                    .then(data => {
                        if (data.success) {
                            showToast('Saved ' + field);
                        } else {
                            alert('Save failed: ' + (data.error || 'Unknown error'));
                        }
                    })
                    .catch(err => console.error(err));
                }
            });
        });

        // Auto-save scheme inputs (text and number)
        document.querySelectorAll('.scheme-input').forEach(function(input) {
            input.addEventListener('blur', function() {
                const schemeId = input.getAttribute('data-scheme-id');
                const field = input.getAttribute('data-field');
                const value = input.value.trim();

                if (schemeId && field) {
                    fetch('view_report.php', {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8'
                        },
                        body: new URLSearchParams({
                            ajax_scheme: '1',
                            scheme_id: schemeId,
                            [field]: value
                        })
                    })
                    .then(response => response.json())
                    .then(data => {
                        if (data.success) {
                            showToast('Saved ' + field);
                        } else {
                            alert('Save failed: ' + (data.error || 'Unknown error'));
                        }
                    })
                    .catch(err => {
                        console.error('Save error:', err);
                    });
                }
            });
        });
    });
</script>

</body>
</html>