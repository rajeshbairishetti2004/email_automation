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
if (isset($_POST['new_scheme_name']) && is_array($_POST['new_scheme_name'])) {
    $delNs = $pdo->prepare("DELETE FROM client_new_schemes WHERE client_id = ?");
    $delNs->execute([$clientId]);

    $insNs = $pdo->prepare("INSERT INTO client_new_schemes (client_id, scheme_name, amount) VALUES (?, ?, ?)");
    foreach ($_POST['new_scheme_name'] as $idx => $name) {
        $name = trim($name);
        $amt  = trim($_POST['new_scheme_amount'][$idx] ?? '');
        if ($name !== '') {
            $insNs->execute([$clientId, $name, $amt]);
        }
    }
}

if ($clientId <= 0) {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        header('Location: view_saved_reports.php');
        exit;
    }
}

$currentUser    = getCurrentUser();
$displayName    = 'User';
$nameForInitials = 'U';

if ($currentUser) {
    if (!empty($currentUser['name'])) {
        $displayName     = htmlspecialchars($currentUser['name']);
        $nameForInitials = $currentUser['name'];
    } elseif (!empty($currentUser['username'])) {
        $displayName     = htmlspecialchars($currentUser['username']);
        $nameForInitials = $currentUser['username'];
    }
}

$initials = strtoupper(substr($nameForInitials, 0, 1));

// --- ROLE DETECTION ---
$userDesignation = $currentUser['designation'] ?? '';
$isARM = (stripos($userDesignation, 'Associate') !== false);
$isRM  = !$isARM;


