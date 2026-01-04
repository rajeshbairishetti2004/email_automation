<?php
// send_email.php
require_once 'db_config.php';

$domainProfiles = require __DIR__ . '/organization_emails.php';
$sendAsProfiles = $domainProfiles;
$clientId = (int)($clientId ?? 0); 

try {
    $allClientEmails = [];
    $stmt = $pdo->query("SELECT email FROM clients WHERE email IS NOT NULL AND email <> '' ORDER BY email ASC");
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $allClientEmails[] = $row['email'];
    }
} catch (Exception $e) {
    $allClientEmails = [];
}

// Fixed list for CC section as requested in your previous logic
$allEmails = [
    'contact@financedoctor.in',
    'tanmay.vyas@financedoctor.in',
    'akshay.krishna@financedoctor.in',
    'sajid.ali@financedoctor.in',
    'vivek.sharma@financedoctor.in',
    'sailesh.mulleti@financedoctor.in'
];
?>

<style>
/* --- ALIGNED ENTERPRISE STYLING --- */
:root {
    --primary-navy: #1e293b; 
    --border-gray: #cbd5e1;
    --bg-light-gray: #f8fafc;
    --accent-blue: #2563eb;
    --text-main: #334155;
    --label-gray: #64748b;
}

.communication-box-style {
    border: 1px solid var(--border-gray);
    border-radius: 4px;
    background: #ffffff;
    box-shadow: 0 1px 3px rgba(0,0,0,0.05);
    padding: 25px;
    font-family: "Inter", "Segoe UI", sans-serif;
    color: var(--text-main);
    margin-bottom: 24px;
}

.section-title {
    font-size: 13px;
    color: var(--primary-navy);
    font-weight: 700;
    margin-bottom: 20px;
    text-transform: uppercase;
    letter-spacing: 0.08em;
    border-left: 4px solid var(--primary-navy);
    padding-left: 12px;
    display: block;
}

.email-fields-container {
    display: flex;
    flex-direction: column;
    gap: 20px;
}

/* Row-based layout to match "All Reports" style */
.form-row {
    display: flex;
    gap: 24px;
    align-items: flex-end;
}

.email-field-group {
    flex: 1;
    min-width: 0;
}

.email-field-group label {
    font-size: 11px;
    color: var(--label-gray);
    font-weight: 700;
    margin-bottom: 6px;
    display: block;
    text-transform: uppercase;
}

.styled-input {
    padding: 8px 12px;
    font-size: 14px;
    width: 100%;
    box-sizing: border-box;
    border: 1px solid var(--border-gray);
    border-radius: 3px;
    background-color: #fff;
    color: var(--text-main);
    transition: border-color 0.2s;
}

.styled-input:focus {
    border-color: var(--accent-blue);
    outline: none;
    box-shadow: 0 0 0 1px var(--accent-blue);
}

/* CC Panel Structured Grid */
.cc-panel {
    background: var(--bg-light-gray);
    border: 1px solid var(--border-gray);
    border-radius: 4px;
    padding: 16px;
    margin-top: 10px;
}

.cc-label-row {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 12px;
}

.cc-hint {
    font-size: 11px;
    color: #94a3b8;
    font-weight: 400;
}

.cc-checkboxes {
    display: flex;
    flex-wrap: wrap;
    gap: 10px;
    background: #fff;
    border: 1px solid var(--border-gray);
    padding: 12px;
    max-height: 140px;
    overflow-y: auto;
    border-radius: 3px;
}

.cc-checkbox-item {
    font-size: 12px;
    display: flex;
    align-items: center;
    gap: 8px;
    flex: 0 0 calc(33.33% - 10px); /* 3 Columns for horizontal alignment */
    padding: 4px 0;
    cursor: pointer;
}

.cc-summary {
    margin-top: 10px;
    font-size: 12px;
    color: var(--accent-blue);
    font-weight: 500;
}

.submit-button {
    background: #16a34a; /* Professional Green */
    color: #fff;
    border: none;
    padding: 12px 30px;
    font-size: 13px;
    font-weight: 700;
    border-radius: 4px;
    cursor: pointer;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    transition: background 0.2s;
}

.submit-button:hover {
    background: #15803d;
}

@media (max-width: 768px) {
    .form-row { flex-direction: column; gap: 15px; }
    .cc-checkbox-item { flex: 0 0 100%; }
}
</style>

