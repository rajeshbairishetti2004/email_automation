<?php
// view_report.php
// - Shows single report with next/prev navigation, print button
// - Inline editable fields with AJAX -> DB
// - Send current client report by email using PHPMailer

require_once 'login.php';
require_once 'db_config.php';
require_once 'email_handler.php';
require_once 'renderers.php';
require_once 'env_loader.php'; // Add this line to load environment variables

requireAuth();

$pdo = getPdo();
$clientId = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if ($clientId <= 0) {
    header('Location: view_saved_reports.php');
    exit;
}

/* ---------- DATABASE HELPER FUNCTIONS (Existing) ---------- */

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


/* ---------- AJAX SCHEME UPDATE HANDLER ---------- */

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ajax_scheme']) && $_POST['ajax_scheme'] === '1') {
    header('Content-Type: application/json');

    try {
        $pdo = getPdo();
        $schemeId = (int)($_POST['scheme_id'] ?? 0);

        if (!$schemeId) {
            throw new Exception('Invalid scheme ID');
        }

        $updates = [];
        $params = [':id' => $schemeId];

        if (isset($_POST['action_step'])) {
            $updates[] = 'action_step = :action_step';
            $params[':action_step'] = $_POST['action_step'];
        }

        if (isset($_POST['recommended_scheme'])) {
            $updates[] = 'recommended_scheme = :recommended_scheme';
            $params[':recommended_scheme'] = $_POST['recommended_scheme'];
        }

        if (isset($_POST['recommended_amount'])) {
            $amount = (float)$_POST['recommended_amount'];
            $updates[] = 'recommended_amount = :recommended_amount';
            $params[':recommended_amount'] = $amount;
        }

        if (!empty($updates)) {
            $sql = "UPDATE client_schemes SET " . implode(', ', $updates) . " WHERE id = :id";
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
        }

        echo json_encode(['success' => true]);
    } catch (Throwable $e) {
        echo json_encode([
            'success' => false,
            'error' => $e->getMessage(),
        ]);
    }
    exit;
}


/* ---------- AJAX RM LOADER HANDLER (NEW) ---------- */

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ajax_action']) && $_POST['ajax_action'] === 'load_rm') {
    header('Content-Type: application/json');

    try {
        // RM functions are now available via db_config.php
        $rmId = (int)($_POST['rm_id'] ?? 0);
        
        $pdo = getPdo();
        $stmt = $pdo->prepare("SELECT * FROM relationship_managers WHERE id = :rm_id");
        $stmt->execute([':rm_id' => $rmId]);
        $rm = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$rm) {
            throw new Exception("Relationship Manager not found for ID: " . $rmId);
        }

        // Generate the default signature block from the RM's details (Function defined in db_config.php)
        $newSignature = generateSignatureBlock($rm);

        echo json_encode([
            'success' => true,
            'signature_block' => $newSignature,
            'rm_name' => $rm['name']
        ]);

    } catch (Throwable $e) {
        error_log("RM Load AJAX Error: " . $e->getMessage());
        echo json_encode([
            'success' => false,
            'error' => $e->getMessage(),
        ]);
    }
    exit;
}


