<?php
// send_email.php
require_once 'db_config.php';

$domainProfiles = require __DIR__ . '/organization_emails.php';
$sendAsProfiles = $domainProfiles;
$clientId = (int)($clientId ?? 0);

try {
    $allClientEmails = [];
    $stmt = $pdo->query("SELECT DISTINCT email FROM clients WHERE email IS NOT NULL AND email <> '' ORDER BY email ASC");
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $allClientEmails[] = $row['email'];
    }
} catch (Exception $e) {
    $allClientEmails = [];
}

// Fixed list for CC section
$allEmails = [
    'contact@financedoctor.in',
    'tanmay.vyas@financedoctor.in',
    'akshay.krishna@financedoctor.in',
    'sajid.ali@financedoctor.in',
    'vivek.sharma@financedoctor.in',
    'sailesh.mulleti@financedoctor.in'
];

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
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Email Communication Center</title>
    <link rel="stylesheet" href="public/css/send_email.css">
    <style>
    /* Additional inline styles */
    .email-data {
        display: none;
    }
    
    .typing-indicator {
        font-size: 12px;
        color: #64748b;
        margin-top: 4px;
        display: none;
    }
    
    .typing-indicator.visible {
        display: flex;
        align-items: center;
        gap: 8px;
    }
    
    .email-validation {
        font-size: 12px;
        margin-top: 4px;
        display: none;
    }
    
    .email-validation.valid {
        color: #10b981;
        display: block;
    }
    
    .email-validation.invalid {
        color: #ef4444;
        display: block;
    }
    
    .smart-hint {
        font-size: 11px;
        color: #94a3b8;
        margin-top: 6px;
        font-style: italic;
    }
    
    .loading-spinner-small {
        width: 12px;
        height: 12px;
        border: 2px solid #e2e8f0;
        border-top-color: var(--accent-blue);
        border-radius: 50%;
        animation: spin 1s linear infinite;
    }
    
    /* For keyboard navigation */
    .email-result-item.active {
        background-color: #eff6ff;
        border-left: 3px solid var(--accent-blue);
        padding-left: 15px;
    }
    
    /* Style select to look like input */
    select.modern-search-input {
        width: 100%;
        padding: 12px 40px 12px 40px;
        border: 2px solid #e2e8f0;
        border-radius: 8px;
        font-size: 14px;
        background-color: #ffffff;
        color: #374151;
        cursor: pointer;
        appearance: none;
        background-image: url("data:image/svg+xml;charset=UTF-8,%3csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24' fill='none' stroke='currentColor' stroke-width='2' stroke-linecap='round' stroke-linejoin='round'%3e%3cpolyline points='6,9 12,15 18,9'%3e%3c/polyline%3e%3c/svg%3e");
        background-repeat: no-repeat;
        background-position: right 12px center;
        background-size: 16px;
    }
    
    select.modern-search-input:focus {
        outline: none;
        border-color: var(--accent-blue);
        box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.1);
    }
    </style>
</head>
<body>
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
                                
                                <!-- Hidden input for form submission -->
                                <input type="hidden" name="recipient_email" id="recipient_email_hidden">
                                
                                <!-- Smart dropdown for suggestions -->
                                <div id="recipient_email_list" class="modern-dropdown-list"></div>
                                
                                <!-- Typing indicator -->
                                <div class="typing-indicator" id="typing-indicator">
                                    <div class="loading-spinner-small"></div>
                                    <span>Searching for matching emails...</span>
                                </div>
                                
                                <!-- Email validation message -->
                                <div class="email-validation" id="email-validation"></div>
                                
                                <!-- Smart hint -->
                                <div class="smart-hint" id="smart-hint">
                                    Start typing to search client directory. You can also enter any email address.
                                </div>
                            </div>
                            
                            <!-- Selected Email Display -->
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
                    <!-- CC Panel -->
<div class="email-field-group cc-panel">
    <div class="cc-label-row">
        <div class="cc-header">
            <div class="cc-icon">
                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
                    <path d="M20 4H4C2.9 4 2 4.9 2 6V18C2 19.1 2.9 20 4 20H20C21.1 20 22 19.1 22 18V6C22 4.9 21.1 4 20 4ZM19.6 6L12 11L4.4 6H19.6ZM4 18V6.97L12 12L20 6.97V18H4Z" fill="currentColor"/>
                </svg>
            </div>
            <div>
                <h3 class="cc-title">Carbon Copy (CC)</h3>
            </div>
        </div>
        <label class="select-all-toggle">
            <input type="checkbox" id="cc_select_all" onchange="toggleCcSelectAll()">
            <span class="select-all-text">Select All</span>
        </label>
    </div>

    <div class="cc-checkboxes" id="cc_checkbox_list">
    <?php foreach ($allEmails as $index => $email): ?>
        <div class="cc-checkbox-item">
            <input type="checkbox" 
                   id="cc_<?php echo $index; ?>"
                   value="<?php echo htmlspecialchars($email); ?>" 
                   onchange="onCcCheckboxChange()"
                   <?php echo ($index === 0) ? 'checked' : ''; ?>>
            <label for="cc_<?php echo $index; ?>" class="cc-checkbox-label">
                <span class="cc-checkbox-custom"></span>
                <span class="cc-email-text"><?php echo htmlspecialchars($email); ?></span>
            </label>
        </div>
    <?php endforeach; ?>
