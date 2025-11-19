<?php
// email_handler.php
// - Email sending functionality using PHPMailer

require_once __DIR__ . '/vendor/autoload.php';
require_once 'db_config.php';
require_once 'env_loader.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

function handleEmailSending($clientId) {
    $pdo = getPdo();
    $rawEmails = trim($_POST['recipient_email'] ?? '');

    // split by comma or semicolon, trim each
    $emailList = array_filter(
        array_map('trim', preg_split('/[;,]+/', $rawEmails))
    );

    if ($clientId <= 0 || empty($emailList)) {
        header('Location: view_report.php?id=' . $clientId);
        exit;
    }

    // Get email configuration from environment variables
    $smtpHost = $_ENV['SMTP_HOST'] ?? 'smtp.gmail.com';
    $smtpPort = $_ENV['SMTP_PORT'] ?? 587;
    $smtpUsername = $_ENV['SMTP_USERNAME'] ?? '';
    $smtpPassword = $_ENV['SMTP_PASSWORD'] ?? '';
    $smtpFromEmail = $_ENV['SMTP_FROM_EMAIL'] ?? '';
    $smtpFromName = $_ENV['SMTP_FROM_NAME'] ?? 'Portfolio Reports';

    // ✅ UPDATED: Handle Multiple File Uploads and Track Names
    $attachmentPaths = [];
    $attachmentNames = []; // ← NEW: Store file names for annexure section
    $uploadDir = $_ENV['UPLOAD_PATH'] ?? (__DIR__ . '/uploads');
    if (!is_dir($uploadDir)) {
        mkdir($uploadDir, 0777, true);
    }

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
                        $attachmentNames[] = $originalName; // ✅ Store original file name
                    }
                }
            }
        }
    }

    // Load client + related data
    $stmt = $pdo->prepare("SELECT * FROM clients WHERE id = :id");
    $stmt->execute([':id' => $clientId]);
    $client = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($client) {
        $stmt = $pdo->prepare("SELECT * FROM client_goals WHERE client_id = :id ORDER BY id ASC");
        $stmt->execute([':id' => $clientId]);
        $goals = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $stmt = $pdo->prepare("SELECT * FROM client_allocations WHERE client_id = :id ORDER BY id ASC");
        $stmt->execute([':id' => $clientId]);
        $allocations = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // ✅ UPDATED: Fetch schemes with latest saved data including action_step, recommended_scheme, recommended_amount
        $stmt = $pdo->prepare("SELECT * FROM client_schemes WHERE client_id = :id ORDER BY id ASC");
        $stmt->execute([':id' => $clientId]);
        $schemes = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $stmt = $pdo->prepare("SELECT * FROM client_annexures WHERE client_id = :id ORDER BY id ASC");
        $stmt->execute([':id' => $clientId]);
        $annexures = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // ✅ Load stored annexures from database (if any)
        $stmt = $pdo->prepare("SELECT * FROM client_annexures WHERE client_id = :id ORDER BY id ASC");
        $stmt->execute([':id' => $clientId]);
        $storedAnnexures = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // ✅ Merge uploaded files with stored annexures
        $emailAnnexures = [];
        
        // Add uploaded files to annexure list
        foreach ($attachmentNames as $fileName) {
            $emailAnnexures[] = [
                'text' => $fileName,
                'date' => date('d/m/Y')
            ];
        }
        
        // Add stored annexures (if no files were uploaded)
        if (empty($attachmentNames)) {
            foreach ($storedAnnexures as $ax) {
                $emailAnnexures[] = [
                    'text' => $ax['line_text'],
                    'date' => $client['as_on'] ?? date('d/m/Y')
                ];
            }
        }

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

        $DEFAULT_GREETING  = 'Dear Mr.';
        $DEFAULT_INTRO     = 'Introduction';
        $DEFAULT_CLOSING   = 'Closing remarks';
        $DEFAULT_RATIONALE = 'Rationale for recommendations';
        $DEFAULT_SIGNATURE = "Regards,\n\nVivek Sharma,\nRelationship Manager,\nFinance Doctor Private Limited.\n\nMobile - 888 4091 666.\nEmail - vivek.sharma@financedoctor.in\nUrl: www.financedoctor.in";

        // ✅ Build merged client message for email
        $clientMessageParts = [];
        
        if ($greetingStored !== '') {
            $clientMessageParts[] = $greetingStored;
        } else {
            $clientMessageParts[] = $DEFAULT_GREETING . ' ' . $name . ',';
        }
        
        if ($introTextStored !== '') {
            $clientMessageParts[] = $introTextStored;
        }
        
        if ($closingTextStored !== '') {
            $clientMessageParts[] = $closingTextStored;
        }
        
        $clientMessage = implode("\n\n", $clientMessageParts);
        $rationaleText = $rationaleStored !== '' ? $rationaleStored : $DEFAULT_RATIONALE;
        $signatureBlock = $signatureStored !== '' ? $signatureStored : $DEFAULT_SIGNATURE;

        // Build HTML email body
        ob_start();
        ?>
        <html>
        <body style="font-family: Arial, sans-serif; font-size: 13px;">
        
        <!-- ✅ Use merged client message -->
        <?php echo nl2br(htmlspecialchars($clientMessage)); ?>

        <h4>1. Current Situation</h4>
        <table border="1" cellpadding="4" cellspacing="0" style="border-collapse: collapse; font-size: 12px;">
            <tr>
                <th colspan="2">Current Situation</th>
            </tr>
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

        <h4>2. Objectives Progress</h4>
        <table border="1" cellpadding="4" cellspacing="0" style="border-collapse: collapse; font-size: 12px;">
            <tr>
                <th>Goal</th>
                <th>Target Year</th>
                <th>Current Amount</th>
                <th>SIP/SWP</th>
                <th>Target Amount</th>
                <th>Status</th>
            </tr>
            <?php foreach ($goals as $g): ?>
                <tr>
                    <td><?php echo htmlspecialchars($g['goal']); ?></td>
                    <td><?php echo htmlspecialchars(substr((string)$g['goal_date'], -4)); ?></td>
                    <td><?php echo formatAmount((float)$g['current_amount']); ?></td>
                    <td><?php echo formatAmount((float)$g['sip_swp']); ?></td>
                    <td><?php echo formatAmount((float)$g['target_amount']); ?></td>
                    <td><?php echo ($g['status'] === 'Needs Attention') ? 'Invest More' : htmlspecialchars($g['status']); ?></td>
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

        <h4>3. Asset Allocation</h4>
        <table border="1" cellpadding="4" cellspacing="0" style="border-collapse: collapse; font-size: 12px;">
            <tr>
                <th>Asset</th>
                <th>Share %</th>
            </tr>
            <?php foreach ($allocations as $a): ?>
                <tr>
                    <td><?php echo htmlspecialchars($a['asset']); ?></td>
                    <td><?php echo number_format((float)$a['share_pct'], 0); ?></td>
                </tr>
            <?php endforeach; ?>
        </table>

        <h4>4. Schemes & Recommendations</h4>
        <table border="1" cellpadding="4" cellspacing="0" style="border-collapse: collapse; font-size: 12px;">
            <tr>
                <th colspan="3" style="background-color: #E3F2FD;">Present Schemes</th>
                <th colspan="3" style="background-color: #FFF3E0;">Recommendations</th>
            </tr>
            <tr>
                <th>Scheme Name</th>
                <th>SIP/SWP</th>
                <th>Current Value</th>
                <th>Action Step</th>
                <th>Recommended Scheme</th>
                <th>Amount</th>
            </tr>
            <?php foreach ($schemes as $s): ?>
                <tr>
                    <td><?php echo htmlspecialchars($s['scheme_name']); ?></td>
                    <td><?php echo formatAmount((float)$s['sip_swp']); ?></td>
                    <td><?php echo formatAmount((float)$s['current_value']); ?></td>
                    <td><strong><?php echo htmlspecialchars($s['action_step'] ?? 'Continue'); ?></strong></td>
                    <td><?php echo htmlspecialchars($s['recommended_scheme'] ?? '-'); ?></td>
                    <td><?php echo !empty($s['recommended_amount']) ? formatAmount((float)$s['recommended_amount']) : '-'; ?></td>
                </tr>
            <?php endforeach; ?>
        </table>

        <?php if (trim($rationaleText) !== ''): ?>
            <h4>Rationale</h4>
            <p><?php echo nl2br(htmlspecialchars($rationaleText)); ?></p>
        <?php endif; ?>

        <?php if (!empty($emailAnnexures)): ?>
            <h4>Annexures</h4>
            <ul>
                <?php foreach ($emailAnnexures as $annexure): ?>
                    <li>
                        <?php echo htmlspecialchars($annexure['text']); ?>
                        (uploaded on: <?php echo htmlspecialchars($annexure['date']); ?>)
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>

        <!-- ✅ Add signature block -->
        <p><?php echo nl2br(htmlspecialchars($signatureBlock)); ?></p>
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

            // send to all emails entered in the form
            foreach ($emailList as $email) {
                $mail->addAddress($email);
            }

            $mail->Subject = $dynamicSubject;
            $mail->isHTML(true);
            $mail->Body = $emailHtml;

            // ✅ Attach All Uploaded Files (Unlimited)
            foreach ($attachmentPaths as $file) {
                if (file_exists($file)) {
                    $mail->addAttachment($file, basename($file));
                }
            }

            $mail->send();
            
            // ✅ Clean up all uploaded files after sending
            foreach ($attachmentPaths as $file) {
                if (file_exists($file)) {
                    @unlink($file);
                }
            }
            
            header('Location: view_report.php?id=' . $clientId . '&sent=1');
            exit;
        } catch (Exception $e) {
            // ✅ Clean up files even if email fails
            foreach ($attachmentPaths as $file) {
                if (file_exists($file)) {
                    @unlink($file);
                }
            }
            
            header('Location: view_report.php?id=' . $clientId . '&sent_error=1');
            exit;
        }
    } else {
        // No client found for that ID
        header('Location: view_report.php');
        exit;
    }
}
?>