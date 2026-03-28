<?php
// client_communication.php
// Complete standalone template management for greeting, intro, and closing sections
// Also handles workflow actions and auto-save
// UPDATED: Greeting auto-appends client first name when template is loaded or on first visit
// UPDATED: Default templates auto-load into empty editors on page load
// UPDATED: Falls back to first template if no is_default=1 found (mirrors rationale.php)

// Handle AJAX requests
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
        } elseif ($action === 'delete_template') {
            $stmt = $pdo->prepare("DELETE FROM report_templates WHERE id = ?");
            $stmt->execute([(int)$_POST['template_id']]);
            $response['success'] = true;
            $response['deleted_id'] = (int)$_POST['template_id'];
        } elseif ($action === 'save_template') {
            $section = $_POST['section_type'] ?? '';
            $name    = trim($_POST['template_name'] ?? '');
            $content = $_POST['template_content'] ?? '';

            if ($name === '' || $content === '') {
                throw new Exception('Template name and content are required');
            }

            $stmt = $pdo->prepare("INSERT INTO report_templates (name, section_type, content) VALUES (?, ?, ?)");
            $stmt->execute([$name, $section, $content]);
            $newId = $pdo->lastInsertId();

            $response = [
                'success' => true,
                'new_id' => $newId,
                'template_name' => $name,
                'template_content' => $content
            ];
        } elseif ($action === 'save_draft') {
            $clientId = (int)($_POST['client_id'] ?? 0);
            if ($clientId <= 0) throw new Exception("Invalid Client ID.");
            $pdo->prepare("UPDATE clients SET report_state='draft', draft_at=NOW(), review_not_ok=0, review_comment=NULL WHERE id=:id")->execute([':id' => $clientId]);
            echo json_encode(['success' => true, 'message' => 'Draft saved successfully.', 'updated_state' => 'draft']);
            exit;
        } elseif ($action === 'ready_for_review') {
            $clientId = (int)($_POST['client_id'] ?? 0);
            if ($clientId <= 0) throw new Exception("Invalid Client ID.");
            $pdo->prepare("UPDATE clients SET report_state='ready', ready_at=NOW(), review_not_ok=0, review_comment=NULL WHERE id=:id")->execute([':id' => $clientId]);
            echo json_encode(['success' => true, 'message' => 'Report marked Ready for Review.', 'updated_state' => 'ready']);
            exit;
        } elseif ($action === 'approve_review') {
            $clientId = (int)($_POST['client_id'] ?? 0);
            if ($clientId <= 0) throw new Exception("Invalid Client ID.");
            $pdo->prepare("UPDATE clients SET report_state='reviewed', reviewed_at=NOW(), review_not_ok=0, review_comment=NULL WHERE id=:id")->execute([':id' => $clientId]);
            echo json_encode(['success' => true, 'message' => 'Report Approved (Reviewed).', 'updated_state' => 'reviewed']);
            exit;
        } elseif ($action === 'review_not_ok') {
            $clientId = (int)($_POST['client_id'] ?? 0);
            $comment  = trim($_POST['review_comment'] ?? '');
            if ($clientId <= 0) throw new Exception("Invalid Client ID.");
            if (empty($comment)) throw new Exception("A comment is required for rejection.");
            $pdo->prepare("UPDATE clients SET report_state='draft', review_not_ok=1, review_comment=:comment WHERE id=:id")->execute([':id' => $clientId, ':comment' => $comment]);
            echo json_encode(['success' => true, 'message' => 'Report rejected and moved back to Draft.', 'updated_state' => 'draft']);
            exit;
        } elseif ($action === 'email_sent') {
            $clientId = (int)($_POST['client_id'] ?? 0);
            if ($clientId <= 0) throw new Exception("Invalid Client ID.");
            $pdo->prepare("UPDATE clients SET report_state='sent', sent_at=NOW() WHERE id=:id")->execute([':id' => $clientId]);
            echo json_encode(['success' => true, 'message' => 'Report marked as Sent.', 'updated_state' => 'sent']);
            exit;
        } elseif ($action === 'save_client_field') {
            $clientId = (int)($_POST['client_id'] ?? 0);
            $field    = trim($_POST['field'] ?? '');
            $value    = $_POST['value'] ?? '';

            if ($clientId <= 0) throw new Exception("Invalid Client ID.");
            if (empty($field)) throw new Exception("Field name is required.");

            $allowedFields = ['greeting_prefix', 'intro_text', 'closing_text'];
            if (!in_array($field, $allowedFields)) {
                throw new Exception("Invalid field name.");
            }

            $pdo->prepare("UPDATE clients SET $field = ? WHERE id = ?")->execute([$value, $clientId]);
            echo json_encode(['success' => true, 'message' => ucfirst(str_replace('_', ' ', $field)) . ' saved successfully.']);
            exit;
        } else {
            echo json_encode(['success' => false, 'error' => 'Unknown action: ' . $action]);
            exit;
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

// ── Load stored communication fields ─────────────────────────────────────────
if (!isset($greetingStored) || !isset($introTextStored) || !isset($closingTextStored)) {
    require_once __DIR__ . '/db_config.php';
    $pdo = getPdo();

    $stmt = $pdo->prepare("SELECT greeting_prefix, intro_text, closing_text FROM clients WHERE id = ?");
    $stmt->execute([$clientId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

    $greetingStored    = (string)($row['greeting_prefix'] ?? '');
    $introTextStored   = (string)($row['intro_text'] ?? '');
    $closingTextStored = (string)($row['closing_text'] ?? '');
}

// ── Derive client first name ──────────────────────────────────────────────────
$clientFirstName = '';
if (!empty($name)) {
    $clientFirstName = explode(' ', trim($name))[0];
    $clientFirstName = ucfirst(strtolower($clientFirstName));
}

// ── Decide whether the stored greeting already contains the client name ───────
function _greetingNeedsName(string $stored, string $firstName): bool
{
    if ($firstName === '') return false;
    $plain = trim(strip_tags($stored));
    if ($plain === '') return true;
    if (mb_stripos($plain, $firstName) !== false) return false;
    return true;
}

$greetingNeedsName = _greetingNeedsName($greetingStored, $clientFirstName);

// If greeting is empty or bare default, build a proper initial value
$BARE_DEFAULTS = ['Dear Mr.', 'Dear Mrs.', 'Dear Ms.', 'Dear Dr.', 'Dear', 'Hello'];

if ($greetingStored === '' || in_array(trim(strip_tags($greetingStored)), $BARE_DEFAULTS, true)) {
    $salutation = trim(strip_tags($greetingStored));
    if ($salutation === '') $salutation = 'Dear Mr.';
    if ($clientFirstName !== '') {
        $greetingInitial = $salutation . ' ' . $clientFirstName . ',';
    } else {
        $greetingInitial = $salutation;
    }
    if (strip_tags($greetingStored) === $greetingStored) {
        $greetingForEditor = $greetingInitial;
    } else {
        $greetingForEditor = rtrim($greetingStored, ' ,') . ' ' . $clientFirstName . ',';
    }
    $greetingNeedsSave = true;
} else {
    $greetingForEditor = $greetingStored;
    $greetingNeedsSave = false;
}

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

    .comm-btn:focus,
    .comm-select:focus {
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

    .comm-quill-wrap .ql-toolbar .ql-stroke { stroke: #4a7a92; }
    .comm-quill-wrap .ql-toolbar .ql-fill   { fill: #4a7a92; }
    .comm-quill-wrap .ql-toolbar button:hover .ql-stroke { stroke: #0288d1; }
    .comm-quill-wrap .ql-toolbar button:hover .ql-fill   { fill: #0288d1; }
    .comm-quill-wrap .ql-toolbar .ql-active .ql-stroke   { stroke: #0288d1; }
    .comm-quill-wrap .ql-toolbar .ql-active .ql-fill     { fill: #0288d1; }
    .comm-quill-wrap .ql-snow .ql-picker { color: #4a7a92; }
    .comm-quill-wrap .ql-snow .ql-picker-options {
        background: #fff;
        border: 1px solid #dbeefb;
        border-radius: 6px;
        box-shadow: 0 4px 12px rgba(2, 136, 209, 0.10);
    }

    .comm-quill-wrap.is-locked .ql-toolbar { display: none; }
    .comm-quill-wrap.is-locked .ql-editor  { background: #f8fbff; cursor: default; }

    .comm-flash { margin-top: 8px; min-height: 26px; font-size: 13px; }

    .flash-success {
        color: #2eb85c; background: #edf9f0;
        padding: 6px 10px; border-radius: 4px; border-left: 3px solid #2eb85c;
    }

    .flash-error {
        color: #dc3545; background: #fef2f2;
        padding: 6px 10px; border-radius: 4px; border-left: 3px solid #dc3545;
    }

    @media (max-width: 640px) {
        .comm-controls { flex-direction: column; align-items: stretch; }
        .comm-select { width: 100%; }
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
        'stored'      => $introTextStored,
        'db_field'    => 'intro_text',
        'placeholder' => 'Enter introduction text…',
    ],
    'closing' => [
        'title'       => 'Closing',
        'stored'      => $closingTextStored,
        'db_field'    => 'closing_text',
        'placeholder' => 'Enter closing remarks…',
    ],
];

foreach ($sections as $sec => $data):
    $tpls = $templates[$sec] ?? [];

    // ── Find default template for this section ────────────────────────────────
    // Priority 1: template with is_default = 1
    // Priority 2 (fallback): first template in list  ← mirrors rationale.php exactly
    $defaultTemplateId      = 0;
    $defaultTemplateContent = '';

    if (!empty($tpls)) {
        // Try explicitly-marked default first
        foreach ($tpls as $t) {
            if ((int)($t['is_default'] ?? 0) === 1) {
                $defaultTemplateId      = (int)$t['id'];
                $defaultTemplateContent = (string)($t['content'] ?? '');
                break;
            }
        }
        // Fallback: first template when none is marked default
        if ($defaultTemplateId === 0) {
            $defaultTemplateId      = (int)$tpls[0]['id'];
            $defaultTemplateContent = (string)($tpls[0]['content'] ?? '');
        }
    }
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
                    $tid        = (int)($t['id'] ?? 0);
                    $tname      = htmlspecialchars($t['name'] ?? '');
                    $tcontent   = htmlspecialchars($t['content'] ?? '', ENT_QUOTES);
                    // Pre-select the default/first template in the dropdown — mirrors rationale.php
                    $isSelected = ($tid === $defaultTemplateId) ? 'selected' : '';
                ?>
                    <option value="<?= $tid ?>" data-content="<?= $tcontent ?>" <?= $isSelected ?>>
                        <?= $tname ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>

        <!-- Quill editor -->
        <div id="<?= $sec ?>_quill_wrap" class="comm-quill-wrap<?= $isLocked ? ' is-locked' : '' ?>">
            <div id="<?= $sec ?>_quill_editor"></div>
        </div>

        <!-- Hidden textarea for form POST fallback -->
        <textarea
            id="<?= $sec ?>_textarea"
            name="<?= $sec ?>"
            data-client-id="<?= (int)$clientId ?>"
            data-field="<?= $data['db_field'] ?>"
            style="display:none;"><?= htmlspecialchars($data['stored']) ?></textarea>

        <div id="<?= $sec ?>_flash_container" class="comm-flash"></div>
    </div>

    <?php if (!defined('QUILL_JS_LOADED')): define('QUILL_JS_LOADED', true); ?>
        <script src="https://cdnjs.cloudflare.com/ajax/libs/quill/1.3.7/quill.min.js"></script>
    <?php endif; ?>

    <script>
        (function() {
            /* ── CONFIG ── */
            const SEC               = <?= json_encode($sec) ?>;
            const DB_FIELD          = <?= json_encode($data['db_field']) ?>;
            const CLIENT_ID         = <?= json_encode((int)$clientId) ?>;
            const IS_LOCKED         = <?= $isLocked ? 'true' : 'false' ?>;
            const PLACEHOLDER       = <?= json_encode($data['placeholder']) ?>;
            const IS_GREETING       = (SEC === 'greeting');
            const CLIENT_FIRST_NAME = <?= json_encode($clientFirstName) ?>;
            const GREETING_NEEDS_SAVE = <?= (!$isLocked && $sec === 'greeting' && $greetingNeedsSave) ? 'true' : 'false' ?>;

            // Default template (is_default=1 wins; fallback = first template in list)
            const DEFAULT_TEMPLATE_ID      = <?= json_encode($defaultTemplateId) ?>;
            const DEFAULT_TEMPLATE_CONTENT = <?= json_encode($defaultTemplateContent) ?>;

            /* ── DOM refs ── */
            const selector = document.getElementById(SEC + '_template_selector');
            const hiddenTA = document.getElementById(SEC + '_textarea');
            const flash    = document.getElementById(SEC + '_flash_container');

            /* ── INITIALISE QUILL ── */
            const quill = new Quill('#' + SEC + '_quill_editor', {
                theme: 'snow',
                readOnly: IS_LOCKED,
                placeholder: PLACEHOLDER,
                modules: { toolbar: false }
            });

            /* ── SYNC Quill → hidden textarea ── */
            function syncToHidden() {
                hiddenTA.value = quill.root.innerHTML;
            }
            quill.on('text-change', syncToHidden);

            /* ── AUTO-RESIZE ── */
            function autoResize() {
                const editor = document.querySelector('#' + SEC + '_quill_editor .ql-editor');
                if (!editor) return;
                editor.style.height = 'auto';
                editor.style.height = editor.scrollHeight + 'px';
            }
            quill.on('text-change', autoResize);
            setTimeout(autoResize, 150);

            /* ── HELPERS ── */
            function showFlash(type, msg) {
                flash.innerHTML = '<div class="' + (type === 'success' ? 'flash-success' : 'flash-error') + '">' +
                    (type === 'success' ? '✓ ' : '✗ ') + msg + '</div>';
                setTimeout(() => { flash.innerHTML = ''; }, 3500);
            }

            function normalize(html) { return html.replace(/\s+/g, ' ').trim(); }

            function saveToServer(html, silent) {
                return fetch('client_communication.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
                    body: new URLSearchParams({
                        ajax_action: 'save_client_field',
                        client_id: CLIENT_ID,
                        field: DB_FIELD,
                        value: html
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

            /* ── INIT EDITOR on page load ── */
            (function initEditor() {
                // Suppress scroll-jump during programmatic load
                quill.root.setAttribute('contenteditable', 'false');

                const stored     = hiddenTA.value.trim();
                const storedText = stored.replace(/<[^>]*>/g, '').trim();

                // Values treated as "no real stored content"
                const EMPTY_VALUES = ['', 'Introduction', 'Closing remarks', 'Rationale for recommendations'];
                const isStoredEmpty = EMPTY_VALUES.includes(storedText);

                if (stored && !isStoredEmpty) {
                    // ── Case 1: Previously saved client content — load it ──
                    quill.clipboard.dangerouslyPasteHTML(stored);
                    syncToHidden();
                    setTimeout(() => {
                        if (!IS_LOCKED) quill.root.setAttribute('contenteditable', 'true');
                        autoResize();
                    }, 100);
                    // Greeting: auto-save if first name was just appended
                    if (IS_GREETING && GREETING_NEEDS_SAVE && !IS_LOCKED) {
                        setTimeout(() => saveToServer(quill.root.innerHTML, true), 300);
                    }

                } else if (DEFAULT_TEMPLATE_ID > 0 && DEFAULT_TEMPLATE_CONTENT) {
                    // ── Case 2: No saved content — load default/first template ──
                    // (Matches rationale.php behaviour exactly)
                    const tmp = document.createElement('div');
                    tmp.innerHTML = DEFAULT_TEMPLATE_CONTENT;
                    let decoded = tmp.innerHTML;

                    if (IS_GREETING) decoded = appendFirstNameToGreeting(decoded);

                    quill.clipboard.dangerouslyPasteHTML(decoded);
                    syncToHidden();

                    // Sync dropdown selection (PHP already set `selected` but enforce via JS too)
                    if (selector) selector.value = String(DEFAULT_TEMPLATE_ID);

                    setTimeout(() => {
                        if (!IS_LOCKED) quill.root.setAttribute('contenteditable', 'true');
                        autoResize();
                    }, 100);

                    // Persist to DB so next load hits Case 1 directly
                    if (!IS_LOCKED) {
                        setTimeout(() => saveToServer(quill.root.innerHTML, true), 300);
                    }

                } else {
                    // ── Case 3: No templates at all — show placeholder ──
                    setTimeout(() => {
                        if (!IS_LOCKED) quill.root.setAttribute('contenteditable', 'true');
                    }, 100);
                }
            })();

            /* ── Auto-save tracking ── */
            let lastSaved = '';
            setTimeout(() => { lastSaved = quill.root.innerHTML; }, 0);
            let isSaving = false;

            /* ── AUTO-SAVE on blur ── */
            quill.root.addEventListener('blur', function() {
                if (IS_LOCKED || isSaving) return;
                const html = quill.root.innerHTML;
                if (normalize(html) === normalize(lastSaved)) return;
                isSaving = true;
                saveToServer(html, false)
                    .then(() => { lastSaved = html; })
                    .finally(() => { isSaving = false; });
            });

            /* ── PERIODIC AUTO-SAVE (every 15 s) ── */
            setInterval(function() {
                if (IS_LOCKED || isSaving) return;
                const html = quill.root.innerHTML;
                if (normalize(html) !== normalize(lastSaved)) {
                    isSaving = true;
                    saveToServer(html, true)
                        .then(() => { lastSaved = html; })
                        .finally(() => { isSaving = false; });
                }
            }, 15000);

            /* ── TEMPLATE SELECTOR: load on change ── */
            if (selector) {
                selector.addEventListener('change', function() {
                    const id = selector.value;
                    if (!id || id === '0') return;
                    const opt = selector.options[selector.selectedIndex];
                    const tmp = document.createElement('div');
                    tmp.innerHTML = opt.getAttribute('data-content') || '';
                    let decoded = tmp.innerHTML;
                    if (IS_GREETING) decoded = appendFirstNameToGreeting(decoded);
                    quill.clipboard.dangerouslyPasteHTML(decoded);
                    syncToHidden();
                    showFlash('success', 'Template loaded' + (IS_GREETING && CLIENT_FIRST_NAME ? ' — name added' : '') + '.');
                    if (!IS_LOCKED) saveToServer(quill.root.innerHTML, true);
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
                if (!opt) {
                    opt = document.createElement('option');
                    opt.value = String(id);
                    selector.appendChild(opt);
                }
                opt.textContent = name;
                opt.setAttribute('data-content', htmlContent);
                return opt;
            }

            function removeOption(id) {
                const o = findOption(id);
                if (o) o.remove();
            }

            /* ── SAVE button (if present) ── */
            const saveBtn = document.getElementById(SEC + '_save_btn');
            saveBtn && saveBtn.addEventListener('click', function() {
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

                fetch('client_communication.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
                    body
                })
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

            /* ── DELETE button (if present) ── */
            const delBtn = document.getElementById(SEC + '_delete_btn');
            delBtn && delBtn.addEventListener('click', function() {
                if (IS_LOCKED) return;
                const id = selector.value;
                if (!id || id === '0') { showFlash('error', 'Please select a template to delete.'); return; }
                if (!confirm('Delete selected template? This cannot be undone.')) return;

                fetch('client_communication.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
                    body: new URLSearchParams({ ajax_action: 'delete_template', template_id: id })
                })
                .then(r => r.json())
                .then(d => {
                    if (d && d.success) {
                        removeOption(id);
                        selector.value = '0';
                        showFlash('success', 'Template deleted.');
                    } else showFlash('error', 'Delete failed: ' + (d.error || 'Unknown'));
                })
                .catch(() => showFlash('error', 'Network error while deleting.'));
            });

        })();
    </script>
<?php endforeach; ?>