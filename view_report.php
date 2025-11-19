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

/* ---------- DATABASE HELPER FUNCTIONS ---------- */

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
            $updates[] = 'recommended_amount = :recommended_amount';
            $params[':recommended_amount'] = (float)$_POST['recommended_amount'];
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

/* ---------- AJAX EDIT HANDLER ---------- */

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ajax']) && $_POST['ajax'] === '1') {
    header('Content-Type: application/json');

    try {
        $pdo      = getPdo();
        $clientId = (int)($_POST['client_id'] ?? 0);
        $field    = $_POST['field'] ?? '';
        $value    = $_POST['value'] ?? '';

        if (!$clientId || !in_array($field, ['greeting','intro','closing','rationale','client_message','signature'], true)) {
            throw new Exception('Invalid input');
        }

        // Handle merged client message
        if ($field === 'client_message') {
            // Parse the message to extract parts
            $lines = explode("\n\n", $value);
            
            // First paragraph = greeting
            $greeting = isset($lines[0]) ? trim($lines[0]) : '';
            
            // Middle paragraphs = intro
            $introParts = array_slice($lines, 1, -1);
            $intro = !empty($introParts) ? implode("\n\n", $introParts) : '';
            
            // Last paragraph = closing
            $closing = isset($lines[count($lines) - 1]) && count($lines) > 1 ? trim($lines[count($lines) - 1]) : '';
            
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

        switch ($field) {
            case 'greeting':
                $column = 'greeting_prefix';
                break;
            case 'intro':
                $column = 'intro_text';
                break;
            case 'closing':
                $column = 'closing_text';
                break;
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

            // Parse client message into greeting, intro, closing
            $lines = explode("\n\n", $clientMessage);
            $greeting = isset($lines[0]) ? trim($lines[0]) : '';
            $introParts = array_slice($lines, 1, -1);
            $intro = !empty($introParts) ? implode("\n\n", $introParts) : '';
            $closing = isset($lines[count($lines) - 1]) && count($lines) > 1 ? trim($lines[count($lines) - 1]) : '';

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
                        ':id' => (int)$schemeId,
                    ]);
                }
            }

            // Save action steps if provided
            if (isset($_POST['action_step']) && is_array($_POST['action_step'])) {
                foreach ($_POST['action_step'] as $schemeId => $actionStep) {
                    $stmt = $pdo->prepare("
                        UPDATE client_schemes 
                        SET action_step = :action_step
                        WHERE id = :id
                    ");
                    $stmt->execute([
                        ':action_step' => $actionStep,
                        ':id' => (int)$schemeId,
                    ]);
                }
            }

            header('Location: view_report.php?id=' . $clientId . '&saved=1');
            exit;
        } catch (Exception $e) {
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
$DEFAULT_SIGNATURE = "Regards,\n\nVivek Sharma,\nRelationship Manager,\nFinance Doctor Private Limited.\n\nMobile - 888 4091 666.\nEmail - vivek.sharma@financedoctor.in\nUrl: www.financedoctor.in";

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
    
    <!-- Modern Fonts -->
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;600&family=Inter:wght@400;500;600&display=swap" rel="stylesheet">
    
    <!-- Modern Styling -->
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
    <div class="flash-success">Email sent successfully.</div>
<?php elseif (isset($_GET['sent_error']) && $_GET['sent_error'] == '1'): ?>
    <div class="flash-error">Failed to send email. Please check SMTP settings.</div>
<?php elseif (isset($_GET['saved']) && $_GET['saved'] == '1'): ?>
    <div class="flash-success">✅ Report saved successfully!</div>
<?php elseif (isset($_GET['save_error']) && $_GET['save_error'] == '1'): ?>
    <div class="flash-error">❌ Failed to save report. Please try again.</div>
<?php endif; ?>

<!-- Email form for this client -->
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

    <!-- ✅ Wrap everything in a form for batch save -->
    <form method="POST" id="reportForm">
        <input type="hidden" name="client_id" value="<?php echo (int)$clientId; ?>">

        <!-- ✅ Client Communication textarea -->
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

        <!-- ✅ Rationale -->
        <div class="card" style="margin-top: 20px;">
            <label class="card-title">Rationale</label>
            <textarea name="rationale"
                      class="large-textarea" 
                      data-field="rationale" 
                      data-client-id="<?php echo (int)$clientId; ?>"
                      placeholder="Write your rationale here..."><?php echo htmlspecialchars($rationaleText); ?></textarea>
        </div>

        <!-- ✅ Signature -->
        <div class="card" style="margin-top: 20px;">
            <label class="card-title">Signature / Closing Note</label>
            <textarea name="signature_block"
                      class="large-textarea" 
                      data-field="signature" 
                      data-client-id="<?php echo (int)$clientId; ?>"
                      placeholder="Write your signature block here..."><?php echo htmlspecialchars($signatureBlock); ?></textarea>
        </div>

        <!-- ✅ Save Button -->
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
    function showToast(msg) {
        const toast = document.getElementById('toast');
        toast.textContent = msg;
        toast.classList.add('show');
        setTimeout(() => {
            toast.classList.remove('show');
        }, 2000);
    }

    // ✅ Auto-save textareas on blur
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
                        alert('Save failed: ' . (data.error || 'Unknown error'));
                    }
                })
                .catch(err => {
                    console.error('Save error:', err);
                });
            }
        });
    });

    // ✅ Auto-save dropdowns
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

    // ✅ Auto-save scheme inputs (text and number)
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
                        alert('Save failed: ' . (data.error || 'Unknown error'));
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