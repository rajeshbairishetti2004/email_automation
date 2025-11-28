<?php
// rationale.php
// Renders the Rationale section with user-specific template management.
// Requires: $clientId, $rationaleText, $userRationaleTemplates (new array from view_report.php)

$clientId = (int)($clientId ?? 0);

// Determine if there is a flash message to display for rationale template actions
$rationale_flash_container = (isset($_GET['template_added']) && $_GET['section'] == 'rationale') || 
                             (isset($_GET['template_add_error']) && isset($_GET['section']) && $_GET['section'] == 'rationale');
?>

<style>
    /* CSS for Enterprise Aesthetic */
    
    /* Container Styling */
    .rationale-card-container {
        padding: 30px;
        background: white;
        border-radius: 12px;
        box-shadow: 0 4px 24px rgba(0, 0, 0, 0.06);
        margin-top: 20px;
    }
    .rationale-card-container .card-title {
        font-size: 22px;
        font-weight: 700;
        color: #0288D1;
        border-bottom: 2px solid #E3F2FD;
        padding-bottom: 10px;
        margin-bottom: 20px;
        font-family: 'Poppins', sans-serif;
    }

    /* Template Controls Bar */
    .template-controls-bar {
        display: flex;
        align-items: center;
        flex-wrap: wrap;
        gap: 15px;
        margin-bottom: 20px;
        padding: 10px;
        background-color: #F8F8F8;
        border-radius: 8px;
    }
    .template-controls-bar label {
        font-size: 14px;
        font-weight: 500;
        color: #333;
        margin: 0;
    }

    /* Input/Select Standardization */
    .template-controls-bar select, 
    .template-controls-bar input[type="text"] {
        padding: 8px 10px;
        border: 1px solid #ccc;
        border-radius: 4px;
        font-size: 14px;
        box-shadow: inset 0 1px 2px rgba(0,0,0,0.05);
    }
    .template-controls-bar select {
        width: 180px;
        cursor: pointer;
    }

    /* Button Styling (Cohesive Enterprise Look) */
    .enterprise-btn {
        padding: 8px 15px;
        border: none;
        border-radius: 4px;
        cursor: pointer;
        font-size: 14px;
        font-weight: 600;
        transition: all 0.2s ease;
        text-decoration: none !important;
        display: inline-flex;
        align-items: center;
        gap: 5px;
    }
    .btn-save-new, .btn-edit {
        background-color: #4CAF50; /* Green */
        color: white;
    }
    .btn-save-new:hover, .btn-edit:hover {
        background-color: #43A047;
        box-shadow: 0 2px 4px rgba(0,0,0,0.1);
    }
    .btn-delete {
        background-color: #F44336; /* Red */
        color: white;
        padding: 8px 12px;
    }
    .btn-delete:hover {
        background-color: #E53935;
    }

    /* Template Save Form Styling */
    #rationale_template_form_container {
        padding: 20px;
        border: 2px solid #C5E1A5; /* Light green border */
        border-radius: 8px;
        background-color: #F1F8E9; /* Very light green background */
        margin-bottom: 20px;
        display: none;
    }
    .template-save-form h4 {
        color: #388E3C;
        margin-top: 0;
        margin-bottom: 15px;
        font-size: 16px;
    }
    .template-save-form input[type="text"], 
    .template-save-form textarea {
        width: 100%;
        padding: 10px;
        margin-bottom: 10px;
        border: 1px solid #A5D6A7;
        border-radius: 4px;
        box-sizing: border-box;
    }
    .template-save-form textarea {
        min-height: 100px;
    }

    /* Main Rationale Text Area */
    #rationale_textarea {
        width: 100%;
        min-height: 250px;
        padding: 15px;
        border: 2px solid #ddd;
        border-radius: 8px;
        box-sizing: border-box;
        font-size: 14px;
        transition: border-color 0.2s, box-shadow 0.2s;
    }
    #rationale_textarea:focus {
        border-color: #4FC3F7;
        box-shadow: 0 0 0 3px rgba(79, 195, 247, 0.2);
        outline: none;
    }
</style>

