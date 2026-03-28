<?php
// rationale.php
// FIX: initEditor now compares stored text against default template plain-text.
// If stored == template text (stale placeholder), reload fresh from real template HTML.

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
            $stmt->execute([$_POST['template_name'] ?? '', $_POST['template_content'] ?? '', (int)$_POST['template_id']]);
            $response['success'] = true;
        } elseif ($action === 'delete_template') {
            $stmt = $pdo->prepare("DELETE FROM report_templates WHERE id = ?");
            $stmt->execute([(int)$_POST['template_id']]);
            $response['success'] = true;
            $response['deleted_id'] = (int)$_POST['template_id'];
        } elseif ($action === 'save_template') {
            $section = $_POST['section_type'] ?? '';
            $name    = trim($_POST['template_name'] ?? '');
            $content = $_POST['template_content'] ?? '';
            if ($name === '' || $content === '') throw new Exception('Template name and content are required');
            $stmt = $pdo->prepare("INSERT INTO report_templates (name, section_type, content) VALUES (?, ?, ?)");
            $stmt->execute([$name, $section, $content]);
            $newId = $pdo->lastInsertId();
            $response = ['success' => true, 'new_id' => $newId, 'template_name' => $name, 'template_content' => $content];
        } elseif ($action === 'save_user_template') {
            $templateId = (int)($_POST['template_id_to_update'] ?? 0);
            $name    = trim($_POST['template_name'] ?? '');
            $content = $_POST['template_content'] ?? '';
            if ($name === '' || $content === '') throw new Exception('Template name and content are required');
            if ($templateId > 0) {
                $stmt = $pdo->prepare("UPDATE report_templates SET name = ?, content = ? WHERE id = ? AND section_type = 'rationale'");
                $stmt->execute([$name, $content, $templateId]);
            } else {
                $stmt = $pdo->prepare("INSERT INTO report_templates (name, section_type, content) VALUES (?, 'rationale', ?)");
                $stmt->execute([$name, $content]);
                $templateId = $pdo->lastInsertId();
            }
            $response = ['success' => true, 'template_id' => $templateId];
        } elseif ($action === 'delete_user_template') {
            $templateId = (int)($_POST['template_id'] ?? 0);
            if ($templateId <= 0) throw new Exception('Invalid template ID');
            $pdo->prepare("DELETE FROM report_templates WHERE id = ? AND section_type = 'rationale'")->execute([$templateId]);
            $response = ['success' => true];
        } elseif ($action === 'save_draft') {
            $clientId = (int)($_POST['client_id'] ?? 0);
            if ($clientId <= 0) throw new Exception("Invalid Client ID.");
            $pdo->prepare("UPDATE clients SET report_state = 'draft', draft_at = NOW(), review_not_ok = 0, review_comment = NULL WHERE id = :id")->execute([':id' => $clientId]);
            echo json_encode(['success' => true, 'message' => 'Draft saved successfully.', 'updated_state' => 'draft']); exit;
        } elseif ($action === 'ready_for_review') {
            $clientId = (int)($_POST['client_id'] ?? 0);
            if ($clientId <= 0) throw new Exception("Invalid Client ID.");
            $pdo->prepare("UPDATE clients SET report_state = 'ready', ready_at = NOW(), review_not_ok = 0, review_comment = NULL WHERE id = :id")->execute([':id' => $clientId]);
            echo json_encode(['success' => true, 'message' => 'Report marked Ready for Review.', 'updated_state' => 'ready']); exit;
        } elseif ($action === 'approve_review') {
            $clientId = (int)($_POST['client_id'] ?? 0);
            if ($clientId <= 0) throw new Exception("Invalid Client ID.");
            $pdo->prepare("UPDATE clients SET report_state = 'reviewed', reviewed_at = NOW(), review_not_ok = 0, review_comment = NULL WHERE id = :id")->execute([':id' => $clientId]);
            echo json_encode(['success' => true, 'message' => 'Report Approved (Reviewed).', 'updated_state' => 'reviewed']); exit;
        } elseif ($action === 'review_not_ok') {
            $clientId = (int)($_POST['client_id'] ?? 0);
            $comment  = trim($_POST['review_comment'] ?? '');
            if ($clientId <= 0) throw new Exception("Invalid Client ID.");
            if (empty($comment)) throw new Exception("A comment is required for rejection.");
            $pdo->prepare("UPDATE clients SET report_state = 'draft', review_not_ok = 1, review_comment = :comment WHERE id = :id")->execute([':id' => $clientId, ':comment' => $comment]);
            echo json_encode(['success' => true, 'message' => 'Report rejected and moved back to Draft.', 'updated_state' => 'draft']); exit;
        } elseif ($action === 'email_sent') {
            $clientId = (int)($_POST['client_id'] ?? 0);
            if ($clientId <= 0) throw new Exception("Invalid Client ID.");
            $pdo->prepare("UPDATE clients SET report_state = 'sent', sent_at = NOW() WHERE id = :id")->execute([':id' => $clientId]);
            echo json_encode(['success' => true, 'message' => 'Report marked as Sent.', 'updated_state' => 'sent']); exit;
        } elseif ($action === 'save_rationale') {
            $clientId = (int)($_POST['client_id'] ?? 0);
            $value    = $_POST['value'] ?? '';
            if ($clientId <= 0) throw new Exception("Invalid Client ID.");
            $pdo->prepare("UPDATE clients SET rationale_text = ? WHERE id = ?")->execute([$value, $clientId]);
            echo json_encode(['success' => true, 'message' => 'Rationale saved successfully.']); exit;
        } elseif ($action === 'autosave_rationale') {
            $clientId = (int)($_POST['client_id'] ?? 0);
            $value    = $_POST['value'] ?? '';
            if ($clientId <= 0) throw new Exception("Invalid Client ID.");
            $pdo->prepare("UPDATE clients SET rationale_text = :val WHERE id = :id")->execute([':val' => $value, ':id' => $clientId]);
            echo json_encode(['success' => true]); exit;
        } else {
            echo json_encode(['success' => false, 'error' => 'Unknown action: ' . $action]); exit;
        }

        ob_end_clean();
        echo json_encode($response);
        exit;
    } catch (Throwable $e) {
        ob_end_clean();
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        exit;
    }
}

