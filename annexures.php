<?php
// Ensure $clientId is set in parent scope
if (!isset($clientId)) {
    throw new Exception('clientId must be set before including annexures.php');
}
$annexures = getClientAnnexures($clientId);
?>

<?php
// annexures.php
// Requires: $clientId, $client, $canEditAttachments to be set in parent scope

if (!isset($clientId) || !isset($client)) {
    echo "<p>Error: Missing client data</p>";
    return;
}

// Function to format annexure label
if (!function_exists('formatAnnexureLabel')) {
    function formatAnnexureLabel($filename, $clientName = '') {
        $name = pathinfo($filename, PATHINFO_FILENAME);
        if ($name === '') return $filename;
        return $name;
    }
}

// NEW: List actual files from the persistent attachment folder
$attDir = __DIR__ . '/uploads/attachments/client_' . $clientId;
$hasFiles = false;
?>
<style>
.refresh-icon-btn .refresh-svg-icon { display:inline-block; vertical-align:middle; }
.refresh-icon-btn .refresh-svg-icon.rotating { animation: refresh-rotate 0.6s linear; }
@keyframes refresh-rotate { 100% { transform: rotate(360deg); } }
.refresh-icon-btn { background:transparent; border:none; outline:none; cursor:pointer; padding:4px; z-index:100; display:flex; align-items:center; justify-content:center; box-shadow:none; border-radius:50%; transition:background 0.15s; }
.refresh-icon-btn:hover { background:rgba(2,136,209,0.08); }

/* --- ANNEXURE ACTION BUTTONS --- */
.annex-actions {
    display: inline-flex;
    gap: 8px;
    align-items: center;
}

.annex-edit, .annex-delete {
    padding: 4px 10px;
    border-radius: 6px;
    font-size: 12px;
    font-weight: 600;
    text-decoration: none;
    transition: all 0.2s cubic-bezier(0.4, 0, 0.2, 1);
    display: inline-flex;
    align-items: center;
    gap: 4px;
    border: 1px solid transparent;
}

/* Edit Button Styling (Blue Theme) */
.annex-edit {
    color: #0288D1;
    background: #e3f2fd;
    border-color: #b3e5fc;
}

.annex-edit:hover {
    background: #0288D1;
    color: #ffffff;
    transform: translateY(-1px);
}

/* Delete Button Styling (Red Theme) */
.annex-delete {
    color: #dc3545;
    background: #fee2e2;
    border-color: #fecaca;
}

