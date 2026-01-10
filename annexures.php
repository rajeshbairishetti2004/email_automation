<?php
// Expects $clientId, $client, $canEditAttachments to be present in parent scope
if (!isset($clientId)) $clientId = 0;
if (!isset($client)) $client = [];
if (!isset($canEditAttachments)) $canEditAttachments = false;
?>
<style>
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

<h3>Annexures</h3>
<ul id="annexures_list">
<?php
$attDir = __DIR__ . '/uploads/attachments/client_' . $clientId;
$hasFiles = false;
function formatAnnexureLabel($filename, $clientName = '') {
    $name = pathinfo($filename, PATHINFO_FILENAME);
    if ($name === '') return $filename;
    return $name;
}
if (is_dir($attDir)) {
    $files = scandir($attDir);
    $sortedFiles = [];
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
    if ($inceptionFile) {
        $label = formatAnnexureLabel($inceptionFile, $client['name'] ?? '');
        echo '<li data-filename="' . htmlspecialchars($inceptionFile) . '" style="margin-bottom:8px; border-bottom:1px solid #eee; padding:5px 0; display:flex; justify-content:space-between; align-items:center;">';
        echo '<span>📎 <strong>' . htmlspecialchars($label) . '</strong></span>';
        if ($canEditAttachments) {
            echo '<span class="annex-actions">';
            echo '<a href="#" class="annex-edit" data-filename="' . htmlspecialchars($inceptionFile) . '">✏️ Edit</a>';
            echo '<a href="#" class="annex-delete" data-filename="' . htmlspecialchars($inceptionFile) . '">🗑 Delete</a>';
            echo '</span>';
        }
        echo '</li>';
    }
    foreach ($sortedFiles as $file) {
        $label = formatAnnexureLabel($file, $client['name'] ?? '');
        echo '<li data-filename="' . htmlspecialchars($file) . '" style="margin-bottom:8px; border-bottom:1px solid #eee; padding:5px 0; display:flex; justify-content:space-between; align-items:center;">';
        echo '<span>📎 <strong>' . htmlspecialchars($label) . '</strong></span>';
        if ($canEditAttachments) {
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
<script>
// Delegate handlers for annexure edit / delete (works for both Attachments and Annexures lists)
document.addEventListener('click', function(e) {
    // Delete link
    if (e.target && e.target.matches('a.annex-delete')) {
        e.preventDefault();
        const fileName = e.target.getAttribute('data-filename');
        if (!fileName) return;
        deleteAttachment(fileName, e.target);
        return;
    }
    // Edit (rename) link
    if (e.target && e.target.matches('a.annex-edit')) {
        e.preventDefault();
        const fileName = e.target.getAttribute('data-filename');
        if (!fileName) return;
        const newNameRaw = prompt('Enter new filename for annexure:', fileName);
        if (!newNameRaw || !newNameRaw.trim() || newNameRaw.trim() === fileName) return;
        const newName = newNameRaw.trim();
        const clientId = <?= (int)$clientId ?>;
        fetch('template_actions.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
            body: new URLSearchParams({
                ajax_action: 'rename_attachment',
                client_id: clientId,
                old_name: fileName,
                new_name: newName
            })
        })
        .then(res => res.json().catch(()=>({success:false, error:'Invalid JSON'})))
        .then(data => {
            if (data.success) {
                const effectiveName = data.file_name || newName;
                document.querySelectorAll('#attachment_list li[data-filename]').forEach(li => {
                    if (li.dataset.filename === fileName) {
                        li.dataset.filename = effectiveName;
                        const strong = li.querySelector('strong');
                        if (strong) {
                            strong.textContent = effectiveName;
                        }
                        li.querySelectorAll('a[data-filename]').forEach(link => {
                            link.setAttribute('data-filename', effectiveName);
                        });
                    }
                });
                const annexLabel = data.display_label || effectiveName;
                document.querySelectorAll('#annexures_list li[data-filename]').forEach(li => {
                    if (li.dataset.filename === fileName) {
                        li.dataset.filename = effectiveName;
                        const strong = li.querySelector('strong');
                        if (strong) {
                            strong.textContent = annexLabel;
                        }
                        li.querySelectorAll('a[data-filename]').forEach(link => {
                            link.setAttribute('data-filename', effectiveName);
                        });
                    }
                });
                if (typeof showToast === 'function') showToast('Renamed: ' + effectiveName);
            } else {
                alert('Rename failed: ' + (data.error || 'Server error'));
            }
        })
        .catch(err => alert('Rename request failed.'));
    }
});
</script>
