<?php
// rationale.php
// Complete standalone template management for rationale section
// Also handles workflow actions and auto-save

// Handle AJAX requests for template management AND workflow actions
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ajax_action'])) {
    ob_start();
    header('Content-Type: application/json');
    
    $action = $_POST['ajax_action'] ?? '';
    $response = ['success' => false];
    
    try {
        require_once 'db_config.php';
        $pdo = getPdo();
        
        // Template management actions
        if ($action === 'edit_template') {
            $stmt = $pdo->prepare("UPDATE report_templates SET name = ?, content = ? WHERE id = ?");
            $stmt->execute([
                $_POST['template_name'] ?? '',
                $_POST['template_content'] ?? '',
                (int)$_POST['template_id']
            ]);
            $response['success'] = true;
        } 
        elseif ($action === 'delete_template') {
            $stmt = $pdo->prepare("DELETE FROM report_templates WHERE id = ?");
            $stmt->execute([(int)$_POST['template_id']]);
            $response['success'] = true;
            $response['deleted_id'] = (int)$_POST['template_id'];
        }
        elseif ($action === 'save_template') {
            $section = $_POST['section_type'] ?? '';
            $name = trim($_POST['template_name'] ?? '');
            $content = $_POST['template_content'] ?? '';
            
            if ($name === '' || $content === '') {
                throw new Exception('Template name and content are required');
            }
            
            $stmt = $pdo->prepare("INSERT INTO report_templates (name, section_type, content) VALUES (?, ?, ?)");
            $stmt->execute([$name, $section, $content]);
            $newId = $pdo->lastInsertId();
            
            $response['success'] = true;
            $response['new_id'] = $newId;
            $response['template_name'] = $name;
            $response['template_content'] = $content;
        }
        // Rationale-specific template actions (compatible with existing view_report.php)
        elseif ($action === 'save_user_template') {
            $templateId = (int)($_POST['template_id_to_update'] ?? 0);
            $name = trim($_POST['template_name'] ?? '');
            $content = $_POST['template_content'] ?? '';
            
            if ($name === '' || $content === '') {
                throw new Exception('Template name and content are required');
            }
            
            if ($templateId > 0) {
                // Update existing template
                $stmt = $pdo->prepare("UPDATE report_templates SET name = ?, content = ? WHERE id = ? AND section_type = 'rationale'");
                $stmt->execute([$name, $content, $templateId]);
            } else {
                // Create new template
                $stmt = $pdo->prepare("INSERT INTO report_templates (name, section_type, content) VALUES (?, 'rationale', ?)");
                $stmt->execute([$name, $content]);
                $templateId = $pdo->lastInsertId();
            }
            
            $response['success'] = true;
            $response['template_id'] = $templateId;
        }
        elseif ($action === 'delete_user_template') {
            $templateId = (int)($_POST['template_id'] ?? 0);
            
            if ($templateId <= 0) {
                throw new Exception('Invalid template ID');
            }
            
            $stmt = $pdo->prepare("DELETE FROM report_templates WHERE id = ? AND section_type = 'rationale'");
            $stmt->execute([$templateId]);
            $response['success'] = true;
        }
        // Workflow actions - UPDATED TO USE SAME PATTERN AS client_communication.php
        elseif ($action === 'save_draft') {
            $clientId = (int)($_POST['client_id'] ?? 0);
            if ($clientId <= 0) throw new Exception("Invalid Client ID.");

            // Note: rationale_text should already be saved via auto-save on blur
            $stmt = $pdo->prepare("UPDATE clients SET report_state = 'draft', draft_at = NOW(), review_not_ok = 0, review_comment = NULL WHERE id = :id");
            $stmt->execute([':id' => $clientId]);
            echo json_encode(['success' => true, 'message' => 'Draft saved successfully.', 'updated_state' => 'draft']);
            exit;
        }
        elseif ($action === 'ready_for_review') {
            $clientId = (int)($_POST['client_id'] ?? 0);
            if ($clientId <= 0) throw new Exception("Invalid Client ID.");

            // Note: rationale_text should already be saved via auto-save on blur
            $stmt = $pdo->prepare("UPDATE clients SET report_state = 'ready', ready_at = NOW(), review_not_ok = 0, review_comment = NULL WHERE id = :id");
            $stmt->execute([':id' => $clientId]);
            echo json_encode(['success' => true, 'message' => 'Report marked Ready for Review.', 'updated_state' => 'ready']);
            exit;
        }
        elseif ($action === 'approve_review') {
            $clientId = (int)($_POST['client_id'] ?? 0);
            if ($clientId <= 0) throw new Exception("Invalid Client ID.");

            // Note: rationale_text should already be saved via auto-save on blur
            $stmt = $pdo->prepare("UPDATE clients SET report_state = 'reviewed', reviewed_at = NOW(), review_not_ok = 0, review_comment = NULL WHERE id = :id");
            $stmt->execute([':id' => $clientId]);
            echo json_encode(['success' => true, 'message' => 'Report Approved (Reviewed).', 'updated_state' => 'reviewed']);
            exit;
        }
        elseif ($action === 'review_not_ok') {
            $clientId = (int)($_POST['client_id'] ?? 0);
            $comment  = trim($_POST['review_comment'] ?? '');
            if ($clientId <= 0) throw new Exception("Invalid Client ID.");
            if (empty($comment)) throw new Exception("A comment is required for rejection.");

            // Note: rationale_text should already be saved via auto-save on blur
            $stmt = $pdo->prepare("UPDATE clients SET report_state = 'draft', review_not_ok = 1, review_comment = :comment WHERE id = :id");
            $stmt->execute([':id' => $clientId, ':comment' => $comment]);
            echo json_encode(['success' => true, 'message' => 'Report rejected and moved back to Draft.', 'updated_state' => 'draft']);
            exit;
        }
        elseif ($action === 'email_sent') {
            $clientId = (int)($_POST['client_id'] ?? 0);
            if ($clientId <= 0) throw new Exception("Invalid Client ID.");
            
            // Note: rationale_text should already be saved via auto-save on blur
            $stmt = $pdo->prepare("UPDATE clients SET report_state = 'sent', sent_at = NOW() WHERE id = :id");
            $stmt->execute([':id' => $clientId]);
            echo json_encode(['success' => true, 'message' => 'Report marked as Sent.', 'updated_state' => 'sent']);
            exit;
        }
        // Auto-save rationale text (from blur event) - This is what preserves the text
        elseif ($action === 'save_rationale') {
            $clientId = (int)($_POST['client_id'] ?? 0);
            $value = trim($_POST['value'] ?? '');
            
            if ($clientId <= 0) throw new Exception("Invalid Client ID.");
            
            $stmt = $pdo->prepare("UPDATE clients SET rationale_text = ? WHERE id = ?");
            $stmt->execute([$value, $clientId]);
            echo json_encode(['success' => true, 'message' => 'Rationale saved successfully.']);
            exit;
        }
        // Auto-save on interval (for autosave feature)
        elseif ($action === 'autosave_rationale') {
            $clientId = (int)($_POST['client_id'] ?? 0);
            $value = $_POST['value'] ?? '';
            
            if ($clientId <= 0) {
                throw new Exception("Invalid Client ID.");
            }
            
            $stmt = $pdo->prepare("UPDATE clients SET rationale_text = :val WHERE id = :id");
            $stmt->execute([':val' => $value, ':id' => $clientId]);
            echo json_encode(['success' => true]);
            exit;
        }
        else {
            // Unknown action
            echo json_encode(['success' => false, 'error' => 'Unknown action: ' . $action]);
            exit;
        }
        
        // Only for template actions that haven't exited yet
        ob_end_clean();
        echo json_encode($response);
        exit;
        
    } catch (Throwable $e) {
        // Clean output buffer and return error
        ob_end_clean();
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        exit;
    }
}

