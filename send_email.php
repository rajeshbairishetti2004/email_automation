<?php
// send_email.php
require_once 'db_config.php';

// 1. LOAD ORGANIZATION PROFILES
$domainProfiles = require __DIR__ . '/organization_emails.php';

// Prepare the list for dropdown usage (preserves structure)
$sendAsProfiles = $domainProfiles;

// 2. Client ID
$clientId = (int)($clientId ?? 0); 

// Load Client Emails for the "To" field
$clientMailOptions = [];
$rmMailOptions = [];
$generalCCMailOptions = [];
$sendAsEmails = array_keys($sendAsProfiles);

try {
    $clientMailOptions = getEmailContacts('CLIENT', $clientId);
    $rmMailOptions     = getEmailContacts('RM');
    $generalCCMailOptions = getEmailContacts('CC');
} catch (Exception $e) {
    // Silent fail if DB issue, dropdowns will just be empty
}

// Combine for CC suggestions (includes Finance Doctor profiles too)
$allEmails = array_unique(array_merge($rmMailOptions, $clientMailOptions, $generalCCMailOptions, $sendAsEmails));
?>

<link rel="stylesheet" href="public/css/send_email.css">
<script>
function updateSenderDetails(selectObj) {
    const selectedOption = selectObj.options[selectObj.selectedIndex];
    const name = selectedOption.getAttribute('data-name');
    const mobile = selectedOption.getAttribute('data-mobile');
    const designation = selectedOption.getAttribute('data-designation');
    const email = selectedOption.value;

    if (!name) { return; }

    const hiddenNameField = document.getElementById('from_name');
    if (hiddenNameField) { hiddenNameField.value = name; }

    const newSignature = "Regards,\n\n" +
        name + ",\n" +
        designation + ",\n" +
        "Finance Doctor Private Limited.\n\n" +
        "Mobile - " + mobile + ".\n" +
        "Email - " + email + "\n" +
        "Url: www.financedoctor.in";

    let visibleSignatureBox = document.getElementById('signature_block');
    if (!visibleSignatureBox) {
        visibleSignatureBox = document.querySelector('textarea[name=\"signature_block\"]');
    }

    if (visibleSignatureBox) {
        visibleSignatureBox.value = newSignature;
        visibleSignatureBox.dispatchEvent(new Event('blur'));
        visibleSignatureBox.style.transition = "background-color 0.5s";
        visibleSignatureBox.style.backgroundColor = "#e8f0fe";
        setTimeout(() => { visibleSignatureBox.style.backgroundColor = "#fff"; }, 800);
    }

    const hiddenSigField = document.getElementById('custom_signature_for_email');
    if (hiddenSigField) { hiddenSigField.value = newSignature; }
    filterCcOptionsBySender();
}
</script>
<script>
// Keep CC text input in sync with multi-select selections
function syncCcFromSelect() {
    const select = document.getElementById('cc_multi_select');
    const input  = document.getElementById('cc_emails');
    const selectAll = document.getElementById('cc_select_all');
    if (!select || !input) return;
    const selected = Array.from(select.selectedOptions).map(o => o.value).filter(Boolean);
    input.value = selected.join(', ');
    updateCcSummary(selected);

    if (selectAll) {
        const total = select.options.length;
        selectAll.checked = total > 0 && selected.length === total;
    }

    syncCheckboxesFromSelect();
}

// Filter CC options to exclude the currently selected sender
function filterCcOptionsBySender() {
    const senderSelect = document.getElementById('from_email');
    const senderEmail  = senderSelect ? senderSelect.value.toLowerCase() : '';
    const select = document.getElementById('cc_multi_select');
    const datalist = document.getElementById('all-emails');
    const checkboxList = document.getElementById('cc_checkbox_list');
    if (!select || !datalist) return;

    const existingSelected = new Set(Array.from(select.selectedOptions).map(o => o.value));

    // Merge any newly added options with the cached list
    const mergedOptions = Array.from(new Set([
        ...(window.allCcOptions || []),
        ...Array.from(datalist.options).map(o => o.value),
        ...Array.from(select.options).map(o => o.value)
    ].filter(Boolean)));
    window.allCcOptions = mergedOptions;

    select.innerHTML = '';
    datalist.innerHTML = '';
    if (checkboxList) {
        checkboxList.innerHTML = '';
    }

    mergedOptions.forEach(email => {
        if (!email) return;
        if (email.toLowerCase() === senderEmail) return; // exclude current sender

        const opt1 = document.createElement('option');
        opt1.value = email;
        opt1.textContent = email;
        if (existingSelected.has(email)) opt1.selected = true;
        select.appendChild(opt1);

        const opt2 = document.createElement('option');
        opt2.value = email;
        datalist.appendChild(opt2);

        if (checkboxList) {
            const label = document.createElement('label');
            label.className = 'cc-checkbox-item';

            const checkbox = document.createElement('input');
            checkbox.type = 'checkbox';
            checkbox.value = email;
            checkbox.checked = existingSelected.has(email);
            checkbox.addEventListener('change', onCcCheckboxChange);

            label.appendChild(checkbox);
            label.appendChild(document.createTextNode(email));
            checkboxList.appendChild(label);
        }
    });

    syncCcFromSelect();
}