/* ---------- AJAX DELETE RM HANDLER (NEW) ---------- */

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ajax_action']) && $_POST['ajax_action'] === 'delete_rm') {
    header('Content-Type: application/json');

    try {
        $rmId = (int)($_POST['rm_id'] ?? 0);
        
        if ($rmId <= 0) {
            throw new Exception("Invalid RM ID for deletion.");
        }
        
        $rmCount = getRelationshipManagerCount();
        if ($rmCount <= 1) {
            throw new Exception("Cannot delete: At least one Relationship Manager must remain in the system.");
        }
        
        $pdo = getPdo();
        
        // 1. Check if the RM being deleted is the current default
        $stmtCheck = $pdo->prepare("SELECT is_default FROM relationship_managers WHERE id = :rm_id");
        $stmtCheck->execute([':rm_id' => $rmId]);
        $isDefault = $stmtCheck->fetchColumn();

        // 2. Delete the RM
        $stmtDelete = $pdo->prepare("DELETE FROM relationship_managers WHERE id = :rm_id");
        $stmtDelete->execute([':rm_id' => $rmId]);
        
        // 3. If the deleted RM was the default, set a new default
        if ($isDefault == 1) {
            $pdo->exec("UPDATE relationship_managers SET is_default = 1 ORDER BY id ASC LIMIT 1");
        }

        echo json_encode([
            'success' => true,
            'rm_id' => $rmId
        ]);

    } catch (Throwable $e) {
        error_log("RM Delete AJAX Error: " . $e->getMessage());
        echo json_encode([
            'success' => false,
            'error' => $e->getMessage(),
        ]);
    }
    exit;
}


/* ---------- AJAX EDIT HANDLER (For textareas) ---------- */

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ajax']) && $_POST['ajax'] === '1') {
    header('Content-Type: application/json');

    try {
        $pdo      = getPdo();
        $clientId = (int)($_POST['client_id'] ?? 0);
        $field    = $_POST['field'] ?? '';
        $value    = $_POST['value'] ?? '';

        if (!$clientId || !in_array($field, ['client_message', 'rationale', 'signature'], true)) {
            throw new Exception("Invalid input. Client ID: {$clientId}, Field: {$field}");
        }

        $sql = '';
        $params = [':id' => $clientId];

        // Handle merged client message: update three separate columns
        if ($field === 'client_message') {
            
            // Parse the message to extract parts
            $lines = explode("\n\n", $value);
            
            // First paragraph = greeting
            $greeting = isset($lines[0]) ? trim($lines[0]) : '';
            
            // Last paragraph = closing (if more than one line)
            $closing = (count($lines) > 1 && $lines[count($lines) - 1] !== $greeting) ? trim($lines[count($lines) - 1]) : '';
            
            // Middle paragraphs = intro (all lines between greeting and closing)
            $introParts = array_slice($lines, 1, count($lines) - (empty($closing) ? 1 : 2));
            $intro = !empty($introParts) ? implode("\n\n", $introParts) : '';
            
            // Fallback for simple message without clear paragraph splits
            if (strpos(strtolower($greeting), 'dear') === false && strpos($greeting, ',') === false) {
                $intro = $value;
                $greeting = $closing = '';
            }

            // Update all three fields
            $sql = "UPDATE clients SET greeting_prefix = :greeting, intro_text = :intro, closing_text = :closing WHERE id = :id";
            $stmt = $pdo->prepare($sql);
            $stmt->execute([
                ':greeting' => $greeting,
                ':intro'    => $intro,
                ':closing'  => $closing,
                ':id'       => $clientId,
            ]);
            
            echo json_encode(['success' => true]);
            exit;
        }

        // Handle Rationale and Signature: update a single column
        switch ($field) {
            case 'rationale':
                $column = 'rationale_text';
                break;
            case 'signature':
                $column = 'signature_block';
                break;
            default:
                throw new Exception('Unknown field');
        }

        $sql = "UPDATE clients SET {$column} = :val WHERE id = :id";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            ':val' => $value,
            ':id'  => $clientId,
        ]);

        echo json_encode(['success' => true]);
    } catch (Throwable $e) {
        echo json_encode([
            'success' => false,
            'error'   => $e->getMessage(),
        ]);
    }
    exit;
}

/* ---------- HANDLE ADD NEW RM REQUEST (NEW) ---------- */

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

/* ---------- HANDLE "SEND EMAIL" REQUEST ---------- */

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['send_email']) && $_POST['send_email'] == '1') {
    handleEmailSending($clientId);
    exit;
}

/* ---------- HANDLE "SAVE REPORT" REQUEST ---------- */

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

/* ---------- LOAD AND DISPLAY CLIENT REPORT ---------- */

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

