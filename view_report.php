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


// [PATCH] Save New Recommended Schemes Synchronously
// This ensures data is saved immediately when changing state/clicking save
if (isset($_POST['new_scheme_name']) && is_array($_POST['new_scheme_name'])) {
    // 1. Clear old entries for this client first
    $delNs = $pdo->prepare("DELETE FROM client_new_schemes WHERE client_id = ?");
    $delNs->execute([$clientId]);

    // 2. Insert the current data from the form
    $insNs = $pdo->prepare("INSERT INTO client_new_schemes (client_id, scheme_name, amount) VALUES (?, ?, ?)");
    
    foreach ($_POST['new_scheme_name'] as $idx => $name) {
        $name = trim($name);
        // [CRITICAL] Use trim() to keep text like "5 Lakhs"
        $amt = trim($_POST['new_scheme_amount'][$idx] ?? '');
        
        if ($name !== '') {
            $insNs->execute([$clientId, $name, $amt]);
        }
    }
}

// Do not redirect away for POST (AJAX) requests even if id is missing
if ($clientId <= 0) {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        header('Location: view_saved_reports.php');
        exit;
    }
}

// // Fetch current user details for the header and defaults
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
        if ($name === '') {
            return $filename;
        }
        return $name;
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


/* ---------- HANDLE AJAX REQUESTS (USER RATIONALE TEMPLATE MANAGEMENT) ---------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ajax_action'])) {
    header('Content-Type: application/json; charset=UTF-8');

    $userId = (int)($currentUser['id'] ?? 0);
    if ($userId <= 0) {
        echo json_encode(['success' => false, 'error' => 'User not authenticated.']);
        exit;
    }

    try {
        $action = $_POST['ajax_action'];

        if ($action === 'save_user_template') {
            $templateName = trim($_POST['template_name'] ?? '');
            $content = $_POST['template_content'] ?? '';
            $templateIdToUpdate = isset($_POST['template_id_to_update']) ? (int)$_POST['template_id_to_update'] : 0;

            if ($templateName === '' || trim($content) === '') {
                echo json_encode(['success' => false, 'error' => 'Template name and content are required.']);
                exit;
            }

            if ($templateIdToUpdate > 0) {
                // update existing template in report_templates
                $ok = updateReportTemplate($templateIdToUpdate, $templateName, $content);
                if (!$ok) {
                    echo json_encode(['success' => false, 'error' => 'Failed to update template.']);
                    exit;
                }
                echo json_encode(['success' => true, 'template_id' => $templateIdToUpdate, 'message' => 'Template updated.']);
                exit;
            } else {
                // insert new template into report_templates as 'rationale'
                $newId = addNewTemplate($templateName, 'rationale', $content);
                if (!$newId) {
                    echo json_encode(['success' => false, 'error' => 'Failed to create template.']);
                    exit;
                }
                echo json_encode(['success' => true, 'template_id' => (int)$newId, 'message' => 'Template created.']);
                exit;
            }

        } elseif ($action === 'delete_user_template') {
            $templateId = (int)($_POST['template_id'] ?? 0);
            if ($templateId <= 0) {
                echo json_encode(['success' => false, 'error' => 'Invalid template ID for deletion.']);
                exit;
            }

            // delete from report_templates
            $ok = deleteTemplate($templateId);
            if (!$ok) {
                echo json_encode(['success' => false, 'error' => 'Failed to delete template.']);
                exit;
            }

            echo json_encode(['success' => true, 'message' => 'Template deleted successfully.']);
            exit;
        } else {
            echo json_encode(['success' => false, 'error' => 'Unknown ajax_action.']);
            exit;
        }
    } catch (Exception $e) {
        error_log("User Rationale Template AJAX Error: " . $e->getMessage());
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => 'Server error.']);
        exit;
    }
}
// ---------------------------------------------------------------------------------


/* ---------- HANDLE AJAX REQUESTS (GOAL DATA SAVE) ---------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ajax_goal_update']) && $_POST['ajax_goal_update'] === '1') {
    // Clean any output buffers to prevent HTML contamination of JSON response
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    
    header('Content-Type: application/json');
    $goalId = (int)($_POST['goal_id'] ?? 0);
    
    if ($goalId <= 0) {
        echo json_encode(['success' => false, 'error' => 'Invalid ID']);
        exit;
    }

    // LOCK CHECK: Prevent edits if report is approved/sent
    $stmtCheck = $pdo->prepare("SELECT client_id FROM client_goals WHERE id = :id");
    $stmtCheck->execute([':id' => $goalId]);
    $checkClientId = (int)$stmtCheck->fetchColumn();
    if ($checkClientId > 0) {
        $stmtLock = $pdo->prepare("SELECT report_state, review_not_ok FROM clients WHERE id = :id");
        $stmtLock->execute([':id' => $checkClientId]);
        $lockRow = $stmtLock->fetch(PDO::FETCH_ASSOC);
        if ($lockRow) {
            $lockState = (string)($lockRow['report_state'] ?? 'draft');
            $lockReviewNotOk = (int)($lockRow['review_not_ok'] ?? 0);
            $locked = (($lockState === 'reviewed' && $lockReviewNotOk === 0) || $lockState === 'sent');
            if ($locked) {
                http_response_code(403);
                echo json_encode(['success' => false, 'message' => 'Report is locked. Changes cannot be saved after approval.']);
                exit;
            }
        }
    }

try {
    // 1. Whitelist allowed fields for security
    $allowedFields = ['status', 'sip_swp', 'current_amount', 'target_amount', 'goal', 'goal_date'];
    $updatedFields = [];
    
    // 2. Define which fields are strictly numeric
    $numericFields = ['sip_swp', 'current_amount', 'target_amount'];
    
    foreach ($allowedFields as $field) {
        if (isset($_POST[$field])) {
            $val = trim($_POST[$field]);
            $originalVal = $val;
            
            // 3. ONLY parse Indian number format for specific numeric fields
            // This prevents the Goal Name and Goal Date from being corrupted to 0
            if (in_array($field, $numericFields)) {
                $val = parseIndianNumber($val); 
            }
            
            // 4. Update the database
            $stmt = $pdo->prepare("UPDATE client_goals SET $field = :val WHERE id = :id");
            $stmt->execute([':val' => $val, ':id' => $goalId]);
            
            $updatedFields[$field] = [
                'original' => $originalVal, 
                'parsed' => $val, 
                'rows' => $stmt->rowCount()
            ];
        }
    }

    echo json_encode(['success' => true, 'updated' => $updatedFields, 'goal_id' => $goalId]);
    exit;
} catch (PDOException $e) {
    error_log("Goal Update Error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'DB Error: ' . $e->getMessage()]);
    exit;
}
}

/* ---------- HANDLE AJAX REQUESTS (GOAL STATUS SAVE - LEGACY SUPPORT) ---------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ajax_goal_status']) && $_POST['ajax_goal_status'] === '1') {
    header('Content-Type: application/json');
    $goalId = (int)($_POST['goal_id'] ?? 0);
    $newStatus = trim($_POST['status'] ?? '');
    
    if ($goalId <= 0 || empty($newStatus)) {
        echo json_encode(['success' => false, 'error' => 'Invalid parameters.']);
        exit;
    }

    // LOCK CHECK: Prevent edits if report is approved/sent
    $stmtCheck = $pdo->prepare("SELECT client_id FROM client_goals WHERE id = :id");
    $stmtCheck->execute([':id' => $goalId]);
    $checkClientId = (int)$stmtCheck->fetchColumn();
    if ($checkClientId > 0) {
        $stmtLock = $pdo->prepare("SELECT report_state, review_not_ok FROM clients WHERE id = :id");
        $stmtLock->execute([':id' => $checkClientId]);
        $lockRow = $stmtLock->fetch(PDO::FETCH_ASSOC);
        if ($lockRow) {
            $lockState = (string)($lockRow['report_state'] ?? 'draft');
            $lockReviewNotOk = (int)($lockRow['review_not_ok'] ?? 0);
            $locked = (($lockState === 'reviewed' && $lockReviewNotOk === 0) || $lockState === 'sent');
            if ($locked) {
                http_response_code(403);
                echo json_encode(['success' => false, 'message' => 'Report is locked. Changes cannot be saved after approval.']);
                exit;
            }
        }
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

    // LOCK CHECK: Prevent edits if report is approved/sent
    $stmtCheck = $pdo->prepare("SELECT client_id FROM client_schemes WHERE id = :id");
    $stmtCheck->execute([':id' => $schemeId]);
    $checkClientId = (int)$stmtCheck->fetchColumn();
    if ($checkClientId > 0) {
        $stmtLock = $pdo->prepare("SELECT report_state, review_not_ok FROM clients WHERE id = :id");
        $stmtLock->execute([':id' => $checkClientId]);
        $lockRow = $stmtLock->fetch(PDO::FETCH_ASSOC);
        if ($lockRow) {
            $lockState = (string)($lockRow['report_state'] ?? 'draft');
            $lockReviewNotOk = (int)($lockRow['review_not_ok'] ?? 0);
            $locked = (($lockState === 'reviewed' && $lockReviewNotOk === 0) || $lockState === 'sent');
            if ($locked) {
                http_response_code(403);
                echo json_encode(['success' => false, 'message' => 'Report is locked. Changes cannot be saved after approval.']);
                exit;
            }
        }
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
        // Instead of exit, mark as sent and show success flash
        header('Location: view_report.php?id=' . $clientId . '&sent=1');
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
                // $greeting = trim($_POST['greeting'] ?? '');
                // $intro = trim($_POST['intro'] ?? '');
                // $closing = trim($_POST['closing'] ?? '');
                // $signatureBlock = trim($_POST['signature_block'] ?? '');
// $stmt = $pdo->prepare("
//   UPDATE clients
//   SET greeting_prefix=:g,
//       intro_text=:i,
//       closing_text=:c,
//       signature_block=:s
//   WHERE id=:id
// ");


//                $stmt->execute([
//   ':g'   => $greeting,
//   ':i'   => $intro,
//   ':c'   => $closing,
//   ':s'   => $signatureBlock,
//   ':id'  => $clientId
// ]);

// ONLY save signature here
$signatureBlock = trim($_POST['signature_block'] ?? '');
$pdo->prepare("UPDATE clients SET signature_block = :s WHERE id = :id")
    ->execute([':s' => $signatureBlock, ':id' => $clientId]);



                if (isset($_POST['recommended_scheme']) && is_array($_POST['recommended_scheme'])) {
                    foreach ($_POST['recommended_scheme'] as $sId => $sName) {
                        $sId=(int)$sId; if($sId<=0) continue;
                        $amt=trim($_POST['recommended_amount'][$sId]??'');
                        $step=$_POST['action_step'][$sId]??'Continue';
                        $pdo->prepare("UPDATE client_schemes SET recommended_scheme=:s, recommended_amount=:a, action_step=:st WHERE id=:id")
                            ->execute([':s'=>trim($sName), ':a'=>$amt, ':st'=>$step, ':id'=>$sId]);
                    }
                }

                // [PATCH] Save New Recommended Schemes Synchronously
                // This ensures data is saved immediately when changing state/clicking save
                if (isset($_POST['new_scheme_name']) && is_array($_POST['new_scheme_name'])) {
                    // 1. Clear old entries for this client first
                    $delNs = $pdo->prepare("DELETE FROM client_new_schemes WHERE client_id = ?");
                    $delNs->execute([$clientId]);

                    // 2. Insert the current data from the form
                    $insNs = $pdo->prepare("INSERT INTO client_new_schemes (client_id, scheme_name, amount) VALUES (?, ?, ?)");
                    
                    foreach ($_POST['new_scheme_name'] as $idx => $name) {
                        $name = trim($name);
                        // [CRITICAL] Use trim() to keep text like "5 Lakhs"
                        $amt = trim($_POST['new_scheme_amount'][$idx] ?? '');
                        
                        if ($name !== '') {
                            $insNs->execute([$clientId, $name, $amt]);
                        }
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

// LOCK MECHANISM: Report is locked (no edits allowed) when approved by RM or already sent
$isLocked = (($reportState === 'reviewed' && $reviewNotOk === 0) || $reportState === 'sent');

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
    'rationale' => getReportTemplates('rationale'), 
];


// Get related data
$goals = getClientGoals($clientId);
$allocations = getClientAllocations($clientId);
$schemes = getClientSchemes($clientId);


// Navigation
$prevId = getPrevClientId($clientId);
$nextId = getNextClientId($clientId);

// --- LOAD EMAIL LOG FOR SENT REPORTS ---
$emailLog = null;
if ($reportState == 'sent') {
    try {
        $stmt = $pdo->prepare("
            SELECT * FROM email_logs 
            WHERE client_id = ? 
            ORDER BY sent_at DESC 
            LIMIT 1
        ");
        $stmt->execute([$clientId]);
        $emailLog = $stmt->fetch(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        error_log("Failed to fetch email log: " . $e->getMessage());
        $emailLog = null;
    }
}


// --- Fallback logic for Current Situation fields ---
// You must define $current with calculated values before this block for full effect.
$name = $client['name'];
$asOn = $client['as_on'] ?? '';

// TOTAL AMOUNT
$totalAmount = ($client['total_amount'] !== null)
    ? (float)$client['total_amount']
    : ($current['totals']['current'] ?? 0);

// PROFIT
$profit = ($client['profit'] !== null)
    ? (float)$client['profit']
    : ($current['totals']['profit'] ?? 0);

// CAGR
$cagr = ($client['cagr'] !== null)
    ? (float)$client['cagr']
    : ($current['totals']['cagr_weighted'] ?? 0);

// XIRR
$xirr = ($client['xirr'] !== null)
    ? (float)$client['xirr']
    : ($current['summary']['xirr'] ?? 0);

// ABSOLUTE RETURN
$absoluteReturn = ($client['absolute_return'] !== null)
    ? (float)$client['absolute_return']
    : ($current['totals']['absolute_return'] ?? null);


$totalGoalCurrent  = (float)($client['total_goal_current'] ?? 0);
$totalGoalTarget   = (float)($client['total_goal_target'] ?? 0);
$totalSip          = (float)($client['total_sip'] ?? 0);

// $greetingStored    = trim((string)($client['greeting_prefix'] ?? ''));
// $introTextStored   = trim((string)($client['intro_text'] ?? ''));
// $closingTextStored = trim((string)($client['closing_text'] ?? ''));
// $rationaleStored   = trim((string)($client['rationale_text'] ?? ''));
// $signatureStored   = trim((string)($client['signature_block'] ?? ''));

// $DEFAULT_GREETING  = 'Dear Mr.';
// $DEFAULT_INTRO     = 'Introduction';
// $DEFAULT_CLOSING   = 'Closing remarks';
// $DEFAULT_RATIONALE = 'Rationale for recommendations';


// // MERGED: Combine greeting, intro, and closing into ONE message
// $clientMessageParts = [];

// // Add greeting
// if ($greetingStored !== '') {
//     $clientMessageParts[] = $greetingStored;
// } else {
//     $clientMessageParts[] = $DEFAULT_GREETING . ' ' . $name . ',';
// }

// // Add intro
// if ($introTextStored !== '') {
//     $clientMessageParts[] = $introTextStored;
// } else {
//     $clientMessageParts[] = "I am sure that all of you are safe and fine. I am pleased to send your quarterly portfolio review. The portfolio is doing well, and the scheme selection is good. Almost all schemes are showing good comparative performance and can be continued.";
// }

// // Add closing
// if ($closingTextStored !== '') {
//     $clientMessageParts[] = $closingTextStored;
// } else {
//     $clientMessageParts[] = "We are very keen to have a portfolio discussion meeting with you to discuss the portfolio. Please let us know at your convenience.";
// }

// $clientMessage = implode("\n\n", $clientMessageParts);

// $rationaleText = $rationaleStored !== '' ? $rationaleStored : $DEFAULT_RATIONALE;


?>

<!DOCTYPE html>
<html>
<head>
    <title>Client Report - <?php echo htmlspecialchars($name); ?></title>
    
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;600&family=Inter:wght@400;500;600&display=swap" rel="stylesheet">
    
    <link rel="stylesheet" href="public/css/view_report.css">
    <link rel="stylesheet" href="public/css/navbar.css">
    
</head>
<body>
  <?php include 'navbar.php'; ?>

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
        $statusText = 'Ready for Review';
        $borderColor = '#ffc107';
    } elseif ($reportState == 'reviewed') {
        $borderColor = '#28a745';
    } elseif ($reportState == 'sent') {
        $borderColor = '#007bff';
    }
?>
<!-- WORKFLOW BAR -->
<div class="workflow-bar" style="border-left-color: <?= $borderColor ?>;margin-top: 30px;">
    <div class="workflow-status">
        <span style="font-size: 12px; color: #666; margin-right: 10px;">Status:</span>
        <span class="workflow-status-badge <?= $statusClass ?>">
            <?= $statusText ?>
        </span>
    </div>
    <!-- WORKFLOW BUTTONS (OUTSIDE FORM, REMOVE THIS BLOCK IF IT EXISTS) -->
    <!-- Remove any .workflow-actions block here -->
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
    <!-- ADD NAVIGATION BUTTONS HERE -->
    <div class="nav-bar" style="margin-bottom: 20px;">
        <a href="view_saved_reports.php" class="nav-button">&larr; Back to list</a>
        <a href="upload.php?auto_search=<?php echo urlencode($client['name']); ?>" class="nav-button">Upload New Files</a>
        <?php if ($prevId): ?>
            <a href="view_report.php?id=<?php echo (int)$prevId; ?>" class="nav-button">&larr; Previous</a>
        <?php endif; ?>
        <?php if ($nextId): ?>
            <a href="view_report.php?id=<?php echo (int)$nextId; ?>" class="nav-button">Next &rarr;</a>
        <?php endif; ?>
        <button type="button" onclick="window.print()" class="nav-button">Print</button>
    </div>
    <!-- ...existing code... -->
    <div class="client-report" data-client-id="<?php echo (int)$clientId; ?>">
        <!-- Show send_email.php only if reviewed and not rejected -->
        <?php if ($reportState === 'reviewed' && $reviewNotOk === 0): ?>
            <div style="margin-bottom: 20px;">
                <?php
                $default_sender_email = $currentUser['email'] ?? '';
                require 'send_email.php';
                ?>
            </div>
        <?php endif; ?>
        <form method="POST" id="reportForm">
            <input type="hidden" name="client_id" value="<?php echo (int)$clientId; ?>">
            <input type="hidden" name="workflow_action" id="workflowActionInput" value="">
            <input type="hidden" name="review_comment" id="reviewCommentInput" value="">

            <!-- WORKFLOW BUTTONS (INSIDE FORM, ONLY HERE) -->
            <div class="workflow-actions">
                <?php if ($isARM): ?>
                    <?php if ($reportState == 'draft' || $reviewNotOk == 1): ?>
                        <button type="button" class="wf-btn btn-draft" onclick="submitWorkflow('save_draft')">Save Draft</button>
                        <button type="button" class="wf-btn btn-ready" onclick="submitWorkflow('ready_for_review')">Mark Ready for Review</button>
                    <?php endif; ?>
                    <?php if ($reportState == 'reviewed' && $reviewNotOk === 0): ?>
                        <span style="font-size: 13px; color: #28a745; font-weight: 600;">✓ Approved. You can send email below.</span>
                    <?php endif; ?>
                <?php endif; ?>

                <?php if ($isRM): ?>
                    <?php if ($reportState == 'draft' || $reviewNotOk == 1): ?>
                        <button type="button" class="wf-btn btn-draft" onclick="submitWorkflow('save_draft')">Save Draft</button>
                        <button type="button" class="wf-btn btn-ready" onclick="submitWorkflow('ready_for_review')">Mark Ready for Review</button>
                    <?php endif; ?>
                    <?php if ($reportState == 'ready' && $reviewNotOk === 0): ?>
                        <button type="button" class="wf-btn btn-approve" onclick="submitWorkflow('approve_review')">Approve (Reviewed OK)</button>
                        <button type="button" class="wf-btn btn-reject" onclick="submitWorkflow('review_not_ok')">Reject (Not OK)</button>
                    <?php endif; ?>
                    <?php if ($reportState == 'reviewed' && $reviewNotOk === 0): ?>
                        <span style="font-size: 13px; color: #28a745; font-weight: 600;">✓ Approved. You can send email below.</span>
                    <?php endif; ?>
                <?php endif; ?>
                               <?php if ($reportState == 'sent'): ?>
    <div style="background: #f8fdff; border: 1px solid #d1e9ff; border-radius: 8px; padding: 16px; margin-top: 12px; width: 100%;">
        <div style="display: flex; align-items: center; margin-bottom: 16px;">
            <div style="background: #e6f4ff; border-radius: 50%; width: 28px; height: 28px; display: flex; align-items: center; justify-content: center; margin-right: 12px;">
                <span style="font-size: 16px; color: #007bff;">✓</span>
            </div>
            <div style="font-size: 16px; color: #007bff; font-weight: 600;">Email Sent Successfully</div>
        </div>
        
        <?php if ($emailLog): ?>
            <!-- From and To in same line -->
            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 24px; margin-bottom: 16px;">
                <!-- From Section -->
                <div style="background: #fff; border: 1px solid #e8f4ff; border-radius: 8px; padding: 14px; box-shadow: 0 2px 4px rgba(0,0,0,0.05);">
                    <div style="font-size: 12px; color: #666; text-transform: uppercase; letter-spacing: 0.5px; margin-bottom: 8px; display: flex; align-items: center;">
                        <span style="background: #f0f7ff; padding: 3px 8px; border-radius: 4px; margin-right: 8px; font-weight: 600;">FROM</span>
                    </div>
                    <div style="font-weight: 600; color: #1890ff; font-size: 15px; margin-bottom: 6px;">
                        <?php echo htmlspecialchars($emailLog['from_name']); ?>
                    </div>
                    <div style="color: #666; font-size: 13px; font-weight: 500;">
                        <?php echo htmlspecialchars($emailLog['from_email']); ?>
                    </div>
                </div>
                
                <!-- To Section -->
                <div style="background: #fff; border: 1px solid #e8f4ff; border-radius: 8px; padding: 14px; box-shadow: 0 2px 4px rgba(0,0,0,0.05);">
                    <div style="font-size: 12px; color: #666; text-transform: uppercase; letter-spacing: 0.5px; margin-bottom: 8px; display: flex; align-items: center;">
                        <span style="background: #f0f7ff; padding: 3px 8px; border-radius: 4px; margin-right: 8px; font-weight: 600;">TO</span>
                    </div>
                    <div style="font-weight: 600; color: #1890ff; font-size: 15px; margin-bottom: 6px;">
                        <?php echo htmlspecialchars($emailLog['sent_to_name']); ?>
                    </div>
                    <div style="color: #666; font-size: 13px; font-weight: 500;">
                        <?php echo htmlspecialchars($emailLog['sent_to_email']); ?>
                    </div>
                </div>
            </div>
            
            <!-- CC Section (below) - IMPROVED DESIGN -->
            <?php if (!empty($emailLog['cc_emails'])): ?>
                <?php
                $ccEmails = explode(', ', $emailLog['cc_emails']);
                $ccList = array_filter(array_map('trim', $ccEmails));
                ?>
                <div style="background: #fff; border: 1px solid #e8f4ff; border-radius: 8px; padding: 14px; margin-bottom: 12px; box-shadow: 0 2px 4px rgba(0,0,0,0.05);">
                    <div style="font-size: 12px; color: #666; text-transform: uppercase; letter-spacing: 0.5px; margin-bottom: 10px; display: flex; align-items: center;">
                        <span style="background: #f0f7ff; padding: 3px 8px; border-radius: 4px; margin-right: 8px; font-weight: 600;">CC</span>
                        <span style="color: #888; font-size: 11px; font-weight: 500;">
                            (<?php echo count($ccList); ?> recipient<?php echo count($ccList) !== 1 ? 's' : ''; ?>)
                        </span>
                    </div>
                    
                    <div style="display: flex; flex-wrap: wrap; gap: 8px;">
                        <?php foreach ($ccList as $ccEmail): ?>
                            <div style="
                                background: #f8f9fa;
                                border: 1px solid #e9ecef;
                                border-radius: 6px;
                                padding: 6px 10px;
                                font-size: 12px;
                                color: #495057;
                                display: inline-flex;
                                align-items: center;
                                max-width: 100%;
                            ">
                                
                                <span style="
                                    font-weight: 500;
                                    overflow: hidden;
                                    text-overflow: ellipsis;
                                    white-space: nowrap;
                                "><?php echo htmlspecialchars($ccEmail); ?></span>
                            </div>
                        <?php endforeach; ?>
                    </div>
                    
                    <!-- Alternative: Simple comma-separated version -->
                    <!--
                    <div style="color: #666; font-size: 13px; line-height: 1.5; font-weight: 500; padding: 8px 0;">
                        <?php echo htmlspecialchars(implode(', ', $ccList)); ?>
                    </div>
                    -->
                </div>
            <?php endif; ?>
            
            <!-- Sent Date -->
            <?php if (!empty($emailLog['sent_at'])): ?>
                <div style="font-size: 13px; color: #666; text-align: right; padding-top: 12px; border-top: 1px dashed #e0e0e0; font-weight: 500;">
                    <span style="color: #888; margin-right: 4px;">Sent on</span>
                    <?php 
                    $sentDate = new DateTime($emailLog['sent_at']);
                    echo htmlspecialchars($sentDate->format('F d, Y \a\t h:i A'));
                    ?>
                </div>
            <?php endif; ?>
        <?php else: ?>
            <div style="background: #f9f9f9; border-radius: 8px; padding: 20px; text-align: center;">
                <div style="font-size: 28px; color: #ccc; margin-bottom: 8px;">📭</div>
                <div style="font-size: 14px; color: #999; font-weight: 500;">Email details not available</div>
            </div>
        <?php endif; ?>
    </div>
<?php endif; ?>
            </div>
            <!-- END WORKFLOW BUTTONS INSIDE FORM -->
<?php
// Get relationship manager data for this client
$rmName = 'Not Assigned'; // Default value

try {
    // First, get the assigned relationship manager ID for this client
    $stmt = $pdo->prepare("SELECT assigned_to FROM clients WHERE id = ?");
    $stmt->execute([$clientId]);
    $assignedTo = $stmt->fetchColumn();
    
    if ($assignedTo) {
        // Get the relationship manager's details
        $stmt = $pdo->prepare("SELECT name, designation FROM relationship_managers WHERE id = ?");
        $stmt->execute([$assignedTo]);
        $rmData = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($rmData) {
            $rmName = htmlspecialchars($rmData['name']);
            $rmDesignation = htmlspecialchars($rmData['designation'] ?? 'Relationship Manager');
        } else {
            // Fallback: get the default relationship manager
            $stmt = $pdo->prepare("SELECT name, designation FROM relationship_managers WHERE is_default = 1 LIMIT 1");
            $stmt->execute();
            $defaultRm = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($defaultRm) {
                $rmName = htmlspecialchars($defaultRm['name']);
                $rmDesignation = htmlspecialchars($defaultRm['designation'] ?? 'Relationship Manager');
            }
        }
    } else {
        // If no RM assigned, get the default one
        $stmt = $pdo->prepare("SELECT name, designation FROM relationship_managers WHERE is_default = 1 LIMIT 1");
        $stmt->execute();
        $defaultRm = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($defaultRm) {
            $rmName = htmlspecialchars($defaultRm['name']);
            $rmDesignation = htmlspecialchars($defaultRm['designation'] ?? 'Relationship Manager');
        }
    }
} catch (Exception $e) {
    // Error handling - fallback to default text
    error_log("Error fetching relationship manager: " . $e->getMessage());
    $rmName = 'RM / ARM';
    $rmDesignation = 'Relationship Manager';
}

// Determine icon based on designation
$rmIcon = '👤'; // Default icon
if (isset($rmDesignation)) {
    if (stripos($rmDesignation, 'assistant') !== false || stripos($rmDesignation, 'ARM') !== false) {
        $rmIcon = '👨‍💼'; // Assistant icon
    } elseif (stripos($rmDesignation, 'manager') !== false || stripos($rmDesignation, 'RM') !== false) {
        $rmIcon = '👔'; // Manager icon
    }
}
?>

<!-- Show client name above greeting section -->
<div style="
    background: #ffffff;
    color: #333;
    padding: 20px;
    border-radius: 10px;
    margin-bottom: 20px;
    box-shadow: 0 2px 8px rgba(0,0,0,0.05);
    border-left: 5px solid #0288D1;
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-top:30px;
">
    <div>
        <div style="font-size: 14px; color: #666; margin-bottom: 5px; text-transform: uppercase; letter-spacing: 1px;">
            Client Portfolio
        </div>
        <div style="font-size: 22px; font-weight: 600; color: #0288D1;">
            <?php echo htmlspecialchars($name); ?>
        </div>
    </div>
    <div style="
        background: #f0f7ff;
        padding: 10px 20px;
        border-radius: 6px;
        font-size: 14px;
        color: #0288D1;
        font-weight: 500;
        display: flex;
        flex-direction: column;
        align-items: center;
        gap: 4px;
        border: 1px solid #d1e7ff;
        min-width: 180px;
    ">
        <span style="font-size: 12px; color: #666; opacity: 0.8;">
            <?php echo isset($rmDesignation) ? htmlspecialchars($rmDesignation) : 'Relationship Manager'; ?>
        </span>
        <span style="font-size: 16px; font-weight: 600; display: flex; align-items: center; gap: 8px;">
            <span style="font-size: 18px;"><?php echo $rmIcon; ?></span>
            <?php echo $rmName; ?>
        </span>
    </div>
</div>

            <?php require_once 'client_communication.php'; ?>
            <?php
            // Logic to determine if editing is allowed (Draft, Ready for Review, or Rejected)
            $canEditAttachments = ($reportState === 'draft' || $reportState === 'ready' || $reviewNotOk == 1);
            ?>
            <?php include 'report_attachments.php'; ?>

            <!-- ADD THE MISSING TABLE INCLUDES HERE -->
            <?php require_once 'table1.php'; ?>
            <?php require_once 'table2.php'; ?>
            <?php require_once 'table3.php'; ?>
            <?php require_once 'table4.php'; ?>

            <?php require_once 'recommended_schemes.php'; ?>
            <?php require_once 'rationale.php'; ?>
            <?php require_once 'recommendations.php'; ?>
            <?php require_once 'annexures.php'; ?>
            <?php require_once 'signature.php'; ?>
        </form>
    </div>
</div>

<!-- Compliance Checklist Modal -->
<div id="complianceModal" class="modal-overlay">
    <div class="checklist-modal-box">
        <div class="checklist-title">
            <span style="font-size: 24px;">✓</span>
            <span>Pre-Review Compliance Checklist</span>
        </div>
        <div class="checklist-subtitle">
            Please confirm all the following checks before marking the report as ready for review:
        </div>
        
        <div class="checklist-item">
            <input type="checkbox" id="check1" class="compliance-checkbox">
            <label for="check1">Have you checked the Risk Profile?</label>
        </div>
        
        <div class="checklist-item">
            <input type="checkbox" id="check2" class="compliance-checkbox">
            <label for="check2">Has Contact and Nominee verification been checked?</label>
        </div>
        
        <div class="checklist-item">
            <input type="checkbox" id="check3" class="compliance-checkbox">
            <label for="check3">Has Tax Impact been checked?</label>
        </div>
        
        <div class="checklist-item">
            <input type="checkbox" id="check4" class="compliance-checkbox">
            <label for="check4">Has there been any SIP/SWP update?</label>
        </div>
        
        <div class="checklist-item">
            <input type="checkbox" id="check5" class="compliance-checkbox">
            <label for="check5">Are Annexures attached?</label>
        </div>
        
        <div class="modal-buttons">
            <button type="button" class="modal-btn modal-btn-cancel" onclick="closeComplianceModal()">Cancel</button>
            <button type="button" id="confirmComplianceBtn" class="modal-btn modal-btn-confirm" disabled onclick="confirmCompliance()">Confirm & Mark Ready</button>
        </div>
    </div>
</div>

<!-- Rejection Modal -->
<div id="rejectModal" class="modal-overlay">
    <div class="modal-box">
        <h3 style="margin-top: 0; color: #dc3545;">Reject Report</h3>
        <p style="font-size: 14px; color: #666; margin-bottom: 15px;">Please provide a comment explaining why this report needs revision:</p>
        <textarea id="rejectComment" rows="4" style="width: 100%; padding: 10px; border: 1px solid #ddd; border-radius: 4px; font-family: inherit; resize: vertical;"></textarea>
        <div class="modal-buttons">
            <button type="button" class="modal-btn modal-btn-cancel" onclick="closeRejectModal()">Cancel</button>
            <button type="button" class="modal-btn modal-btn-confirm" style="background: #dc3545;" onclick="submitRejection()">Submit Rejection</button>
        </div>
    </div>
</div>

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
                        ajax_action: 'delete_user_template',
                        template_id: templateId
                    })
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        showContextualFlash('success', `Template "${templateName}" deleted. Reloading...`, `${templateSection}_flash_container`);
                        
                        // FIX: Use window.location.href to force a non-cached reload
                        window.location.href = window.location.href.split('?')[0] + '?id=' + clientId + '&deleted=1#rationale_module'; 

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
        // document.querySelectorAll('.large-textarea').forEach(function(textarea) {
        //     textarea.addEventListener('blur', function() {
        //         const clientId = textarea.getAttribute('data-client-id');
        //         const field = textarea.getAttribute('data-field');
        //         const value = textarea.value.trim();

        //         if (clientId && field) {
        //             fetch('view_report.php', {
        //                 method: 'POST',
        //                 headers: {
        //                     'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8'
        //                 },
        //                 body: new URLSearchParams({
        //                     ajax: '1',
        //                     client_id: clientId,
        //                     field: field,
        //                     value: value
        //                 })
        //             })
                    
        //         }
        //     });
        // });

        // LOCK CHECK: Pass lock status from PHP to JS
        const reportLocked = <?php echo json_encode($isLocked); ?>;

        // --- GOAL STATUS DROPDOWN LOGIC ---
        if (!reportLocked) {
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
                            alert(data.message || 'Failed to save status.');
                        }
                    })
                    .catch(err => console.error(err));
                });
            });
        }

        // Track if goals have been modified
        let goalsDirty = false;

        // Parse shorthand number formats (30k, 1lakh, 2cr)
        function parseShorthandNumber(value) {
            if (!value) return 0;
            value = value.toString().toLowerCase().trim();
            
            // Remove common prefixes
            value = value.replace(/^rs\.?\s*/i, '').replace(/^₹\s*/i, '');
            
            // Handle shorthand formats
            if (value.match(/k$/)) {
                return parseFloat(value.replace(/k$/, '')) * 1000;
            } else if (value.match(/lakh?s?$/)) {
                return parseFloat(value.replace(/lakh?s?$/, '')) * 100000;
            } else if (value.match(/cr?s?$/)) {
                return parseFloat(value.replace(/cr?s?$/, '')) * 10000000;
            }
            
            // Remove commas and parse as regular number
            return parseFloat(value.replace(/,/g, '')) || 0;
        }

        // Format number to Indian format for display
        function formatIndianNumber(num) {
            if (num >= 10000000) {
                return 'Rs ' + (num / 10000000).toFixed(2) + ' Cr';
            } else if (num >= 100000) {
                return 'Rs ' + (num / 100000).toFixed(2) + ' lakhs';
            } else if (num >= 1000) {
                return 'Rs ' + (num / 1000).toFixed(2) + ' thousand';
            }
            return 'Rs ' + num.toFixed(0);
        }

        // Update totals based on current input values
        function updateTotals() {
            let totalSip = 0;
            let totalCurrent = 0;

            document.querySelectorAll('.goal-input').forEach(function(input) {
                const field = input.getAttribute('data-field');
                const value = parseShorthandNumber(input.value);

                if (field === 'current_amount') {
                    totalCurrent += value;
                } else if (field === 'sip_swp') {
                    totalSip += value;
                }
            });

            // Update total row (no Target Amount total by request)
            const totalCurrentEl = document.getElementById('total-current-amount');
            const totalSipEl = document.getElementById('total-sip-wp');

            if (totalCurrentEl) totalCurrentEl.textContent = formatIndianNumber(totalCurrent);
            if (totalSipEl) totalSipEl.textContent = formatIndianNumber(totalSip);
        }

        // Auto-save Goal Inputs (Current Amount, SIP/SWP, Target Amount)
        document.querySelectorAll('.goal-input').forEach(function(input) {
            // Update totals on input change
            input.addEventListener('input', function() {
                updateTotals();
                if (!reportLocked) goalsDirty = true;
            });

            if (!reportLocked) {
                input.addEventListener('blur', function() {
                    const goalId = this.getAttribute('data-goal-id');
                    const field  = this.getAttribute('data-field');
                    const value  = this.value;

                    fetch('view_report.php', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
                        body: new URLSearchParams({
                            ajax_goal_update: '1',
                            goal_id: goalId,
                            [field]: value
                        })
                    })
                    .then(res => res.json())
                    .then(data => {
                        if(data.success) {
                            // Visual feedback
                            input.style.backgroundColor = "#e8f5e9"; // Light green
                            setTimeout(() => input.style.backgroundColor = "transparent", 500);
                            // Update totals after successful save
                            updateTotals();
                            goalsDirty = false;
                        } else if (data.message) {
                            alert(data.message);
                        }
                    });
            }
        });

        // Initialize totals on page load
        updateTotals();

        // Save Goals button handler
        const saveGoalsBtn = document.getElementById('saveGoalsBtn');
        if (saveGoalsBtn && !reportLocked) {
            saveGoalsBtn.addEventListener('click', function() {
            const btn = this;
            const statusSpan = document.getElementById('saveGoalsStatus');
            
            btn.disabled = true;
            btn.textContent = '💾 Saving...';
            
            
            const savePromises = [];
            document.querySelectorAll('.goal-input').forEach(function(input) {
                const goalId = input.getAttribute('data-goal-id');
                const field  = input.getAttribute('data-field');
                const value  = input.value;

                const promise = fetch('view_report.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
                    body: new URLSearchParams({
                        ajax_goal_update: '1',
                        goal_id: goalId,
                        [field]: value
                    })
                })
                .then(res => {
                    if (!res.ok) {
                        throw new Error('HTTP ' + res.status + ': ' + res.statusText);
                    }
                    return res.text();
                })
                .then(text => {
                    console.log('Response text:', text);
                    try {
                        return JSON.parse(text);
                    } catch(e) {
                        console.error('JSON parse error:', e, 'Response:', text);
                        throw new Error('Invalid JSON response');
                    }
                })
                .then(data => {
                    console.log('Saved:', field, 'for goal', goalId, ':', data);
                    return data;
                })
                .catch(err => {
                    console.error('Error saving', field, 'for goal', goalId, ':', err);
                    return {success: false, error: err.message, field: field, goalId: goalId};
                });
                
                savePromises.push(promise);
            });

            Promise.all(savePromises)
                .then((results) => {
                    btn.textContent = '💾 Save Goals';
                    btn.disabled = false;
                    
                    const allSuccess = results.every(r => r && r.success);
                    const failedResults = results.filter(r => !r || !r.success);
                    
                    if (allSuccess) {
                        statusSpan.textContent = '✓ All goals saved to database';
                        statusSpan.style.color = '#28a745';
                        statusSpan.style.display = 'inline';
                        console.log('All goals saved successfully:', results);
                        goalsDirty = false;
                        
                        // Green flash on all inputs
                        document.querySelectorAll('.goal-input').forEach(input => {
                            input.style.backgroundColor = "#e8f5e9";
                            setTimeout(() => input.style.backgroundColor = "transparent", 1000);
                        });
                    } else {
                        statusSpan.textContent = '⚠ ' + failedResults.length + ' field(s) failed - see red borders';
                        statusSpan.style.color = '#dc3545';
                        statusSpan.style.display = 'inline';
                        console.error('Failed saves:', failedResults);
                        console.log('All results:', results);
                        
                        // Mark failed inputs with red border
                        results.forEach((result, index) => {
                            const inputs = document.querySelectorAll('.goal-input');
                            if (inputs[index]) {



                                if (result && result.success) {
                                    inputs[index].style.backgroundColor = "#e8f5e9";
                                    setTimeout(() => inputs[index].style.backgroundColor = "transparent", 1000);
                                } else {
                                    inputs[index].style.border = "2px solid #dc3545";
                                    inputs[index].style.backgroundColor = "#ffe6e6";
                                }
                            }
                        });
                        
                        alert('Some fields failed to save. Fields with red borders had errors.\nError details logged to console (F12).');
                    }
                    
                    setTimeout(() => {
                        statusSpan.style.display = 'none';
                    }, 5000);
                    updateTotals();
                })
                .catch(err => {
                    console.error('Error saving goals:', err);
                    btn.textContent = '💾 Save Goals';
                    btn.disabled = false;
                    statusSpan.textContent = '❌ Error: ' + err.message;
                    statusSpan.style.color = '#dc3545';
                    statusSpan.style.display = 'inline';
                    alert('Error saving goals: ' + err.message + '\nCheck console for details.');
                });
        }

        // Function to save all goal inputs synchronously
        function saveAllGoalsSync() {
            if (reportLocked) return; // Don't save if locked
            const inputs = document.querySelectorAll('.goal-input');
            if (inputs.length === 0) return;
            
            // Use synchronous XMLHttpRequest for beforeunload
            inputs.forEach(function(input) {
                const goalId = input.getAttribute('data-goal-id');
                const field = input.getAttribute('data-field');
                const value = input.value;
                
                const xhr = new XMLHttpRequest();
                xhr.open('POST', 'view_report.php', false); // false = synchronous
                xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
                xhr.send(`ajax_goal_update=1&goal_id=${goalId}&${field}=${encodeURIComponent(value)}`);
            });
        }

        // Save goals before page unload (only if not locked)
        if (!reportLocked) {
            window.addEventListener('beforeunload', function(e) {
                if (goalsDirty) {
                    saveAllGoalsSync();
                }
            });
        }

        // Auto-save dropdowns and inputs for schemes (Action Step, Recommended Scheme/Amount)
        if (!reportLocked) {
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
                        if (data && data.success) {
                            showToast('Saved ' + (field === 'action_step' ? 'action step' : field.replace('_', ' ')));
                        } else {
                            alert((data && data.message) || (data && data.error) || 'Unknown error');
                        }
                    })
                    .catch(err => console.error(err));
                }
            });
        }

        // Immediately-responding Portfolio Tenure handler (improved: swaps label and value)
        (function() {
            const returnLabel = document.getElementById('returnLabel');
            const returnValueCell = document.getElementById('returnValueCell');
            const xirrRow = document.getElementById('xirrRow');
            const xirrValue = <?php echo json_encode((float)$xirr); ?>;

            // Preformatted server-side strings to avoid client-side number formatting differences
            const cagrText = <?php echo json_encode(formatPercent($cagr)); ?>;
            const absoluteReturnText = <?php
                // Format as percentage when available, fallback to N/A
                $absVal = ($absoluteReturn !== null) ? (float)$absoluteReturn : null;
                echo json_encode($absVal !== null ? formatPercent($absVal) : 'N/A');
            ?>;

            window.updateCurrentSituation = function() {
    try {
        const selected = document.querySelector('input[name="is_older_than_1_year"]:checked');
        if (!selected) return;
        
        const val = selected.value;
        const returnLabel = document.getElementById('returnLabel');
        const returnValueCell = document.getElementById('returnValueCell');
        const xirrRow = document.getElementById('xirrRow');

        if (val === '0') {
            // --- Less than 1 year: Show Absolute Return ---
            if (returnLabel) returnLabel.textContent = 'Absolute Return of schemes';
            if (returnValueCell) {
                // Update the visible value and the metadata for AJAX saving
                returnValueCell.value = absoluteReturnText; 
                returnValueCell.setAttribute('data-field', 'absolute_return');
                returnValueCell.setAttribute('data-raw', <?php echo json_encode((float)$absoluteReturn); ?>);
            }
            if (xirrRow) xirrRow.style.display = 'none';
        } else {
            // --- More than 1 year: Show CAGR ---
            if (returnLabel) returnLabel.textContent = 'CAGR of current schemes';
            if (returnValueCell) {
                // Update the visible value and the metadata for AJAX saving
                returnValueCell.value = cagrText;
                returnValueCell.setAttribute('data-field', 'cagr');
                returnValueCell.setAttribute('data-raw', <?php echo json_encode((float)$cagr); ?>);
            }
            if (xirrRow) {
                if (xirrValue && !isNaN(xirrValue) && Number(xirrValue) !== 0) {
                    xirrRow.style.display = '';
                } else {
                    xirrRow.style.display = 'none';
                }
            }
        }
    } catch (e) {
        console.error('updateCurrentSituation error', e);
    }
};

            // Attach listeners to radio inputs and via delegated change to capture dynamic changes
            document.querySelectorAll('input[name="is_older_than_1_year"]').forEach(function(r) {
                r.addEventListener('change', window.updateCurrentSituation);
            });
            document.addEventListener('change', function(e) {
                if (e.target && e.target.name === 'is_older_than_1_year') window.updateCurrentSituation();
            });

            // Initialize UI once
            window.updateCurrentSituation();
        })();
    });
    // --- WORKFLOW JS FUNCTIONS ---