<div class="email-send-container">
    <form method="post" enctype="multipart/form-data" class="email-form">
        <input type="hidden" name="send_email" value="1">
        <input type="hidden" name="client_id" value="<?php echo (int)$clientId; ?>">
        <input type="hidden" name="from_name" id="from_name" value="Finance Doctor">
        <input type="hidden" name="cc_emails" id="cc_emails">
        
        <div class="communication-box-style">
            <span class="section-title">Communication Center</span>
            
            <div class="email-fields-container">
                <div class="form-row">
                    <div class="email-field-group">
                        <label for="from_email">Send As</label>
                        <select name="from_email" id="from_email" required class="styled-input" onchange="updateSenderDetails(this)">
                            <option value="" disabled>-- Select Sender --</option>
                            <?php foreach ($sendAsProfiles as $email => $profile): ?>
                                <option value="<?php echo htmlspecialchars($email); ?>" 
                                        data-name="<?php echo htmlspecialchars($profile['name']); ?>"
                                        data-mobile="<?php echo htmlspecialchars($profile['mobile']); ?>"
                                        data-designation="<?php echo htmlspecialchars($profile['designation']); ?>"
                                        <?php echo ($email === 'contact@financedoctor.in') ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($profile['name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="email-field-group">
                        <label for="recipient_email_search">Primary Recipient (To)</label>
                        <div class="custom-dropdown" style="position:relative;">
                            <input type="text" id="recipient_email_search" class="styled-input" placeholder="Search client directory..." autocomplete="off">
                            <input type="hidden" name="recipient_email" id="recipient_email_hidden">
                            <div id="recipient_email_list" class="dropdown-list" style="display:none;position:absolute;z-index:100;width:100%;background:#fff;border:1px solid var(--border-gray);box-shadow:0 4px 6px rgba(0,0,0,0.1);max-height:180px;overflow-y:auto;"></div>
                        </div>
                    </div>
                </div>

                <div class="email-field-group cc-panel">
                    <div class="cc-label-row">
                        <div>
                            <label style="display:inline; margin-right:8px;">Carbon Copy (CC)</label>
                            <span class="cc-hint">Select contacts to copy on this report</span>
                        </div>
                        <label style="font-size:11px; cursor:pointer; color: var(--label-gray);">
                            <input type="checkbox" id="cc_select_all" onchange="toggleCcSelectAll()"> SELECT ALL
                        </label>
                    </div>

                    <div class="cc-checkboxes" id="cc_checkbox_list">
                        <?php foreach ($allEmails as $email): ?>
                            <label class="cc-checkbox-item">
                                <input type="checkbox" value="<?php echo htmlspecialchars($email); ?>" onchange="onCcCheckboxChange(event)">
                                <span><?php echo htmlspecialchars($email); ?></span>
                            </label>
                        <?php endforeach; ?>
                    </div>
                    <div class="cc-summary" id="cc_summary">Selected: none</div>
                </div>

                <div style="margin-top: 10px;">
                    <button type="submit" class="submit-button">Send Report via Email</button>
                </div>
            </div>
        </div>
    </form>
</div>

<script>
// JS LOGIC FOR AUTOCOMPLETE & CHECKBOX SYNC
function onCcCheckboxChange(event) {
    const checkboxList = document.getElementById('cc_checkbox_list');
    const checked = Array.from(checkboxList.querySelectorAll('input[type="checkbox"]:checked')).map(cb => cb.value);
    document.getElementById('cc_emails').value = checked.join(', ');
    updateCcSummary(checked);
}

function updateCcSummary(selectedEmails) {
    const summaryEl = document.getElementById('cc_summary');
    if (!summaryEl) return;
    summaryEl.textContent = selectedEmails.length ? `Selected (${selectedEmails.length}): ${selectedEmails.join(', ')}` : 'Selected: none';
}

function toggleCcSelectAll() {
    const selectAll = document.getElementById('cc_select_all');
    const checkboxList = document.getElementById('cc_checkbox_list');
    const allCheckboxes = checkboxList.querySelectorAll('input[type="checkbox"]');
    allCheckboxes.forEach(cb => { cb.checked = selectAll.checked; });
    onCcCheckboxChange();
}

// Recipient Search Autocomplete
document.addEventListener('DOMContentLoaded', function() {
    var emails = <?php echo json_encode(array_values($allClientEmails)); ?>;
    var searchInput = document.getElementById('recipient_email_search');
    var listDiv = document.getElementById('recipient_email_list');
    var hiddenInput = document.getElementById('recipient_email_hidden');

    searchInput.addEventListener('input', function() {
        var filter = this.value.toLowerCase();
        var html = '';
        emails.forEach(function(email) {
            if (email.toLowerCase().includes(filter)) {
                html += `<div class="dropdown-item" style="padding:8px 12px; cursor:pointer; font-size:13px; border-bottom:1px solid #f1f5f9;" onclick="selectRecipient('${email}')">${email}</div>`;
            }
        });
        listDiv.innerHTML = html || '<div style="padding:8px; color:#999;">No matches</div>';
        listDiv.style.display = 'block';
    });

    window.selectRecipient = function(val) {
        searchInput.value = val;
        hiddenInput.value = val;
        listDiv.style.display = 'none';
    };

    document.addEventListener('click', function(e) {
        if (!searchInput.contains(e.target)) listDiv.style.display = 'none';
    });
});
</script>