</div>
    
    <div class="cc-summary-container">
        <div class="cc-summary-header">
            <span class="cc-summary-title">Selected Recipients</span>
            <span class="cc-count" id="cc_count">1</span>
        </div>
        <div class="selected-emails-list" id="selected_emails_list">
            <?php if (isset($allEmails[0])): ?>
                <div class="email-chip">
                    <span><?php echo htmlspecialchars($allEmails[0]); ?></span>
                    <button type="button" class="remove-email" onclick="removeEmail('<?php echo htmlspecialchars($allEmails[0]); ?>')">
                        <svg width="12" height="12" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
                            <path d="M18 6L6 18M6 6L18 18" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
                        </svg>
                    </button>
                </div>
            <?php endif; ?>
        </div>
        <div class="cc-summary-hint" id="cc_summary_hint">Email will be sent to <?php echo isset($allEmails[0]) ? htmlspecialchars($allEmails[0]) : ''; ?> and copied to selected recipients</div>
    </div>
</div>
                    <!-- Submit Button -->
                    <div style="margin-top: 24px; text-align: center;">
                        <button type="submit" class="submit-button">
                            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <line x1="22" y1="2" x2="11" y2="13"></line>
                                <polygon points="22 2 15 22 11 13 2 9 22 2"></polygon>
                            </svg>
                            Send Communication
                        </button>
                    </div>
                </div>
            </div>
        </form>
    </div>

    <script>
    // Email data from PHP (for client-side filtering as fallback)
    const emailData = <?php echo json_encode($allClientEmails); ?>;
    const ccEmailData = <?php echo json_encode($allEmails); ?>;
    const sendAsProfiles = <?php echo json_encode($sendAsProfiles); ?>;
    
    // State variables
    let emailSearchTimeout;
    let currentSearchQuery = '';
    let lastValidatedEmail = '';
    
    // Initialize CC summary on load
    document.addEventListener('DOMContentLoaded', function() {
        updateCcSummary();
        
        // Set initial hint
        updateSmartHint('');
        updateSendAsHint();
        
        // Focus management
        const searchInput = document.getElementById('recipient_email_search');
        searchInput.addEventListener('focus', function() {
            // Only show dropdown if user has already typed something
            if (this.value.length >= 2) {
                handleEmailInput();
            }
        });
    });
    
    // Handle email input with smart search
    function handleEmailInput() {
        const searchInput = document.getElementById('recipient_email_search');
        const clearBtn = document.getElementById('search-clear-btn');
        const dropdown = document.getElementById('recipient_email_list');
        const typingIndicator = document.getElementById('typing-indicator');
        const validation = document.getElementById('email-validation');
        const emailValue = searchInput.value.trim();
        
        // Clear previous timeout
        clearTimeout(emailSearchTimeout);
        
        // Show/hide clear button
        clearBtn.classList.toggle('visible', emailValue.length > 0);
        
        // Hide validation messages while typing
        validation.className = 'email-validation';
        
        // Update smart hint
        updateSmartHint(emailValue);
        
        // Clear dropdown if input is cleared
        if (emailValue.length === 0) {
            dropdown.classList.remove('visible');
            typingIndicator.classList.remove('visible');
            hideSelectedDisplay();
            return;
        }
        
        // If it looks like a complete email, validate it
        if (isCompleteEmail(emailValue)) {
            validateAndProcessEmail(emailValue);
            dropdown.classList.remove('visible');
            typingIndicator.classList.remove('visible');
            return;
        }
        
        // Only search after user types at least 2 characters
        if (emailValue.length < 2) {
            dropdown.classList.remove('visible');
            typingIndicator.classList.remove('visible');
            return;
        }
        
        // Show typing indicator
        typingIndicator.classList.add('visible');
        dropdown.classList.remove('visible');
        
        // Debounce the search
        emailSearchTimeout = setTimeout(() => {
            // First try client-side search for immediate results
            const clientSideResults = performClientSideSearch(emailValue);
            
            if (clientSideResults.length > 0) {
                // Show client-side results immediately
                renderEmailResults(clientSideResults);
                dropdown.classList.add('visible');
                typingIndicator.classList.remove('visible');
            } else {
                // If no client-side results, try AJAX search
                performAjaxEmailSearch(emailValue);
            }
        }, 300);
    }
    
    // Client-side search (immediate, no AJAX)
    function performClientSideSearch(query) {
        const lowercaseQuery = query.toLowerCase();
        
        return emailData.filter(email => {
            // Get the username part (before @)
            const username = email.split('@')[0].toLowerCase();
            
            // Check if query matches from the beginning of username
            return username.indexOf(lowercaseQuery) === 0;
        }).slice(0, 10); // Limit to 10 results
    }
    
    // AJAX search (fallback)
    function performAjaxEmailSearch(query) {
        const typingIndicator = document.getElementById('typing-indicator');
        const dropdown = document.getElementById('recipient_email_list');
        
        // Store current query
        currentSearchQuery = query;
        
        // Show loading in dropdown
        dropdown.innerHTML = `
            <div class="dropdown-loading">
                <div class="loading-spinner"></div>
                <div class="loading-text">Searching for "${query}"...</div>
            </div>
        `;
        dropdown.classList.add('visible');
        
        // AJAX request to PHP backend
        const xhr = new XMLHttpRequest();
        xhr.open('GET', `send_email.php?search_emails=1&query=${encodeURIComponent(query)}`, true);
        xhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest');
        
        xhr.onload = function() {
            typingIndicator.classList.remove('visible');
            
            // Check if query hasn't changed during the request
            if (query !== currentSearchQuery) {
                return;
            }
            
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
                    // Fallback to client-side search
                    const clientSideResults = performClientSideSearch(query);
                    if (clientSideResults.length > 0) {
                        renderEmailResults(clientSideResults);
                    } else {
                        showNoResults(query);
                    }
                }
            } else {
                // If AJAX fails, use client-side search
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
            // Fallback to client-side search
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
        
        // Check if email exists in database
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
        
        // Set the value for form submission
        hiddenInput.value = email;
        lastValidatedEmail = email;
        
        // Update selected display
        updateSelectedDisplay(email, !isInDatabase);
    }
    
    // Validate email on blur
    function validateEmailOnBlur() {
        const searchInput = document.getElementById('recipient_email_search');
        const emailValue = searchInput.value.trim();
        
        if (emailValue && isCompleteEmail(emailValue)) {
            validateAndProcessEmail(emailValue);
        }
        
        // Hide dropdown
        document.getElementById('recipient_email_list').classList.remove('visible');
        document.getElementById('typing-indicator').classList.remove('visible');
    }
    
    // Render email search results
    function renderEmailResults(emails) {
        const dropdown = document.getElementById('recipient_email_list');
        let html = '';
        
        // Limit results to 10 for better performance
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
        
        // Add "No exact match" option if user might want to enter custom email
        if (limitedResults.length > 0) {
            html += `
                <div class="email-result-item" style="border-top: 1px dashed #e2e8f0; margin-top: 8px; padding-top: 12px; color: #64748b;">
                    <div class="email-avatar" style="background: linear-gradient(135deg, #94a3b8 0%, #64748b 100%);">?</div>
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
        
        // Trigger validation and processing
        validateAndProcessEmail(email);
        
        // Close dropdown
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
            
            // Animate avatar
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
    
    // CC Management
    // CC Management
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
        chip.innerHTML = `
            <span>${email}</span>
            <button type="button" class="remove-email" onclick="removeEmail('${email}')">
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
    
    // Sender details update (removed - now handled in selectSendAsFromResults)
    // function updateSenderDetails(select) {
    //     const selectedOption = select.options[select.selectedIndex];
    //     const fromNameInput = document.getElementById('from_name');
    //     fromNameInput.value = selectedOption.getAttribute('data-name') || 'Finance Doctor';
    // }
    
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
        // Check if text looks like a complete email (contains @ and . after @)
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
        
        if (!searchInput.contains(event.target) && !dropdown.contains(event.target)) {
            dropdown.classList.remove('visible');
            document.getElementById('typing-indicator').classList.remove('visible');
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
    
    function toggleSendAsDropdown() {
    document.getElementById('sendAsDropdown').classList.toggle('show');
}

function selectSendAs(email, name, role) {
    document.getElementById('from_email_hidden').value = email;
    document.getElementById('from_name_hidden').value = name;

    document.getElementById('sendAsAvatar').textContent =
        name.split(' ').map(n => n[0]).join('').slice(0,2);

    document.getElementById('sendAsName').textContent = name;
    document.getElementById('sendAsRole').textContent = role;

    document.getElementById('sendAsDropdown').classList.remove('show');
}

function filterSendAs(query) {
    query = query.toLowerCase();
    document.querySelectorAll('.sendas-item').forEach(item => {
        item.style.display = item.innerText.toLowerCase().includes(query)
            ? 'flex'
            : 'none';
    });
}

// Close when clicking outside
document.addEventListener('click', e => {
    if (!document.getElementById('sendAsWrapper').contains(e.target)) {
        document.getElementById('sendAsDropdown').classList.remove('show');
    }
});
    </script>
</body>
</html>