// If not an AJAX request, output the rationale module HTML
// ------------------------------------------------------------------
// This file is typically included by view_report.php
// Expects: $templates (array), $clientId, $rationaleText, $isLocked to be present
// ------------------------------------------------------------------

// --- Ensure $rationaleText is always up-to-date from DB before rendering textarea ---
if (!isset($rationaleText) || $rationaleText === '') {
    require_once 'db_config.php';
    $pdo = getPdo();
    $stmt = $pdo->prepare("SELECT rationale_text FROM clients WHERE id = ?");
    $stmt->execute([$clientId]);
    $rationaleText = $stmt->fetchColumn() ?? '';
}
?>

<style>
/* Rationale module styles - updated to match site blue theme */
.rat-box {
    margin-top: 18px;
    margin-bottom: 18px;
    padding: 14px;
    border: 1px solid #e6f2fb;
    border-radius: 8px;
    background: linear-gradient(180deg, #fbfdff 0%, #f6fbff 100%);
    box-shadow: 0 1px 0 rgba(2,136,209,0.03);
    font-family: Inter, Arial, sans-serif;
}

.rat-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 12px;
    padding-bottom: 8px;
    border-bottom: 1px solid #e6f2fb;
}

.rat-title {
    font-weight: 700;
    color: #083744;
    font-size: 16px;
    margin: 0;
}