function submitWorkflow(action) {
    // Handle modals for compliance and rejection
    if (action === 'ready_for_review') {
        openComplianceModal();
        return;
    }
    if (action === 'review_not_ok') {
        openRejectModal();
        return;
    }
    if (!confirm("Are you sure you want to perform this action?")) return;

    // Show loading state
    const workflowActions = document.querySelector('.workflow-actions');
    if (workflowActions) {
        workflowActions.innerHTML = '<div style="padding: 10px; text-align: center; color: #666;">⏳ Processing...</div>';
    }
    showLoading();

    // Set the workflow action
    document.getElementById('workflowActionInput').value = action;

    // Add save_report flag to ensure content is saved
    let saveReportInput = document.getElementById('saveReportInput');
    if (!saveReportInput) {
        saveReportInput = document.createElement('input');
        saveReportInput.type = 'hidden';
        saveReportInput.name = 'save_report';
        saveReportInput.value = '1';
        saveReportInput.id = 'saveReportInput';
        document.getElementById('reportForm').appendChild(saveReportInput);
    }

    // Force save rationale before submitting the form
    forceSaveRationaleBeforeSubmit().finally(() => {
        document.getElementById('reportForm').submit();
    });
}

// --- COMPLIANCE CHECKLIST MODAL FUNCTIONS ---
function openComplianceModal() {
    document.querySelectorAll('.compliance-checkbox').forEach(cb => cb.checked = false);
    document.getElementById('confirmComplianceBtn').disabled = true;
    document.getElementById('complianceModal').style.display = 'flex';
}
function closeComplianceModal() {
    document.getElementById('complianceModal').style.display = 'none';
}
function confirmCompliance() {
    if (!confirm("Are you sure you want to mark this report as ready for review?")) return;
    closeComplianceModal();
    showLoading();
    document.getElementById('workflowActionInput').value = 'ready_for_review';
    let saveReportInput = document.getElementById('saveReportInput');
    if (!saveReportInput) {
        saveReportInput = document.createElement('input');
        saveReportInput.type = 'hidden';
        saveReportInput.name = 'save_report';
        saveReportInput.value = '1';
        saveReportInput.id = 'saveReportInput';
        document.getElementById('reportForm').appendChild(saveReportInput);
    }
    document.getElementById('reportForm').submit();
}

