document.addEventListener('DOMContentLoaded', function() {
    const selector = document.getElementById('rationale_template_selector');
    const textarea = document.getElementById('rationale_textarea');
    const editBtn = document.getElementById('rationale_edit_btn');
    const saveBtn = document.getElementById('rationale_save_btn');
    const deleteBtn = document.getElementById('rationale_delete_btn');
    const nameInput = document.getElementById('rationale_template_name');
    const flashContainer = document.getElementById('rationale_flash_container');

    function showFlash(type, msg) {
        if (!flashContainer) return;
        flashContainer.innerHTML = `<div class="flash-message ${type === 'success' ? 'flash-success' : 'flash-error'}">${msg}</div>`;
        setTimeout(()=> { flashContainer.innerHTML = ''; }, 3000);
    }

    function setEditMode(on) {
        if (on) {
            textarea.removeAttribute('readonly');
            nameInput.style.display = 'inline-block';
            saveBtn.disabled = false;
            deleteBtn.disabled = !(selector && selector.value && selector.value !== '0');
            editBtn.textContent = 'Cancel';
        } else {
            textarea.setAttribute('readonly', 'readonly');
            nameInput.style.display = 'none';
            saveBtn.disabled = true;
            deleteBtn.disabled = true;
            editBtn.textContent = 'Edit';
            if (selector && selector.value !== '0') {
                const opt = selector.options[selector.selectedIndex];
                textarea.value = opt.getAttribute('data-content') || '';
            }
        }
    }

    function insertOrUpdateOption(id, name, content) {
        let existingOpt = Array.from(selector.options).find(o => o.value == id);
        if (existingOpt) {
            existingOpt.textContent = name;
            existingOpt.setAttribute('data-content', content);
            const insertBeforeNode = selector.options[1] || null;
            selector.insertBefore(existingOpt, insertBeforeNode);
            return existingOpt;
        } else {
            const opt = document.createElement('option');
            opt.value = id;
            opt.textContent = name;
            opt.setAttribute('data-content', content);
            const insertBeforeNode = selector.options[1] || null;
            selector.insertBefore(opt, insertBeforeNode);
            return opt;
        }
    }

    // initial states
    if (saveBtn) saveBtn.disabled = true;
    if (deleteBtn) deleteBtn.disabled = true;

    if (selector) {
        selector.addEventListener('change', function() {
            const opt = selector.options[selector.selectedIndex] || {};
            const content = opt.getAttribute('data-content') || '';
            textarea.value = content;
            if (nameInput.style.display !== 'none') {
                nameInput.value = selector.value !== '0' ? (selector.options[selector.selectedIndex].text || '') : '';
                if (selector.value === '0') {
                    deleteBtn.disabled = true;
                    nameInput.removeAttribute('data-template-id');
                } else {
                    nameInput.dataset.templateId = selector.value;
                    deleteBtn.disabled = false;
                }
            } else {
                deleteBtn.disabled = true;
            }
        });
    }

    // Edit toggles
    if (editBtn) {
        editBtn.addEventListener('click', function() {
            const isEditing = !textarea.hasAttribute('readonly');
            if (!isEditing) {
                setEditMode(true);
                if (selector && selector.value !== '0') {
                    nameInput.value = selector.options[selector.selectedIndex].text || '';
                    nameInput.dataset.templateId = selector.value;
                } else {
                    nameInput.value = '';
                    nameInput.removeAttribute('data-template-id');
                }
                textarea.focus();
            } else {
                setEditMode(false);
            }
        });
    }

    // Save handler (create/update, move to top, select)
    if (saveBtn) {
        saveBtn.addEventListener('click', function() {
            const templateName = nameInput.value.trim();
            const content = textarea.value.trim();
            const templateId = nameInput.dataset.templateId ? parseInt(nameInput.dataset.templateId, 10) : 0;

            if (!templateName) { showFlash('error','Please provide a template name.'); return; }
            if (!content) { showFlash('error','Template content is empty.'); return; }

            const body = new URLSearchParams();
            body.append('ajax_action', 'save_user_template');
            body.append('template_name', templateName);
            body.append('template_content', content);
            body.append('template_id_to_update', templateId);

            fetch('view_report.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
                body: body.toString()
            })
            .then(res => res.json())
            .then(data => {
                if (data.success) {
                    const newId = data.template_id || templateId || null;
                    if (newId) {
                        insertOrUpdateOption(newId, templateName, content);
                        selector.value = newId;
                        selector.dispatchEvent(new Event('change'));
                        nameInput.dataset.templateId = newId;
                        deleteBtn.disabled = false;
                    }
                    showFlash('success','Template saved.');
                    setEditMode(true); // keep editing mode
                } else {
                    showFlash('error', data.error || 'Save failed');
                }
            })
            .catch(()=> showFlash('error','Network error'));
        });
    }

    // Delete handler
    if (deleteBtn) {
        deleteBtn.addEventListener('click', function() {
            const templateId = nameInput.dataset.templateId ? parseInt(nameInput.dataset.templateId, 10) : 0;
            if (!templateId) { showFlash('error','No template selected to delete.'); return; }
            if (!confirm('Delete selected template? This cannot be undone.')) return;

            const body = new URLSearchParams();
            body.append('ajax_action', 'delete_user_template');
            body.append('template_id', templateId);

            fetch('view_report.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
                body: body.toString()
            })
            .then(res => res.json())
            .then(data => {
                if (data.success) {
                    const opt = Array.from(selector.options).find(o => o.value == templateId);
                    if (opt) opt.remove();
                    textarea.value = '';
                    nameInput.value = '';
                    nameInput.removeAttribute('data-template-id');
                    deleteBtn.disabled = true;
                    showFlash('success','Template deleted.');
                } else {
                    showFlash('error', data.error || 'Delete failed');
                }
            })
            .catch(()=> showFlash('error','Network error'));
        });
    }

    // Autosave on blur when editable
    if (textarea) {
        textarea.addEventListener('blur', function() {
            if (textarea.hasAttribute('readonly')) return;
            const clientId = textarea.getAttribute('data-client-id');
            const field = textarea.getAttribute('data-field');
            const value = textarea.value.trim();
            if (!clientId || !field) return;

            const body = new URLSearchParams();
            body.append('ajax', '1');
            body.append('client_id', clientId);
            body.append('field', field);
            body.append('value', value);

            fetch('view_report.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
                body: body.toString()
            })
            .then(res => res.json())
            .then(data => {
                if (data.success) showFlash('success','Rationale saved.');
                else showFlash('error', data.error || 'Save failed');
            })
            .catch(()=> showFlash('error','Network error'));
        });
    }

    // enable delete when selector changes
    if (selector) {
        selector.addEventListener('change', function() {
            if (deleteBtn) deleteBtn.disabled = (selector.value === '0');
        });
    }
});
