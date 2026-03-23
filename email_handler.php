<?php
// email_handler.php
require_once __DIR__ . '/vendor/autoload.php';
require_once 'db_config.php';
require_once 'env_loader.php';
require_once 'followup_email_handler.php';


use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

function handleEmailSending($clientId)
{
    $pdo = getPdo();

    // --- BACKEND SECURITY: Verify report state before proceeding ---
    $stmt = $pdo->prepare("SELECT report_state, review_not_ok FROM clients WHERE id = :id");
    $stmt->execute([':id' => $clientId]);
    $stateCheck = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$stateCheck || $stateCheck['report_state'] !== 'reviewed' || $stateCheck['review_not_ok'] != 0) {
        // State is not 'reviewed' or report is rejected - deny access
        header('Location: view_report.php?id=' . $clientId . '&error=permission_denied');
        exit;
    }

    // --- Read email fields from the form ---
    $toEmail = trim($_POST['recipient_email'] ?? '');
    $ccEmailsRaw = trim($_POST['cc_emails'] ?? '');
    $fromEmailSender = trim($_POST['from_email'] ?? '');

    if (!filter_var($fromEmailSender, FILTER_VALIDATE_EMAIL)) {
        header('Location: view_report.php?id=' . $clientId . '&sent_error=1&msg=Invalid sender email');
        exit;
    }


    $selectedFromName  = trim($_POST['from_name'] ?? '');

    // Combine To and CC emails into a single validation/logging list
    $rawEmailList = [];
    if (!empty($toEmail)) $rawEmailList[] = $toEmail;

    $ccList = array_filter(
        array_map('trim', preg_split('/[;,]+/', $ccEmailsRaw)),
        function ($email) {
            return filter_var($email, FILTER_VALIDATE_EMAIL);
        }
    );


    $emailList = array_merge($rawEmailList, $ccList);

    $emailList = array_unique($emailList);


    if ($clientId <= 0 || empty($emailList) || empty($fromEmailSender)) {
        // Redirect if no recipients or no sender is specified
        $errorMsg = '';
        if ($clientId <= 0) $errorMsg = 'Invalid client ID';
        elseif (empty($fromEmailSender)) $errorMsg = 'Sender email is required';
        elseif (empty($toEmail)) $errorMsg = 'Recipient email is required';

        header('Location: view_report.php?id=' . $clientId . '&sent_error=1&msg=' . urlencode($errorMsg));
        exit;
    }

    // Get email configuration from environment variables
    $smtpHost = $_ENV['SMTP_HOST'] ?? 'smtp.gmail.com';
    $smtpPort = $_ENV['SMTP_PORT'] ?? 587;
    $smtpUsername = $_ENV['SMTP_USERNAME'] ?? '';
    $smtpPassword = $_ENV['SMTP_PASSWORD'] ?? '';

    // Validate SMTP credentials
    if (empty($smtpUsername) || empty($smtpPassword)) {
        error_log("SMTP credentials missing in environment variables");
        header('Location: view_report.php?id=' . $clientId . '&sent_error=1&msg=' . urlencode('SMTP configuration is missing. Please contact administrator.'));
        exit;
    }


    // --- 1. Handle New/Temporary Attachments (from the Send Email form) ---
    $attachmentPaths = [];
    $attachmentNames = [];

    $uploadDir = $_ENV['UPLOAD_PATH'] ?? (__DIR__ . '/uploads');
    if (!is_dir($uploadDir)) {
        mkdir($uploadDir, 0777, true);
    }

    // Check if new files were uploaded right now via the email form
    if (isset($_FILES['attachments']) && !empty($_FILES['attachments']['name'][0])) {
        $uploadedFiles = $_FILES['attachments'];

        for ($i = 0; $i < count($uploadedFiles['name']); $i++) {
            if ($uploadedFiles['error'][$i] === UPLOAD_ERR_OK) {
                $tmpName = $uploadedFiles['tmp_name'][$i];
                $originalName = basename($uploadedFiles['name'][$i]);
                $ext = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));

                // Allow PDF, Excel, Word, and common image formats
                $allowedExts = ['pdf', 'xlsx', 'xls', 'doc', 'docx', 'jpg', 'jpeg', 'png', 'gif'];

                if (in_array($ext, $allowedExts)) {
                    $safeName = uniqid() . '_' . $originalName;
                    $savePath = $uploadDir . '/' . $safeName;

                    if (move_uploaded_file($tmpName, $savePath)) {
                        $attachmentPaths[] = $savePath;
                        $attachmentNames[] = $originalName;
                    }
                }
            }
        }
    }

    // Load client + related data
    $stmt = $pdo->prepare("SELECT * FROM clients WHERE id = :id");
    $stmt->execute([':id' => $clientId]);
    $client = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$client) {
        header('Location: view_report.php?id=' . $clientId . '&sent_error=1&msg=' . urlencode('Client not found'));
        exit;
    }

    if (!function_exists('formatAnnexureLabel')) {
        // Map filenames to descriptive labels with dates
        function formatAnnexureLabel($filename, $clientName = '')
        {
            $name = pathinfo($filename, PATHINFO_FILENAME);
            if ($name === '') {
                return $filename;
            }
            return $name;
        }
    }

    $stmt = $pdo->prepare("SELECT * FROM client_goals WHERE client_id = :id ORDER BY id ASC");
    $stmt->execute([':id' => $clientId]);
    $goals = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $stmt = $pdo->prepare("SELECT * FROM client_allocations WHERE client_id = :id ORDER BY id ASC");
    $stmt->execute([':id' => $clientId]);
    $allocations = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // UPDATED: Fetch schemes with latest saved data including action_step, recommended_scheme, recommended_amount
    $stmt = $pdo->prepare("SELECT * FROM client_schemes WHERE client_id = :id ORDER BY id ASC");
    $stmt->execute([':id' => $clientId]);
    $schemes = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $stmt = $pdo->prepare("SELECT * FROM client_annexures WHERE client_id = :id ORDER BY id ASC");
    $stmt->execute([':id' => $clientId]);
    $annexures = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Initialize Annexures List for the Email Body (must mirror report attachments shown in View Report)
    $emailAnnexures = [];

    // --- 2. Use only persistent Report Attachments (uploads/attachments/client_{id}) ---
    $persistentAttDir = __DIR__ . '/uploads/attachments/client_' . $clientId;

    if (is_dir($persistentAttDir)) {
        $pFiles = scandir($persistentAttDir);
        $sortedFiles = [];
        $inceptionFile = null;

        // Separate inception portfolio file from others
        foreach ($pFiles as $pf) {
            if ($pf === '.' || $pf === '..') continue;

            $nameLower = strtolower($pf);
            if (
                preg_match('/portfolio.*performance.*since.*inception/i', $nameLower) ||
                preg_match('/portfolio.*performance.*inception/i', $nameLower)
            ) {
                $inceptionFile = $pf;
            } else {
                $sortedFiles[] = $pf;
            }
        }

        // Add inception file first if it exists
        if ($inceptionFile) {
            $fullPath = $persistentAttDir . '/' . $inceptionFile;
            $attachmentPaths[] = $fullPath;
            $attachmentNames[] = $inceptionFile; // Store original filename
            $emailAnnexures[] = [
                'text' => formatAnnexureLabel($inceptionFile, $client['name'] ?? '')
            ];
        }

        // Add remaining files
        foreach ($sortedFiles as $pf) {
            $fullPath = $persistentAttDir . '/' . $pf;
            $attachmentPaths[] = $fullPath;
            $attachmentNames[] = $pf; // Store original filename
            $emailAnnexures[] = [
                'text' => formatAnnexureLabel($pf, $client['name'] ?? '')
            ];
        }
    }

    // Fetch RM Details
    $rmData = getDefaultRelationshipManager();
    $rmName = $rmData['name'] ?? 'Relationship Manager';

    $name = $client['name'];
    $asOn = $client['as_on'] ?? '';
    $totalAmount = (float)($client['total_amount'] ?? 0);
    $profit = (float)($client['profit'] ?? 0);
    $cagr = (float)($client['cagr'] ?? 0);
    $xirr = (float)($client['xirr'] ?? 0);
    $absoluteReturnRaw = $client['absolute_return'] ?? null;
    $absoluteReturn = ($absoluteReturnRaw !== null) ? (float)$absoluteReturnRaw : null;
    $isOlderThanOneYear = (int)($client['is_older_than_1_year'] ?? 1);
    $totalGoalCurrent = (float)($client['total_goal_current'] ?? 0);
    $totalGoalTarget = (float)($client['total_goal_target'] ?? 0);
    $totalSip = (float)($client['total_sip'] ?? 0);

    // ----- text fields (same logic as detail view) -----
    $greetingStored = trim((string)($client['greeting_prefix'] ?? ''));
    $introTextStored = trim((string)($client['intro_text'] ?? ''));
    $closingTextStored = trim((string)($client['closing_text'] ?? ''));
    $rationaleStored = trim((string)($client['rationale_text'] ?? ''));
    $signatureStored = trim((string)($client['signature_block'] ?? ''));

    $rationaleText = $rationaleStored;

    $clientMessage = implode("\n\n", [
        $greetingStored,
        $introTextStored,
        $closingTextStored
    ]);



    /* --- FIX: PRIORITIZE DYNAMIC SIGNATURE FROM DROPDOWN --- */
    $dynamicSignature = trim($_POST['custom_signature'] ?? '');

    if (!empty($dynamicSignature)) {
        $signatureBlock = $dynamicSignature;
    } elseif ($signatureStored !== '') {
        $signatureBlock = $signatureStored;
    } else {
        $signatureBlock = generateSignatureBlock($rmData);
    }

    ob_start();