.rat-controls {
    display: flex;
    gap: 10px;
    align-items: center;
    margin-bottom: 10px;
    flex-wrap: wrap;
}

.rat-select {
    min-width: 300px;
    padding: 8px 10px;
    border: 1px solid #dbeefb;
    border-radius: 6px;
    background: #fff;
    color: #083744;
    font-size: 14px;
    box-shadow: inset 0 1px 0 rgba(2,136,209,0.02);
}

.rat-btn {
    padding: 8px 12px;
    border-radius: 6px;
    border: none;
    cursor: pointer;
    font-weight: 600;
    font-size: 13px;
    box-shadow: 0 1px 0 rgba(0,0,0,0.02);
    transition: background-color 0.12s ease, transform 0.06s ease;
}

/* Blue-themed buttons to match page */
.rat-btn.save {
    background: #0288D1; /* primary */
    color: #fff;
}
.rat-btn.save:hover { background: #2eb85c !important; transform: translateY(-1px); }

.rat-btn.edit {
    background: #039be5; /* lighter blue */
    color: #fff;
}
.rat-btn.edit:hover { background: #0288d1; transform: translateY(-1px); }

/* Delete: base blue, hover becomes red */
.rat-btn.del {
    background: #0277bd; /* darker blue */
    color: #fff;
}
.rat-btn.del:hover { background: #dc3545 !important; transform: translateY(-1px); }

/* Add button (plus) specific styling */
.rat-btn.add {
    display: flex;
    align-items: center;
    justify-content: center;
    width: 36px;
    height: 36px;
    padding: 0;
    border-radius: 50%;
    background: #eaf7ff;
    border: 1px solid #cfeefc;
    color: #0288d1;
}

/* Disabled button state */
.rat-btn[disabled] {
    opacity: 0.65;
    cursor: not-allowed;
    transform: none !important;
}

/* Focus / keyboard accessibility */
.rat-btn:focus,
.rat-select:focus,
.rat-textarea:focus {
    outline: 3px solid rgba(2,136,209,0.12);
    outline-offset: 2px;
}

/* Textarea with auto-grow */
.rat-textarea {
    width: 100%;
    padding: 12px;
    font-size: 14px;
    min-height: 140px;
    height: auto;
    line-height: 1.5;
    box-sizing: border-box;
    border: 1px solid #dbeefb;
    border-radius: 6px;
    background: #fff;
    color: #052b36;
    resize: none;
    overflow: hidden;
}

/* Textarea with auto-resize height */
.rat-textarea.auto-resize {
    resize: none;
    overflow-y: hidden;
}

/* Flash messages area */
.rat-flash { 
    margin-top: 8px; 
    min-height: 26px; 
    font-size: 13px;
}

.flash-success {
    color: #2eb85c;
    background: #edf9f0;
    padding: 6px 10px;
    border-radius: 4px;
    border-left: 3px solid #2eb85c;
}

.flash-error {
    color: #dc3545;
    background: #fef2f2;
    padding: 6px 10px;
    border-radius: 4px;
    border-left: 3px solid #dc3545;
}

/* Make buttons consistent on small screens */
@media (max-width: 640px) {
    .rat-controls { 
        flex-direction: column; 
        align-items: stretch; 
    }
    .rat-select { 
        width: 100%; 
    }
    .rat-btn:not(.add) { 
        width: 100%; 
        text-align: center; 
    }
}

/* SVG plus icon */
.rat-btn.add svg {
    width: 16px;
    height: 16px;
    stroke: currentColor;
    stroke-width: 2;
    stroke-linecap: round;
    stroke-linejoin: round;
}
</style>

<?php
// Define variables if not already defined by parent scope
$clientId = $clientId ?? 0;
$rationaleText = $rationaleText ?? '';
$isLocked = $isLocked ?? false;
$templates = $templates ?? [];
?>

<div class="comm-section" id="rationale_module">
    <div class="comm-header">
        <div class="comm-title">
            Rationale
            <?php if (!empty($isLocked)): ?>
                <span title="Locked" style="margin-left:8px;color:#888;">🔒</span>
            <?php endif; ?>
        </div>
    </div>
    <div class="rat-controls">
        <select id="rationale_template_selector" class="rat-select" <?= $isLocked ? 'disabled' : '' ?>>
            <option value="0">-- Select saved rationale template --</option>
            <?php if (!empty($templates['rationale'])): ?>
                <?php foreach ($templates['rationale'] as $t): 
                    $tid = (int)($t['id'] ?? 0);
                    $tname = htmlspecialchars($t['name'] ?? '');
                    $tcontent = htmlspecialchars($t['content'] ?? '');
                ?>
                    <option value="<?= $tid ?>" data-content="<?= $tcontent ?>">
                        <?= $tname ?>
                    </option>
                <?php endforeach; ?>
            <?php endif; ?>
        </select>
        
        <button id="rationale_add_btn" class="rat-btn add" type="button" title="Add new template" aria-label="Add new template" <?= $isLocked ? 'disabled' : '' ?>>
            <svg viewBox="0 0 24 24" fill="none" aria-hidden="true" focusable="false">
                <path d="M12 5v14M5 12h14" stroke="currentColor"/>
            </svg>
        </button>
        
        <button id="rationale_save_btn" class="rat-btn save" type="button" <?= $isLocked ? 'disabled' : '' ?>>Save</button>
        <button id="rationale_edit_btn" class="rat-btn edit" type="button" <?= $isLocked ? 'disabled' : '' ?>>Edit</button>
        <button id="rationale_delete_btn" class="rat-btn del" type="button" <?= $isLocked ? 'disabled' : '' ?>>Delete</button>
    </div>
    
    <textarea
        id="rationale_textarea"
        name="rationale_text"
        class="comm-textarea large-textarea rat-main-textarea"
        data-client-id="<?= (int)$clientId ?>"
        data-field="rationale_text"
        <?= $isLocked ? 'readonly' : '' ?>
    ><?= htmlspecialchars($rationaleText) ?></textarea>
    
    <div id="rationale_flash_container" class="comm-flash"></div>
</div>

<script>
(function(){
    const selector = document.getElementById('rationale_template_selector');
    const textarea = document.getElementById('rationale_textarea');
    const saveBtn = document.getElementById('rationale_save_btn');
    const editBtn = document.getElementById('rationale_edit_btn');
    const delBtn = document.getElementById('rationale_delete_btn');
    const addBtn = document.getElementById('rationale_add_btn');
    const flash = document.getElementById('rationale_flash_container');
    const isLocked = <?= $isLocked ? 'true' : 'false' ?>;
    const clientId = <?= json_encode((int)$clientId); ?>;

    // Auto-save variables
    let autoSaveTimeout = null;
    let lastSavedValue = textarea.value;
    const AUTO_SAVE_DELAY = 1000; // 1 second delay for auto-save
    let isSaving = false;

    // --- Auto-height resize logic ---
    function autoResizeHeight() {
        if (!textarea) return;
        
        // Store current cursor position
        const start = textarea.selectionStart;
        const end = textarea.selectionEnd;
        
        // Reset height to auto to calculate scrollHeight
        textarea.style.height = 'auto';
        
        // Calculate content height (add 2px buffer)
        const scrollHeight = textarea.scrollHeight;
        textarea.style.height = (scrollHeight + 2) + 'px';
        
        // Restore cursor position
        textarea.setSelectionRange(start, end);
    }

    // Initialize auto-resize
    autoResizeHeight();
    
    // Resize on input, paste, and cut
    textarea.addEventListener('input', () => {
        autoResizeHeight();
        triggerAutoSave(); // Also trigger auto-save on input
    });
    
    textarea.addEventListener('paste', () => {
        setTimeout(() => {
            autoResizeHeight();
            triggerAutoSave();
        }, 0);
    });
    
    textarea.addEventListener('cut', () => {
        setTimeout(() => {
            autoResizeHeight();
            triggerAutoSave();
        }, 0);
    });

    // --- Auto-save logic with debouncing ---
    function triggerAutoSave() {
        if (isLocked || isSaving) return;
        
        const currentValue = textarea.value.trim();
        
        // Only save if value has changed
        if (currentValue === lastSavedValue) return;
        
        // Clear any existing timeout
        if (autoSaveTimeout) {
            clearTimeout(autoSaveTimeout);
        }
        
        // Set new timeout for auto-save
        autoSaveTimeout = setTimeout(() => {
            performAutoSave(currentValue);
        }, AUTO_SAVE_DELAY);
    }

    function performAutoSave(value) {
        if (isLocked || isSaving) return;
        
        const clientId = textarea.getAttribute('data-client-id');
        const field = textarea.getAttribute('data-field');
        
        if (!clientId || !field) return;
        
        isSaving = true;
        
        fetch('rationale.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8'
            },
            body: new URLSearchParams({
                ajax_action: 'save_rationale',
                client_id: clientId,
                value: value
            })
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                lastSavedValue = value;
                showFlash('success', data.message || 'Auto-saved successfully');
            } else {
                showFlash('error', 'Auto-save failed: ' + (data.error || 'Unknown error'));
            }
        })
        .catch(err => {
            console.error('Auto-save error:', err);
            showFlash('error', 'Network error while auto-saving');
        })
        .finally(() => {
            isSaving = false;
        });
    }

    // Also save on blur as a fallback
    textarea.addEventListener('blur', () => {
        if (isLocked) return;
        
        const currentValue = textarea.value.trim();
        if (currentValue !== lastSavedValue) {
            performAutoSave(currentValue);
        }
        
        // Clear any pending timeout
        if (autoSaveTimeout) {
            clearTimeout(autoSaveTimeout);
            autoSaveTimeout = null;
        }
    });

    // Clean up timeout when page unloads
    window.addEventListener('beforeunload', () => {
        if (autoSaveTimeout) {
            clearTimeout(autoSaveTimeout);
        }
    });

    // CRITICAL: Intercept workflow buttons from view_report.php
    function interceptWorkflowButtons() {
        // Find workflow buttons in the main form
        const workflowButtons = document.querySelectorAll('.workflow-actions .wf-btn');
        
        workflowButtons.forEach(button => {
            button.addEventListener('click', function(e) {
                if (isLocked) return;
                
                // Get the action from onclick attribute
                const onclickAttr = this.getAttribute('onclick');
                if (onclickAttr && onclickAttr.includes('submitWorkflow')) {
                    // Extract the action from the onclick
                    const match = onclickAttr.match(/submitWorkflow\('([^']+)'\)/);
                    if (match) {
                        const action = match[1];
                        
                        // Save rationale before workflow action
                        const rationaleValue = textarea.value.trim();
                        
                        // Only proceed if we're not already saving
                        if (!isSaving) {
                            performAutoSave(rationaleValue).then(() => {
                                // After auto-save completes, continue with workflow
                                // The original onclick will be triggered by the form submission
                            });
                        }
                    }
                }
            });
        });
    }

    // Call this function when the page loads
    setTimeout(interceptWorkflowButtons, 500);

    function findOptionByValue(val) {
        if (!selector) return null;
        const target = String(val);
        for (const option of selector.options) {
            if (option.value === target) return option;
        }
        return null;
    }

    function upsertTemplateOption(id, name, content) {
        if (!selector) return null;
        const value = String(id);
        let opt = findOptionByValue(value);
        if (!opt) {
            opt = document.createElement('option');
            opt.value = value;
            selector.appendChild(opt);
        }
        opt.textContent = name;
        opt.setAttribute('data-content', content);
        return opt;
    }

    function removeTemplateOption(id) {
        const opt = findOptionByValue(id);
        if (opt) opt.remove();
    }

    function showFlash(type, msg) {
        flash.innerHTML = '<div class="flash-message ' + (type === 'success' ? 'flash-success' : 'flash-error') + '">' +
                         (type === 'success' ? '✓ ' : '✗ ') + msg + '</div>';
        setTimeout(() => { flash.innerHTML = ''; }, 3500);
    }

    function setButtonsDisabled(state) {
        [addBtn, saveBtn, editBtn, delBtn].forEach(btn => {
            if (!btn) return;
            btn.disabled = !!state;
        });
    }

    // Auto-load when selection changes
    if (selector) {
        selector.addEventListener('change', function() {
            const id = selector.value;
            if (id && id !== '0') {
                const opt = selector.options[selector.selectedIndex];
                const newContent = opt.getAttribute('data-content') || '';
                textarea.value = newContent;
                autoResizeHeight();
                showFlash('success', 'Template loaded into editor.');
                
                // Update last saved value and trigger auto-save
                lastSavedValue = newContent;
                triggerAutoSave();
            }
        });
    }

    // Add: create new template from current textarea content
    if (addBtn) {
        addBtn.addEventListener('click', function() {
            if (isLocked) return;
            
            const content = (textarea.value || '').trim();
            if (!content) {
                showFlash('error', 'Rationale content cannot be empty.');
                return;
            }
            const name = prompt('Enter a name for this new rationale template:');
            if (!name || !name.trim()) return;

            setButtonsDisabled(true);
            const body = new URLSearchParams();
            body.append('ajax_action', 'save_template');
            body.append('template_name', name.trim());
            body.append('template_content', content);
            body.append('section_type', 'rationale');

            fetch('rationale.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
                body: body
            })
            .then(r => r.json())
            .then(data => {
                if (data && data.success) {
                    if (data.new_id) {
                        const opt = upsertTemplateOption(data.new_id, name.trim(), content);
                        if (opt) selector.value = String(data.new_id);
                    }
                    showFlash('success', 'Template added successfully.');
                } else {
                    throw new Error(data && data.error ? data.error : 'Save failed');
                }
            })
            .catch((err) => showFlash('error', err.message))
            .finally(() => setButtonsDisabled(false));
        });
    }

    // Edit: load selected template into editor
    if (editBtn) {
        editBtn.addEventListener('click', function() {
            if (isLocked) return;
            
            const id = selector.value;
            if (!id || id === '0') {
                showFlash('error', 'Please select a template to edit.');
                return;
            }
            const opt = selector.options[selector.selectedIndex];
            const newContent = opt.getAttribute('data-content') || '';
            textarea.value = newContent;
            autoResizeHeight();
            textarea.focus();
            
            // Update last saved value
            lastSavedValue = newContent;
        });
    }

    // Save: update selected template or create new if none selected
    if (saveBtn) {
        saveBtn.addEventListener('click', function() {
            if (isLocked) return;
            
            const id = selector.value;
            const content = (textarea.value || '').trim();
            if (!content) {
                showFlash('error', 'Rationale content cannot be empty.');
                return;
            }

            let name;
            if (id && id !== '0') {
                // update existing: use current option text as name
                name = selector.options[selector.selectedIndex].text.trim() || 'Updated Template';
            } else {
                name = prompt('Enter a name for this new rationale template:');
                if (!name || !name.trim()) {
                    showFlash('error', 'Template name is required.');
                    return;
                }
            }

            setButtonsDisabled(true);
            const body = new URLSearchParams();
            body.append('ajax_action', id && id !== '0' ? 'edit_template' : 'save_template');
            body.append('template_name', name.trim());
            body.append('template_content', content);
            body.append('section_type', 'rationale');

            if (id && id !== '0') {
                body.append('template_id', id);
            }

            fetch('rationale.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
                body: body
            })
            .then(r => r.json())
            .then(data => {
                if (data && data.success) {
                    const templateId = data.new_id || id;
                    if (templateId) {
                        const opt = upsertTemplateOption(templateId, name.trim(), content);
                        if (opt) selector.value = String(templateId);
                    }
                    showFlash('success', 'Template saved successfully.');
                } else {
                    showFlash('error', 'Save failed: ' + (data.error || 'Unknown'));
                }
            })
            .catch(() => showFlash('error', 'Network error while saving template.'))
            .finally(() => setButtonsDisabled(false));
        });
    }

    // Delete: delete selected template
    if (delBtn) {
        delBtn.addEventListener('click', function() {
            if (isLocked) return;
            
            const id = selector.value;
            if (!id || id === '0') {
                showFlash('error', 'Please select a template to delete.');
                return;
            }
            if (!confirm('Delete selected template? This cannot be undone.')) return;

            setButtonsDisabled(true);
            const body = new URLSearchParams();
            body.append('ajax_action', 'delete_template');
            body.append('template_id', id);

            fetch('rationale.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
                body: body
            })
            .then(r => r.json())
            .then(data => {
                if (data && data.success) {
                    removeTemplateOption(id);
                    selector.value = '0';
                    showFlash('success', 'Template deleted successfully.');
                } else {
                    showFlash('error', 'Delete failed: ' + (data.error || 'Unknown'));
                }
            })
            .catch(() => showFlash('error', 'Network error while deleting template.'))
            .finally(() => setButtonsDisabled(false));
        });
    }

    // Auto-save on interval (optional feature)
    if (!isLocked && clientId) {
        const autosaveInterval = 15000; // 15 seconds
        let lastContent = textarea.value;
        let autosaveTimer = null;

        function doAutosave() {
            const content = textarea.value;
            if (content !== lastContent) {
                lastContent = content;
                
                fetch('rationale.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
                    body: new URLSearchParams({
                        ajax_action: 'autosave_rationale',
                        client_id: clientId,
                        value: content
                    })
                })
                .then(r => r.json())
                .then(data => {
                    if (data && data.success) {
                        console.log('Auto-saved rationale');
                    }
                })
                .catch(err => console.error('Auto-save error:', err));
            }
        }

        // Auto-save on text changes (debounced)
        textarea.addEventListener('input', function() {
            if (autosaveTimer) {
                clearTimeout(autosaveTimer);
            }
            autosaveTimer = setTimeout(doAutosave, autosaveInterval);
        });

        // Auto-save when leaving the page
        window.addEventListener('beforeunload', function() {
            if (textarea.value !== lastContent) {
                navigator.sendBeacon('rationale.php', 
                    new URLSearchParams({
                        ajax_action: 'autosave_rationale',
                        client_id: clientId,
                        value: textarea.value
                    })
                );
            }
        });
    }
})();
</script>