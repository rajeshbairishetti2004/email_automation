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
            $templateId = (int)($_POST['template_id_to_update'] ?? 0);
            
            if (empty($templateName) || empty($content)) {
                throw new Exception("Template name and content are required.");
            }

            // If updating existing template
            if ($templateId > 0) {
                $stmtUpdate = $pdo->prepare("UPDATE report_templates SET name = :name, content = :content WHERE id = :id");
                $ok = $stmtUpdate->execute([':name' => $templateName, ':content' => $content, ':id' => $templateId]);
                if (!$ok) throw new Exception("Database failed to update the template.");
                echo json_encode(['success' => true, 'template_id' => $templateId, 'message' => 'Template updated successfully.']);
                exit;
            }

            // Insert new template and return its id
            $stmtInsert = $pdo->prepare("INSERT INTO report_templates (name, section_type, content) VALUES (:name, 'rationale', :content)");
            $ok = $stmtInsert->execute([':name' => $templateName, ':content' => $content]);
            if (!$ok) throw new Exception("Database failed to save the template.");
            $newId = (int)$pdo->lastInsertId();
            echo json_encode(['success' => true, 'template_id' => $newId, 'message' => 'Template saved successfully.']);
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

                $stmt = $pdo->prepare("UPDATE clients SET greeting_prefix=:g, intro_text=:i, closing_text=:c, rationale_text=:r, signature_block=:s WHERE id=:id");
                $stmt->execute([':g'=>$greeting, ':i'=>$intro, ':c'=>$closing, ':r'=>$rationale, ':s'=>$signatureBlock, ':id'=>$clientId]);

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

// ----- CURRENT SITUATION EXTRA FIELDS -----
$isOlderThan1Year = isset($client['is_older_than_1_year']) ? (int)$client['is_older_than_1_year'] : 1;
$absoluteReturn = isset($client['absolute_return']) && $client['absolute_return'] !== null
    ? (float)$client['absolute_return']
    : null;
?>
<!DOCTYPE html>
<html>
<head>
    <title>Client Report - <?php echo htmlspecialchars($name); ?></title>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;600&family=Inter:wght@400;500;600&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="public/css/styles.css">
    <link rel="stylesheet" href="public/css/view_report.css">
    <link rel="stylesheet" href="public/css/current_situation.css">
    <link rel="stylesheet" href="public/css/objectives_progress.css">
    <link rel="stylesheet" href="public/css/product_selection.css">
    <link rel="stylesheet" href="public/css/scheme_selection.css">
    <link rel="stylesheet" href="public/css/report_attachments.css">
    <link rel="stylesheet" href="public/css/annexures.css">
    <link rel="stylesheet" href="public/css/rationale.css">
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
            // Before including report_attachments.php
            $canEditAttachments = true; // or set based on user role/logic

            require_once 'report_attachments.php'; 
            ?>

            <?php
            // Before including current_situation.php
            $asOnFormatted = '';
            if (!empty($asOn)) {
                $asOnDate = DateTime::createFromFormat('d/m/Y', $asOn) ?: DateTime::createFromFormat('d-m-Y', $asOn);
                if ($asOnDate instanceof DateTime) {
                    $asOnFormatted = $asOnDate->format('jS F Y');
                } else {
                    $asOnFormatted = $asOn;
                }
            }

            require_once 'current_situation.php'; 
            ?>
            <?php require_once 'objectives_progress.php'; ?>
            <?php require_once 'product_selection.php'; ?>
            <?php require_once 'scheme_selection.php'; ?>
            <?php require_once 'rationale.php'; ?>
            <?php
            // Before including annexures.php
            $attDir = __DIR__ . '/uploads/attachments/client_' . $clientId;

            require_once 'annexures.php'; 
            ?>
            <?php require_once 'signature.php'; ?>
        </form>

    </div>

</div>

<div id="toast" class="toast"></div>

<!-- Add modular JS includes -->
<script src="public/js/global_utils.js"></script>
<script src="public/js/view_report.js"></script>
<script src="public/js/header_dropdown.js"></script>
<script src="public/js/client_communication.js"></script>
<script src="public/js/report_attachments.js"></script>
<script src="public/js/objectives_progress.js"></script>
<script src="public/js/product_selection.js"></script>
<script src="public/js/scheme_selection.js"></script>
<script src="public/js/template_actions.js"></script>
<script src="public/js/email_handler.js"></script>
<script src="public/js/workflow.js"></script> <!-- workflow functions -->
<script src="public/js/rationale.js"></script>
</body>
</html>
