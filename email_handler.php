<?php
// email_handler.php
require_once __DIR__ . '/vendor/autoload.php';
require_once 'db_config.php';
require_once 'env_loader.php';
require_once 'followup_email_handler.php';


function handleEmailSending($clientId)
{
    $pdo = getPdo();

    // --- BACKEND SECURITY: Verify report state before proceeding ---
    $stmt = $pdo->prepare("SELECT report_state, review_not_ok FROM clients WHERE id = :id");
    $stmt->execute([':id' => $clientId]);
    $stateCheck = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$stateCheck || $stateCheck['report_state'] !== 'reviewed' || $stateCheck['review_not_ok'] != 0) {
        header('Location: view_report.php?id=' . $clientId . '&error=permission_denied');
        exit;
    }

    // --- Read email fields from the form ---
    $toEmail         = trim($_POST['recipient_email'] ?? '');
    $ccEmailsRaw     = trim($_POST['cc_emails'] ?? '');
    $fromEmailSender = trim($_POST['from_email'] ?? '');

    if (!filter_var($fromEmailSender, FILTER_VALIDATE_EMAIL)) {
        header('Location: view_report.php?id=' . $clientId . '&sent_error=1&msg=Invalid sender email');
        exit;
    }

    $selectedFromName = trim($_POST['from_name'] ?? '');

    $rawEmailList = [];
    if (!empty($toEmail)) $rawEmailList[] = $toEmail;

    $ccList = array_filter(
        array_map('trim', preg_split('/[;,]+/', $ccEmailsRaw)),
        function ($email) {
            return filter_var($email, FILTER_VALIDATE_EMAIL);
        }
    );

    $emailList = array_unique(array_merge($rawEmailList, $ccList));

    if ($clientId <= 0 || empty($emailList) || empty($fromEmailSender)) {
        $errorMsg = '';
        if ($clientId <= 0)              $errorMsg = 'Invalid client ID';
        elseif (empty($fromEmailSender)) $errorMsg = 'Sender email is required';
        elseif (empty($toEmail))         $errorMsg = 'Recipient email is required';

        header('Location: view_report.php?id=' . $clientId . '&sent_error=1&msg=' . urlencode($errorMsg));
        exit;
    }

    // Get email configuration from environment variables
    // Get Brevo API key from environment
    $brevoApiKey = $_ENV['BREVO_API_KEY'] ?? '';

    if (empty($brevoApiKey)) {
        error_log("Brevo API key missing in environment variables");
        header('Location: view_report.php?id=' . $clientId . '&sent_error=1&msg=' . urlencode('Email configuration is missing. Please contact administrator.'));
        exit;
    }

    // --- 1. Handle New/Temporary Attachments ---
    $attachmentPaths = [];
    $attachmentNames = [];

    $uploadDir = $_ENV['UPLOAD_PATH'] ?? (__DIR__ . '/uploads');
    if (!is_dir($uploadDir)) {
        mkdir($uploadDir, 0777, true);
    }

    if (isset($_FILES['attachments']) && !empty($_FILES['attachments']['name'][0])) {
        $uploadedFiles = $_FILES['attachments'];

        for ($i = 0; $i < count($uploadedFiles['name']); $i++) {
            if ($uploadedFiles['error'][$i] === UPLOAD_ERR_OK) {
                $tmpName      = $uploadedFiles['tmp_name'][$i];
                $originalName = basename($uploadedFiles['name'][$i]);
                $ext          = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));

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
        function formatAnnexureLabel($filename, $clientName = '')
        {
            $name = pathinfo($filename, PATHINFO_FILENAME);
            return ($name === '') ? $filename : $name;
        }
    }

    $stmt = $pdo->prepare("SELECT * FROM client_goals WHERE client_id = :id ORDER BY id ASC");
    $stmt->execute([':id' => $clientId]);
    $goals = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $stmt = $pdo->prepare("SELECT * FROM client_allocations WHERE client_id = :id ORDER BY id ASC");
    $stmt->execute([':id' => $clientId]);
    $allocations = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $stmt = $pdo->prepare("SELECT * FROM client_schemes WHERE client_id = :id ORDER BY id ASC");
    $stmt->execute([':id' => $clientId]);
    $schemes = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $stmt = $pdo->prepare("SELECT * FROM client_annexures WHERE client_id = :id ORDER BY id ASC");
    $stmt->execute([':id' => $clientId]);
    $annexures = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $emailAnnexures = [];

    // --- 2. Persistent Report Attachments ---
    $persistentAttDir = __DIR__ . '/uploads/attachments/client_' . $clientId;

    if (is_dir($persistentAttDir)) {
        $pFiles        = scandir($persistentAttDir);
        $sortedFiles   = [];
        $inceptionFile = null;

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

        if ($inceptionFile) {
            $fullPath          = $persistentAttDir . '/' . $inceptionFile;
            $attachmentPaths[] = $fullPath;
            $attachmentNames[] = $inceptionFile;
            $emailAnnexures[]  = ['text' => formatAnnexureLabel($inceptionFile, $client['name'] ?? '')];
        }

        foreach ($sortedFiles as $pf) {
            $fullPath          = $persistentAttDir . '/' . $pf;
            $attachmentPaths[] = $fullPath;
            $attachmentNames[] = $pf;
            $emailAnnexures[]  = ['text' => formatAnnexureLabel($pf, $client['name'] ?? '')];
        }
    }

    // Fetch RM Details
    $rmData = getDefaultRelationshipManager();
    $rmName = $rmData['name'] ?? 'Relationship Manager';

    $name               = $client['name'];
    $asOn               = $client['as_on'] ?? '';
    $totalAmount        = (float)($client['total_amount'] ?? 0);
    $profit             = (float)($client['profit'] ?? 0);
    $cagr               = (float)($client['cagr'] ?? 0);
    $xirr               = (float)($client['xirr'] ?? 0);
    $absoluteReturnRaw  = $client['absolute_return'] ?? null;
    $absoluteReturn     = ($absoluteReturnRaw !== null) ? (float)$absoluteReturnRaw : null;
    $isOlderThanOneYear = (int)($client['is_older_than_1_year'] ?? 1);
    $totalGoalCurrent   = (float)($client['total_goal_current'] ?? 0);
    $totalGoalTarget    = (float)($client['total_goal_target'] ?? 0);
    $totalSip           = (float)($client['total_sip'] ?? 0);

    // ----- HTML text fields from Quill editor — output raw, never htmlspecialchars -----
    $greetingStored    = trim((string)($client['greeting_prefix'] ?? ''));
    $introTextStored   = trim((string)($client['intro_text'] ?? ''));
    $closingTextStored = trim((string)($client['closing_text'] ?? ''));
    $rationaleStored   = trim((string)($client['rationale_text'] ?? ''));
    $signatureStored   = trim((string)($client['signature_block'] ?? ''));

    $rationaleText = $rationaleStored;
    $rationaleStripped = trim(strip_tags($rationaleText));

    /* ---------------------------------------------------------------
       SIGNATURE PRIORITY:
       1. $_POST['custom_signature'] — live textarea value submitted
          with the send-email form (name="custom_signature" in signature.php)
       2. $signatureStored — last saved value from DB
       3. generateSignatureBlock($rmData) — fallback

       NOTE: The textarea in signature.php uses name="custom_signature"
       so that this value is always submitted fresh with the form.
    --------------------------------------------------------------- */
    $dynamicSignature = trim($_POST['custom_signature'] ?? '');

    if (!empty($dynamicSignature)) {
        $signatureBlock = $dynamicSignature;
    } elseif ($signatureStored !== '') {
        $signatureBlock = $signatureStored;
    } else {
        $signatureBlock = generateSignatureBlock($rmData);
    }

    /*
     * Convert signature to safe HTML for the email body.
     * - Plain text (from textarea with \n): convert via nl2br + htmlspecialchars
     * - HTML (legacy stored value):         detect and pass through as-is
     */
    $signatureIsHtml = $signatureBlock !== strip_tags($signatureBlock);
    $signatureHtml   = $signatureIsHtml
        ? $signatureBlock                           // already HTML — pass through
        : nl2br(htmlspecialchars($signatureBlock)); // plain text — convert safely

    ob_start();
