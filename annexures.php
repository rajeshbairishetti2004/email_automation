<?php
// annexures.php
// Requires: $clientId and $client

if (!isset($clientId) || !isset($client)) {
    echo "<p>Error: Missing client data</p>";
    return;
}

// Format annexure label
if (!function_exists('formatAnnexureLabel')) {
    function formatAnnexureLabel($filename) {
        $name = pathinfo($filename, PATHINFO_FILENAME);
        return $name ?: $filename;
    }
}

// Database connection
require_once 'db_config.php';
$pdo = getPdo();

// KEY FIX: Get the current client's name directly from the clients table
$stmtClientName = $pdo->prepare("SELECT name FROM clients WHERE id = :id LIMIT 1");
$stmtClientName->execute([':id' => $clientId]);
$currentClientName = $stmtClientName->fetchColumn();

if (!$currentClientName) {
    echo "<p>Error: Client not found.</p>";
    return;
}

// KEY FIX: Filter by BOTH client_id AND client_name so re-used IDs never show another client's files
$stmt = $pdo->prepare(
    "SELECT file_name FROM report_attachments
     WHERE client_id = :client_id AND client_name = :client_name
     ORDER BY id DESC"
);
$stmt->execute([':client_id' => $clientId, ':client_name' => $currentClientName]);
$existingFiles = $stmt->fetchAll(PDO::FETCH_COLUMN);

// REMOVED: filesystem fallback that caused the cross-client leakage bug.
// Files must be in the DB with the correct client_name to appear here.

$hasFiles = !empty($existingFiles);
?>
<style>
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
    display: inline-flex;
    align-items: center;
    gap: 4px;
    border: 1px solid transparent;
    transition: all 0.2s ease;
}

.annex-edit {
    color: #0288D1;
    background: #e3f2fd;
}
.annex-edit:hover {
    background: #0288D1;
    color: #fff;
}

.annex-delete {
    color: #dc3545;
    background: #fee2e2;
}
.annex-delete:hover {
    background: #dc3545;
    color: #fff;
}
</style>

<h3>Annexures</h3>

<ul id="annexures_list" style="list-style:none; padding:0;">
<?php
if ($hasFiles) {
    $sortedFiles  = [];
    $inceptionFile = null;

    foreach ($existingFiles as $file) {
        if (preg_match('/portfolio.*performance.*inception/i', $file)) {
            $inceptionFile = $file;
        } else {
            $sortedFiles[] = $file;
        }
    }

    // Render inception file first
    if ($inceptionFile) {
        $label = formatAnnexureLabel($inceptionFile);
        echo '<li data-filename="' . htmlspecialchars($inceptionFile) . '"
              style="margin-bottom:8px; padding:5px 0; border-bottom:1px solid #eee;
                     display:flex; justify-content:space-between; align-items:center;">';
        echo '<span>📎 <strong>' . htmlspecialchars($label) . '</strong></span>';
        echo '<span class="annex-actions">
                <a href="javascript:void(0)" class="annex-edit"   data-filename="' . htmlspecialchars($inceptionFile) . '"><i class="fa-solid fa-pen-to-square"></i> Edit</a>
                <a href="javascript:void(0)" class="annex-delete" data-filename="' . htmlspecialchars($inceptionFile) . '"><i class="fa-solid fa-trash-can"></i> Delete</a>
              </span>';
        echo '</li>';
    }

    foreach ($sortedFiles as $file) {
        $label = formatAnnexureLabel($file);
        echo '<li data-filename="' . htmlspecialchars($file) . '"
              style="margin-bottom:8px; padding:5px 0; border-bottom:1px solid #eee;
                     display:flex; justify-content:space-between; align-items:center;">';
        echo '<span>📎 <strong>' . htmlspecialchars($label) . '</strong></span>';
        echo '<span class="annex-actions">
                <a href="javascript:void(0)" class="annex-edit"   data-filename="' . htmlspecialchars($file) . '"><i class="fa-solid fa-pen-to-square"></i> Edit</a>
                <a href="javascript:void(0)" class="annex-delete" data-filename="' . htmlspecialchars($file) . '"><i class="fa-solid fa-trash-can"></i> Delete</a>
              </span>';
        echo '</li>';
    }
} else {
    echo '<li style="color:#777;">No annexures available.</li>';
}
?>
</ul>

<!-- Rename Modal -->
<div id="editAnnexureModal" style="display:none; position:fixed; inset:0;
     background:rgba(0,0,0,0.35); z-index:9999; justify-content:center; align-items:center;">
  <div style="background:#fff; padding:25px; border-radius:8px; min-width:320px;">
    <h3>Rename Annexure</h3>
    <form id="editAnnexureForm">
      <input type="hidden" id="editOldFileName">
      <input type="text" id="editNewFileName" style="width:100%; padding:8px; margin-bottom:12px;">
      <div style="text-align:right;">
        <button type="button" id="editAnnexureCancel">Cancel</button>
        <button type="submit" style="background:#0288D1; color:#fff; border:none; padding:6px 14px;">Save</button>
      </div>
    </form>
  </div>
