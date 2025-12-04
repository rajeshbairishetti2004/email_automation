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

try {
    $clientMailOptions = getEmailContacts('CLIENT', $clientId);
    $rmMailOptions     = getEmailContacts('RM');
    $generalCCMailOptions = getEmailContacts('CC');
} catch (Exception $e) {
    // Silent fail if DB issue, dropdowns will just be empty
}

// Combine for CC suggestions
$allEmails = array_unique(array_merge($rmMailOptions, $clientMailOptions, $generalCCMailOptions));
?>

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
}
</script>

<div class="email-send-container">
    <form method="post" enctype="multipart/form-data" class="email-form">
        <input type="hidden" name="send_email" value="1">
        <input type="hidden" name="client_id" value="<?php echo (int)$clientId; ?>">
        <input type="hidden" name="from_name" id="from_name" value="Finance Doctor">
        <input type="hidden" name="custom_signature" id="custom_signature_for_email" value="">
        <div class="email-attachment-wrapper communication-box-style">
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

                    <div class="email-field-group">
                        <div class="input-with-buttons">
                            <label for="cc_emails">CC (Multiple, comma separated):</label>
                            <div class="manage-buttons-group">
                                <button type="button" class="manage-btn add-btn" title="Add Email" onclick="addEmail('all-emails', 'CC Options', 'CC')">➕</button>
                                <button type="button" class="manage-btn delete-btn" title="Delete Email" onclick="openDeleteModal('all-emails', 'CC Options', 'cc_emails', 'CC')">➖</button>
                            </div>
                        </div>
                        <input type="text" name="cc_emails" id="cc_emails"
                               placeholder="Enter multiple emails (e.g., mail1@dom, mail2@dom)"
                               list="all-emails"
                               class="styled-input">

                        <datalist id="all-emails">
                            <?php foreach ($allEmails as $email): ?>
                                <option value="<?php echo htmlspecialchars($email); ?>">
                            <?php endforeach; ?>
                        </datalist>
                    </div>
                </div>
            </div>

            

        </div>
        <button type="submit" class="submit-button">
            Send Report by Email
        </button>
        

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

<style>
    /* ... (CSS remains the same) ... */
    /* New Class to match Client Communication Box (image_a36b79.png) */
    .communication-box-style {
        border: 1px solid #c9c9c9; 
        border-radius: 4px;
        background-color: #f7f7f7; 
        padding: 15px;
        font-family: Arial, sans-serif; 
    }

    /* Base and General Styles */
    .email-send-container {
        font-family: Arial, sans-serif;
        padding: 0; 
        border: none;
        background-color: transparent;
        margin-bottom: 20px;
    }
    .email-attachment-wrapper {
        display: flex;
        gap: 30px;
        margin-bottom: 20px;
    }
    .section-title {
        font-size: 14px; 
        color: #333;
        display: block;
        margin-bottom: 10px;
        padding-bottom: 5px;
        border-bottom: 1px solid #dcdcdc; 
        font-weight: bold; 
    }
    .email-recipients-section, .file-attachments-section {
        flex: 1;
    }
    .email-fields-container {
        display: flex;
        flex-direction: column;
        gap: 12px;
    }
    .email-field-group {
        width: 100%; 
    }
    
    .input-with-buttons {
        display: flex;
        justify-content: space-between;
        align-items: center;
        margin-bottom: 4px;
    }
    .email-field-group label {
        font-size: 11px;
        color: #666;
        font-weight: 600;
        margin-bottom: 0;
    }
    
    .manage-buttons-group {
        display: flex;
        gap: 8px; 
    }

    .manage-btn {
        background: #fdfdfd; 
        border: 1px solid #ccc;
        color: #333;
        padding: 1px 6px; 
        font-size: 10px;
        border-radius: 4px;
        cursor: pointer;
        transition: background-color 0.2s;
        height: 18px; 
        line-height: 14px;
    }
    .manage-btn:hover {
        background-color: #e6e6e6;
    }
    .add-btn:hover {
        border-color: #28a745; 
        color: #28a745;
    }
    .delete-btn:hover {
        border-color: #dc3545; 
        color: #dc3545;
    }
    
    .styled-input {
        padding: 6px 10px; 
        font-size: 13px;
        width: 100%;
        box-sizing: border-box;
        border: 1px solid #ccc;
        border-radius: 4px;
        transition: border-color 0.2s;
    }
    .styled-input:focus {
        border-color: #007bff;
        outline: none;
    }
    
    /* File Attachment Specifics */
    .file-input-wrapper {
        display: flex;
        flex-direction: column;
        gap: 8px;
    }
    .file-label {
        font-size: 13px;
        color: #333;
        cursor: pointer;
        display: inline-flex;
        align-items: center;
        margin-bottom: 5px;
    }
    .file-icon {
        margin-right: 5px;
        font-size: 16px;
    }
    .file-input {
        font-size: 13px;
        border: 1px solid #ccc;
        border-radius: 4px;
        padding: 5px;
        background-color: #f9f9f9;
    }
    .file-list-display {
        margin-top: 10px;
        padding: 10px;
        border: 1px solid #ccc; 
        border-radius: 4px;
        min-height: 40px;
        font-size: 12px;
        color: #555;
        background-color: #fff; 
    }
    .file-name-item {
        display: block;
        margin-bottom: 3px;
        font-weight: 500;
        color: #0056b3;
        overflow: hidden;
        text-overflow: ellipsis;
        white-space: nowrap;
    }
    .submit-button {
        padding: 8px 15px;
        background-color: #007bff;
        color: white;
        border: none;
        border-radius: 4px;
        cursor: pointer;
        font-size: 14px;
        font-weight: 600;
        transition: background-color 0.3s;
    }
    .submit-button:hover {
        background-color: #0056b3;
    }
    @media (max-width: 768px) {
        .email-attachment-wrapper {
            flex-direction: column;
            gap: 20px;
        }
    }
    
    /* MODAL-SPECIFIC STYLES */
    .modal-overlay {
        position: fixed;
        z-index: 1000;
        left: 0;
        top: 0;
        width: 100%;
        height: 100%;
        overflow: auto;
        background-color: rgba(0,0,0,0.5);
        display: flex;
        justify-content: center;
        align-items: center;
    }
    .modal-content {
        background-color: #fefefe;
        padding: 25px;
        border: 1px solid #888;
        border-radius: 8px;
        width: 90%; 
        max-width: 450px;
        box-shadow: 0 4px 8px rgba(0,0,0,0.2);
    }
    #modalTitle {
        margin-top: 0;
        color: #333;
        border-bottom: 1px solid #eee;
        padding-bottom: 10px;
    }
    .checkbox-list {
        max-height: 250px;
        overflow-y: auto;
        border: 1px solid #ddd;
        padding: 10px;
        margin: 15px 0;
        background-color: #fafafa;
    }
    .checkbox-item {
        display: block;
        margin-bottom: 8px;
        font-size: 14px;
        cursor: pointer;
    }
    .checkbox-item input[type="checkbox"] {
        margin-right: 8px;
    }
    .modal-actions {
        display: flex;
        justify-content: flex-end;
        gap: 10px;
        margin-top: 20px;
    }
    .modal-btn {
        padding: 8px 15px;
        border: none;
        border-radius: 4px;
        cursor: pointer;
        font-weight: 600;
    }
    .cancel-btn {
        background-color: #ccc;
        color: #333;
    }
    .delete-submit-btn {
        background-color: #dc3545; 
        color: white;
    }
</style>