?>
    <html>

    <head>
        <style>
            body {
                font-family: 'Helvetica', 'Arial', sans-serif;
                font-size: 14px;
                color: #333;
                margin: 0;
                padding: 0;
                background-color: #f4f4f4;
            }

            .email-wrapper {
                max-width: 600px;
                margin: 0 auto;
                background-color: #ffffff;
                padding: 20px;
                border-radius: 5px;
            }

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

            table {
                width: 70% !important;
                border-collapse: collapse;
                margin: 0 0 15px 0;
                font-size: 13px;
                background-color: #ffffff;
                max-width: none;
                margin-left: 30px;
            }

            th {
                background-color: #29B6F6;
                color: #ffffff;
                padding: 10px 8px !important;
                text-align: left;
                font-weight: bold;
                border: none;
                font-size: 12px;
            }

            td {
                padding: 8px !important;
                border-bottom: 1px solid #f0f0f0;
                vertical-align: middle;
                color: #333;
                font-size: 12px;
            }

            .compact-text {
                line-height: 1.4;
                margin-bottom: 10px;
            }

            .text-center {
                text-align: center;
            }

            .text-right {
                text-align: right;
            }

            .font-bold {
                font-weight: bold;
            }

            .status-off {
                background-color: #F44336;
                color: white;
                font-weight: bold;
                text-align: center;
                padding: 4px 6px !important;
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
                border-radius: 3px;
                font-size: 11px;
                white-space: nowrap;
            }

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

            .rationale-box {
                margin-top: 15px;
                border: 1px solid #29B6F6;
                border-radius: 4px;
                overflow: hidden;
                max-width: 580px;
                margin-left: auto;
                margin-right: auto;
            }

            .section-spacing {
                margin-bottom: 15px !important;
            }
        </style>
    </head>

    <body>

        <!-- Greeting: stored as HTML from Quill — output raw -->
        <div style="font-family: 'Helvetica', 'Arial', sans-serif; font-size: 14px; line-height: 1.6; font-weight: bold; margin-bottom: 15px; color: #333;">
            <?php echo $greetingStored; ?>
        </div>

        <!-- Intro & Closing: stored as HTML from Quill — output raw -->
        <div style="font-family: 'Helvetica', 'Arial', sans-serif; font-size: 14px; line-height: 1.6; margin-bottom: 20px; color: #333;">
            <?php echo $introTextStored; ?>
            <?php echo $closingTextStored; ?>
        </div>

        <?php
        $asOnFormatted = $asOn;
        $asOnDate = DateTime::createFromFormat('d/m/Y', $asOn);
        if (!$asOnDate instanceof DateTime) {
            $asOnDate = DateTime::createFromFormat('d-m-Y', $asOn);
        }
        if ($asOnDate instanceof DateTime) {
            $asOnFormatted = $asOnDate->format('jS F Y');
        }
        ?>

        <h4>1. Current Situation</h4>
        <table>
            <tr>
                <th colspan="2">Current Situation as of <?php echo htmlspecialchars($asOnFormatted); ?></th>
            </tr>
            <tr>
                <td>Total Amount</td>
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
                $calculatedGoalCurrent = 0;
                $calculatedSip         = 0;
                $calculatedGoalTarget  = 0;

                foreach ($goals as $g):
                    $calculatedGoalCurrent += (float)($g['current_amount'] ?? 0);
                    $calculatedSip         += (float)($g['sip_swp'] ?? 0);
                    $calculatedGoalTarget  += (float)($g['target_amount'] ?? 0);

                    $dbStatus = trim($g['status'] ?? '');

                    if ($dbStatus === 'On Track') {
                        $bgStyle = 'background-color: #00B050; color: black; font-weight: bold;';
                    } else {
                        $bgStyle = 'background-color: #ED7D31; color: black; font-weight: bold;';
                    }

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
                <?php endforeach; ?>
                <tr style="font-weight: bold; background-color: #fafafa;">
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
            $share     = (float)$a['share_pct'];
            $assetName = $a['asset'];
            if ($share <= 0 && stripos($assetName, 'Gold') === false) continue;
            $chartLabels[] = $assetName . ' (' . number_format($share, 2) . '%)';
            $chartValues[] = $share;
            if (stripos($assetName, 'Equity') !== false) $chartColors[] = '#36A2EB';
            elseif (stripos($assetName, 'Debt')   !== false) $chartColors[] = '#2eb85c';
            elseif (stripos($assetName, 'Gold')   !== false) $chartColors[] = '#f9b115';
            else                                               $chartColors[] = '#e55353';
        }

        $chartConfig = [
            'type' => 'pie',
            'data' => [
                'labels'   => $chartLabels,
                'datasets' => [[
                    'data'            => $chartValues,
                    'backgroundColor' => $chartColors,
                    'borderColor'     => '#fff',
                    'borderWidth'     => 2
                ]]
            ],
            'options' => [
                'responsive' => true,
                'legend'     => [
                    'position' => 'right',
                    'align'    => 'center',
                    'labels'   => ['padding' => 10, 'boxWidth' => 15, 'fontSize' => 13]
                ],
                'plugins' => ['datalabels' => ['display' => false]]
            ]
        ];
        $chartUrl = 'https://quickchart.io/chart?c=' . urlencode(json_encode($chartConfig));

        echo '<div style="text-align: left; margin: 20px 0; margin-left: 60px;">'
            . '<img src="' . htmlspecialchars($chartUrl) . '" alt="Asset Allocation" style="max-width: 100%; max-height: 300px; width: auto; height: auto; border: none; border-radius: 4px;">'
            . '</div>';
        ?>

        <h4>4. Appropriate Scheme Selection</h4>
        <table>
            <thead>
                <tr>
                    <th>Scheme Name</th>
                    <th>SIP/SWP</th>
                    <th>Current Value</th>
                    <th>Action Step</th>
                    <th>Recommended Scheme</th>
                    <th>Recommended Amount</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($schemes as $s):
                    $act    = strtolower(trim($s['action_step'] ?? ''));
                    $aClass = '';
                    if ($act == 'continue')                    $aClass = 'action-continue';
                    elseif ($act == 'drop')                        $aClass = 'action-drop';
                    elseif ($act == 'switch')                      $aClass = 'action-switch';
                    elseif (strpos($act, 'redeem') !== false)      $aClass = 'action-redeem';
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
        // New Recommended Schemes
        $targetId = isset($clientId) ? $clientId : (isset($client['id']) ? $client['id'] : 0);

        $nsStmt = $pdo->prepare("SELECT * FROM client_new_schemes WHERE client_id = ?");
        $nsStmt->execute([$targetId]);
        $emailNewSchemes = $nsStmt->fetchAll(PDO::FETCH_ASSOC);

        if (!empty($emailNewSchemes)) {
            $messagePart  = '<h4>New Recommended Schemes</h4>';
            $messagePart .= '<table style="width: 50%;">';
            $messagePart .= '<thead><tr>';
            $messagePart .= '<th>Scheme Name</th>';
            $messagePart .= '<th class="text-right">Amount (&#8377;)</th>';
            $messagePart .= '</tr></thead>';
            $messagePart .= '<tbody>';
            foreach ($emailNewSchemes as $ns) {
                $messagePart .= '<tr>';
                $messagePart .= '<td>' . htmlspecialchars($ns['scheme_name']) . '</td>';
                $messagePart .= '<td class="text-right">' . htmlspecialchars($ns['amount']) . '</td>';
                $messagePart .= '</tr>';
            }
            $messagePart .= '</tbody></table>';
            echo $messagePart;
        }
        ?>

        <?php if (trim(strip_tags($rationaleText)) !== ''): ?>
            <table width="70%" cellpadding="0" cellspacing="0"
                style="margin: 20px 0 20px 30px; border: 1px solid #29B6F6; border-collapse: collapse; display: block; clear: both;">
                <tr>
                    <td width="120"
                        style="background: #E1F5FE; font-weight: bold; text-align: center;
                               border-right: 1px solid #29B6F6; border-bottom: 1px solid #29B6F6;
                               vertical-align: top; padding: 12px;">
                        Rationale
                    </td>
                    <td style="padding: 12px; vertical-align: top; border-bottom: 1px solid #29B6F6; word-wrap: break-word;">
                        <!-- Rationale stored as HTML from Quill — output raw -->
                        <?php echo $rationaleText; ?>
                    </td>
                </tr>
            </table>
        <?php endif; ?>

        <?php if (!empty($emailAnnexures)): ?>
            <h4>Annexures</h4>
            <ul style="color: #333;">
                <?php foreach ($emailAnnexures as $annexure): ?>
                    <li><?php echo htmlspecialchars($annexure['text']); ?></li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>

        <!--
            Signature: $signatureHtml is already safe.
            Plain text was converted via nl2br(htmlspecialchars()).
            HTML was passed through as-is.
        -->
        <div style="margin-top: 30px; font-family: 'Helvetica', 'Arial', sans-serif;">
            <?php echo $signatureHtml; ?>
        </div>

    </body>

    </html>
