<?php
// client_communication.php
// Renders the Client Communication section with modular template selection and save forms.
// Requires: $clientId, $clientMessage, $templates['greeting'], $templates['intro'], $templates['closing'], $greetingStored, $introTextStored, $closingTextStored

$communication_flash_container = isset($_GET['template_added']) && $_GET['section'] == 'communication' || isset($_GET['template_add_error']) && isset($_GET['section']) && $_GET['section'] == 'communication';

// Helper function to render a single template group (used three times below)
function renderTemplateGroup(string $part_name, string $section_type, array $templates, int $clientId, string $default_content) {
    ?>
    <div class="template-selector-group">
        <label><?php echo ucfirst($part_name); ?>:</label>
        <select id="<?php echo $section_type; ?>_template_selector" data-template-part="<?php echo $section_type; ?>" style="width: 140px; padding: 5px;">
            <option value="0">--- Custom ---</option>
            <?php foreach ($templates as $tpl): ?>
                <option value="<?php echo (int)$tpl['id']; ?>" data-content="<?php echo htmlspecialchars($tpl['content']); ?>" data-name="<?php echo htmlspecialchars($tpl['name']); ?>">
                    <?php echo htmlspecialchars($tpl['name']); ?>
                </option>
            <?php endforeach; ?>
        </select>
        
        <a href="#" class="save-template-toggle-btn action-link-button" data-target-id="<?php echo $section_type; ?>_save_container" data-template-section="<?php echo $section_type; ?>" style="margin-left: 5px;" title="Save/Edit Template">
            Save/Edit
        </a>
        <a href="#" class="delete-template-btn" data-template-id-attr="<?php echo $section_type; ?>_template_selector" data-template-section="<?php echo $section_type; ?>" style="margin-left: 5px;" title="Delete Template">🗑️</a>

        <div id="<?php echo $section_type; ?>_save_container" class="template-save-form" style="display: none; padding: 15px; border: 1px dashed #CCC; margin-top: 10px; position: absolute; background: white; z-index: 100; box-shadow: 0 4px 8px rgba(0,0,0,0.1); width: 450px; max-width: 60vw;">
            <form method="POST">
                <input type="hidden" name="action_add_template" value="1">
                <input type="hidden" name="client_id" value="<?php echo (int)$clientId; ?>">
                <input type="hidden" name="template_section" value="<?php echo $section_type; ?>">
                <input type="hidden" name="template_id_to_update" class="template-id-to-update" value="0"> <h5 class="template-form-title" style="margin-top: 0; margin-bottom: 5px;">Save/Edit <?php echo ucfirst($part_name); ?> Template</h5>
                
                <input type="text" name="template_name" class="template-name-input" placeholder="Template Name (Required)" required style="margin-bottom: 8px; width: 95%;">
                
                <textarea name="template_content" class="template-content-input" placeholder="Content (Required)" required rows="5" style="margin-bottom: 10px; width: 95%; height: 120px;"></textarea>
                
                <button type="submit" class="rm-action-button" style="width: auto; padding: 3px 6px; font-size: 11px;">
                    💾 Save/Edit
                </button>
            </form>
        </div>
    </div>
    <?php
}
?>

