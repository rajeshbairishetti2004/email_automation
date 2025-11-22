<?php
// send_email.php
// Renders the email sending interface with separate Add and Delete buttons for list management.
// List updates are handled via AJAX to the server-side API.

require_once 'db_config.php'; // Include database config to use new functions

$clientId = 139; // Using HARVINDER SINGH GILL's ID from SQL dump

// --- FETCH DATA FROM DATABASE ---
try {
    $rmMailOptions     = getEmailContacts('RM');
    $generalCCMailOptions = getEmailContacts('CC');
    $clientMailOptions = getEmailContacts('CLIENT', $clientId);

    // Combine all CC options (RM emails + Client emails + General CC emails)
    $allEmails = array_unique(array_merge($rmMailOptions, $clientMailOptions, $generalCCMailOptions));

    // Set a default initial 'From' email (e.g., the first RM email)
    $fromEmail = !empty($rmMailOptions) ? $rmMailOptions[0] : '';
    
} catch (PDOException $e) {
    // Fallback if DB connection fails
    $rmMailOptions = ['db_error@fallback.com'];
    $clientMailOptions = [];
    $allEmails = [];
    $fromEmail = 'db_error@fallback.com';
    error_log("Failed to load email contacts: " . $e->getMessage());
}
?>

<div class="email-send-container">
    <form method="post" enctype="multipart/form-data" class="email-form">
        <input type="hidden" name="send_email" value="1">
        <input type="hidden" name="client_id" value="<?php echo (int)$clientId; ?>">

        <div class="email-attachment-wrapper communication-box-style">

            <div class="email-recipients-section">
                <strong class="section-title">Email Recipients</strong>
                <div class="email-fields-container">
                    
                    <div class="email-field-group">
                        <div class="input-with-buttons">
                            <label for="from_email">From (Sender Email):</label>
                            <div class="manage-buttons-group">
                                <button type="button" class="manage-btn add-btn" title="Add Email" onclick="addEmail('rm-emails', 'From Options', 'RM')">➕</button>
                                <button type="button" class="manage-btn delete-btn" title="Delete Email" onclick="openDeleteModal('rm-emails', 'From Options', 'from_email', 'RM')">➖</button>
                            </div>
                        </div>
                        <input type="email" name="from_email" id="from_email" 
                               value="<?php echo htmlspecialchars($fromEmail); ?>" 
                               list="rm-emails"
                               required
                               class="styled-input">
                        
                        <datalist id="rm-emails">
                            <?php foreach ($rmMailOptions as $email): ?>
                                <option value="<?php echo htmlspecialchars($email); ?>">
                            <?php endforeach; ?>
                        </datalist>
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

            <div class="file-attachments-section">
                <strong class="section-title">File Attachments</strong>
                <div class="file-input-wrapper">
                    <label for="attachments" class="file-label">
                        <span class="file-icon">📎</span> Choose Files to Attach (Multiple):
                    </label>
                    <input type="file" 
                            name="attachments[]" 
                            id="attachments"
                            accept=".pdf,.xlsx,.xls,.doc,.docx,.jpg,.jpeg,.png,.gif"
                            multiple
                            class="file-input">
                    
                    <div id="file-list-display" class="file-list-display">
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