<div class="rationale-card-container">
    <label class="card-title">Rationale</label>
    
    <div id="rationale_flash_container" class="signature-flash-container">
        <?php if (isset($_GET['template_added']) && $_GET['section'] == 'rationale'): ?>
            <div class="flash-message flash-success" style="opacity: 1;">✅ Rationale template saved successfully!</div>
        <?php elseif (isset($_GET['template_add_error']) && isset($_GET['section']) && $_GET['section'] == 'rationale'): ?>
            <div class="flash-message flash-error" style="opacity: 1;">❌ Failed to add template: <?php echo htmlspecialchars($_GET['template_add_error']); ?></div>
        <?php endif; ?>
    </div>

    <div class="template-controls-bar">
        <label for="rationale_template_selector_main">Load My Template:</label>
        
        <select id="rationale_template_selector_main" data-textarea-id="rationale_textarea" data-section-type="rationale">
            <option value="0" data-content="<?php echo htmlspecialchars($rationaleText); ?>">--- Use Current Text ---</option>
            <?php foreach ($userRationaleTemplates as $tpl): ?>
                <option value="<?php echo (int)$tpl['id']; ?>" data-content="<?php echo htmlspecialchars($tpl['content']); ?>">
                    <?php echo htmlspecialchars($tpl['name']); ?>
                </option>
            <?php endforeach; ?>
        </select>
        
        <a href="#" id="rationale_delete_template_btn" class="btn-delete enterprise-btn" title="Delete Selected Template">
           🗑️ Delete
        </a>
        
        <a href="#" id="rationale_save_template_toggle" class="btn-save-new enterprise-btn">
            + Save Current Text as Template
        </a>
        
        <a href="#" id="rationale_edit_template_btn" class="btn-edit enterprise-btn" style="display: none;">
            ✍️ Edit Selected
        </a>
    </div>

    <div id="rationale_template_form_container" style="display: none;">
        <form method="POST" class="template-save-form" id="rationaleTemplateForm">
            <input type="hidden" name="action_add_template" value="1">
            <input type="hidden" name="client_id" value="<?php echo (int)$clientId; ?>">
            <input type="hidden" name="template_section" value="rationale">
            <input type="hidden" name="template_id_to_update" id="template_id_to_update" value="0">
            
            <h4><span id="templateFormTitle">Save New Rationale Template</span></h4>
            
            <label for="template_name_input" style="font-size: 12px; margin: 0;">Template Name (Required)</label>
            <input type="text" name="template_name" id="template_name_input" placeholder="Template Name (e.g., Aggressive View)" required>
            
            <label for="rationale_template_content_input" style="font-size: 12px; margin: 0;">Template Content</label>
            <textarea name="template_content" id="rationale_template_content_input" placeholder="Paste the full content here..." required rows="5"></textarea>
            
            <button type="submit" id="templateSaveSubmit" class="btn-save-new enterprise-btn" style="width: auto;">
                💾 Save Template
            </button>
        </form>
    </div>

    <textarea name="rationale"
              class="large-textarea" 
              data-field="rationale" 
              data-client-id="<?php echo (int)$clientId; ?>"
              id="rationale_textarea"
              placeholder="Write your rationale here..."><?php echo htmlspecialchars($rationaleText); ?></textarea>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const textarea = document.getElementById('rationale_textarea');
    const selector = document.getElementById('rationale_template_selector_main');
    const saveToggleBtn = document.getElementById('rationale_save_template_toggle');
    const editBtn = document.getElementById('rationale_edit_template_btn'); // New Edit Btn
    const deleteBtn = document.getElementById('rationale_delete_template_btn'); 
    const formContainer = document.getElementById('rationale_template_form_container');
    const templateForm = document.getElementById('rationaleTemplateForm');
    const nameInput = document.getElementById('template_name_input');
    const contentInput = document.getElementById('rationale_template_content_input');
    const templateIdInput = document.getElementById('template_id_to_update');
    const formTitle = document.getElementById('templateFormTitle');

    // --- Helper function to reset and show the form ---
    function prepareForm(type, templateId, templateName, templateContent) {
        if (type === 'new') {
            formTitle.textContent = 'Save New Rationale Template';
            templateIdInput.value = 0;
            nameInput.value = '';
        } else if (type === 'edit') {
            formTitle.textContent = 'Edit Existing Template';
            templateIdInput.value = templateId;
            nameInput.value = templateName;
        }
        
        // Use provided content or fallback to main textarea content
        contentInput.value = templateContent || textarea.value;
        formContainer.style.display = 'block';
    }

    // --- Template Selector Logic (Load & Control Visibility) ---
    if (selector) {
        selector.addEventListener('change', function() {
            const selectedOption = selector.options[selector.selectedIndex];
            const templateContent = selectedOption.getAttribute('data-content');
            const templateId = selector.value;

            // 1. Load content into main textarea and trigger auto-save (blur)
            if (templateContent !== null && templateId !== '0') {
                textarea.value = templateContent;
                if (typeof showContextualFlash === 'function') {
                    showContextualFlash('success', `Template "${selectedOption.textContent}" loaded. Auto-saving...`, 'rationale_flash_container');
                }
                // Show Edit button for loaded template
                editBtn.style.display = 'inline-flex'; 
                deleteBtn.style.display = 'inline-flex';
            } else {
                 if (typeof showContextualFlash === 'function') {
                    showContextualFlash('success', 'Using current Rationale text.', 'rationale_flash_container');
                 }
                 // Hide Edit button for default option
                 editBtn.style.display = 'none';
                 deleteBtn.style.display = 'none';
            }

            textarea.dispatchEvent(new Event('blur')); 
            
            // 2. Hide save form if showing
            formContainer.style.display = 'none';
        });
    }

    // --- Toggle Save Form Logic (SaveToggleBtn - For New Save) ---
    if (saveToggleBtn) {
        saveToggleBtn.addEventListener('click', function(e) {
            e.preventDefault();
            
            if (formContainer.style.display === 'block' && templateIdInput.value === '0') {
                formContainer.style.display = 'none';
                return;
            }
            
            // Prepare form for NEW save, using current text area content
            prepareForm('new', 0, '', textarea.value);
        });
    }

    // --- Edit Template Button Logic ---
    if (editBtn) {
        editBtn.addEventListener('click', function(e) {
            e.preventDefault();
            const templateId = selector.value;
            const templateName = selector.options[selector.selectedIndex].text;
            const templateContent = selector.options[selector.selectedIndex].getAttribute('data-content');

            if (templateId === '0') return; // Should be hidden, but safety check

            // Prepare form for EDITING
            prepareForm('edit', templateId, templateName, templateContent);
        });
    }

    // --- Delete Template Logic ---
    if (deleteBtn) {
        deleteBtn.addEventListener('click', function(e) {
            e.preventDefault();
            const templateId = selector.value;
            const templateName = selector.options[selector.selectedIndex].text;
            
            if (templateId === '0' || templateId === 0) {
                if (typeof showContextualFlash === 'function') {
                    showContextualFlash('error', '❌ Please select a specific template to delete.', 'rationale_flash_container');
                }
                return;
            }
            
            if (!confirm(`Are you sure you want to permanently delete the template "${templateName}"?`)) return;

            // Perform AJAX deletion
            fetch('view_report.php?id=<?php echo (int)$clientId; ?>', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
                body: new URLSearchParams({
                    ajax_action: 'delete_user_template',
                    template_id: templateId
                })
            })
            .then(response => {
                if (!response.ok) {
                    throw new Error(`HTTP error! status: ${response.status}`);
                }
                return response.json();
            })
            .then(data => {
                if (data.success) {
                    if (typeof showContextualFlash === 'function') {
                         showContextualFlash('success', `✅ Template "${templateName}" deleted. Reloading...`, 'rationale_flash_container');
                    }
                    // Force full page reload to refresh the selector options
                    window.location.reload(); 
                } else {
                    if (typeof showContextualFlash === 'function') {
                        showContextualFlash('error', `❌ Failed to delete template: ${data.error}`, 'rationale_flash_container');
                    }
                }
            })
            .catch(err => {
                if (typeof showContextualFlash === 'function') {
                    showContextualFlash('error', `Network error during template deletion: ${err.message}`, 'rationale_flash_container');
                }
            });
        });
    }

    // --- Template Form Submission (Uses standard POST now, but we validate fields) ---
    templateForm.addEventListener('submit', function(e) {
        // Prevent default POST behavior to handle the form submission via AJAX
        e.preventDefault(); 
        
        const templateId = templateIdInput.value;
        const action = templateId > 0 ? 'edit' : 'add';
        const submitBtn = document.getElementById('templateSaveSubmit');
        submitBtn.disabled = true;

        // Prepare form data with correct parameter names
        const formData = new URLSearchParams();
        formData.append('ajax_action', 'save_user_template');
        formData.append('template_id_to_update', templateId);
        formData.append('template_name', nameInput.value);
        formData.append('template_content', contentInput.value);

        fetch('view_report.php?id=<?php echo (int)$clientId; ?>', {
            method: 'POST',
            headers: { 
                'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8'
            },
            body: formData
        })
        .then(response => {
            if (!response.ok) {
                throw new Error(`HTTP error! status: ${response.status}`);
            }
            return response.json();
        })
        .then(data => {
            submitBtn.disabled = false;
            if (data.success) {
                const actionText = action === 'add' ? 'saved' : 'updated';
                if (typeof showContextualFlash === 'function') {
                     showContextualFlash('success', `✅ Template ${actionText} successfully. Reloading...`, 'rationale_flash_container');
                }
                // Reload to refresh the selector options
                window.location.reload(); 
            } else {
                if (typeof showContextualFlash === 'function') {
                    showContextualFlash('error', `❌ Failed to save template: ${data.error}`, 'rationale_flash_container');
                }
            }
        })
        .catch(err => {
            submitBtn.disabled = false;
            if (typeof showContextualFlash === 'function') {
                showContextualFlash('error', `Network error during template save: ${err.message}`, 'rationale_flash_container');
            }
            console.error('Template Save Error:', err);
        });
    });

    // Initial check to control visibility of Edit/Delete buttons on load
    if (selector.value !== '0') {
        editBtn.style.display = 'inline-flex';
        deleteBtn.style.display = 'inline-flex';
    } else {
        editBtn.style.display = 'none';
        deleteBtn.style.display = 'none';
    }
});
</script>