<style>
    /* CSS specific to the Client Communication module */
    .template-part-selector {
        margin-bottom: 10px;
        display: flex;
        align-items: flex-start;
        flex-wrap: wrap;
        padding: 10px 0;
        border: 1px dashed #eee;
        border-radius: 4px;
    }
    .template-selector-group {
        margin-right: 20px;
        padding: 0 10px;
        position: relative; /* Crucial for absolute positioning of save form */
    }
    .template-selector-group label {
        font-weight: 600;
        font-size: 13px;
        display: block;
        margin-bottom: 5px;
    }
    .rm-action-button {
        display: inline-block;
        padding: 4px 8px;
        background-color: #007bff;
        color: white !important;
        border-radius: 4px;
        text-decoration: none;
        font-size: 13px;
        margin-left: 10px;
        transition: background-color 0.2s;
        line-height: normal;
    }
    .rm-action-button:hover {
        background-color: #0056b3;
        text-decoration: none;
    }
    
    /* NEW CSS for Save/Edit Button Link */
    .action-link-button {
        display: inline-block;
        font-size: 11px;
        line-height: 1.5;
        padding: 2px 6px;
        background: #f0f0f0;
        border: 1px solid #ccc;
        border-radius: 3px;
        color: #333 !important;
        vertical-align: top;
    }
    .action-link-button:hover {
        background-color: #ddd;
        text-decoration: none;
    }
    
    /* Buttons next to selectors */
    .delete-template-btn {
        color: red !important;
        font-weight: 600;
        text-decoration: none;
        padding: 2px 4px;
        border: 1px solid #f0f0f0;
        border-radius: 3px;
        cursor: pointer;
        font-size: 13px;
        line-height: 1.5;
        vertical-align: top;
        margin-left: 5px; 
    }
    .delete-template-btn:hover {
        background-color: #ffe6e6;
        text-decoration: none;
    }

    /* Styling for temporary textarea expansion */
    .large-textarea.expanded {
        width: 100% !important;
        height: 250px; 
        transition: all 0.3s ease;
    }
    .large-textarea {
        transition: all 0.3s ease;
        min-height: 100px;
    }
    .template-save-form textarea, .template-save-form input[type="text"] {
        box-sizing: border-box;
    }
</style>

<div class="card">
    <label class="card-title">Client Communication</label>
    
    <div id="communication_flash_container" class="signature-flash-container">
        <?php if (isset($_GET['template_added']) && $_GET['section'] == 'communication'): ?>
            <div class="flash-message flash-success" style="opacity: 1;">✅ Communication template saved successfully!</div>
        <?php elseif (isset($_GET['template_add_error']) && isset($_GET['section']) && $_GET['section'] == 'communication'): ?>
            <div class="flash-message flash-error" style="opacity: 1;">❌ Failed to add template: <?php echo htmlspecialchars($_GET['template_add_error']); ?></div>
        <?php endif; ?>
    </div>

    <div class="template-part-selector">
        
        <?php 
        // Greeting Block
        renderTemplateGroup('greeting', 'greeting', $templates['greeting'], $clientId, $greetingStored); 
        
        // Intro Block
        renderTemplateGroup('intro', 'intro', $templates['intro'], $clientId, $introTextStored); 
        
        // Closing Block
        renderTemplateGroup('closing', 'closing', $templates['closing'], $clientId, $closingTextStored); 
        ?>
    </div>

    <textarea name="client_message"
              class="large-textarea" 
              data-field="client_message" 
              data-client-id="<?php echo (int)$clientId; ?>"
              id="client_message_textarea"
              placeholder="Write your greeting, introduction, and closing remarks here..."><?php echo htmlspecialchars($clientMessage); ?></textarea>
    <p style="font-size: 12px; color: #666; margin-top: 8px;">
        💡 The text is saved as one block. Use the buttons above to manage specific sections.
    </p>
</div>

<script>
// --- JS Logic for Client Communication ---
// NOTE: Global functions (showToast, showContextualFlash, getTemplateContentById, assembleClientMessage) are assumed to be defined in view_report.php's main script block.