// Fetch fresh rationale HTML from DB
if (!isset($rationaleText) || $rationaleText === '') {
    require_once 'db_config.php';
    $pdo = getPdo();
    $stmt = $pdo->prepare("SELECT rationale_text FROM clients WHERE id = ?");
    $stmt->execute([$clientId]);
    $rationaleText = $stmt->fetchColumn() ?? '';
}

$rationaleTemplates      = $templates['rationale'] ?? [];
$rationaleDefaultId      = 0;
$rationaleDefaultContent = '';

if (!empty($rationaleTemplates)) {
    foreach ($rationaleTemplates as $t) {
        if ((int)($t['is_default'] ?? 0) === 1) {
            $rationaleDefaultId      = (int)$t['id'];
            $rationaleDefaultContent = (string)($t['content'] ?? '');
            break;
        }
    }
    if ($rationaleDefaultId === 0) {
        $rationaleDefaultId      = (int)$rationaleTemplates[0]['id'];
        $rationaleDefaultContent = (string)($rationaleTemplates[0]['content'] ?? '');
    }
}
?>

<?php if (!defined('QUILL_CSS_LOADED')): define('QUILL_CSS_LOADED', true); ?>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/quill/1.3.7/quill.snow.min.css" rel="stylesheet">
<?php endif; ?>