?>
    <html>

    <head>
        <style>
            /* Main container with limited width */
            body {
                font-family: 'Helvetica', 'Arial', sans-serif;
                font-size: 14px;
                color: #333;
                margin: 0;
                padding: 0;
                background-color: #f4f4f4;
            }

            /* Email wrapper - reduced width */
            .email-wrapper {
                max-width: 600px;
                margin: 0 auto;
                background-color: #ffffff;
                padding: 20px;
                border-radius: 5px;
            }

            /* Headers matching UI Blue (#0288D1 / #29B6F6 theme) */
            h4 {
                color: #0288D1;
                font-family: 'Helvetica', 'Arial', sans-serif;
                font-size: 16px;
                font-weight: bold;
                margin-top: 25px;
                margin-bottom: 10px;
                border-bottom: 2px solid #E3F2FD;
                padding-bottom: 5px;
                text-decoration: none;
            }

            /* Compact Table Style */
            table {
                width: 70% !important;
                /* Occupy 70% */
                border-collapse: collapse;
                margin: 0 0 15px 0;
                /* Remove auto centering */
                font-size: 13px;
                background-color: #ffffff;
                max-width: none;
                margin-left: 30px;
            }

            /* Table Header: Light Blue background, White text */
            th {
                background-color: #29B6F6;
                color: #ffffff;
                padding: 10px 8px !important;
                /* Reduced padding */
                text-align: left;
                font-weight: bold;
                border: none;
                font-size: 12px;
            }

            /* Table Body: Clean rows with light bottom border */
            td {
                padding: 8px !important;
                /* Reduced padding */
                border-bottom: 1px solid #f0f0f0;
                vertical-align: middle;
                color: #333;
                font-size: 12px;
            }

            /* Tighten text spacing */
            .compact-text {
                line-height: 1.4;
                margin-bottom: 10px;
            }

            /* Alignment utilities */
            .text-center {
                text-align: center;
            }

            .text-right {
                text-align: right;
            }

            .font-bold {
                font-weight: bold;
            }

            /* Status Badges - more compact */
            .status-off {
                background-color: #F44336;
                color: white;
                font-weight: bold;
                text-align: center;
                padding: 4px 6px !important;
                /* Reduced padding */
                border-radius: 3px;
                font-size: 11px;
                white-space: nowrap;
            }

            .status-on {
                background-color: #4CAF50;
                color: white;
                font-weight: bold;
                text-align: center;
                padding: 4px 6px !important;
                /* Reduced padding */
                border-radius: 3px;
                font-size: 11px;
                white-space: nowrap;
            }

            /* Action Steps (Schemes) - more compact */
            .action-continue {
                background-color: #C8E6C9;
                color: #256029;
                text-align: center;
                font-weight: bold;
                border-radius: 3px;
                padding: 4px 6px !important;
                font-size: 11px;
            }

            .action-drop {
                background-color: #FFCDD2;
                color: #C62828;
                text-align: center;
                font-weight: bold;
                border-radius: 3px;
                padding: 4px 6px !important;
                font-size: 11px;
            }

            .action-switch {
                background-color: #FFF9C4;
                color: #FBC02D;
                text-align: center;
                font-weight: bold;
                border-radius: 3px;
                padding: 4px 6px !important;
                font-size: 11px;
            }

            .action-redeem {
                background-color: #F5F5F5;
                color: #616161;
                text-align: center;
                font-weight: bold;
                border-radius: 3px;
                padding: 4px 6px !important;
                font-size: 11px;
            }

            .action-observation {
                background-color: #E3F2FD;
                color: #0288D1;
                text-align: center;
                font-weight: bold;
                border-radius: 3px;
                padding: 4px 6px !important;
                font-size: 11px;
            }

            /* Chart image - make it responsive and centered */
            .chart-container {
                text-align: center;
                margin: 15px auto;
                max-width: 500px;
            }

            .chart-container img {
                max-width: 100%;
                height: auto;
                border: none;
                border-radius: 4px;
            }

            /* Rationale box - make it narrower */
            .rationale-box {
                margin-top: 15px;
                border: 1px solid #29B6F6;
                border-radius: 4px;
                overflow: hidden;
                max-width: 580px;
                margin-left: auto;
                margin-right: auto;
            }

            /* Reduce spacing between sections */
            .section-spacing {
                margin-bottom: 15px !important;
            }
        </style>
    </head>

    <body>

        <div style="font-family: 'Helvetica', 'Arial', sans-serif; font-size: 14px; line-height: 1.6; font-weight: bold; margin-bottom: 15px; color: #333;">
            <?php echo nl2br(htmlspecialchars($greetingStored)); ?>
        </div>

        <div style="font-family: 'Helvetica', 'Arial', sans-serif; font-size: 14px; line-height: 1.6; margin-bottom: 20px; color: #333;">
            <?php echo nl2br(htmlspecialchars($introTextStored)); ?>
            <br><br>
            <?php echo nl2br(htmlspecialchars($closingTextStored)); ?>
        </div>

        <?php
        $asOnFormatted = $asOn;
        $asOnDate = DateTime::createFromFormat('d/m/Y', $asOn);
        if (!$asOnDate instanceof DateTime) {
            $asOnDate = DateTime::createFromFormat('d-m-Y', $asOn);
        }
        if ($asOnDate instanceof DateTime) {
            // Display as day first, e.g., 17th November 2025
            $asOnFormatted = $asOnDate->format('jS F Y');
        }
        ?>

        <h4>1. Current Situation</h4>
        <table>
            <tr>
                <th colspan="2">Current Situation as of <?php echo htmlspecialchars($asOnFormatted); ?></th>
            </tr>
            <tr>
                <td>Total Amount </td>
                <td><?php echo formatAmount($totalAmount); ?></td>
            </tr>
            <tr>
                <td><?php echo ($isOlderThanOneYear === 0) ? 'Absolute Return of schemes' : 'CAGR of current schemes'; ?></td>
                <td>
                    <?php
                    if ($isOlderThanOneYear === 0) {
                        echo ($absoluteReturn !== null) ? formatPercent($absoluteReturn) : 'N/A';
                    } else {
                        echo formatPercent($cagr);
                    }
                    ?>
                </td>
            </tr>
            <?php if ($isOlderThanOneYear === 1 && $xirr != 0): ?>
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

        <h4>2. Objectives Progress for guiding on appropriate schemes</h4>
        <table>
            <thead>
                <tr>
                    <th>Goal/s</th>
                    <th>Target Year</th>
                    <th>Current Amount (Rs)</th>
                    <th>SIP/SWP</th>
                    <th>Target Amount (Rs)</th>
                    <th>Status</th>
                </tr>
            </thead>
            <tbody>
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

                    // Trust the DB status (It handles both initial formula AND manual overrides)
                    $dbStatus = trim($g['status'] ?? '');

                    // Color Logic
                    $bgStyle = '';
                    if ($dbStatus === 'On Track') {
                        $bgStyle = 'background-color: #00B050; color: black; font-weight: bold;'; // Green
                    } else {
                        // Default to Invest More/Orange for anything else
                        $bgStyle = 'background-color: #ED7D31; color: black; font-weight: bold;'; // Orange
                    }

                    // Ensure display text is clean
                    $displayText = ($dbStatus === 'Needs Attention') ? 'Invest More' : $dbStatus;
                ?>
                    <tr>
                        <td style="border: 1px solid #4472C4; padding: 6px;"><?php echo htmlspecialchars($g['goal']); ?></td>
                        <td style="border: 1px solid #4472C4; padding: 6px; text-align: center;"><?php echo htmlspecialchars(substr((string)$g['goal_date'], -4)); ?></td>
                        <td style="border: 1px solid #4472C4; padding: 6px; text-align: center;"><?php echo formatAmount((float)$g['current_amount']); ?></td>
                        <td style="border: 1px solid #4472C4; padding: 6px; text-align: center;"><?php echo formatAmount((float)$g['sip_swp']); ?></td>
                        <td style="border: 1px solid #4472C4; padding: 6px; text-align: center;"><?php echo formatAmount((float)$g['target_amount']); ?></td>

                        <td style="border: 1px solid #4472C4; padding: 6px; text-align: center; <?php echo $bgStyle; ?>">
                            <?php echo htmlspecialchars($displayText); ?>
                        </td>
                    </tr>
                <?php endforeach; ?> <tr style="font-weight: bold; background-color: #fafafa;">
                    <td>Total</td>
                    <td style="text-align: center;"></td>
                    <td style="text-align: center;"><?php echo formatAmount($calculatedGoalCurrent); ?></td>
                    <td style="text-align: center;"><?php echo formatAmount($calculatedSip); ?></td>
                    <td style="text-align: center;"></td>
                    <td></td>
                </tr>
            </tbody>
        </table>

        <h4>3. Appropriate Asset Allocation</h4>
        <?php
        // Ensure Gold always exists in allocations
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

        // Build chart data for QuickChart.io with formatted labels
        $chartLabels = [];
        $chartValues = [];
        $chartColors = [];
        foreach ($allocations as $a) {
            $share = (float)$a['share_pct'];
            $assetName = $a['asset'];
            if ($share <= 0 && stripos($assetName, 'Gold') === false) {
                continue;
            }
            $chartLabels[] = $assetName . ' (' . number_format($share, 2) . '%)';
            $chartValues[] = $share;
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
        $chartConfig = [
            'type' => 'pie',
            'data' => [
                'labels' => $chartLabels,
                'datasets' => [[
                    'data' => $chartValues,
                    'backgroundColor' => $chartColors,
                    'borderColor' => '#fff',
                    'borderWidth' => 2
                ]]
            ],
            'options' => [
                'responsive' => true,
                'legend' => [
                    'position' => 'right',
                    'align' => 'center',
                    'labels' => [
                        'padding' => 10,
                        'boxWidth' => 15,
                        'fontSize' => 13
                    ]
                ],
                'plugins' => [
                    'datalabels' => [
                        'display' => false
                    ]
                ]
            ]
        ];
        $chartUrl = 'https://quickchart.io/chart?c=' . urlencode(json_encode($chartConfig));

        // Embed the chart as an image in the email body (works in most email clients)
        echo '<div style="text-align: left; margin: 20px 0;margin-left:60px;">'
            . '<img src="' . htmlspecialchars($chartUrl) . '" alt="Asset Allocation" style="max-width: 100%; max-height: 300px; width: auto; height: auto; border: none; border-radius: 4px;">'
            . '</div>';

        // --- Schemes Table Section ---
        ?>
        
        <h4>4. Appropriate Scheme Selection</h4>
        <table>
            <thead>
                <tr>
                    <th>Scheme Name</th>
                    <th>SIP/SWP</th>
                    <th>Current Value </th>
                    <th>Action Step</th>
                    <th>Recommended Scheme</th>
                    <th>Recommended Amount</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($schemes as $s):
                    $act = strtolower(trim($s['action_step'] ?? ''));
                    $aClass = '';
                    if ($act == 'continue') $aClass = 'action-continue';
                    elseif ($act == 'drop') $aClass = 'action-drop';
                    elseif ($act == 'switch') $aClass = 'action-switch';
                    elseif (strpos($act, 'redeem') !== false) $aClass = 'action-redeem';
                    // [FIX] Add styling for Under Observation
                    elseif (strpos($act, 'observation') !== false) $aClass = 'action-observation';
                ?>
                    <tr>
                        <td><?php echo htmlspecialchars($s['scheme_name']); ?></td>
                        <td><?php echo formatAmount((float)$s['sip_swp']); ?></td>
                        <td><?php echo formatAmount((float)$s['current_value']); ?></td>
                        <td class="<?php echo $aClass; ?>"><?php echo htmlspecialchars($s['action_step'] ?? 'Continue'); ?></td>
                        <td><?php echo htmlspecialchars($s['recommended_scheme'] ?? ''); ?></td>
                        <td class="text-center"><?php
                                                $recAmt = trim($s['recommended_amount'] ?? '');
                                                if ($recAmt !== '' && is_numeric($recAmt)) {
                                                    echo formatAmount((float)$recAmt);
                                                } else {
                                                    echo htmlspecialchars($recAmt);
                                                }
                                                ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>

        <?php
        // ---------------------------------------------------------
        // [PATCH START] NEW RECOMMENDED SCHEMES (Matches Report Style)
        // ---------------------------------------------------------

        // 1. Fetch New Schemes
        $targetId = isset($clientId) ? $clientId : (isset($client['id']) ? $client['id'] : 0);

        $nsStmt = $pdo->prepare("SELECT * FROM client_new_schemes WHERE client_id = ?");
        $nsStmt->execute([$targetId]);
        $emailNewSchemes = $nsStmt->fetchAll(PDO::FETCH_ASSOC);

        // 2. Only show this section if there are actually schemes to show
        if (!empty($emailNewSchemes)) {
            // Use <h4> to match "1. Current Situation" and "4. Recommended Schemes" headers
            $messagePart = '<h4>New Recommended Schemes</h4>';

            // Use standard <table> tag but Force 50% Width
            $messagePart .= '<table style="width: 50%;">';

            $messagePart .= '<thead>';
            $messagePart .= '<tr>';
            // Standard <th> automatically gets background-color: #29B6F6
            $messagePart .= '<th>Scheme Name</th>';
            // Use 'text-right' class defined in your CSS
            $messagePart .= '<th class="text-right">Amount (₹)</th>';
            $messagePart .= '</tr>';
            $messagePart .= '</thead>';

            $messagePart .= '<tbody>';

            foreach ($emailNewSchemes as $ns) {
                $messagePart .= '<tr>';
                // Standard <td> gets the correct padding and border
                $messagePart .= '<td>' . htmlspecialchars($ns['scheme_name']) . '</td>';
                // Use 'text-right' class defined in your CSS
                $messagePart .= '<td class="text-right">' . htmlspecialchars($ns['amount']) . '</td>';
                $messagePart .= '</tr>';
            }

            $messagePart .= '</tbody>';
            $messagePart .= '</table>';

            echo $messagePart; // Output to buffer
        }
        // ---------------------------------------------------------
        // [PATCH END]
        // ---------------------------------------------------------
        ?>

        <?php if (trim($rationaleText) !== ''): ?>
            <table width="70%" cellpadding="0" cellspacing="0"
                style="margin:20px 0 20px 30px;
              border:1px solid #29B6F6;
              border-collapse:collapse;
              display:block; clear:both;">
                <tr>
                    <td width="120"
                        style="background:#E1F5FE;
                   font-weight:bold;
                   text-align:center;
                   border-right:1px solid #29B6F6;
                   border-bottom:1px solid #29B6F6;
                   vertical-align:top;
                   padding:12px;">
                        Rationale
                    </td>
                    <td
                        style="padding:12px;
                   vertical-align:top;
                   border-bottom:1px solid #29B6F6;
                   word-wrap:break-word;">
                        <?php echo nl2br(htmlspecialchars($rationaleText)); ?>
                    </td>
                </tr>
            </table>
        <?php endif; ?>

        <?php if (!empty($emailAnnexures)): ?>
            <h4>Annexures</h4>
            <ul style="color: #333;">
                <?php foreach ($emailAnnexures as $annexure): ?>
                    <li>
                        <?php echo htmlspecialchars($annexure['text']); ?>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>

        <p style="margin-top: 30px; font-family: 'Helvetica', 'Arial', sans-serif; ">
            <?php echo nl2br(htmlspecialchars($signatureBlock)); ?>
        </p>

    </body>

    </html>
<?php
    $emailHtml = ob_get_clean();

    // Get client name dynamically (already available)
    $clientName = $name;

    // Use report date from Excel files instead of current date
    if (!empty($asOn)) {
        // Try to parse the date from the report (formats: dd-mm-yyyy or dd/mm/yyyy)
        $dateObj = DateTime::createFromFormat('d-m-Y', $asOn);
        if (!$dateObj) {
            $dateObj = DateTime::createFromFormat('d/m/Y', $asOn);
        }
        if (!$dateObj) {
            // Fallback to strtotime if above formats fail
            $dateObj = new DateTime($asOn);
        }

        $month = $dateObj->format('M');    // Jan, Feb, Mar, etc.
        $year = $dateObj->format('Y');     // 2024, 2025, etc.
    } else {
        // Fallback to current date if report date is not available
        $month = date('M');
        $year = date('Y');
    }

    // Build dynamic subject
    $dynamicSubject = "$clientName - Quarterly Review $month $year";


    $mail = new PHPMailer(true);

    try {

        // ---------------- SMTP CONFIG ----------------
        $mail->isSMTP();
        $mail->Host       = $smtpHost;
        $mail->SMTPAuth   = true;
        $mail->Username   = $smtpUsername;   // contact@financedoctor.in
        $mail->Password   = $smtpPassword;
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port       = $smtpPort;
        $mail->CharSet    = 'UTF-8';

        // FROM = logged-in user (Send mail as already configured)
        $mail->setFrom($fromEmailSender, $selectedFromName);

        // ---------------- RECIPIENTS ----------------
        foreach ($emailList as $email) {
            if ($email === $toEmail) {
                $mail->addAddress($email);
            } else {
                $mail->addCC($email);
            }
        }

        // ---------------- CONTENT ----------------
        $mail->Subject = $dynamicSubject;
        $mail->isHTML(true);
        $mail->Body = $emailHtml;

        // ---------------- ATTACHMENTS ----------------
        foreach ($attachmentPaths as $index => $file) {
            if (is_file($file)) {
                $displayName = $attachmentNames[$index] ?? basename($file);
                $mail->addAttachment($file, $displayName);
            }
        }

        // ---------------- SEND MAIL ----------------
        $mail->send();

        // ---------------- FOLLOW-UP EMAIL ----------------
        if (!empty($_POST['send_followup']) && !empty($_POST['followup_message'])) {
            sendFollowupEmail(
                $client,
                $toEmail,
                $ccList,
                $fromEmailSender,
                $selectedFromName,
                $pdo,
                $_POST['followup_message']
            );
        }

        // ---------------- UPDATE REPORT STATUS ----------------
        $updateStmt = $pdo->prepare("
        UPDATE clients 
        SET report_state = 'sent', 
            sent_at = NOW() 
        WHERE id = :client_id
    ");
        $updateStmt->execute([':client_id' => $clientId]);

        // ---------------- LOG EMAIL ----------------
        $ccEmailsString = !empty($ccList) ? implode(', ', $ccList) : '';

        $logStmt = $pdo->prepare("
        INSERT INTO email_logs 
        (client_id, from_email, from_name, sent_to_email, sent_to_name, cc_emails, email_body, email_type, followup_sent)
        VALUES 
        (:client_id, :from_email, :from_name, :to_email, :to_name, :cc_emails, :email_body, 'primary', 0)
    ");

        $logStmt->execute([
            ':client_id' => $clientId,
            ':from_email' => $fromEmailSender,
            ':from_name'  => $selectedFromName,
            ':to_email'   => $toEmail,
            ':to_name'    => $name,
            ':cc_emails'  => $ccEmailsString,
            ':email_body' => $emailHtml
        ]);

        // ---------------- CLEANUP TEMP FILES ----------------
        foreach ($attachmentPaths as $file) {
            if (is_file($file) && strpos($file, '/attachments/client_') === false) {
                @unlink($file);
            }
        }

        header('Location: view_report.php?id=' . $clientId . '&sent=1');
        exit;
    } catch (Exception $e) {

        // Cleanup temp files on failure
        foreach ($attachmentPaths as $file) {
            if (is_file($file) && strpos($file, '/attachments/client_') === false) {
                @unlink($file);
            }
        }

        error_log("Email sending failed for client ID {$clientId}: " . $e->getMessage());

        header('Location: view_report.php?id=' . $clientId . '&sent_error=1&msg=' . urlencode($e->getMessage()));
        exit;
    }
}

?>