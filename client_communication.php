<?php
// client_communication.php
// Complete standalone template management for greeting, intro, and closing sections
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
        // Workflow actions
        elseif ($action === 'save_draft') {
            $clientId = (int)($_POST['client_id'] ?? 0);
            if ($clientId <= 0) throw new Exception("Invalid Client ID.");

            $stmt = $pdo->prepare("UPDATE clients SET report_state = 'draft', draft_at = NOW(), review_not_ok = 0, review_comment = NULL WHERE id = :id");
            $stmt->execute([':id' => $clientId]);
            echo json_encode(['success' => true, 'message' => 'Draft saved successfully.', 'updated_state' => 'draft']);
            exit;
        }
        elseif ($action === 'ready_for_review') {
            $clientId = (int)($_POST['client_id'] ?? 0);
            if ($clientId <= 0) throw new Exception("Invalid Client ID.");

            $stmt = $pdo->prepare("UPDATE clients SET report_state = 'ready', ready_at = NOW(), review_not_ok = 0, review_comment = NULL WHERE id = :id");
            $stmt->execute([':id' => $clientId]);
            echo json_encode(['success' => true, 'message' => 'Report marked Ready for Review.', 'updated_state' => 'ready']);
            exit;
        }
        elseif ($action === 'approve_review') {
            $clientId = (int)($_POST['client_id'] ?? 0);
            if ($clientId <= 0) throw new Exception("Invalid Client ID.");

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

            $stmt = $pdo->prepare("UPDATE clients SET report_state = 'draft', review_not_ok = 1, review_comment = :comment WHERE id = :id");
            $stmt->execute([':id' => $clientId, ':comment' => $comment]);
            echo json_encode(['success' => true, 'message' => 'Report rejected and moved back to Draft.', 'updated_state' => 'draft']);
            exit;
        }
        elseif ($action === 'email_sent') {
            $clientId = (int)($_POST['client_id'] ?? 0);
            if ($clientId <= 0) throw new Exception("Invalid Client ID.");
            
            $stmt = $pdo->prepare("UPDATE clients SET report_state = 'sent', sent_at = NOW() WHERE id = :id");
            $stmt->execute([':id' => $clientId]);
            echo json_encode(['success' => true, 'message' => 'Report marked as Sent.', 'updated_state' => 'sent']);
            exit;
        }
        // Auto-save client field (from view_report.php blur event)
        elseif ($action === 'save_client_field') {
            $clientId = (int)($_POST['client_id'] ?? 0);
            $field = trim($_POST['field'] ?? '');
            $value = trim($_POST['value'] ?? '');
            
            if ($clientId <= 0) throw new Exception("Invalid Client ID.");
            if (empty($field)) throw new Exception("Field name is required.");
            
            // Validate field name to prevent SQL injection
            $allowedFields = ['greeting_prefix', 'intro_text', 'closing_text'];
            if (!in_array($field, $allowedFields)) {
                throw new Exception("Invalid field name.");
            }
            
            $stmt = $pdo->prepare("UPDATE clients SET $field = ? WHERE id = ?");
            $stmt->execute([$value, $clientId]);
            echo json_encode(['success' => true, 'message' => ucfirst(str_replace('_', ' ', $field)) . ' saved successfully.']);
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
?>

<style>
/* Client Communication module styles - matching rationale.php blue theme */
.comm-section {
    margin-top: 18px;
    margin-bottom: 18px;
    padding: 14px;
    border: 1px solid #e6f2fb;
    border-radius: 8px;
    background: linear-gradient(180deg, #fbfdff 0%, #f6fbff 100%);
    box-shadow: 0 1px 0 rgba(2,136,209,0.03);
    font-family: Inter, Arial, sans-serif;
}

.comm-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 12px;
    padding-bottom: 8px;
    border-bottom: 1px solid #e6f2fb;
}

.comm-title {
    font-weight: 700;
    color: #083744;
    font-size: 16px;
    margin: 0;
}

.comm-controls {
    display: flex;
    gap: 10px;
    align-items: center;
    margin-bottom: 10px;
    flex-wrap: wrap;
}

.comm-select {
    min-width: 300px;
    padding: 8px 10px;
    border: 1px solid #dbeefb;
    border-radius: 6px;
    background: #fff;
    color: #083744;
    font-size: 14px;
    box-shadow: inset 0 1px 0 rgba(2,136,209,0.02);
}

.comm-btn {
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
.comm-btn.save {
    background: #0288D1; /* primary */
    color: #fff;
}
.comm-btn.save:hover { background: #2eb85c !important; transform: translateY(-1px); }

.comm-btn.edit {
    background: #039be5; /* lighter blue */
    color: #fff;
}
.comm-btn.edit:hover { background: #0288d1; transform: translateY(-1px); }

/* Delete: base blue, hover becomes red */
.comm-btn.del {
    background: #0277bd; /* darker blue */
    color: #fff;
}
.comm-btn.del:hover { background: #dc3545 !important; transform: translateY(-1px); }

/* Add button (plus) specific styling */
.comm-btn.add {
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
.comm-btn[disabled] {
    opacity: 0.65;
    cursor: not-allowed;
    transform: none !important;
}

/* Focus / keyboard accessibility */
.comm-btn:focus,
.comm-select:focus,
.comm-textarea:focus {
    outline: 3px solid rgba(2,136,209,0.12);
    outline-offset: 2px;
}

/* Textarea with auto-grow */
.comm-textarea {
    width: 100%;
    padding: 12px;
    font-size: 14px;
    min-height: 100px;
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

/* Flash messages area */
.comm-flash { 
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
    .comm-controls { 
        flex-direction: column; 
        align-items: stretch; 
    }
    .comm-select { 
        width: 100%; 
    }
    .comm-btn:not(.add) { 
        width: 100%; 
        text-align: center; 
    }
}

/* SVG plus icon */
.comm-btn.add svg {
    width: 16px;
    height: 16px;
    stroke: currentColor;
    stroke-width: 2;
    stroke-linecap: round;
    stroke-linejoin: round;
}
</style>

<?php
// Define $clientId and $isLocked if not already defined
$clientId = $clientId ?? 0;
$isLocked = $isLocked ?? false;

$sections = [
    'greeting' => [
        'title' => 'Greeting',
        'stored' => $greetingStored ?? '',
        'db_field' => 'greeting_prefix'
    ],
    'intro' => [
        'title' => 'Introduction',
        'stored' => $introTextStored ?? '',
        'db_field' => 'intro_text'
    ],
    'closing' => [
        'title' => 'Closing',
        'stored' => $closingTextStored ?? '',
        'db_field' => 'closing_text'
    ]
];

foreach ($sections as $sec => $data):
    $templates_for_section = $templates[$sec] ?? [];
?>
<div class="comm-section" id="<?= $sec ?>_section">
    <div class="comm-header">
        <div class="comm-title"><?= htmlspecialchars($data['title']) ?>
            <?php if (isset($isLocked) && $isLocked): ?>
                <span title="Locked" style="margin-left:8px;color:#888;vertical-align:middle;">🔒</span>
            <?php endif; ?>
        </div>
    </div>
    <div class="comm-controls">
        <select id="<?= $sec ?>_template_selector" class="comm-select" <?= $isLocked ? 'disabled' : '' ?>>
            <option value="0">-- Select saved <?= strtolower($data['title']) ?> template --</option>
            <?php if (!empty($templates_for_section)): ?>
                <?php foreach ($templates_for_section as $t): 
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
        <button id="<?= $sec ?>_add_btn" class="comm-btn add" type="button" title="Add new template" aria-label="Add new template" <?= $isLocked ? 'disabled' : '' ?>>
            <svg viewBox="0 0 24 24" fill="none" aria-hidden="true" focusable="false">
                <path d="M12 5v14M5 12h14" stroke="currentColor"/>
            </svg>
        </button>
        <button id="<?= $sec ?>_save_btn" class="comm-btn save" type="button" <?= $isLocked ? 'disabled' : '' ?>>Save</button>
        <button id="<?= $sec ?>_edit_btn" class="comm-btn edit" type="button" <?= $isLocked ? 'disabled' : '' ?>>Edit</button>
        <button id="<?= $sec ?>_delete_btn" class="comm-btn del" type="button" <?= $isLocked ? 'disabled' : '' ?>>Delete</button>
    </div>
    <!-- CRITICAL: Add name attribute to match view_report.php POST fields and data attributes for auto-save -->
    <textarea id="<?= $sec ?>_textarea"
        name="<?= $sec ?>"
        data-client-id="<?= (int)$clientId ?>"
        data-field="<?= $data['db_field'] ?>"
        class="comm-textarea large-textarea"
        <?= $isLocked ? 'readonly' : '' ?>><?= htmlspecialchars($data['stored']) ?></textarea>
    <div id="<?= $sec ?>_flash_container" class="comm-flash"></div>
</div>
<script>
(function(){
    const section = '<?= $sec ?>';
    const selector = document.getElementById(section + '_template_selector');
    const textarea = document.getElementById(section + '_textarea');
    const saveBtn = document.getElementById(section + '_save_btn');
    const editBtn = document.getElementById(section + '_edit_btn');
    const delBtn = document.getElementById(section + '_delete_btn');
    const addBtn = document.getElementById(section + '_add_btn');
    const flash = document.getElementById(section + '_flash_container');
    const isLocked = <?= $isLocked ? 'true' : 'false' ?>;

    // --- Auto-grow logic (must be inside IIFE) ---
    function autoGrow(el) {
        if (!el) return;
        el.style.height = 'auto';
        el.style.height = el.scrollHeight + 'px';
    }
    // Initial resize
    autoGrow(textarea);
    // Resize on typing
    textarea.addEventListener('input', () => autoGrow(textarea));
    // Resize on paste
    textarea.addEventListener('paste', () => {
        setTimeout(() => autoGrow(textarea), 0);
    });

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

    // Auto-save on blur (using client_communication.php endpoint)
    textarea.addEventListener('blur', function() {
        if (isLocked) return;
        
        const clientId = textarea.getAttribute('data-client-id');
        const field = textarea.getAttribute('data-field');
        const value = textarea.value.trim();

        if (clientId && field) {
            fetch('client_communication.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8'
                },
                body: new URLSearchParams({
                    ajax_action: 'save_client_field',
                    client_id: clientId,
                    field: field,
                    value: value
                })
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    // Show flash message
                    showFlash('success', data.message || 'Saved');
                } else {
                    showFlash('error', 'Save failed: ' + (data.error || 'Unknown error'));
                }
            })
            .catch(err => {
                console.error('Save error:', err);
                showFlash('error', 'Network error while saving');
            });
        }
    });

    // Auto-load when selection changes
    if (selector) {
        selector.addEventListener('change', function() {
            const id = selector.value;
            if (id && id !== '0') {
                const opt = selector.options[selector.selectedIndex];
                textarea.value = opt.getAttribute('data-content') || '';
                autoGrow(textarea);
                showFlash('success', 'Template loaded into editor.');
                
                // Auto-save the loaded template content
                if (!isLocked) {
                    textarea.dispatchEvent(new Event('blur'));
                }
            }
        });
    }

    // Add: create new template from current textarea content
    if (addBtn) {
        addBtn.addEventListener('click', function() {
            if (isLocked) return;
            
            const content = (textarea.value || '').trim();
            if (!content) {
                showFlash('error', '<?= ucfirst($data['title']) ?> content cannot be empty.');
                return;
            }
            const name = prompt('Enter a name for this new <?= strtolower($data['title']) ?> template:');
            if (!name || !name.trim()) return;

            setButtonsDisabled(true);
            const body = new URLSearchParams();
            body.append('ajax_action', 'save_template');
            body.append('template_name', name.trim());
            body.append('template_content', content);
            body.append('section_type', section);

            fetch('client_communication.php', {
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
            textarea.value = opt.getAttribute('data-content') || '';
            autoGrow(textarea);
            textarea.focus();
        });
    }

    // Save: update selected template or create new if none selected
    if (saveBtn) {
        saveBtn.addEventListener('click', function() {
            if (isLocked) return;
            
            const id = selector.value;
            const content = (textarea.value || '').trim();
            if (!content) {
                showFlash('error', '<?= ucfirst($data['title']) ?> content cannot be empty.');
                return;
            }

            let name;
            if (id && id !== '0') {
                // update existing: use current option text as name
                name = selector.options[selector.selectedIndex].text.trim() || 'Updated Template';
            } else {
                name = prompt('Enter a name for this new <?= strtolower($data['title']) ?> template:');
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
            body.append('section_type', section);

            if (id && id !== '0') {
                body.append('template_id', id);
            }

            fetch('client_communication.php', {
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

            fetch('client_communication.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
                body: body
            })
            .then(r => r.json())
            .then data => {
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
})();
</script>
<?php endforeach; ?>