<style>
    .rat-box { margin-top: 18px; margin-bottom: 18px; padding: 14px; border: 1px solid #e6f2fb; border-radius: 8px; background: linear-gradient(180deg, #fbfdff 0%, #f6fbff 100%); box-shadow: 0 1px 0 rgba(2,136,209,0.03); font-family: Inter, Arial, sans-serif; }
    .rat-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 12px; padding-bottom: 8px; border-bottom: 1px solid #e6f2fb; }
    .rat-title { font-weight: 700; color: #083744; font-size: 16px; margin: 0; }
    .rat-controls { display: flex; gap: 10px; align-items: center; margin-bottom: 10px; flex-wrap: wrap; }
    .rat-select { min-width: 300px; padding: 8px 10px; border: 1px solid #dbeefb; border-radius: 6px; background: #fff; color: #083744; font-size: 14px; }
    .rat-btn { padding: 8px 12px; border-radius: 6px; border: none; cursor: pointer; font-weight: 600; font-size: 13px; transition: background-color 0.12s ease, transform 0.06s ease; }
    .ql-toolbar { display: none !important; }
    .rat-btn.save { background: #0288D1; color: #fff; }
    .rat-btn.save:hover { background: #2eb85c !important; transform: translateY(-1px); }
    .rat-btn.edit { background: #039be5; color: #fff; }
    .rat-btn.edit:hover { background: #0288d1; transform: translateY(-1px); }
    .rat-btn.del { background: #0277bd; color: #fff; }
    .rat-btn.del:hover { background: #dc3545 !important; transform: translateY(-1px); }
    .rat-btn.add { display: flex; align-items: center; justify-content: center; width: 36px; height: 36px; padding: 0; border-radius: 50%; background: #eaf7ff; border: 1px solid #cfeefc; color: #0288d1; }
    .rat-btn[disabled] { opacity: 0.65; cursor: not-allowed; transform: none !important; }
    .rat-btn:focus, .rat-select:focus { outline: 3px solid rgba(2,136,209,0.12); outline-offset: 2px; }
    .rat-quill-wrap { border: 1px solid #dbeefb; border-radius: 6px; overflow: hidden; background: #fff; transition: border-color 0.15s, box-shadow 0.15s; }
    .rat-quill-wrap:focus-within { border-color: #0288d1; box-shadow: 0 0 0 3px rgba(2,136,209,0.10); }
    .rat-quill-wrap .ql-toolbar.ql-snow { border: none; border-bottom: 1px solid #e6f2fb; background: #f5fbff; padding: 6px 10px; }
    .rat-quill-wrap .ql-container.ql-snow { border: none; }
    .rat-quill-wrap .ql-editor { min-height: 140px; max-height: 500px; overflow-y: auto; font-family: Inter, Arial, sans-serif; font-size: 14px; color: #052b36; line-height: 1.6; padding: 10px 12px; }
    .rat-quill-wrap .ql-editor.ql-blank::before { font-style: normal; color: #aac6d8; font-size: 13px; }
    .rat-quill-wrap .ql-toolbar .ql-stroke { stroke: #4a7a92; }
    .rat-quill-wrap .ql-toolbar .ql-fill   { fill: #4a7a92; }
    .rat-quill-wrap .ql-toolbar button:hover .ql-stroke { stroke: #0288d1; }
    .rat-quill-wrap .ql-toolbar button:hover .ql-fill   { fill: #0288d1; }
    .rat-quill-wrap .ql-toolbar .ql-active .ql-stroke   { stroke: #0288d1; }
    .rat-quill-wrap .ql-toolbar .ql-active .ql-fill     { fill: #0288d1; }
    .rat-quill-wrap .ql-snow .ql-picker { color: #4a7a92; }
    .rat-quill-wrap .ql-snow .ql-picker-options { background: #fff; border: 1px solid #dbeefb; border-radius: 6px; box-shadow: 0 4px 12px rgba(2,136,209,0.10); }
    .rat-quill-wrap .ql-snow .ql-color-picker .ql-picker-item { width: 18px !important; height: 18px !important; border-radius: 3px !important; border: 1px solid rgba(0,0,0,0.10) !important; }
    .rat-quill-wrap .ql-snow .ql-color-picker.ql-color .ql-picker-options, .rat-quill-wrap .ql-snow .ql-color-picker.ql-background .ql-picker-options { width: 164px !important; padding: 5px !important; }
    .rat-quill-wrap .ql-snow .ql-color-picker .ql-picker-options { display: none; }
    .rat-quill-wrap .ql-snow .ql-color-picker.ql-expanded .ql-picker-options { display: block !important; }
    .rat-quill-wrap.is-locked .ql-toolbar { display: none; }
    .rat-quill-wrap.is-locked .ql-editor  { background: #f8fbff; cursor: default; }
    .rat-flash { margin-top: 8px; min-height: 26px; font-size: 13px; }
    .flash-success { color: #2eb85c; background: #edf9f0; padding: 6px 10px; border-radius: 4px; border-left: 3px solid #2eb85c; }
    .flash-error   { color: #dc3545; background: #fef2f2; padding: 6px 10px; border-radius: 4px; border-left: 3px solid #dc3545; }
    .rat-btn.add svg { width: 16px; height: 16px; stroke: currentColor; stroke-width: 2; stroke-linecap: round; stroke-linejoin: round; }
    @media (max-width: 640px) { .rat-controls { flex-direction: column; align-items: stretch; } .rat-select { width: 100%; } .rat-btn:not(.add) { width: 100%; text-align: center; } }
</style>

<?php
$clientId      = $clientId      ?? 0;
$rationaleText = $rationaleText ?? '';
$isLocked      = $isLocked      ?? false;
$templates     = $templates     ?? [];
?>

<div class="rat-box comm-section" id="rationale_module">
    <div class="rat-header comm-header">
        <div class="rat-title comm-title">
            Rationale
            <?php if ($isLocked): ?>
                <span title="Locked" style="margin-left:8px;color:#888;">🔒</span>
            <?php endif; ?>
        </div>
    </div>

    <div class="rat-controls">
        <select id="rationale_template_selector" class="rat-select" <?= $isLocked ? 'disabled' : '' ?>>
            <option value="0">-- Select saved rationale template --</option>
            <?php foreach ($rationaleTemplates as $t):
                $tid      = (int)($t['id'] ?? 0);
                $tname    = htmlspecialchars($t['name'] ?? '');
                $tcontent = htmlspecialchars($t['content'] ?? '', ENT_QUOTES);
                $isSel    = ($tid === $rationaleDefaultId) ? 'selected' : '';
            ?>
                <option value="<?= $tid ?>" data-content="<?= $tcontent ?>" <?= $isSel ?>><?= $tname ?></option>
            <?php endforeach; ?>
        </select>
    </div>

    <div id="rationale_quill_wrap" class="rat-quill-wrap<?= $isLocked ? ' is-locked' : '' ?>">
        <div id="rationale_quill_editor"></div>
    </div>

    <textarea id="rationale_textarea" name="rationale_text"
        class="comm-textarea large-textarea rat-main-textarea"
        data-client-id="<?= (int)$clientId ?>" data-field="rationale_text"
        style="display:none;" <?= $isLocked ? 'readonly' : '' ?>><?= htmlspecialchars($rationaleText) ?></textarea>

    <div id="rationale_flash_container" class="comm-flash"></div>
</div>

<?php if (!defined('QUILL_JS_LOADED')): define('QUILL_JS_LOADED', true); ?>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/quill/1.3.7/quill.min.js"></script>
<?php endif; ?>

<script>
(function() {
    const CLIENT_ID = <?= json_encode((int)$clientId) ?>;
    const IS_LOCKED = <?= $isLocked ? 'true' : 'false' ?>;
    const DEFAULT_TEMPLATE_ID      = <?= json_encode($rationaleDefaultId) ?>;
    const DEFAULT_TEMPLATE_CONTENT = <?= json_encode($rationaleDefaultContent) ?>;

    const selector = document.getElementById('rationale_template_selector');
    const hiddenTA = document.getElementById('rationale_textarea');
    const saveBtn  = document.getElementById('rationale_save_btn');
    const editBtn  = document.getElementById('rationale_edit_btn');
    const delBtn   = document.getElementById('rationale_delete_btn');
    const addBtn   = document.getElementById('rationale_add_btn');
    const flash    = document.getElementById('rationale_flash_container');

    const quill = new Quill('#rationale_quill_editor', {
        theme: 'snow',
        readOnly: IS_LOCKED,
        placeholder: '',
        modules: { toolbar: false }
    });

    function syncToHidden() { hiddenTA.value = quill.root.innerHTML; }
    quill.on('text-change', syncToHidden);

    function stripHtml(html) {
        const d = document.createElement('div');
        d.innerHTML = html;
        return (d.textContent || d.innerText || '').replace(/\s+/g, ' ').trim();
    }

    /* ── KEY FIX: initEditor ─────────────────────────────────────────────────
       Same logic as client_communication.php:
       - No template → load stored as-is
       - Template exists + stored is blank → load template
       - Template exists + stored text == template text → reload fresh template HTML
       - Template exists + stored genuinely differs → keep user's edits
    ── */
    (function initEditor() {
        quill.root.setAttribute('contenteditable', 'false');

        const stored     = hiddenTA.value.trim();
        const storedText = stripHtml(stored);

        function loadDefaultTemplate() {
            const tmp = document.createElement('div');
            tmp.innerHTML = DEFAULT_TEMPLATE_CONTENT;
            quill.clipboard.dangerouslyPasteHTML(tmp.innerHTML);
            syncToHidden();
            if (selector) selector.value = String(DEFAULT_TEMPLATE_ID);
            if (!IS_LOCKED && CLIENT_ID) setTimeout(() => performSave(quill.root.innerHTML), 300);
        }

        if (!DEFAULT_TEMPLATE_ID || !DEFAULT_TEMPLATE_CONTENT) {
            if (stored && storedText !== '') {
                quill.clipboard.dangerouslyPasteHTML(stored);
                syncToHidden();
            }
        } else {
            const defaultText = stripHtml(DEFAULT_TEMPLATE_CONTENT);

            if (!storedText) {
                loadDefaultTemplate();
            } else if (storedText === defaultText) {
                // Stored text matches template text — reload fresh HTML (picks up formatting changes)
                loadDefaultTemplate();
            } else {
                // Genuine user edit — keep it
                quill.clipboard.dangerouslyPasteHTML(stored);
                syncToHidden();
                if (selector) selector.value = String(DEFAULT_TEMPLATE_ID);
            }
        }

        setTimeout(function() {
            if (!IS_LOCKED) quill.root.setAttribute('contenteditable', 'true');
            window.scrollTo({ top: 0, behavior: 'instant' });
        }, 100);
    })();

    let lastSaved = quill.root.innerHTML;

    function performSave(html) {
        fetch('rationale.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
            body: new URLSearchParams({ ajax_action: 'save_rationale', client_id: CLIENT_ID, value: html })
        })
        .then(r => r.json())
        .then(d => {
            if (d.success) { lastSaved = html; showFlash('success', d.message || 'Saved'); }
            else showFlash('error', 'Save failed: ' + (d.error || 'Unknown'));
        })
        .catch(() => showFlash('error', 'Network error while saving'));
    }

    quill.root.addEventListener('blur', function() {
        if (IS_LOCKED) return;
        const html = quill.root.innerHTML;
        if (html === lastSaved) return;
        performSave(html);
    });

    if (!IS_LOCKED && CLIENT_ID) {
        setInterval(function() {
            const html = quill.root.innerHTML;
            if (html !== lastSaved) performSave(html);
        }, 15000);

        window.addEventListener('beforeunload', function() {
            const html = quill.root.innerHTML;
            if (html !== lastSaved) {
                navigator.sendBeacon('rationale.php', new URLSearchParams({ ajax_action: 'autosave_rationale', client_id: CLIENT_ID, value: html }));
            }
        });
    }

    function interceptWorkflowButtons() {
        document.querySelectorAll('.workflow-actions .wf-btn').forEach(function(button) {
            button.addEventListener('click', function() {
                if (IS_LOCKED) return;
                syncToHidden();
                const html = quill.root.innerHTML;
                if (html !== lastSaved) performSave(html);
            }, true);
        });
    }
    setTimeout(interceptWorkflowButtons, 600);

    hiddenTA.autoResizeHeight = function() {};

    function showFlash(type, msg) {
        flash.innerHTML = '<div class="' + (type === 'success' ? 'flash-success' : 'flash-error') + '">' +
            (type === 'success' ? '✓ ' : '✗ ') + msg + '</div>';
        setTimeout(() => { flash.innerHTML = ''; }, 3500);
    }

    function setButtonsDisabled(state) {
        [addBtn, saveBtn, editBtn, delBtn].forEach(b => { if (b) b.disabled = !!state; });
    }

    function findOption(id) {
        const v = String(id);
        for (const o of selector.options) if (o.value === v) return o;
        return null;
    }
    function upsertOption(id, name, htmlContent) {
        let opt = findOption(id);
        if (!opt) { opt = document.createElement('option'); opt.value = String(id); selector.appendChild(opt); }
        opt.textContent = name;
        opt.setAttribute('data-content', htmlContent);
        return opt;
    }
    function removeOption(id) { const o = findOption(id); if (o) o.remove(); }

    selector.addEventListener('change', function() {
        const id = selector.value;
        if (!id || id === '0') return;
        const opt = selector.options[selector.selectedIndex];
        const tmp = document.createElement('div');
        tmp.innerHTML = opt.getAttribute('data-content') || '';
        quill.clipboard.dangerouslyPasteHTML(tmp.innerHTML);
        syncToHidden();
        showFlash('success', 'Template loaded — colours preserved.');
        if (!IS_LOCKED) performSave(quill.root.innerHTML);
    });

    addBtn && addBtn.addEventListener('click', function() {
        if (IS_LOCKED) return;
        if (!quill.getText().trim()) { showFlash('error', 'Content cannot be empty.'); return; }
        const name = prompt('Enter a name for this new rationale template:');
        if (!name || !name.trim()) return;
        const html = quill.root.innerHTML;
        setButtonsDisabled(true);
        fetch('rationale.php', { method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' }, body: new URLSearchParams({ ajax_action: 'save_template', template_name: name.trim(), template_content: html, section_type: 'rationale' }) })
            .then(r => r.json())
            .then(d => {
                if (d && d.success) { upsertOption(d.new_id, name.trim(), html); selector.value = String(d.new_id); showFlash('success', 'Template added successfully.'); }
                else throw new Error(d.error || 'Save failed');
            })
            .catch(e => showFlash('error', e.message))
            .finally(() => setButtonsDisabled(false));
    });

    editBtn && editBtn.addEventListener('click', function() {
        if (IS_LOCKED) return;
        const id = selector.value;
        if (!id || id === '0') { showFlash('error', 'Please select a template to edit.'); return; }
        const opt = selector.options[selector.selectedIndex];
        const tmp = document.createElement('div');
        tmp.innerHTML = opt.getAttribute('data-content') || '';
        quill.clipboard.dangerouslyPasteHTML(tmp.innerHTML);
        syncToHidden();
        quill.focus();
    });

    saveBtn && saveBtn.addEventListener('click', function() {
        if (IS_LOCKED) return;
        const id   = selector.value;
        const html = quill.root.innerHTML;
        if (!quill.getText().trim()) { showFlash('error', 'Content cannot be empty.'); return; }
        let name;
        if (id && id !== '0') { name = selector.options[selector.selectedIndex].text.trim() || 'Updated Template'; }
        else { name = prompt('Enter a name for this new rationale template:'); if (!name || !name.trim()) { showFlash('error', 'Template name is required.'); return; } }
        setButtonsDisabled(true);
        const body = new URLSearchParams({ ajax_action: id && id !== '0' ? 'edit_template' : 'save_template', template_name: name.trim(), template_content: html, section_type: 'rationale' });
        if (id && id !== '0') body.append('template_id', id);
        fetch('rationale.php', { method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' }, body })
            .then(r => r.json())
            .then(d => {
                if (d && d.success) { const tid = d.new_id || id; if (tid) { upsertOption(tid, name.trim(), html); selector.value = String(tid); } showFlash('success', 'Template saved successfully.'); }
                else showFlash('error', 'Save failed: ' + (d.error || 'Unknown'));
            })
            .catch(() => showFlash('error', 'Network error while saving.'))
            .finally(() => setButtonsDisabled(false));
    });

    delBtn && delBtn.addEventListener('click', function() {
        if (IS_LOCKED) return;
        const id = selector.value;
        if (!id || id === '0') { showFlash('error', 'Please select a template to delete.'); return; }
        if (!confirm('Delete selected template? This cannot be undone.')) return;
        setButtonsDisabled(true);
        fetch('rationale.php', { method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' }, body: new URLSearchParams({ ajax_action: 'delete_template', template_id: id }) })
            .then(r => r.json())
            .then(d => {
                if (d && d.success) { removeOption(id); selector.value = '0'; showFlash('success', 'Template deleted.'); }
                else showFlash('error', 'Delete failed: ' + (d.error || 'Unknown'));
            })
            .catch(() => showFlash('error', 'Network error while deleting.'))
            .finally(() => setButtonsDisabled(false));
    });

})();
</script>