document.addEventListener('DOMContentLoaded', function() {

    const mainTextarea = document.getElementById('client_message_textarea');
    
    // Function to expand/collapse the main textarea (Used by multiple modules)
    function toggleTextareaExpansion(expand) {
        if (mainTextarea) {
            mainTextarea.classList.toggle('expanded', expand);
        }
    }
    
    // Function to pre-fill the form with selected content/name
    function prefillTemplateForm(containerId, selectorId, isEdit) {
        const selector = document.getElementById(selectorId);
        const selectedOption = selector.options[selector.selectedIndex];
        const contentInput = document.querySelector(`#${containerId} .template-content-input`);
        const nameInput = document.querySelector(`#${containerId} .template-name-input`);
        const idToUpdateInput = document.querySelector(`#${containerId} .template-id-to-update`);
        
        // Reset the form update ID first
        idToUpdateInput.value = '0';
        
        // Find the currently selected text from the main textarea based on the section type
        const sectionType = selector.getAttribute('data-template-part');
        let currentSectionText = '';
        let mainContent = mainTextarea.value;

        // Determine the content part based on the section type and the current content of the main textarea
        if (sectionType === 'greeting') {
            currentSectionText = mainContent.split('\n\n')[0] || '';
        } else if (sectionType === 'closing') {
            let parts = mainContent.split('\n\n');
            currentSectionText = parts.length > 1 ? parts[parts.length - 1] : mainContent;
        } else { // intro
            let parts = mainContent.split('\n\n');
            if (parts.length > 2) {
                currentSectionText = parts.slice(1, -1).join('\n\n');
            } else {
                currentSectionText = mainContent;
            }
        }
        
        if (isEdit) {
            // Editing existing template (ID != 0)
            nameInput.value = selectedOption.getAttribute('data-name');
            contentInput.value = selectedOption.getAttribute('data-content');
            idToUpdateInput.value = selector.value; // Set the ID for update
        } else {
            // Creating new template (use text currently in the main message box)
            contentInput.value = currentSectionText.trim();
            nameInput.value = ''; // Ensure name field is clear for a new entry
            idToUpdateInput.value = '0';
        }
    }


    // 1. Client Communication Selector Logic (Change event)
    document.querySelectorAll('#greeting_template_selector, #intro_template_selector, #closing_template_selector').forEach(selector => {
        selector.addEventListener('change', function() {
            const newContent = assembleClientMessage();
            
            if (newContent) {
                mainTextarea.value = newContent;
                if (typeof showContextualFlash === 'function') {
                    showContextualFlash('success', 'Communication assembled. Auto-saving...', 'communication_flash_container');
                }
            } else {
                 if (typeof showContextualFlash === 'function') {
                    showContextualFlash('success', 'Communication cleared.', 'communication_flash_container');
                 }
                 mainTextarea.value = '';
            }
            // Trigger blur to save the combined message (blur listener is in view_report.php)
            mainTextarea.dispatchEvent(new Event('blur'));
        });
    });

    // 2. Template Save Toggle Logic (Individual Save/Edit button)
    document.querySelectorAll('.save-template-toggle-btn').forEach(btn => {
        btn.addEventListener('click', function(e) {
            e.preventDefault();
            const sectionType = this.getAttribute('data-template-section');
            const targetContainerId = this.getAttribute('data-target-id');
            const selectorId = `${sectionType}_template_selector`;
            const templateId = document.getElementById(selectorId).value;
            const container = document.getElementById(targetContainerId);
            
            // This button always opens the form for ADDING NEW, using current textarea content
            const isEditing = false; 

            // Close all other open save forms and restore textarea size
            document.querySelectorAll('.template-save-form').forEach(c => {
                if (c.id !== targetContainerId && c.style.display === 'block') {
                    c.style.display = 'none';
                }
            });

            // Toggle visibility
            if (container.style.display === 'block') {
                container.style.display = 'none';
                toggleTextareaExpansion(false); // Collapse
                return;
            }
            
            // --- Preparation before opening form ---
            prefillTemplateForm(targetContainerId, selectorId, isEditing);
            
            // Show the form and expand the main textarea
            container.style.display = 'block';
            toggleTextareaExpansion(true);
        });
    });
    
    // 3. Template Edit Button Logic (Specific to editing selected template)
    document.querySelectorAll('.edit-template-btn').forEach(btn => {
        btn.addEventListener('click', function(e) {
            e.preventDefault();
            const sectionType = this.getAttribute('data-template-section');
            const targetContainerId = this.getAttribute('data-target-id');
            const selectorId = `${sectionType}_template_selector`;
            const templateId = document.getElementById(selectorId).value;
            const container = document.getElementById(targetContainerId);
            
            if (templateId === '0') {
                 if (typeof showContextualFlash === 'function') {
                     showContextualFlash('error', '❌ Select a template to edit first.', 'communication_flash_container');
                 }
                 return;
            }
            
            // Close all other open save forms
            document.querySelectorAll('.template-save-form').forEach(c => {
                if (c.id !== targetContainerId && c.style.display === 'block') {
                    c.style.display = 'none';
                }
            });
            
            // Populate form with existing template details and open for editing
            prefillTemplateForm(targetContainerId, selectorId, true); // Pass true for EDIT mode
            container.style.display = 'block';
            toggleTextareaExpansion(true);
        });
    });
});
</script>