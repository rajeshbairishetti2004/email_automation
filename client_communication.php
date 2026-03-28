<?php
// client_communication.php
// Complete standalone template management for greeting, intro, and closing sections
// FIXED: ALWAYS uses default templates from report_templates table, ignores all stored text

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

            $response = ['success' => true, 'new_id' => $newId,
                         'template_name' => $name, 'template_content' => $content];
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

// ── Helper functions ─────────────────────────────────────────────────────────
function stripHtmlToPlain($html) {
    return trim(preg_replace('/\s+/', ' ', strip_tags($html)));
}

function getDefaultTemplateHtml($section, $templates) {
    if (empty($templates[$section] ?? [])) {
        return '';
    }
    foreach ($templates[$section] as $t) {
        if (!empty($t['is_default']) && (int)$t['is_default'] === 1) {
            return $t['content'] ?? '';
        }
    }
    return $templates[$section][0]['content'] ?? '';
}

function getDefaultTemplateId($section, $templates) {
    if (empty($templates[$section] ?? [])) {
        return 0;
    }
    foreach ($templates[$section] as $t) {
        if (!empty($t['is_default']) && (int)$t['is_default'] === 1) {
            return (int)$t['id'];
        }
    }
    return (int)$templates[$section][0]['id'] ?? 0;
}

// ── Get default templates from report_templates table ────────────────────────
$defaultGreetingHtml = getDefaultTemplateHtml('greeting', $templates ?? []);
$defaultIntroHtml = getDefaultTemplateHtml('intro', $templates ?? []);
$defaultClosingHtml = getDefaultTemplateHtml('closing', $templates ?? []);

$defaultGreetingId = getDefaultTemplateId('greeting', $templates ?? []);
$defaultIntroId = getDefaultTemplateId('intro', $templates ?? []);
$defaultClosingId = getDefaultTemplateId('closing', $templates ?? []);

// ── ALWAYS USE DEFAULT TEMPLATES (ignore all stored text) ────────────────────
// For greeting: use default template + client first name
function buildGreetingWithName($templateHtml, $firstName) {
    if ($firstName === '') {
        return $templateHtml;
    }
    
    $plain = strip_tags($templateHtml);
    
    // Check if template already contains a placeholder or name
    if (stripos($plain, $firstName) !== false) {
        return $templateHtml;
    }
    
    // If template has placeholder like "FirstName" or just ends with space
    if (stripos($plain, 'firstname') !== false || stripos($plain, 'FirstName') !== false) {
        // Replace placeholder with actual name
        $result = str_ireplace('firstname', $firstName, $templateHtml);
        $result = str_ireplace('FirstName', $firstName, $result);
        // Add comma if not present
        if (!preg_match('/,$/', trim($result))) {
            $result = rtrim($result, ' ,') . ',';
        }
        return $result;
    }
    
    // Just append name with comma
    $trimmed = rtrim($templateHtml, ' ,');
    return $trimmed . ' ' . $firstName . ',';
}

$greetingFinal = buildGreetingWithName($defaultGreetingHtml, $clientFirstName);
$introFinal = $defaultIntroHtml;
$closingFinal = $defaultClosingHtml;

