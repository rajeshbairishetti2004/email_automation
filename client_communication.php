<?php
// client_communication.php - SEAMLESS THREE-TEXTAREA DESIGN + TEMPLATE SAVING
// Requires: $clientId, $greetingStored, $introTextStored, $closingTextStored, $templates

$communication_flash_container = isset($_GET['template_added']) || isset($_GET['template_add_error']);
?>

<head>
    <!-- ...existing code... -->
    <link rel="stylesheet" href="public/client_communication.css">
    <!-- ...existing code... -->
</head>

<div class="editor-card">
    <div class="editor-header">
        <h3 class="editor-title">Client Communication</h3>
    </div>
    
    <div id="communication_flash_container" class="comm-flash-container signature-flash-container"></div>
    
    <div class="editor-toolbar">
        <div class="toolbar-group">
            <label class="toolbar-label">Greeting</label>
            <div class="toolbar-controls">
                <select id="greeting_template_selector" class="toolbar-select">
                    <option value="0">-- Select --</option>
                    <?php foreach ($templates['greeting'] as $t): ?>
                        <?php
                            $templateName = htmlspecialchars($t['template_name'] ?? $t['name'] ?? 'Untitled Template');
                            $templateContent = htmlspecialchars($t['content'] ?? '');
                        ?>
                        <option value="<?php echo (int)($t['id'] ?? 0); ?>" data-content="<?php echo $templateContent; ?>">
                            <?php echo $templateName; ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <button type="button" class="btn-icon" onclick="openSaveModal('greeting')" title="Save as New Template">💾</button>
                <button type="button" class="delete-template-btn btn-icon delete" data-template-id-attr="greeting_template_selector" title="Delete Template">🗑️</button>
            </div>
        </div>
        
        <div class="toolbar-group" style="flex: 2;">
            <label class="toolbar-label">Intro Body</label>
            <div class="toolbar-controls">
                <select id="intro_template_selector" class="toolbar-select">
                    <option value="0">-- Select --</option>
                    <?php foreach ($templates['intro'] as $t): ?>
                        <?php
                            $templateName = htmlspecialchars($t['template_name'] ?? $t['name'] ?? 'Untitled Template');
                            $templateContent = htmlspecialchars($t['content'] ?? '');
                        ?>
                        <option value="<?php echo (int)($t['id'] ?? 0); ?>" data-content="<?php echo $templateContent; ?>">
                            <?php echo $templateName; ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <button type="button" class="btn-icon" onclick="openSaveModal('intro')" title="Save as New Template">💾</button>
                <button type="button" class="delete-template-btn btn-icon delete" data-template-id-attr="intro_template_selector" title="Delete Template">🗑️</button>
            </div>
        </div>
        
        <div class="toolbar-group">
            <label class="toolbar-label">Closing</label>
            <div class="toolbar-controls">
                <select id="closing_template_selector" class="toolbar-select">
                    <option value="0">-- Select --</option>
                    <?php foreach ($templates['closing'] as $t): ?>
                        <?php
                            $templateName = htmlspecialchars($t['template_name'] ?? $t['name'] ?? 'Untitled Template');
                            $templateContent = htmlspecialchars($t['content'] ?? '');
                        ?>
                        <option value="<?php echo (int)($t['id'] ?? 0); ?>" data-content="<?php echo $templateContent; ?>">
                            <?php echo $templateName; ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <button type="button" class="btn-icon" onclick="openSaveModal('closing')" title="Save as New Template">💾</button>
                <button type="button" class="delete-template-btn btn-icon delete" data-template-id-attr="closing_template_selector" title="Delete Template">🗑️</button>
            </div>
        </div>
    </div>

    <div class="seamless-editor-wrapper">
        <textarea name="greeting" id="greeting_input" class="seamless-input greeting-part" data-field="greeting" data-client-id="<?php echo (int)$clientId; ?>" placeholder="Greeting..."><?php echo htmlspecialchars($greetingStored ?? ''); ?></textarea>
        <textarea name="intro" id="intro_input" class="seamless-input intro-part" data-field="intro" data-client-id="<?php echo (int)$clientId; ?>" placeholder="Write the introduction here..."><?php echo htmlspecialchars($introTextStored ?? ''); ?></textarea>
        <textarea name="closing" id="closing_input" class="seamless-input closing-part" data-field="closing" data-client-id="<?php echo (int)$clientId; ?>" placeholder="Closing remarks..."><?php echo htmlspecialchars($closingTextStored ?? ''); ?></textarea>
    </div>
