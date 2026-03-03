<?php
// report_attachments.php
// Requires: $clientId, $canEditAttachments set in parent scope

// ==============================================
// 1. AJAX HANDLING (when called directly)
// ==============================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ajax_action'])) {
    require_once 'db_config.php';
    header('Content-Type: application/json');
    $clientId    = (int)($_POST['client_id'] ?? 0);
    $ajax_action = $_POST['ajax_action'];

    if ($clientId <= 0) {
        echo json_encode(['success' => false, 'error' => 'Invalid Client ID']);
        exit;
    }

    try {
        $pdo = getPdo();
        switch ($ajax_action) {
            case 'upload_attachment':
                $stmtClient = $pdo->prepare("SELECT name FROM clients WHERE id = :id LIMIT 1");
                $stmtClient->execute([':id' => $clientId]);
                $targetClientName = $stmtClient->fetchColumn();
                if (!$targetClientName) throw new Exception("Client not found with ID: " . $clientId);

                $baseDir = __DIR__ . '/uploads/attachments/client_' . $clientId;
                if (!is_dir($baseDir)) mkdir($baseDir, 0777, true);

                $savedFiles = [];
                if (isset($_FILES['files']) && is_array($_FILES['files']['name'])) {
                    for ($i = 0; $i < count($_FILES['files']['name']); $i++) {
                        if ($_FILES['files']['error'][$i] === UPLOAD_ERR_OK) {
                            $rawName          = basename($_FILES['files']['name'][$i]);
                            $normalizedFile   = strtolower(preg_replace('/[^a-zA-Z0-9]/', '', $rawName));
                            $normalizedClient = strtolower(preg_replace('/[^a-zA-Z0-9]/', '', $targetClientName));
                            if (strpos($normalizedFile, $normalizedClient) === false) {
                                $nameParts = preg_split('/\s+/', $targetClientName);
                                $partFound = false;
                                foreach ($nameParts as $part) {
                                    $normalizedPart = strtolower(preg_replace('/[^a-zA-Z0-9]/', '', $part));
                                    if (!empty($normalizedPart) && strpos($normalizedFile, $normalizedPart) !== false) {
                                        $partFound = true;
                                        break;
                                    }
                                }
                                if (!$partFound) throw new Exception("❌ Security Alert: Filename must contain the client's name.");
                            }
                            $fileBase   = preg_replace('/\.[^.]+$/', '', $rawName);
                            $fileName   = preg_replace('/[^\w\s\._-]/u', '', $fileBase) . '.pdf';
                            $targetPath = $baseDir . '/' . $fileName;
                            if (move_uploaded_file($_FILES['files']['tmp_name'][$i], $targetPath)) {
                                $savedFiles[] = $fileName;
                                $stmt = $pdo->prepare("INSERT INTO report_attachments (client_id, file_name) VALUES (:client_id, :file_name)");
                                $stmt->execute([':client_id' => $clientId, ':file_name' => $fileName]);
                            }
                        }
                    }
                }
                echo json_encode(['success' => true, 'files' => $savedFiles]);
                break;

            case 'delete_attachment':
                $file = basename($_POST['file_name']);
                if (!$clientId || !$file) {
                    echo json_encode(['success' => false, 'error' => 'Invalid params']);
                    exit;
                }
                $path = __DIR__ . "/uploads/attachments/client_$clientId/$file";
                $pdo->beginTransaction();
                try {
                    if (file_exists($path)) unlink($path);
                    $stmt = $pdo->prepare("DELETE FROM report_attachments WHERE client_id = :cid AND file_name = :file");
                    $stmt->execute([':cid' => $clientId, ':file' => $file]);
                    $pdo->commit();
                    echo json_encode(['success' => true]);
                } catch (Throwable $e) {
                    $pdo->rollBack();
                    echo json_encode(['success' => false, 'error' => 'Delete failed']);
                }
                break;

            case 'rename_attachment':
                $old = basename($_POST['old_name']);
                $new = basename($_POST['new_name']);
                if (!$clientId || !$old || !$new) {
                    echo json_encode(['success' => false, 'error' => 'Invalid params']);
                    exit;
                }
                if (!preg_match('/\.pdf$/i', $new)) $new .= '.pdf';

                $dir   = __DIR__ . "/uploads/attachments/client_$clientId/";
                $files = scandir($dir);

                if ($files === false) {
                    echo json_encode(['success' => false, 'error' => 'Unable to read directory', 'dir' => $dir]);
                    exit;
                }

                $matchedFile = null;
                foreach ($files as $f) {
                    if (strcasecmp($f, $old) === 0) { $matchedFile = $f; break; }
                }

                if (!$matchedFile) {
                    echo json_encode([
                        'success'            => false,
                        'error'              => 'File not found (case-sensitive mismatch possible)',
                        'requested_old_name' => $old,
                        'directory'          => $dir,
                        'files_in_directory' => array_values(array_diff($files, ['.', '..']))
                    ]);
                    exit;
                }

                $oldPath = $dir . $matchedFile;
                $newPath = $dir . $new;

                if (!file_exists($oldPath)) {
                    echo json_encode(['success' => false, 'error' => 'File exists in scan but not at computed path', 'computed_old_path' => $oldPath]);
                    exit;
                }
                if (file_exists($newPath)) {
                    echo json_encode(['success' => false, 'error' => 'Target filename already exists', 'target_path' => $newPath]);
                    exit;
                }
                if (!rename($oldPath, $newPath)) {
                    echo json_encode(['success' => false, 'error' => 'Rename function failed', 'old_path' => $oldPath, 'new_path' => $newPath]);
                    exit;
                }

                $stmt = $pdo->prepare("UPDATE report_attachments SET file_name = :new WHERE client_id = :cid AND file_name = :old");
                $stmt->execute([':new' => $new, ':old' => $matchedFile, ':cid' => $clientId]);

                echo json_encode([
                    'success'  => true,
                    'message'  => 'File renamed successfully',
                    'old_name' => $matchedFile,
                    'new_name' => $new,
                    'old_path' => $oldPath,
                    'new_path' => $newPath
                ]);
                exit;
        }
    } catch (Throwable $e) {
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

// ==============================================
// 2. DISPLAY SECTION (included in view_report.php)
// ==============================================
if (!isset($clientId)) {
    throw new Exception('clientId must be set before including report_attachments.php');
}
if (!isset($pdo)) {
    require_once 'db_config.php';
    $pdo = getPdo();
}
if (!isset($isLocked)) {
    $stmt        = $pdo->prepare("SELECT report_state, review_not_ok FROM clients WHERE id = ?");
    $stmt->execute([$clientId]);
    $clientLock  = $stmt->fetch(PDO::FETCH_ASSOC);
    $reportState = $clientLock['report_state'] ?? 'draft';
    $reviewNotOk = (int)($clientLock['review_not_ok'] ?? 0);
    $isLocked    = (($reportState === 'reviewed' && $reviewNotOk === 0) || $reportState === 'sent');
}

$stmt = $pdo->prepare("SELECT file_name FROM report_attachments WHERE client_id = :client_id ORDER BY uploaded_at DESC, id DESC");
$stmt->execute([':client_id' => $clientId]);
$existingFiles = $stmt->fetchAll(PDO::FETCH_COLUMN);
?>

<!-- ============================================================
     ATTACHMENT CARD
     NOTE: The edit modal is NOT inside this div.
     It is injected directly into <body> by JS (ensureModalInDOM).
     This prevents ANY innerHTML wipe of an ancestor element from
     removing the modal — which caused:
       "editAttachmentForm not found in DOM" @ view_report.php:1507
     ============================================================ -->
<div class="card" style="margin-top:20px; border-left:4px solid #17a2b8; position:relative;">
    <label class="card-title" style="display:flex; align-items:center; justify-content:space-between;">
        <span>📂 Report Attachments</span>
    </label>

    <?php if ($reportState !== 'sent'): ?>
        <div style="margin-bottom:15px; padding:10px; background:#eefbff; border-radius:4px;">
            <input type="file" id="ajax_attachment_upload" multiple onchange="uploadAttachment()">
            <span id="upload_spinner" style="display:none; margin-left:10px; font-weight:bold; color:#0288D1;">
                ⏳ Uploading...
            </span>
        </div>
    <?php endif; ?>

    <ul id="attachment_list" style="list-style:none; padding:0;">
        <?php if (empty($existingFiles)): ?>
            <li style="color:#777; font-style:italic;">No attachments uploaded yet.</li>
        <?php else: ?>
            <?php foreach ($existingFiles as $file): ?>
                <li style="margin-bottom:8px; border-bottom:1px solid #eee; padding-bottom:5px; display:flex; justify-content:space-between;"
                    data-filename="<?php echo htmlspecialchars($file); ?>">
                    <span>📎 <strong><?php echo htmlspecialchars($file); ?></strong></span>
                    <span class="annex-actions">
                        <a href="#" class="annex-edit"   data-filename="<?php echo htmlspecialchars($file); ?>"><i class="fa-solid fa-pen-to-square"></i> Edit</a>
                        <a href="#" class="annex-delete" data-filename="<?php echo htmlspecialchars($file); ?>"><i class="fa-solid fa-trash-can"></i> Delete</a>
                    </span>
                </li>
            <?php endforeach; ?>
        <?php endif; ?>
    </ul>

    <p style="font-size:11px; color:#666;">Note: Files uploaded here will be automatically attached to the final email.</p>
</div>
<!-- Modal is intentionally outside the card div above -->

<script>
(function () {
    const clientIdForAttachments = <?php echo (int)$clientId; ?>;
    const isLockedAttachments    = <?php echo $isLocked ? 'true' : 'false'; ?>;

    // ----------------------------------------------------------------
    // KEY FIX: Modal injected into <body>, never inside any card/form/
    // container that could be wiped. Idempotent — safe to call again.
    // ----------------------------------------------------------------
    function ensureModalInDOM() {
        if (document.getElementById('editAttachmentModal')) return;

        document.body.insertAdjacentHTML('beforeend', `
            <div id="editAttachmentModal" style="display:none; position:fixed; top:0; left:0; width:100vw; height:100vh; background:rgba(0,0,0,0.3); z-index:9999; justify-content:center; align-items:center;">
                <div style="background:#fff; padding:30px 25px; border-radius:8px; min-width:320px; max-width:90vw; box-shadow:0 4px 16px rgba(0,0,0,0.15);">
                    <h3 style="margin-top:0;">Rename Attachment</h3>
                    <form id="editAttachmentForm" onsubmit="return false;">
                        <input type="hidden" id="editOldAttachmentFileName">
                        <label for="editNewAttachmentFileName">New Name:</label>
                        <input type="text" id="editNewAttachmentFileName" style="width:100%; margin-bottom:15px; padding:8px; font-size:15px;">
                        <div style="text-align:right;">
                            <button type="button" id="editAttachmentCancel" style="margin-right:10px; padding:7px 18px;">Cancel</button>
                            <button type="submit" style="padding:7px 18px; background:#0288D1; color:#fff; border:none; border-radius:4px;">Save</button>
                        </div>
                    </form>
                </div>
            </div>`
        );
    }

    // ----------------------------------------------------------------
    // Upload (global — called from inline onchange="uploadAttachment()")
    // ----------------------------------------------------------------
    window.uploadAttachment = function () {
        const input   = document.getElementById('ajax_attachment_upload');
        const spinner = document.getElementById('upload_spinner');
        if (!input || !input.files.length) return;

        spinner.style.display = 'inline';
        const formData = new FormData();
        formData.append('ajax_action', 'upload_attachment');
        formData.append('client_id', clientIdForAttachments);
        for (let i = 0; i < input.files.length; i++) {
            formData.append('files[]', input.files[i]);
        }

        fetch('report_attachments.php', { method: 'POST', body: formData })
            .then(r => r.json())
            .then(data => {
                spinner.style.display = 'none';
                if (data.success) { input.value = ''; location.reload(); }
                else alert('Upload failed: ' + (data.error || 'Unknown error'));
            })
            .catch(err => {
                spinner.style.display = 'none';
                alert('Upload error: ' + err.message);
            });
    };

    // ----------------------------------------------------------------
    // Init — wires all handlers after DOM ready
    // ----------------------------------------------------------------
    function init() {
        ensureModalInDOM();

        document.getElementById('editAttachmentCancel').onclick = function () {
            document.getElementById('editAttachmentModal').style.display = 'none';
        };

        document.getElementById('editAttachmentModal').addEventListener('click', function (e) {
            if (e.target === this) this.style.display = 'none';
        });

        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape') {
                const m = document.getElementById('editAttachmentModal');
                if (m && m.style.display === 'flex') m.style.display = 'none';
            }
        });

        // Delegated handler for delete + edit clicks
        document.addEventListener('click', function (e) {
            const target = e.target.closest('a');
            if (!target) return;
            if (!target.closest('#attachment_list')) return;

            // ---- DELETE ----
            if (target.classList.contains('annex-delete')) {
                e.preventDefault();
                const fileName = target.getAttribute('data-filename');
                if (!fileName) return;
                if (!confirm('Are you sure you want to delete ' + fileName + '?')) return;

                fetch('report_attachments.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
                    body: new URLSearchParams({ ajax_action: 'delete_attachment', client_id: clientIdForAttachments, file_name: fileName })
                })
                .then(r => r.json().catch(() => ({ success: false, error: 'Invalid JSON' })))
                .then(data => {
                    if (data.success) {
                        const li = target.closest('li');
                        if (li) li.remove();
                        const list = document.getElementById('attachment_list');
                        if (list && list.querySelectorAll('li[data-filename]').length === 0) {
                            list.innerHTML = '<li style="color:#777; font-style:italic;">No attachments uploaded yet.</li>';
                        }
                    } else {
                        alert('Error: ' + (data.error || 'Delete failed'));
                    }
                })
                .catch(() => alert('Delete error.'));
            }

            // ---- EDIT (RENAME) ----
            else if (target.classList.contains('annex-edit')) {
                e.preventDefault();
                const fileName = target.getAttribute('data-filename');
                if (!fileName) return;

                // Safety net in case modal was somehow removed
                ensureModalInDOM();

                const oldForm = document.getElementById('editAttachmentForm');
                if (!oldForm) {
                    console.error('editAttachmentForm not found even after ensureModalInDOM() — this should never happen.');
                    return;
                }

                // Clone to clear any previous onsubmit handler
                const newForm = oldForm.cloneNode(true);
                oldForm.parentNode.replaceChild(newForm, oldForm);

                // Re-fetch by ID after cloneNode (stale vars point to detached nodes)
                document.getElementById('editOldAttachmentFileName').value = fileName;
                document.getElementById('editNewAttachmentFileName').value = fileName.replace(/\.pdf$/i, '');

                document.getElementById('editAttachmentModal').style.display = 'flex';

                setTimeout(() => {
                    const inp = document.getElementById('editNewAttachmentFileName');
                    if (inp) { inp.focus(); inp.select(); }
                }, 100);

                // Re-wire cancel on cloned element
                document.getElementById('editAttachmentCancel').onclick = function () {
                    document.getElementById('editAttachmentModal').style.display = 'none';
                };

                // Submit handler on the new form
                newForm.onsubmit = function (e) {
                    e.preventDefault();

                    const oldName      = document.getElementById('editOldAttachmentFileName').value;
                    const newNameInput = document.getElementById('editNewAttachmentFileName').value.trim();

                    if (!oldName || !newNameInput) { alert('Please enter a new name.'); return; }
                    if (/[\/\\:*?"<>|]/.test(newNameInput)) { alert('Filename contains invalid characters.'); return; }

                    const existingNames  = Array.from(document.querySelectorAll('#attachment_list li[data-filename]'))
                        .map(li => li.dataset.filename.toLowerCase());
                    const newNameWithPdf = newNameInput.toLowerCase().endsWith('.pdf')
                        ? newNameInput.toLowerCase()
                        : newNameInput.toLowerCase() + '.pdf';

                    if (existingNames.includes(newNameWithPdf)) { alert('A file with this name already exists.'); return; }

                    fetch('report_attachments.php', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
                        body: new URLSearchParams({
                            ajax_action: 'rename_attachment',
                            client_id:   clientIdForAttachments,
                            old_name:    oldName,
                            new_name:    newNameInput
                        })
                    })
                    .then(r => r.text())
                    .then(text => {
                        console.log('Raw rename response:', text);
                        let data;
                        try { data = JSON.parse(text); }
                        catch (err) { console.error('JSON parse error:', err); alert('Server returned invalid JSON.\nCheck console.'); return; }

                        if (data.success) {
                            const finalName = data.new_name;
                            document.querySelectorAll('#attachment_list li[data-filename]').forEach(li => {
                                if (li.dataset.filename === oldName) {
                                    li.dataset.filename = finalName;
                                    const strong = li.querySelector('strong');
                                    if (strong) strong.textContent = finalName;
                                    li.querySelectorAll('a').forEach(a => a.setAttribute('data-filename', finalName));
                                }
                            });
                            document.getElementById('editAttachmentModal').style.display = 'none';
                            alert('Rename successful!');
                        } else {
                            alert('Rename failed: ' + (data.error || 'Unknown error'));
                        }
                    })
                    .catch(err => { console.error('Rename fetch error:', err); alert('Network error during rename.'); });
                };
            }
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }

})();
</script>