// Auto-save greeting if it's different from stored
$greetingNeedsAutoSave = ($greetingFinal !== $greetingStored);
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

    .comm-quill-wrap {
        border: 1px solid #dbeefb;
        border-radius: 6px;
        overflow: hidden;
        background: #fff;
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
        max-height: 360px;
        overflow-y: auto;
        font-family: Inter, Arial, sans-serif;
        font-size: 14px;
        color: #052b36;
        line-height: 1.6;
        padding: 10px 12px;
    }

    .comm-quill-wrap.is-locked .ql-toolbar { display: none; }
    .comm-quill-wrap.is-locked .ql-editor { background: #f8fbff; cursor: default; }

    .comm-flash { margin-top: 8px; min-height: 26px; font-size: 13px; }
    .flash-success { color: #2eb85c; background: #edf9f0; padding: 6px 10px; border-radius: 4px; border-left: 3px solid #2eb85c; }
    .flash-error { color: #dc3545; background: #fef2f2; padding: 6px 10px; border-radius: 4px; border-left: 3px solid #dc3545; }
</style>

<?php
$clientId = $clientId ?? 0;

if (!isset($isLocked)) {
    require_once __DIR__ . '/db_config.php';
    $pdo = getPdo();
    $stmt = $pdo->prepare("SELECT report_state, review_not_ok FROM clients WHERE id = ?");
    $stmt->execute([$clientId]);
    $clientLock = $stmt->fetch(PDO::FETCH_ASSOC);
    $reportState = $clientLock['report_state'] ?? 'draft';
    $reviewNotOk = (int)($clientLock['review_not_ok'] ?? 0);
    $isLocked = (($reportState === 'reviewed' && $reviewNotOk === 0) || $reportState === 'sent');
}

$sections = [
    'greeting' => [
        'title' => 'Greeting',
        'final' => $greetingFinal,
        'db_field' => 'greeting_prefix',
        'placeholder' => 'Enter greeting text…',
        'default_id' => $defaultGreetingId,
    ],
    'intro' => [
        'title' => 'Introduction',
        'final' => $introFinal,
        'db_field' => 'intro_text',
        'placeholder' => 'Enter introduction text…',
        'default_id' => $defaultIntroId,
    ],
    'closing' => [
        'title' => 'Closing',
        'final' => $closingFinal,
        'db_field' => 'closing_text',
        'placeholder' => 'Enter closing remarks…',
        'default_id' => $defaultClosingId,
    ],
];

foreach ($sections as $sec => $data):
    $tpls = $templates[$sec] ?? [];
    $editorId = $sec . '_quill_editor';
    $wrapperId = $sec . '_quill_wrap';
?>
<div class="comm-section" id="<?= $sec ?>_section">
    <div class="comm-header">
        <div class="comm-title">
            <?= htmlspecialchars($data['title']) ?>
            <?php if ($isLocked): ?>
                <span title="Locked" style="margin-left:8px;color:#888;">🔒</span>
            <?php endif; ?>
        </div>
    </div>

    <div class="comm-controls">
        <select id="<?= $sec ?>_template_selector" class="comm-select" <?= $isLocked ? 'disabled' : '' ?>>
            <option value="0">-- Select saved <?= strtolower($data['title']) ?> template --</option>
            <?php
            if (!empty($tpls)) {
                foreach ($tpls as $t):
                    $tid = (int)($t['id'] ?? 0);
                    $tname = htmlspecialchars($t['name'] ?? '');
                    $tcontent = htmlspecialchars($t['content'] ?? '', ENT_QUOTES);
            ?>
                <option value="<?= $tid ?>" data-content="<?= $tcontent ?>" <?= ($tid === $data['default_id']) ? 'selected' : '' ?>>
                    <?= $tname ?>
                </option>
            <?php endforeach; } ?>
        </select>
    </div>

    <div id="<?= $wrapperId ?>" class="comm-quill-wrap<?= $isLocked ? ' is-locked' : '' ?>">
        <div id="<?= $editorId ?>"></div>
    </div>

    <textarea
        id="<?= $sec ?>_textarea"
        name="<?= $sec ?>"
        data-client-id="<?= (int)$clientId ?>"
        data-field="<?= $data['db_field'] ?>"
        style="display:none;"><?= htmlspecialchars($data['final']) ?></textarea>

    <div id="<?= $sec ?>_flash_container" class="comm-flash"></div>
</div>

<?php if (!defined('QUILL_JS_LOADED')): define('QUILL_JS_LOADED', true); ?>
<script src="https://cdnjs.cloudflare.com/ajax/libs/quill/1.3.7/quill.min.js"></script>
<?php endif; ?>

<script>
(function () {
    const SEC = <?= json_encode($sec) ?>;
    const DB_FIELD = <?= json_encode($data['db_field']) ?>;
    const CLIENT_ID = <?= json_encode((int)$clientId) ?>;
    const IS_LOCKED = <?= $isLocked ? 'true' : 'false' ?>;
    const PLACEHOLDER = <?= json_encode($data['placeholder']) ?>;
    const FINAL_HTML = <?= json_encode($data['final']) ?>;
    const IS_GREETING = (SEC === 'greeting');
    const CLIENT_FIRST_NAME = <?= json_encode($clientFirstName) ?>;
    const GREETING_NEEDS_AUTO_SAVE = <?= ($sec === 'greeting' && $greetingNeedsAutoSave) ? 'true' : 'false' ?>;

    const selector = document.getElementById(SEC + '_template_selector');
    const hiddenTA = document.getElementById(SEC + '_textarea');
    const flash = document.getElementById(SEC + '_flash_container');

    const quill = new Quill('#' + SEC + '_quill_editor', {
        theme: 'snow',
        readOnly: IS_LOCKED,
        placeholder: PLACEHOLDER,
        modules: { toolbar: false }
    });

    function syncToHidden() {
        hiddenTA.value = quill.root.innerHTML;
    }

    quill.on('text-change', syncToHidden);

    // Load final content (PHP already decided)
    (function loadFinalContent() {
        if (FINAL_HTML) {
            quill.clipboard.dangerouslyPasteHTML(FINAL_HTML);
        }
    })();

    // Auto-save if PHP built the greeting with name
    if (GREETING_NEEDS_AUTO_SAVE && !IS_LOCKED) {
        setTimeout(function() {
            saveToServer(quill.root.innerHTML, true);
        }, 300);
    }

    function showFlash(type, msg) {
        flash.innerHTML = '<div class="' + (type === 'success' ? 'flash-success' : 'flash-error') + '">'
            + (type === 'success' ? '✓ ' : '✗ ') + msg + '</div>';
        setTimeout(() => { flash.innerHTML = ''; }, 3500);
    }

    function normalize(html) {
        return html.replace(/\s+/g, ' ').trim();
    }

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

    let lastSaved = '';
    setTimeout(() => { lastSaved = quill.root.innerHTML; }, 0);
    let isSaving = false;

    quill.root.addEventListener('blur', function () {
        if (IS_LOCKED || isSaving) return;
        const html = quill.root.innerHTML;
        if (normalize(html) === normalize(lastSaved)) return;
        isSaving = true;
        saveToServer(html, false)
            .then(() => { lastSaved = html; })
            .finally(() => { isSaving = false; });
    });

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

    // Template selector - load template and append name for greeting
    if (selector) {
        selector.addEventListener('change', function () {
            const id = selector.value;
            if (!id || id === '0') return;

            const opt = selector.options[selector.selectedIndex];
            const tmp = document.createElement('div');
            tmp.innerHTML = opt.getAttribute('data-content') || '';
            let decoded = tmp.innerHTML;

            // For greeting, append client first name when loading template
            if (IS_GREETING && CLIENT_FIRST_NAME) {
                // Check if name already present
                const plain = strip_tags(decoded);
                if (stripos(plain, CLIENT_FIRST_NAME) === false) {
                    // Add name
                    decoded = rtrim(decoded, ' ,') + ' ' + CLIENT_FIRST_NAME + ',';
                }
            }

            quill.clipboard.dangerouslyPasteHTML(decoded);
            syncToHidden();
            showFlash('success', 'Template loaded.');

            if (!IS_LOCKED) {
                saveToServer(quill.root.innerHTML, true);
            }
        });
    }

    // Helper for strip_tags in JS
    function strip_tags(html) {
        const div = document.createElement('div');
        div.innerHTML = html;
        return div.textContent || div.innerText || '';
    }

    function stripos(haystack, needle) {
        return haystack.toLowerCase().indexOf(needle.toLowerCase()) !== -1;
    }
})();
</script>
<?php endforeach; ?>