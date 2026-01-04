<?php
// client_communication.php
?>

<style>
/* ===============================
   CARD & HEADER
================================ */
.editor-card {
    background: linear-gradient(120deg,#f8fbff,#e9f0fa);
    border-radius: 18px;
    border: 1px solid #e3eafc;
    box-shadow: 0 10px 40px rgba(0,60,180,.12);
    margin-bottom: 32px;
}
.editor-header {
    background: linear-gradient(135deg,#007bff,#0056b3);
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
    background: #fafdff;
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
    background: #ffffff;
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
    <select id="<?= $sec ?>_template_selector" class="comm-dropdown" onchange="handleTemplateChange('<?= $sec ?>')">
        <option value="0">-- Select --</option>
        <?php foreach ($templates[$sec] as $t): ?>
            <option value="<?= (int)$t['id'] ?>"
                data-content="<?= htmlspecialchars($t['content'] ?? '') ?>">
                <?= htmlspecialchars($t['name'] ?? $t['template_name'] ?? 'Untitled') ?>
            </option>
        <?php endforeach; ?>
    </select>

    <div>
        <textarea id="<?= $sec ?>_input"
            class="comm-textarea"
            readonly
            oninput="forceFullHeight(this)"><?= htmlspecialchars($val) ?></textarea>

        <div class="inline-edit-actions" id="<?= $sec ?>_edit_actions">
            <button class="save-btn" onclick="saveInlineEdit('<?= $sec ?>')">Save</button>
            <button class="cancel-btn" onclick="cancelInlineEdit('<?= $sec ?>')">Cancel</button>
        </div>
        
        <!-- Status message will appear here -->
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
    
    fetch('template_actions.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: new URLSearchParams(actionData)
    })
    .then(r => r.json())
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
</script>