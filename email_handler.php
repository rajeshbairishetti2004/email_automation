<?php
// email_handler.php
// ... (Code is unchanged from the last successful update) ...
require_once __DIR__ . '/vendor/autoload.php';
require_once 'db_config.php';
require_once 'env_loader.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

function handleEmailSending($clientId) {
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
    
    // --- NEW: Read email fields from the form ---
    $toEmail = trim($_POST['recipient_email'] ?? '');
    $ccEmailsRaw = trim($_POST['cc_emails'] ?? '');
    // UPDATED: Read the dynamic FROM email directly from the 'from_email' input
    $fromEmailSender = trim($_POST['from_email'] ?? '');
    
    // Combine To and CC emails into a single validation/logging list
    $rawEmailList = [];
    if (!empty($toEmail)) $rawEmailList[] = $toEmail;
    
    // Split CC emails by comma, filter out empty strings
    $ccList = array_filter(
        array_map('trim', preg_split('/[;,]+/', $ccEmailsRaw))
    );
    $emailList = array_merge($rawEmailList, $ccList);

    if ($clientId <= 0 || empty($emailList) || empty($fromEmailSender)) {
        // Redirect if no recipients or no sender is specified
        $errorMsg = '';
        if ($clientId <= 0) $errorMsg = 'Invalid client ID';
        elseif (empty($fromEmailSender)) $errorMsg = 'Sender email is required';
        elseif (empty($emailList)) $errorMsg = 'At least one recipient is required';
        
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
    
    // Use the dynamic sender email as the sender for PHPMailer
    $smtpFromEmail = $fromEmailSender; 
    
    $smtpFromName = $_ENV['SMTP_FROM_NAME'] ?? 'Portfolio Reports'; 

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

    if ($client) {
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

        // Load stored annexures from database (if any)
        $stmt = $pdo->prepare("SELECT * FROM client_annexures WHERE client_id = :id ORDER BY id ASC");
        $stmt->execute([':id' => $clientId]);
        $storedAnnexures = $stmt->fetchAll(PDO::FETCH_ASSOC);

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
                if (preg_match('/portfolio.*performance.*since.*inception/i', $nameLower) || 
                    preg_match('/portfolio.*performance.*inception/i', $nameLower)) {
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
        
        // Note: Do NOT add stored DB annexure defaults or temp upload names; annexures should reflect the report attachments list only.

        // Fetch RM Details (using centralized function from db_config)
        $rmData = getDefaultRelationshipManager(); // Rename to avoid conflict with $rm variable
        $rmName        = $rmData['name'] ?? 'Relationship Manager';
        
        $name        = $client['name'];
        $asOn        = $client['as_on'] ?? '';
        $totalAmount = (float)($client['total_amount'] ?? 0);
        $profit      = (float)($client['profit'] ?? 0);
        $cagr        = (float)($client['cagr'] ?? 0);
        $xirr        = (float)($client['xirr'] ?? 0);
        $totalGoalCurrent = (float)($client['total_goal_current'] ?? 0);
        $totalGoalTarget  = (float)($client['total_goal_target'] ?? 0);
        $totalSip         = (float)($client['total_sip'] ?? 0);

        // ----- text fields (same logic as detail view) -----
        $greetingStored    = trim((string)($client['greeting_prefix'] ?? ''));
        $introTextStored   = trim((string)($client['intro_text'] ?? ''));
        $closingTextStored = trim((string)($client['closing_text'] ?? ''));
        $rationaleStored   = trim((string)($client['rationale_text'] ?? ''));
        $signatureStored   = trim((string)($client['signature_block'] ?? ''));

        $DEFAULT_RATIONALE = 'Rationale for recommendations';

        $clientMessage = implode("\n\n", [
            $greetingStored,
            $introTextStored,
            $closingTextStored
        ]);

        $rationaleText = $rationaleStored !== '' ? $rationaleStored : $DEFAULT_RATIONALE;

        /* --- FIX: PRIORITIZE DYNAMIC SIGNATURE FROM DROPDOWN --- */
        $dynamicSignature = trim($_POST['custom_signature'] ?? '');

        if (!empty($dynamicSignature)) {
            $signatureBlock = $dynamicSignature;
        } elseif ($signatureStored !== '') {
            $signatureBlock = $signatureStored;
        } else {
            $signatureBlock = generateSignatureBlock($rmData);
        }
        /* ------------------------------------------------------- */

        // Build HTML email body
        ob_start();
        ?>
        <html>
        <head>
        <style>
            /* Typography matching UI */
            body { font-family: 'Helvetica', 'Arial', sans-serif; font-size: 14px; color: #333; }
            
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

            /* Clean Table Style */
            table { width: 100%; border-collapse: collapse; margin-bottom: 15px; font-size: 13px; background-color: #ffffff; }
            
            /* Table Header: Light Blue background, White text */
            th { 
                background-color: #29B6F6; 
                color: #ffffff; 
                padding: 12px 10px; 
                text-align: left; 
                font-weight: bold; 
                border: none; /* Removed heavy borders */
            }
            
            /* Table Body: Clean rows with light bottom border */
            td { 
                padding: 10px; 
                border-bottom: 1px solid #f0f0f0; 
                vertical-align: middle; 
                color: #333;
            }

            /* Alignment utilities */
            .text-center { text-align: center; }
            .text-right { text-align: right; }
            .font-bold { font-weight: bold; }
            
            /* UI-Match Status Badges */
            /* Red Badge for 'Invest More' / 'Needs Attention' */
            .status-off { 
                background-color: #F44336; 
                color: white; 
                font-weight: bold; 
                text-align: center; 
                padding: 6px 10px; 
                border-radius: 4px; /* Works in modern email clients */
                white-space: nowrap;
            }
            
            /* Green Badge for 'On Track' */
            .status-on { 
                background-color: #4CAF50; 
                color: white; 
                font-weight: bold; 
                text-align: center; 
                padding: 6px 10px; 
                border-radius: 4px; 
                white-space: nowrap;
            }

            /* Action Steps (Schemes) */
            .action-continue { background-color: #C8E6C9; color: #256029; text-align: center; font-weight: bold; border-radius: 4px; }
            .action-drop { background-color: #FFCDD2; color: #C62828; text-align: center; font-weight: bold; border-radius: 4px; }
            .action-switch { background-color: #FFF9C4; color: #FBC02D; text-align: center; font-weight: bold; border-radius: 4px; }
            .action-redeem { background-color: #F5F5F5; color: #616161; text-align: center; font-weight: bold; border-radius: 4px; }
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
                <td><?php echo (($client['is_older_than_1_year'] ?? 1) == 0) ? 'Absolute Return of schemes' : 'CAGR of current schemes'; ?></td>
                <td><?php echo formatPercent($cagr); ?></td>
            </tr>
            <?php if (($client['is_older_than_1_year'] ?? 1) == 1 && $xirr != 0): ?>
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
            <?php endforeach; ?>                <tr style="font-weight: bold; background-color: #fafafa;">
                    <td>Total</td>
                    <td style="text-align: center;"></td>
                    <td style="text-align: center;"><?php echo formatAmount($calculatedGoalCurrent); ?></td>
                    <td style="text-align: center;"><?php echo formatAmount($calculatedSip); ?></td>
                    <td style="text-align: center;"><?php echo formatAmount($calculatedGoalTarget); ?></td>
                    <td></td>
                </tr>
            </tbody>
        </table>

        <h4>3. Appropriate Product Selection at a macro level</h4>
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
            
            // Skip if value is 0 UNLESS it's Gold (Gold always shows even at 0)
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
        
        // Construct QuickChart.io URL
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
                'plugins' => [
                    'legend' => [
                        'position' => 'bottom'
                    ],
                    'datalabels' => [
                        'display' => false
                    ]
                ]
            ]
        ];
        
        $chartUrl = 'https://quickchart.io/chart?c=' . urlencode(json_encode($chartConfig));
        ?>
        <div style="text-align: center; margin: 20px 0;">
            <img src="<?php echo htmlspecialchars($chartUrl); ?>" alt="Asset Allocation" style="max-width: 100%; max-height: 300px; width: auto; height: auto; border: none; border-radius: 4px;">
        </div>
        </table>

        <h4>4. Appropriate Scheme Selection</h4>
        <table>
            <thead>
                <tr>
                    <th colspan="3">Present Schemes</th>
                    <th rowspan="2" style="width: 100px;">Action Step</th>
                    <th colspan="2">Recommended Schemes</th>
                </tr>
                <tr>
                    <th>Scheme Name</th>
                    <th>SIP/SWP</th>
                    <th>Value as of <?php echo htmlspecialchars($asOn); ?></th>
                    <th>Scheme Name</th>
                    <th>Amount</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($schemes as $s): 
                    // FIX: If all values in the row (SIP and Current Value) are 0, remove the row.
                    $sipVal = (float)($s['sip_swp'] ?? 0);
                    $currVal = (float)($s['current_value'] ?? 0);
                    
                    if ($sipVal <= 0 && $currVal <= 0) {
                        continue;
                    }

                    // Color Logic for Action Step
                    $act = strtolower(trim($s['action_step'] ?? ''));
                    $aClass = '';
                    if ($act == 'continue') $aClass = 'action-continue';
                    elseif ($act == 'drop') $aClass = 'action-drop';
                    elseif ($act == 'switch') $aClass = 'action-switch';
                    elseif (strpos($act, 'redeem') !== false) $aClass = 'action-redeem';
                ?>
                <tr>
                    <td><?php echo htmlspecialchars($s['scheme_name']); ?></td>
                    <td><?php echo formatAmount((float)$s['sip_swp']); ?></td>
                    <td><?php echo formatAmount((float)$s['current_value']); ?></td>
                    <td class="<?php echo $aClass; ?>"><?php echo htmlspecialchars($s['action_step'] ?? 'Continue'); ?></td>
                    <td><?php echo htmlspecialchars($s['recommended_scheme'] ?? ''); ?></td>
                    <td class="text-center"><?php echo htmlspecialchars($s['recommended_amount'] ?? ''); ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>

        <?php if (trim($rationaleText) !== ''): ?>
            <div style="margin-top: 20px; border: 1px solid #29B6F6; border-radius: 4px; overflow: hidden;">
                <table style="margin: 0;">
                    <tr>
                        <td style="width: 120px; background-color: #E1F5FE; font-weight: bold; text-align: center; border-right: 1px solid #29B6F6; border-bottom: none;">
                            Rationale
                        </td>
                        <td style="border-bottom: none; padding: 15px;">
                            <?php echo nl2br(htmlspecialchars($rationaleText)); ?>
                        </td>
                    </tr>
                </table>
            </div>
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

        <p style="margin-top: 30px; font-family: 'Helvetica', 'Arial', sans-serif;">
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

        // Send email via PHPMailer using environment variables
        $mail = new PHPMailer(true);
        try {
            $mail->isSMTP();
            $mail->Host       = $smtpHost;
            $mail->SMTPAuth   = true;

            $mail->Username   = $smtpUsername;
            $mail->Password   = $smtpPassword;
            $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
            $mail->Port       = $smtpPort;
            $mail->CharSet    = 'UTF-8';

            $mail->setFrom($smtpFromEmail, $smtpFromName);

            // Add all recipients (TO and CC)
            foreach ($emailList as $email) {
                 // PHPMailer determines if an address is TO or CC automatically if multiple are added
                 // but we'll add the primary recipient first, then the CC list.
                if ($email === $toEmail) {
                    $mail->addAddress($email);
                } else {
                    $mail->addCC($email);
                }
            }

            $mail->Subject = $dynamicSubject;
            $mail->isHTML(true);
            $mail->Body = $emailHtml;

            // Attach All Uploaded Files (Unlimited)
            foreach ($attachmentPaths as $index => $file) {
                if (file_exists($file)) {
                    // Use original filename if available, otherwise use basename
                    $displayName = isset($attachmentNames[$index]) ? $attachmentNames[$index] : basename($file);
                    $mail->addAttachment($file, $displayName);
                }
            }

            $mail->send();
            
            // --- UPDATE REPORT STATUS TO 'SENT' ---
            $updateStmt = $pdo->prepare("
                UPDATE clients 
                SET report_state = 'sent', 
                    sent_at = NOW() 
                WHERE id = :client_id
            ");
            $updateStmt->execute([':client_id' => $clientId]);
            
            // --- Log the successful email transmission ---
            $logStmt = $pdo->prepare("
                INSERT INTO email_logs (client_id, recipients_count, sent_by, sent_at)
                VALUES (:client_id, :recipients_count, :sent_by, NOW())
            ");
            $logStmt->execute([
                ':client_id' => $clientId,
                ':recipients_count' => count($emailList),
                ':sent_by' => $smtpFromEmail 
            ]);

            // Clean up ONLY temporary uploaded files (not the persistent ones)
            // Persistent files live in /uploads/attachments/, temp files live in /uploads/
            // We check the path to decide.
            foreach ($attachmentPaths as $file) {
                // Only delete if it is NOT in the attachments folder
                if (file_exists($file) && strpos($file, '/attachments/client_') === false) {
                    @unlink($file);
                }
            }
            
            header('Location: view_report.php?id=' . $clientId . '&sent=1');
            exit;
        } catch (Exception $e) {
            // Clean up temp files even if email fails (not persistent ones)
            foreach ($attachmentPaths as $file) {
                if (file_exists($file) && strpos($file, '/attachments/client_') === false) {
                    @unlink($file);
                }
            }
            
            // Log the error for debugging
            error_log("Email sending failed for client ID $clientId: " . $e->getMessage());
            
            header('Location: view_report.php?id=' . $clientId . '&sent_error=1&msg=' . urlencode($e->getMessage()));
            exit;
        }
    } else {
        // No client found for that ID
        header('Location: view_report.php');
        exit;
    }
}
?>