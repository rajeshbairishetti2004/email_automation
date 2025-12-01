<?php
// rationale.php - REDESIGNED ENTERPRISE UI
// Renders the Rationale section with user-specific template management.
// Requires: $clientId, $rationaleText, $userRationaleTemplates (new array from view_report.php)

$clientId = (int)($clientId ?? 0);

// Determine if there is a flash message to display for rationale template actions
$communication_flash_container = isset($_GET['template_added']) || isset($_GET['template_add_error']);
?>

<div class="rat-main-container">
    <h2 class="rat-main-title">Rationale</h2>
    
    <div id="rationale_flash_container" class="rat-flash-container signature-flash-container">
        <?php if (isset($_GET['template_added']) && $_GET['section'] == 'rationale'): ?>
            <div class="flash-message flash-success" style="opacity: 1;">✅ Rationale template saved successfully!</div>
        <?php elseif (isset($_GET['template_add_error']) && isset($_GET['section']) && $_GET['section'] == 'rationale'): ?>
            <div class="flash-message flash-error" style="opacity: 1;">❌ Failed to add template: <?php echo htmlspecialchars($_GET['template_add_error']); ?></div>
        <?php endif; ?>
    </div>

    <!-- Template Controls Bar -->
    <div class="rat-controls-bar">
        <div class="rat-control-group">
            <label for="rationale_template_selector_main" class="rat-control-label">My Templates:</label>
            <select id="rationale_template_selector_main" 
                    class="rat-template-select" 
                    data-textarea-id="rationale_textarea" 
                    data-section-type="rationale">
                <option value="0" data-content="<?php echo htmlspecialchars($rationaleText); ?>">-- Use Current Text --</option>
                <?php foreach ($userRationaleTemplates as $tpl): ?>
                    <option value="<?php echo (int)$tpl['id']; ?>" 
                            data-content="<?php echo htmlspecialchars($tpl['content']); ?>">
                        <?php echo htmlspecialchars($tpl['name']); ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        
        <button type="button" id="rationale_delete_template_btn" class="rat-btn rat-btn-delete" title="Delete Selected Template">
            <span class="rat-btn-icon">🗑️</span>
            <span>Delete</span>
        </button>
        
        <button type="button" id="rationale_edit_template_btn" class="rat-btn rat-btn-edit" style="display: none;">
            <span class="rat-btn-icon">✍️</span>
            <span>Edit Selected</span>
        </button>
        
        <button type="button" id="rationale_save_template_toggle" class="rat-btn rat-btn-save-new">
            <span class="rat-btn-icon">💾</span>
            <span>Save Current Text as Template</span>
        </button>
    </div>

    <!-- Save Form Container -->
    <div id="rationale_template_form_container" class="rat-save-form-container">
        <form method="POST" id="rationaleTemplateForm">
            <input type="hidden" name="action_add_template" value="1">
            <input type="hidden" name="client_id" value="<?php echo (int)$clientId; ?>">
            <input type="hidden" name="template_section" value="rationale">
            <input type="hidden" name="template_id_to_update" id="template_id_to_update" value="0">
            
            <div class="rat-form-header">
                <h4 class="rat-form-title"><span id="templateFormTitle">Save New Rationale Template</span></h4>
            </div>
            
            <div class="rat-form-group">
                <label for="template_name_input" class="rat-form-label">Template Name *</label>
                <input type="text" 
                       name="template_name" 
                       id="template_name_input" 
                       class="rat-form-input"
                       placeholder="e.g., Aggressive Growth View, Conservative Outlook" 
                       required>
            </div>
            
            <div class="rat-form-group">
                <label for="rationale_template_content_input" class="rat-form-label">Template Content *</label>
                <textarea name="template_content" 
                          id="rationale_template_content_input" 
                          class="rat-form-textarea"
                          placeholder="Enter the full rationale text here..." 
                          required 
                          rows="6"></textarea>
            </div>
            
            <div class="rat-form-actions">
                <button type="submit" id="templateSaveSubmit" class="rat-btn rat-btn-primary">
                    <span class="rat-btn-icon">💾</span>
                    <span>Save Template</span>
                </button>
                <button type="button" class="rat-btn rat-btn-secondary rat-cancel-btn">
                    Cancel
                </button>
            </div>
        </form>
    </div>

    <!-- Main Textarea -->
    <div class="rat-textarea-container">
        <textarea name="rationale"
                  class="rat-main-textarea large-textarea" 
                  data-field="rationale" 
                  data-client-id="<?php echo (int)$clientId; ?>"
                  id="rationale_textarea"
                  placeholder="Enter your rationale analysis here. You can type directly or load from your saved templates above..."><?php echo htmlspecialchars($rationaleText); ?></textarea>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const textarea = document.getElementById('rationale_textarea');
    const selector = document.getElementById('rationale_template_selector_main');
    const saveToggleBtn = document.getElementById('rationale_save_template_toggle');
    const editBtn = document.getElementById('rationale_edit_template_btn');
    const deleteBtn = document.getElementById('rationale_delete_template_btn');
    const formContainer = document.getElementById('rationale_template_form_container');
    const templateForm = document.getElementById('rationaleTemplateForm');
    const nameInput = document.getElementById('template_name_input');
    const contentInput = document.getElementById('rationale_template_content_input');
    const templateIdInput = document.getElementById('template_id_to_update');
    const formTitle = document.getElementById('templateFormTitle');

    // Helper function to reset and show the form
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

    // Template Selector Logic (Load & Control Visibility)
    if (selector) {
        selector.addEventListener('change', function() {
            const selectedOption = selector.options[selector.selectedIndex];
            const templateContent = selectedOption.getAttribute('data-content');
            const templateId = selector.value;

            // Load content into main textarea and trigger auto-save (blur)
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
            
            // Hide save form if showing
            formContainer.style.display = 'none';
        });
    }

    // Toggle Save Form Logic (SaveToggleBtn - For New Save)
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

    // Edit Template Button Logic
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

    // Delete Template Logic
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

    // Template Form Submission
    templateForm.addEventListener('submit', function(e) {
        e.preventDefault(); 
        
        const templateId = templateIdInput.value;
        const action = templateId > 0 ? 'edit' : 'add';
        const submitBtn = document.getElementById('templateSaveSubmit');
        submitBtn.disabled = true;

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

    // Cancel button handler
    const cancelBtn = document.querySelector('.rat-cancel-btn');
    if (cancelBtn) {
        cancelBtn.addEventListener('click', function(e) {
            e.preventDefault();
            formContainer.style.display = 'none';
        });
    }

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
