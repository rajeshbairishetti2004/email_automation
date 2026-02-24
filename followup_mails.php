<?php
ob_start(); // buffer everything so AJAX responses are clean
require_once 'auth.php';
require_once 'db_config.php';
require_once __DIR__ . '/vendor/autoload.php';
requireAuth();

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

$pdo         = getPdo();
$currentUser = getCurrentUser();
$currentPage = basename($_SERVER['PHP_SELF']);

// Current cycle detection
$currentMonthShort = date('M');
if (in_array($currentMonthShort, ['Jan', 'Apr', 'Jul', 'Oct'])) {
    $defaultCycle = 'RJ';
} elseif (in_array($currentMonthShort, ['Feb', 'May', 'Aug', 'Nov'])) {
    $defaultCycle = 'RF';
} else {
    $defaultCycle = 'RM';
}

$cycleMap = [
    'RJ' => ['Jan', 'Apr', 'Jul', 'Oct'],
    'RF' => ['Feb', 'May', 'Aug', 'Nov'],
    'RM' => ['Mar', 'Jun', 'Sep', 'Dec'],
];

// ─────────────────────────────────────────────
// AJAX: Load clients for a cycle
// ─────────────────────────────────────────────
if (isset($_GET['ajax']) && $_GET['ajax'] === 'clients') {
    ob_end_clean(); // discard any stray output before sending JSON
    header('Content-Type: application/json; charset=utf-8');

    try {
        $cycle  = strtoupper(trim($_GET['cycle'] ?? $defaultCycle));
        $months = $cycleMap[$cycle] ?? [];

        if (empty($months)) {
            echo json_encode([]);
            exit;
        }

        $stmt = $pdo->prepare("
            SELECT
                cl.id,
                cl.name,
                cl.email,
                cl.mobile,
                cl.rm,
                cl.tags,
                u.name        AS rm_full_name,
                u.email       AS rm_email,
                u.mobile      AS rm_mobile,
                u.company_name,
                u.website_url
            FROM customer_list cl
            LEFT JOIN users u
                   ON LOWER(TRIM(u.name)) COLLATE utf8mb4_unicode_ci = LOWER(TRIM(cl.rm)) COLLATE utf8mb4_unicode_ci
                   OR LOWER(TRIM(u.username)) COLLATE utf8mb4_unicode_ci = LOWER(TRIM(cl.rm)) COLLATE utf8mb4_unicode_ci
            WHERE UPPER(TRIM(cl.tags)) = ?
            ORDER BY cl.name ASC
        ");
        $stmt->execute([$cycle]);
        $clients = $stmt->fetchAll(PDO::FETCH_ASSOC);

        echo json_encode($clients, JSON_UNESCAPED_UNICODE);

    } catch (PDOException $e) {
        http_response_code(500);
        echo json_encode(['error' => $e->getMessage()]);
    }
    exit;
}

// ─────────────────────────────────────────────
// AJAX: Send emails (via PHPMailer + SMTP)
// ─────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'send_emails') {
    ob_end_clean();
    header('Content-Type: application/json; charset=utf-8');

    // SMTP config from .env
    $smtpHost     = getEnvVar('SMTP_HOST',       'smtp.gmail.com');
    $smtpPort     = (int) getEnvVar('SMTP_PORT',  '587');
    $smtpUser     = getEnvVar('SMTP_USERNAME',    '');
    $smtpPass     = getEnvVar('SMTP_PASSWORD',    '');
    $smtpFromEmail = getEnvVar('SMTP_FROM_EMAIL', $smtpUser);
    $smtpFromName  = getEnvVar('SMTP_FROM_NAME',  'Finance Doctor');

    if (empty($smtpHost) || empty($smtpUser) || empty($smtpPass)) {
        echo json_encode(['success' => false, 'message' => 'SMTP not configured. Please fill SMTP_HOST, SMTP_USERNAME, SMTP_PASSWORD in your .env file.']);
        exit;
    }

    $clientIds = json_decode($_POST['client_ids'] ?? '[]', true);
    if (empty($clientIds)) {
        echo json_encode(['success' => false, 'message' => 'No clients selected.']);
        exit;
    }

    try {
        $placeholders = implode(',', array_fill(0, count($clientIds), '?'));
        $stmt = $pdo->prepare("
            SELECT
                cl.id,
                cl.name,
                cl.email,
                cl.rm,
                u.name        AS rm_full_name,
                u.email       AS rm_email,
                u.mobile      AS rm_mobile,
                u.company_name,
                u.website_url
            FROM customer_list cl
            LEFT JOIN users u
                   ON LOWER(TRIM(u.name)) COLLATE utf8mb4_unicode_ci = LOWER(TRIM(cl.rm)) COLLATE utf8mb4_unicode_ci
                   OR LOWER(TRIM(u.username)) COLLATE utf8mb4_unicode_ci = LOWER(TRIM(cl.rm)) COLLATE utf8mb4_unicode_ci
            WHERE cl.id IN ($placeholders)
        ");
        $stmt->execute(array_values($clientIds));
        $clients = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        exit;
    }

    $customBodies = json_decode($_POST['custom_bodies'] ?? '{}', true);
    $sent   = 0;
    $failed = [];
    $errors = [];

    foreach ($clients as $client) {
        if (empty($client['email'])) {
            $failed[] = $client['name'];
            continue;
        }

        $rmName   = !empty($client['rm_full_name']) ? $client['rm_full_name'] : ($client['rm'] ?? $smtpFromName);
        $rmEmail  = !empty($client['rm_email'])     ? $client['rm_email']     : $smtpFromEmail;
        $rmMobile = !empty($client['rm_mobile'])    ? $client['rm_mobile']    : '7799397314';
        $company  = !empty($client['company_name']) ? $client['company_name'] : 'Finance Doctor Private Limited';
        $website  = !empty($client['website_url'])  ? $client['website_url']  : 'www.financedoctor.in';

        $clientName  = strtoupper(trim($client['name']));
        $subject     = "Your Quarterly Review – Let's Connect";
        $customBody  = $customBodies[strval($client['id'])] ?? null;

        if ($customBody) {
            $body = $customBody;
        } else {
            $body = "Dear {$clientName},\n\n"
                  . "I hope you are doing well.\n\n"
                  . "We have sent your quarterly review for this month, it would be good to connect over a Zoom meeting to walk through the review together, understand your current priorities, and discuss the way forward.\n\n"
                  . "Please let me know your convenience, and I will be happy to schedule the meeting accordingly.\n\n"
                  . "Looking forward to speaking with you.\n\n"
                  . "Regards,\n"
                  . "{$rmName},\n"
                  . "{$company}.\n"
                  . "Mobile - {$rmMobile}\n"
                  . "Email - {$rmEmail}\n"
                  . "Url: {$website}";
        }

        try {
            $mail = new PHPMailer(true);
            $mail->isSMTP();
            $mail->Host        = $smtpHost;
            $mail->SMTPAuth    = true;
            $mail->Username    = $smtpUser;
            $mail->Password    = $smtpPass;
            $mail->SMTPSecure  = ($smtpPort === 465) ? PHPMailer::ENCRYPTION_SMTPS : PHPMailer::ENCRYPTION_STARTTLS;
            $mail->Port        = $smtpPort;
            $mail->CharSet     = 'UTF-8';

            $mail->setFrom($smtpFromEmail, $smtpFromName);
            $mail->addReplyTo($rmEmail, $rmName);
            $mail->addAddress($client['email'], $client['name']);

            $mail->Subject = $subject;
            $mail->Body    = $body;
            $mail->isHTML(false);

            $mail->send();
            $sent++;
        } catch (Exception $e) {
            $failed[] = $client['name'];
            $errors[] = "Failed [{$client['name']}]: " . $mail->ErrorInfo;
        }
    }

    // ── Log sent emails to followup_emails table ──
    if ($sent > 0) {
        // Ensure table exists
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS `followup_emails` (
                `id`        INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                `name`      VARCHAR(255) NOT NULL,
                `email`     VARCHAR(255) NOT NULL,
                `sent_date` DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
                KEY `idx_email` (`email`),
                KEY `idx_sent_date` (`sent_date`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        $logInsert = $pdo->prepare("
            INSERT INTO followup_emails (name, email, sent_date)
            VALUES (?, ?, NOW())
        ");

        foreach ($clients as $client) {
            // Only log clients that were actually sent (have email and not in failed list)
            if (!empty($client['email']) && !in_array($client['name'], $failed)) {
                $logInsert->execute([$client['name'], $client['email']]);
            }
        }
    }

    echo json_encode([
        'success' => true,
        'sent'    => $sent,
        'failed'  => $failed,
        'errors'  => $errors,
    ]);
    exit;
}

// ─────────────────────────────────────────────
// Normal page render — flush buffer
// ─────────────────────────────────────────────
ob_end_flush();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Follow-Up Mails</title>
    <link rel="stylesheet" href="public/css/navbar.css">
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css" crossorigin="anonymous" />
    <style>
        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

        body {
            font-family: 'Inter', sans-serif;
            background: #f0f6fb;
            color: #0f172a;
            height: 100vh;
            overflow: hidden;
        }

        .page-wrapper {
            display: flex;
            height: calc(100vh - 80px);
            overflow: hidden;
        }

        /* ── LEFT PANEL ── */
        .left-panel {
            width: 380px;
            min-width: 320px;
            background: #fff;
            border-right: 1.5px solid #e2e8f0;
            display: flex;
            flex-direction: column;
            overflow: hidden;
        }

        .panel-header {
            padding: 22px 22px 16px;
            border-bottom: 1px solid #f1f5f9;
            background: linear-gradient(135deg, rgba(148,227,241,0.18), rgba(227,242,253,0.35));
        }

        .panel-header h2 {
            font-size: 18px;
            font-weight: 700;
            color: #0f172a;
            margin-bottom: 14px;
        }

        .cycle-tabs { display: flex; gap: 8px; }

        .cycle-tab {
            flex: 1;
            padding: 9px 0;
            text-align: center;
            font-size: 13px;
            font-weight: 700;
            border-radius: 10px;
            border: 2px solid #e2e8f0;
            background: #f8fafc;
            color: #64748b;
            cursor: pointer;
            transition: all 0.2s;
            letter-spacing: 0.04em;
        }
        .cycle-tab:hover  { border-color: #0288D1; color: #0288D1; background: #e3f2fd; }
        .cycle-tab.active {
            background: linear-gradient(135deg, #0288D1, #4FC3F7);
            color: #fff; border-color: transparent;
            box-shadow: 0 4px 12px rgba(2,136,209,0.25);
        }

        .cycle-info {
            padding: 10px 22px 12px;
            font-size: 12px;
            color: #64748b;
            border-bottom: 1px solid #f1f5f9;
            background: #fafbfc;
        }
        .cycle-info span { font-weight: 600; color: #0288D1; }

        .search-wrap {
            padding: 12px 16px;
            border-bottom: 1px solid #f1f5f9;
            position: relative;
        }
        .search-wrap input {
            width: 100%;
            padding: 9px 14px 9px 36px;
            border: 1.5px solid #e2e8f0;
            border-radius: 10px;
            font-size: 13.5px;
            background: #f8fafc;
            outline: none;
            transition: border-color 0.2s;
            font-family: 'Inter', sans-serif;
        }
        .search-wrap input:focus { border-color: #0288D1; background: #fff; }
        .search-wrap .search-icon {
            position: absolute; left: 28px; top: 50%;
            transform: translateY(-50%);
            color: #94a3b8; font-size: 13px; pointer-events: none;
        }

        .select-all-bar {
            display: flex; align-items: center; justify-content: space-between;
            padding: 10px 18px;
            border-bottom: 1px solid #f1f5f9;
            background: #f8fafc;
        }
        .select-all-bar label {
            display: flex; align-items: center; gap: 8px;
            font-size: 13px; font-weight: 600; color: #475569; cursor: pointer;
        }
        .count-badge {
            font-size: 12px; background: #e3f2fd; color: #0288D1;
            padding: 2px 10px; border-radius: 20px; font-weight: 700;
        }

        .client-list {
            flex: 1; overflow-y: auto; padding: 8px 10px;
            scrollbar-width: thin; scrollbar-color: rgba(2,136,209,0.2) transparent;
        }
        .client-list::-webkit-scrollbar { width: 4px; }
        .client-list::-webkit-scrollbar-thumb { background: rgba(2,136,209,0.2); border-radius: 4px; }

        .client-item {
            display: flex; align-items: center; gap: 10px;
            padding: 10px 12px; border-radius: 10px; cursor: pointer;
            transition: background 0.15s, box-shadow 0.15s;
            border: 1.5px solid transparent; margin-bottom: 3px;
        }
        .client-item:hover           { background: #f0f9ff; border-color: #bae6fd; }
        .client-item.selected        { background: #e3f2fd; border-color: #7dd3fc; }
        .client-item.active-preview  {
            background: linear-gradient(135deg, #e3f2fd, #f0f9ff);
            border-color: #0288D1;
            box-shadow: 0 2px 8px rgba(2,136,209,0.12);
        }

        .client-check { width: 18px; height: 18px; accent-color: #0288D1; cursor: pointer; flex-shrink: 0; }

        .client-avatar {
            width: 36px; height: 36px; border-radius: 50%;
            background: linear-gradient(135deg, #e3f2fd, #b3e5fc);
            display: flex; align-items: center; justify-content: center;
            font-size: 13px; font-weight: 700; color: #0288D1;
            flex-shrink: 0; border: 1.5px solid rgba(2,136,209,0.2);
        }

        .client-info { flex: 1; min-width: 0; }
        .client-name {
            font-size: 13.5px; font-weight: 700; color: #0f172a;
            white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
        }
        .client-email {
            font-size: 11.5px; color: #64748b;
            white-space: nowrap; overflow: hidden; text-overflow: ellipsis; margin-top: 2px;
        }
        .no-email-badge {
            font-size: 10px; background: #fee2e2; color: #dc2626;
            padding: 2px 7px; border-radius: 6px; font-weight: 600; flex-shrink: 0;
        }

        /* Skeleton */
        .skeleton {
            background: linear-gradient(90deg, #f1f5f9 25%, #e2e8f0 50%, #f1f5f9 75%);
            background-size: 200% 100%;
            animation: shimmer 1.2s infinite;
            border-radius: 8px;
        }
        @keyframes shimmer {
            0%   { background-position: -200% 0; }
            100% { background-position:  200% 0; }
        }
        .skeleton-item { display: flex; gap: 10px; padding: 10px 12px; margin-bottom: 3px; }

        /* Send bar */
        .send-bar { padding: 14px 16px; border-top: 1.5px solid #e2e8f0; background: #fff; }
        .send-btn {
            width: 100%; padding: 12px;
            background: linear-gradient(135deg, #0288D1, #4FC3F7);
            color: #fff; font-size: 14.5px; font-weight: 700;
            border: none; border-radius: 12px; cursor: pointer;
            transition: all 0.2s;
            display: flex; align-items: center; justify-content: center; gap: 8px;
            box-shadow: 0 4px 14px rgba(2,136,209,0.3);
        }
        .send-btn:hover:not(:disabled)  { transform: translateY(-1px); box-shadow: 0 6px 20px rgba(2,136,209,0.38); }
        .send-btn:active:not(:disabled) { transform: translateY(0); }
        .send-btn:disabled { background: #cbd5e1; box-shadow: none; cursor: not-allowed; }

        /* ── RIGHT PANEL ── */
        .right-panel {
            flex: 1; display: flex; flex-direction: column;
            overflow: hidden; background: #f0f6fb;
        }

        .preview-header {
            padding: 20px 28px 16px; border-bottom: 1px solid #e2e8f0;
            background: #fff; display: flex; align-items: center; justify-content: space-between;
        }
        .preview-header h3   { font-size: 16px; font-weight: 700; color: #0f172a; }
        .preview-header .preview-meta { font-size: 12.5px; color: #64748b; margin-top: 3px; }

        .preview-body {
            flex: 1; overflow-y: auto; padding: 28px;
            display: flex; align-items: flex-start; justify-content: center;
        }

        .email-card {
            background: #fff; border-radius: 16px;
            box-shadow: 0 4px 24px rgba(0,0,0,0.07);
            width: 100%; max-width: 680px;
            border: 1px solid #e2e8f0; overflow: hidden;
        }
        .email-card-header {
            background: linear-gradient(135deg, #0288D1, #4FC3F7);
            padding: 20px 28px; color: #fff;
        }
        .email-card-header .subject  { font-size: 15px; font-weight: 700; margin-bottom: 6px; }
        .email-card-header .to-field { font-size: 12.5px; opacity: 0.88; }
        .email-card-body { padding: 28px; }
        .email-line {
            font-size: 15px; line-height: 1.8; color: #1e293b;
            white-space: pre-wrap; font-family: 'Inter', sans-serif;
        }
        .email-regards {
            margin-top: 28px; padding-top: 18px;
            border-top: 1px solid #f1f5f9;
            font-size: 14px; line-height: 1.9; color: #334155;
        }
        .email-regards .rm-name   { font-weight: 700; color: #0288D1; font-size: 15px; }
        .email-regards .rm-detail { display: flex; align-items: center; gap: 6px; font-size: 13px; color: #475569; }
        .email-regards .rm-detail i { color: #0288D1; width: 16px; }

        /* Empty state */
        .empty-state {
            display: flex; flex-direction: column; align-items: center; justify-content: center;
            height: 100%; color: #94a3b8; gap: 14px; text-align: center; padding: 40px;
        }
        .empty-state i { font-size: 52px; opacity: 0.3; }
        .empty-state p { font-size: 15px; font-weight: 500; }
        .empty-state small { font-size: 13px; opacity: 0.7; }

        /* Toast */
        .toast-container {
            position: fixed; bottom: 28px; right: 28px; z-index: 9999;
            display: flex; flex-direction: column; gap: 10px;
        }
        .toast {
            padding: 14px 20px; border-radius: 12px;
            font-size: 14px; font-weight: 600;
            box-shadow: 0 8px 24px rgba(0,0,0,0.14);
            display: flex; align-items: center; gap: 10px;
            animation: slideInRight 0.3s ease; max-width: 360px;
        }
        @keyframes slideInRight {
            from { transform: translateX(120%); opacity: 0; }
            to   { transform: translateX(0);    opacity: 1; }
        }
        .toast.success { background: #dcfce7; color: #15803d; border: 1.5px solid #86efac; }
        .toast.error   { background: #fee2e2; color: #dc2626; border: 1.5px solid #fca5a5; }
        .toast.info    { background: #e3f2fd; color: #0288D1; border: 1.5px solid #7dd3fc; }

        /* Modal */
        .modal-backdrop {
            position: fixed; inset: 0;
            background: rgba(15,23,42,0.45); backdrop-filter: blur(4px);
            z-index: 2000; display: none; align-items: center; justify-content: center;
        }
        .modal-backdrop.open { display: flex; }
        .modal-box {
            background: #fff; border-radius: 20px; padding: 32px 36px;
            max-width: 440px; width: 90%;
            box-shadow: 0 20px 60px rgba(0,0,0,0.18); text-align: center;
        }
        .modal-icon { font-size: 42px; margin-bottom: 16px; }
        .modal-box h3 { font-size: 18px; font-weight: 800; margin-bottom: 10px; color: #0f172a; }
        .modal-box p  { font-size: 14px; color: #64748b; line-height: 1.6; margin-bottom: 26px; }
        .modal-actions { display: flex; gap: 12px; justify-content: center; }
        .modal-btn { padding: 11px 28px; border-radius: 10px; font-size: 14px; font-weight: 700; cursor: pointer; border: none; transition: all 0.2s; }
        .modal-btn.cancel  { background: #f1f5f9; color: #64748b; }
        .modal-btn.cancel:hover { background: #e2e8f0; }
        .modal-btn.confirm { background: linear-gradient(135deg, #0288D1, #4FC3F7); color: #fff; box-shadow: 0 4px 12px rgba(2,136,209,0.3); }
        .modal-btn.confirm:hover { transform: translateY(-1px); }

        /* Spinner */
        .spinner {
            width: 16px; height: 16px;
            border: 2.5px solid rgba(255,255,255,0.4); border-top-color: #fff;
            border-radius: 50%; animation: spin 0.7s linear infinite; display: none;
        }
        @keyframes spin { to { transform: rotate(360deg); } }

        /* ── EDITABLE EMAIL STYLES ── */
        .edit-mode-banner {
            display: flex;
            align-items: center;
            gap: 8px;
            padding: 8px 12px;
            background: #f0f9ff;
            border: 1px dashed #7dd3fc;
            border-radius: 8px;
            margin-bottom: 18px;
            font-size: 12px;
            color: #0284c7;
            font-weight: 600;
        }
        .edit-badge {
            display: flex;
            align-items: center;
            gap: 4px;
            background: #fef3c7;
            color: #92400e;
            padding: 2px 8px;
            border-radius: 10px;
            font-size: 11px;
        }
        .reset-btn {
            margin-left: auto;
            background: none;
            border: 1px solid #7dd3fc;
            color: #0284c7;
            font-size: 11px;
            font-weight: 700;
            padding: 3px 10px;
            border-radius: 6px;
            cursor: pointer;
            transition: all 0.2s;
            font-family: 'Inter', sans-serif;
        }
        .reset-btn:hover { background: #e0f2fe; }

        [contenteditable="true"] {
            outline: none;
            border-radius: 6px;
            transition: background 0.15s, box-shadow 0.15s;
            cursor: text;
        }
        [contenteditable="true"]:hover {
            background: #f8fafc;
        }
        [contenteditable="true"]:focus {
            background: #f0f9ff;
            box-shadow: 0 0 0 2px rgba(2,136,209,0.2);
            padding: 4px 8px;
        }

        /* Regards editable section */
        .regards-editable {
            margin-top: 28px;
            padding-top: 18px;
            border-top: 1px solid #f1f5f9;
        }
        .regards-label {
            font-weight: 600;
            color: #475569;
            margin-bottom: 6px;
            font-size: 14px;
        }
        .regards-field.rm-name {
            font-weight: 700;
            color: #0288D1;
            font-size: 15px;
            min-height: 24px;
        }
        .regards-field.rm-company {
            font-size: 13px;
            color: #64748b;
            margin-top: 2px;
            min-height: 20px;
        }
        .regards-fields-row {
            display: flex;
            align-items: center;
            gap: 8px;
            margin-top: 6px;
        }
        .regards-field-icon {
            color: #0288D1;
            width: 16px;
            font-size: 13px;
            flex-shrink: 0;
        }
        .regards-field {
            font-size: 13px;
            color: #475569;
            flex: 1;
            min-height: 20px;
        }
    </style>
</head>
<body>
<?php include 'navbar.php'; ?>

<div class="page-wrapper">

    <!-- LEFT PANEL -->
    <div class="left-panel">
        <div class="panel-header">
            <h2><i class="fa-solid fa-envelope-open-text" style="color:#0288D1;margin-right:8px;"></i>Follow-Up Mails</h2>
            <div class="cycle-tabs">
                <button class="cycle-tab <?= $defaultCycle === 'RJ' ? 'active' : '' ?>" data-cycle="RJ">
                    RJ <span style="font-size:10px;font-weight:500;display:block;">Jan · Apr · Jul · Oct</span>
                </button>
                <button class="cycle-tab <?= $defaultCycle === 'RF' ? 'active' : '' ?>" data-cycle="RF">
                    RF <span style="font-size:10px;font-weight:500;display:block;">Feb · May · Aug · Nov</span>
                </button>
                <button class="cycle-tab <?= $defaultCycle === 'RM' ? 'active' : '' ?>" data-cycle="RM">
                    RM <span style="font-size:10px;font-weight:500;display:block;">Mar · Jun · Sep · Dec</span>
                </button>
            </div>
        </div>

        <div class="cycle-info" id="cycleInfo">
            Loading clients for <span id="cycleInfoText"><?= $defaultCycle ?></span> cycle...
        </div>

        <div class="search-wrap">
            <i class="fa-solid fa-magnifying-glass search-icon"></i>
            <input type="text" id="searchInput" placeholder="Search clients...">
        </div>

        <div class="select-all-bar">
            <label>
                <input type="checkbox" id="selectAll"> Select All
            </label>
            <span class="count-badge" id="selectedCount">0 selected</span>
        </div>

        <div class="client-list" id="clientList"></div>

        <div class="send-bar">
            <button class="send-btn" id="sendBtn" disabled>
                <div class="spinner" id="sendSpinner"></div>
                <i class="fa-solid fa-paper-plane" id="sendIcon"></i>
                <span id="sendBtnText">Send Follow-Up Mails</span>
            </button>
        </div>
    </div>

    <!-- RIGHT PANEL -->
    <div class="right-panel">
        <div class="preview-header">
            <div>
                <h3><i class="fa-regular fa-eye" style="color:#0288D1;margin-right:8px;"></i>Email Preview</h3>
                <div class="preview-meta" id="previewMeta">Click a client to preview their email</div>
            </div>
        </div>

        <div class="preview-body">
            <div class="empty-state" id="emptyState">
                <i class="fa-regular fa-envelope"></i>
                <p>No client selected</p>
                <small>Click on any client from the list to preview their personalised follow-up email.</small>
            </div>

            <div class="email-card" id="emailCard" style="display:none;">
                <div class="email-card-header">
                    <div class="subject">Your Quarterly Review – Let's Connect</div>
                    <div class="to-field" id="previewTo">To: —</div>
                </div>
                <div class="email-card-body">
                    <div class="edit-mode-banner" id="editBanner">
                        <i class="fa-solid fa-pen-to-square"></i>
                        <span>Click anywhere in the email to edit</span>
                        <span class="edit-badge" id="editBadge" style="display:none;">
                            <i class="fa-solid fa-circle" style="font-size:7px;color:#f59e0b;"></i> Edited
                        </span>
                        <button class="reset-btn" id="resetEditBtn" onclick="resetCurrentEdit()" style="display:none;">
                            <i class="fa-solid fa-rotate-left"></i> Reset
                        </button>
                    </div>
                    <div class="email-line"
                         id="previewBody"
                         contenteditable="true"
                         spellcheck="true"
                         data-client-id=""></div>
                    <div class="regards-editable" id="previewRemarks">
                        <div class="regards-label">Regards,</div>
                        <div class="regards-field rm-name" id="editRmName" contenteditable="true" title="Click to edit RM name"></div>
                        <div class="regards-field rm-company" id="editCompany" contenteditable="true" title="Click to edit company"></div>
                        <div class="regards-fields-row">
                            <div class="regards-field-icon"><i class="fa-solid fa-phone"></i></div>
                            <div class="regards-field" id="editRmMobile" contenteditable="true" title="Click to edit mobile"></div>
                        </div>
                        <div class="regards-fields-row">
                            <div class="regards-field-icon"><i class="fa-solid fa-envelope"></i></div>
                            <div class="regards-field" id="editRmEmail" contenteditable="true" title="Click to edit email"></div>
                        </div>
                        <div class="regards-fields-row">
                            <div class="regards-field-icon"><i class="fa-solid fa-globe"></i></div>
                            <div class="regards-field" id="editWebsite" contenteditable="true" title="Click to edit website"></div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Confirm Modal -->
<div class="modal-backdrop" id="confirmModal">
    <div class="modal-box">
        <div class="modal-icon">📬</div>
        <h3>Send Follow-Up Emails?</h3>
        <p id="confirmText">You are about to send emails to <strong>0</strong> clients. This action cannot be undone.</p>
        <div class="modal-actions">
            <button class="modal-btn cancel" id="modalCancel">Cancel</button>
            <button class="modal-btn confirm" id="modalConfirm">Yes, Send All</button>
        </div>
    </div>
</div>

<div class="toast-container" id="toastContainer"></div>

<script>
const cycleMap = {
    RJ: ['Jan', 'Apr', 'Jul', 'Oct'],
    RF: ['Feb', 'May', 'Aug', 'Nov'],
    RM: ['Mar', 'Jun', 'Sep', 'Dec']
};

let allClients      = [];
let filteredClients = [];
let selectedIds     = new Set();
let activePreviewId = null;
let currentCycle    = '<?= $defaultCycle ?>';

document.addEventListener('DOMContentLoaded', () => {
    loadClients(currentCycle);

    document.querySelectorAll('.cycle-tab').forEach(tab => {
        tab.addEventListener('click', () => {
            document.querySelectorAll('.cycle-tab').forEach(t => t.classList.remove('active'));
            tab.classList.add('active');
            currentCycle = tab.dataset.cycle;
            selectedIds.clear();
            activePreviewId = null;
            loadClients(currentCycle);
        });
    });

    document.getElementById('searchInput').addEventListener('input', e => {
        const q = e.target.value.toLowerCase();
        filteredClients = allClients.filter(c =>
            c.name.toLowerCase().includes(q) ||
            (c.email || '').toLowerCase().includes(q)
        );
        renderList();
    });

    document.getElementById('selectAll').addEventListener('change', e => {
        if (e.target.checked) {
            filteredClients.forEach(c => { if (c.email) selectedIds.add(String(c.id)); });
        } else {
            filteredClients.forEach(c => selectedIds.delete(String(c.id)));
        }
        renderList();
        updateSendBtn();
    });

    document.getElementById('sendBtn').addEventListener('click', () => {
        if (selectedIds.size === 0) return;
        document.getElementById('confirmText').innerHTML =
            `You are about to send emails to <strong>${selectedIds.size}</strong> client${selectedIds.size > 1 ? 's' : ''}. This action cannot be undone.`;
        document.getElementById('confirmModal').classList.add('open');
    });

    document.getElementById('modalCancel').addEventListener('click',  () => document.getElementById('confirmModal').classList.remove('open'));
    document.getElementById('modalConfirm').addEventListener('click', () => { document.getElementById('confirmModal').classList.remove('open'); sendEmails(); });
});

async function loadClients(cycle) {
    document.getElementById('cycleInfo').innerHTML =
        `Loading clients for <span id="cycleInfoText">${cycle}</span> cycle...`;

    const list = document.getElementById('clientList');
    list.innerHTML = Array(6).fill('').map(() => `
        <div class="skeleton-item">
            <div class="skeleton" style="width:18px;height:18px;border-radius:4px;flex-shrink:0;"></div>
            <div class="skeleton" style="width:36px;height:36px;border-radius:50%;flex-shrink:0;"></div>
            <div style="flex:1">
                <div class="skeleton" style="height:13px;width:70%;margin-bottom:6px;"></div>
                <div class="skeleton" style="height:11px;width:50%;"></div>
            </div>
        </div>
    `).join('');
    hidePreview();

    try {
        const res  = await fetch(`followup_mails.php?ajax=clients&cycle=${cycle}`);
        const text = await res.text();

        // Try to parse — show raw text in console if it fails
        let data;
        try {
            data = JSON.parse(text);
        } catch (parseErr) {
            console.error('JSON parse error. Raw response:', text);
            list.innerHTML = `<div style="padding:16px;color:#e53935;font-size:12px;">
                <strong>Server Error:</strong><br><pre style="white-space:pre-wrap;font-size:11px;">${text.substring(0, 500)}</pre>
            </div>`;
            return;
        }

        // If server returned an error object
        if (data && data.error) {
            list.innerHTML = `<div style="padding:16px;color:#e53935;font-size:12px;">
                <strong>DB Error:</strong> ${data.error}
            </div>`;
            return;
        }

        allClients      = Array.isArray(data) ? data : [];
        filteredClients = [...allClients];

        const months = cycleMap[cycle].join(' · ');
        document.getElementById('cycleInfo').innerHTML =
            `Cycle <span>${cycle}</span> — ${months} &nbsp;|&nbsp; <strong>${allClients.length}</strong> clients`;

        renderList();
        updateSendBtn();

    } catch (err) {
        list.innerHTML = `<div style="padding:24px;text-align:center;color:#e53935;font-size:13px;">
            <i class="fa-solid fa-triangle-exclamation"></i> Network error: ${err.message}
        </div>`;
    }
}

function renderList() {
    const list = document.getElementById('clientList');

    if (filteredClients.length === 0) {
        list.innerHTML = `<div style="padding:28px;text-align:center;color:#94a3b8;font-size:13px;">
            <i class="fa-regular fa-face-meh" style="font-size:28px;display:block;margin-bottom:10px;"></i>
            No clients found.
        </div>`;
        updateCounts();
        return;
    }

    list.innerHTML = filteredClients.map(c => {
        const initials = c.name.split(' ').slice(0, 2).map(w => w[0]).join('').toUpperCase();
        const checked  = selectedIds.has(String(c.id));
        const isActive = activePreviewId === String(c.id);
        const noEmail  = !c.email;

        return `
        <div class="client-item ${checked ? 'selected' : ''} ${isActive ? 'active-preview' : ''}"
             data-id="${c.id}" onclick="handleItemClick(event, '${c.id}')">
            <input type="checkbox" class="client-check" data-id="${c.id}"
                   ${checked ? 'checked' : ''} ${noEmail ? 'disabled' : ''}
                   onclick="event.stopPropagation(); toggleSelect('${c.id}')">
            <div class="client-avatar">${initials}</div>
            <div class="client-info">
                <div class="client-name">${escHtml(c.name)}</div>
                <div class="client-email">${c.email ? escHtml(c.email) : 'No email on record'}</div>
            </div>
            ${noEmail ? '<span class="no-email-badge">No email</span>' : ''}
        </div>`;
    }).join('');

    updateCounts();
}

function escHtml(str) {
    return String(str).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}

function handleItemClick(e, id) {
    if (e.target.type === 'checkbox') return;
    activePreviewId = id;
    const client = allClients.find(c => String(c.id) === id);
    if (client) showPreview(client);
    renderList();
}

function toggleSelect(id) {
    const client = allClients.find(c => String(c.id) === id);
    if (!client || !client.email) return;
    selectedIds.has(id) ? selectedIds.delete(id) : selectedIds.add(id);
    renderList();
    updateSendBtn();
}

function updateCounts() {
    document.getElementById('selectedCount').textContent = `${selectedIds.size} selected`;
    const allWithEmail = filteredClients.filter(c => c.email);
    const allChecked   = allWithEmail.length > 0 && allWithEmail.every(c => selectedIds.has(String(c.id)));
    document.getElementById('selectAll').checked       = allChecked;
    document.getElementById('selectAll').indeterminate = !allChecked && allWithEmail.some(c => selectedIds.has(String(c.id)));
}

function updateSendBtn() {
    const btn = document.getElementById('sendBtn');
    document.getElementById('sendBtnText').textContent = selectedIds.size > 0
        ? `Send to ${selectedIds.size} Client${selectedIds.size > 1 ? 's' : ''}`
        : 'Send Follow-Up Mails';
    btn.disabled = selectedIds.size === 0;
    updateCounts();
}

// Stores per-client edits: { clientId: { body, rmName, company, rmMobile, rmEmail, website } }
const clientEdits = {};

function getDefaultContent(client) {
    const rmName   = client.rm_full_name || client.rm || 'Admin';
    const rmEmail  = client.rm_email    || 'contact@financedoctor.in';
    const rmMobile = client.rm_mobile   || '7799397314';
    const company  = client.company_name || 'Finance Doctor Private Limited';
    const website  = client.website_url  || 'www.financedoctor.in';
    const cName    = client.name.toUpperCase();

    return {
        body: `Dear ${cName},\n\nI hope you are doing well.\n\nWe have sent your quarterly review for this month, it would be good to connect over a Zoom meeting to walk through the review together, understand your current priorities, and discuss the way forward.\n\nPlease let me know your convenience, and I will be happy to schedule the meeting accordingly.\n\nLooking forward to speaking with you.`,
        rmName, rmEmail, rmMobile, company, website
    };
}

function saveCurrentEdits(clientId) {
    if (!clientId) return;
    const body     = document.getElementById('previewBody').innerText;
    const rmName   = document.getElementById('editRmName').innerText;
    const company  = document.getElementById('editCompany').innerText;
    const rmMobile = document.getElementById('editRmMobile').innerText;
    const rmEmail  = document.getElementById('editRmEmail').innerText;
    const website  = document.getElementById('editWebsite').innerText;
    clientEdits[clientId] = { body, rmName, company, rmMobile, rmEmail, website };
    updateEditBadge(clientId);
}

function updateEditBadge(clientId) {
    const client  = allClients.find(c => String(c.id) === String(clientId));
    if (!client) return;
    const defaults = getDefaultContent(client);
    const edits    = clientEdits[clientId];
    if (!edits) return;

    const isEdited =
        edits.body     !== defaults.body     ||
        edits.rmName   !== defaults.rmName   ||
        edits.company  !== defaults.company  ||
        edits.rmMobile !== defaults.rmMobile ||
        edits.rmEmail  !== defaults.rmEmail  ||
        edits.website  !== defaults.website;

    document.getElementById('editBadge').style.display  = isEdited ? 'flex'  : 'none';
    document.getElementById('resetEditBtn').style.display = isEdited ? 'inline-flex' : 'none';
}

function resetCurrentEdit() {
    const clientId = document.getElementById('previewBody').dataset.clientId;
    if (!clientId) return;
    delete clientEdits[clientId];
    const client = allClients.find(c => String(c.id) === String(clientId));
    if (client) showPreview(client);
}

function showPreview(client) {
    // Save edits for previously viewed client
    const prevId = document.getElementById('previewBody').dataset.clientId;
    if (prevId && prevId !== String(client.id)) saveCurrentEdits(prevId);

    const id = String(client.id);
    const saved    = clientEdits[id];
    const defaults = getDefaultContent(client);
    const content  = saved || defaults;

    document.getElementById('emptyState').style.display = 'none';
    document.getElementById('emailCard').style.display  = 'block';
    document.getElementById('previewTo').textContent    = `To: ${client.name}${client.email ? ' — ' + client.email : ' (No email)'}`;
    document.getElementById('previewMeta').textContent  = `Previewing email for ${client.name}`;

    const bodyEl = document.getElementById('previewBody');
    bodyEl.innerText           = content.body;
    bodyEl.dataset.clientId    = id;

    document.getElementById('editRmName').innerText   = content.rmName;
    document.getElementById('editCompany').innerText  = content.company;
    document.getElementById('editRmMobile').innerText = content.rmMobile;
    document.getElementById('editRmEmail').innerText  = content.rmEmail;
    document.getElementById('editWebsite').innerText  = content.website;

    updateEditBadge(id);

    // Auto-save on every input
    const editableIds = ['previewBody','editRmName','editCompany','editRmMobile','editRmEmail','editWebsite'];
    editableIds.forEach(elId => {
        const el = document.getElementById(elId);
        el.oninput = () => {
            const cid = document.getElementById('previewBody').dataset.clientId;
            saveCurrentEdits(cid);
        };
    });
}

function hidePreview() {
    document.getElementById('emptyState').style.display = 'flex';
    document.getElementById('emailCard').style.display  = 'none';
    document.getElementById('previewMeta').textContent  = 'Click a client to preview their email';
}

async function sendEmails() {
    const btn     = document.getElementById('sendBtn');
    const spinner = document.getElementById('sendSpinner');
    const icon    = document.getElementById('sendIcon');
    const txt     = document.getElementById('sendBtnText');

    btn.disabled          = true;
    spinner.style.display = 'block';
    icon.style.display    = 'none';
    txt.textContent       = 'Sending...';

    try {
        // Save any current edits before sending
        const currentId = document.getElementById('previewBody').dataset.clientId;
        if (currentId) saveCurrentEdits(currentId);

        // Build full email content per selected client
        const customBodies = {};
        selectedIds.forEach(id => {
            const client   = allClients.find(c => String(c.id) === id);
            const edits    = clientEdits[id];
            const defaults = client ? getDefaultContent(client) : null;
            const content  = edits || defaults;
            if (content) {
                customBodies[id] = content.body + '\n\nRegards,\n' + content.rmName + ',\n' + content.company + '.\nMobile - ' + content.rmMobile + '\nEmail - ' + content.rmEmail + '\nUrl: ' + content.website;
            }
        });

        const formData = new FormData();
        formData.append('action', 'send_emails');
        formData.append('client_ids', JSON.stringify([...selectedIds]));
        formData.append('custom_bodies', JSON.stringify(customBodies));

        const res  = await fetch('followup_mails.php', { method: 'POST', body: formData });
        const rawText = await res.text();
        let data;
        try {
            data = JSON.parse(rawText);
        } catch (parseErr) {
            console.error('POST response (not JSON):', rawText);
            showToast('❌ Server error: ' + rawText.substring(0, 120), 'error', 8000);
            return;
        }

        if (data.success) {
            if (data.sent > 0)           showToast(`✅ Successfully sent ${data.sent} email${data.sent > 1 ? 's' : ''}!`, 'success');
            if (data.failed.length > 0)  showToast(`⚠️ Failed: ${data.failed.slice(0,3).join(', ')}${data.failed.length > 3 ? '…' : ''}`, 'error', 6000);
            selectedIds.clear();
            renderList();
            updateSendBtn();
        } else {
            showToast('❌ ' + (data.message || 'Something went wrong.'), 'error');
        }
    } catch (err) {
        showToast('❌ Error: ' + err.message, 'error');
        console.error('sendEmails error:', err);
    } finally {
        btn.disabled          = false;
        spinner.style.display = 'none';
        icon.style.display    = 'inline';
        updateSendBtn();
    }
}

function showToast(message, type = 'info', duration = 4000) {
    const container = document.getElementById('toastContainer');
    const toast = document.createElement('div');
    toast.className = `toast ${type}`;
    toast.textContent = message;
    container.appendChild(toast);
    setTimeout(() => {
        toast.style.opacity   = '0';
        toast.style.transform = 'translateX(120%)';
        toast.style.transition = '0.3s ease';
        setTimeout(() => toast.remove(), 300);
    }, duration);
}
</script>
</body>
</html>