// --- REJECTION MODAL FUNCTIONS ---
function openRejectModal() {
    document.getElementById('rejectComment').value = '';
    document.getElementById('rejectModal').style.display = 'flex';
}
function closeRejectModal() {
    document.getElementById('rejectModal').style.display = 'none';
}
function submitRejection() {
        alert("Comment is required for rejection.");
        return;
    }
    if (!confirm("Are you sure you want to reject this report?")) return;
    closeRejectModal();
    showLoading();
    document.getElementById('workflowActionInput').value = 'review_not_ok';
    document.getElementById('reviewCommentInput').value = comment;
    let saveReportInput = document.getElementById('saveReportInput');
    if (!saveReportInput) {
        saveReportInput = document.createElement('input');
        saveReportInput.type = 'hidden';
        saveReportInput.name = 'save_report';
        saveReportInput.value = '1';
        saveReportInput.id = 'saveReportInput';
        document.getElementById('reportForm').appendChild(saveReportInput);
    }
    document.getElementById('reportForm').submit();
}

// --- LOADING OVERLAY FUNCTIONS ---
function showLoading() {
    const loadingOverlay = document.getElementById('loadingOverlay');
    if (loadingOverlay) {
        loadingOverlay.style.display = 'flex';
    }
}
function hideLoading() {
    const loadingOverlay = document.getElementById('loadingOverlay');
    if (loadingOverlay) {
        loadingOverlay.style.display = 'none';
    }
}

