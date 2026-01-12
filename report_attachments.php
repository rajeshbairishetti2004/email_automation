<?php
// report_attachments.php
// Requires: $clientId, $canEditAttachments set in parent scope

// ==============================================
// 1. AJAX HANDLING (when called directly)
// ==============================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ajax_action'])) {
    // Handle AJAX requests for attachments
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
                                // Insert into DB
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
    
                // enforce .pdf once
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

// Use existing PDO if available, else require db_config.php
if (!isset($pdo)) {
    require_once 'db_config.php';
    $pdo = getPdo();
}

$stmt = $pdo->prepare("SELECT file_name FROM report_attachments WHERE client_id = :client_id ORDER BY uploaded_at DESC, id DESC");
$stmt->execute([':client_id' => $clientId]);
$existingFiles = $stmt->fetchAll(PDO::FETCH_COLUMN);

// Lock logic (do not show error, just set $canEditAttachments)
if (!isset($isLocked)) {
    require_once 'db_config.php';
    $pdo = getPdo();
    $stmt = $pdo->prepare("SELECT report_state, review_not_ok FROM clients WHERE id = ?");
    $stmt->execute([$clientId]);
    $clientLock = $stmt->fetch(PDO::FETCH_ASSOC);
    $reportState = $clientLock['report_state'] ?? 'draft';
    $reviewNotOk = (int)($clientLock['review_not_ok'] ?? 0);
    $isLocked = (($reportState === 'reviewed' && $reviewNotOk === 0) || $reportState === 'sent');
}
if ($isLocked) {
    $canEditAttachments = false;
}
?>
<div class="card" style="margin-top: 20px; border-left: 4px solid #17a2b8; position:relative;">
    <label class="card-title" style="display:flex; align-items:center; justify-content:space-between;">
      <span>
        📂 Report Attachments
        <?php if (isset($isLocked) && $isLocked): ?>
            <span title="Locked" style="margin-left:8px;color:#888;vertical-align:middle;">🔒</span>
        <?php endif; ?>
      </span>
      <button type="button" id="refreshAttachments" class="refresh-icon-btn" title="Clear attachments" style="margin-left:auto; background:transparent; border:none; outline:none; cursor:pointer; padding:4px; z-index:100; display:flex; align-items:center; justify-content:center;">
        <span class="refresh-svg-icon" id="refreshAttachmentsIcon">
          <svg width="28" height="28" viewBox="0 0 32 32" fill="none" xmlns="http://www.w3.org/2000/svg">
            <path d="M23.5 8.5A11 11 0 1 0 27 16" stroke="#0288D1" stroke-width="2.2" fill="none" stroke-linecap="round"/>
            <polygon points="27,16 23,13.5 23,18.5" fill="#0288D1"/>
            <path d="M8.5 23.5A11 11 0 1 0 5 16" stroke="#0288D1" stroke-width="2.2" fill="none" stroke-linecap="round"/>
            <polygon points="5,16 9,18.5 9,13.5" fill="#0288D1"/>
          </svg>
        </span>
      </button>
    </label>

    <?php if ($canEditAttachments): ?>
        <div style="margin-bottom: 15px; padding: 10px; background: #eefbff; border-radius: 4px;">
            <input type="file" id="ajax_attachment_upload" multiple style="width: auto;" onchange="uploadAttachment()">
            <span id="upload_spinner" style="display:none; margin-left: 10px; font-weight: bold; color: #0288D1;">
                ⏳ Uploading...
            </span>
        </div>
    <?php endif; ?>

    <ul id="attachment_list" style="list-style: none; padding: 0;">
        <?php if (empty($existingFiles)): ?>
            <li style="color: #777; font-style: italic;">No attachments uploaded yet.</li>
        <?php else: ?>
            <?php foreach ($existingFiles as $file): ?>
                <li style="margin-bottom: 8px; border-bottom: 1px solid #eee; padding-bottom: 5px; display: flex; justify-content: space-between;" data-filename="<?php echo htmlspecialchars($file); ?>">
                    <span>📎 <strong><?php echo htmlspecialchars($file); ?></strong></span>
                    <?php if ($canEditAttachments): ?>
                       <span class="annex-actions">
<a href="#" class="annex-edit" data-filename="<?php echo htmlspecialchars($file); ?>">
    <i class="fa-solid fa-pen-to-square"></i> Edit
</a>
<a href="#" class="annex-delete" data-filename="<?php echo htmlspecialchars($file); ?>">
    <i class="fa-solid fa-trash-can"></i> Delete
