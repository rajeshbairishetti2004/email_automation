<?php
// client_communication.php
// Complete standalone template management for greeting, intro, and closing sections
// Also handles workflow actions and auto-save
// FIX: Default template loads correctly even when stored value is a hardcoded placeholder string
// FIX: Uses setTimeout(50ms) so Quill is fully ready before dangerouslyPasteHTML is called
// FIX: INITIAL_CONTENT resolved entirely in PHP to avoid JS DOM-reading race conditions

// Handle AJAX requests
require_once 'db_config.php';
$pdo = getPdo();

$stmt = $pdo->prepare("
    SELECT greeting_prefix, intro_text, closing_text
    FROM clients
    WHERE id = ?
");
$stmt->execute([$clientId]);
$row = $stmt->fetch(PDO::FETCH_ASSOC);

// ✅ SAFE DEFAULTS
$greetingStored   = $row['greeting_prefix'] ?? '';
$introTextStored  = $row['intro_text'] ?? '';
$closingTextStored= $row['closing_text'] ?? '';

// ── Load stored communication fields ─────────────────────────────────────────
$introPlain = trim(strip_tags($introTextStored ?? ''));

if ($introPlain === '') {
    $introForEditor = $defaultIntro['content'] ?? '';
} else {
    $introForEditor = $introTextStored;
}

$closingPlain = trim(strip_tags($closingTextStored ?? ''));

if ($closingPlain === '') {
    $closingForEditor = $defaultClosing['content'] ?? '';
} else {
    $closingForEditor = $closingTextStored;
}

// ── Derive client first name ──────────────────────────────────────────────────
$clientFirstName = '';
if (!empty($name)) {
    $clientFirstName = explode(' ', trim($name))[0];
    $clientFirstName = ucfirst(strtolower($clientFirstName));
}

// ── Decide whether the stored greeting already contains the client name ───────
function _greetingNeedsName(?string $stored, string $firstName): bool
{
    if ($firstName === '') return false;
    $plain = trim(strip_tags($stored ?? ''));
    if ($plain === '') return true;
    if (mb_stripos($plain, $firstName) !== false) return false;
    return true;
}

$greetingNeedsName = _greetingNeedsName($greetingStored ?? '', $clientFirstName ?? '');


// ✅ FIX: Proper placeholder detection + load default template
$greetingPlain = trim(strip_tags($greetingStored ?? ''));

if ($greetingPlain === '') {
    $greetingForEditor = $defaultGreeting['content'] ?? '';
    $greetingNeedsSave = true;
} else {
    $greetingForEditor = $greetingStored;
    $greetingNeedsSave = false;
}
// ── Placeholder strings: DB values that mean "no real content yet" ──
// These are the DEFAULT_* constants written at upload time.


?>

<?php if (!defined('QUILL_CSS_LOADED')): define('QUILL_CSS_LOADED', true); ?>
<link href="https://cdnjs.cloudflare.com/ajax/libs/quill/1.3.7/quill.snow.min.css" rel="stylesheet">
<?php endif; ?>

<style>
    .comm-section {
        margin-top: 18px;
        margin-bottom: 18px;
        padding: 14px;
        border: 1px solid #e6f2fb;
        border-radius: 8px;
        background: linear-gradient(180deg, #fbfdff 0%, #f6fbff 100%);
        box-shadow: 0 1px 0 rgba(2, 136, 209, 0.03);
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
    }

    .comm-btn {
        padding: 8px 12px;
        border-radius: 6px;
        border: none;
        cursor: pointer;
        font-weight: 600;
        font-size: 13px;
        transition: background-color 0.12s ease, transform 0.06s ease;
    }

    .comm-btn.save  { background: #0288D1; color: #fff; }
    .comm-btn.save:hover  { background: #2eb85c !important; transform: translateY(-1px); }
    .comm-btn.edit  { background: #039be5; color: #fff; }
    .comm-btn.edit:hover  { background: #0288d1; transform: translateY(-1px); }
    .comm-btn.del   { background: #0277bd; color: #fff; }
    .comm-btn.del:hover   { background: #dc3545 !important; transform: translateY(-1px); }

    .comm-btn:focus, .comm-select:focus {
        outline: 3px solid rgba(2, 136, 209, 0.12);
        outline-offset: 2px;
    }

    .comm-quill-wrap {
        border: 1px solid #dbeefb;
        border-radius: 6px;
        overflow: hidden;
        background: #fff;
        transition: border-color 0.15s, box-shadow 0.15s;
    }

    .comm-quill-wrap:focus-within {
        border-color: #0288d1;
        box-shadow: 0 0 0 3px rgba(2, 136, 209, 0.10);
    }

    .comm-quill-wrap .ql-toolbar.ql-snow {
        border: none;
        border-bottom: 1px solid #e6f2fb;
        background: #f5fbff;
        padding: 6px 10px;
    }

    .comm-quill-wrap .ql-container.ql-snow { border: none; }

    .comm-quill-wrap .ql-editor {
        min-height: 100px;
        height: auto;
        overflow-y: hidden;
        font-family: Inter, Arial, sans-serif;
        font-size: 14px;
        color: #052b36;
        line-height: 1.6;
        padding: 10px 12px;
    }

    .comm-quill-wrap .ql-editor.ql-blank::before {
        font-style: normal;
        color: #aac6d8;
        font-size: 13px;
    }

    .comm-quill-wrap .ql-toolbar .ql-stroke  { stroke: #4a7a92; }
    .comm-quill-wrap .ql-toolbar .ql-fill    { fill:   #4a7a92; }
    .comm-quill-wrap .ql-toolbar button:hover .ql-stroke { stroke: #0288d1; }
    .comm-quill-wrap .ql-toolbar button:hover .ql-fill   { fill:   #0288d1; }
    .comm-quill-wrap .ql-toolbar .ql-active  .ql-stroke  { stroke: #0288d1; }
    .comm-quill-wrap .ql-toolbar .ql-active  .ql-fill    { fill:   #0288d1; }
    .comm-quill-wrap .ql-snow .ql-picker      { color: #4a7a92; }
    .comm-quill-wrap .ql-snow .ql-picker-options {
        background: #fff;
        border: 1px solid #dbeefb;
        border-radius: 6px;
        box-shadow: 0 4px 12px rgba(2, 136, 209, 0.10);
    }

    .comm-quill-wrap.is-locked .ql-toolbar { display: none; }
    .comm-quill-wrap.is-locked .ql-editor  { background: #f8fbff; cursor: default; }

    .comm-flash      { margin-top: 8px; min-height: 26px; font-size: 13px; }
    .flash-success   { color: #2eb85c; background: #edf9f0; padding: 6px 10px; border-radius: 4px; border-left: 3px solid #2eb85c; }
    .flash-error     { color: #dc3545; background: #fef2f2; padding: 6px 10px; border-radius: 4px; border-left: 3px solid #dc3545; }

    @media (max-width: 640px) {
        .comm-controls  { flex-direction: column; align-items: stretch; }
        .comm-select    { width: 100%; }
        .comm-btn:not(.add) { width: 100%; text-align: center; }
    }
</style>

<?php
$clientId = $clientId ?? 0;

if (!isset($isLocked)) {
    require_once __DIR__ . '/db_config.php';
    $pdo = getPdo();
    $stmt = $pdo->prepare("SELECT report_state, review_not_ok FROM clients WHERE id = ?");
    $stmt->execute([$clientId]);
    $clientLock  = $stmt->fetch(PDO::FETCH_ASSOC);
    $reportState = $clientLock['report_state']  ?? 'draft';
    $reviewNotOk = (int)($clientLock['review_not_ok'] ?? 0);
    $isLocked = (($reportState === 'reviewed' && $reviewNotOk === 0) || $reportState === 'sent');
}

$sections = [
    'greeting' => [
        'title'       => 'Greeting',
        'stored'      => $greetingForEditor,
        'db_field'    => 'greeting_prefix',
        'placeholder' => 'Enter greeting text…',
    ],
    'intro' => [
        'title'       => 'Introduction',
        'stored'      => $introForEditor,
        'db_field'    => 'intro_text',
        'placeholder' => 'Enter introduction text…',
    ],
    'closing' => [
        'title'       => 'Closing',
        'stored'      => $closingForEditor,
        'db_field'    => 'closing_text',
        'placeholder' => 'Enter closing remarks…',
    ],
];

foreach ($sections as $sec => $data):
    $tpls      = $templates[$sec] ?? [];
    $editorId  = $sec . '_quill_editor';
    $wrapperId = $sec . '_quill_wrap';

    // ── Resolve the default template content entirely in PHP ──────────────────
    // This is the KEY FIX: instead of relying on JS to read the dropdown at
    // runtime (which has timing issues), we resolve what to show right here
    // in PHP and pass it as a JSON constant into JS.
    $defaultTemplateContent = '';
    $defaultTemplateId      = 0;
    if (!empty($tpls)) {
        // First pass: find is_default = 1
        foreach ($tpls as $t) {
            if ((int)($t['is_default'] ?? 0) === 1) {
                $defaultTemplateId      = (int)$t['id'];
                $defaultTemplateContent = $t['content'] ?? '';
                break;
            }
        }
        // Fallback: use first template in list
        if ($defaultTemplateId === 0 && !empty($tpls[0])) {
            $defaultTemplateId      = (int)$tpls[0]['id'];
            $defaultTemplateContent = $tpls[0]['content'] ?? '';
        }
    }

    // Check if stored value is a bare placeholder (no real user content yet)
   $rawStored = '';

if ($sec === 'greeting') {
    $rawStored = $greetingStored;
} elseif ($sec === 'intro') {
    $rawStored = $introTextStored;
} elseif ($sec === 'closing') {
    $rawStored = $closingTextStored;
}

$storedPlain = trim(strip_tags($rawStored));
$storedIsEmpty = $storedPlain === '';

if (!$storedIsEmpty) {
    $initialContent = $rawStored;
    $isDefaultBeingLoaded = false;
} elseif ($defaultTemplateContent !== '') {
    $initialContent = $defaultTemplateContent;
    $isDefaultBeingLoaded = true;
} else {
    $initialContent = '';
    $isDefaultBeingLoaded = false;
}

 $shouldAutosaveInitial = !$isLocked && ($isDefaultBeingLoaded || ($sec === 'greeting' && $greetingNeedsSave));
?>
<div class="comm-section" id="<?= $sec ?>_section">
    <div class="comm-header">
        <div class="comm-title">
            <?= htmlspecialchars($data['title']) ?>
            <?php if ($isLocked): ?>
                <span title="Locked" style="margin-left:8px;color:#888;vertical-align:middle;">🔒</span>
            <?php endif; ?>
        </div>
    </div>

    <div class="comm-controls">
        <select id="<?= $sec ?>_template_selector" class="comm-select" <?= $isLocked ? 'disabled' : '' ?>>
            <option value="0">-- Select saved <?= strtolower($data['title']) ?> template --</option>
            <?php foreach ($tpls as $t):
                $tid      = (int)($t['id'] ?? 0);
                $tname    = htmlspecialchars($t['name'] ?? '');
                $tcontent = htmlspecialchars($t['content'] ?? '', ENT_QUOTES);
            ?>
                <option
                    value="<?= $tid ?>"
                    data-content="<?= $tcontent ?>"
                    <?= ($tid === $defaultTemplateId) ? 'selected' : '' ?>>
                    <?= $tname ?>
                </option>
            <?php endforeach; ?>
        </select>
    </div>

    <!-- Quill editor -->
    <div id="<?= $wrapperId ?>" class="comm-quill-wrap<?= $isLocked ? ' is-locked' : '' ?>">
        <div id="<?= $editorId ?>"></div>
    </div>

    <!-- Hidden textarea keeps the HTML value for form POST and auto-save -->
    <textarea
        id="<?= $sec ?>_textarea"
        name="<?= $sec ?>"
        data-client-id="<?= (int)$clientId ?>"
        data-field="<?= $data['db_field'] ?>"
        style="display:none;"><?= htmlspecialchars($initialContent) ?></textarea>

    <div id="<?= $sec ?>_flash_container" class="comm-flash"></div>
</div>

<?php if (!defined('QUILL_JS_LOADED')): define('QUILL_JS_LOADED', true); ?>
<script src="https://cdnjs.cloudflare.com/ajax/libs/quill/1.3.7/quill.min.js"></script>
<?php endif; ?>

<script>
(function () {
    /* ── CONFIG ── */
    const SEC       = <?= json_encode($sec) ?>;
    const DB_FIELD  = <?= json_encode($data['db_field']) ?>;
    const CLIENT_ID = <?= json_encode((int)$clientId) ?>;
    const IS_LOCKED = <?= $isLocked ? 'true' : 'false' ?>;
    const PLACEHOLDER_TEXT = <?= json_encode($data['placeholder']) ?>;

    const IS_GREETING = (SEC === 'greeting');
    const CLIENT_FIRST_NAME = <?= json_encode($clientFirstName) ?>;

    // KEY FIX: Content to load is resolved in PHP, passed as a plain JSON string.
    // No JS DOM reading needed — eliminates all timing/race conditions.
    const INITIAL_CONTENT         = <?= json_encode($initialContent) ?>;
    const SHOULD_AUTOSAVE_INITIAL = <?= $shouldAutosaveInitial ? 'true' : 'false' ?>;

    /* ── DOM refs ── */
    const selector = document.getElementById(SEC + '_template_selector');
    const hiddenTA = document.getElementById(SEC + '_textarea');
    const flash    = document.getElementById(SEC + '_flash_container');

    /* ── INITIALISE QUILL ── */
    const quill = new Quill('#' + SEC + '_quill_editor', {
        theme: 'snow',
        readOnly: IS_LOCKED,
        placeholder: PLACEHOLDER_TEXT,
        modules: { toolbar: false }
    });
    setTimeout(() => {
    if (typeof INITIAL_CONTENT !== "undefined") {
        quill.clipboard.dangerouslyPasteHTML(INITIAL_CONTENT);
    }
}, 50);

    /* ── SYNC Quill → hidden textarea ── */
    function syncToHidden() {
        hiddenTA.value = quill.root.innerHTML;
    }
    quill.on('text-change', syncToHidden);

    /* ── AUTO-RESIZE: grow editor with content ── */
    function autoResize() {
        const editor = document.querySelector('#' + SEC + '_quill_editor .ql-editor');
        if (!editor) return;
        editor.style.height = 'auto';
        editor.style.height = editor.scrollHeight + 'px';
    }
    quill.on('text-change', autoResize);

    /* ── HELPERS ── */
    function showFlash(type, msg) {
        flash.innerHTML = '<div class="' + (type === 'success' ? 'flash-success' : 'flash-error') + '">'
            + (type === 'success' ? '✓ ' : '✗ ') + msg + '</div>';
        setTimeout(() => { flash.innerHTML = ''; }, 3500);
    }

    function normalize(html) {
        return (html || '').replace(/\s+/g, ' ').trim();
    }

    function saveToServer(html, silent) {
        return fetch('view_report.php?id=' + CLIENT_ID, {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
            body: new URLSearchParams({
                ajax_action: 'save_client_field',
                client_id:   CLIENT_ID,
                field:       DB_FIELD,
                value:       html
            })
        })
        .then(r => r.json())
        .then(d => {
            if (!silent) {
                if (d.success) showFlash('success', d.message || 'Saved');
                else showFlash('error', 'Save failed: ' + (d.error || 'Unknown'));
            }
            return d;
        })
        .catch(() => { if (!silent) showFlash('error', 'Network error'); });
    }

    /* ── Append first name to greeting ── */
    function appendFirstNameToGreeting(html) {
        if (!IS_GREETING || !CLIENT_FIRST_NAME) return html;
        const tmp = document.createElement('div');
        tmp.innerHTML = html;
        const plain = tmp.textContent || tmp.innerText || '';
        if (plain.toLowerCase().indexOf(CLIENT_FIRST_NAME.toLowerCase()) !== -1) return html;
        const frag = document.createElement('div');
        frag.innerHTML = html;
        const lastChild = frag.lastChild;
        if (lastChild && lastChild.nodeType === Node.TEXT_NODE) {
            lastChild.textContent = lastChild.textContent.replace(/[,\s]+$/, '') + ' ' + CLIENT_FIRST_NAME + ',';
        } else if (lastChild) {
            frag.innerHTML = frag.innerHTML.replace(/[,\s]+$/, '') + ' ' + CLIENT_FIRST_NAME + ',';
        } else {
            frag.innerHTML = html.replace(/[,\s]+$/, '') + ' ' + CLIENT_FIRST_NAME + ',';
        }
        return frag.innerHTML;
    }

    /* ── LOAD INITIAL CONTENT ──────────────────────────────────────────────────
       KEY FIX: Wrapped in setTimeout(50) so Quill's internal DOM is fully
       ready before we call dangerouslyPasteHTML. Without this delay, Quill
       may silently discard the paste or overwrite it during its own init.

       INITIAL_CONTENT comes directly from PHP (resolved there), so there is
       no JS-side DOM reading of the selector — no race condition possible.
    ── */
    setTimeout(function() {
        if (!INITIAL_CONTENT || INITIAL_CONTENT.trim() === '') {
            // Nothing to load — Quill shows its placeholder hint text
            if (!IS_LOCKED) quill.root.setAttribute('contenteditable', 'true');
            return;
        }

        // Disable focus during paste to prevent page auto-scroll
        quill.root.setAttribute('contenteditable', 'false');

        let content = INITIAL_CONTENT;

        // For greeting: append first name if missing
        if (IS_GREETING) {
            content = appendFirstNameToGreeting(content);
        }

        quill.clipboard.dangerouslyPasteHTML(content);
        syncToHidden();
        autoResize();

        // Re-enable editing
        if (!IS_LOCKED) {
            quill.root.setAttribute('contenteditable', 'true');
        }

        // Auto-save if we loaded a default template or built a greeting with the
        // client name — this persists the content so the next page load shows it
        // as "real" content and does not re-trigger the default template logic.
        if (SHOULD_AUTOSAVE_INITIAL) {
            saveToServer(quill.root.innerHTML, true /* silent */);
        }

    }, 50); // 50 ms is enough for Quill to finish its internal setup

    /* ── Tracking for auto-save ── */
    let lastSaved = '';
    setTimeout(() => { lastSaved = quill.root.innerHTML; }, 300);
    let isSaving = false;

    /* ── AUTO-SAVE on blur ── */
    quill.root.addEventListener('blur', function () {
        if (IS_LOCKED || isSaving) return;
        const html = quill.root.innerHTML;
        if (normalize(html) === normalize(lastSaved)) return;
        isSaving = true;
        saveToServer(html, false)
            .then(() => { lastSaved = html; })
            .finally(() => { isSaving = false; });
    });

    /* ── PERIODIC AUTO-SAVE (every 15 s) ── */
    setInterval(function () {
        if (IS_LOCKED || isSaving) return;
        const html = quill.root.innerHTML;
        if (normalize(html) !== normalize(lastSaved)) {
            isSaving = true;
            saveToServer(html, true)
                .then(() => { lastSaved = html; })
                .finally(() => { isSaving = false; });
        }
    }, 15000);

    /* ── TEMPLATE SELECTOR: load on manual change ── */
    if (selector) {
        selector.addEventListener('change', function () {
            const id = selector.value;
            if (!id || id === '0') return;

            const opt = selector.options[selector.selectedIndex];
            const tmp = document.createElement('div');
            tmp.innerHTML = opt.getAttribute('data-content') || '';
            let decoded = tmp.innerHTML;

            if (IS_GREETING) {
                decoded = appendFirstNameToGreeting(decoded);
            }

            quill.clipboard.dangerouslyPasteHTML(decoded);
            syncToHidden();
            autoResize();
            showFlash('success', 'Template loaded' + (IS_GREETING && CLIENT_FIRST_NAME ? ' — name added' : '') + '.');

            if (!IS_LOCKED) {
                saveToServer(quill.root.innerHTML, true /* silent */);
            }
        });
    }

    /* ── Option helpers ── */
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

    /* ── SAVE button (if present) ── */
    const saveBtn = document.getElementById(SEC + '_save_btn');
    saveBtn && saveBtn.addEventListener('click', function () {
        if (IS_LOCKED) return;
        const id   = selector.value;
        const html = quill.root.innerHTML;
        if (!quill.getText().trim()) { showFlash('error', 'Content cannot be empty.'); return; }
        let tplName;
        if (id && id !== '0') {
            tplName = selector.options[selector.selectedIndex].text.trim() || 'Updated Template';
        } else {
            tplName = prompt('Enter a name for this new ' + SEC + ' template:');
            if (!tplName || !tplName.trim()) { showFlash('error', 'Template name is required.'); return; }
        }
        const body = new URLSearchParams({
            ajax_action:      id && id !== '0' ? 'edit_template' : 'save_template',
            template_name:    tplName.trim(),
            template_content: html,
            section_type:     SEC
        });
        if (id && id !== '0') body.append('template_id', id);
        fetch('view_report.php?id=' + CLIENT_ID, { method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' }, body })
            .then(r => r.json())
            .then(d => {
                if (d && d.success) {
                    const tid = d.new_id || id;
                    if (tid) { upsertOption(tid, tplName.trim(), html); selector.value = String(tid); }
                    showFlash('success', 'Template saved successfully.');
                } else showFlash('error', 'Save failed: ' + (d.error || 'Unknown'));
            })
            .catch(() => showFlash('error', 'Network error while saving template.'));
    });

    /* ── EDIT button (if present) ── */
    const editBtn = document.getElementById(SEC + '_edit_btn');
    editBtn && editBtn.addEventListener('click', function () {
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

    /* ── DELETE button (if present) ── */
    const delBtn = document.getElementById(SEC + '_delete_btn');
    delBtn && delBtn.addEventListener('click', function () {
        if (IS_LOCKED) return;
        const id = selector.value;
        if (!id || id === '0') { showFlash('error', 'Please select a template to delete.'); return; }
        if (!confirm('Delete selected template? This cannot be undone.')) return;
       fetch('view_report.php?id=' + CLIENT_ID, { method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
    body: new URLSearchParams({ ajax_action: 'delete_template', template_id: id }) })
.then(r => r.json())
            .then(d => {
                if (d && d.success) { removeOption(id); selector.value = '0'; showFlash('success', 'Template deleted.'); }
                else showFlash('error', 'Delete failed: ' + (d.error || 'Unknown'));
            })
            .catch(() => showFlash('error', 'Network error while deleting.'));
    });

})();
document.addEventListener("DOMContentLoaded", function () {
    setTimeout(() => {
        window.scrollTo({ top: 0, behavior: "smooth" });
    }, 50);
});
</script>
<?php endforeach; ?>