// --- INITIALIZE COMPLIANCE CHECKLIST ---
document.addEventListener('DOMContentLoaded', function() {
    const checkboxes = document.querySelectorAll('.compliance-checkbox');
    const confirmBtn = document.getElementById('confirmComplianceBtn');
    if (checkboxes.length > 0 && confirmBtn) {
        checkboxes.forEach(checkbox => {
            checkbox.addEventListener('change', function() {
                const allChecked = Array.from(checkboxes).every(cb => cb.checked);
                confirmBtn.disabled = !allChecked;
            });
        });
    }
    hideLoading();
});

// Handle form submission
document.getElementById('reportForm').addEventListener('submit', function(e) {
    const workflowAction = document.getElementById('workflowActionInput').value;
    if (workflowAction) {
        showLoading();
        document.querySelectorAll('.wf-btn').forEach(btn => {
            btn.disabled = true;
        });
    }
});

</script>

    <!-- Loading Overlay -->
<div id="loadingOverlay" class="loading-overlay">
    <div class="loading-spinner"></div>
    <div style="margin-top: 20px; color: #3498db; font-weight: 600;">Processing workflow action...</div>
</div>

<script>

function submitWorkflow(action) {
    // Handle modals for compliance and rejection
    if (action === 'ready_for_review') {
        openComplianceModal();
        return;
    }
    if (action === 'review_not_ok') {
        openRejectModal();
        return;
    }
    if (!confirm("Are you sure you want to perform this action?")) return;

    // Show loading state
    const workflowActions = document.querySelector('.workflow-actions');
    if (workflowActions) {
        workflowActions.innerHTML = '<div style="padding: 10px; text-align: center; color: #666;">⏳ Processing...</div>';
    }
    showLoading();

    // Set the workflow action
    document.getElementById('workflowActionInput').value = action;

    // Add save_report flag to ensure content is saved
    let saveReportInput = document.getElementById('saveReportInput');
    if (!saveReportInput) {
        saveReportInput = document.createElement('input');
        saveReportInput.type = 'hidden';
        saveReportInput.name = 'save_report';
        saveReportInput.value = '1';
        saveReportInput.id = 'saveReportInput';
        document.getElementById('reportForm').appendChild(saveReportInput);
    }

    // Submit the form
    document.getElementById('reportForm').submit();
}