</a>
</span>
                    <?php else: ?>
                        <span style="font-size: 11px; color: #999;">(Read Only)</span>
                    <?php endif; ?>
                </li>
            <?php endforeach; ?>
        <?php endif; ?>
    </ul>
    <p style="font-size: 11px; color: #666;">Note: Files uploaded here will be automatically attached to the final email.</p>

    <!-- Edit Modal HTML -->
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
    // Attachment-specific JavaScript functions
    const clientIdForAttachments = <?php echo (int)$clientId; ?>;
    
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
        .then data => {
            spinner.style.display = 'none';
            if (data.success) {
                // Clear file input
                input.value = '';
                
                // Reload the attachment list
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
    
    // Fix: Ensure DOM is loaded before attaching event listeners
    document.addEventListener('DOMContentLoaded', function() {
        initAttachmentHandlers();
    });
    
    function initAttachmentHandlers() {
        const refreshAttachmentsBtn = document.getElementById('refreshAttachments');
        const refreshAttachmentsIcon = document.getElementById('refreshAttachmentsIcon');
        const editModal = document.getElementById('editAttachmentModal');
        const editForm = document.getElementById('editAttachmentForm');
        const editOldFileName = document.getElementById('editOldAttachmentFileName');
        const editNewFileName = document.getElementById('editNewAttachmentFileName');
        const editCancel = document.getElementById('editAttachmentCancel');

        if (refreshAttachmentsBtn && refreshAttachmentsIcon) {
            refreshAttachmentsBtn.addEventListener('click', function() {
                refreshAttachmentsIcon.classList.add('rotating');
                // Note: delete_attachments.php should also be moved to report_attachments.php
                // For now, we'll keep it as is or create a new AJAX action
                fetch('delete_attachments.php?client_id=<?php echo (int)$clientId; ?>', { method: 'POST' })
                    .then(r => r.json())
                    .then(data => {
                        const list = document.getElementById('attachment_list');
                        if (list) list.innerHTML = '<li style="color: #777; font-style: italic;">No attachments uploaded yet.</li>';
                        // Also clear annexures if present
                        const annexList = document.getElementById('annexures_list');
                        if (annexList) annexList.innerHTML = '<li style="color: #777; font-style: italic;">No annexures available.</li>';
                    })
                    .finally(() => {
                        refreshAttachmentsIcon.classList.remove('rotating');
                    });
            });
        }

        // Fix: Use event delegation for dynamically created elements
        document.addEventListener('click', function(e) {
            const target = e.target.closest('a');
            if (!target) return;
            
            // Check if click is inside attachment_list
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
                        // Remove from DOM
                        const listItem = target.closest('li');
                        if (listItem) listItem.remove();
                        
                        // Also remove from annexures if present
                        const annexList = document.getElementById('annexures_list');
                        if (annexList) {
                            annexList.querySelectorAll('li[data-filename]')
                                .forEach(li => { 
                                    if (li.dataset.filename === fileName) li.remove(); 
                                });
                        }
                        
                        // If no attachments left, show message
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
                
                // Show modal
                editOldFileName.value = fileName;
                // Remove .pdf extension for editing if present
                editNewFileName.value = fileName.replace(/\.pdf$/i, '');
                editModal.style.display = 'flex';
                
                // Focus and select text for easy editing
                setTimeout(() => {
                    editNewFileName.focus();
                    editNewFileName.select();
                }, 100);
            }
        });

        // Cancel button
        if (editCancel) {
            editCancel.addEventListener('click', function() {
                editModal.style.display = 'none';
            });
        }

        // Submit rename
        if (editForm) {
            editForm.addEventListener('submit', function(e) {
                e.preventDefault();
                const oldName = editOldFileName.value;
                let newNameInput = editNewFileName.value.trim();
                
                if (!oldName || !newNameInput) {
                    alert('Please enter a new name.');
                    return;
                }
                
                // Disallow invalid characters (basic check)
                if (/[\/\\:*?"<>|]/.test(newNameInput)) {
                    alert('Filename contains invalid characters.');
                    return;
                }
                
                // Prevent duplicate names
                const existingNames = Array.from(document.querySelectorAll('#attachment_list li[data-filename]'))
                    .map(li => li.dataset.filename.toLowerCase());
                
                // Check if new name already exists (with or without .pdf)
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
                        
                        // Update DOM in attachment_list
                        document.querySelectorAll('#attachment_list li[data-filename]').forEach(li => {
                            if (li.dataset.filename === oldName) {
                                li.dataset.filename = finalName;
                                const strongElement = li.querySelector('strong');
                                if (strongElement) strongElement.textContent = finalName;
                                
                                // Update all anchor tags within this li
                                li.querySelectorAll('a').forEach(a => {
                                    a.setAttribute('data-filename', finalName);
                                });
                            }
                        });
                        
                        // Also update in annexures list if present
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
                        
                        editModal.style.display = 'none';
                        
                        // Show success message
                        alert('File renamed successfully to: ' + finalName);
                    } else {
                        alert('Error: ' + (data.error || 'Rename failed'));
                    }
                })
                .catch((error) => {
                    console.error('Rename error:', error);
                    alert('Rename error: ' + error.message);
                });
            });
        }
        
        // Close modal when clicking outside
        editModal.addEventListener('click', function(e) {
            if (e.target === editModal) {
                editModal.style.display = 'none';
            }
        });
        
        // Close modal with Escape key
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape' && editModal.style.display === 'flex') {
                editModal.style.display = 'none';
            }
        });
    }
    </script>
</div>