document.addEventListener('DOMContentLoaded', function() {
    // Cache all CC options from the initial PHP render
    const select = document.getElementById('cc_multi_select');
    if (select) {
        window.allCcOptions = Array.from(select.options).map(o => o.value).filter(Boolean);
    }
    filterCcOptionsBySender();
});

function toggleCcSelectAll() {
    const select = document.getElementById('cc_multi_select');
    const selectAll = document.getElementById('cc_select_all');
    if (!select || !selectAll) return;

    Array.from(select.options).forEach(opt => { opt.selected = selectAll.checked; });
    syncCcFromSelect();
}

function onCcCheckboxChange(event) {
    const select = document.getElementById('cc_multi_select');
    if (!select) return;
    const email = event.target.value;
    const option = Array.from(select.options).find(o => o.value === email);
    if (option) {
        option.selected = event.target.checked;
    }
    syncCcFromSelect();
}

function syncCheckboxesFromSelect() {
    const select = document.getElementById('cc_multi_select');
    const checkboxList = document.getElementById('cc_checkbox_list');
    if (!select || !checkboxList) return;
    const selectedSet = new Set(Array.from(select.selectedOptions).map(o => o.value));
    checkboxList.querySelectorAll('input[type="checkbox"]').forEach(cb => {
        cb.checked = selectedSet.has(cb.value);
    });
}

// Lightweight summary of selected CCs for quick glance
function updateCcSummary(selectedEmails) {
    const summaryEl = document.getElementById('cc_summary');
    if (!summaryEl) return;
    const list = (selectedEmails || []).filter(Boolean);
    summaryEl.textContent = list.length ? `Selected: ${list.join(', ')}` : 'Selected: none';
}
</script>

