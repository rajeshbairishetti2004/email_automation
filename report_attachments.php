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
                            $rawName = basename($_FILES['files']['name'][$i]);
                            // Security check: ensure client name is in filename
                            $normalizedFile = strtolower(preg_replace('/[^a-zA-Z0-9]/', '', $rawName));
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
                            $fileBase = preg_replace('/\.[^.]+$/', '', $rawName);
                            $fileName = preg_replace('/[^\w\s\._-]/u', '', $fileBase) . '.pdf';
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
                    echo json_encode(['success'=>false,'error'=>'Invalid params']);
                    exit;
                }
                $path = __DIR__ . "/uploads/attachments/client_$clientId/$file";
                $pdo->beginTransaction();
                try {
                    if (file_exists($path)) {
                        unlink($path);
                    }
                    $stmt = $pdo->prepare("DELETE FROM report_attachments WHERE client_id = :cid AND file_name = :file");
                    $stmt->execute([':cid' => $clientId, ':file' => $file]);
                    $pdo->commit();
                    echo json_encode(['success'=>true]);
                } catch (Throwable $e) {
                    $pdo->rollBack();
                    echo json_encode(['success'=>false,'error'=>'Delete failed']);
                }
                break;

            case 'rename_attachment':
                $old = basename($_POST['old_name']);
                $new = basename($_POST['new_name']);
                if (!$clientId || !$old || !$new) {
                    echo json_encode(['success'=>false,'error'=>'Invalid params']);
                    exit;
                }
                if (!preg_match('/\.pdf$/i', $new)) {
                    $new .= '.pdf';
                }
                $dir = __DIR__ . "/uploads/attachments/client_$clientId/";
                $oldPath = $dir . $old;
                $newPath = $dir . $new;
                if (!file_exists($oldPath)) {
                    echo json_encode(['success'=>false,'error'=>'File not found']);
                    exit;
                }
                if (file_exists($newPath)) {
                    echo json_encode(['success'=>false,'error'=>'File already exists']);
                    exit;
                }
                $pdo->beginTransaction();
                try {
                    rename($oldPath, $newPath);
                    $stmt = $pdo->prepare("UPDATE report_attachments SET file_name = :new WHERE client_id = :cid AND file_name = :old");
                    $stmt->execute([':new' => $new, ':old' => $old, ':cid' => $clientId]);
                    $pdo->commit();
                    echo json_encode(['success'=>true,'new_name'=>$new]);
                } catch (Throwable $e) {
                    $pdo->rollBack();
                    echo json_encode(['success'=>false,'error'=>'Rename failed: ' . $e->getMessage()]);
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
if (!isset($isLocked)) {
    $stmt = $pdo->prepare("SELECT report_state, review_not_ok FROM clients WHERE id = ?");
    $stmt->execute([$clientId]);
    $clientLock = $stmt->fetch(PDO::FETCH_ASSOC);
    $reportState = $clientLock['report_state'] ?? 'draft';
    $reviewNotOk = (int)($clientLock['review_not_ok'] ?? 0);
    $isLocked = (($reportState === 'reviewed' && $reviewNotOk === 0) || $reportState === 'sent');
}
$stmt = $pdo->prepare("SELECT file_name FROM report_attachments WHERE client_id = :client_id ORDER BY uploaded_at DESC, id DESC");
$stmt->execute([':client_id' => $clientId]);
$existingFiles = $stmt->fetchAll(PDO::FETCH_COLUMN);
?>
<div class="card" style="margin-top: 20px; border-left: 4px solid #17a2b8; position:relative;">
    <label class="card-title" style="display:flex; align-items:center; justify-content:space-between;">
      <span>📂 Report Attachments 
      </span>
      <!-- <button type="button" id="refreshAttachments" class="refresh-icon-btn" title="Clear attachments" style="margin-left:auto; background:transparent; border:none; outline:none; cursor:pointer; padding:4px; z-index:100; display:flex; align-items:center; justify-content:center;" <?= $isLocked ? 'disabled style="opacity:0.5;pointer-events:none;"' : '' ?>>
        <span class="refresh-svg-icon" id="refreshAttachmentsIcon">
          <svg width="28" height="28" viewBox="0 0 32 32" fill="none" xmlns="http://www.w3.org/2000/svg">
            <path d="M23.5 8.5A11 11 0 1 0 27 16" stroke="#0288D1" stroke-width="2.2" fill="none" stroke-linecap="round"/>
            <polygon points="27,16 23,13.5 23,18.5" fill="#0288D1"/>
            <path d="M8.5 23.5A11 11 0 1 0 5 16" stroke="#0288D1" stroke-width="2.2" fill="none" stroke-linecap="round"/>
            <polygon points="5,16 9,18.5 9,13.5" fill="#0288D1"/>
          </svg>
        </span>
      </button> -->
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
            <!-- <li style="color: #777; font-style: italic;">No attachments uploaded yet.</li> -->
        <?php else: ?>
            <?php foreach ($existingFiles as $file): ?>
                <li style="margin-bottom: 8px; border-bottom: 1px solid #eee; padding-bottom: 5px; display: flex; justify-content: space-between;" data-filename="<?php echo htmlspecialchars($file); ?>">
                    <span>📎 <strong><?php echo htmlspecialchars($file); ?></strong></span>
<span class="annex-actions">
    <a href="#" class="annex-edit" data-filename="<?php echo htmlspecialchars($file); ?>">
        <i class="fa-solid fa-pen-to-square"></i> Edit
    </a>
    <a href="#" class="annex-delete" data-filename="<?php echo htmlspecialchars($file); ?>">
        <i class="fa-solid fa-trash-can"></i> Delete
    </a>
</span>

                </li>
            <?php endforeach; ?>
        <?php endif; ?>
    </ul>
    <p style="font-size: 11px; color: #666;">Note: Files uploaded here will be automatically attached to the final email.</p>

    <!-- <style>
    .refresh-icon-btn .refresh-svg-icon { display:inline-block; vertical-align:middle; }
    .refresh-icon-btn .refresh-svg-icon.rotating { animation: refresh-rotate 0.6s linear; }
    @keyframes refresh-rotate { 100% { transform: rotate(360deg); } }
    .refresh-icon-btn { background:transparent; border:none; outline:none; cursor:pointer; padding:4px; z-index:100; display:flex; align-items:center; justify-content:center; box-shadow:none; border-radius:50%; transition:background 0.15s; }
    .refresh-icon-btn:hover { background:rgba(2,136,209,0.08); }
    </style> -->
    <!-- Edit Modal HTML -->
    <div id="editAttachmentModal" style="display:none; position:fixed; top:0; left:0; width:100vw; height:100vh; background:rgba(0,0,0,0.3); z-index:9999; justify-content:center; align-items:center;">
      <div style="background:#fff; padding:30px 25px; border-radius:8px; min-width:320px; max-width:90vw; box-shadow:0 4px 16px rgba(0,0,0,0.15);">
        <h3 style="margin-top:0;">Rename Attachment</h3>
        <form id="editAttachmentForm" onsubmit="return false;">
          <input type="hidden" id="editOldAttachmentFileName">
          <label for="editNewAttachmentFileName">New Name:</label>
          <input type="text" id="editNewAttachmentFileName" style="width:100%; margin-bottom:15px; padding:8px; font-size:15px;">
          <div style="text-align:right;">
            <button type="button" id="editAttachmentCancel" style="margin-right:10px; padding: 7px 18px;">Cancel</button>
            <button type="submit" style="padding:7px 18px; background:#0288D1; color:#fff; border:none; border-radius:4px;">Save</button>
          </div>
        </form>
      </div>
    </div>
    
    <script>
    // Attachment-specific JavaScript functions
    const clientIdForAttachments = <?php echo (int)$clientId; ?>;
    const isLocked = <?php echo $isLocked ? 'true' : 'false'; ?>;

    function uploadAttachment() {
    
        const input = document.getElementById('ajax_attachment_upload');
        const spinner = document.getElementById('upload_spinner');
        if (!input.files.length) return;
        spinner.style.display = 'inline';
        const formData = new FormData();
        formData.append('ajax_action', 'upload_attachment');
        formData.append('client_id', clientIdForAttachments);
        for (let i = 0; i < input.files.length; i++) {
            formData.append('files[]', input.files[i]);
        }
        fetch('report_attachments.php', {
            method: 'POST',
            body: formData
        })
        .then(response => response.json())
        .then(data => {
            spinner.style.display = 'none';
            if (data.success) {
                input.value = '';
                location.reload();
            } else {
                alert('Upload failed: ' + (data.error || 'Unknown error'));
            }
        })
        .catch(error => {
            spinner.style.display = 'none';
            alert('Upload error: ' + error.message);
        });
    }

    document.addEventListener('DOMContentLoaded', function() {
        initAttachmentHandlers();
    });

    function initAttachmentHandlers() {

        const editModal = document.getElementById('editAttachmentModal');
        const editOldFileName = document.getElementById('editOldAttachmentFileName');
        const editNewFileName = document.getElementById('editNewAttachmentFileName');
        const editCancel = document.getElementById('editAttachmentCancel');

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
                        client_id: clientIdForAttachments,
                        file_name: fileName
                    })
                })
                .then(response => response.json().catch(()=>({success:false, error:'Invalid JSON'})))
                .then(data => {
                    if (data.success) {
                        const listItem = target.closest('li');
                        if (listItem) listItem.remove();
                        const annexList = document.getElementById('annexures_list');
                        if (annexList) {
                            annexList.querySelectorAll('li[data-filename]')
                                .forEach(li => { 
                                    if (li.dataset.filename === fileName) li.remove(); 
                                });
                        }
                        const attachmentList = document.getElementById('attachment_list');
                        if (attachmentList && attachmentList.children.length === 0) {
                            attachmentList.innerHTML = '<li style="color: #777; font-style: italic;">No attachments uploaded yet.</li>';
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

                // Remove previous listeners by replacing the form node
                const editForm = document.getElementById('editAttachmentForm');
                const newForm = editForm.cloneNode(true);
                editForm.parentNode.replaceChild(newForm, editForm);

                document.getElementById('editOldAttachmentFileName').value = fileName;
                document.getElementById('editNewAttachmentFileName').value = fileName.replace(/\.pdf$/i, '');
                document.getElementById('editAttachmentModal').style.display = 'flex';

                setTimeout(() => {
                    document.getElementById('editNewAttachmentFileName').focus();
                    document.getElementById('editNewAttachmentFileName').select();
                }, 100);

                document.getElementById('editAttachmentCancel').onclick = function() {
                    document.getElementById('editAttachmentModal').style.display = 'none';
                };

                newForm.onsubmit = function(e) {
                    e.preventDefault();
                    const oldName = document.getElementById('editOldAttachmentFileName').value;
                    let newNameInput = document.getElementById('editNewAttachmentFileName').value.trim();

                    if (!oldName || !newNameInput) {
                        alert('Please enter a new name.');
                        return;
                    }
                    if (/[\/\\:*?"<>|]/.test(newNameInput)) {
                        alert('Filename contains invalid characters.');
                        return;
                    }
                    const existingNames = Array.from(document.querySelectorAll('#attachment_list li[data-filename]'))
                        .map(li => li.dataset.filename.toLowerCase());
                    const newNameWithPdf = newNameInput.toLowerCase().endsWith('.pdf') ?
                        newNameInput.toLowerCase() :
                        newNameInput.toLowerCase() + '.pdf';
                    if (existingNames.includes(newNameWithPdf)) {
                        alert('A file with this name already exists.');
                        return;
                    }
                    fetch('report_attachments.php', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
                        body: new URLSearchParams({
                            ajax_action: 'rename_attachment',
                            client_id: clientIdForAttachments,
                            old_name: oldName,
                            new_name: newNameInput
                        })
                    })
                    .then(response => response.json().catch(() => ({success:false, error:'Invalid JSON'})))
                    .then(data => {
                        if (data.success) {
                            const finalName = data.new_name;
                            document.querySelectorAll('#attachment_list li[data-filename]').forEach(li => {
                                if (li.dataset.filename === oldName) {
                                    li.dataset.filename = finalName;
                                    const strongElement = li.querySelector('strong');
                                    if (strongElement) strongElement.textContent = finalName;
                                    li.querySelectorAll('a').forEach(a => {
                                        a.setAttribute('data-filename', finalName);
                                    });
                                }
                            });
                            const annexList = document.getElementById('annexures_list');
                            if (annexList) {
                                annexList.querySelectorAll('li[data-filename]').forEach(li => {
                                    if (li.dataset.filename === oldName) {
                                        li.dataset.filename = finalName;
                                        const strongElement = li.querySelector('strong');
                                        if (strongElement) strongElement.textContent = finalName;
                                        li.querySelectorAll('a').forEach(a => {
                                            a.setAttribute('data-filename', finalName);
                                        });
                                    }
                                });
                            }
                            document.getElementById('editAttachmentModal').style.display = 'none';
                            alert('File renamed successfully to: ' + finalName);
                        } else {
                            alert('Error: ' + (data.error || 'Rename failed'));
                        }
                    })
                    .catch((error) => {
                        console.error('Rename error:', error);
                        alert('Rename error: ' + error.message);
                    });
                };
            }
        });

        document.getElementById('editAttachmentCancel').onclick = function() {
            document.getElementById('editAttachmentModal').style.display = 'none';
        };

        document.getElementById('editAttachmentModal').addEventListener('click', function(e) {
            if (e.target === this) {
                this.style.display = 'none';
            }
        });

        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape' && document.getElementById('editAttachmentModal').style.display === 'flex') {
                document.getElementById('editAttachmentModal').style.display = 'none';
            }
        });
    }
    </script>
</div>