</div>

<script>
// NOTE: Do not redeclare clientId if report_attachments.php is also included on the same page.
// Use a scoped variable to avoid conflicts.
const annexureClientId = <?php echo (int)$clientId; ?>;

function deleteAnnexure(fileName) {
    if (!confirm('Delete ' + fileName + '?')) return;

    fetch('report_attachments.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/x-www-form-urlencoded'},
        body: new URLSearchParams({
            ajax_action: 'delete_attachment',
            client_id:   annexureClientId,
            file_name:   fileName
        })
    })
    .then(r => r.json())
    .then(data => {
        if (data.success) {
            document.querySelectorAll('#annexures_list li[data-filename="' + fileName + '"]')
                .forEach(li => li.remove());

            const attachmentList = document.getElementById('attachment_list');
            if (attachmentList) {
                attachmentList.querySelectorAll('li[data-filename="' + fileName + '"]')
                    .forEach(li => li.remove());
            }

            const annexList = document.getElementById('annexures_list');
            const remaining = annexList.querySelectorAll('li[data-filename]');
            if (remaining.length === 0) {
                annexList.innerHTML = '<li style="color:#777;">No annexures available.</li>';
            }
        } else {
            alert('Delete failed: ' + (data.error || 'Unknown error'));
        }
    })
    .catch(error => alert('Delete error: ' + error.message));
}

function renameAnnexure(oldName, newName) {
    fetch('report_attachments.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/x-www-form-urlencoded'},
        body: new URLSearchParams({
            ajax_action: 'rename_attachment',
            client_id:   annexureClientId,
            old_name:    oldName,
            new_name:    newName
        })
    })
    .then(r => r.json())
    .then(data => {
        if (data.success) {
            const newFileName = data.new_name;
            const newLabel    = newFileName.replace(/\.pdf$/i, '');

            document.querySelectorAll('#annexures_list li[data-filename="' + oldName + '"]').forEach(li => {
                li.dataset.filename = newFileName;
                const strong = li.querySelector('strong');
                if (strong) strong.textContent = newLabel;
                li.querySelectorAll('a').forEach(a => a.dataset.filename = newFileName);
            });

            const attachmentList = document.getElementById('attachment_list');
            if (attachmentList) {
                attachmentList.querySelectorAll('li[data-filename="' + oldName + '"]').forEach(li => {
                    li.dataset.filename = newFileName;
                    const strong = li.querySelector('strong');
                    if (strong) strong.textContent = newFileName;
                    li.querySelectorAll('a').forEach(a => a.dataset.filename = newFileName);
                });
            }
        } else {
            alert('Rename failed: ' + (data.error || 'Unknown error'));
        }
    })
    .catch(error => alert('Rename error: ' + error.message));
}

document.addEventListener('click', function(e) {
    const a = e.target.closest('a');
    if (!a) return;

    if (a.classList.contains('annex-delete') && e.target.closest('#annexures_list')) {
        e.preventDefault();
        deleteAnnexure(a.dataset.filename);
    }

    if (a.classList.contains('annex-edit') && e.target.closest('#annexures_list')) {
        e.preventDefault();
        document.getElementById('editOldFileName').value = a.dataset.filename;
        document.getElementById('editNewFileName').value = a.dataset.filename.replace(/\.pdf$/i, '');
        document.getElementById('editAnnexureModal').style.display = 'flex';
        setTimeout(() => {
            const inp = document.getElementById('editNewFileName');
            inp.focus(); inp.select();
        }, 100);
    }
});

document.getElementById('editAnnexureCancel').onclick = () =>
    document.getElementById('editAnnexureModal').style.display = 'none';

document.getElementById('editAnnexureModal').addEventListener('click', function(e) {
    if (e.target === this) this.style.display = 'none';
});

document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape' && document.getElementById('editAnnexureModal').style.display === 'flex') {
        document.getElementById('editAnnexureModal').style.display = 'none';
    }
});

document.getElementById('editAnnexureForm').onsubmit = function(e) {
    e.preventDefault();
    let oldName = document.getElementById('editOldFileName').value;
    let newName = document.getElementById('editNewFileName').value.trim();
    if (!newName) { alert('Please enter a new name'); return; }
    if (/[\/\\:*?"<>|]/.test(newName)) { alert('Filename contains invalid characters.'); return; }
    if (!newName.endsWith('.pdf')) newName += '.pdf';
    renameAnnexure(oldName, newName);
    document.getElementById('editAnnexureModal').style.display = 'none';
};
</script>