<?php
    $emailHtml = ob_get_clean();

    $clientName = $name;

    if (!empty($asOn)) {
        $dateObj = DateTime::createFromFormat('d-m-Y', $asOn);
        if (!$dateObj) $dateObj = DateTime::createFromFormat('d/m/Y', $asOn);
        if (!$dateObj) $dateObj = new DateTime($asOn);

        $month = $dateObj->format('M');
        $year  = $dateObj->format('Y');
    } else {
        $month = date('M');
        $year  = date('Y');
    }

    $dynamicSubject = "$clientName - Quarterly Review $month $year";

    // Build recipients
$toRecipients  = [['email' => $toEmail, 'name' => $clientName]];
$ccRecipients  = array_map(fn($e) => ['email' => $e], $ccList);

// Build attachments for Brevo
$brevoAttachments = [];
foreach ($attachmentPaths as $index => $file) {
    if (is_file($file)) {
        $displayName          = $attachmentNames[$index] ?? basename($file);
        $brevoAttachments[]   = [
            'name'    => $displayName,
            'content' => base64_encode(file_get_contents($file))
        ];
    }
}

// Build Brevo API payload
$payload = [
    'sender'      => ['name' => $selectedFromName, 'email' => $fromEmailSender],
    'to'          => $toRecipients,
    'subject'     => $dynamicSubject,
    'htmlContent' => $emailHtml,
];