// --- COMPLIANCE CHECKLIST MODAL FUNCTIONS ---
function openComplianceModal() {
    document.querySelectorAll('.compliance-checkbox').forEach(cb => cb.checked = false);
    document.getElementById('confirmComplianceBtn').disabled = true;
    document.getElementById('complianceModal').style.display = 'flex';
}
function closeComplianceModal() {
    document.getElementById('complianceModal').style.display = 'none';
}
function confirmCompliance() {
    if (!confirm("Are you sure you want to mark this report as ready for review?")) return;
    closeComplianceModal();
    showLoading();
    document.getElementById('workflowActionInput').value = 'ready_for_review';
    let saveReportInput = document.getElementById('saveReportInput');
    if (!saveReportInput) {
        saveReportInput = document.createElement('input');
        saveReportInput.type = 'hidden';
        saveReportInput.name = 'save_report';
        saveReportInput.value = '1';
        saveReportInput.id = 'saveReportInput';
        document.getElementById('reportForm').appendChild(saveReportInput);
    }
    document.getElementById('reportForm').submit();
}

// --- REJECTION MODAL FUNCTIONS ---
function openRejectModal() {
    document.getElementById('rejectComment').value = '';
    document.getElementById('rejectModal').style.display = 'flex';
}
function closeRejectModal() {
    document.getElementById('rejectModal').style.display = 'none';
}
function submitRejection() {
    const comment = document.getElementById('rejectComment').value.trim();
    if (!comment) {
        alert("Comment is required for rejection.");
        return;
    }
    if (!confirm("Are you sure you want to reject this report?")) return;
    closeRejectModal();
    showLoading();
    document.getElementById('workflowActionInput').value = 'review_not_ok';
    document.getElementById('reviewCommentInput').value = comment;
    let saveReportInput = document.getElementById('saveReportInput');
    if (!saveReportInput) {
        saveReportInput = document.createElement('input');
        saveReportInput.type = 'hidden';
        saveReportInput.name = 'save_report';
        saveReportInput.value = '1';
        saveReportInput.id = 'saveReportInput';
        document.getElementById('reportForm').appendChild(saveReportInput);
    }
    document.getElementById('reportForm').submit();
}

