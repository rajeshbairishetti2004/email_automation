<?php
// rationale.php
// Complete standalone template management for rationale section
// FIXED: Uses default template from report_templates table, ignores stale stored text

// Handle AJAX requests for template management AND workflow actions
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

            $response['success']          = true;
            $response['new_id']           = $newId;
            $response['template_name']    = $name;
            $response['template_content'] = $content;
        } elseif ($action === 'save_user_template') {
            $templateId = (int)($_POST['template_id_to_update'] ?? 0);
            $name       = trim($_POST['template_name']    ?? '');
            $content    = $_POST['template_content'] ?? '';

            if ($name === '' || $content === '') {
                throw new Exception('Template name and content are required');
            }

            if ($templateId > 0) {
                $stmt = $pdo->prepare("UPDATE report_templates SET name = ?, content = ? WHERE id = ? AND section_type = 'rationale'");
                $stmt->execute([$name, $content, $templateId]);
            } else {
                $stmt = $pdo->prepare("INSERT INTO report_templates (name, section_type, content) VALUES (?, 'rationale', ?)");
                $stmt->execute([$name, $content]);
                $templateId = $pdo->lastInsertId();
            }

            $response['success']     = true;
            $response['template_id'] = $templateId;
        } elseif ($action === 'delete_user_template') {
            $templateId = (int)($_POST['template_id'] ?? 0);
            if ($templateId <= 0) throw new Exception('Invalid template ID');
            $stmt = $pdo->prepare("DELETE FROM report_templates WHERE id = ? AND section_type = 'rationale'");
            $stmt->execute([$templateId]);
            $response['success'] = true;
        }
        // Workflow actions
        elseif ($action === 'save_draft') {
            $clientId = (int)($_POST['client_id'] ?? 0);
            if ($clientId <= 0) throw new Exception("Invalid Client ID.");
            $pdo->prepare("UPDATE clients SET report_state = 'draft', draft_at = NOW(), review_not_ok = 0, review_comment = NULL WHERE id = :id")->execute([':id' => $clientId]);
            echo json_encode(['success' => true, 'message' => 'Draft saved successfully.', 'updated_state' => 'draft']);
            exit;
        } elseif ($action === 'ready_for_review') {
            $clientId = (int)($_POST['client_id'] ?? 0);
            if ($clientId <= 0) throw new Exception("Invalid Client ID.");
            $pdo->prepare("UPDATE clients SET report_state = 'ready', ready_at = NOW(), review_not_ok = 0, review_comment = NULL WHERE id = :id")->execute([':id' => $clientId]);
            echo json_encode(['success' => true, 'message' => 'Report marked Ready for Review.', 'updated_state' => 'ready']);
            exit;
        } elseif ($action === 'approve_review') {
            $clientId = (int)($_POST['client_id'] ?? 0);
            if ($clientId <= 0) throw new Exception("Invalid Client ID.");
            $pdo->prepare("UPDATE clients SET report_state = 'reviewed', reviewed_at = NOW(), review_not_ok = 0, review_comment = NULL WHERE id = :id")->execute([':id' => $clientId]);
            echo json_encode(['success' => true, 'message' => 'Report Approved (Reviewed).', 'updated_state' => 'reviewed']);
            exit;
        } elseif ($action === 'review_not_ok') {
            $clientId = (int)($_POST['client_id'] ?? 0);
            $comment  = trim($_POST['review_comment'] ?? '');
            if ($clientId <= 0) throw new Exception("Invalid Client ID.");
            if (empty($comment)) throw new Exception("A comment is required for rejection.");
            $pdo->prepare("UPDATE clients SET report_state = 'draft', review_not_ok = 1, review_comment = :comment WHERE id = :id")->execute([':id' => $clientId, ':comment' => $comment]);
            echo json_encode(['success' => true, 'message' => 'Report rejected and moved back to Draft.', 'updated_state' => 'draft']);
            exit;
        } elseif ($action === 'email_sent') {
            $clientId = (int)($_POST['client_id'] ?? 0);
            if ($clientId <= 0) throw new Exception("Invalid Client ID.");
            $pdo->prepare("UPDATE clients SET report_state = 'sent', sent_at = NOW() WHERE id = :id")->execute([':id' => $clientId]);
            echo json_encode(['success' => true, 'message' => 'Report marked as Sent.', 'updated_state' => 'sent']);
            exit;
        }
        // Auto-save rationale HTML
        elseif ($action === 'save_rationale') {
            $clientId = (int)($_POST['client_id'] ?? 0);
            $value    = $_POST['value'] ?? '';
            if ($clientId <= 0) throw new Exception("Invalid Client ID.");
            $pdo->prepare("UPDATE clients SET rationale_text = ? WHERE id = ?")->execute([$value, $clientId]);
            echo json_encode(['success' => true, 'message' => 'Rationale saved successfully.']);
            exit;
        } elseif ($action === 'autosave_rationale') {
            $clientId = (int)($_POST['client_id'] ?? 0);
            $value    = $_POST['value'] ?? '';
            if ($clientId <= 0) throw new Exception("Invalid Client ID.");
            $pdo->prepare("UPDATE clients SET rationale_text = :val WHERE id = :id")->execute([':val' => $value, ':id' => $clientId]);
            echo json_encode(['success' => true]);
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

// Fetch stored rationale text from DB
if (!isset($rationaleText) || $rationaleText === '') {
    require_once 'db_config.php';
    $pdo = getPdo();
    $stmt = $pdo->prepare("SELECT rationale_text FROM clients WHERE id = ?");
    $stmt->execute([$clientId]);
    $rationaleText = $stmt->fetchColumn() ?? '';
}

// ── PHP SIDE DECISION: ALWAYS use default template from report_templates ──────
// Renamed function to avoid conflict with client_communication.php
function _rat_strip_html_to_plain($html) {
    return trim(preg_replace('/\s+/', ' ', strip_tags($html)));
}

// Get default template content from report_templates table
$defaultTemplateHtml = '';
$defaultTemplateId = 0;

if (!empty($templates['rationale'] ?? [])) {
    // Find template marked as default
    foreach ($templates['rationale'] as $t) {
        if (!empty($t['is_default']) && (int)$t['is_default'] === 1) {
            $defaultTemplateId = (int)$t['id'];
            $defaultTemplateHtml = $t['content'] ?? '';
            break;
        }
    }
    // If no default found, use first template
    if ($defaultTemplateId === 0 && !empty($templates['rationale'][0])) {
        $defaultTemplateId = (int)$templates['rationale'][0]['id'];
        $defaultTemplateHtml = $templates['rationale'][0]['content'] ?? '';
    }
}

// Stale text to ignore - these are the hardcoded defaults that should be replaced
$STALE_DEFAULTS = [
    'Rationale for recommendations',
    'Rationale',
    'Enter rationale for recommendations…',
    ''
];

$storedPlain = _rat_strip_html_to_plain($rationaleText);

// ALWAYS use default template if:
// 1. Stored is empty OR
// 2. Stored matches any stale default text
if (in_array($storedPlain, $STALE_DEFAULTS) || $storedPlain === '') {
    $finalContent = $defaultTemplateHtml;
} else {
    // User has actual custom content, keep it
    $finalContent = $rationaleText;
}
?>

<!-- Quill CSS -->
<?php if (!defined('QUILL_CSS_LOADED')): define('QUILL_CSS_LOADED', true); ?>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/quill/1.3.7/quill.snow.min.css" rel="stylesheet">
<?php endif; ?>

<style>
    /* ── Rationale module styles ── */
    .rat-box {
        margin-top: 18px;
        margin-bottom: 18px;
        padding: 14px;
        border: 1px solid #e6f2fb;
        border-radius: 8px;
        background: linear-gradient(180deg, #fbfdff 0%, #f6fbff 100%);
        box-shadow: 0 1px 0 rgba(2, 136, 209, 0.03);
        font-family: Inter, Arial, sans-serif;
    }

    .rat-header {
        display: flex;
        justify-content: space-between;
        align-items: center;
        margin-bottom: 12px;
        padding-bottom: 8px;
        border-bottom: 1px solid #e6f2fb;
    }

    .rat-title {
        font-weight: 700;
        color: #083744;
        font-size: 16px;
        margin: 0;
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
    }

    .rat-quill-wrap {
        border: 1px solid #dbeefb;
        border-radius: 6px;
        overflow: hidden;
        background: #fff;
        transition: border-color 0.15s, box-shadow 0.15s;
    }

    .rat-quill-wrap:focus-within {
        border-color: #0288d1;
        box-shadow: 0 0 0 3px rgba(2, 136, 209, 0.10);
    }

    .rat-quill-wrap .ql-toolbar.ql-snow {
        border: none;
        border-bottom: 1px solid #e6f2fb;
        background: #f5fbff;
        padding: 6px 10px;
    }

    .rat-quill-wrap .ql-container.ql-snow {
        border: none;
    }

    .rat-quill-wrap .ql-editor {
        min-height: 140px;
        max-height: 500px;
        overflow-y: auto;
        font-family: Inter, Arial, sans-serif;
        font-size: 14px;
        color: #052b36;
        line-height: 1.6;
        padding: 10px 12px;
    }

    .rat-quill-wrap .ql-editor.ql-blank::before {
        font-style: normal;
        color: #aac6d8;
        font-size: 13px;
    }

    .rat-quill-wrap.is-locked .ql-toolbar {
        display: none;
    }

    .rat-quill-wrap.is-locked .ql-editor {
        background: #f8fbff;
        cursor: default;
    }

    .rat-flash {
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

    @media (max-width: 640px) {
        .rat-controls {
            flex-direction: column;
            align-items: stretch;
        }
        .rat-select {
            width: 100%;
        }
    }
</style>

<?php
$clientId = $clientId ?? 0;
$isLocked = $isLocked ?? false;
$templates = $templates ?? [];
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
            <?php
            if (!empty($templates['rationale'] ?? [])) {
                foreach ($templates['rationale'] as $t):
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
            <?php
                endforeach;
            }
            ?>
        </select>
    </div>

    <!-- Quill editor -->
    <div id="rationale_quill_wrap" class="rat-quill-wrap<?= $isLocked ? ' is-locked' : '' ?>">
        <div id="rationale_quill_editor"></div>
    </div>

    <!-- Hidden textarea with FINAL content (PHP already decided) -->
    <textarea
        id="rationale_textarea"
        name="rationale_text"
        class="comm-textarea large-textarea rat-main-textarea"
        data-client-id="<?= (int)$clientId ?>"
        data-field="rationale_text"
        style="display:none;"
        <?= $isLocked ? 'readonly' : '' ?>><?= htmlspecialchars($finalContent) ?></textarea>

    <div id="rationale_flash_container" class="comm-flash"></div>
</div>

<!-- Quill JS -->
<?php if (!defined('QUILL_JS_LOADED')): define('QUILL_JS_LOADED', true); ?>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/quill/1.3.7/quill.min.js"></script>
<?php endif; ?>

<script>
    (function() {
        const CLIENT_ID = <?= json_encode((int)$clientId) ?>;
        const IS_LOCKED = <?= $isLocked ? 'true' : 'false' ?>;

        const selector = document.getElementById('rationale_template_selector');
        const hiddenTA = document.getElementById('rationale_textarea');
        const flash = document.getElementById('rationale_flash_container');

        const quill = new Quill('#rationale_quill_editor', {
            theme: 'snow',
            readOnly: IS_LOCKED,
            placeholder: 'Enter rationale for recommendations…',
            modules: { toolbar: false }
        });

        quill.on('text-change', function() {
            syncToHidden();
        });

        // Load final content (PHP already decided)
        (function loadFinalContent() {
            const final = hiddenTA.value;
            if (final) {
                quill.clipboard.dangerouslyPasteHTML(final);
            }
        })();

        function syncToHidden() {
            hiddenTA.value = quill.root.innerHTML;
        }

        let lastSaved = quill.root.innerHTML;

        quill.root.addEventListener('blur', function() {
            if (IS_LOCKED) return;
            const html = quill.root.innerHTML;
            if (html === lastSaved) return;
            performSave(html);
        });

        function performSave(html) {
            fetch('rationale.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
                body: new URLSearchParams({
                    ajax_action: 'save_rationale',
                    client_id: CLIENT_ID,
                    value: html
                })
            })
            .then(r => r.json())
            .then(d => {
                if (d.success) {
                    lastSaved = html;
                    showFlash('success', d.message || 'Saved');
                } else showFlash('error', 'Save failed: ' + (d.error || 'Unknown'));
            })
            .catch(() => showFlash('error', 'Network error while saving'));
        }

        if (!IS_LOCKED && CLIENT_ID) {
            setInterval(function() {
                const html = quill.root.innerHTML;
                if (html !== lastSaved) performSave(html);
            }, 15000);
        }

        function showFlash(type, msg) {
            flash.innerHTML = '<div class="' + (type === 'success' ? 'flash-success' : 'flash-error') + '">' +
                (type === 'success' ? '✓ ' : '✗ ') + msg + '</div>';
            setTimeout(() => { flash.innerHTML = ''; }, 3500);
        }

        // Template selector
        selector.addEventListener('change', function() {
            const id = selector.value;
            if (!id || id === '0') return;
            const opt = selector.options[selector.selectedIndex];
            const tmp = document.createElement('div');
            tmp.innerHTML = opt.getAttribute('data-content') || '';
            quill.clipboard.dangerouslyPasteHTML(tmp.innerHTML);
            syncToHidden();
            showFlash('success', 'Template loaded.');

            if (!IS_LOCKED) performSave(quill.root.innerHTML);
        });

    })();
</script>