<script>
    // Global variables to store context when modal is opened
    let currentDatalistId = '';
    let currentInputId = '';
    let currentListType = ''; 

    const CLIENT_ID = <?php echo (int)$clientId; ?>;
    const API_ENDPOINT = 'manage_contacts_api.php'; // New API endpoint

    /**
     * Helper function to validate if a string is a basic email format.
     */
    function isValidEmail(email) {
        const re = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
        return re.test(String(email).toLowerCase());
    }
    
    /**
     * Sends an asynchronous request to the API endpoint.
     */
    function sendApiRequest(data) {
        const formData = new FormData();
        for (const key in data) {
            formData.append(key, data[key]);
        }

        fetch(API_ENDPOINT, {
            method: 'POST',
            body: formData
        })
        .then(response => response.json())
        .then(result => {
            if (result.status === 'success') {
                console.log(`Persistence success: ${result.message}`);
            } else {
                console.error(`Persistence error: ${result.message}`);
                // Optional: Alert the user that the change was NOT saved persistently
            }
        })
        .catch(error => {
            console.error('API connection failed:', error);
            // Optional: Alert the user about the connection failure
        });
    }

    /**
     * Adds an email to a specific datalist (client-side update + API call).
     */
    function addEmail(datalistId, listName, listType) {
        const datalist = document.getElementById(datalistId);
        if (!datalist) return;

        const newEmail = prompt(`Add to ${listName}:\n\nEnter the email address to add:`);
        if (newEmail && isValidEmail(newEmail.trim())) {
            const cleanedEmail = newEmail.trim();
            const existingOptions = Array.from(datalist.options).map(opt => opt.value.toLowerCase());
            
            if (existingOptions.includes(cleanedEmail.toLowerCase())) {
                alert(`Error: The email '${cleanedEmail}' already exists in the list.`);
                return;
            }

            // 1. Client-side update: Appends new option to the datalist
            const newOption = document.createElement('option');
            newOption.value = cleanedEmail;
            datalist.appendChild(newOption);
            alert(`Successfully added '${cleanedEmail}' to ${listName}.`);
            
            // 2. Server-side Persistence Call
            sendApiRequest({
                action: 'add',
                email: cleanedEmail,
                listType: listType,
                clientId: CLIENT_ID 
            });

        } else if (newEmail !== null && newEmail.trim() !== '') {
            alert('Error: Please enter a valid email address.');
        }
    }

    /**
     * Opens the custom modal and populates it with checkboxes.
     */
    function openDeleteModal(datalistId, listName, inputId, listType) {
        const datalist = document.getElementById(datalistId);
        const modal = document.getElementById('deleteModal');
        const checkboxesContainer = document.getElementById('emailCheckboxes');

        if (!datalist || !modal) return;

        // Set global context
        currentDatalistId = datalistId;
        currentInputId = inputId;
        currentListType = listType;

        document.getElementById('modalTitle').textContent = `Delete Emails from ${listName}`;
        checkboxesContainer.innerHTML = '';
        
        // Populate checkboxes
        const options = Array.from(datalist.options);
        if (options.length === 0) {
            checkboxesContainer.innerHTML = '<p style="font-style:italic;">No emails available to delete.</p>';
        } else {
            options.forEach(option => {
                const email = option.value;
                const label = document.createElement('label');
                label.className = 'checkbox-item';
                
                const checkbox = document.createElement('input');
                checkbox.type = 'checkbox';
                checkbox.value = email;
                checkbox.name = 'emailToDelete';
                
                label.appendChild(checkbox);
                label.appendChild(document.createTextNode(email));
                checkboxesContainer.appendChild(label);
            });
        }

        modal.style.display = 'flex';
    }
    
    /**
     * Processes the selected emails from the modal and deletes them (client-side update + API call).
     */
    function submitDeletion() {
        const datalist = document.getElementById(currentDatalistId);
        const inputField = document.getElementById(currentInputId);
        const modal = document.getElementById('deleteModal');
        const listType = currentListType;
        
        if (!datalist || !inputField) return;

        const checkedBoxes = document.querySelectorAll('#emailCheckboxes input[name="emailToDelete"]:checked');
        const emailsToDelete = Array.from(checkedBoxes).map(cb => cb.value); 
        
        if (emailsToDelete.length === 0) {
            alert('Please select at least one email to delete.');
            return;
        }

        let deletedCount = 0;
        
        emailsToDelete.forEach(email => {
            const emailLower = email.toLowerCase();
            
            // Find option case-insensitively
            const option = datalist.querySelector(`option[value="${email}" i]`) || 
                           datalist.querySelector(`option[value="${emailLower}"]`); 
            
            if (option) {
                // 1. Client-side update: Removes option from the datalist
                datalist.removeChild(option);
                deletedCount++;

                // If the deleted email was currently selected in the input, clear the input.
                if (inputField.value.toLowerCase() === emailLower) {
                    inputField.value = '';
                }
            }
        });
        
        // 2. Server-side Persistence Call
        // Join emails with commas for the API endpoint
        sendApiRequest({
            action: 'delete',
            emails: emailsToDelete.join(','),
            listType: listType,
            clientId: CLIENT_ID 
        });

        alert(`Successfully deleted ${deletedCount} email(s) from the list.`);
        modal.style.display = 'none';
        
        // Clear global context
        currentDatalistId = '';
        currentInputId = '';
        currentListType = '';
    }


    // --- File Attachment Logic (Kept for completeness) ---
    document.addEventListener('DOMContentLoaded', function() {
        const attachmentsInput = document.getElementById('attachments');
        const fileListDisplay = document.getElementById('file-list-display');

        function updateFileListDisplay() {
            fileListDisplay.innerHTML = '';
            if (attachmentsInput.files.length > 0) {
                Array.from(attachmentsInput.files).forEach(file => {
                    const fileNameSpan = document.createElement('span');
                    fileNameSpan.className = 'file-name-item';
                    fileNameSpan.textContent = `• ${file.name}`;
                    fileListDisplay.appendChild(fileNameSpan);
                });
            } else {
                 const placeholderText = document.createElement('span');
                 placeholderText.textContent = 'No files selected.';
                 fileListDisplay.appendChild(placeholderText);
            }
        }

        if (attachmentsInput) {
            attachmentsInput.addEventListener('change', updateFileListDisplay);
            updateFileListDisplay(); // Initial load
        }
    });
</script>