.annex-delete:hover {
    background: #dc3545;
    color: #ffffff;
    transform: translateY(-1px);
    box-shadow: 0 2px 8px rgba(220, 53, 69, 0.2);
}
</style>
<script>
// --- Refresh Attachments/Annexures Logic (Moved from view_report.php) ---
document.addEventListener('DOMContentLoaded', function() {
    const refreshAttachmentsBtn = document.getElementById('refreshAttachments');
    const refreshAttachmentsIcon = document.getElementById('refreshAttachmentsIcon');
    if (refreshAttachmentsBtn && refreshAttachmentsIcon) {
        refreshAttachmentsBtn.addEventListener('click', function() {
            refreshAttachmentsIcon.classList.add('rotating');
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
});
</script>
<script>
// --- ATTACHMENT & ANNEXURE JS HELPERS (shared for both lists) ---
function escapeHtml(text) {
    return (text + '').replace(/[&<>"'`=\/]/g, function (s) {
        return {
            '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;',
            '/': '&#x2F;', '`': '&#x60;', '=': '&#x3D;'
        }[s];
    });
}
function getAnnexureLabel(fileName) {
    if (!fileName) return '';
    return fileName.replace(/\.pdf$/i, '');
}
function deleteAttachment(fileName, el) {
    if(!confirm("Are you sure you want to delete " + fileName + "?")) return;
    const clientId = <?php echo (int)$clientId; ?>;
    fetch('report_attachments.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
        body: new URLSearchParams({
            ajax_action: 'delete_attachment',
            client_id: clientId,
            file_name: fileName
        })
    })
    .then(response => response.json().catch(()=>({success:false, error:'Invalid JSON'})))
    .then(data => {
        if (data.success) {
            // Remove from both lists
            document.querySelectorAll('#attachment_list li[data-filename], #annexures_list li[data-filename]').forEach(li => {
                if (li.dataset.filename === fileName) li.remove();
            });
            if (typeof showToast === 'function') showToast('Deleted: ' + fileName);
            // Show empty message if needed
            if (document.querySelectorAll('#attachment_list li[data-filename]').length === 0) {
                const list = document.getElementById('attachment_list');
                if (list) list.innerHTML = '<li style="color: #777; font-style: italic;">No attachments uploaded yet.</li>';
            }
            if (document.querySelectorAll('#annexures_list li[data-filename]').length === 0) {
                const list = document.getElementById('annexures_list');
                if (list) list.innerHTML = '<li style="color: #777; font-style: italic;">No annexures available.</li>';
            }
        } else {
            alert('Error: ' + (data.error || 'Delete failed'));
        }
    })
    .catch(() => alert('Delete error.'));
}
function renameAttachment(oldName, newName, cb) {
    const clientId = <?php echo (int)$clientId; ?>;
    fetch('report_attachments.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
        body: new URLSearchParams({
            ajax_action: 'rename_attachment',
            client_id: clientId,
            old_name: oldName,
            new_name: newName
        })
    })
    .then(response => response.json().catch(()=>({success:false, error:'Invalid JSON'})))
    .then(data => {
        cb(data);
    })
    .catch(() => cb({success:false, error:'Rename error'}));
}
</script>

<h3>
    Annexures
    <?php if (isset($isLocked) && $isLocked): ?>
        <span title="Locked" style="margin-left:8px;color:#888;vertical-align:middle;">🔒</span>
    <?php endif; ?>
</h3>
<ul id="annexures_list">
    <?php
    if (is_dir($attDir)) {
        $files = scandir($attDir);
        $sortedFiles = [];
        
        // Separate inception portfolio file from others
        $inceptionFile = null;
        foreach ($files as $file) {
            if ($file === '.' || $file === '..') continue;
            $hasFiles = true;
            
            $nameLower = strtolower($file);
            if (preg_match('/portfolio.*performance.*since.*inception/i', $nameLower) || 
                preg_match('/portfolio.*performance.*inception/i', $nameLower)) {
                $inceptionFile = $file;
            } else {
                $sortedFiles[] = $file;
            }
        }
        
        // Display inception file first if it exists
        if ($inceptionFile) {
            $label = formatAnnexureLabel($inceptionFile, $client['name'] ?? '');
            echo '<li data-filename="' . htmlspecialchars($inceptionFile) . '" style="margin-bottom:8px; border-bottom:1px solid #eee; padding:5px 0; display:flex; justify-content:space-between; align-items:center;">';
            echo '<span>📎 <strong>' . htmlspecialchars($label) . '</strong></span>';
            if (isset($canEditAttachments) && $canEditAttachments) {
                echo '<span class="annex-actions">';
                echo '<a href="#" class="annex-edit" data-filename="' . htmlspecialchars($inceptionFile) . '">✏️ Edit</a>';
                echo '<a href="#" class="annex-delete" data-filename="' . htmlspecialchars($inceptionFile) . '">🗑 Delete</a>';
                echo '</span>';
            }
            echo '</li>';
        }
        
        // Display remaining files
        foreach ($sortedFiles as $file) {
            $label = formatAnnexureLabel($file, $client['name'] ?? '');
            echo '<li data-filename="' . htmlspecialchars($file) . '" style="margin-bottom:8px; border-bottom:1px solid #eee; padding:5px 0; display:flex; justify-content:space-between; align-items:center;">';
            echo '<span>📎 <strong>' . htmlspecialchars($label) . '</strong></span>';
            if (isset($canEditAttachments) && $canEditAttachments) {
                echo '<span class="annex-actions">';
                echo '<a href="#" class="annex-edit" data-filename="' . htmlspecialchars($file) . '">✏️ Edit</a>';
                echo '<a href="#" class="annex-delete" data-filename="' . htmlspecialchars($file) . '">🗑 Delete</a>';
                echo '</span>';
            }
            echo '</li>';
        }
    }
    
    if (!$hasFiles) {
        echo "<li>No documents attached.</li>";
    }
    ?>
</ul>

<!-- Edit Modal HTML -->
<div id="editAnnexureModal" style="display:none; position:fixed; top:0; left:0; width:100vw; height:100vh; background:rgba(0,0,0,0.3); z-index:9999; justify-content:center; align-items:center;">
  <div style="background:#fff; padding:30px 25px; border-radius:8px; min-width:320px; max-width:90vw; box-shadow:0 4px 16px rgba(0,0,0,0.15);">
    <h3 style="margin-top:0;">Rename Attachment</h3>
    <form id="editAnnexureForm">
      <input type="hidden" id="editOldFileName">
      <label for="editNewFileName">New Name:</label>
      <input type="text" id="editNewFileName" style="width:100%; margin-bottom:15px; padding:8px; font-size:15px;">
      <div style="text-align:right;">
        <button type="button" id="editAnnexureCancel" style="margin-right:10px; padding:7px 18px;">Cancel</button>
        <button type="submit" style="padding:7px 18px; background:#0288D1; color:#fff; border:none; border-radius:4px;">Save</button>
      </div>
    </form>
  </div>
</div>
<script>
// --- Event delegation for both annexures and attachments lists ---
document.addEventListener('DOMContentLoaded', function() {
    const annexList = document.getElementById('annexures_list');
    const attachList = document.getElementById('attachment_list');
    const editModal = document.getElementById('editAnnexureModal');
    const editForm = document.getElementById('editAnnexureForm');
    const editOldFileName = document.getElementById('editOldFileName');
    const editNewFileName = document.getElementById('editNewFileName');
    const editCancel = document.getElementById('editAnnexureCancel');

    function handleEditDeleteClick(e, listType) {
        const target = e.target.closest('a');
        if (!target) return;
        if (target.classList.contains('annex-delete')) {
            e.preventDefault();
            const fileName = target.getAttribute('data-filename');
            if (!fileName) return;
            deleteAttachment(fileName, target);
        } else if (target.classList.contains('annex-edit')) {
            e.preventDefault();
            const fileName = target.getAttribute('data-filename');
            if (!fileName) return;
            // Show modal
            editOldFileName.value = fileName;
            // Remove .pdf for editing
            editNewFileName.value = fileName.replace(/\.pdf$/i, '');
            editModal.style.display = 'flex';
            setTimeout(() => { editNewFileName.focus(); editNewFileName.select(); }, 100);
        }
    }
    if (annexList) annexList.addEventListener('click', e => handleEditDeleteClick(e, 'annexures'));
    if (attachList) attachList.addEventListener('click', e => handleEditDeleteClick(e, 'attachments'));

    // Cancel button
    editCancel.addEventListener('click', function() {
        editModal.style.display = 'none';
    });

    // Submit rename
    editForm.addEventListener('submit', function(e) {
        e.preventDefault();
        const oldName = editOldFileName.value;
        let newName = editNewFileName.value.trim();
        if (!oldName || !newName) {
            alert('Please enter a new name.');
            return;
        }
        // Disallow invalid characters
        if (/[\/\\:*?"<>|]/.test(newName)) {
            alert('Filename contains invalid characters.');
            return;
        }
        // Enforce .pdf extension
        if (!/\.pdf$/i.test(newName)) newName += '.pdf';

        // Prevent duplicate names in DOM
        const allNames = Array.from(document.querySelectorAll('li[data-filename]')).map(li => li.dataset.filename.toLowerCase());
        if (allNames.includes(newName.toLowerCase())) {
            alert('A file with this name already exists.');
            return;
        }

        renameAttachment(oldName, newName, function(data) {
            if (data.success) {
                // Update DOM in both lists
                document.querySelectorAll('#attachment_list li[data-filename], #annexures_list li[data-filename]').forEach(li => {
                    if (li.dataset.filename === oldName) {
                        li.dataset.filename = data.new_name;
                        const strong = li.querySelector('strong');
                        if (strong) strong.textContent = (li.closest('#annexures_list')) ? getAnnexureLabel(data.new_name) : data.new_name;
                        li.querySelectorAll('a').forEach(a => a.setAttribute('data-filename', data.new_name));
                    }
                });
                editModal.style.display = 'none';
                if (typeof showToast === 'function') showToast('Renamed to: ' + data.new_name);
            } else {
                alert('Error: ' + (data.error || 'Rename failed'));
            }
        });
    });

    // Close modal when clicking outside
    editModal.addEventListener('click', function(e) {
        if (e.target === editModal) editModal.style.display = 'none';
    });
    // Escape key closes modal
    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape' && editModal.style.display === 'flex') editModal.style.display = 'none';
    });
});
</script>