/* ---------- DATABASE HELPER FUNCTIONS ---------- */
if (!function_exists('getClientById')) {
    function getClientById($clientId) {
        $pdo  = getPdo();
        $stmt = $pdo->prepare("SELECT * FROM clients WHERE id = :id");
        $stmt->execute([':id' => $clientId]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }
}

if (!function_exists('getClientGoals')) {
    function getClientGoals($clientId) {
        $pdo  = getPdo();
        $stmt = $pdo->prepare("SELECT * FROM client_goals WHERE client_id = :id ORDER BY id ASC");
        $stmt->execute([':id' => $clientId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}

if (!function_exists('getClientAllocations')) {
    function getClientAllocations($clientId) {
        $pdo  = getPdo();
        $stmt = $pdo->prepare("SELECT * FROM client_allocations WHERE client_id = :id ORDER BY id ASC");
        $stmt->execute([':id' => $clientId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}

if (!function_exists('getClientSchemes')) {
    function getClientSchemes($clientId) {
        $pdo  = getPdo();
        $stmt = $pdo->prepare("SELECT * FROM client_schemes WHERE client_id = :id ORDER BY id ASC");
        $stmt->execute([':id' => $clientId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}

if (!function_exists('getClientAnnexures')) {
    function getClientAnnexures($clientId) {
        $pdo  = getPdo();
        $stmt = $pdo->prepare("SELECT * FROM client_annexures WHERE client_id = :id ORDER BY id ASC");
        $stmt->execute([':id' => $clientId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}

if (!function_exists('formatAnnexureLabel')) {
    function formatAnnexureLabel($filename, $clientName = '') {
        $name = pathinfo($filename, PATHINFO_FILENAME);
        return ($name === '') ? $filename : $name;
    }
}

if (!function_exists('getPrevClientId')) {
    function getPrevClientId($clientId) {
        $pdo  = getPdo();
        $stmt = $pdo->prepare("SELECT id FROM clients WHERE id < :id ORDER BY id DESC LIMIT 1");
        $stmt->execute([':id' => $clientId]);
        return $stmt->fetchColumn();
    }
}

if (!function_exists('getNextClientId')) {
    function getNextClientId($clientId) {
        $pdo  = getPdo();
        $stmt = $pdo->prepare("SELECT id FROM clients WHERE id > :id ORDER BY id ASC LIMIT 1");
        $stmt->execute([':id' => $clientId]);
        return $stmt->fetchColumn();
    }
}


/* ---------- AJAX: USER RATIONALE TEMPLATE MANAGEMENT ---------- */
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
            $templateName      = trim($_POST['template_name'] ?? '');
            $content           = $_POST['template_content'] ?? '';
            $templateIdToUpdate = isset($_POST['template_id_to_update']) ? (int)$_POST['template_id_to_update'] : 0;

            if ($templateName === '' || trim($content) === '') {
                echo json_encode(['success' => false, 'error' => 'Template name and content are required.']);
                exit;
            }

            if ($templateIdToUpdate > 0) {
                $ok = updateReportTemplate($templateIdToUpdate, $templateName, $content);
                if (!$ok) { echo json_encode(['success' => false, 'error' => 'Failed to update template.']); exit; }
                echo json_encode(['success' => true, 'template_id' => $templateIdToUpdate, 'message' => 'Template updated.']);
                exit;
            } else {
                $newId = addNewTemplate($templateName, 'rationale', $content);
                if (!$newId) { echo json_encode(['success' => false, 'error' => 'Failed to create template.']); exit; }
                echo json_encode(['success' => true, 'template_id' => (int)$newId, 'message' => 'Template created.']);
                exit;
            }

        } elseif ($action === 'delete_user_template') {
            $templateId = (int)($_POST['template_id'] ?? 0);
            if ($templateId <= 0) {
                echo json_encode(['success' => false, 'error' => 'Invalid template ID for deletion.']);
                exit;
            }
            $ok = deleteTemplate($templateId);
            if (!$ok) { echo json_encode(['success' => false, 'error' => 'Failed to delete template.']); exit; }
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


/* ---------- AJAX: GOAL DATA SAVE ---------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ajax_goal_update']) && $_POST['ajax_goal_update'] === '1') {
    while (ob_get_level() > 0) { ob_end_clean(); }
    header('Content-Type: application/json');
    $goalId = (int)($_POST['goal_id'] ?? 0);

    if ($goalId <= 0) { echo json_encode(['success' => false, 'error' => 'Invalid ID']); exit; }

    $stmtCheck     = $pdo->prepare("SELECT client_id FROM client_goals WHERE id = :id");
    $stmtCheck->execute([':id' => $goalId]);
    $checkClientId = (int)$stmtCheck->fetchColumn();
    if ($checkClientId > 0) {
        $stmtLock = $pdo->prepare("SELECT report_state, review_not_ok FROM clients WHERE id = :id");
        $stmtLock->execute([':id' => $checkClientId]);
        $lockRow = $stmtLock->fetch(PDO::FETCH_ASSOC);
        if ($lockRow) {
            $lockState      = (string)($lockRow['report_state'] ?? 'draft');
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
        $allowedFields = ['status', 'sip_swp', 'current_amount', 'target_amount', 'goal', 'goal_date'];
        $numericFields = ['sip_swp', 'current_amount', 'target_amount'];
        $updatedFields = [];

        foreach ($allowedFields as $field) {
            if (isset($_POST[$field])) {
                $val         = trim($_POST[$field]);
                $originalVal = $val;
                if (in_array($field, $numericFields)) {
                    $val = parseIndianNumber($val);
                }
                $stmt = $pdo->prepare("UPDATE client_goals SET $field = :val WHERE id = :id");
                $stmt->execute([':val' => $val, ':id' => $goalId]);
                $updatedFields[$field] = ['original' => $originalVal, 'parsed' => $val, 'rows' => $stmt->rowCount()];
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


/* ---------- AJAX: GOAL STATUS SAVE ---------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ajax_goal_status']) && $_POST['ajax_goal_status'] === '1') {
    header('Content-Type: application/json');
    $goalId    = (int)($_POST['goal_id'] ?? 0);
    $newStatus = trim($_POST['status'] ?? '');

    if ($goalId <= 0 || empty($newStatus)) {
        echo json_encode(['success' => false, 'error' => 'Invalid parameters.']); exit;
    }

    $stmtCheck = $pdo->prepare("SELECT client_id FROM client_goals WHERE id = :id");
    $stmtCheck->execute([':id' => $goalId]);
    $checkClientId = (int)$stmtCheck->fetchColumn();
    if ($checkClientId > 0) {
        $stmtLock = $pdo->prepare("SELECT report_state, review_not_ok FROM clients WHERE id = :id");
        $stmtLock->execute([':id' => $checkClientId]);
        $lockRow = $stmtLock->fetch(PDO::FETCH_ASSOC);
        if ($lockRow) {
            $lockState       = (string)($lockRow['report_state'] ?? 'draft');
            $lockReviewNotOk = (int)($lockRow['review_not_ok'] ?? 0);
            $locked = (($lockState === 'reviewed' && $lockReviewNotOk === 0) || $lockState === 'sent');
            if ($locked) {
                http_response_code(403);
                echo json_encode(['success' => false, 'message' => 'Report is locked.']);
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


/* ---------- AJAX: SCHEME SAVE ---------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ajax_scheme']) && $_POST['ajax_scheme'] === '1') {
    header('Content-Type: application/json');
    $schemeId = (int)($_POST['scheme_id'] ?? 0);

    if ($schemeId <= 0) { echo json_encode(['success' => false, 'error' => 'Invalid scheme ID.']); exit; }

    $stmtCheck = $pdo->prepare("SELECT client_id FROM client_schemes WHERE id = :id");
    $stmtCheck->execute([':id' => $schemeId]);
    $checkClientId = (int)$stmtCheck->fetchColumn();
    if ($checkClientId > 0) {
        $stmtLock = $pdo->prepare("SELECT report_state, review_not_ok FROM clients WHERE id = :id");
        $stmtLock->execute([':id' => $checkClientId]);
        $lockRow = $stmtLock->fetch(PDO::FETCH_ASSOC);
        if ($lockRow) {
            $lockState       = (string)($lockRow['report_state'] ?? 'draft');
            $lockReviewNotOk = (int)($lockRow['review_not_ok'] ?? 0);
            $locked = (($lockState === 'reviewed' && $lockReviewNotOk === 0) || $lockState === 'sent');
            if ($locked) {
                http_response_code(403);
                echo json_encode(['success' => false, 'message' => 'Report is locked.']);
                exit;
            }
        }
    }

    try {
        $updateFields = [];
        $params       = [];

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


/* ---------- POST: EMAIL + WORKFLOW ---------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['send_email']) && $_POST['send_email'] == '1') {
        handleEmailSending($clientId);
        header('Location: view_report.php?id=' . $clientId . '&sent=1');
        exit;
    }

    if (isset($_POST['save_report']) || isset($_POST['workflow_action'])) {
        $pdo      = getPdo();
        $clientId = (int)($_POST['client_id'] ?? 0);

        if ($clientId > 0) {
            try {
                $signatureBlock = trim($_POST['signature_block'] ?? '');
                $pdo->prepare("UPDATE clients SET signature_block = :s WHERE id = :id")
                    ->execute([':s' => $signatureBlock, ':id' => $clientId]);

                if (isset($_POST['recommended_scheme']) && is_array($_POST['recommended_scheme'])) {
                    foreach ($_POST['recommended_scheme'] as $sId => $sName) {
                        $sId = (int)$sId;
                        if ($sId <= 0) continue;
                        $amt  = trim($_POST['recommended_amount'][$sId] ?? '');
                        $step = $_POST['action_step'][$sId] ?? 'Continue';
                        $pdo->prepare("UPDATE client_schemes SET recommended_scheme=:s, recommended_amount=:a, action_step=:st WHERE id=:id")
                            ->execute([':s' => trim($sName), ':a' => $amt, ':st' => $step, ':id' => $sId]);
                    }
                }

                if (isset($_POST['new_scheme_name']) && is_array($_POST['new_scheme_name'])) {
                    $pdo->prepare("DELETE FROM client_new_schemes WHERE client_id = ?")->execute([$clientId]);
                    $insNs = $pdo->prepare("INSERT INTO client_new_schemes (client_id, scheme_name, amount) VALUES (?, ?, ?)");
                    foreach ($_POST['new_scheme_name'] as $idx => $name) {
                        $name = trim($name);
                        $amt  = trim($_POST['new_scheme_amount'][$idx] ?? '');
                        if ($name !== '') { $insNs->execute([$clientId, $name, $amt]); }
                    }
                }

                if (isset($_POST['goal_status']) && is_array($_POST['goal_status'])) {
                    $stmtGoalStatus = $pdo->prepare("UPDATE client_goals SET status = :status WHERE id = :id");
                    foreach ($_POST['goal_status'] as $gId => $gStatus) {
                        $gId = (int)$gId;
                        if ($gId > 0) { $stmtGoalStatus->execute([':status' => trim($gStatus), ':id' => $gId]); }
                    }
                }

                if (!empty($_POST['workflow_action'])) {
                    $action  = $_POST['workflow_action'];
                    $comment = $_POST['review_comment'] ?? null;

                    if ($action === 'save_draft') {
                        $pdo->prepare("UPDATE clients SET report_state='draft', draft_at=NOW(), review_not_ok=0, review_comment=NULL WHERE id=:id")->execute([':id' => $clientId]);
                    } elseif ($action === 'ready_for_review') {
                        $pdo->prepare("UPDATE clients SET report_state='ready', ready_at=NOW(), review_not_ok=0, review_comment=NULL WHERE id=:id")->execute([':id' => $clientId]);
                    } elseif ($action === 'approve_review') {
                        $pdo->prepare("UPDATE clients SET report_state='reviewed', reviewed_at=NOW(), review_not_ok=0, review_comment=NULL WHERE id=:id")->execute([':id' => $clientId]);
                    } elseif ($action === 'review_not_ok') {
                        $pdo->prepare("UPDATE clients SET report_state='draft', review_not_ok=1, review_comment=:c WHERE id=:id")->execute([':id' => $clientId, ':c' => $comment]);
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


/* ---------- LOAD CLIENT DATA ---------- */
$client = getClientById($clientId);
if (!$client) {
    ?>
    <!DOCTYPE html>
    <html>
    <head><title>Report Not Found</title></head>
    <body>
        <p>No report found for ID <?php echo htmlspecialchars((string)$clientId); ?>.</p>
        <p><a href="view_saved_reports.php">&larr; Back to list</a></p>
    </body>
    </html>
    <?php
    exit;
}

$reportState   = $client['report_state'] ?? 'draft';
$reviewNotOk   = (int)($client['review_not_ok'] ?? 0);
$reviewComment = $client['review_comment'] ?? '';
$isLocked      = (($reportState === 'reviewed' && $reviewNotOk === 0) || $reportState === 'sent');

$rmName        = $currentUser['name']        ?? $currentUser['username'] ?? 'Relationship Manager';
$rmDesignation = $currentUser['designation'] ?? 'Relationship Manager';
$rmMobile      = $currentUser['mobile']      ?? 'N/A';
$rmEmail       = $currentUser['email']       ?? 'N/A';

$allActiveUsers = getAllActiveUserEmails();
$templates = [
    'greeting'  => getReportTemplates('greeting'),
    'intro'     => getReportTemplates('intro'),
    'closing'   => getReportTemplates('closing'),
    'rationale' => getReportTemplates('rationale'),
];

$goals       = getClientGoals($clientId);
$allocations = getClientAllocations($clientId);
$schemes     = getClientSchemes($clientId);
$prevId      = getPrevClientId($clientId);
$nextId      = getNextClientId($clientId);

$emailLog = null;
if ($reportState == 'sent') {
    try {
        $stmt = $pdo->prepare("SELECT * FROM email_logs WHERE client_id = ? ORDER BY sent_at DESC LIMIT 1");
        $stmt->execute([$clientId]);
        $emailLog = $stmt->fetch(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        error_log("Failed to fetch email log: " . $e->getMessage());
    }
}

$name   = $client['name'];
$asOn   = $client['as_on'] ?? '';

$totalAmount    = ($client['total_amount']    !== null) ? (float)$client['total_amount']    : ($current['totals']['current']           ?? 0);
$profit         = ($client['profit']          !== null) ? (float)$client['profit']          : ($current['totals']['profit']            ?? 0);
$cagr           = ($client['cagr']            !== null) ? (float)$client['cagr']            : ($current['totals']['cagr_weighted']     ?? 0);
$xirr           = ($client['xirr']            !== null) ? (float)$client['xirr']            : ($current['summary']['xirr']             ?? 0);
$absoluteReturn = ($client['absolute_return'] !== null) ? (float)$client['absolute_return'] : ($current['totals']['absolute_return']   ?? null);

$totalGoalCurrent = (float)($client['total_goal_current'] ?? 0);
$totalGoalTarget  = (float)($client['total_goal_target']  ?? 0);
$totalSip         = (float)($client['total_sip']          ?? 0);
?>

<!DOCTYPE html>
<html>
<head>
    <title>Client Report - <?php echo htmlspecialchars($name); ?></title>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;600&family=Inter:wght@400;500;600&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="public/css/view_report.css">
</head>
<body>
<?php include 'navbar.php'; ?>

<?php
$statusClass = 'status-' . $reportState;
$statusText  = ucfirst($reportState);
$borderColor = '#999';

if ($reviewNotOk == 1)            { $statusClass = 'status-rejected'; $statusText = 'Reviewed Not OK'; $borderColor = '#dc3545'; }
elseif ($reportState == 'ready')  { $statusText = 'Ready for Review'; $borderColor = '#ffc107'; }
elseif ($reportState == 'reviewed') { $borderColor = '#28a745'; }
elseif ($reportState == 'sent')   { $borderColor = '#007bff'; }
?>

<!-- WORKFLOW BAR -->
<div class="workflow-bar" style="border-left-color: <?= $borderColor ?>; margin-top: 30px;">
    <div class="workflow-status">
        <span style="font-size:12px; color:#666; margin-right:10px;">Status:</span>
        <span class="workflow-status-badge <?= $statusClass ?>"><?= $statusText ?></span>
    </div>
</div>

<?php if ($reviewNotOk == 1 && !empty($reviewComment)): ?>
<div style="max-width:1200px; margin:0 auto 20px auto; padding:0 20px;">
    <div style="background:#fff3cd; border-left:5px solid #dc3545; padding:15px 20px; border-radius:8px; box-shadow:0 2px 4px rgba(0,0,0,0.05);">
        <div style="display:flex; align-items:flex-start; gap:10px;">
            <span style="font-size:24px; color:#dc3545;">⚠️</span>
            <div style="flex:1;">
                <strong style="color:#721c24; font-size:16px; display:block; margin-bottom:8px;">RM Comment:</strong>
                <p style="color:#856404; margin:0; line-height:1.5; white-space:pre-wrap;"><?= htmlspecialchars($reviewComment) ?></p>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>

<div class="main-content">
    <div class="nav-bar" style="margin-bottom:20px;">
        <a href="view_saved_reports.php" class="nav-button">&larr; Back to list</a>
        <a href="upload.php?auto_search=<?php echo urlencode($client['name']); ?>" class="nav-button">Upload New Files</a>
        <?php if ($prevId): ?><a href="view_report.php?id=<?php echo (int)$prevId; ?>" class="nav-button">&larr; Previous</a><?php endif; ?>
        <?php if ($nextId): ?><a href="view_report.php?id=<?php echo (int)$nextId; ?>" class="nav-button">Next &rarr;</a><?php endif; ?>
        <button type="button" onclick="window.print()" class="nav-button">Print</button>
    </div>

    <div class="client-report" data-client-id="<?php echo (int)$clientId; ?>">
        <?php if ($reportState === 'reviewed' && $reviewNotOk === 0): ?>
            <div style="margin-bottom:20px;">
                <?php $default_sender_email = $currentUser['email'] ?? ''; require 'send_email.php'; ?>
            </div>
        <?php endif; ?>

        <form method="POST" id="reportForm">
            <input type="hidden" name="client_id"       value="<?php echo (int)$clientId; ?>">
            <input type="hidden" name="workflow_action" id="workflowActionInput" value="">
            <input type="hidden" name="review_comment"  id="reviewCommentInput"  value="">

            <!-- WORKFLOW BUTTONS -->
            <div class="workflow-actions">
                <?php if ($isARM): ?>
                    <?php if ($reportState == 'draft' || $reviewNotOk == 1): ?>
                        <button type="button" class="wf-btn btn-draft" onclick="submitWorkflow('save_draft')">Save Draft</button>
                        <button type="button" class="wf-btn btn-ready" onclick="submitWorkflow('ready_for_review')">Mark Ready for Review</button>
                    <?php endif; ?>
                    <?php if ($reportState == 'reviewed' && $reviewNotOk === 0): ?>
                        <span style="font-size:13px; color:#28a745; font-weight:600;">✓ Approved. You can send email below.</span>
                    <?php endif; ?>
                <?php endif; ?>

                <?php if ($isRM): ?>
                    <?php if ($reportState == 'draft' || $reviewNotOk == 1): ?>
                        <button type="button" class="wf-btn btn-draft" onclick="submitWorkflow('save_draft')">Save Draft</button>
                        <button type="button" class="wf-btn btn-ready" onclick="submitWorkflow('ready_for_review')">Mark Ready for Review</button>
                    <?php endif; ?>
                    <?php if ($reportState == 'ready' && $reviewNotOk === 0): ?>
                        <button type="button" class="wf-btn btn-approve" onclick="submitWorkflow('approve_review')">Approve (Reviewed OK)</button>
                        <button type="button" class="wf-btn btn-reject"  onclick="submitWorkflow('review_not_ok')">Reject (Not OK)</button>
                    <?php endif; ?>
                    <?php if ($reportState == 'reviewed' && $reviewNotOk === 0): ?>
                        <span style="font-size:13px; color:#28a745; font-weight:600;">✓ Approved. You can send email below.</span>
                    <?php endif; ?>
                <?php endif; ?>

                <?php if ($reportState == 'sent'): ?>
                <div style="background:#f8fdff; border:1px solid #d1e9ff; border-radius:8px; padding:16px; margin-top:12px; width:100%;">
                    <div style="display:flex; align-items:center; margin-bottom:16px;">
                        <div style="background:#e6f4ff; border-radius:50%; width:28px; height:28px; display:flex; align-items:center; justify-content:center; margin-right:12px;">
                            <span style="font-size:16px; color:#007bff;">✓</span>
                        </div>
                        <div style="font-size:16px; color:#007bff; font-weight:600;">Email Sent Successfully</div>
                    </div>
                    <?php if ($emailLog): ?>
                        <div style="display:grid; grid-template-columns:1fr 1fr; gap:24px; margin-bottom:16px;">
                            <div style="background:#fff; border:1px solid #e8f4ff; border-radius:8px; padding:14px; box-shadow:0 2px 4px rgba(0,0,0,0.05);">
                                <div style="font-size:12px; color:#666; text-transform:uppercase; letter-spacing:0.5px; margin-bottom:8px;">
                                    <span style="background:#f0f7ff; padding:3px 8px; border-radius:4px; font-weight:600;">FROM</span>
                                </div>
                                <div style="font-weight:600; color:#1890ff; font-size:15px; margin-bottom:6px;"><?php echo htmlspecialchars($emailLog['from_name']); ?></div>
                                <div style="color:#666; font-size:13px; font-weight:500;"><?php echo htmlspecialchars($emailLog['from_email']); ?></div>
                            </div>
                            <div style="background:#fff; border:1px solid #e8f4ff; border-radius:8px; padding:14px; box-shadow:0 2px 4px rgba(0,0,0,0.05);">
                                <div style="font-size:12px; color:#666; text-transform:uppercase; letter-spacing:0.5px; margin-bottom:8px;">
                                    <span style="background:#f0f7ff; padding:3px 8px; border-radius:4px; font-weight:600;">TO</span>
                                </div>
                                <div style="font-weight:600; color:#1890ff; font-size:15px; margin-bottom:6px;"><?php echo htmlspecialchars($emailLog['sent_to_name']); ?></div>
                                <div style="color:#666; font-size:13px; font-weight:500;"><?php echo htmlspecialchars($emailLog['sent_to_email']); ?></div>
                            </div>
                        </div>
                        <?php if (!empty($emailLog['cc_emails'])): ?>
                            <?php $ccList = array_filter(array_map('trim', explode(', ', $emailLog['cc_emails']))); ?>
                            <div style="background:#fff; border:1px solid #e8f4ff; border-radius:8px; padding:14px; margin-bottom:12px; box-shadow:0 2px 4px rgba(0,0,0,0.05);">
                                <div style="font-size:12px; color:#666; text-transform:uppercase; letter-spacing:0.5px; margin-bottom:10px;">
                                    <span style="background:#f0f7ff; padding:3px 8px; border-radius:4px; font-weight:600;">CC</span>
                                    <span style="color:#888; font-size:11px; font-weight:500;">(<?php echo count($ccList); ?> recipient<?php echo count($ccList) !== 1 ? 's' : ''; ?>)</span>
                                </div>
                                <div style="display:flex; flex-wrap:wrap; gap:8px;">
                                    <?php foreach ($ccList as $ccEmail): ?>
                                        <div style="background:#f8f9fa; border:1px solid #e9ecef; border-radius:6px; padding:6px 10px; font-size:12px; color:#495057;">
                                            <?php echo htmlspecialchars($ccEmail); ?>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        <?php endif; ?>
                        <?php if (!empty($emailLog['sent_at'])): ?>
                            <div style="font-size:13px; color:#666; text-align:right; padding-top:12px; border-top:1px dashed #e0e0e0; font-weight:500;">
                                <span style="color:#888; margin-right:4px;">Sent on</span>
                                <?php $sentDate = new DateTime($emailLog['sent_at']); echo htmlspecialchars($sentDate->format('F d, Y \a\t h:i A')); ?>
                            </div>
                        <?php endif; ?>
                    <?php else: ?>
                        <div style="background:#f9f9f9; border-radius:8px; padding:20px; text-align:center;">
                            <div style="font-size:28px; color:#ccc; margin-bottom:8px;">📭</div>
                            <div style="font-size:14px; color:#999; font-weight:500;">Email details not available</div>
                        </div>
                    <?php endif; ?>
                </div>
                <?php endif; ?>
            </div>
            <!-- END WORKFLOW BUTTONS -->

            <?php
            // Get relationship manager data
            $rmName = 'Not Assigned';
            try {
                $stmt = $pdo->prepare("SELECT assigned_to FROM clients WHERE id = ?");
                $stmt->execute([$clientId]);
                $assignedTo = $stmt->fetchColumn();

                if ($assignedTo) {
                    $stmt = $pdo->prepare("SELECT name, designation FROM relationship_managers WHERE id = ?");
                    $stmt->execute([$assignedTo]);
                    $rmData = $stmt->fetch(PDO::FETCH_ASSOC);
                    if ($rmData) {
                        $rmName        = htmlspecialchars($rmData['name']);
                        $rmDesignation = htmlspecialchars($rmData['designation'] ?? 'Relationship Manager');
                    } else {
                        $stmt = $pdo->prepare("SELECT name, designation FROM relationship_managers WHERE is_default = 1 LIMIT 1");
                        $stmt->execute();
                        $defaultRm = $stmt->fetch(PDO::FETCH_ASSOC);
                        if ($defaultRm) { $rmName = htmlspecialchars($defaultRm['name']); $rmDesignation = htmlspecialchars($defaultRm['designation'] ?? 'Relationship Manager'); }
                    }
                } else {
                    $stmt = $pdo->prepare("SELECT name, designation FROM relationship_managers WHERE is_default = 1 LIMIT 1");
                    $stmt->execute();
                    $defaultRm = $stmt->fetch(PDO::FETCH_ASSOC);
                    if ($defaultRm) { $rmName = htmlspecialchars($defaultRm['name']); $rmDesignation = htmlspecialchars($defaultRm['designation'] ?? 'Relationship Manager'); }
                }
            } catch (Exception $e) {
                error_log("Error fetching relationship manager: " . $e->getMessage());
                $rmName = 'RM / ARM'; $rmDesignation = 'Relationship Manager';
            }

            $rmIcon = '👤';
            if (isset($rmDesignation)) {
                if (stripos($rmDesignation, 'assistant') !== false || stripos($rmDesignation, 'ARM') !== false) { $rmIcon = '👨‍💼'; }
                elseif (stripos($rmDesignation, 'manager') !== false || stripos($rmDesignation, 'RM') !== false) { $rmIcon = '👔'; }
            }
            ?>

            <!-- Client name + RM header card -->
            <div style="background:#ffffff; color:#333; padding:20px; border-radius:10px; margin-bottom:20px; box-shadow:0 2px 8px rgba(0,0,0,0.05); border-left:5px solid #0288D1; display:flex; justify-content:space-between; align-items:center; margin-top:30px;">
                <div>
                    <div style="font-size:14px; color:#666; margin-bottom:5px; text-transform:uppercase; letter-spacing:1px;">Client Portfolio</div>
                    <div style="font-size:22px; font-weight:600; color:#0288D1;"><?php echo htmlspecialchars($name); ?></div>
                </div>
                <div style="background:#f0f7ff; padding:10px 20px; border-radius:6px; font-size:14px; color:#0288D1; font-weight:500; display:flex; flex-direction:column; align-items:center; gap:4px; border:1px solid #d1e7ff; min-width:180px;">
                    <span style="font-size:12px; color:#666; opacity:0.8;"><?php echo isset($rmDesignation) ? htmlspecialchars($rmDesignation) : 'Relationship Manager'; ?></span>
                    <span style="font-size:16px; font-weight:600; display:flex; align-items:center; gap:8px;">
                        <span style="font-size:18px;"><?php echo $rmIcon; ?></span>
                        <?php echo $rmName; ?>
                    </span>
                </div>
            </div>

            <?php require_once 'client_communication.php'; ?>
            <?php $canEditAttachments = ($reportState === 'draft' || $reportState === 'ready' || $reviewNotOk == 1); ?>
            <?php include 'report_attachments.php'; ?>

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
            <span style="font-size:24px;">✓</span>
            <span>Pre-Review Compliance Checklist</span>
        </div>
        <div class="checklist-subtitle">Please confirm all the following checks before marking the report as ready for review:</div>
        <div class="checklist-item"><input type="checkbox" id="check1" class="compliance-checkbox"><label for="check1">Have you checked the Risk Profile?</label></div>
        <div class="checklist-item"><input type="checkbox" id="check2" class="compliance-checkbox"><label for="check2">Has Contact and Nominee verification been checked?</label></div>
        <div class="checklist-item"><input type="checkbox" id="check3" class="compliance-checkbox"><label for="check3">Has Tax Impact been checked?</label></div>
        <div class="checklist-item"><input type="checkbox" id="check4" class="compliance-checkbox"><label for="check4">Has there been any SIP/SWP update?</label></div>
        <div class="checklist-item"><input type="checkbox" id="check5" class="compliance-checkbox"><label for="check5">Are Annexures attached?</label></div>
        <div class="modal-buttons">
            <button type="button" class="modal-btn modal-btn-cancel"  onclick="closeComplianceModal()">Cancel</button>
            <button type="button" class="modal-btn modal-btn-confirm" id="confirmComplianceBtn" disabled onclick="confirmCompliance()">Confirm & Mark Ready</button>
        </div>
    </div>
</div>

<!-- Rejection Modal -->
<div id="rejectModal" class="modal-overlay">
    <div class="modal-box">
        <h3 style="margin-top:0; color:#dc3545;">Reject Report</h3>
        <p style="font-size:14px; color:#666; margin-bottom:15px;">Please provide a comment explaining why this report needs revision:</p>
        <textarea id="rejectComment" rows="4" style="width:100%; padding:10px; border:1px solid #ddd; border-radius:4px; font-family:inherit; resize:vertical;"></textarea>
        <div class="modal-buttons">
            <button type="button" class="modal-btn modal-btn-cancel"                            onclick="closeRejectModal()">Cancel</button>
            <button type="button" class="modal-btn modal-btn-confirm" style="background:#dc3545;" onclick="submitRejection()">Submit Rejection</button>
        </div>
    </div>
</div>

<script>
    // =============================================================
    // GLOBAL FUNCTIONS (must be at top level — called from onclick)
    // =============================================================

    function toggleDropdown() {
        const dropdown = document.getElementById('profileDropdown');
        const isVisible = dropdown.style.display === 'block';
        document.querySelectorAll('.profile-dropdown').forEach(d => { d.style.display = 'none'; });
        if (!isVisible) dropdown.style.display = 'block';
    }

    document.addEventListener('click', function(event) {
        const profilePic = document.querySelector('.profile-pic');
        const dropdown   = document.getElementById('profileDropdown');
        if (profilePic && dropdown) {
            if (!profilePic.contains(event.target) && !dropdown.contains(event.target)) {
                dropdown.style.display = 'none';
            }
        }
    });

    function showToast(msg) {
        const toast = document.getElementById('toast');
        if (!toast) return;
        toast.textContent = msg;
        toast.classList.add('show');
        setTimeout(() => toast.classList.remove('show'), 3000);
    }

    function showContextualFlash(type, message, containerId) {
        const container = document.getElementById(containerId);
        if (!container) { showToast(message); return; }
        const cssClass = type === 'success' ? 'flash-success' : 'flash-error';
        const icon     = type === 'success' ? '✅' : '❌';
        container.innerHTML = `<div class="flash-message ${cssClass}" style="opacity:1;transition:opacity 0.5s ease;">${icon} ${message}</div>`;
        setTimeout(() => {
            const msg = container.querySelector('.flash-message');
            if (msg) { msg.style.opacity = '0'; setTimeout(() => { container.innerHTML = ''; }, 500); }
        }, 3000);
    }

    function getTemplateContentById(selectorId) {
        const selector = document.getElementById(selectorId);
        if (!selector || selector.value === '0') return null;
        return selector.options[selector.selectedIndex].getAttribute('data-content');
    }

    function assembleClientMessage() {
        return ['greeting_template_selector','intro_template_selector','closing_template_selector']
            .map(getTemplateContentById).filter(Boolean).join('\n\n');
    }

    function autoResizeTextarea(element) {
        element.style.height = 'auto';
        element.style.height = (element.scrollHeight + 2) + 'px';
    }

    function showLoading() {
        const el = document.getElementById('loadingOverlay');
        if (el) el.style.display = 'flex';
    }
    function hideLoading() {
        const el = document.getElementById('loadingOverlay');
        if (el) el.style.display = 'none';
    }

    // --- WORKFLOW ---
    function submitWorkflow(action) {
        if (action === 'ready_for_review') { openComplianceModal(); return; }
        if (action === 'review_not_ok')    { openRejectModal();     return; }
        if (!confirm("Are you sure you want to perform this action?")) return;

        const wa = document.querySelector('.workflow-actions');
        if (wa) wa.innerHTML = '<div style="padding:10px;text-align:center;color:#666;">⏳ Processing...</div>';
        showLoading();

        document.getElementById('workflowActionInput').value = action;

        let inp = document.getElementById('saveReportInput');
        if (!inp) {
            inp = document.createElement('input');
            inp.type = 'hidden'; inp.name = 'save_report';
            inp.value = '1';     inp.id   = 'saveReportInput';
            document.getElementById('reportForm').appendChild(inp);
        }

        const savePromise = (typeof forceSaveRationaleBeforeSubmit === 'function')
            ? forceSaveRationaleBeforeSubmit()
            : Promise.resolve();

        savePromise.finally(() => document.getElementById('reportForm').submit());
    }

    function openComplianceModal() {
        document.querySelectorAll('.compliance-checkbox').forEach(cb => cb.checked = false);
        document.getElementById('confirmComplianceBtn').disabled = true;
        document.getElementById('complianceModal').style.display = 'flex';
    }
    function closeComplianceModal() { document.getElementById('complianceModal').style.display = 'none'; }
    function confirmCompliance() {
        if (!confirm("Are you sure you want to mark this report as ready for review?")) return;
        closeComplianceModal();
        showLoading();
        document.getElementById('workflowActionInput').value = 'ready_for_review';
        let inp = document.getElementById('saveReportInput');
        if (!inp) {
            inp = document.createElement('input');
            inp.type = 'hidden'; inp.name = 'save_report'; inp.value = '1'; inp.id = 'saveReportInput';
            document.getElementById('reportForm').appendChild(inp);
        }
        document.getElementById('reportForm').submit();
    }

    function openRejectModal() {
        document.getElementById('rejectComment').value = '';
        document.getElementById('rejectModal').style.display = 'flex';
    }
    function closeRejectModal() { document.getElementById('rejectModal').style.display = 'none'; }
    function submitRejection() {
        const comment = document.getElementById('rejectComment').value.trim();
        if (!comment) { alert("Comment is required for rejection."); return; }
        if (!confirm("Are you sure you want to reject this report?")) return;
        closeRejectModal();
        showLoading();
        document.getElementById('workflowActionInput').value = 'review_not_ok';
        document.getElementById('reviewCommentInput').value  = comment;
        let inp = document.getElementById('saveReportInput');
        if (!inp) {
            inp = document.createElement('input');
            inp.type = 'hidden'; inp.name = 'save_report'; inp.value = '1'; inp.id = 'saveReportInput';
            document.getElementById('reportForm').appendChild(inp);
        }
        document.getElementById('reportForm').submit();
    }

    // =============================================================
    // DOM READY
    // =============================================================
    document.addEventListener('DOMContentLoaded', function() {

        // Auto-resize textareas
        document.querySelectorAll('.large-textarea, .seamless-input, .rat-main-textarea').forEach(function(ta) {
            autoResizeTextarea(ta);
            ta.addEventListener('input', function() { autoResizeTextarea(this); });
            window.addEventListener('resize', function() { autoResizeTextarea(ta); });
        });

        // Fade out flash messages
        document.querySelectorAll('.flash-message').forEach(function(msg) {
            setTimeout(() => { msg.style.opacity = '0'; msg.style.marginTop = '-50px'; }, 3000);
            setTimeout(() => { msg.remove(); }, 3500);
        });

        // Delete template buttons
        document.querySelectorAll('.delete-template-btn').forEach(function(btn) {
            btn.addEventListener('click', function(e) {
                e.preventDefault();
                const selectorId      = this.getAttribute('data-template-id-attr');
                const templateSection = this.getAttribute('data-template-section');
                const selector        = document.getElementById(selectorId);
                const templateId      = selector.value;

                if (templateId === '0' || templateId === 0) {
                    showContextualFlash('error', '❌ Please select a template to delete.', templateSection + '_flash_container');
                    return;
                }
                const templateName = selector.options[selector.selectedIndex].text;
                const cid          = document.querySelector('input[name="client_id"]').value;
                if (!confirm('Are you sure you want to delete the template "' + templateName + '"?')) return;

                fetch('view_report.php?id=' + encodeURIComponent(cid), {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
                    body: new URLSearchParams({ ajax_action: 'delete_user_template', template_id: templateId })
                })
                .then(r => r.json())
                .then(data => {
                    if (data.success) {
                        showContextualFlash('success', 'Template "' + templateName + '" deleted. Reloading...', templateSection + '_flash_container');
                        window.location.href = window.location.href.split('?')[0] + '?id=' + cid + '&deleted=1#rationale_module';
                    } else {
                        showContextualFlash('error', '❌ Failed to delete: ' + data.error, templateSection + '_flash_container');
                    }
                })
                .catch(() => showContextualFlash('error', 'Network error during deletion.', templateSection + '_flash_container'));
            });
        });

        // Lock status
        const reportLocked = <?php echo json_encode($isLocked); ?>;

        // Goal status dropdowns
        if (!reportLocked) {
            document.querySelectorAll('.goal-status-dropdown').forEach(function(select) {
                select.addEventListener('change', function() {
                    const goalId    = this.getAttribute('data-goal-id');
                    const newStatus = this.value;
                    this.classList.toggle('status-on',  newStatus === 'On Track');
                    this.classList.toggle('status-off', newStatus !== 'On Track');

                    fetch('view_report.php', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
                        body: new URLSearchParams({ ajax_goal_status: '1', goal_id: goalId, status: newStatus })
                    })
                    .then(r => r.json())
                    .then(data => {
                        if (data.success) showToast('Status updated');
                        else alert(data.message || 'Failed to save status.');
                    })
                    .catch(err => console.error(err));
                });
            });
        }

        let goalsDirty = false;

        function parseShorthandNumber(value) {
            if (!value) return 0;
            value = value.toString().toLowerCase().trim()
                .replace(/^rs\.?\s*/i, '').replace(/^₹\s*/i, '');
            if (value.match(/k$/))       return parseFloat(value) * 1000;
            if (value.match(/lakh?s?$/)) return parseFloat(value) * 100000;
            if (value.match(/cr?s?$/))   return parseFloat(value) * 10000000;
            return parseFloat(value.replace(/,/g, '')) || 0;
        }

        function formatIndianNumber(num) {
            if (num >= 10000000) return 'Rs ' + (num / 10000000).toFixed(2) + ' Cr';
            if (num >= 100000)   return 'Rs ' + (num / 100000).toFixed(2)   + ' lakhs';
            if (num >= 1000)     return 'Rs ' + (num / 1000).toFixed(2)     + ' thousand';
            return 'Rs ' + num.toFixed(0);
        }

        function updateTotals() {
            let totalSip = 0, totalCurrent = 0;
            document.querySelectorAll('.goal-input').forEach(function(input) {
                const field = input.getAttribute('data-field');
                const value = parseShorthandNumber(input.value);
                if (field === 'current_amount') totalCurrent += value;
                else if (field === 'sip_swp')   totalSip     += value;
            });
            const elCurrent = document.getElementById('total-current-amount');
            const elSip     = document.getElementById('total-sip-wp');
            if (elCurrent) elCurrent.textContent = formatIndianNumber(totalCurrent);
            if (elSip)     elSip.textContent     = formatIndianNumber(totalSip);
        }

        // -------------------------------------------------------
        // FIX 1: .goal-input forEach — blur handler + forEach
        //         both correctly closed
        // -------------------------------------------------------
        document.querySelectorAll('.goal-input').forEach(function(input) {
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
                        body: new URLSearchParams({ ajax_goal_update: '1', goal_id: goalId, [field]: value })
                    })
                    .then(r => r.json())
                    .then(data => {
                        if (data.success) {
                            input.style.backgroundColor = '#e8f5e9';
                            setTimeout(() => input.style.backgroundColor = 'transparent', 500);
                            updateTotals();
                            goalsDirty = false;
                        } else if (data.message) {
                            alert(data.message);
                        }
                    });
                }); // closes blur addEventListener
            }     // closes if (!reportLocked)
        });       // closes .goal-input forEach  ← FIX 1

        updateTotals();

        // -------------------------------------------------------
        // FIX 2: saveGoalsBtn click handler correctly closed
        // -------------------------------------------------------
        const saveGoalsBtn = document.getElementById('saveGoalsBtn');
        if (saveGoalsBtn && !reportLocked) {
            saveGoalsBtn.addEventListener('click', function() {
                const btn        = this;
                const statusSpan = document.getElementById('saveGoalsStatus');
                btn.disabled    = true;
                btn.textContent = '💾 Saving...';

                const savePromises = [];
                document.querySelectorAll('.goal-input').forEach(function(input) {
                    const goalId = input.getAttribute('data-goal-id');
                    const field  = input.getAttribute('data-field');
                    const value  = input.value;

                    savePromises.push(
                        fetch('view_report.php', {
                            method: 'POST',
                            headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
                            body: new URLSearchParams({ ajax_goal_update: '1', goal_id: goalId, [field]: value })
                        })
                        .then(r => { if (!r.ok) throw new Error('HTTP ' + r.status); return r.text(); })
                        .then(text => {
                            try { return JSON.parse(text); }
                            catch(e) { throw new Error('Invalid JSON response'); }
                        })
                        .catch(err => ({ success: false, error: err.message, field, goalId }))
                    );
                });

                Promise.all(savePromises).then(results => {
                    btn.textContent = '💾 Save Goals';
                    btn.disabled    = false;

                    const allSuccess    = results.every(r => r && r.success);
                    const failedResults = results.filter(r => !r || !r.success);

                    if (allSuccess) {
                        statusSpan.textContent  = '✓ All goals saved to database';
                        statusSpan.style.color  = '#28a745';
                        statusSpan.style.display = 'inline';
                        goalsDirty = false;
                        document.querySelectorAll('.goal-input').forEach(inp => {
                            inp.style.backgroundColor = '#e8f5e9';
                            setTimeout(() => inp.style.backgroundColor = 'transparent', 1000);
                        });
                    } else {
                        statusSpan.textContent  = '⚠ ' + failedResults.length + ' field(s) failed - see red borders';
                        statusSpan.style.color  = '#dc3545';
                        statusSpan.style.display = 'inline';
                        console.error('Failed saves:', failedResults);
                        results.forEach((result, index) => {
                            const inp = document.querySelectorAll('.goal-input')[index];
                            if (!inp) return;
                            if (result && result.success) {
                                inp.style.backgroundColor = '#e8f5e9';
                                setTimeout(() => inp.style.backgroundColor = 'transparent', 1000);
                            } else {
                                inp.style.border          = '2px solid #dc3545';
                                inp.style.backgroundColor = '#ffe6e6';
                            }
                        });
                        alert('Some fields failed to save. Fields with red borders had errors.\nCheck console (F12) for details.');
                    }

                    setTimeout(() => { statusSpan.style.display = 'none'; }, 5000);
                    updateTotals();
                })
                .catch(err => {
                    btn.textContent         = '💾 Save Goals';
                    btn.disabled            = false;
                    statusSpan.textContent  = '❌ Error: ' + err.message;
                    statusSpan.style.color  = '#dc3545';
                    statusSpan.style.display = 'inline';
                    alert('Error saving goals: ' + err.message);
                });
            }); // closes saveGoalsBtn click addEventListener  ← FIX 2
        }

        // Sync save on page unload
        function saveAllGoalsSync() {
            if (reportLocked) return;
            document.querySelectorAll('.goal-input').forEach(function(input) {
                const goalId = input.getAttribute('data-goal-id');
                const field  = input.getAttribute('data-field');
                const value  = input.value;
                const xhr = new XMLHttpRequest();
                xhr.open('POST', 'view_report.php', false);
                xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
                xhr.send('ajax_goal_update=1&goal_id=' + goalId + '&' + field + '=' + encodeURIComponent(value));
            });
        }
        if (!reportLocked) {
            window.addEventListener('beforeunload', function() { if (goalsDirty) saveAllGoalsSync(); });
        }

        // -------------------------------------------------------
        // FIX 3: .action-dropdown forEach correctly closed
        // -------------------------------------------------------
        if (!reportLocked) {
            document.querySelectorAll('.action-dropdown, .scheme-input').forEach(function(element) {
                const eventType = element.classList.contains('action-dropdown') ? 'change' : 'blur';
                element.addEventListener(eventType, function() {
                    const schemeId = element.getAttribute('data-scheme-id');
                    const field    = element.getAttribute('data-field') || 'action_step';
                    const value    = element.value.trim();
                    if (!schemeId) return;

                    const postBody  = { ajax_scheme: '1', scheme_id: schemeId };
                    postBody[field] = value;

                    fetch('view_report.php', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
                        body: new URLSearchParams(postBody)
                    })
                    .then(r => r.json())
                    .then(data => {
                        if (data && data.success) showToast('Saved ' + (field === 'action_step' ? 'action step' : field.replace('_', ' ')));
                        else alert((data && (data.message || data.error)) || 'Unknown error');
                    })
                    .catch(err => console.error(err));
                });
            }); // closes .action-dropdown forEach  ← FIX 3
        }

        // Portfolio Tenure radio handler
        (function() {
            const xirrValue          = <?php echo json_encode((float)$xirr); ?>;
            const cagrText           = <?php echo json_encode(formatPercent($cagr)); ?>;
            const absoluteReturnText = <?php
                $absVal = ($absoluteReturn !== null) ? (float)$absoluteReturn : null;
                echo json_encode($absVal !== null ? formatPercent($absVal) : 'N/A');
            ?>;

            window.updateCurrentSituation = function() {
                try {
                    const selected = document.querySelector('input[name="is_older_than_1_year"]:checked');
                    if (!selected) return;

                    const val             = selected.value;
                    const returnLabel     = document.getElementById('returnLabel');
                    const returnValueCell = document.getElementById('returnValueCell');
                    const xirrRow         = document.getElementById('xirrRow');

                    if (val === '0') {
                        if (returnLabel)     returnLabel.textContent = 'Absolute Return of schemes';
                        if (returnValueCell) {
                            returnValueCell.value = absoluteReturnText;
                            returnValueCell.setAttribute('data-field', 'absolute_return');
                            returnValueCell.setAttribute('data-raw', <?php echo json_encode((float)$absoluteReturn); ?>);
                        }
                        if (xirrRow) xirrRow.style.display = 'none';
                    } else {
                        if (returnLabel)     returnLabel.textContent = 'CAGR of current schemes';
                        if (returnValueCell) {
                            returnValueCell.value = cagrText;
                            returnValueCell.setAttribute('data-field', 'cagr');
                            returnValueCell.setAttribute('data-raw', <?php echo json_encode((float)$cagr); ?>);
                        }
                        if (xirrRow) {
                            xirrRow.style.display = (xirrValue && !isNaN(xirrValue) && Number(xirrValue) !== 0) ? '' : 'none';
                        }
                    }
                } catch(e) { console.error('updateCurrentSituation error', e); }
            };

            document.querySelectorAll('input[name="is_older_than_1_year"]').forEach(function(r) {
                r.addEventListener('change', window.updateCurrentSituation);
            });
            document.addEventListener('change', function(e) {
                if (e.target && e.target.name === 'is_older_than_1_year') window.updateCurrentSituation();
            });
            window.updateCurrentSituation();
        })();

        // Compliance checklist — enable confirm button only when all checked
        const checkboxes = document.querySelectorAll('.compliance-checkbox');
        const confirmBtn = document.getElementById('confirmComplianceBtn');
        if (checkboxes.length > 0 && confirmBtn) {
            checkboxes.forEach(function(cb) {
                cb.addEventListener('change', function() {
                    confirmBtn.disabled = !Array.from(checkboxes).every(c => c.checked);
                });
            });
        }

        // Form submission — show loading overlay
        document.getElementById('reportForm').addEventListener('submit', function() {
            const action = document.getElementById('workflowActionInput').value;
            if (action) {
                showLoading();
                document.querySelectorAll('.wf-btn').forEach(btn => { btn.disabled = true; });
            }
        });

        hideLoading();

    }); // closes DOMContentLoaded
</script>

<!-- Loading Overlay -->
<div id="loadingOverlay" class="loading-overlay">
    <div class="loading-spinner"></div>
    <div style="margin-top:20px; color:#3498db; font-weight:600;">Processing workflow action...</div>
</div>

</body>
</html>