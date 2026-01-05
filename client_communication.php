<?php
// client_communication.php
// Complete standalone template management for greeting, intro, and closing sections

// Handle AJAX requests for template management
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ajax_action'])) {
    ob_start();
    header('Content-Type: application/json');
    
    $action = $_POST['ajax_action'] ?? '';
    $response = ['success' => false];
    
    try {
        require_once 'db_config.php';
        $pdo = getPdo();
        
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
            // Delete the template
            $stmt = $pdo->prepare("DELETE FROM report_templates WHERE id = ?");
            $stmt->execute([(int)$_POST['template_id']]);
            
            // Generate updated dropdown HTML for ONLY the affected section
            $section = $_POST['section_type'];
            $stmtList = $pdo->prepare("SELECT id, name, content FROM report_templates WHERE section_type = ? ORDER BY name ASC");
            $stmtList->execute([$section]);
            $rows = $stmtList->fetchAll(PDO::FETCH_ASSOC);
            
            $html = '<option value="0">-- Select --</option>';
            foreach($rows as $r) {
                $html .= sprintf(
                    '<option value="%d" data-content="%s">%s</option>',
                    (int)$r['id'],
                    htmlspecialchars($r['content'] ?? ''),
                    htmlspecialchars($r['name'] ?? 'Untitled')
                );
            }
            $response['html_update'] = $html;
            $response['success'] = true;
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

            // Generate updated dropdown HTML for ONLY the affected section
            $stmtList = $pdo->prepare("SELECT id, name, content FROM report_templates WHERE section_type = ? ORDER BY name ASC");
            $stmtList->execute([$section]);
            $rows = $stmtList->fetchAll(PDO::FETCH_ASSOC);
            
            $html = '<option value="0">-- Select --</option>';
            foreach($rows as $r) {
                $html .= sprintf(
                    '<option value="%d" data-content="%s">%s</option>',
                    (int)$r['id'],
                    htmlspecialchars($r['content'] ?? ''),
                    htmlspecialchars($r['name'] ?? 'Untitled')
                );
            }
            $response['html_update'] = $html;
            $response['success'] = true;
            $response['new_id'] = $newId;
        } else {
            throw new Exception('Invalid action');
        }
        
        ob_end_clean();
        echo json_encode($response);
        exit;
    } catch (Exception $e) {
        ob_end_clean();
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        exit;
    }
}

// Continue with normal page rendering if not an AJAX request
?>