if (!empty($ccRecipients))      $payload['cc']          = $ccRecipients;
if (!empty($brevoAttachments))  $payload['attachment']  = $brevoAttachments;

// Send via Brevo API
$ch = curl_init('https://api.brevo.com/v3/smtp/email');
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    'Content-Type: application/json',
    'api-key: ' . $brevoApiKey
]);

$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($httpCode === 201) {

    // FOLLOW-UP EMAIL
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

    // UPDATE REPORT STATUS
    $pdo->prepare("UPDATE clients SET report_state = 'sent', sent_at = NOW() WHERE id = :client_id")
        ->execute([':client_id' => $clientId]);

    // LOG EMAIL
    $ccEmailsString = !empty($ccList) ? implode(', ', $ccList) : '';

    $pdo->prepare("
        INSERT INTO email_logs
        (client_id, from_email, from_name, sent_to_email, sent_to_name, cc_emails, email_body, email_type, followup_sent)
        VALUES
        (:client_id, :from_email, :from_name, :to_email, :to_name, :cc_emails, :email_body, 'primary', 0)
    ")->execute([
        ':client_id'  => $clientId,
        ':from_email' => $fromEmailSender,
        ':from_name'  => $selectedFromName,
        ':to_email'   => $toEmail,
        ':to_name'    => $clientName,
        ':cc_emails'  => $ccEmailsString,
        ':email_body' => $emailHtml
    ]);

    // CLEANUP TEMP FILES
    foreach ($attachmentPaths as $file) {
        if (is_file($file) && strpos($file, '/attachments/client_') === false) {
            @unlink($file);
        }
    }

    header('Location: view_report.php?id=' . $clientId . '&sent=1');
    exit;

} else {
    $errorDetail = json_decode($response, true)['message'] ?? 'Unknown error';
    error_log("Brevo API error for client ID {$clientId}: HTTP $httpCode - $errorDetail");

    // CLEANUP TEMP FILES
    foreach ($attachmentPaths as $file) {
        if (is_file($file) && strpos($file, '/attachments/client_') === false) {
            @unlink($file);
        }
    }

    header('Location: view_report.php?id=' . $clientId . '&sent_error=1&msg=' . urlencode($errorDetail));
    exit;
} 
}