// Get RM data
$rm = getDefaultRelationshipManager();

// Get ALL RMs for the dropdown
$allRMs = getAllRelationshipManagers();

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

// DYNAMIC DEFAULT SIGNATURE BLOCK
$rmName        = $rm['name'] ?? 'Relationship Manager';
$rmDesignation = $rm['designation'] ?? 'Relationship Manager';
$rmMobile      = $rm['mobile'] ?? 'N/A';
$rmEmail       = $rm['email'] ?? 'N/A';

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
$signatureBlock = $signatureStored !== '' ? $signatureStored : $DEFAULT_SIGNATURE;

?>
<!DOCTYPE html>
<html>
<head>
    <title>Client Report - <?php echo htmlspecialchars($name); ?></title>
    
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;600&family=Inter:wght@400;500;600&display=swap" rel="stylesheet">
    
    <link rel="stylesheet" href="public/css/styles.css">
    
    <style>
        /* Additional specific styles for view_report.php */
        .report-table {
            width: 70%;
            margin: 0 auto 20px 0;
        }
        .report-table.small {
            width: 40%;
        }
        
        .client-report {
            page-break-after: always;
        }
        /* CSS for smooth flash message transition */
        .flash-message {
            padding: 10px;
            border-radius: 4px;
            margin-bottom: 20px;
            opacity: 1;
            transition: opacity 0.5s ease-out, margin-top 0.5s ease-out;
            margin-top: 0;
            /* Added for contextual messages */
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
        /* New Button Styling */
        .rm-action-button {
            display: inline-block;
            padding: 4px 8px;
            background-color: #007bff;
            color: white !important;
            border-radius: 4px;
            text-decoration: none;
            font-size: 13px;
            margin-left: 10px;
            transition: background-color 0.2s;
            line-height: normal;
        }
        .rm-action-button:hover {
            background-color: #0056b3;
            text-decoration: none;
        }
        .delete-rm-btn {
            color: red !important;
            font-weight: 600;
            text-decoration: none;
            padding: 2px 4px;
            border: 1px solid #f0f0f0;
            border-radius: 3px;
        }
        .delete-rm-btn:hover {
            background-color: #ffe6e6;
            text-decoration: none;
        }
        .rm-list-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 5px 0;
            border-bottom: 1px dashed #eee;
        }
    </style>
</head>
<body>

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
    <form method="post" enctype="multipart/form-data" style="display: inline-flex; gap: 8px; align-items: center; flex-wrap: wrap;">
        <input type="hidden" name="send_email" value="1">
        <input type="hidden" name="client_id" value="<?php echo (int)$clientId; ?>">

        <input type="email" name="recipient_email" multiple
               required
               placeholder="Enter one or more emails, separated by commas"
               style="padding: 4px 8px; font-size: 13px; width: 350px;">

        <div style="display: flex; align-items: center; gap: 8px;">
            <label for="attachments" style="font-size: 12px; color: #666; cursor: pointer;">
                📎 Attach Files (Multiple):
            </label>
            <input type="file" 
                   name="attachments[]" 
                   id="attachments"
                   accept=".pdf,.xlsx,.xls,.doc,.docx,.jpg,.jpeg,.png,.gif"
                   multiple
                   style="font-size: 12px;">
        </div>

        <button type="submit" class="nav-button" style="padding: 4px 10px;">
            Send Report by Email
        </button>
    </form>
</div>

<h1>Client Report</h1>
<h2><?php echo htmlspecialchars($name); ?></h2>

