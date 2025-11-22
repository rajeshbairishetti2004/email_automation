<?php
// rationale.php
// Renders the Rationale section with template selection and forms.
// Requires: $clientId, $rationaleText, $templates['rationale']

$rationale_flash_container = isset($_GET['template_added']) && $_GET['section'] == 'rationale' || isset($_GET['template_add_error']) && isset($_GET['section']) && $_GET['section'] == 'rationale';
?>

<style>
    /* CSS specific to Rationale (styles are mostly shared with communication, but defined locally for modularity) */
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
    .delete-template-btn, .save-template-toggle-btn {
        color: #007bff !important;
        font-weight: 600;
        text-decoration: none;
        padding: 2px 4px;
        border: 1px solid #f0f0f0;
        border-radius: 3px;
        cursor: pointer;
        font-size: 13px;
        line-height: 1.5;
        vertical-align: top;
    }
    .delete-template-btn {
        color: red !important;
    }
    .template-save-form textarea, .template-save-form input[type="text"] {
        box-sizing: border-box; /* Ensure padding doesn't affect the width */
    }
</style>

<div class="card" style="margin-top: 20px;">
    <label class="card-title">Rationale</label>
    
    <div id="rationale_flash_container" class="signature-flash-container">
        <?php if (isset($_GET['template_added']) && $_GET['section'] == 'rationale'): ?>
            <div class="flash-message flash-success" style="opacity: 1;">✅ Rationale template saved successfully!</div>
        <?php elseif (isset($_GET['template_add_error']) && isset($_GET['section']) && $_GET['section'] == 'rationale'): ?>
            <div class="flash-message flash-error" style="opacity: 1;">❌ Failed to add template: <?php echo htmlspecialchars($_GET['template_add_error']); ?></div>
        <?php endif; ?>
    </div>

    <div style="margin-bottom: 10px; display: flex; align-items: center; flex-wrap: wrap;">
        <label for="rationale_template_selector_main" style="font-size: 14px; font-weight: normal; margin-top: 0; margin-right: 10px;">
            Load Template:
        </label>
        <select id="rationale_template_selector_main" data-textarea-id="rationale_textarea" data-section-type="rationale" style="width: 160px; padding: 5px;">
            <option value="0">--- Use Current Text ---</option>
            <?php foreach ($templates['rationale'] as $tpl): ?>
                <option value="<?php echo (int)$tpl['id']; ?>" data-content="<?php echo htmlspecialchars($tpl['content']); ?>">
                    <?php echo htmlspecialchars($tpl['name']); ?>
                </option>
            <?php endforeach; ?>
        </select>
        <a href="#" class="delete-template-btn" data-template-id-attr="rationale_template_selector_main" data-template-section="rationale" style="margin-left: 5px;" title="Delete Template">🗑️</a>
        
        <a href="#" id="rationale_save_template_toggle" class="rm-action-button" style="margin-left: 10px;">
            + Save Current Text as New Template
        </a>
    </div>

    <div id="rationale_save_template_container" style="display: none; padding: 15px; border: 1px dashed #DDD; margin-bottom: 15px;">
        <form method="POST">
            <input type="hidden" name="action_add_template" value="1">
            <input type="hidden" name="client_id" value="<?php echo (int)$clientId; ?>">
            <input type="hidden" name="template_section" value="rationale">
            <h4 style="margin-top: 0; margin-bottom: 10px;">Save Rationale Template</h4>
            
            <input type="text" name="template_name" placeholder="Template Name (Required)" required style="margin-bottom: 8px;">
            <textarea name="template_content" id="rationale_template_content_input" placeholder="Paste the full content here..." required rows="5" style="margin-bottom: 10px;"><?php echo htmlspecialchars($rationaleText); ?></textarea>
            
            <button type="submit" class="rm-action-button" style="width: auto;">
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
    const rationaleTextarea = document.getElementById('rationale_textarea');

    function toggleRationaleTextareaExpansion(expand) {
        if (rationaleTextarea) {
            rationaleTextarea.classList.toggle('expanded', expand);
        }
    }

    // 1. Rationale Selector Logic
    const rationaleSelector = document.getElementById('rationale_template_selector_main');
    if (rationaleSelector) {
        rationaleSelector.addEventListener('change', function() {
            // getTemplateContentById is assumed to be available globally (in view_report.php)
            const templateContent = getTemplateContentById('rationale_template_selector_main');
            
            if (templateContent !== null) {
                rationaleTextarea.value = templateContent;
                // showContextualFlash is defined in view_report.php
                if (typeof showContextualFlash === 'function') {
                    showContextualFlash('success', 'Rationale template loaded. Auto-saving...', 'rationale_flash_container');
                }
                rationaleTextarea.dispatchEvent(new Event('blur'));
            } else {
                 if (typeof showContextualFlash === 'function') {
                    showContextualFlash('success', 'Using current Rationale text.', 'rationale_flash_container');
                 }
                 rationaleTextarea.dispatchEvent(new Event('blur'));
            }
        });
    }

    // 2. Template Save Toggle Logic
    const rationaleSaveToggle = document.getElementById('rationale_save_template_toggle');
    const rationaleSaveContainer = document.getElementById('rationale_save_template_container');
    
    if (rationaleSaveToggle) {
        rationaleSaveToggle.addEventListener('click', function(e) {
            e.preventDefault();
            
            // Toggle visibility
            if (rationaleSaveContainer.style.display === 'block') {
                rationaleSaveContainer.style.display = 'none';
                toggleRationaleTextareaExpansion(false);
                return;
            }
            
            // Pre-fill content from the main textarea
            document.querySelector('#rationale_save_template_container form [name="template_name"]').value = '';
            document.getElementById('rationale_template_content_input').value = rationaleTextarea.value;
            
            // Show the form and expand the textarea
            rationaleSaveContainer.style.display = 'block';
            toggleRationaleTextareaExpansion(true);
        });
    }
});
</script>