<style>
/* ===============================
   CARD & HEADER
================================ */
.editor-card {
    background: linear-gradient(120deg,#f8fbff,#e9f0fa);
    border-radius: 18px;
    border: 1px solid #ffffffff;
    box-shadow: 0 10px 40px rgba(0,60,180,.12);
    margin-bottom: 32px;
}
.editor-header {
    background:  #0ba1e1bb;
    padding: 22px 32px;
    border-radius: 18px 18px 0 0;
}
.editor-title {
    margin: 0;
    font-size: 24px;
    font-weight: 800;
    color: #fff;
}

/* ===============================
   GRID LAYOUT (CRITICAL)
================================ */
.comm-row {
    display: grid;
    grid-template-columns: 180px 1fr 44px;
    gap: 18px;
    margin: 26px 32px;
    align-items: flex-start;
}

/* ===============================
   DROPDOWN
================================ */
.comm-dropdown {
    width: 100%;
    padding: 10px 14px;
    border-radius: 10px;
    border: 1.5px solid #c9d6f0;
    background: #e4e7eaff;
    font-size: 14px;
    cursor: pointer;
}

/* ===============================
   TEXTAREA
================================ */
.comm-textarea {
    width: 90%;
    resize: none;
    overflow: hidden;
    padding: 18px 22px;
    border-radius: 14px;
    border: 1px solid #dbe3f3;
    font-size: 15px;
    line-height: 1.7;
    background: #ffffffff;
    min-height: 64px;
    box-shadow:
        0 1px 2px rgba(0,0,0,0.04),
        0 6px 20px rgba(0,80,200,0.06);
}
.comm-textarea[readonly] {
    background: linear-gradient(180deg,#f7f9fd,#eef3fb);
    color: #374151;
    cursor: default;
}
.comm-textarea:not([readonly]) {
    background: #ffffff;
    border-color: #007bff;
    box-shadow: 0 0 0 2px rgba(0,123,255,0.1);
}

/* ===============================
   INLINE ACTIONS
================================ */
.inline-edit-actions {
    display: none;
    margin-top: 12px;
    gap: 10px;
}
.inline-edit-actions button {
    padding: 8px 18px;
    border-radius: 8px;
    border: none;
    cursor: pointer;
    font-weight: 600;
    transition: all 0.2s;
}
.inline-edit-actions .save-btn {
    background: linear-gradient(135deg,#007bff,#0056b3);
    color: #fff;
}
.inline-edit-actions .save-btn:hover {
    background: linear-gradient(135deg,#0069d9,#004085);
}
.inline-edit-actions .cancel-btn {
    background: #eef3fb;
    color: #0056b3;
    border: 1px solid #c9d6f0;
}
.inline-edit-actions .cancel-btn:hover {
    background: #e2e9f5;
}

/* ===============================
   3 DOTS MENU (FIXED)
================================ */
.comm-dots-menu-wrapper {
    display: flex;
    justify-content: center;
    padding-top: 6px;
    position: relative;
    z-index: 100;
}
.comm-dots-btn {
    width: 36px;
    height: 36px;
    border-radius: 50%;
    border: none;
    background: #f1f4fa;
    font-size: 20px;
    cursor: pointer;
    color: #4b5c7a;
    transition: all 0.2s;
    position: relative;
    z-index: 1001;
}
.comm-dots-btn:hover {
    background: #e4e9f6;
}
.comm-dots-dropdown {
    display: none;
    position: absolute;
    top: 44px;
    right: 0;
    background: #fff;
    border-radius: 12px;
    min-width: 180px;
    list-style: none;
    padding: 6px 0;
    box-shadow:
        0 8px 32px rgba(0,0,0,0.12),
        0 2px 6px rgba(0,0,0,0.06);
    border: 1px solid #e3e8f4;
    z-index: 1000;
}
.comm-dots-dropdown.show { 
    display: block;
    animation: fadeIn 0.2s ease;
}
@keyframes fadeIn {
    from { opacity: 0; transform: translateY(-10px); }
    to { opacity: 1; transform: translateY(0); }
}
.comm-dots-dropdown li {
    padding: 12px 16px;
    font-size: 14px;
    cursor: pointer;
    transition: background 0.2s;
    color: #374151;
}
.comm-dots-dropdown li:hover {
    background: #f3f6fc;
}

/* ===============================
   STATUS MESSAGE
================================ */
.comm-status-msg {
    color: #10b981;
    font-size: 13px;
    margin-top: 6px;
    display: none;
    font-weight: 500;
}
.comm-status-msg.error {
    color: #ef4444;
}

/* ===============================
   MODAL STYLES
================================ */
#saveTemplateModal {
    display: none;
    position: fixed;
    inset: 0;
    background: rgba(0,0,0,.45);
    align-items: center;
    justify-content: center;
    z-index: 10000;
}
.modal-content {
    background: #fff;
    padding: 28px 32px;
    border-radius: 16px;
    width: 400px;
    box-shadow: 0 20px 60px rgba(0,0,0,0.2);
}
.modal-content h3 {
    margin: 0 0 20px 0;
    color: #1f2937;
    font-size: 20px;
    font-weight: 600;
}
.modal-input {
    width: 100%;
    padding: 12px 16px;
    margin-bottom: 20px;
    border-radius: 10px;
    border: 1.5px solid #c9d6f0;
    font-size: 14px;
    box-sizing: border-box;
}
.modal-input:focus {
    outline: none;
    border-color: #007bff;
    box-shadow: 0 0 0 3px rgba(0,123,255,0.1);
}
.modal-actions {
    text-align: right;
    display: flex;
    gap: 12px;
    justify-content: flex-end;
}
.modal-actions button {
    padding: 10px 22px;
    border-radius: 8px;
    border: none;
    cursor: pointer;
    font-weight: 600;
    font-size: 14px;
    transition: all 0.2s;
}
.modal-actions .cancel-btn {
    background: #f3f4f6;
    color: #374151;
}
.modal-actions .cancel-btn:hover {
    background: #e5e7eb;
}
.modal-actions .save-btn {
    background: linear-gradient(135deg,#007bff,#0056b3);
    color: #fff;
}
.modal-actions .save-btn:hover {
    background: linear-gradient(135deg,#0069d9,#004085);
}

/* SEARCHABLE SELECT */
.searchable-select {
    position: relative;
    width: 100%;
    margin-bottom: 8px; /* Add space below search box */
    min-width: 180px;
    max-width: 320px;
}

.searchable-input {
    width: 100%;
    padding: 10px 38px 10px 36px;
    border-radius: 10px;
    border: 1.5px solid #c9d6f0;
    font-size: 14px;
    cursor: pointer;
    background: #fafdff;
    box-sizing: border-box;
}

.searchable-input:focus {
    outline: none;
    border-color: #007bff;
}

.search-icon {
    position: absolute;
    left: 12px;
    top: 50%;
    transform: translateY(-50%);
    font-size: 14px;
    opacity: 0.6;
    pointer-events: none;
}

.searchable-options {
    display: none;
    position: absolute;
    z-index: 999;
    width: 100%;
    background: #fff;
    border-radius: 10px;
    border: 1px solid #dbe3f3;
    margin-top: 6px;
    max-height: 220px;
    overflow-y: auto;
    box-shadow: 0 10px 30px rgba(0,0,0,0.15);
}

.searchable-options li {
    padding: 10px 14px;
    cursor: pointer;
    font-size: 14px;
}

.searchable-options li:hover {
    background: #f3f6fc;
}

.hidden-select {
    display: none;
}

/* Make comm-row layout more responsive and prevent overlap */
.comm-row {
    display: flex;
    flex-wrap: wrap;
    gap: 18px;
    margin: 26px 32px;
    align-items: flex-start;
}

.comm-row > .searchable-select {
    flex: 0 0 220px;
    min-width: 180px;
    max-width: 320px;
}

.comm-row > div:not(.searchable-select):not(.comm-dots-menu-wrapper) {
    flex: 1 1 320px;
    min-width: 220px;
    max-width: 600px;
}

.comm-row > .comm-dots-menu-wrapper {
    flex: 0 0 44px;
    min-width: 44px;
    max-width: 44px;
    display: flex;
    align-items: flex-start;
    justify-content: flex-end;
}

/* Ensure textarea fills available width and doesn't overlap */
.comm-textarea {
    width: 100%;
    min-width: 180px;
    max-width: 100%;
    box-sizing: border-box;
    margin-top: 0;
    margin-bottom: 0;
}

/* Responsive: stack on small screens */
@media (max-width: 700px) {
    .comm-row {
        flex-direction: column;
        gap: 10px;
        margin: 18px 8px;
    }
    .comm-row > .searchable-select,
    .comm-row > div:not(.searchable-select):not(.comm-dots-menu-wrapper),
    .comm-row > .comm-dots-menu-wrapper {
        min-width: 0;
        max-width: 100%;
        flex: 1 1 100%;
    }
    .comm-dots-menu-wrapper {
        justify-content: flex-start;
    }
}
</style>

<div class="editor-card">
    <div class="editor-header">
        <h3 class="editor-title">Client Communication</h3>
    </div>

<?php
$sections = [
    'greeting' => $greetingStored ?? '',
    'intro'    => $introTextStored ?? '',
    'closing'  => $closingTextStored ?? ''
];
foreach ($sections as $sec => $val):
?>
<div class="comm-row" id="<?= $sec ?>_row">
    <div class="searchable-select" data-section="<?= $sec ?>">
        <span class="search-icon">🔍</span>
        <input type="text"
               class="searchable-input"
               placeholder="Select template..."
               readonly>
        <select id="<?= $sec ?>_template_selector"
                class="comm-dropdown hidden-select"
                onchange="handleTemplateChange('<?= $sec ?>')">
            <option value="0">-- Select --</option>
            <?php foreach ($templates[$sec] as $t): ?>
                <option value="<?= (int)$t['id'] ?>"
                        data-content="<?= htmlspecialchars($t['content'] ?? '') ?>">
                    <?= htmlspecialchars($t['name'] ?? 'Untitled') ?>
                </option>
            <?php endforeach; ?>
        </select>
        <ul class="searchable-options"></ul>
    </div>
    <div>
        <textarea id="<?= $sec ?>_input"
            class="comm-textarea"
            readonly
            oninput="forceFullHeight(this)"><?= htmlspecialchars($val) ?></textarea>
        <div class="inline-edit-actions" id="<?= $sec ?>_edit_actions">
            <button class="save-btn" onclick="saveInlineEdit('<?= $sec ?>')">Save</button>
            <button class="cancel-btn" onclick="cancelInlineEdit('<?= $sec ?>')">Cancel</button>
        </div>
        <div class="comm-status-msg" id="<?= $sec ?>_status_msg"></div>
    </div>
    <div class="comm-dots-menu-wrapper">
        <button class="comm-dots-btn"
                onclick="toggleDots('<?= $sec ?>', event)">⋮</button>
        <ul class="comm-dots-dropdown" id="<?= $sec ?>_dots_dropdown">
            <li onclick="openSaveModal('<?= $sec ?>')">Save as New</li>
            <li onclick="editTemplate('<?= $sec ?>')">Edit</li>
            <li onclick="deleteSelectedTemplate('<?= $sec ?>')">Delete</li>
        </ul>
    </div>
</div>
<?php endforeach; ?>
</div>
<!-- SAVE MODAL -->
<div id="saveTemplateModal">
    <div class="modal-content">
        <h3>Save Template</h3>
        <input id="new_template_name" class="modal-input"
               placeholder="Enter template name">
        <input type="hidden" id="current_section">
        <div class="modal-actions">
            <button class="cancel-btn" onclick="closeModal()">Cancel</button>
            <button class="save-btn" onclick="submitNewTemplate()">Save Template</button>
        </div>
    </div>
</div>

<script>
// Store original values for cancel operation
const originalValues = {};
let activeDropdown = null;

/* Universal Auto-scaling */
function forceFullHeight(el) {
    if (!el) return;
    el.style.height = 'auto';
    el.style.height = el.scrollHeight + 'px';
}

// Initialize textarea heights on load
document.addEventListener('DOMContentLoaded', function() {
    ['greeting', 'intro', 'closing'].forEach(sec => {
        const ta = document.getElementById(sec + '_input');
        if (ta) forceFullHeight(ta);
    });
});

function handleTemplateChange(sec) {
    const sel = document.getElementById(sec + '_template_selector');
    const ta = document.getElementById(sec + '_input');
    const opt = sel.options[sel.selectedIndex];
    
    ta.value = (sel.value !== '0') ? opt.dataset.content : '';
    ta.setAttribute('readonly', 'readonly');
    document.getElementById(sec + '_edit_actions').style.display = 'none';
    forceFullHeight(ta);
    
    // Hide any existing status message when changing template
    document.getElementById(sec + '_status_msg').style.display = 'none';
    
    // Close any open dropdown
    closeAllDropdowns();
}

/* AJAX Action Handler - No Page Refresh */
function performAjax(sec, actionData, successText) {
    const statusMsg = document.getElementById(sec + '_status_msg');
    
    // Send to THIS SAME FILE (client_communication.php)
    fetch('client_communication.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: new URLSearchParams(actionData)
    })
    .then(r => {
        if (!r.ok) {
            throw new Error('Network response was not ok: ' + r.status);
        }
        return r.json();
    })
    .then(data => {
        if (!data.success) {
            throw new Error(data.error || 'Operation failed');
        }

        // Show inline text message
        statusMsg.textContent = successText;
        statusMsg.className = 'comm-status-msg';
        statusMsg.style.display = 'block';

        // Reset UI state for that section only
        const ta = document.getElementById(sec + '_input');
        ta.setAttribute('readonly', 'readonly');
        document.getElementById(sec + '_edit_actions').style.display = 'none';
        forceFullHeight(ta);

        // Update the dropdown list only if html_update is provided
        if (data.html_update) {
            const sel = document.getElementById(sec + '_template_selector');
            const currentVal = sel.value;
            const currentContent = ta.value;
            sel.innerHTML = data.html_update;
            
            // Try to restore the same selection if it still exists
            if (currentVal !== '0') {
                for (let i = 0; i < sel.options.length; i++) {
                    if (sel.options[i].value === currentVal) {
                        sel.selectedIndex = i;
                        break;
                    }
                }
            } else if (data.new_id) {
                // If this was a save operation, select the new template
                sel.value = data.new_id;
                ta.value = currentContent; // Keep the current content
            }
        }

        // Auto-hide message after 3 seconds
        setTimeout(() => {
            statusMsg.style.display = 'none';
        }, 3000);
        
        // Close modal if open
        closeModal();
        closeAllDropdowns();
    })
    .catch(err => {
        console.error('AJAX Error:', err);
        statusMsg.textContent = 'Error: ' + err.message;
        statusMsg.className = 'comm-status-msg error';
        statusMsg.style.display = 'block';
        
        setTimeout(() => {
            statusMsg.style.display = 'none';
        }, 3000);
    });
}

function saveInlineEdit(sec) {
    const sel = document.getElementById(sec + '_template_selector');
    const ta = document.getElementById(sec + '_input');
    
    if (sel.value === '0') {
        alert('Please select a template to edit first');
        return;
    }
    
    performAjax(sec, {
        ajax_action: 'edit_template',
        template_id: sel.value,
        template_name: sel.options[sel.selectedIndex].text,
        template_content: ta.value
    }, "✓ Template updated successfully");
}

function deleteSelectedTemplate(sec) {
    const sel = document.getElementById(sec + '_template_selector');
    if (sel.value === '0') {
        alert('Please select a template to delete');
        return;
    }
    
    const templateName = sel.options[sel.selectedIndex].text;
    if (!confirm(`Are you sure you want to delete "${templateName}"?`)) return;

    performAjax(sec, {
        ajax_action: 'delete_template',
        template_id: sel.value,
        section_type: sec
    }, "✓ Template deleted");
}

/* --- 3-dots menu logic --- */
function toggleDots(sec, event) {
    event.stopPropagation();
    event.preventDefault();
    
    const menu = document.getElementById(sec + '_dots_dropdown');
    const isOpen = menu.classList.contains('show');
    
    // Close all other dropdowns first
    closeAllDropdowns();
    
    // Toggle current menu
    if (!isOpen) {
        menu.classList.add('show');
        activeDropdown = menu;
    }
}

function closeAllDropdowns() {
    document.querySelectorAll('.comm-dots-dropdown.show').forEach(d => {
        d.classList.remove('show');
    });
    activeDropdown = null;
}

// Close dropdowns when clicking outside
document.addEventListener('click', function(e) {
    if (!e.target.closest('.comm-dots-menu-wrapper')) {
        closeAllDropdowns();
    }
});

// Prevent closing when clicking inside dropdown
document.querySelectorAll('.comm-dots-dropdown').forEach(dropdown => {
    dropdown.addEventListener('click', function(e) {
        e.stopPropagation();
    });
});

/* --- Edit/Cancel logic --- */
function editTemplate(sec) {
    const sel = document.getElementById(sec + '_template_selector');
    if (sel.value === '0') {
        alert('Please select a template to edit first');
        return;
    }
    
    const ta = document.getElementById(sec + '_input');
    originalValues[sec] = ta.value; // Store original value
    ta.removeAttribute('readonly');
    ta.focus();
    document.getElementById(sec + '_edit_actions').style.display = 'flex';
    
    // Close dropdown
    closeAllDropdowns();
}

function cancelInlineEdit(sec) {
    const ta = document.getElementById(sec + '_input');
    ta.value = originalValues[sec] || '';
    ta.setAttribute('readonly', 'readonly');
    document.getElementById(sec + '_edit_actions').style.display = 'none';
    forceFullHeight(ta);
    delete originalValues[sec];
}

/* --- Modal logic --- */
function openSaveModal(sec) {
    const ta = document.getElementById(sec + '_input');
    if (!ta.value.trim()) {
        alert('Please enter some content before saving as a template');
        return;
    }
    
    document.getElementById('current_section').value = sec;
    document.getElementById('new_template_name').value = '';
    document.getElementById('saveTemplateModal').style.display = 'flex';
    document.getElementById('new_template_name').focus();
    
    // Close dropdown
    closeAllDropdowns();
}

function closeModal() {
    document.getElementById('saveTemplateModal').style.display = 'none';
}

function submitNewTemplate() {
    const sec = document.getElementById('current_section').value;
    const nameInput = document.getElementById('new_template_name');
    const templateName = nameInput.value.trim();
    
    if (!templateName) {
        alert('Please enter a template name');
        nameInput.focus();
        return;
    }
    
    performAjax(sec, {
        ajax_action: 'save_template',
        section_type: sec,
        template_name: templateName,
        template_content: document.getElementById(sec + '_input').value
    }, "✓ Template saved successfully");
}

// Searchable select logic
document.querySelectorAll('.searchable-select').forEach(wrapper => {
    const input = wrapper.querySelector('.searchable-input');
    const select = wrapper.querySelector('select');
    const list = wrapper.querySelector('.searchable-options');

    function buildList(filter = '') {
        list.innerHTML = '';
        [...select.options].forEach(opt => {
            if (opt.value === '0') return;
            if (!opt.text.toLowerCase().includes(filter.toLowerCase())) return;

            const li = document.createElement('li');
            li.textContent = opt.text;
            li.dataset.value = opt.value;
            li.onclick = () => {
                select.value = opt.value;
                input.value = opt.text;
                list.style.display = 'none';
                select.dispatchEvent(new Event('change'));
            };
            list.appendChild(li);
        });
    }

    input.addEventListener('click', () => {
        input.removeAttribute('readonly');
        input.value = '';
        buildList();
        list.style.display = 'block';
    });

    input.addEventListener('input', () => {
        buildList(input.value);
        list.style.display = 'block';
    });

    document.addEventListener('click', e => {
        if (!wrapper.contains(e.target)) {
            list.style.display = 'none';
            input.setAttribute('readonly', true);

            const selected = select.options[select.selectedIndex];
            input.value = selected ? selected.text : '';
        }
    });

    // Initial value sync
    const selected = select.options[select.selectedIndex];
    if (selected && selected.value !== '0') {
        input.value = selected.text;
    }
});
</script>