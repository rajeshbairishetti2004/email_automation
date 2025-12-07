<?php
// client_communication.php - SEAMLESS THREE-TEXTAREA DESIGN + TEMPLATE SAVING
// Requires: $clientId, $greetingStored, $introTextStored, $closingTextStored, $templates

$communication_flash_container = isset($_GET['template_added']) || isset($_GET['template_add_error']);
?>

<link rel="stylesheet" href="public/css/client_communication.css">

<div class="editor-card">
    <div class="editor-header">
        <h3 class="editor-title">Client Communication</h3>
    </div>
    
    <div id="communication_flash_container" class="comm-flash-container signature-flash-container"></div>
    
    <div class="editor-toolbar">
        <div class="toolbar-group">
            <label class="toolbar-label">Greeting</label>
            <div class="toolbar-controls">
                <select name="greeting_template_selector" id="greeting_template_selector" class="toolbar-select">
                    <option value="0" data-content="">--- Select Greeting Template ---</option>
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
                <select name="intro_template_selector" id="intro_template_selector" class="toolbar-select">
                    <option value="0" data-content="">--- Select Intro Template ---</option>
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
                <select name="closing_template_selector" id="closing_template_selector" class="toolbar-select">
                    <option value="0" data-content="">--- Select Closing Template ---</option>
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
        <textarea name="greeting" id="greeting_textarea" class="seamless-input greeting-part large-textarea" data-field="greeting" data-client-id="<?php echo (int)$clientId; ?>" placeholder="Greeting..."><?php echo htmlspecialchars($greetingStored ?? ''); ?></textarea>
        <textarea name="intro" id="intro_textarea" class="seamless-input intro-part large-textarea" data-field="intro" data-client-id="<?php echo (int)$clientId; ?>" placeholder="Write the introduction here..."><?php echo htmlspecialchars($introTextStored ?? ''); ?></textarea>
        <textarea name="closing" id="closing_textarea" class="seamless-input closing-part large-textarea" data-field="closing" data-client-id="<?php echo (int)$clientId; ?>" placeholder="Closing remarks..."><?php echo htmlspecialchars($closingTextStored ?? ''); ?></textarea>
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

<script src="public/js/client_communication.js"></script>