<div class="card" style="margin-bottom: 20px;">
    <label class="card-title" style="font-weight: 600; font-size: 16px; color: #17a2b8; display: block; margin-bottom: 10px;">Send Report by Email</label>
    <form method="POST" enctype="multipart/form-data" id="sendEmailForm">
        <input type="hidden" name="send_email" value="1">
        <input type="hidden" name="client_id" value="<?php echo (int)$clientId; ?>">
        <input type="hidden" name="from_name" id="from_name" value="Finance Doctor">
        <input type="hidden" name="custom_signature" id="custom_signature_for_email" value="">
        <div class="email-attachment-wrapper" style="margin-bottom: 15px;">
            <div class="email-recipients-section">
                <strong class="section-title">Email Recipients</strong>
                <div class="email-fields-container">
                    
                    <div class="email-field-group">
                        <label for="from_email">Send As:</label>
                        
                        <select name="from_email" id="from_email" required class="styled-input" onchange="updateSenderDetails(this)">
        <option value="" disabled selected>-- Select Sender --</option>
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
                        <div class="input-with-buttons">
                            <label for="recipient_email">To (Primary Recipient):</label>
                            <div class="manage-buttons-group">
                                <button type="button" class="manage-btn add-btn" title="Add Email" onclick="addEmail('client-emails', 'To Options', 'CLIENT')">➕</button>
                                <button type="button" class="manage-btn delete-btn" title="Delete Email" onclick="openDeleteModal('client-emails', 'To Options', 'recipient_email', 'CLIENT')">➖</button>
                            </div>
                        </div>
                        <input type="email" name="recipient_email" id="recipient_email"
                               required
                               placeholder="Enter primary email"
                               list="client-emails"
                               class="styled-input">

                        <datalist id="client-emails">
                            <?php foreach ($clientMailOptions as $email): ?>
                                <option value="<?php echo htmlspecialchars($email); ?>">
                            <?php endforeach; ?>
                        </datalist>
                    </div>

                    <div class="email-field-group cc-panel">
                        <div class="input-with-buttons cc-label-row">
                            <div>
                                <label for="cc_multi_select">CC</label>
                                <span class="cc-hint">Pick one or many; selected items flow into the box.</span>
                                <label class="cc-select-all"><input type="checkbox" id="cc_select_all" onchange="toggleCcSelectAll()"> Select all</label>
                            </div>
                            <div class="manage-buttons-group">
                                <button type="button" class="manage-btn add-btn" title="Add Email" onclick="addEmail('all-emails', 'CC Options', 'CC')">➕</button>
                                <button type="button" class="manage-btn delete-btn" title="Delete Email" onclick="openDeleteModal('all-emails', 'CC Options', 'cc_emails', 'CC')">➖</button>
                            </div>
                        </div>

                        <select id="cc_multi_select" multiple size="6" class="styled-input cc-multi-select" onchange="syncCcFromSelect()">
                            <?php foreach ($allEmails as $email): ?>
                                <option value="<?php echo htmlspecialchars($email); ?>"><?php echo htmlspecialchars($email); ?></option>
                            <?php endforeach; ?>
                        </select>

                        <div class="cc-checkboxes" id="cc_checkbox_list" aria-label="CC options checklist"></div>

                        <input type="text" name="cc_emails" id="cc_emails"
                               placeholder="Enter or paste emails (comma separated)"
                               list="all-emails"
                               class="styled-input cc-text-input">

                        <datalist id="all-emails">
                            <?php foreach ($allEmails as $email): ?>
                                <option value="<?php echo htmlspecialchars($email); ?>">
                            <?php endforeach; ?>
                        </datalist>
                        <div class="cc-summary" id="cc_summary">Selected: none</div>
                    </div>
                </div>
            </div>

            <div class="file-attachments-section">
                <label style="font-weight: 500;">Attach Additional Files (optional):</label>
                <input type="file" name="attachments[]" id="email_attachments_input" multiple onchange="updateAttachmentList()">
                <ul id="selected_attachment_list" style="list-style: none; padding: 0; margin-top: 8px;"></ul>
                <p style="font-size: 11px; color: #666;">Note: All report attachments will be sent automatically. Files selected here are additional.</p>
            </div>
        </div>
        <button type="submit" class="btn btn-primary">Send Email</button>
    </form>
</div>

<div id="deleteModal" class="modal-overlay" style="display:none;">
    <div class="modal-content">
        <h3 id="modalTitle">Delete Emails from List</h3>
        <p>Select the emails you wish to remove from the list:</p>
        <div id="emailCheckboxes" class="checkbox-list">
            </div>
        <div class="modal-actions">
            <button type="button" class="modal-btn cancel-btn" onclick="document.getElementById('deleteModal').style.display='none'">Cancel</button>
            <button type="button" class="modal-btn delete-submit-btn" onclick="submitDeletion()">Delete Selected</button>
        </div>
    </div>
</div>

<script>
function updateAttachmentList() {
    const input = document.getElementById('email_attachments_input');
    const list = document.getElementById('selected_attachment_list');
    list.innerHTML = '';
    Array.from(input.files).forEach((file, idx) => {
        const li = document.createElement('li');
        li.style.cssText = "margin-bottom: 6px; display: flex; align-items: center;";
        li.innerHTML = `<span>📎 <strong>${file.name}</strong></span>
            <a href="#" style="color:red; margin-left:10px; font-size:12px;" onclick="removeSelectedFile(${idx});return false;">🗑 Remove</a>`;
        list.appendChild(li);
    });
}

// Remove file from input (by recreating FileList)
function removeSelectedFile(idx) {
    const input = document.getElementById('email_attachments_input');
    const dt = new DataTransfer();
    Array.from(input.files).forEach((file, i) => {
        if (i !== idx) dt.items.add(file);
    });
    input.files = dt.files;
    updateAttachmentList();
}

// Initialize list on page load (in case browser remembers files)
document.addEventListener('DOMContentLoaded', updateAttachmentList);
</script>
