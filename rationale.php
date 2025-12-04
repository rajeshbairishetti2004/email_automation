<?php
// rationale.php
// Rationale Editor - Single Block
// Features: Shared System Templates, Inline Save/Edit Form

// 1. Fetch ONLY Rationale Templates
$rationaleTemplates = getReportTemplates('rationale');

// 2. Current Rationale Text
$currentRationale = $rationaleText ?? ''; 
?>

<div class="card">
    <label class="card-title">Rationale</label>
    
    <div id="rationale_flash_container" class="signature-flash-container"></div>

    <div class="template-selector-group" style="margin-bottom: 10px; position: relative; background: #f9f9f9; padding: 10px; border-radius: 6px; border: 1px dashed #ddd;">
        <label style="font-weight: 600; font-size: 13px; margin-right: 10px;">Select Rationale Template:</label>
        
        <select id="rationale_template_selector" style="width: 250px; padding: 6px; border-radius: 4px; border: 1px solid #ccc;">
            <option value="0">--- Custom / Standard ---</option>
            <?php foreach ($rationaleTemplates as $tpl): ?>
                <option value="<?php echo (int)$tpl['id']; ?>" 
                        data-content="<?php echo htmlspecialchars($tpl['content']); ?>" 
                        data-name="<?php echo htmlspecialchars($tpl['name']); ?>">
                    <?php echo htmlspecialchars($tpl['name']); ?>
                </option>
            <?php endforeach; ?>
        </select>
        
        <a href="#" id="rationale_save_toggle" class="action-link-button" style="margin-left: 10px;" title="Save current text as a template">
            💾 Save/Edit
        </a>
        
        <a href="#" id="rationale_delete_btn" class="delete-template-btn" style="margin-left: 5px;" title="Delete selected template">🗑️</a>

        <div id="rationale_save_container" class="template-save-form" style="display: none; padding: 15px; border: 1px solid #0288D1; margin-top: 10px; position: absolute; background: white; z-index: 100; box-shadow: 0 4px 12px rgba(0,0,0,0.15); width: 400px; max-width: 80vw; border-radius: 8px;">
            <form id="rationale_save_form">
                <input type="hidden" name="template_id_to_update" id="rationale_update_id" value="0">
                
                <h5 class="template-form-title" style="margin-top: 0; margin-bottom: 10px; color: #0288D1;">Save Rationale Template</h5>
                
                <label style="font-size: 12px; font-weight: bold;">Template Name:</label>
                <input type="text" id="rationale_template_name" placeholder="e.g. Aggressive Profile" required style="margin-bottom: 10px; width: 100%; padding: 8px; border: 1px solid #ddd; border-radius: 4px; box-sizing: border-box;">
                
                <label style="font-size: 12px; font-weight: bold;">Content:</label>
                <textarea id="rationale_template_content" required rows="4" style="margin-bottom: 10px; width: 100%; padding: 8px; border: 1px solid #ddd; border-radius: 4px; box-sizing: border-box; font-family: inherit;"></textarea>
                
                <div style="text-align: right;">
                    <button type="button" class="btn-secondary" onclick="document.getElementById('rationale_save_container').style.display='none'" style="margin-right: 5px; cursor:pointer;">Cancel</button>
                    <button type="submit" class="btn-primary" style="padding: 6px 12px; font-size: 12px; cursor:pointer;">
                        Save Template
                    </button>
                </div>
            </form>
        </div>
    </div>

    <textarea name="rationale_text" 
              id="rationale_input" 
              class="large-textarea" 
              data-field="rationale_text" 
              data-client-id="<?php echo (int)$clientId; ?>" 
              placeholder="Explain why these schemes were selected..."><?php echo htmlspecialchars($currentRationale); ?></textarea>
              
    <p style="font-size: 12px; color: #666; margin-top: 8px;">
        💡 <strong>Note:</strong> Changes to this text box are saved automatically to the report.
    </p>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const selector = document.getElementById('rationale_template_selector');
    const input = document.getElementById('rationale_input');
    const saveContainer = document.getElementById('rationale_save_container');
    const saveToggle = document.getElementById('rationale_save_toggle');
    const deleteBtn = document.getElementById('rationale_delete_btn');
    const saveForm = document.getElementById('rationale_save_form');

    // 1. Auto-Load on Selection
    selector.addEventListener('change', function() {
        const selectedOption = selector.options[selector.selectedIndex];
        const content = selectedOption.getAttribute('data-content');
        
        if (selector.value !== '0' && content) {
            if(confirm("Replace current text with this template?")) {
                input.value = content;
                // Auto-Resize
                if (typeof autoResizeTextarea === 'function') autoResizeTextarea(input);
                // Auto-Save Report
                input.dispatchEvent(new Event('blur'));
                
                if (typeof showContextualFlash === 'function') {
                    showContextualFlash('success', 'Rationale template loaded!', 'rationale_flash_container');
                }
            }
        }
    });

    // 2. Toggle Save/Edit Form
    saveToggle.addEventListener('click', function(e) {
        e.preventDefault();
        
        if (saveContainer.style.display === 'block') {
            saveContainer.style.display = 'none';
            return;
        }

        const selectedOption = selector.options[selector.selectedIndex];
        const updateId = document.getElementById('rationale_update_id');
        const nameField = document.getElementById('rationale_template_name');
        const contentField = document.getElementById('rationale_template_content');

        // Pre-fill form
        if (selector.value !== '0') {
            // Edit Mode
            updateId.value = selector.value;
            nameField.value = selectedOption.getAttribute('data-name');
            contentField.value = selectedOption.getAttribute('data-content');
        } else {
            // New Mode (Take content from main textarea)
            updateId.value = '0';
            nameField.value = '';
            contentField.value = input.value;
        }

        saveContainer.style.display = 'block';
    });

    // 3. Submit Save Form (AJAX)
    saveForm.addEventListener('submit', function(e) {
        e.preventDefault();
        
        const name = document.getElementById('rationale_template_name').value.trim();
        const content = document.getElementById('rationale_template_content').value.trim();
        const updateId = document.getElementById('rationale_update_id').value;

        if (!name || !content) {
            alert("Name and Content are required.");
            return;
        }

        const formData = new FormData();
        formData.append('ajax_action', 'save_template'); // Uses GENERIC template action
        formData.append('section_type', 'rationale');
        formData.append('template_name', name);
        formData.append('template_content', content);
        if (updateId !== '0') {
            formData.append('template_id', updateId);
        }

        fetch('template_actions.php', { method: 'POST', body: formData })
        .then(r => r.json())
        .then(data => {
            if (data.success) {
                saveContainer.style.display = 'none';
                if (typeof showContextualFlash === 'function') {
                    showContextualFlash('success', 'Template saved successfully!', 'rationale_flash_container');
                }
                setTimeout(() => window.location.reload(), 1000);
            } else {
                alert('Error: ' + data.error);
            }
        });
    });

    // 4. Delete Template
    deleteBtn.addEventListener('click', function(e) {
        e.preventDefault();
        if (selector.value === '0') return;

        if (!confirm("Delete this template permanently?")) return;

        const formData = new FormData();
        formData.append('ajax_action', 'delete_template'); // Uses GENERIC delete action
        formData.append('template_id', selector.value);

        fetch('template_actions.php', { method: 'POST', body: formData })
        .then(r => r.json())
        .then(data => {
            if (data.success) {
                if (typeof showContextualFlash === 'function') {
                    showContextualFlash('success', 'Template deleted.', 'rationale_flash_container');
                }
                setTimeout(() => window.location.reload(), 1000);
            } else {
                alert('Error: ' + data.error);
            }
        });
    });
});
</script>