<div class="client-report" data-client-id="<?php echo (int)$clientId; ?>">

    <form method="POST" id="reportForm">
        <input type="hidden" name="client_id" value="<?php echo (int)$clientId; ?>">

        <div class="card">
            <label class="card-title">Client Communication</label>
            <textarea name="client_message"
                      class="large-textarea" 
                      data-field="client_message" 
                      data-client-id="<?php echo (int)$clientId; ?>"
                      placeholder="Write your greeting, introduction, and closing remarks here..."><?php echo htmlspecialchars($clientMessage); ?></textarea>
            <p style="font-size: 12px; color: #666; margin-top: 8px;">
                💡 This message will appear at the top of the email and printed report
            </p>
        </div>

        <h3>1. Current Situation</h3>
        <table class="report-table">
            <tr><th colspan="2">Current Situation</th></tr>
            <tr>
                <td>Total Amount as of <?php echo htmlspecialchars($asOn); ?></td>
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

        <div class="card" style="margin-top: 20px;">
            <label class="card-title">Rationale</label>
            <textarea name="rationale"
                      class="large-textarea" 
                      data-field="rationale" 
                      data-client-id="<?php echo (int)$clientId; ?>"
                      placeholder="Write your rationale here..."><?php echo htmlspecialchars($rationaleText); ?></textarea>
        </div>

        <div class="card" style="margin-top: 20px;">
            <label class="card-title">Signature / Closing Note</label>
            
            <?php if (count($allRMs) === 0): ?>
                <div class="signature-flash-container">
                    <div class="flash-message flash-error" style="opacity: 1; margin-top: 5px;">
                        You must **add a Relationship Manager** before using the dynamic signature feature.
                    </div>
                </div>
                <form method="POST" style="padding: 15px; border: 1px dashed #DDD;">
                    <input type="hidden" name="action_add_rm" value="1">
                    <input type="hidden" name="client_id" value="<?php echo (int)$clientId; ?>">
                    <h4 style="margin-top: 0; margin-bottom: 10px;">Add New Relationship Manager</h4>
                    
                    <input type="text" name="rm_name" placeholder="Name (e.g., Vivek Sharma)" required style="margin-bottom: 8px;">
                    <input type="text" name="rm_designation" placeholder="Designation (e.g., Relationship Manager)" value="Relationship Manager" style="margin-bottom: 8px;">
                    <input type="text" name="rm_mobile" placeholder="Mobile (e.g., 888 4091 666)" required style="margin-bottom: 8px;">
                    <input type="email" name="rm_email" placeholder="Email (e.g., vivek.sharma@...)" required style="margin-bottom: 15px;">
                    
                    <button type="submit" class="rm-action-button" style="width: auto;">
                        ➕ Add & Set as Default
                    </button>
                    <p style="font-size: 12px; color: #999; margin-top: 5px;">
                        The first RM added will be set as the default automatically.
                    </p>
                </form>

            <?php else: ?>
                <div id="signature_flash_container" class="signature-flash-container">
                    <?php if (isset($_GET['rm_added'])): ?>
                        <div class="flash-message flash-success" style="opacity: 1;">✅ Relationship Manager added successfully!</div>
                    <?php elseif (isset($_GET['rm_add_error'])): ?>
                        <div class="flash-message flash-error" style="opacity: 1;">❌ Failed to add RM: <?php echo htmlspecialchars($_GET['rm_add_error']); ?></div>
                    <?php endif; ?>
                </div>

                <div style="margin-bottom: 10px; display: flex; align-items: center; flex-wrap: wrap;">
                    <label for="rm_selector" style="font-size: 14px; font-weight: normal; margin-top: 0; margin-right: 10px;">
                        Select Default RM:
                    </label>
                    <select id="rm_selector" data-client-id="<?php echo (int)$clientId; ?>" style="width: 160px; padding: 5px;">
                        <option value="0">--- Use Saved Text ---</option>
                        <?php foreach ($allRMs as $currentRM): ?>
                            <option value="<?php echo (int)$currentRM['id']; ?>"
                                    data-name="<?php echo htmlspecialchars($currentRM['name']); ?>">
                                <?php echo htmlspecialchars($currentRM['name']); ?>
                                <?php echo ($currentRM['is_default'] == 1) ? ' (Default)' : ''; ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <a href="#" id="add_rm_toggle_btn" class="rm-action-button" style="margin-left: 10px;">
                        + Add New RM
                    </a>
                    <a href="#" id="view_rm_list_toggle" class="rm-action-button" style="margin-left: 10px;">
                        View/Delete RMs
                    </a>
                </div>
                
                <div id="rm_management_list" style="display: none; padding: 15px; border: 1px dashed #DDD; margin-bottom: 15px;">
                    <h4 style="margin-top: 0; margin-bottom: 10px;">Manage Relationship Managers</h4>
                    <ul style="list-style: none; padding: 0; margin: 0;">
                        <?php foreach ($allRMs as $rmItem): ?>
                            <li class="rm-list-item" data-rm-id="<?php echo (int)$rmItem['id']; ?>">
                                <span>
                                    <strong><?php echo htmlspecialchars($rmItem['name']); ?></strong>
                                    (<?php echo htmlspecialchars($rmItem['designation']); ?>)
                                    <?php echo ($rmItem['is_default'] == 1) ? ' <span style="color: green; font-weight: 600;">(Default)</span>' : ''; ?>
                                </span>
                                <a href="#" 
                                   class="delete-rm-btn" 
                                   data-rm-id="<?php echo (int)$rmItem['id']; ?>" 
                                   data-rm-name="<?php echo htmlspecialchars($rmItem['name']); ?>" 
                                   title="Delete this Relationship Manager">
                                    [Delete]
                                </a>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                </div>

                <div id="add_rm_container" style="display: none; padding: 15px; border: 1px dashed #DDD; margin-bottom: 15px;">
                    <form method="POST">
                        <input type="hidden" name="action_add_rm" value="1">
                        <input type="hidden" name="client_id" value="<?php echo (int)$clientId; ?>">
                        <h4 style="margin-top: 0; margin-bottom: 10px;">Add New Relationship Manager</h4>
                        
                        <input type="text" name="rm_name" placeholder="Name (Required)" required style="margin-bottom: 8px;">
                        <input type="text" name="rm_designation" placeholder="Designation" value="Relationship Manager" style="margin-bottom: 8px;">
                        <input type="text" name="rm_mobile" placeholder="Mobile (Required)" required style="margin-bottom: 8px;">
                        <input type="email" name="rm_email" placeholder="Email (Required)" required style="margin-bottom: 15px;">
                        <button type="submit" class="rm-action-button" style="width: auto;">
                            ➕ Add RM
                        </button>
                    </form>
                </div>

                <textarea name="signature_block"
                        class="large-textarea" 
                        data-field="signature" 
                        data-client-id="<?php echo (int)$clientId; ?>"
                        id="signature_textarea"
                        placeholder="Write your signature block here..."><?php echo htmlspecialchars($signatureBlock); ?></textarea>
            <?php endif; ?>
        </div>

        <div style="margin-top: 30px; text-align: right; padding-bottom: 20px;">
            <button type="submit" name="save_report" class="btn-primary">
                💾 Save
            </button>
        </div>

    </form>

    <?php if ($annexures): ?>
        <h3>Annexures</h3>
        <ul>
            <?php foreach ($annexures as $ax): ?>
                <li><?php echo htmlspecialchars($ax['line_text']); ?></li>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>

