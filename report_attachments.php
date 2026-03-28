<?php
// report_attachments.php
// Requires: $clientId, $canEditAttachments set in parent scope

// ==============================================
// 1. AJAX HANDLING (when called directly)
// ==============================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ajax_action'])) {
    require_once 'db_config.php';
    header('Content-Type: application/json');
    $clientId = (int)($_POST['client_id'] ?? 0);
    $ajax_action = $_POST['ajax_action'];

    if ($clientId <= 0) {
        echo json_encode(['success' => false, 'error' => 'Invalid Client ID']);
        exit;
    }

    try {
        $pdo = getPdo();

        // Always fetch current client name from clients table for validation
        $stmtClient = $pdo->prepare("SELECT name FROM clients WHERE id = :id LIMIT 1");
        $stmtClient->execute([':id' => $clientId]);
        $currentClientName = $stmtClient->fetchColumn();
        if (!$currentClientName) {
            echo json_encode(['success' => false, 'error' => 'Client not found with ID: ' . $clientId]);
            exit;
        }

        switch ($ajax_action) {
            case 'upload_attachment':
                $baseDir = __DIR__ . '/uploads/attachments/client_' . $clientId;
                if (!is_dir($baseDir)) mkdir($baseDir, 0777, true);

                $savedFiles = [];
                if (isset($_FILES['files']) && is_array($_FILES['files']['name'])) {
                    for ($i = 0; $i < count($_FILES['files']['name']); $i++) {
                        if ($_FILES['files']['error'][$i] === UPLOAD_ERR_OK) {
                            $rawName = basename($_FILES['files']['name'][$i]);
                            // Security check: ensure current client name is in filename
                            $normalizedFile   = strtolower(preg_replace('/[^a-zA-Z0-9]/', '', $rawName));
                            $normalizedClient = strtolower(preg_replace('/[^a-zA-Z0-9]/', '', $currentClientName));
                            if (strpos($normalizedFile, $normalizedClient) === false) {
                                $nameParts = preg_split('/\s+/', $currentClientName);
                                $partFound = false;
                                foreach ($nameParts as $part) {
                                    $normalizedPart = strtolower(preg_replace('/[^a-zA-Z0-9]/', '', $part));
                                    if (!empty($normalizedPart) && strpos($normalizedFile, $normalizedPart) !== false) {
                                        $partFound = true;
                                        break;
                                    }
                                }
                                if (!$partFound) {
                                    throw new Exception("❌ Security Alert: Filename must contain the client's name.");
                                }
                            }
                            $fileBase = preg_replace('/\.[^.]+$/', '', $rawName);
                            $fileName = preg_replace('/[^\w\s\._-]/u', '', $fileBase) . '.pdf';
                            $targetPath = $baseDir . '/' . $fileName;
                            if (move_uploaded_file($_FILES['files']['tmp_name'][$i], $targetPath)) {
                                $savedFiles[] = $fileName;
                                // Store client_name alongside client_id so re-used IDs can't leak files
                                $stmt = $pdo->prepare(
                                    "INSERT INTO report_attachments (client_id, file_name, client_name)
                                     VALUES (:client_id, :file_name, :client_name)"
                                );
                                $stmt->execute([
                                    ':client_id'   => $clientId,
                                    ':file_name'   => $fileName,
                                    ':client_name' => $currentClientName,
                                ]);
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

                // Verify the record belongs to the CURRENT client by both id AND name
                $stmtCheck = $pdo->prepare(
                    "SELECT id FROM report_attachments
                     WHERE client_id = :cid AND file_name = :file AND client_name = :cname
                     LIMIT 1"
                );
                $stmtCheck->execute([':cid' => $clientId, ':file' => $file, ':cname' => $currentClientName]);
                if (!$stmtCheck->fetch()) {
                    echo json_encode(['success' => false, 'error' => 'File not associated with this client.']);
                    exit;
                }

                $path = __DIR__ . "/uploads/attachments/client_$clientId/$file";
                $pdo->beginTransaction();
                try {
                    if (file_exists($path)) unlink($path);
                    $stmt = $pdo->prepare(
                        "DELETE FROM report_attachments
                         WHERE client_id = :cid AND file_name = :file AND client_name = :cname"
                    );
                    $stmt->execute([':cid' => $clientId, ':file' => $file, ':cname' => $currentClientName]);
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

                // Verify ownership by both client_id AND client_name
                $stmtCheck = $pdo->prepare(
                    "SELECT id FROM report_attachments
                     WHERE client_id = :cid AND file_name = :file AND client_name = :cname
                     LIMIT 1"
                );
                $stmtCheck->execute([':cid' => $clientId, ':file' => $old, ':cname' => $currentClientName]);
                if (!$stmtCheck->fetch()) {
                    echo json_encode(['success' => false, 'error' => 'File not associated with this client.']);
                    exit;
                }

                $dir     = __DIR__ . "/uploads/attachments/client_$clientId/";
                $oldPath = $dir . $old;
                $newPath = $dir . $new;
                if (!file_exists($oldPath)) {
                    echo json_encode(['success' => false, 'error' => 'File not found']);
                    exit;
                }
                if (file_exists($newPath)) {
                    echo json_encode(['success' => false, 'error' => 'File already exists']);
                    exit;
                }
                $pdo->beginTransaction();
                try {
                    rename($oldPath, $newPath);
                    $stmt = $pdo->prepare(
                        "UPDATE report_attachments
                         SET file_name = :new
                         WHERE client_id = :cid AND file_name = :old AND client_name = :cname"
                    );
                    $stmt->execute([
                        ':new'   => $new,
                        ':old'   => $old,
                        ':cid'   => $clientId,
                        ':cname' => $currentClientName,
                    ]);
                    $pdo->commit();
                    echo json_encode(['success' => true, 'new_name' => $new]);
                } catch (Throwable $e) {
                    $pdo->rollBack();
                    echo json_encode(['success' => false, 'error' => 'Rename failed: ' . $e->getMessage()]);
                }
                break;

            default:
                echo json_encode(['success' => false, 'error' => 'Unknown action']);
                break;
        }
        exit;
    } catch (Throwable $e) {
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        exit;
    }
}

// ==============================================
// 2. DISPLAY SECTION (when included in view_report.php)
// ==============================================
if (!isset($clientId)) {
    throw new Exception('clientId must be set before including report_attachments.php');
}
if (!isset($pdo)) {
    require_once 'db_config.php';
    $pdo = getPdo();
}

// Fetch the current client's name from the clients table
$stmtClientName = $pdo->prepare("SELECT name FROM clients WHERE id = :id LIMIT 1");
$stmtClientName->execute([':id' => $clientId]);
$currentClientName = $stmtClientName->fetchColumn();
if (!$currentClientName) {
    throw new Exception("Client not found for ID: $clientId");
}

if (!isset($isLocked)) {
    $stmt = $pdo->prepare("SELECT report_state, review_not_ok FROM clients WHERE id = ?");
    $stmt->execute([$clientId]);
    $clientLock  = $stmt->fetch(PDO::FETCH_ASSOC);
    $reportState = $clientLock['report_state'] ?? 'draft';
    $reviewNotOk = (int)($clientLock['review_not_ok'] ?? 0);
    $isLocked    = (($reportState === 'reviewed' && $reviewNotOk === 0) || $reportState === 'sent');
}

// KEY FIX: filter by BOTH client_id AND client_name to prevent ID-reuse leakage
$stmt = $pdo->prepare(
    "SELECT file_name FROM report_attachments
     WHERE client_id = :client_id AND client_name = :client_name
     ORDER BY id DESC"
);
$stmt->execute([':client_id' => $clientId, ':client_name' => $currentClientName]);
$existingFiles = $stmt->fetchAll(PDO::FETCH_COLUMN);
?>
<div class="card" style="margin-top: 20px; border-left: 4px solid #17a2b8; position:relative;">
    <label class="card-title" style="display:flex; align-items:center; justify-content:space-between;">
        <span>📂 Report Attachments</span>
    </label>

    <?php if ($reportState !== 'sent'): ?>
        <div style="margin-bottom: 15px; padding: 10px; background: #eefbff; border-radius: 4px;">
            <input type="file"
                   id="ajax_attachment_upload"
                   multiple
                   onchange="uploadAttachment()">
            <span id="upload_spinner"
                  style="display:none; margin-left: 10px; font-weight: bold; color: #0288D1;">
                ⏳ Uploading...
            </span>
        </div>
    <?php endif; ?>

    <ul id="attachment_list" style="list-style: none; padding: 0;">
        <?php if (empty($existingFiles)): ?>
            <!-- No attachments yet -->
        <?php else: ?>
            <?php foreach ($existingFiles as $file): ?>
                <li style="margin-bottom: 8px; border-bottom: 1px solid #eee; padding-bottom: 5px; display: flex; justify-content: space-between;"
                    data-filename="<?php echo htmlspecialchars($file); ?>">
                    <span>📎 <strong><?php echo htmlspecialchars($file); ?></strong></span>
                    <span class="annex-actions">
                        <a href="javascript:void(0)" class="annex-edit" data-filename="<?php echo htmlspecialchars($file); ?>">
                            <i class="fa-solid fa-pen-to-square"></i> Edit
                        </a>
                        <a href="javascript:void(0)" class="annex-delete" data-filename="<?php echo htmlspecialchars($file); ?>">
                            <i class="fa-solid fa-trash-can"></i> Delete
                        </a>
                    </span>
                </li>
            <?php endforeach; ?>
        <?php endif; ?>
    </ul>

    <p style="font-size: 11px; color: #666;">Note: Files uploaded here will be automatically attached to the final email.</p>

    <!-- Edit Modal -->
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
    </div>

    <script>
    const clientIdForAttachments = <?php echo (int)$clientId; ?>;

    function buildAttachmentLi(fileName) {
        const li = document.createElement('li');
        li.style.cssText = 'margin-bottom:8px; border-bottom:1px solid #eee; padding-bottom:5px; display:flex; justify-content:space-between;';
        li.setAttribute('data-filename', fileName);
        li.innerHTML =
            '<span>📎 <strong>' + fileName + '</strong></span>' +
            '<span class="annex-actions">' +
                '<a href="javascript:void(0)" class="annex-edit" data-filename="' + fileName + '">' +
                    '<i class="fa-solid fa-pen-to-square"></i> Edit' +
                '</a> ' +
                '<a href="javascript:void(0)" class="annex-delete" data-filename="' + fileName + '">' +
                    '<i class="fa-solid fa-trash-can"></i> Delete' +
                '</a>' +
            '</span>';
        return li;
    }

    function uploadAttachment() {
        const input   = document.getElementById('ajax_attachment_upload');
        const spinner = document.getElementById('upload_spinner');
        if (!input.files.length) return;
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
                if (data.success) {
                    input.value = '';
                    const list = document.getElementById('attachment_list');
                    data.files.forEach(fileName => list.appendChild(buildAttachmentLi(fileName)));
                } else {
                    alert('Upload failed: ' + (data.error || 'Unknown error'));
                }
            })
            .catch(error => {
                spinner.style.display = 'none';
                alert('Upload error: ' + error.message);
            });
    }

    function closeEditModal() {
        document.getElementById('editAttachmentModal').style.display = 'none';
    }

    function openEditModal(fileName) {
        const editForm = document.getElementById('editAttachmentForm');
        const newForm  = editForm.cloneNode(true);
        editForm.parentNode.replaceChild(newForm, editForm);

        document.getElementById('editOldAttachmentFileName').value = fileName;
        document.getElementById('editNewAttachmentFileName').value = fileName.replace(/\.pdf$/i, '');
        document.getElementById('editAttachmentModal').style.display = 'flex';
        document.getElementById('editAttachmentCancel').onclick = closeEditModal;

        setTimeout(() => {
            const inp = document.getElementById('editNewAttachmentFileName');
            inp.focus(); inp.select();
        }, 100);

        newForm.onsubmit = function(e) {
            e.preventDefault();
            const oldName      = document.getElementById('editOldAttachmentFileName').value;
            let   newNameInput = document.getElementById('editNewAttachmentFileName').value.trim();
            if (!oldName || !newNameInput) { alert('Please enter a new name.'); return; }
            if (/[\/\\:*?"<>|]/.test(newNameInput)) { alert('Filename contains invalid characters.'); return; }

            const existingNames = Array.from(document.querySelectorAll('#attachment_list li[data-filename]'))
                .map(li => li.dataset.filename.toLowerCase());
            const newNameWithPdf = newNameInput.toLowerCase().endsWith('.pdf')
                ? newNameInput.toLowerCase()
                : newNameInput.toLowerCase() + '.pdf';
            if (existingNames.includes(newNameWithPdf) && newNameWithPdf !== oldName.toLowerCase()) {
                alert('A file with this name already exists.');
                return;
            }

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
            .then(r => r.json().catch(() => ({ success: false, error: 'Invalid JSON' })))
            .then(data => {
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
                    const annexList = document.getElementById('annexures_list');
                    if (annexList) {
                        annexList.querySelectorAll('li[data-filename]').forEach(li => {
                            if (li.dataset.filename === oldName) {
                                li.dataset.filename = finalName;
                                const strong = li.querySelector('strong');
                                if (strong) strong.textContent = finalName;
                                li.querySelectorAll('a').forEach(a => a.setAttribute('data-filename', finalName));
                            }
                        });
                    }
                    closeEditModal();
                    if (typeof showToast === 'function') showToast('✓ Renamed to: ' + finalName);
                    else console.info('File renamed to: ' + finalName);
                } else {
                    alert('Error: ' + (data.error || 'Rename failed'));
                }
            })
            .catch(error => {
                console.error('Rename error:', error);
                alert('Rename error: ' + error.message);
            });
        };
    }

    document.addEventListener('DOMContentLoaded', function() {
        initAttachmentHandlers();
    });

    function initAttachmentHandlers() {
        document.addEventListener('click', function(e) {
            const target = e.target.closest('a');
            if (!target) return;
            if (!e.target.closest('#attachment_list')) return;

            if (target.classList.contains('annex-delete')) {
                e.preventDefault();
                const fileName = target.getAttribute('data-filename');
                if (!fileName) return;
                if (!confirm('Are you sure you want to delete ' + fileName + '?')) return;

                fetch('report_attachments.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
                    body: new URLSearchParams({
                        ajax_action: 'delete_attachment',
                        client_id:   clientIdForAttachments,
                        file_name:   fileName
                    })
                })
                .then(r => r.json().catch(() => ({ success: false, error: 'Invalid JSON' })))
                .then(data => {
                    if (data.success) {
                        const listItem = target.closest('li');
                        if (listItem) listItem.remove();
                        const annexList = document.getElementById('annexures_list');
                        if (annexList) {
                            annexList.querySelectorAll('li[data-filename]').forEach(li => {
                                if (li.dataset.filename === fileName) li.remove();
                            });
                        }
                    } else {
                        alert('Error: ' + (data.error || 'Delete failed'));
                    }
                })
                .catch(() => alert('Delete error.'));

            } else if (target.classList.contains('annex-edit')) {
                e.preventDefault();
                const fileName = target.getAttribute('data-filename');
                if (!fileName) return;
                openEditModal(fileName);
            }
        });

        document.getElementById('editAttachmentModal').addEventListener('click', function(e) {
            if (e.target === this) closeEditModal();
        });
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape' && document.getElementById('editAttachmentModal').style.display === 'flex') {
                closeEditModal();
            }
        });
        document.getElementById('editAttachmentCancel').onclick = closeEditModal;
    }
    </script>
</div>