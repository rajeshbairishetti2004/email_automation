<?php
// send_email.php
require_once 'db_config.php';

$domainProfiles = require __DIR__ . '/organization_emails.php';
$sendAsProfiles = $domainProfiles;
$clientId = (int)($clientId ?? 0);

// Fixed list for CC section - only defined once
$allEmails = [
    'contact@financedoctor.in',
    'tanmay.vyas@financedoctor.in',
    'akshay.krishna@financedoctor.in',
    'sajid.ali@financedoctor.in',
    'vivek.sharma@financedoctor.in',
    'sailesh.mulleti@financedoctor.in'
];

// Handle auto-save for follow-up email (MUST BE AT TOP OF FILE)
if (isset($_POST['autosave_followup'])) {
    // Enable error reporting for debugging
    error_reporting(E_ALL);
    ini_set('display_errors', 1);
    
    header('Content-Type: application/json');
    
    $client_id = $_POST['client_id'] ?? null;
    $body = $_POST['email_body'] ?? '';
    
    // Debug logging
    error_log("DEBUG: Auto-save called. Client ID: $client_id, Body length: " . strlen($body));
    
    if (!$client_id || empty($body)) {
        error_log("DEBUG: Missing client_id or empty body");
        echo json_encode(['success' => false, 'error' => 'Missing data']);
        exit;
    }
    
    try {
        // Check database connection
        if (!$pdo) {
            error_log("DEBUG: Database connection failed");
            echo json_encode(['success' => false, 'error' => 'Database connection failed']);
            exit;
        }
        
        // Log the query we're about to execute
        error_log("DEBUG: Preparing to insert draft for client_id: $client_id");
        
        $stmt = $pdo->prepare("
            INSERT INTO email_logs (client_id, email_body, email_type)
            VALUES (?, ?, 'followup_draft')
        ");
        
        $result = $stmt->execute([$client_id, $body]);
        
        if ($result) {
            $lastId = $pdo->lastInsertId();
            error_log("DEBUG: Auto-save successful. Record ID: $lastId for client_id: $client_id");
            echo json_encode(['success' => true, 'id' => $lastId]);
        } else {
            error_log("DEBUG: Auto-save failed. Execute returned false");
            echo json_encode(['success' => false, 'error' => 'Insert failed']);
        }
    } catch (Exception $e) {
        error_log("DEBUG: Exception during auto-save: " . $e->getMessage());
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

// Load last saved draft for this client
function loadLastDraft($pdo, $client_id) {
    if (!$client_id) return '';
    
    try {
        $stmt = $pdo->prepare("
            SELECT email_body 
            FROM email_logs 
            WHERE client_id = ? AND email_type = 'followup_draft'
            ORDER BY sent_at DESC 
            LIMIT 1
        ");
        $stmt->execute([$client_id]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        
        return $result ? $result['email_body'] : '';
    } catch (Exception $e) {
        error_log("DEBUG: Error loading draft: " . $e->getMessage());
        return '';
    }
}

try {
    $allClientEmails = [];
    $stmt = $pdo->query("SELECT DISTINCT email FROM clients WHERE email IS NOT NULL AND email <> '' ORDER BY email ASC");
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $allClientEmails[] = $row['email'];
    }
} catch (Exception $e) {
    $allClientEmails = [];
}

// If AJAX request for email search
if (isset($_GET['search_emails']) && isset($_GET['query'])) {
    header('Content-Type: application/json');
    $query = strtolower(trim($_GET['query']));
    $results = [];
    
    // Search from beginning of email (username part before @)
    foreach ($allClientEmails as $email) {
        // Get the username part (before @)
        $username = strtolower(explode('@', $email)[0]);
        
        // Check if query matches from the beginning of username
        if (strpos($username, $query) === 0) {
            $results[] = $email;
        }
    }
    
    echo json_encode($results);
    exit();
}

// Process form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['send_email'])) {
    $client_id = $_POST['client_id'] ?? 0;
    $recipient_email = $_POST['recipient_email'] ?? '';
    $cc_emails = $_POST['cc_emails'] ?? '';
    $from_email = $_POST['from_email'] ?? '';
    $from_name = $_POST['from_name'] ?? '';
    $send_second_email = isset($_POST['send_second_email']) ? 1 : 0;
    $followup_body = $_POST['followup_email_body'] ?? '';
    
    // Get client name for second email
    $client_name = '';
    if ($client_id > 0) {
        $stmt = $pdo->prepare("SELECT name FROM clients WHERE id = ?");
        $stmt->execute([$client_id]);
        $client = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($client) {
            $client_name = $client['name'];
        }
    }
    
    // Send first email (existing functionality)
    // ... existing email sending code ...
    
    // If second email checkbox is checked, send the second email
    if ($send_second_email && !empty($recipient_email) && !empty($client_name)) {
        sendSecondEmail($recipient_email, $from_email, $from_name, $client_name, $cc_emails, $followup_body, $client_id, $pdo);
    }
}

/**
 * Send the second email (Zoom meeting invitation) - UPDATED VERSION
 */
function sendSecondEmail($to, $from_email, $from_name, $client_name, $cc_emails, $body, $client_id, $pdo) {
    $subject = "Follow-up: Portfolio Review Discussion - Finance Doctor";
    
    // Replace [Client Name] placeholder with actual client name
    $body = str_replace('[Client Name]', $client_name, $body);
    
    // Prepare headers
    $headers = "From: $from_name <$from_email>\r\n";
    $headers .= "Reply-To: $from_email\r\n";
    $headers .= "MIME-Version: 1.0\r\n";
    $headers .= "Content-Type: text/plain; charset=UTF-8\r\n";
    
    // Add CC emails if provided
    if (!empty($cc_emails)) {
        $headers .= "CC: " . $cc_emails . "\r\n";
    }
    
    // Send email
    $mail_sent = mail($to, $subject, $body, $headers);
    
    // Log the email sending to database
    if ($mail_sent) {
        $stmt = $pdo->prepare("
            INSERT INTO email_logs 
            (client_id, from_email, from_name, sent_to_email, sent_to_name, cc_emails, email_body, email_type) 
            VALUES (?, ?, ?, ?, ?, ?, ?, 'followup_sent')
        ");
        $stmt->execute([
            $client_id,
            $from_email,
            $from_name,
            $to,
            $client_name,
            $cc_emails,
            $body
        ]);
        
        error_log("Second email sent successfully to: $to and saved to database");
        
        // Also update any draft records to mark them as sent (optional)
        try {
            $stmt = $pdo->prepare("
                UPDATE email_logs 
                SET email_type = 'followup_draft_sent' 
                WHERE client_id = ? AND email_type = 'followup_draft'
            ");
            $stmt->execute([$client_id]);
        } catch (Exception $e) {
            // Just log error, don't stop execution
            error_log("Note: Could not update draft records: " . $e->getMessage());
        }
    } else {
        error_log("Failed to send second email to: $to");
    }
    
    return $mail_sent;
}

// Load last draft for current client
$lastDraft = loadLastDraft($pdo, $clientId);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Email Communication Center</title>
    <link rel="stylesheet" href="public/css/send_email.css">
    <style>
        /* Additional styles for second email option */
        .second-email-section {
            margin-top: 30px;
            padding: 20px;
            background: #f8f9ff;
            border-radius: 12px;
            border: 1px solid #e0e7ff;
        }
        
        .second-email-header {
            display: flex;
            align-items: center;
            margin-bottom: 15px;
        }
        
        .second-email-icon {
            margin-right: 12px;
            color: #2E75B6;
        }
        
        .second-email-title {
            font-size: 18px;
            font-weight: 600;
            color: #2E75B6;
            margin: 0;
        }
        
        .second-email-subtitle {
            font-size: 14px;
            color: #666;
            margin-top: 4px;
        }
        
        .toggle-switch {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 15px;
            background: white;
            border-radius: 8px;
            border: 1px solid #e0e7ff;
            margin-bottom: 15px;
            cursor: pointer;
            transition: all 0.3s ease;
        }
        
        .toggle-switch:hover {
            border-color: #4F7DF3;
            box-shadow: 0 2px 8px rgba(79, 125, 243, 0.1);
        }
        
        .toggle-switch.active {
            border-color: #4F7DF3;
            background: #f0f7ff;
        }
        
        .toggle-label {
            display: flex;
            align-items: center;
            font-size: 16px;
            font-weight: 600;
            color: #333;
        }
        
        .toggle-icon {
            margin-right: 10px;
            color: #4F7DF3;
        }
        
        .toggle-switch-control {
            position: relative;
            width: 60px;
            height: 30px;
        }
        
        .toggle-checkbox {
            display: none;
        }
        
        .toggle-slider {
            position: absolute;
            cursor: pointer;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background-color: #ccc;
            transition: .4s;
            border-radius: 34px;
        }
        
        .toggle-slider:before {
            position: absolute;
            content: "";
            height: 22px;
            width: 22px;
            left: 4px;
            bottom: 4px;
            background-color: white;
            transition: .4s;
            border-radius: 50%;
        }
        
        .toggle-checkbox:checked + .toggle-slider {
            background-color: #4F7DF3;
        }
        
        .toggle-checkbox:checked + .toggle-slider:before {
            transform: translateX(30px);
        }
        
        .second-email-preview-container {
            max-height: 0;
            overflow: hidden;
            transition: max-height 0.3s ease-out;
        }
        
        .second-email-preview-container.visible {
            max-height: 500px;
            transition: max-height 0.5s ease-in;
        }
        
        .info-note {
            background: #fff3cd;
            border: 1px solid #ffeeba;
            border-radius: 6px;
            padding: 12px 15px;
            margin-top: 15px;
            font-size: 14px;
            color: #856404;
        }
        
        .draft-notice {
            background: #d1ecf1;
            border: 1px solid #bee5eb;
            border-radius: 6px;
            padding: 10px 15px;
            margin-top: 10px;
            font-size: 13px;
            color: #0c5460;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        
        .auto-save-status {
            font-size: 12px;
            color: #6c757d;
            margin-top: 8px;
            text-align: right;
            display: flex;
            align-items: center;
            justify-content: flex-end;
            gap: 5px;
            opacity: 0.8;
        }
        
        .saving {
            color: #ffc107;
        }
        
        .saved {
            color: #28a745;
        }
        
        .error {
            color: #dc3545;
        }
        
        /* Debug panel - only shows when needed */
        .debug-panel {
            background: #f8f9fa;
            border: 1px solid #dee2e6;
            border-radius: 6px;
            padding: 15px;
            margin-top: 15px;
            font-size: 12px;
            font-family: monospace;
            display: none;
        }
        
        .debug-toggle {
            background: #6c757d;
            color: white;
            border: none;
            padding: 5px 10px;
            border-radius: 4px;
            cursor: pointer;
            font-size: 11px;
            margin-top: 10px;
        }
    </style>
</head>
<body>
    <div class="email-send-container">
        <form method="post" enctype="multipart/form-data" class="email-form" onsubmit="return validateForm()">
            <input type="hidden" name="send_email" value="1">
            <input type="hidden" name="client_id" value="<?php echo (int)$clientId; ?>">
            <input type="hidden" name="cc_emails" id="cc_emails">
            
            <div class="communication-box-style">
                <div class="email-fields-container">
                    <div class="form-row">
                        <!-- Send As Dropdown -->
                        <div class="email-field-group">
                            <label>Send As</label>

                            <div class="sendas-wrapper" id="sendAsWrapper">
                                <div class="sendas-input" onclick="toggleSendAsDropdown()">
                                    <div class="sendas-avatar" id="sendAsAvatar">FD</div>
                                    <div class="sendas-text">
                                        <div class="sendas-name" id="sendAsName">Select sender profile</div>
                                        <div class="sendas-role" id="sendAsRole">Click to choose</div>
                                    </div>
                                    <span class="sendas-arrow">⌄</span>
                                </div>

                                <div class="sendas-dropdown" id="sendAsDropdown">
                                    <input
                                        type="text"
                                        class="sendas-search"
                                        placeholder="Search sender..."
                                        oninput="filterSendAs(this.value)"
                                    >

                                    <?php foreach ($sendAsProfiles as $email => $profile): ?>
                                        <div
                                            class="sendas-item"
                                            onclick="selectSendAs(
                                                '<?php echo htmlspecialchars($email); ?>',
                                                '<?php echo htmlspecialchars($profile['name']); ?>',
                                                '<?php echo htmlspecialchars($profile['designation']); ?>'
                                            )"
                                        >
                                            <div class="sendas-item-avatar">
                                                <?php echo strtoupper(substr($profile['name'], 0, 2)); ?>
                                            </div>
                                            <div class="sendas-item-info">
                                                <div class="sendas-item-name"><?php echo htmlspecialchars($profile['name']); ?></div>
                                                <div class="sendas-item-role"><?php echo htmlspecialchars($profile['designation']); ?></div>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            </div>

                            <input type="hidden" name="from_email" id="from_email_hidden">
                            <input type="hidden" name="from_name" id="from_name_hidden">
                        </div>
                        
                        <!-- Smart Email Input -->
                        <div class="email-field-group">
                            <label for="recipient_email_search">Primary Recipient (To)</label>
                            <div class="search-container">
                                <div class="search-input-wrapper">
                                    <span class="search-icon">
                                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                            <circle cx="11" cy="11" r="8"></circle>
                                            <line x1="21" y1="21" x2="16.65" y2="16.65"></line>
                                        </svg>
                                    </span>
                                    <input type="email" 
                                           id="recipient_email_search" 
                                           class="modern-search-input" 
                                           placeholder="Start typing email address..." 
                                           autocomplete="off"
                                           oninput="handleEmailInput()"
                                           onblur="validateEmailOnBlur()">
                                    <button type="button" class="search-clear-btn" id="search-clear-btn" onclick="clearEmailSearch()">
                                        <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3">
                                            <line x1="18" y1="6" x2="6" y2="18"></line>
                                            <line x1="6" y1="6" x2="18" y2="18"></line>
                                        </svg>
                                    </button>
                                </div>
                                
                                <input type="hidden" name="recipient_email" id="recipient_email_hidden">
                                <div id="recipient_email_list" class="modern-dropdown-list"></div>
                                <div class="typing-indicator" id="typing-indicator">
                                    <div class="loading-spinner-small"></div>
                                    <span>Searching for matching emails...</span>
                                </div>
                                <div class="email-validation" id="email-validation"></div>
                                <div class="smart-hint" id="smart-hint">
                                    Start typing to search client directory. You can also enter any email address.
                                </div>
                            </div>
                            
                            <div id="selected_email_display" class="selected-email-display">
                                <div class="selected-email-header">
                                    <span class="selected-email-label">
                                        <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="#10b981" stroke-width="3">
                                            <polyline points="20 6 9 17 4 12"></polyline>
                                        </svg>
                                        Selected Recipient
                                    </span>
                                    <button type="button" class="remove-email-btn" onclick="removeSelectedEmail()">
                                        <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3">
                                            <line x1="18" y1="6" x2="6" y2="18"></line>
                                            <line x1="6" y1="6" x2="18" y2="18"></line>
                                        </svg>
                                        Remove
                                    </button>
                                </div>
                                <div class="selected-email-content">
                                    <div class="selected-email-avatar" id="selected-email-avatar">FD</div>
                                    <div class="selected-email-info">
                                        <div class="selected-email-address" id="selected-email-address"></div>
                                        <div class="selected-email-status" id="selected-email-status">
                                            <svg width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="#10b981" stroke-width="3">
                                                <polyline points="20 6 9 17 4 12"></polyline>
                                            </svg>
                                            <span id="email-source">From client directory</span>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- CC Panel -->
                    <div class="cc-section-container">
                        <div class="cc-header">
                            <div class="cc-title-container">
                                <div class="cc-icon">
                                    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
                                        <path d="M20 4H4C2.9 4 2 4.9 2 6V18C2 19.1 2.9 20 4 20H20C21.1 20 22 19.1 22 18V6C22 4.9 21.1 4 20 4ZM19.6 6L12 11L4.4 6H19.6ZM4 18V6.97L12 12L20 6.97V18H4Z" fill="currentColor"/>
                                    </svg>
                                </div>
                                <div>
                                    <h3 class="cc-main-title">Carbon Copy (CC)</h3>
                                    <p class="cc-subtitle">Select internal team members to receive copies</p>
                                </div>
                            </div>
                            <label class="cc-select-all">
                                <input type="checkbox" id="cc_select_all" onchange="toggleCcSelectAll()">
                                <span class="cc-select-all-text">Select All</span>
                            </label>
                        </div>

                        <div class="cc-checkboxes-grid" id="cc_checkbox_list">
                            <?php foreach ($allEmails as $index => $email): ?>
                                <div class="cc-checkbox-wrapper">
                                    <input type="checkbox" 
                                           id="cc_<?php echo $index; ?>"
                                           value="<?php echo htmlspecialchars($email); ?>" 
                                           onchange="onCcCheckboxChange()"
                                           <?php echo ($index === 0) ? 'checked' : ''; ?>>
                                    <label for="cc_<?php echo $index; ?>" class="cc-checkbox-label">
                                        <span class="cc-checkbox-custom"></span>
                                        <span class="cc-email"><?php echo htmlspecialchars($email); ?></span>
                                    </label>
                                </div>
                            <?php endforeach; ?>
                        </div>
                        
                        <div class="cc-summary-card">
                            <div class="cc-summary-header">
                                <div class="cc-summary-title-group">
                                    <span class="cc-summary-icon">📋</span>
                                    <div>
                                        <h4 class="cc-summary-title">Selected CC Recipients</h4>
                                        <p class="cc-summary-subtitle" id="cc_summary_hint">
                                            <?php echo isset($allEmails[0]) ? 
                                                "Email will be copied to " . htmlspecialchars($allEmails[0]) : 
                                                'No CC recipients selected'; ?>
                                        </p>
                                    </div>
                                </div>
                                <div class="cc-count-badge" id="cc_count">1</div>
                            </div>
                            <div class="selected-emails-chips" id="selected_emails_list">
                                <?php if (isset($allEmails[0])): ?>
                                    <div class="email-chip" data-email="<?php echo htmlspecialchars($allEmails[0]); ?>">
                                        <span class="chip-email"><?php echo htmlspecialchars($allEmails[0]); ?></span>
                                        <button type="button" class="chip-remove" onclick="removeEmail('<?php echo htmlspecialchars($allEmails[0]); ?>')">
                                            <svg width="12" height="12" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
                                                <path d="M18 6L6 18M6 6L18 18" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
                                            </svg>
                                        </button>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Second Email Option Section -->
                    <div class="second-email-section" id="second-email-section">
                        <div class="second-email-header">
                            <div class="second-email-icon">
                                <svg width="24" height="24" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
                                    <path d="M20 4H4C2.9 4 2 4.9 2 6V18C2 19.1 2.9 20 4 20H20C21.1 20 22 19.1 22 18V6C22 4.9 21.1 4 20 4ZM19.6 8L12 13L4.4 8H19.6ZM4 18V8.97L12 14L20 8.97V18H4Z" fill="currentColor"/>
                                </svg>
                            </div>
                            <div>
                                <h3 class="second-email-title">Additional Follow-up Email</h3>
                                <p class="second-email-subtitle">Send a follow-up email for Zoom meeting scheduling</p>
                            </div>
                        </div>
                        
                        <!-- Toggle Switch for Second Email -->
                        <div class="toggle-switch" id="toggle-switch" onclick="toggleSecondEmail()">
                            <div class="toggle-label">
                                <span class="toggle-icon">
                                    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
                                        <path d="M20 4H4C2.9 4 2 4.9 2 6V18C2 19.1 2.9 20 4 20H20C21.1 20 22 19.1 22 18V6C22 4.9 21.1 4 20 4ZM19.6 8L12 13L4.4 8H19.6ZM4 18V8.97L12 14L20 8.97V18H4Z" fill="currentColor"/>
                                    </svg>
                                </span>
                                Send Follow-up Email for Zoom Meeting
                            </div>
                            <div class="toggle-switch-control">
                                <input type="checkbox" name="send_second_email" id="send_second_email" class="toggle-checkbox" value="1">
                                <span class="toggle-slider"></span>
                            </div>
                        </div>
                        
                        <!-- Editable Email Body with Auto-save -->
                        <div class="second-email-preview-container" id="second-email-preview">
                            <?php if (!empty($lastDraft)): ?>
                                <div class="draft-notice">
                                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                        <path d="M12 2L2 7l10 5 10-5-10-5zM2 17l10 5 10-5M2 12l10 5 10-5"></path>
                                    </svg>
                                    Last saved draft loaded
                                </div>
                            <?php endif; ?>
                            
                            <textarea id="followup_email_body" name="followup_email_body" 
                                style="width:100%;height:260px;border:1px solid #ccc;padding:12px;border-radius:8px;font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; font-size: 14px; line-height: 1.5;"
                                oninput="autoSaveFollowupEmail()"><?php echo htmlspecialchars($lastDraft ? $lastDraft : 'Dear [Client Name],

I hope you are doing well.

We have sent your quarterly review for this month. It would be good to connect over a Zoom meeting to walk through the review together, understand your current priorities, and discuss the way forward.

Please let me know your convenience, and I will be happy to schedule the meeting accordingly.

Looking forward to speaking with you.

Regards,
Sailesh Kumar Mulleti
Head of Investor Services
Finance Doctor Pvt Ltd
(O) +91 4046019753
(M) +91 9949700435'); ?></textarea>
                            
                            <div class="auto-save-status" id="auto-save-status">
                                <i></i> <span>Ready to auto-save</span>
                            </div>
                            
                            <div class="info-note">✍️ This email is editable and auto-saved.</div>
                            
                            <!-- Debug button for testing -->
                            <button type="button" class="debug-toggle" onclick="toggleDebug()">Debug Auto-save</button>
                            <div class="debug-panel" id="debug-panel">
                                <div id="debug-content">Debug info will appear here...</div>
                            </div>
                        </div>
                            
                        <div class="info-note">
                            <i>ℹ️</i> This follow-up email will be sent to the same recipient as the primary email, requesting a Zoom meeting to discuss the portfolio review.
                        </div>
                    </div>
                    
                    <!-- Submit Button -->
                    <div style="margin-top: 24px; text-align: center;">
                        <button type="submit" class="submit-button">
                            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <line x1="22" y1="2" x2="11" y2="13"></line>
                                <polygon points="22 2 15 22 11 13 2 9 22 2"></polygon>
                            </svg>
                            Send Email(s)
                        </button>
                    </div>
                </div>
            </div>
        </form>
    </div>

    <script>
    // Email data from PHP
    const emailData = <?php echo json_encode($allClientEmails); ?>;
    const ccEmailData = <?php echo json_encode($allEmails); ?>;
    const sendAsProfiles = <?php echo json_encode($sendAsProfiles); ?>;
    const clientId = <?php echo $clientId; ?>;
    
    // State variables
    let emailSearchTimeout;
    let currentSearchQuery = '';
    let lastValidatedEmail = '';
    let isSecondEmailEnabled = false;
    let autoSaveTimeout = null;
    let isAutoSaving = false;
    let debugLogs = [];
    
    // Initialize on load
    document.addEventListener('DOMContentLoaded', function() {
        updateCcSummary();
        updateSmartHint('');
        updateSendAsHint();
        
        // Focus management
        const searchInput = document.getElementById('recipient_email_search');
        searchInput.addEventListener('focus', function() {
            if (this.value.length >= 2) {
                handleEmailInput();
            }
        });
        
        // Initialize second email toggle state
        const toggleCheckbox = document.getElementById('send_second_email');
        isSecondEmailEnabled = toggleCheckbox.checked;
        
        // Show preview if draft exists or toggle is checked
        if (isSecondEmailEnabled || <?php echo !empty($lastDraft) ? 'true' : 'false'; ?>) {
            toggleSecondEmail();
        }
        
        // Test auto-save on load
        logDebug('Page loaded. Client ID: ' + clientId);
        logDebug('Last draft exists: ' + (<?php echo !empty($lastDraft) ? 'true' : 'false'; ?>));
    });
    
    function toggleDebug() {
        const panel = document.getElementById('debug-panel');
        panel.style.display = panel.style.display === 'block' ? 'none' : 'block';
    }
    
    function logDebug(message) {
        debugLogs.push(new Date().toLocaleTimeString() + ': ' + message);
        const debugContent = document.getElementById('debug-content');
        if (debugContent) {
            debugContent.innerHTML = debugLogs.join('<br>');
        }
        console.log('DEBUG:', message);
    }
    
    // Toggle second email option
    function toggleSecondEmail() {
        const toggleCheckbox = document.getElementById('send_second_email');
        const toggleSwitch = document.getElementById('toggle-switch');
        const previewContainer = document.getElementById('second-email-preview');
        
        toggleCheckbox.checked = !toggleCheckbox.checked;
        isSecondEmailEnabled = toggleCheckbox.checked;
        
        if (isSecondEmailEnabled) {
            toggleSwitch.classList.add('active');
            previewContainer.classList.add('visible');
            logDebug('Second email enabled');
        } else {
            toggleSwitch.classList.remove('active');
            previewContainer.classList.remove('visible');
            logDebug('Second email disabled');
        }
        
        // Update submit button text
        const submitBtn = document.querySelector('.submit-button');
        if (submitBtn) {
            if (isSecondEmailEnabled) {
                submitBtn.innerHTML = '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="22" y1="2" x2="11" y2="13"></line><polygon points="22 2 15 22 11 13 2 9 22 2"></polygon></svg> Send Both Emails';
            } else {
                submitBtn.innerHTML = '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="22" y1="2" x2="11" y2="13"></line><polygon points="22 2 15 22 11 13 2 9 22 2"></polygon></svg> Send Email';
            }
        }
    }
    
    // Auto-save follow-up email
    function autoSaveFollowupEmail() {
        const body = document.getElementById('followup_email_body').value;
        const statusElement = document.getElementById('auto-save-status');
        
        logDebug('Typing detected. Body length: ' + body.length);
        
        // Update status to saving
        statusElement.innerHTML = '<i class="saving">⏳</i> <span>Saving...</span>';
        statusElement.classList.add('saving');
        statusElement.classList.remove('saved', 'error');
        
        // Clear previous timeout
        if (autoSaveTimeout) {
            clearTimeout(autoSaveTimeout);
            logDebug('Cleared previous auto-save timeout');
        }
        
        // Set new timeout for debouncing (save after 2 seconds of inactivity)
        autoSaveTimeout = setTimeout(() => {
            performAutoSave(body);
        }, 2000);
        
        logDebug('Set auto-save timeout for 2 seconds');
    }
    
    function performAutoSave(body) {
        const statusElement = document.getElementById('auto-save-status');
        
        logDebug('Attempting auto-save...');
        
        // Don't save if empty body
        if (!body.trim()) {
            statusElement.innerHTML = '<i></i> <span>Empty - not saved</span>';
            statusElement.classList.remove('saving', 'saved', 'error');
            logDebug('Empty body, skipping save');
            return;
        }
        
        // Don't save if already saving
        if (isAutoSaving) {
            logDebug('Already saving, skipping');
            return;
        }
        
        isAutoSaving = true;
        logDebug('Making fetch request to server');
        
        // Create form data
        const formData = new URLSearchParams();
        formData.append('autosave_followup', '1');
        formData.append('client_id', clientId);
        formData.append('email_body', body);
        
        fetch("send_email.php", {
            method: "POST",
            headers: {"Content-Type": "application/x-www-form-urlencoded"},
            body: formData.toString()
        })
        .then(response => {
            logDebug('Response received. Status: ' + response.status);
            return response.json();
        })
        .then(data => {
            logDebug('Response data: ' + JSON.stringify(data));
            
            if (data.success) {
                statusElement.innerHTML = '<i class="saved">✓</i> <span>Draft saved</span>';
                statusElement.classList.remove('saving', 'error');
                statusElement.classList.add('saved');
                
                // Show temporary success message
                setTimeout(() => {
                    if (statusElement.classList.contains('saved')) {
                        statusElement.innerHTML = '<i></i> <span>Auto-save ready</span>';
                        statusElement.classList.remove('saved');
                    }
                }, 3000);
                
                logDebug('Auto-save successful! Record ID: ' + (data.id || 'N/A'));
            } else {
                statusElement.innerHTML = '<i class="error">⚠</i> <span>Save failed: ' + (data.error || 'Unknown error') + '</span>';
                statusElement.classList.remove('saving', 'saved');
                statusElement.classList.add('error');
                logDebug('Auto-save failed: ' + (data.error || 'Unknown error'));
            }
            isAutoSaving = false;
        })
        .catch(error => {
            console.error('Auto-save error:', error);
            statusElement.innerHTML = '<i class="error">⚠</i> <span>Connection error</span>';
            statusElement.classList.remove('saving', 'saved');
            statusElement.classList.add('error');
            logDebug('Fetch error: ' + error.message);
            isAutoSaving = false;
        });
    }
    
    // Update second email preview based on selected recipient
    function updateSecondEmailPreview() {
        const recipientEmail = document.getElementById('recipient_email_hidden').value;
        const followupTextarea = document.getElementById('followup_email_body');
        
        if (recipientEmail && isSecondEmailEnabled && followupTextarea) {
            // Extract username from email for placeholder
            const username = recipientEmail.split('@')[0];
            const clientName = username.charAt(0).toUpperCase() + username.slice(1);
            
            // Replace [Client Name] placeholder in the textarea
            let currentText = followupTextarea.value;
            if (currentText.includes('[Client Name]')) {
                followupTextarea.value = currentText.replace('[Client Name]', clientName);
                logDebug('Replaced [Client Name] with: ' + clientName);
                
                // Auto-save the updated version
                setTimeout(() => {
                    autoSaveFollowupEmail();
                }, 500);
            }
        }
    }
    
    // Form validation
    function validateForm() {
        const recipientEmail = document.getElementById('recipient_email_hidden').value;
        const fromEmail = document.getElementById('from_email_hidden').value;
        const fromName = document.getElementById('from_name_hidden').value;
        
        if (!recipientEmail) {
            alert('Please select or enter a recipient email address');
            document.getElementById('recipient_email_search').focus();
            return false;
        }
        
        if (!fromEmail || !fromName) {
            alert('Please select a sender profile');
            return false;
        }
        
        if (isSecondEmailEnabled) {
            const followupBody = document.getElementById('followup_email_body').value;
            if (!followupBody.trim()) {
                alert('Follow-up email body cannot be empty');
                document.getElementById('followup_email_body').focus();
                return false;
            }
        }
        
        return true;
    }
    
    // Handle email input with smart search
    function handleEmailInput() {
        const searchInput = document.getElementById('recipient_email_search');
        const clearBtn = document.getElementById('search-clear-btn');
        const dropdown = document.getElementById('recipient_email_list');
        const typingIndicator = document.getElementById('typing-indicator');
        const validation = document.getElementById('email-validation');
        const emailValue = searchInput.value.trim();
        
        clearTimeout(emailSearchTimeout);
        clearBtn.classList.toggle('visible', emailValue.length > 0);
        validation.className = 'email-validation';
        updateSmartHint(emailValue);
        
        if (emailValue.length === 0) {
            dropdown.classList.remove('visible');
            typingIndicator.classList.remove('visible');
            hideSelectedDisplay();
            return;
        }
        
        if (isCompleteEmail(emailValue)) {
            validateAndProcessEmail(emailValue);
            dropdown.classList.remove('visible');
            typingIndicator.classList.remove('visible');
            return;
        }
        
        if (emailValue.length < 2) {
            dropdown.classList.remove('visible');
            typingIndicator.classList.remove('visible');
            return;
        }
        
        typingIndicator.classList.add('visible');
        dropdown.classList.remove('visible');
        
        emailSearchTimeout = setTimeout(() => {
            const clientSideResults = performClientSideSearch(emailValue);
            
            if (clientSideResults.length > 0) {
                renderEmailResults(clientSideResults);
                dropdown.classList.add('visible');
                typingIndicator.classList.remove('visible');
            } else {
                performAjaxEmailSearch(emailValue);
            }
        }, 300);
    }
    
    // Client-side search
    function performClientSideSearch(query) {
        const lowercaseQuery = query.toLowerCase();
        return emailData.filter(email => {
            const username = email.split('@')[0].toLowerCase();
            return username.indexOf(lowercaseQuery) === 0;
        }).slice(0, 10);
    }
    
    // AJAX search
    function performAjaxEmailSearch(query) {
        const typingIndicator = document.getElementById('typing-indicator');
        const dropdown = document.getElementById('recipient_email_list');
        
        currentSearchQuery = query;
        dropdown.innerHTML = `
            <div class="dropdown-loading">
                <div class="loading-spinner"></div>
                <div class="loading-text">Searching for "${query}"...</div>
            </div>
        `;
        dropdown.classList.add('visible');
        
        const xhr = new XMLHttpRequest();
        xhr.open('GET', `send_email.php?search_emails=1&query=${encodeURIComponent(query)}`, true);
        xhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest');
        
        xhr.onload = function() {
            typingIndicator.classList.remove('visible');
            if (query !== currentSearchQuery) return;
            
            if (xhr.status === 200) {
                try {
                    const emails = JSON.parse(xhr.responseText);
                    if (emails.length === 0) {
                        showNoResults(query);
                    } else {
                        renderEmailResults(emails);
                    }
                } catch (e) {
                    console.error('JSON parse error:', e);
                    const clientSideResults = performClientSideSearch(query);
                    if (clientSideResults.length > 0) {
                        renderEmailResults(clientSideResults);
                    } else {
                        showNoResults(query);
                    }
                }
            } else {
                const clientSideResults = performClientSideSearch(query);
                if (clientSideResults.length > 0) {
                    renderEmailResults(clientSideResults);
                } else {
                    showNoResults(query);
                }
            }
        };
        
        xhr.onerror = function() {
            typingIndicator.classList.remove('visible');
            const clientSideResults = performClientSideSearch(query);
            if (clientSideResults.length > 0) {
                renderEmailResults(clientSideResults);
            } else {
                showNoResults(query);
            }
        };
        
        xhr.send();
    }
    
    // Validate and process complete email
    function validateAndProcessEmail(email) {
        const searchInput = document.getElementById('recipient_email_search');
        const hiddenInput = document.getElementById('recipient_email_hidden');
        const validation = document.getElementById('email-validation');
        const emailSource = document.getElementById('email-source');
        
        if (!validateEmailFormat(email)) {
            validation.textContent = 'Please enter a valid email address';
            validation.className = 'email-validation invalid';
            hiddenInput.value = '';
            hideSelectedDisplay();
            return;
        }
        
        const isInDatabase = emailData.includes(email);
        
        if (isInDatabase) {
            validation.textContent = '✓ Email found in client directory';
            validation.className = 'email-validation valid';
            emailSource.textContent = 'From client directory';
        } else {
            validation.textContent = '✓ Custom email address (not in directory)';
            validation.className = 'email-validation valid';
            emailSource.textContent = 'Custom email address';
        }
        
        hiddenInput.value = email;
        lastValidatedEmail = email;
        updateSelectedDisplay(email, !isInDatabase);
        updateSecondEmailPreview();
    }
    
    // Validate email on blur
    function validateEmailOnBlur() {
        const searchInput = document.getElementById('recipient_email_search');
        const emailValue = searchInput.value.trim();
        
        if (emailValue && isCompleteEmail(emailValue)) {
            validateAndProcessEmail(emailValue);
        }
        
        document.getElementById('recipient_email_list').classList.remove('visible');
        document.getElementById('typing-indicator').classList.remove('visible');
    }
    
    // Render email search results
    function renderEmailResults(emails) {
        const dropdown = document.getElementById('recipient_email_list');
        let html = '';
        
        const limitedResults = emails.slice(0, 10);
        
        limitedResults.forEach(email => {
            const initials = getEmailInitials(email);
            html += `
                <div class="email-result-item" onclick="selectEmailFromResults('${escapeHtml(email)}')">
                    <div class="email-avatar">${initials}</div>
                    <div class="email-details">
                        <span class="email-address">${email}</span>
                        <span class="email-client-tag">Client</span>
                    </div>
                </div>
            `;
        });
        
        if (limitedResults.length > 0) {
            html += `
                <div class="email-result-item custom-email-option">
                    <div class="email-avatar">?</div>
                    <div class="email-details">
                        <span class="email-address">Continue typing for custom email</span>
                        <span class="email-client-tag">Other</span>
                    </div>
                </div>
            `;
        }
        
        dropdown.innerHTML = html;
    }
    
    function showNoResults(query) {
        const dropdown = document.getElementById('recipient_email_list');
        dropdown.innerHTML = `
            <div class="no-results">
                <div class="no-results-icon">📭</div>
                <div class="no-results-title">No matching emails</div>
                <div class="no-results-subtitle">Continue typing to use as custom email</div>
            </div>
        `;
    }
    
    // Select email from search results
    function selectEmailFromResults(email) {
        const searchInput = document.getElementById('recipient_email_search');
        searchInput.value = email;
        validateAndProcessEmail(email);
        document.getElementById('recipient_email_list').classList.remove('visible');
    }
    
    // Clear email search
    function clearEmailSearch() {
        const searchInput = document.getElementById('recipient_email_search');
        const hiddenInput = document.getElementById('recipient_email_hidden');
        const clearBtn = document.getElementById('search-clear-btn');
        const dropdown = document.getElementById('recipient_email_list');
        const typingIndicator = document.getElementById('typing-indicator');
        const validation = document.getElementById('email-validation');
        
        searchInput.value = '';
        hiddenInput.value = '';
        clearBtn.classList.remove('visible');
        dropdown.classList.remove('visible');
        typingIndicator.classList.remove('visible');
        validation.className = 'email-validation';
        
        hideSelectedDisplay();
        updateSmartHint('');
    }
    
    // Update selected email display
    function updateSelectedDisplay(email, isCustom = false) {
        const display = document.getElementById('selected_email_display');
        const avatar = document.getElementById('selected-email-avatar');
        const address = document.getElementById('selected-email-address');
        
        if (email) {
            avatar.textContent = getEmailInitials(email);
            address.textContent = email;
            display.classList.add('visible');
            
            avatar.classList.add('email-selected-pulse');
            setTimeout(() => {
                avatar.classList.remove('email-selected-pulse');
            }, 300);
        }
    }
    
    function hideSelectedDisplay() {
        const display = document.getElementById('selected_email_display');
        display.classList.remove('visible');
    }
    
    function removeSelectedEmail() {
        clearEmailSearch();
    }
    
    // Update smart hint based on input
    function updateSmartHint(input) {
        const hint = document.getElementById('smart-hint');
        
        if (!input) {
            hint.textContent = 'Start typing to search client directory. You can also enter any email address.';
        } else if (input.length < 2) {
            hint.textContent = 'Type at least 2 characters to search client directory...';
        } else if (isCompleteEmail(input)) {
            hint.textContent = 'Press Tab or click outside to validate email';
        } else {
            hint.textContent = 'Continue typing or select from suggestions above';
        }
    }
    
    // CC Management Functions
    function onCcCheckboxChange() {
        updateCcSummary();
    }
    
    function toggleCcSelectAll() {
        const selectAll = document.getElementById('cc_select_all');
        const checkboxes = document.querySelectorAll('#cc_checkbox_list input[type="checkbox"]');
        const ccEmailsInput = document.getElementById('cc_emails');
        
        checkboxes.forEach(checkbox => {
            checkbox.checked = selectAll.checked;
        });
        
        updateCcSummary();
    }
    
    function updateCcSummary() {
        const checkboxes = document.querySelectorAll('#cc_checkbox_list input[type="checkbox"]:checked');
        const ccEmailsInput = document.getElementById('cc_emails');
        const ccCount = document.getElementById('cc_count');
        const selectedList = document.getElementById('selected_emails_list');
        const summaryHint = document.getElementById('cc_summary_hint');
        
        const selectedEmails = Array.from(checkboxes).map(cb => cb.value);
        ccEmailsInput.value = selectedEmails.join(', ');
        ccCount.textContent = selectedEmails.length;
        
        // Update selected emails list
        selectedList.innerHTML = '';
        selectedEmails.forEach(email => {
            const chip = document.createElement('div');
            chip.className = 'email-chip';
            chip.setAttribute('data-email', email);
            chip.innerHTML = `
                <span class="chip-email">${email}</span>
                <button type="button" class="chip-remove" onclick="removeEmail('${email}')">
                    <svg width="12" height="12" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
                        <path d="M18 6L6 18M6 6L18 18" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
                    </svg>
                </button>
            `;
            selectedList.appendChild(chip);
        });
        
        // Update hint message
        if (selectedEmails.length === 0) {
            summaryHint.textContent = 'No CC recipients selected';
        } else if (selectedEmails.length === 1) {
            summaryHint.textContent = `Email will be copied to ${selectedEmails[0]}`;
        } else {
            summaryHint.textContent = `Email will be copied to ${selectedEmails.length} recipients`;
        }
        
        // Update select all checkbox state
        const totalCheckboxes = document.querySelectorAll('#cc_checkbox_list input[type="checkbox"]').length;
        const checkedCount = selectedEmails.length;
        
        if (checkedCount === 0) {
            selectAll.checked = false;
            selectAll.indeterminate = false;
        } else if (checkedCount === totalCheckboxes) {
            selectAll.checked = true;
            selectAll.indeterminate = false;
        } else {
            selectAll.checked = false;
            selectAll.indeterminate = true;
        }
    }
    
    function removeEmail(email) {
        const checkbox = document.querySelector(`#cc_checkbox_list input[value="${email}"]`);
        if (checkbox) {
            checkbox.checked = false;
            onCcCheckboxChange();
        }
    }
    
    // Send As Dropdown Functions
    function toggleSendAsDropdown() {
        document.getElementById('sendAsDropdown').classList.toggle('show');
    }
    
    function selectSendAs(email, name, role) {
        document.getElementById('from_email_hidden').value = email;
        document.getElementById('from_name_hidden').value = name;
        document.getElementById('sendAsAvatar').textContent = name.split(' ').map(n => n[0]).join('').slice(0,2);
        document.getElementById('sendAsName').textContent = name;
        document.getElementById('sendAsRole').textContent = role;
        document.getElementById('sendAsDropdown').classList.remove('show');
    }
    
    function filterSendAs(query) {
        query = query.toLowerCase();
        document.querySelectorAll('.sendas-item').forEach(item => {
            item.style.display = item.innerText.toLowerCase().includes(query) ? 'flex' : 'none';
        });
    }
    
    // Helper functions
    function getEmailInitials(email) {
        const namePart = email.split('@')[0];
        if (namePart.includes('.')) {
            return namePart.split('.').map(p => p[0]).join('').toUpperCase().slice(0, 2);
        }
        return namePart.slice(0, 2).toUpperCase();
    }
    
    function validateEmailFormat(email) {
        const re = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
        return re.test(email);
    }
    
    function isCompleteEmail(text) {
        return text.includes('@') && text.split('@')[1]?.includes('.');
    }
    
    function escapeHtml(text) {
        const map = {
            '&': '&amp;',
            '<': '&lt;',
            '>': '&gt;',
            '"': '&quot;',
            "'": '&#039;'
        };
        return text.replace(/[&<>"']/g, function(m) { return map[m]; });
    }
    
    // Close dropdown when clicking outside
    document.addEventListener('click', function(event) {
        const searchInput = document.getElementById('recipient_email_search');
        const dropdown = document.getElementById('recipient_email_list');
        const sendAsWrapper = document.getElementById('sendAsWrapper');
        
        if (!searchInput.contains(event.target) && !dropdown.contains(event.target)) {
            dropdown.classList.remove('visible');
            document.getElementById('typing-indicator').classList.remove('visible');
        }
        
        if (!sendAsWrapper.contains(event.target)) {
            document.getElementById('sendAsDropdown').classList.remove('show');
        }
    });
    
    // Keyboard navigation for email search
    document.getElementById('recipient_email_search').addEventListener('keydown', function(e) {
        const dropdown = document.getElementById('recipient_email_list');
        const items = dropdown.querySelectorAll('.email-result-item');
        
        if (!dropdown.classList.contains('visible') || items.length === 0) return;
        
        switch(e.key) {
            case 'ArrowDown':
                e.preventDefault();
                navigateDropdown(1, items);
                break;
            case 'ArrowUp':
                e.preventDefault();
                navigateDropdown(-1, items);
                break;
            case 'Enter':
                e.preventDefault();
                const activeItem = dropdown.querySelector('.email-result-item.active');
                if (activeItem) {
                    activeItem.click();
                }
                break;
            case 'Escape':
                dropdown.classList.remove('visible');
                break;
        }
    });
    
    function navigateDropdown(direction, items) {
        let activeIndex = -1;
        items.forEach((item, index) => {
            if (item.classList.contains('active')) {
                activeIndex = index;
                item.classList.remove('active');
            }
        });
        
        let newIndex = activeIndex + direction;
        if (newIndex < 0) newIndex = items.length - 1;
        if (newIndex >= items.length) newIndex = 0;
        
        items[newIndex].classList.add('active');
        items[newIndex].scrollIntoView({ block: 'nearest' });
    }
    
    // Update send as hint
    function updateSendAsHint() {
        const sendAsName = document.getElementById('sendAsName');
        const sendAsRole = document.getElementById('sendAsRole');
        
        if (sendAsName.textContent === 'Select sender profile') {
            const firstProfile = Object.values(sendAsProfiles)[0];
            if (firstProfile) {
                document.getElementById('from_email_hidden').value = Object.keys(sendAsProfiles)[0];
                document.getElementById('from_name_hidden').value = firstProfile.name;
                document.getElementById('sendAsAvatar').textContent = firstProfile.name.split(' ').map(n => n[0]).join('').slice(0,2);
                sendAsName.textContent = firstProfile.name;
                sendAsRole.textContent = firstProfile.designation;
            }
        }
    }
    
    // Test auto-save manually
    function testAutoSave() {
        const body = document.getElementById('followup_email_body').value;
        logDebug('Manual test of auto-save with body length: ' + body.length);
        performAutoSave(body);
    }
    </script>
</body>
</html>