</div>

<div id="toast" class="toast"></div>

<script>
    // Note: The showToast function is for general use, while showContextualFlash is for specific blocks.
    function showToast(msg) {
        const toast = document.getElementById('toast');
        toast.textContent = msg;
        toast.classList.add('show');
        setTimeout(() => {
            toast.classList.remove('show');
        }, 2000);
    }

    // Function to display a contextual flash message
    function showContextualFlash(type, message, containerId = 'signature_flash_container') {
        const container = document.getElementById(containerId);
        if (!container) return;

        // Clear existing messages in the container
        container.innerHTML = '';
        
        const div = document.createElement('div');
        div.className = `flash-message flash-${type}`;
        div.style.opacity = '1';
        div.textContent = message;
        
        container.appendChild(div);

        // Set the timer for disappearance
        setTimeout(() => {
            div.style.opacity = '0';
            div.style.marginTop = '-50px'; 
        }, 3000); 
        setTimeout(() => {
            div.remove();
        }, 3500); 
    }

    // New: Handle disappearing top page flash messages
    document.addEventListener('DOMContentLoaded', function() {
        const flashMessages = document.querySelectorAll('.flash-message');
        
        flashMessages.forEach(function(message) {
            // Only handle top-level messages (those not inside the signature card)
            if (message.closest('.card')) {
                // Handle contextual messages inside the card (like rm_added message)
                const container = message.closest('.signature-flash-container');
                if (container) {
                     setTimeout(() => {
                        message.style.opacity = '0';
                        message.style.marginTop = '-50px'; 
                    }, 3000); 
                    setTimeout(() => {
                        message.remove();
                    }, 3500); 
                }
                return; 
            }

            // Handle main page flash messages
            setTimeout(() => {
                message.style.opacity = '0';
                message.style.marginTop = '-50px'; 
            }, 3000); 

            setTimeout(() => {
                message.remove();
            }, 3500); 
        });
    });

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
                    showContextualFlash('success', `✅ RM ${rmName} deleted. Reloading list...`);
                    // Reload the page to reflect changes in dropdown and list
                    window.location.reload(); 
                } else {
                    showContextualFlash('error', `❌ Failed to delete RM: ${data.error}`);
                }
            })
            .catch(err => {
                showContextualFlash('error', 'Network error during deletion.');
                console.error('Delete Error:', err);
            });
        }
    });

    // Auto-save textareas on blur
    document.querySelectorAll('.large-textarea').forEach(function(textarea) {
        textarea.addEventListener('blur', function() {
            const clientId = textarea.getAttribute('data-client-id');
            const field = textarea.getAttribute('data-field');
            const value = textarea.value.trim();

            if (clientId && field) {
                fetch('view_report.php?id=' + encodeURIComponent(clientId), {
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

    // NEW: Handle RM Selector Change (Loads RM signature into the textarea)
    const rmSelector = document.getElementById('rm_selector');
    if (rmSelector) {
        rmSelector.addEventListener('change', function() {
            const rmId = this.value;
            const textarea = document.getElementById('signature_textarea');
            const clientId = this.getAttribute('data-client-id');
            
            if (rmId > 0) {
                // Load RM signature block via AJAX
                fetch('view_report.php?id=' + encodeURIComponent(clientId), {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8'
                    },
                    body: new URLSearchParams({
                        ajax_action: 'load_rm', // Target the new RM loader endpoint
                        rm_id: rmId
                    })
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        textarea.value = data.signature_block;
                        showContextualFlash('success', `✅ Loaded signature for ${data.rm_name}. Auto-saving...`);
                        
                        // Manually trigger the auto-save mechanism for the signature field
                        const signatureData = new URLSearchParams({
                            ajax: '1',
                            client_id: clientId,
                            field: 'signature',
                            value: data.signature_block
                        });
                        
                        fetch('view_report.php?id=' + encodeURIComponent(clientId), {
                            method: 'POST',
                            body: signatureData
                        });

                    } else {
                        showContextualFlash('error', `❌ Error loading signature: ${data.error}`);
                    }
                })
                .catch(err => {
                    showContextualFlash('error', 'Network error loading RM data.');
                    console.error('RM Load Error:', err);
                });
            } else {
                // Option "--- Use Saved Text ---" selected (ID 0). 
                showContextualFlash('success', 'Using client-specific saved signature.');
                // Trigger blur to save the text currently in the box (if edited)
                textarea.dispatchEvent(new Event('blur'));
            }
        });
    }

    // Existing auto-save scripts for dropdowns and inputs (unchanged)
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
                        showToast('Action step saved');
                    }
                })
                .catch(err => console.error(err));
            }
        });
    });

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
</script>

</body>
</html>