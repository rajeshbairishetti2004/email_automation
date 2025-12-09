<?php
// view_report.php
// - Shows single report with next/prev navigation, print button
// - Inline editable fields with AJAX -> DB
// - Send current client report by email using PHPMailer

require_once 'auth.php'; 
require_once 'db_config.php';
require_once 'email_handler.php';
require_once 'renderers.php';
require_once 'env_loader.php'; 

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

// --- ROLE DETECTION ---
$userDesignation = $currentUser['designation'] ?? '';
// If designation contains "Associate", treat as ARM. Otherwise RM.
$isARM = (stripos($userDesignation, 'Associate') !== false);
$isRM  = !$isARM;


/* ---------- DATABASE HELPER FUNCTIONS (Local Definitions) ---------- */
// FIX: Wrap local functions in function_exists() to prevent redeclaration fatal error
if (!function_exists('getClientById')) {
    function getClientById($clientId) {
        $pdo = getPdo();
        $stmt = $pdo->prepare("SELECT * FROM clients WHERE id = :id");
        $stmt->execute([':id' => $clientId]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }
}

if (!function_exists('getClientGoals')) {
    function getClientGoals($clientId) {
        $pdo = getPdo();
        $stmt = $pdo->prepare("SELECT * FROM client_goals WHERE client_id = :id ORDER BY id ASC");
        $stmt->execute([':id' => $clientId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}

if (!function_exists('getClientAllocations')) {
    function getClientAllocations($clientId) {
        $pdo = getPdo();
        $stmt = $pdo->prepare("SELECT * FROM client_allocations WHERE client_id = :id ORDER BY id ASC");
        $stmt->execute([':id' => $clientId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}

if (!function_exists('getClientSchemes')) {
    function getClientSchemes($clientId) {
        $pdo = getPdo();
        $stmt = $pdo->prepare("SELECT * FROM client_schemes WHERE client_id = :id ORDER BY id ASC");
        $stmt->execute([':id' => $clientId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}

if (!function_exists('getClientAnnexures')) {
    function getClientAnnexures($clientId) {
        $pdo = getPdo();
        $stmt = $pdo->prepare("SELECT * FROM client_annexures WHERE client_id = :id ORDER BY id ASC");
        $stmt->execute([':id' => $clientId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}

if (!function_exists('formatAnnexureLabel')) {
    // Map filenames to descriptive labels with dates
    function formatAnnexureLabel($filename, $clientName = '') {
        $name = pathinfo($filename, PATHINFO_FILENAME);
        $nameLower = strtolower($name);
        
        // Extract date from filename (e.g., 4Dec25 or 04Dec2025)
        $dateStr = '';
        if (preg_match('/(\d{1,2})(Jan|Feb|Mar|Apr|May|Jun|Jul|Aug|Sep|Sept|Oct|Nov|Dec)(\d{2,4})/i', $name, $dateMatch)) {
            $day = ltrim($dateMatch[1], '0');
            $mon = ucfirst(strtolower($dateMatch[2]));
            $yearRaw = $dateMatch[3];
            $year = (strlen($yearRaw) === 2) ? ('20' . $yearRaw) : $yearRaw;
            $dateStr = $mon . ' ' . $day . ', ' . $year;
        }
        
        // Map known filename patterns to descriptive labels
        if (preg_match('/portfolio.*performance.*since.*inception/i', $nameLower) || 
            preg_match('/portfolio.*performance.*inception/i', $nameLower)) {
            return 'PDF document showing portfolio performance from inception including redeemed schemes ' . $dateStr;
        }
        
        if (preg_match('/current.*portfolio/i', $nameLower) || preg_match('/portfolio.*valuation/i', $nameLower)) {
            return 'Current portfolio ' . $dateStr;
        }
        
        if (preg_match('/goal.*status.*report/i', $nameLower) || preg_match('/goal.*report/i', $nameLower)) {
            return 'Goal report ' . $dateStr;
        }
        
        if (preg_match('/portfolio.*performance.*between/i', $nameLower) || 
            preg_match('/portfolio.*performance.*from/i', $nameLower)) {
            // Extract date range if present
            if (preg_match('/(\d{1,2}\s*(?:Jan|Feb|Mar|Apr|May|Jun|Jul|Aug|Sep|Oct|Nov|Dec)\d{2,4}).*?(\d{1,2}\s*(?:Jan|Feb|Mar|Apr|May|Jun|Jul|Aug|Sep|Oct|Nov|Dec)\d{2,4})/i', $name, $rangeMatch)) {
                return 'Portfolio Performance from ' . trim($rangeMatch[1]) . '- ' . trim($rangeMatch[2]);
            }
            return 'Portfolio Performance from 1 Mar25- 4Dec25';
        }
        
        // Fallback: clean up the filename
        $name = str_replace('_', ' ', $name);
        $name = preg_replace('/\s*-\s*[A-Z0-9]+\s*$/i', '', $name); // Remove trailing client name
        $name = preg_replace('/\s+/', ' ', trim($name));
        return $name . ($dateStr ? ' ' . $dateStr : '');
    }
}

if (!function_exists('getPrevClientId')) {
    function getPrevClientId($clientId) {
        $pdo = getPdo();
        $stmt = $pdo->prepare("SELECT id FROM clients WHERE id < :id ORDER BY id DESC LIMIT 1");
        $stmt->execute([':id' => $clientId]);
        return $stmt->fetchColumn();
    }
}

if (!function_exists('getNextClientId')) {
    function getNextClientId($clientId) {
        $pdo = getPdo();
        $stmt = $pdo->prepare("SELECT id FROM clients WHERE id > :id ORDER BY id ASC LIMIT 1");
        $stmt->execute([':id' => $clientId]);
        return $stmt->fetchColumn();
    }
}
// --------------------------------------------------------------------------

/* ---------- HANDLE AJAX REQUESTS (INLINE FIELD SAVE - e.g., signature block) ---------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ajax']) && $_POST['ajax'] === '1') {
    header('Content-Type: application/json');
    $clientId = (int)($_POST['client_id'] ?? 0);
    $field = trim($_POST['field'] ?? '');
    $value = $_POST['value'] ?? '';

    if ($clientId <= 0 || empty($field)) {
        echo json_encode(['success' => false, 'error' => 'Invalid parameters.']);
        exit;
    }

    // Security: Whitelist allowed fields
    $allowedFields = ['signature_block', 'greeting_prefix', 'intro_text', 'closing_text', 'rationale_text'];
    
    if (!in_array($field, $allowedFields)) {
        echo json_encode(['success' => false, 'error' => 'Invalid field name.']);
        exit;
    }

    try {
        // All fields directly map to column names in the clients table
        $stmt = $pdo->prepare("UPDATE clients SET {$field} = :value WHERE id = :id");
        $stmt->execute([':value' => $value, ':id' => $clientId]);
        
        echo json_encode(['success' => true, 'message' => ucfirst(str_replace('_', ' ', $field)) . ' saved.']); 
        exit; 
    } catch (PDOException $e) {
        error_log("AJAX Save Error: " . $e->getMessage());
        http_response_code(500); 
        echo json_encode(['success' => false, 'error' => 'Database update failed.']);
        exit; 
    }
}
// ---------------------------------------------------------------


/* ---------- HANDLE AJAX REQUESTS (USER RATIONALE TEMPLATE MANAGEMENT) ---------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ajax_action'])) {
    header('Content-Type: application/json');
    $userId = (int)($currentUser['id'] ?? 0);

    if ($userId <= 0) {
        echo json_encode(['success' => false, 'error' => 'User not authenticated.']);
        exit;
    }
    
    try {
        if ($_POST['ajax_action'] === 'save_user_template') {
            $templateName = trim($_POST['template_name'] ?? '');
            $content = $_POST['template_content'] ?? '';
            // FIX: Read the ID correctly and cast as integer
            $templateId = (int)($_POST['template_id_to_update'] ?? 0); 
            
            if (empty($templateName) || empty($content)) {
                throw new Exception("Template name and content are required.");
            }
            
            // saveUserRationaleTemplate is defined in db_config.php
            // If templateId > 0, it updates; otherwise, it inserts.
            $success = saveUserRationaleTemplate($userId, $templateName, $content, $templateId > 0 ? $templateId : null);
            
            // FIX: Add check for DB failure in the save function
            if (!$success) {
                 throw new Exception("Database failed to save or update the template.");
            }

            echo json_encode(['success' => $success, 'message' => 'Template saved/updated successfully.']);
            exit; 

        } elseif ($_POST['ajax_action'] === 'delete_user_template') {
            $templateId = (int)($_POST['template_id'] ?? 0);
            
            if ($templateId <= 0) {
                throw new Exception("Invalid template ID for deletion.");
            }
            
            // deleteUserRationaleTemplate is defined in db_config.php
            $success = deleteUserRationaleTemplate($userId, $templateId);

            echo json_encode(['success' => $success, 'message' => 'Template deleted successfully.']);
            exit; 
        }
    } catch (Exception $e) {
        error_log("User Rationale Template Error: " . $e->getMessage());
        http_response_code(500); 
        // Pass the specific error message from the exception to the frontend for better debugging
        echo json_encode(['success' => false, 'error' => $e->getMessage()]); 
        exit; 
    }
}
// ---------------------------------------------------------------------------------


/* ---------- HANDLE AJAX REQUESTS (GOAL STATUS SAVE) ---------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ajax_goal_status']) && $_POST['ajax_goal_status'] === '1') {
    header('Content-Type: application/json');
    $goalId = (int)($_POST['goal_id'] ?? 0);
    $newStatus = trim($_POST['status'] ?? '');
    
    if ($goalId <= 0 || empty($newStatus)) {
        echo json_encode(['success' => false, 'error' => 'Invalid parameters.']);
        exit;
    }

    try {
        $stmt = $pdo->prepare("UPDATE client_goals SET status = :status WHERE id = :id");
        $stmt->execute([':status' => $newStatus, ':id' => $goalId]);
        echo json_encode(['success' => true]);
        exit;
    } catch (PDOException $e) {
        error_log("Goal Status Save Error: " . $e->getMessage());
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => 'Database error.']);
        exit;
    }
}

/* ---------- HANDLE AJAX REQUESTS (SCHEME SAVE) ---------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ajax_scheme']) && $_POST['ajax_scheme'] === '1') {
    header('Content-Type: application/json');
    $schemeId = (int)($_POST['scheme_id'] ?? 0);
    
    if ($schemeId <= 0) {
        echo json_encode(['success' => false, 'error' => 'Invalid scheme ID.']);
        exit;
    }

    try {
        $updateFields = [];
        $params = [];
        
        if (isset($_POST['action_step'])) {
            $updateFields[] = 'action_step = :action_step';
            $params[':action_step'] = trim($_POST['action_step']);
        }
        if (isset($_POST['recommended_scheme'])) {
            $updateFields[] = 'recommended_scheme = :recommended_scheme';
            $params[':recommended_scheme'] = trim($_POST['recommended_scheme']);
        }
        if (isset($_POST['recommended_amount'])) {
            $updateFields[] = 'recommended_amount = :recommended_amount';
            $params[':recommended_amount'] = trim($_POST['recommended_amount']);
        }

        if (!empty($updateFields)) {
            $sql = "UPDATE client_schemes SET " . implode(', ', $updateFields) . " WHERE id = :id";
            $params[':id'] = $schemeId;
            
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
        }

        echo json_encode(['success' => true, 'message' => 'Scheme updated.']);
        exit;
    } catch (PDOException $e) {
        error_log("AJAX Scheme Save Error: " . $e->getMessage());
        http_response_code(500); 
        echo json_encode(['success' => false, 'error' => 'Scheme update failed.']);
        exit; 
    }
}
// -------------------------------------------------------------------


/* ---------- HANDLE POST REQUESTS (Non-AJAX, Redirect Only) ---------- */

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // --- 1. EMAIL SENDING (Must be checked first) ---
    if (isset($_POST['send_email']) && $_POST['send_email'] == '1') {
        // Backend security check is in handleEmailSending() - no need to check here
        // because $reportState is not yet loaded at this point in the code
        handleEmailSending($clientId);
        exit;
    }

    // --- 2. SAVE CONTENT AND WORKFLOW STATUS ---
    if (isset($_POST['save_report']) || isset($_POST['workflow_action'])) {
        $pdo = getPdo();
        $clientId = (int)($_POST['client_id'] ?? 0);

        if ($clientId > 0) {
            try {
                // --- SAVE REPORT CONTENT (Always runs first) ---
                $clientMessage = trim($_POST['client_message'] ?? '');
                // DIRECT SAVE LOGIC - No more explode/parse confusion
                // Since we now have 3 separate fields (greeting, intro, closing), we save them directly
                $greeting = trim($_POST['greeting'] ?? '');
                $intro = trim($_POST['intro'] ?? '');
                $closing = trim($_POST['closing'] ?? '');
                $rationale = trim($_POST['rationale'] ?? '');
                $signatureBlock = trim($_POST['signature_block'] ?? '');
                $isOlderThan1Year = (int)($_POST['is_older_than_1_year'] ?? 1);

                $stmt = $pdo->prepare("UPDATE clients SET greeting_prefix=:g, intro_text=:i, closing_text=:c, rationale_text=:r, signature_block=:s, is_older_than_1_year=:iot WHERE id=:id");
                $stmt->execute([':g'=>$greeting, ':i'=>$intro, ':c'=>$closing, ':r'=>$rationale, ':s'=>$signatureBlock, ':iot'=>$isOlderThan1Year, ':id'=>$clientId]);

                if (isset($_POST['recommended_scheme']) && is_array($_POST['recommended_scheme'])) {
                    foreach ($_POST['recommended_scheme'] as $sId => $sName) {
                        $sId=(int)$sId; if($sId<=0) continue;
                        $amt=trim($_POST['recommended_amount'][$sId]??'');
                        $step=$_POST['action_step'][$sId]??'Continue';
                        $pdo->prepare("UPDATE client_schemes SET recommended_scheme=:s, recommended_amount=:a, action_step=:st WHERE id=:id")
                            ->execute([':s'=>trim($sName), ':a'=>$amt, ':st'=>$step, ':id'=>$sId]);
                    }
                }

                // --- SAVE GOAL STATUSES (From Dropdowns) ---
                if (isset($_POST['goal_status']) && is_array($_POST['goal_status'])) {
                    $stmtGoalStatus = $pdo->prepare("UPDATE client_goals SET status = :status WHERE id = :id");
                    foreach ($_POST['goal_status'] as $gId => $gStatus) {
                        $gId = (int)$gId;
                        if ($gId > 0) {
                            $stmtGoalStatus->execute([':status' => trim($gStatus), ':id' => $gId]);
                        }
                    }
                }

                // --- UPDATE WORKFLOW STATUS (If triggered) ---
                if (!empty($_POST['workflow_action'])) {
                    $action = $_POST['workflow_action'];
                    $comment = $_POST['review_comment'] ?? null;

                    if ($action === 'save_draft') {
                        $pdo->prepare("UPDATE clients SET report_state='draft', draft_at=NOW(), review_not_ok=0, review_comment=NULL WHERE id=:id")->execute([':id'=>$clientId]);
                    } 
                    elseif ($action === 'ready_for_review') {
                        $pdo->prepare("UPDATE clients SET report_state='ready', ready_at=NOW(), review_not_ok=0, review_comment=NULL WHERE id=:id")->execute([':id'=>$clientId]);
                    } 
                    elseif ($action === 'approve_review') {
                        $pdo->prepare("UPDATE clients SET report_state='reviewed', reviewed_at=NOW(), review_not_ok=0, review_comment=NULL WHERE id=:id")->execute([':id'=>$clientId]);
                    } 
                    elseif ($action === 'review_not_ok') {
                        $pdo->prepare("UPDATE clients SET report_state='draft', review_not_ok=1, review_comment=:c WHERE id=:id")->execute([':id'=>$clientId, ':c'=>$comment]);
                    }
                }

                header('Location: view_report.php?id=' . $clientId . '&saved=1');
                exit;
            } catch (Exception $e) {
                error_log("Report Save Error: " . $e->getMessage());
                header('Location: view_report.php?id=' . $clientId . '&save_error=1');
                exit;
            }
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

// --- WORKFLOW STATE VARIABLES ---
$reportState    = $client['report_state'] ?? 'draft'; // draft, ready, reviewed, sent
$reviewNotOk    = (int)($client['review_not_ok'] ?? 0);
$reviewComment  = $client['review_comment'] ?? '';

// Override RM defaults with LOGGED-IN USER details
$rmName        = $currentUser['name'] ?? $currentUser['username'] ?? 'Relationship Manager';
$rmDesignation = $currentUser['designation'] ?? 'Relationship Manager'; 
$rmMobile      = $currentUser['mobile'] ?? 'N/A';
$rmEmail       = $currentUser['email'] ?? 'N/A';


// Get the list of all active user emails for the 'From' dropdown
$allActiveUsers = getAllActiveUserEmails(); 

// Get ALL RMs and Templates (Generic, for other sections)
$templates = [
    'greeting' => getReportTemplates('greeting'),
    'intro' => getReportTemplates('intro'),
    'closing' => getReportTemplates('closing'),
    // Note: 'rationale' here is now unused in rationale.php but kept for completeness
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
$isOlderThan1Year  = (int)($client['is_older_than_1_year'] ?? 1);

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
    $clientMessageParts[] = $closingTextStored;
} else {
    $clientMessageParts[] = "We are very keen to have a portfolio discussion meeting with you to discuss the portfolio. Please let us know at your convenience.";
}

$clientMessage = implode("\n\n", $clientMessageParts);

$rationaleText = $rationaleStored !== '' ? $rationaleStored : $DEFAULT_RATIONALE;

// Use stored signature if saved, otherwise use the dynamically generated default.
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
        
        /* WORKFLOW STYLES */
        .workflow-bar {
            background: #fff;
            padding: 15px 20px;
            margin: 0 auto 20px auto;
            max-width: 1200px;
            border-radius: 8px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.05);
            display: flex;
            align-items: center;
            justify-content: space-between;
            border-left: 5px solid #ccc;
        }
        .workflow-status-badge {
            font-size: 14px;
            font-weight: 700;
            padding: 6px 12px;
            border-radius: 20px;
            text-transform: uppercase;
            display: inline-block;
        }
        .status-draft { background: #e0e0e0; color: #555; border-left-color: #999; }
        .status-ready { background: #fff3cd; color: #856404; border-left-color: #ffc107; }
        .status-reviewed { background: #d4edda; color: #155724; border-left-color: #28a745; }
        .status-sent { background: #cce5ff; color: #004085; border-left-color: #007bff; }
        .status-rejected { background: #f8d7da; color: #721c24; border-left-color: #dc3545; cursor: pointer; }

        .workflow-actions {
            display: flex;
            gap: 10px;
        }
        .wf-btn {
            padding: 8px 16px;
            border: none;
            border-radius: 5px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.2s;
            font-size: 13px;
        }
        .btn-draft { background: #6c757d; color: white; }
        .btn-ready { background: #ffc107; color: #333; }
        .btn-approve { background: #28a745; color: white; }
        .btn-reject { background: #dc3545; color: white; }
        
        /* Modal for Rejection */
        .modal-overlay {
            position: fixed; top: 0; left: 0; width: 100%; height: 100%;
            background: rgba(0,0,0,0.5); display: none; justify-content: center; align-items: center; z-index: 1000;
        }
        .modal-box {
            background: white; padding: 25px; border-radius: 8px; width: 400px; max-width: 90%;
            box-shadow: 0 5px 15px rgba(0,0,0,0.3);
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
                <div style="font-size: 12px; color: #666; margin-bottom: 5px; border-bottom: 1px solid #eee; padding-bottom: 5px;">
                    <?= htmlspecialchars($userDesignation) ?>
                </div>
                <a href="logout.php" class="logout-link">Logout</a>
            </div>
        </div>
    </header>
</div>

<?php
    // Determine Status Badge Class & Text
    $statusClass = 'status-' . $reportState;
    $statusText = ucfirst($reportState);
    $borderColor = '#999';
    
    if ($reviewNotOk == 1) {
        $statusClass = 'status-rejected';
        $statusText = 'Reviewed Not OK';
        $borderColor = '#dc3545';
    } elseif ($reportState == 'ready') {
        $borderColor = '#ffc107';
    } elseif ($reportState == 'reviewed') {
        $borderColor = '#28a745';
    } elseif ($reportState == 'sent') {
        $borderColor = '#007bff';
    }
?>

<div class="workflow-bar" style="border-left-color: <?= $borderColor ?>;">
    <div class="workflow-status">
        <span style="font-size: 12px; color: #666; margin-right: 10px;">Status:</span>
        <span class="workflow-status-badge <?= $statusClass ?>">
            <?= $statusText ?>
        </span>
    </div>

    <div class="workflow-actions">
        <?php if ($isARM): ?>
            <?php if ($reportState == 'draft' || $reviewNotOk == 1): ?>
                <button type="button" class="wf-btn btn-draft" onclick="submitWorkflow('save_draft')">Save Draft</button>
                <button type="button" class="wf-btn btn-ready" onclick="submitWorkflow('ready_for_review')">Mark Ready for Review</button>
            <?php endif; ?>
            
            <?php if ($reportState == 'reviewed' && $reviewNotOk == 0): ?>
                <span style="font-size: 13px; color: #28a745; font-weight: 600;">Approved. You can send email below.</span>
            <?php endif; ?>
        <?php endif; ?>

        <?php if ($isRM): ?>
            <?php if ($reportState == 'draft' || $reviewNotOk == 1): ?>
                <button type="button" class="wf-btn btn-draft" onclick="submitWorkflow('save_draft')">Save Draft</button>
                <button type="button" class="wf-btn btn-ready" onclick="submitWorkflow('ready_for_review')">Mark Ready for Review</button>
            <?php endif; ?>

            <?php if ($reportState == 'ready' && $reviewNotOk == 0): ?>
                <button type="button" class="wf-btn btn-approve" onclick="submitWorkflow('approve_review')">Approve (Reviewed OK)</button>
                <button type="button" class="wf-btn btn-reject" onclick="openRejectModal()">Reject (Not OK)</button>
            <?php endif; ?>
        <?php endif; ?>
    </div>
</div>

<?php if ($reviewNotOk == 1 && !empty($reviewComment)): ?>
    <div style="max-width: 1200px; margin: 0 auto 20px auto; padding: 0 20px;">
        <div style="background: #fff3cd; border-left: 5px solid #dc3545; padding: 15px 20px; border-radius: 8px; box-shadow: 0 2px 4px rgba(0,0,0,0.05);">
            <div style="display: flex; align-items: flex-start; gap: 10px;">
                <span style="font-size: 24px; color: #dc3545;">⚠️</span>
                <div style="flex: 1;">
                    <strong style="color: #721c24; font-size: 16px; display: block; margin-bottom: 8px;">RM Comment:</strong>
                    <p style="color: #856404; margin: 0; line-height: 1.5; white-space: pre-wrap;"><?= htmlspecialchars($reviewComment) ?></p>
                </div>
            </div>
        </div>
    </div>
<?php endif; ?>

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
        <div class="flash-message flash-error">
            ❌ Failed to send email.<br>
            <strong>Error Detail:</strong> <?php echo htmlspecialchars($_GET['msg'] ?? 'Unknown error'); ?>
        </div>
    <?php elseif (isset($_GET['saved']) && $_GET['saved'] == '1'): ?>
        <div class="flash-message flash-success">✅ Report saved successfully!</div>
    <?php elseif (isset($_GET['initial_save']) && $_GET['initial_save'] == '1'): ?>
        <div class="flash-message flash-success">✅ Report created successfully! You can now edit and save the details.</div>
    <?php elseif (isset($_GET['save_error']) && $_GET['save_error'] == '1'): ?>
        <div class="flash-message flash-error">❌ Failed to save report. Please try again.</div>
    <?php endif; ?>

    <?php if ($reportState == 'reviewed' && $reviewNotOk == 0): ?>
        <div style="margin-bottom: 20px;">
            <?php 
            // Pass the logged-in user's email as the default sender for the email form
            $default_sender_email = $currentUser['email'] ?? '';
            require 'send_email.php'; 
            ?>
        </div>
    <?php endif; ?>

    <h1>Client Report</h1>
    <h2><?php echo htmlspecialchars($name); ?></h2>

    <div class="client-report" data-client-id="<?php echo (int)$clientId; ?>">

        <form method="POST" id="reportForm">
            <input type="hidden" name="client_id" value="<?php echo (int)$clientId; ?>">
            
            <input type="hidden" name="workflow_action" id="workflowActionInput" value="">
            <input type="hidden" name="review_comment" id="reviewCommentInput" value="">

            <?php require_once 'client_communication.php'; ?>
            
            <?php
            // Logic to determine if editing is allowed (Draft, Ready for Review, or Rejected)
            $canEditAttachments = ($reportState === 'draft' || $reportState === 'ready' || $reviewNotOk == 1);
            
            // Get existing files
            $attDir = __DIR__ . '/uploads/attachments/client_' . $clientId;
            $existingFiles = [];
            if (is_dir($attDir)) {
                $scanned = scandir($attDir);
                foreach ($scanned as $file) {
                    if ($file !== '.' && $file !== '..') {
                        $existingFiles[] = $file;
                    }
                }
            }
            ?>
            <div class="card" style="margin-top: 20px; border-left: 4px solid #17a2b8;">
                <label class="card-title">📂 Report Attachments</label>
                
                <?php if ($canEditAttachments): ?>
                    <div style="margin-bottom: 15px; padding: 10px; background: #eefbff; border-radius: 4px;">
                        <input type="file" id="ajax_attachment_upload" multiple style="width: auto;" onchange="uploadAttachment()">
                        
                        <span id="upload_spinner" style="display:none; margin-left: 10px; font-weight: bold; color: #0288D1;">
                            ⏳ Uploading...
                        </span>
                    </div>
                <?php endif; ?>

                <ul id="attachment_list" style="list-style: none; padding: 0;">
                    <?php if (empty($existingFiles)): ?>
                        <li style="color: #777; font-style: italic;">No attachments uploaded yet.</li>
                    <?php else: ?>
                        <?php foreach ($existingFiles as $file): ?>
                            <li style="margin-bottom: 8px; border-bottom: 1px solid #eee; padding-bottom: 5px; display: flex; justify-content: space-between;">
                                <span>📎 <strong><?php echo htmlspecialchars($file); ?></strong></span>
                                <?php if ($canEditAttachments): ?>
                                    <a href="#" onclick="deleteAttachment('<?php echo htmlspecialchars($file); ?>'); return false;" style="color: red; text-decoration: none; font-size: 12px;">🗑 Delete</a>
                                <?php else: ?>
                                    <span style="font-size: 11px; color: #999;">(Read Only)</span>
                                <?php endif; ?>
                            </li>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </ul>
                <p style="font-size: 11px; color: #666;">Note: Files uploaded here will be automatically attached to the final email.</p>
            </div>
            
            <?php
                $asOnFormatted = $asOn;
                $asOnDate = DateTime::createFromFormat('d/m/Y', (string)$asOn);
                if (!$asOnDate instanceof DateTime) {
                    $asOnDate = DateTime::createFromFormat('d-m-Y', (string)$asOn);
                }
                if ($asOnDate instanceof DateTime) {
                    // Display as day first, e.g., 17th November 2025
                    $asOnFormatted = $asOnDate->format('jS F Y');
                }
            ?>

            <!-- Portfolio Tenure Radio Buttons -->
            <div style="margin-bottom: 20px; padding: 15px; background-color: #f8f9fa; border-radius: 4px;">
                <label style="font-weight: bold; display: block; margin-bottom: 10px;">Portfolio Tenure:</label>
                <label style="margin-right: 20px;">
                    <input type="radio" name="is_older_than_1_year" value="1" <?php echo ($isOlderThan1Year == 1) ? 'checked' : ''; ?>>
                    More than 1 year
                </label>
                <label>
                    <input type="radio" name="is_older_than_1_year" value="0" <?php echo ($isOlderThan1Year == 0) ? 'checked' : ''; ?>>
                    Less than 1 year
                </label>
            </div>

            <h3>1. Current Situation</h3>
            <table class="report-table">
                <tr><th colspan="2">Current Situation as of <?php echo htmlspecialchars($asOnFormatted); ?></th></tr>
                <tr>
                    <td>Total Amount </td>
                    <td><?php echo formatAmount($totalAmount); ?></td>
                </tr>
                <tr>
                    <td><?php echo ($isOlderThan1Year == 0) ? 'Absolute Return of schemes' : 'CAGR of current schemes'; ?></td>
                    <td><?php echo formatPercent($cagr); ?></td>
                </tr>
                <?php if ($isOlderThan1Year == 1 && $xirr != 0): ?>
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
                <?php 
                // Recalculate totals from individual goals instead of using stored values
                $calculatedGoalCurrent = 0;
                $calculatedSip = 0;
                $calculatedGoalTarget = 0;
                
                foreach ($goals as $g): 
                    // Add to calculated totals
                    $calculatedGoalCurrent += (float)($g['current_amount'] ?? 0);
                    $calculatedSip += (float)($g['sip_swp'] ?? 0);
                    $calculatedGoalTarget += (float)($g['target_amount'] ?? 0);
                    
                    // DYNAMIC STATUS CALCULATION (based on Projected vs Target)
                    $projected    = (float)($g['projected'] ?? 0);
                    $targetAmount = (float)($g['target_amount'] ?? 0); // Future Value Required

                    if ($projected < $targetAmount) {
                        $newStatus = 'Invest More';
                        $statusClass = 'status-off'; // Red background
                    } else {
                        $newStatus = 'On Track';
                        $statusClass = 'status-on'; // Green background
                    }
                ?>
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
                        <?php 
                            // Use DB status directly. Normalize text for comparison.
                            $dbStatus = trim($g['status'] ?? 'On Track');
                            // Determine class based on current DB value
                            $dropdownClass = ($dbStatus === 'On Track') ? 'status-on' : 'status-off';
                        ?>
                        <td style="padding: 0;">
                            <select name="goal_status[<?php echo (int)$g['id']; ?>]" 
                                    class="goal-status-dropdown <?php echo $dropdownClass; ?>" 
                                    data-goal-id="<?php echo (int)$g['id']; ?>">
                                <option value="On Track" <?php echo ($dbStatus === 'On Track') ? 'selected' : ''; ?>>On Track</option>
                                <option value="Invest More" <?php echo ($dbStatus === 'Invest More' || $dbStatus === 'Needs Attention') ? 'selected' : ''; ?>>Invest More</option>
                            </select>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <tr>
                    <td><strong>Total</strong></td>
                    <td></td>
                    <td><?php echo formatAmount($calculatedGoalCurrent); ?></td>
                    <td><?php echo formatAmount($calculatedSip); ?></td>
                    <td><?php echo formatAmount($calculatedGoalTarget); ?></td>
                    <td></td>
                </tr>
            </table>

            <h3>3. Appropriate Product Selection at a macro level</h3>
            <div style="max-width: 100%; margin: 20px auto; display: flex; justify-content: center;">
                <canvas id="allocationChart" style="max-height: 300px; max-width: 100%;"></canvas>
            </div>

            <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
            <script>
                // Asset allocation data for pie chart
                const allocationData = <?php 
                    $hasGold = false;
                    foreach ($allocations as $a) {
                        if (stripos($a['asset'], 'Gold') !== false) {
                            $hasGold = true;
                            break;
                        }
                    }
                    if (!$hasGold) {
                        $allocations[] = ['asset' => 'Gold', 'share_pct' => 0];
                    }
                    
                    $chartLabels = [];
                    $chartValues = [];
                    $chartColors = [];
                    
                    foreach ($allocations as $a) {
                        $shareVal = (float)$a['share_pct'];
                        $assetName = $a['asset'];
                        
                        if ($shareVal <= 0 && stripos($assetName, 'Gold') === false) {
                            continue;
                        }
                        
                        $chartLabels[] = $assetName . ' (' . number_format($shareVal, 2) . '%)';
                        $chartValues[] = $shareVal;
                        
                        if (stripos($assetName, 'Equity') !== false) {
                            $chartColors[] = '#36A2EB';
                        } elseif (stripos($assetName, 'Debt') !== false) {
                            $chartColors[] = '#2eb85c';
                        } elseif (stripos($assetName, 'Gold') !== false) {
                            $chartColors[] = '#f9b115';
                        } else {
                            $chartColors[] = '#e55353';
                        }
                    }
                    
                    echo json_encode([
                        'labels' => $chartLabels,
                        'values' => $chartValues,
                        'colors' => $chartColors
                    ]);
                ?>;
                
                const ctx = document.getElementById('allocationChart').getContext('2d');
                new Chart(ctx, {
                    type: 'pie',
                    data: {
                        labels: allocationData.labels,
                        datasets: [{
                            data: allocationData.values,
                            backgroundColor: allocationData.colors,
                            borderColor: '#fff',
                            borderWidth: 2
                        }]
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: true,
                        plugins: {
                            legend: {
                                position: 'right',
                                align: 'center',
                                labels: {
                                    usePointStyle: true,
                                    boxWidth: 15,
                                    padding: 10,
                                    font: {
                                        size: 13
                                    }
                                }
                            },
                            tooltip: {
                                enabled: true
                            }
                        }
                    }
                });
            </script>

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
                <?php foreach ($schemes as $s): 
                    // FILTER: If both SIP and Value are 0, skip this row
                    if ((float)$s['sip_swp'] == 0 && (float)$s['current_value'] == 0) {
                        continue;
                    }
                ?>
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
                            <input type="text" 
                                   name="recommended_amount[<?php echo (int)$s['id']; ?>]"
                                   class="scheme-input" 
                                   data-scheme-id="<?php echo (int)$s['id']; ?>"
                                   data-field="recommended_amount"
                                   value="<?php echo htmlspecialchars($s['recommended_amount'] ?? ''); ?>"
                                   placeholder="Amount / Note">
                        </td>
                    </tr>
                <?php endforeach; ?>
            </table>

            <?php require_once 'rationale.php'; ?>

            <h3>Annexures</h3>
            <ul>
                <?php
                // NEW: List actual files from the persistent attachment folder
                $attDir = __DIR__ . '/uploads/attachments/client_' . $clientId;
                $hasFiles = false;
                
                if (is_dir($attDir)) {
                    $files = scandir($attDir);
                    $sortedFiles = [];
                    
                    // Separate inception portfolio file from others
                    $inceptionFile = null;
                    foreach ($files as $file) {
                        if ($file === '.' || $file === '..') continue;
                        $hasFiles = true;
                        
                        $nameLower = strtolower($file);
                        if (preg_match('/portfolio.*performance.*since.*inception/i', $nameLower) || 
                            preg_match('/portfolio.*performance.*inception/i', $nameLower)) {
                            $inceptionFile = $file;
                        } else {
                            $sortedFiles[] = $file;
                        }
                    }
                    
                    // Display inception file first if it exists
                    if ($inceptionFile) {
                        $label = formatAnnexureLabel($inceptionFile, $client['name'] ?? '');
                        echo "<li>" . htmlspecialchars($label) . "</li>";
                    }
                    
                    // Display remaining files
                    foreach ($sortedFiles as $file) {
                        $label = formatAnnexureLabel($file, $client['name'] ?? '');
                        echo "<li>" . htmlspecialchars($label) . "</li>";
                    }
                }
                
                if (!$hasFiles) {
                    echo "<li>No documents attached.</li>";
                }
                ?>
            </ul>

            <?php require_once 'signature.php'; ?>

        </form>

    </div>

</div>

<div id="toast" class="toast"></div>

<script>
    // --- Header Dropdown Toggle Script (JS code remains the same) ---
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

    // --- GLOBAL UTILITY FUNCTIONS (Needed by all modules - JS code remains the same) ---
    function showToast(msg) {
        const toast = document.getElementById('toast');
        toast.textContent = msg;
        toast.classList.add('show');
        
        // Auto-hide after 3 seconds
        setTimeout(() => {
            toast.classList.remove('show');
        }, 3000);
    }

    // Function to display a contextual flash message
    function showContextualFlash(type, message, containerId) {
        const container = document.getElementById(containerId);
        if (!container) {
            showToast(message);
            return;
        }

        // Create the message HTML
        const cssClass = type === 'success' ? 'flash-success' : 'flash-error';
        const icon = type === 'success' ? '✅' : '❌';
        
        container.innerHTML = `<div class="flash-message ${cssClass}" style="opacity: 1; transition: opacity 0.5s ease;">${icon} ${message}</div>`;

        // Auto-hide after 3 seconds
        setTimeout(() => {
            const flashMsg = container.querySelector('.flash-message');
            if (flashMsg) {
                flashMsg.style.opacity = '0';
                // Remove from DOM after fade out
                setTimeout(() => {
                    container.innerHTML = '';
                }, 500);
            }
        }, 3000);
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

    

    // --- AUTO-GROW TEXTAREA LOGIC (Global Function) ---
    function autoResizeTextarea(element) {
        element.style.height = 'auto';
        element.style.height = (element.scrollHeight + 2) + 'px';
    }

    // --- GLOBAL LISTENERS (Attached to window, includes modular listeners) ---
    document.addEventListener('DOMContentLoaded', function() {

        const resizableTextareas = document.querySelectorAll('.large-textarea, .seamless-input, .rat-main-textarea');
        resizableTextareas.forEach(textarea => {
            autoResizeTextarea(textarea);
            textarea.addEventListener('input', function() { autoResizeTextarea(this); });
            window.addEventListener('resize', function() { autoResizeTextarea(textarea); });
        });
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
        
        // NOTE: RM logic has been stripped out of this general JS block.

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
                            if (field !== 'signature_block' && field !== 'rationale' && field !== 'client_message') {
                                showToast('Saved ' + field); 
                            }
                            // Special case: If signature was saved via blur, update the local variable
                            if (field === 'signature_block' && typeof signatureOriginalContent !== 'undefined') {
                                signatureOriginalContent = value;
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

        // --- GOAL STATUS DROPDOWN LOGIC ---
        document.querySelectorAll('.goal-status-dropdown').forEach(function(select) {
            select.addEventListener('change', function() {
                const goalId = this.getAttribute('data-goal-id');
                const newStatus = this.value;
                const self = this;

                // Update visual style immediately
                if (newStatus === 'On Track') {
                    self.classList.remove('status-off');
                    self.classList.add('status-on');
                } else {
                    self.classList.remove('status-on');
                    self.classList.add('status-off');
                }

                // Save to DB
                fetch('view_report.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
                    body: new URLSearchParams({
                        ajax_goal_status: '1',
                        goal_id: goalId,
                        status: newStatus
                    })
                })
                .then(res => res.json())
                .then(data => {
                    if (data.success) {
                        showToast('Status updated');
                    } else {
                        alert('Failed to save status.');
                    }
                })
                .catch(err => console.error(err));
            });
        });

        // Auto-save dropdowns and inputs for schemes (Action Step, Recommended Scheme/Amount)
        document.querySelectorAll('.action-dropdown, .scheme-input').forEach(function(element) {
            const eventType = element.classList.contains('action-dropdown') ? 'change' : 'blur';
            
            element.addEventListener(eventType, function() {
                const schemeId = element.getAttribute('data-scheme-id');
                const field = element.getAttribute('data-field') || 'action_step'; // Default to action_step for dropdown
                const value = element.value.trim();

                if (schemeId) {
                    const postBody = {
                        ajax_scheme: '1',
                        scheme_id: schemeId,
                    };
                    postBody[field] = value;

                    fetch('view_report.php', {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8'
                        },
                        body: new URLSearchParams(postBody)
                    })
                    .then(response => response.json())
                    .then(data => {
                        if (data.success) {
                            showToast('Saved ' + (field === 'action_step' ? 'action step' : field.replace('_', ' ')));
                        } else {
                            alert('Save failed: ' + (data.error || 'Unknown error'));
                        }
                    })
                    .catch(err => console.error(err));
                }
            });
        });
    });
    
    // --- PATCH 4: NEW WORKFLOW JS FUNCTIONS ---
    function submitWorkflow(action) {
        if(!confirm("Are you sure you want to perform this action?")) return;
        
        // 1. Set the action in the hidden input inside the form
        document.getElementById('workflowActionInput').value = action;
        
        // 2. Submit the form naturally. This sends ALL data + the action.
        document.getElementById('reportForm').submit();
    }

    function openRejectModal() {
        document.getElementById('rejectModal').style.display = 'flex';
    }
    function closeRejectModal() {
        document.getElementById('rejectModal').style.display = 'none';
    }
    
    function submitRejection() {
        const comment = document.getElementById('rejectComment').value.trim();
        if(!comment) {
            alert("Comment is required for rejection.");
            return;
        }
        
        // Set Action and Comment in hidden inputs
        document.getElementById('workflowActionInput').value = 'review_not_ok';
        document.getElementById('reviewCommentInput').value = comment;
        
        // Submit Form
        document.getElementById('reportForm').submit();
    }

    // --- ATTACHMENT JS LOGIC ---
    // --- UPDATED UPLOAD LOGIC (NO RELOAD) ---
    function uploadAttachment() {
        const fileInput = document.getElementById('ajax_attachment_upload');
        const files = fileInput.files; 
        const clientId = <?php echo (int)$clientId; ?>;
        const list = document.getElementById('attachment_list');

        if (files.length === 0) { alert("Please select at least one file."); return; }

        // SAFETY: trigger blur on textareas to persist their content
        document.querySelectorAll('.large-textarea, .seamless-input').forEach(el => {
            el.dispatchEvent(new Event('blur'));
        });

        const formData = new FormData();
        formData.append('ajax_action', 'upload_attachment');
        formData.append('client_id', clientId);
        for (let i = 0; i < files.length; i++) {
            formData.append('files[]', files[i]);
        }

        document.getElementById('upload_spinner').style.display = 'inline';

        fetch('template_actions.php', {
            method: 'POST',
            body: formData
        })
        .then(response => response.json())
        .then(data => {
            document.getElementById('upload_spinner').style.display = 'none';
            if (data.success && data.files) {
                const emptyMsg = Array.from(list.children).find(li => li.textContent.includes('No attachments'));
                if (emptyMsg) emptyMsg.remove();

                data.files.forEach(fileName => {
                    const li = document.createElement('li');
                    li.style.cssText = "margin-bottom: 8px; border-bottom: 1px solid #eee; padding-bottom: 5px; display: flex; justify-content: space-between;";
                    li.innerHTML = `
                        <span>📎 <strong>${fileName}</strong></span>
                        <a href="#" onclick="deleteAttachment('${fileName}', this); return false;" style="color: red; text-decoration: none; font-size: 12px;">🗑 Delete</a>
                    `;
                    list.appendChild(li);
                });

                fileInput.value = '';
            } else {
                alert('Error: ' + (data.error || 'Unknown error'));
            }
        })
        .catch(err => {
            document.getElementById('upload_spinner').style.display = 'none';
            alert('Upload error. Please try again.');
        });
    }

    function deleteAttachment(fileName, el) {
        if(!confirm("Are you sure you want to delete " + fileName + "?")) return;
        
        const clientId = <?php echo (int)$clientId; ?>;
        
        fetch('template_actions.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
            body: new URLSearchParams({
                ajax_action: 'delete_attachment',
                client_id: clientId,
                file_name: fileName
            })
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                if (el) {
                    const li = el.closest('li');
                    if (li) li.remove();
                } else {
                    window.location.reload();
                }
            } else {
                alert('Error: ' + data.error);
            }
        })
        .catch(err => alert('Delete error.'));
    }
</script>

<!-- Rejection Modal -->
<div id="rejectModal" class="modal-overlay" style="display: none;">
    <div class="modal-box">
        <h3 style="margin-top:0; color:#dc3545;">Reject Report</h3>
        <p>Please provide a reason for rejection:</p>
        <textarea id="rejectComment" rows="4" style="width:100%; border:1px solid #ccc; padding:8px;" placeholder="E.g., Fix the intro text..."></textarea>
        <div style="margin-top:15px; text-align:right;">
            <button onclick="closeRejectModal()" style="padding:8px 12px; border:none; background:#ccc; cursor:pointer; margin-right:5px;">Cancel</button>
            <button onclick="submitRejection()" style="padding:8px 12px; border:none; background:#dc3545; color:white; cursor:pointer;">Submit Rejection</button>
        </div>
    </div>
</div>

</body>
</html>