</div>

<div id="saveTemplateModal" class="modal-overlay">
    <div class="modal-box">
        <h3 style="margin-top:0; color:#007bff;">Save New Template</h3>
        <p>Give your new template a name:</p>
        <input type="text" id="new_template_name" style="width:100%; padding:8px; border:1px solid #ccc; border-radius:4px; margin-bottom:15px;" placeholder="e.g. Standard Welcome">
        
        <input type="hidden" id="current_section_type" value="">
        
        <div style="text-align:right;">
            <button onclick="document.getElementById('saveTemplateModal').style.display='none'" style="padding:8px 12px; border:none; background:#ccc; cursor:pointer; margin-right:5px; border-radius:4px;">Cancel</button>
            <button onclick="submitNewTemplate()" style="padding:8px 12px; border:none; background:#007bff; color:white; cursor:pointer; border-radius:4px;">Save Template</button>
        </div>
    </div>
</div>

<script>
// --- 1. Auto-Load Template Logic ---
function applyTemplate(selectorId, targetInputId) {
    const selector = document.getElementById(selectorId);
    const target = document.getElementById(targetInputId);
    const selectedOption = selector.options[selector.selectedIndex];
    const content = selectedOption.getAttribute('data-content');
    
    // Only load if a valid template is selected (ignore "-- Select --")
    if (content !== null && selector.value !== '0') {
        target.value = content;
        
        // 1. Trigger Auto-Resize (if the function exists from view_report.php)
        if (typeof autoResizeTextarea === 'function') {
            autoResizeTextarea(target);
        }
        
        // 2. Trigger Auto-Save (Blur event)
        target.dispatchEvent(new Event('blur')); 
        
        showContextualFlash('success', 'Template loaded automatically!', 'communication_flash_container');
    }
}

// Attach listeners to Dropdowns
document.addEventListener('DOMContentLoaded', function() {
    
    // Greeting
    document.getElementById('greeting_template_selector').addEventListener('change', function() {
        applyTemplate('greeting_template_selector', 'greeting_input');
    });

    // Intro
    document.getElementById('intro_template_selector').addEventListener('change', function() {
        applyTemplate('intro_template_selector', 'intro_input');
    });

    // Closing
    document.getElementById('closing_template_selector').addEventListener('change', function() {
        applyTemplate('closing_template_selector', 'closing_input');
    });

});

// --- 2. Open Save Modal ---
function openSaveModal(section) {
    // Check if there is text to save
    const inputId = section + '_input';
    const content = document.getElementById(inputId).value.trim();
    
    if (!content) {
        alert("Please write some text in the " + section + " box first.");
        return;
    }
    
    document.getElementById('current_section_type').value = section;
    document.getElementById('new_template_name').value = ''; // Reset name
    document.getElementById('saveTemplateModal').style.display = 'flex';
    document.getElementById('new_template_name').focus();
}

// --- 3. Submit New Template via AJAX ---
function submitNewTemplate() {
    const section = document.getElementById('current_section_type').value;
    const name = document.getElementById('new_template_name').value.trim();
    const content = document.getElementById(section + '_input').value.trim();
    const clientId = <?php echo (int)$clientId; ?>;

    if (!name) {
        alert("Template name is required.");
        return;
    }

    // AJAX POST to template_actions.php
    fetch('template_actions.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
        body: new URLSearchParams({
            ajax_action: 'save_template', // Matches the backend switch case
            section_type: section,
            template_name: name,
            template_content: content
        })
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            document.getElementById('saveTemplateModal').style.display = 'none';
            showContextualFlash('success', 'Template saved! Reloading...', 'communication_flash_container');
            // Reload page to refresh dropdowns
            setTimeout(() => window.location.reload(), 1000);
        } else {
            alert('Error: ' + (data.error || 'Unknown error'));
        }
    })
    .catch(err => {
        alert('Network error: ' + err.message);
    });
}
</script>