// --- LOADING OVERLAY FUNCTIONS ---
function showLoading() {
    const loadingOverlay = document.getElementById('loadingOverlay');
    if (loadingOverlay) {
        loadingOverlay.style.display = 'flex';
    }
}
function hideLoading() {
    const loadingOverlay = document.getElementById('loadingOverlay');
    if (loadingOverlay) {
        loadingOverlay.style.display = 'none';
    }
}

// --- INITIALIZE COMPLIANCE CHECKLIST ---
document.addEventListener('DOMContentLoaded', function() {
    const checkboxes = document.querySelectorAll('.compliance-checkbox');
    const confirmBtn = document.getElementById('confirmComplianceBtn');
    if (checkboxes.length > 0 && confirmBtn) {
        checkboxes.forEach(checkbox => {
            checkbox.addEventListener('change', function() {
                const allChecked = Array.from(checkboxes).every(cb => cb.checked);
                confirmBtn.disabled = !allChecked;
            });
        });
    }
    hideLoading();
});

// Handle form submission
document.getElementById('reportForm').addEventListener('submit', function(e) {
    const workflowAction = document.getElementById('workflowActionInput').value;
    if (workflowAction) {
        showLoading();
        document.querySelectorAll('.wf-btn').forEach(btn => {
            btn.disabled = true;
        });
    }
});

</script>

<!-- Portfolio Tenure radio section (find the label or heading for Portfolio Tenure) -->
<label for="portfolioTenure" style="font-weight:600;">
    Portfolio Tenure:
    <?php if (isset($isLocked) && $isLocked): ?>
        <span title="Locked" style="margin-left:6px;color:#888;vertical-align:middle;">🔒</span>
    <?php endif; ?>
</label>