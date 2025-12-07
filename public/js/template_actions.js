// --- Template Management ---
function loadTemplate(templateId, callback) {
    fetch('template_actions.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
        body: new URLSearchParams({
            ajax_action: 'load_template',
            template_id: templateId
        })
    })
    .then(res => res.json())
    .then(data => {
        if (data.success && callback) callback(data.content);
        else alert('Failed to load template.');
    })
    .catch(() => alert('Network error loading template.'));
}

function deleteTemplate(templateId, callback) {
    if (!confirm('Are you sure you want to delete this template?')) return;
    fetch('template_actions.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
        body: new URLSearchParams({
            ajax_action: 'delete_template',
            template_id: templateId
        })
    })
    .then(res => res.json())
    .then(data => {
        if (data.success) {
            if (callback) callback();
        } else {
            alert('Failed to delete template: ' + (data.error || 'Unknown error'));
        }
    })
    .catch(() => alert('Network error deleting template.'));
}

// --- RM Management ---
function loadRM(rmId, callback) {
    fetch('template_actions.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
        body: new URLSearchParams({
            ajax_action: 'load_rm',
            rm_id: rmId
        })
    })
    .then(res => res.json())
    .then(data => {
        if (data.success && callback) callback(data.signature_block, data.rm_name);
        else alert('Failed to load RM.');
    })
    .catch(() => alert('Network error loading RM.'));
}

function deleteRM(rmId, callback) {
    if (!confirm('Are you sure you want to delete this RM?')) return;
    fetch('template_actions.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
        body: new URLSearchParams({
            ajax_action: 'delete_rm',
            rm_id: rmId
        })
    })
    .then(res => res.json())
    .then(data => {
        if (data.success) {
            if (callback) callback();
        } else {
            alert('Failed to delete RM: ' + (data.error || 'Unknown error'));
        }
    })
    .catch(() => alert('Network error deleting RM.'));
}
