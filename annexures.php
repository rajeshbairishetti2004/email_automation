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

$attDir = __DIR__ . '/uploads/attachments/client_' . $clientId;
$hasFiles = false;
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
if (is_dir($attDir)) {
    $files = scandir($attDir);
    $sortedFiles = [];
    $inceptionFile = null;

    foreach ($files as $file) {
        if ($file === '.' || $file === '..') continue;
        $hasFiles = true;

        $lower = strtolower($file);
        if (preg_match('/portfolio.*performance.*inception/i', $lower)) {
            $inceptionFile = $file;
        } else {
            $sortedFiles[] = $file;
        }
    }

    // Inception file
    if ($inceptionFile) {
        $label = formatAnnexureLabel($inceptionFile);
        echo '<li data-filename="'.htmlspecialchars($inceptionFile).'"
              style="margin-bottom:8px; padding:5px 0; border-bottom:1px solid #eee;
                     display:flex; justify-content:space-between; align-items:center;">';
        echo '<span>📎 <strong>'.htmlspecialchars($label).'</strong></span>';
        echo '<span class="annex-actions">
                <a href="#" class="annex-edit" data-filename="'.htmlspecialchars($inceptionFile).'">✏️ Edit</a>
                <a href="#" class="annex-delete" data-filename="'.htmlspecialchars($inceptionFile).'">🗑 Delete</a>
              </span>';
        echo '</li>';
    }

    foreach ($sortedFiles as $file) {
        $label = formatAnnexureLabel($file);
        echo '<li data-filename="'.htmlspecialchars($file).'"
              style="margin-bottom:8px; padding:5px 0; border-bottom:1px solid #eee;
                     display:flex; justify-content:space-between; align-items:center;">';
        echo '<span>📎 <strong>'.htmlspecialchars($label).'</strong></span>';
        echo '<span class="annex-actions">
                <a href="#" class="annex-edit" data-filename="'.htmlspecialchars($file).'">✏️ Edit</a>
                <a href="#" class="annex-delete" data-filename="'.htmlspecialchars($file).'">🗑 Delete</a>
              </span>';
        echo '</li>';
    }
}

if (!$hasFiles) {
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
const clientId = <?php echo (int)$clientId; ?>;

// DELETE
function deleteAttachment(fileName) {
    if (!confirm('Delete ' + fileName + '?')) return;
    fetch('report_attachments.php', {
        method: 'POST',
        headers: {'Content-Type':'application/x-www-form-urlencoded'},
        body: new URLSearchParams({
            ajax_action: 'delete_attachment',
            client_id: clientId,
            file_name: fileName
        })
    })
.then(d => {
    if (!d.success) return;

    const newName = d.new_name;
    const newLabel = newName.replace(/\.pdf$/i, '');

    // Update ALL annexures + attachments safely
    document.querySelectorAll('li[data-filename]').forEach(li => {
        if (li.dataset.filename === oldName) {
            li.dataset.filename = newName;

            const strong = li.querySelector('strong');
            if (strong) strong.textContent = newLabel;

            li.querySelectorAll('a').forEach(a => {
                a.dataset.filename = newName;
            });
        }
    });
});

}

// RENAME
function renameAttachment(oldName, newName) {
    fetch('report_attachments.php', {
        method: 'POST',
        headers: {'Content-Type':'application/x-www-form-urlencoded'},
        body: new URLSearchParams({
            ajax_action: 'rename_attachment',
            client_id: clientId,
            old_name: oldName,
            new_name: newName
        })
    })
    .then(r => r.json())
    .then(d => {
        if (d.success) {
            document.querySelectorAll('[data-filename="'+oldName+'"]').forEach(li => {
                li.dataset.filename = d.new_name;
                li.querySelector('strong').textContent = d.new_name.replace(/\.pdf$/i,'');
                li.querySelectorAll('a').forEach(a => a.dataset.filename = d.new_name);
            });
        }
    });
}

// EVENTS
document.addEventListener('click', function(e) {
    const a = e.target.closest('a');
    if (!a) return;

    if (a.classList.contains('annex-delete')) {
        e.preventDefault();
        deleteAttachment(a.dataset.filename);
    }

    if (a.classList.contains('annex-edit')) {
        e.preventDefault();
        editOldFileName.value = a.dataset.filename;
        editNewFileName.value = a.dataset.filename.replace(/\.pdf$/i,'');
        editAnnexureModal.style.display = 'flex';
    }
});

editAnnexureCancel.onclick = () =>
    editAnnexureModal.style.display = 'none';

editAnnexureForm.onsubmit = function(e) {
    e.preventDefault();
    let oldName = editOldFileName.value;
    let newName = editNewFileName.value.trim();
    if (!newName.endsWith('.pdf')) newName += '.pdf';
    renameAttachment(oldName, newName);
    editAnnexureModal.style.display = 'none';
};
</script>
