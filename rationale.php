    <?php
    // Minimal Rationale module (included by view_report.php).
    // Expects $templates (array), $clientId, $rationaleText to be present in the parent scope.
    ?>
    <style>
    /* Rationale module styles - updated to match site blue theme */
    .rat-box {
        margin-top: 18px;
        padding: 14px;
        border: 1px solid #e6f2fb;
        border-radius: 8px;
        background: linear-gradient(180deg, #fbfdff 0%, #f6fbff 100%);
        box-shadow: 0 1px 0 rgba(2,136,209,0.03);
        font-family: Inter, Arial, sans-serif;
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
    /* hover: change to green (more specific, important to override other rules) */
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
    /* hover: change to red */
    .rat-btn.del:hover { background: #dc3545 !important; transform: translateY(-1px); }

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

    /* Textarea */
    .rat-textarea {
        width: 100%;
        padding: 12px;
        font-size: 14px;
        min-height: 140px;
        box-sizing: border-box;
        border: 1px solid #dbeefb;
        border-radius: 6px;
        background: #fff;
        color: #052b36;
        resize: vertical;
    }

    /* Flash messages area */
    .rat-flash { margin-top: 8px; min-height: 26px; }

    /* Make buttons consistent on small screens */
    @media (max-width: 640px) {
        .rat-controls { flex-direction: column; align-items: stretch; }
        .rat-select { width: 100%; }
        .rat-btn { width: 100%; text-align: center; }
    }
    </style>

    <div class="rat-box" id="rationale_module">
        <label style="font-weight:700; display:block; margin-bottom:8px;">Rationale</label>
        <div class="rat-controls">
            <select id="rationale_template_selector" class="rat-select">
                <option value="0">-- Select saved rationale template --</option>
                <?php if (!empty($templates['rationale'])): ?>
                    <?php foreach ($templates['rationale'] as $t): 
                        $tid = (int)($t['id'] ?? 0);
                        $tname = htmlspecialchars($t['name'] ?? '');
                        $tcontent = htmlspecialchars($t['content'] ?? '');
                    ?>
                        <option value="<?php echo $tid; ?>" data-content="<?php echo $tcontent; ?>"><?php echo $tname; ?></option>
                    <?php endforeach; ?>
                <?php endif; ?>
            </select>

            <!-- Add (plus) button to create a new template from current textarea -->
            <button id="rationale_add_btn" class="rat-btn add" type="button" title="Add new template" aria-label="Add new template" style="display:flex; align-items:center; justify-content:center; width:36px; height:36px; padding:0; border-radius:50%; background:#eaf7ff; border:1px solid #cfeefc; color:#0288d1;">
                <!-- SVG plus icon -->
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" aria-hidden="true" focusable="false" xmlns="http://www.w3.org/2000/svg">
                    <path d="M12 5v14M5 12h14" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                </svg>
            </button>

            <button id="rationale_save_btn" class="rat-btn save" type="button">Save</button>
            <button id="rationale_edit_btn" class="rat-btn edit" type="button">Edit</button>
            <button id="rationale_delete_btn" class="rat-btn del" type="button">Delete</button>
        </div>

        <textarea id="rationale_textarea" name="rationale_text" data-client-id="<?php echo (int)$clientId; ?>" class="rat-textarea"><?php echo htmlspecialchars($rationaleText); ?></textarea>
        <div id="rationale_flash_container" class="rat-flash"></div>
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
        const clientId = <?php echo json_encode((int)$clientId); ?>;

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
            flash.innerHTML = '<div class="flash-message ' + (type === 'success' ? 'flash-success' : 'flash-error') + '">' + (type === 'success' ? '✅ ' : '❌ ') + msg + '</div>';
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
                    textarea.value = opt.getAttribute('data-content') || '';
                    showFlash('success', 'Template loaded into editor.');
                }
            });
        }

        // Add: create new template from current textarea content (creates report_templates row)
        if (addBtn) {
            addBtn.addEventListener('click', function() {
                const content = (textarea.value || '').trim();
                if (!content) { showFlash('error','Rationale content cannot be empty.'); return; }
                const name = prompt('Enter a name for this new rationale template:');
                if (!name || !name.trim()) return;

                setButtonsDisabled(true);
                const body = new URLSearchParams();
                body.append('ajax_action','save_user_template');
                body.append('template_name', name.trim());
                body.append('template_content', content);

                fetch('view_report.php?id=' + encodeURIComponent(clientId), {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
                    body: body
                })
                .then(r => r.json())
                .then(data => {
                    if (data && data.success) {
                        if (data.template_id) {
                            const opt = upsertTemplateOption(data.template_id, name.trim(), content);
                            if (opt) selector.value = String(data.template_id);
                        }
                        showFlash('success','Template added successfully.');
                    } else {
                        throw new Error(data && data.error ? data.error : 'Save failed');
                    }
                })
                .catch(() => showFlash('error','Network error while adding template.'))
                .finally(() => setButtonsDisabled(false));
            });
        }

        // Edit: load selected template into editor
        if (editBtn) {
            editBtn.addEventListener('click', function() {
                const id = selector.value;
                if (!id || id === '0') { showFlash('error','Please select a template to edit.'); return; }
                const opt = selector.options[selector.selectedIndex];
                textarea.value = opt.getAttribute('data-content') || '';
                textarea.focus();
            });
        }

        // Save: update selected template or create new if none selected
        if (saveBtn) {
            saveBtn.addEventListener('click', function() {
                const id = selector.value;
                const content = (textarea.value || '').trim();
                if (!content) { showFlash('error','Rationale content cannot be empty.'); return; }

                let name;
                if (id && id !== '0') {
                    // update existing: use current option text as name (or prompt)
                    name = selector.options[selector.selectedIndex].text.trim() || 'Updated Template';
                } else {
                    name = prompt('Enter a name for this new rationale template:');
                    if (!name || !name.trim()) { showFlash('error','Template name is required.'); return; }
                }

                setButtonsDisabled(true);
                const body = new URLSearchParams();
                body.append('ajax_action','save_user_template');
                body.append('template_name', name.trim());
                body.append('template_content', content);
                if (id && id !== '0') body.append('template_id_to_update', id);

                fetch('view_report.php?id=' + encodeURIComponent(clientId), {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
                    body: body
                })
                .then(r => r.json())
                .then(data => {
                    if (data && data.success) {
                        const templateId = data.template_id || id;
                        if (templateId) {
                            const opt = upsertTemplateOption(templateId, name.trim(), content);
                            if (opt) selector.value = String(templateId);
                        }
                        showFlash('success','Template saved successfully.');
                    } else {
                        showFlash('error','Save failed: ' + (data.error || 'Unknown'));
                    }
                })
                .catch(() => showFlash('error','Network error while saving template.'))
                .finally(() => setButtonsDisabled(false));
            });
        }

        // Delete: delete selected template from report_templates
        if (delBtn) {
            delBtn.addEventListener('click', function() {
                const id = selector.value;
                if (!id || id === '0') { showFlash('error','Please select a template to delete.'); return; }
                if (!confirm('Delete selected template? This cannot be undone.')) return;

                setButtonsDisabled(true);
                const body = new URLSearchParams();
                body.append('ajax_action','delete_user_template');
                body.append('template_id', id);

                fetch('view_report.php?id=' + encodeURIComponent(clientId), {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
                    body: body
                })
                .then(r => r.json())
                .then(data => {
                    if (data && data.success) {
                        removeTemplateOption(id);
                        selector.value = '0';
                        showFlash('success','Template deleted successfully.');
                    } else {
                        showFlash('error','Delete failed: ' + (data.error || 'Unknown'));
                    }
                })
                .catch(() => showFlash('error','Network error while deleting template.'))
                .finally(() => setButtonsDisabled(false));
            });
        }
    })();
    </script>