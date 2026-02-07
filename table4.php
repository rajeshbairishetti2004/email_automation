<?php
// table4.php
// Section 4: Appropriate Scheme Selection
// Expects: $schemes, $asOn, $clientId

// --- Handle AJAX for save/delete scheme rows directly here ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['table4_action'])) {
    header('Content-Type: application/json');
    require_once __DIR__ . '/db_config.php';
    $pdo = getPdo();

    $action = $_POST['table4_action'];
    $clientId = (int)($_POST['client_id'] ?? 0);

    if ($action === 'delete_scheme_rows') {
        $ids = json_decode($_POST['scheme_ids'] ?? '[]', true);
        if (!is_array($ids)) $ids = [];
        $ids = array_map('intval', $ids);
        $ids = array_filter($ids);
        $deleted = 0;
        if (!empty($ids)) {
            $placeholders = implode(',', array_fill(0, count($ids), '?'));
            $sql = "DELETE FROM client_schemes WHERE client_id = ? AND id IN ($placeholders)";
            $stmt = $pdo->prepare($sql);
            $stmt->execute(array_merge([$clientId], $ids));
            $deleted = $stmt->rowCount();
        }
        echo json_encode(['success' => true, 'deleted' => $deleted]);
        exit;
    }

    if ($action === 'save_scheme_field') {
        $id = (int)($_POST['scheme_id'] ?? 0);
        $field = $_POST['field'] ?? '';
        $value = $_POST['value'] ?? '';
        $allowedFields = ['scheme_name', 'sip_swp', 'current_value', 'action_step', 'recommended_scheme', 'recommended_amount'];
        if (!in_array($field, $allowedFields)) {
            echo json_encode(['success' => false, 'error' => 'Invalid field']);
            exit;
        }
        // Parse SIP/SWP and current_value as numbers with Indian units (allow negative values)
        if ($field === 'current_value' || $field === 'sip_swp' || $field === 'recommended_amount') {
            $v = strtolower(str_replace(',', '', $value));
            $multiplier = 1;
            if (strpos($v, 'cr') !== false) {
                $multiplier = 10000000;
                $v = str_replace('cr', '', $v);
            } elseif (strpos($v, 'lakh') !== false) {
                $multiplier = 100000;
                $v = str_replace('lakh', '', $v);
            } elseif (strpos($v, 'lac') !== false) {
                $multiplier = 100000;
                $v = str_replace('lac', '', $v);
            } elseif (strpos($v, 'k') !== false) {
                $multiplier = 1000;
                $v = str_replace('k', '', $v);
            }
            $v = floatval(preg_replace('/[^0-9\.\-]/', '', $v));
            $value = $v * $multiplier;
        }
        if ($id > 0) {
            $stmt = $pdo->prepare("UPDATE client_schemes SET `$field` = ? WHERE id = ? AND client_id = ?");
            $stmt->execute([$value, $id, $clientId]);
            echo json_encode(['success' => true, 'updated' => $stmt->rowCount()]);
        } else {
            // --- FIX: Only insert a new row if at least scheme_name is not empty ---
            if ($field !== 'scheme_name' && (!isset($_POST['scheme_name']) || trim($_POST['scheme_name']) === '')) {
                echo json_encode(['success' => false, 'error' => 'Scheme name required for new row']);
                exit;
            }

            // Try to find an existing empty row for this client
            $stmt = $pdo->prepare("SELECT id FROM client_schemes WHERE client_id = ? AND scheme_name = '' AND sip_swp = 0.00 AND current_value = 0.00 AND action_step = 'Continue' AND recommended_scheme = '' AND recommended_amount = '' LIMIT 1");
            $stmt->execute([$clientId]);
            $existingEmptyId = $stmt->fetchColumn();

            if ($existingEmptyId) {
                // Update the empty row instead of inserting a new one
                $stmt = $pdo->prepare("UPDATE client_schemes SET `$field` = ? WHERE id = ? AND client_id = ?");
                $stmt->execute([$value, $existingEmptyId, $clientId]);
                echo json_encode(['success' => true, 'new_id' => $existingEmptyId]);
            } else {
                // Insert new row
                $fields = ['scheme_name'=>'','sip_swp'=>'','current_value'=>'','action_step'=>'Continue','recommended_scheme'=>'','recommended_amount'=>''];
                $fields[$field] = $value;
                $stmt = $pdo->prepare("INSERT INTO client_schemes (client_id, scheme_name, sip_swp, current_value, action_step, recommended_scheme, recommended_amount) VALUES (?, ?, ?, ?, ?, ?, ?)");
                $stmt->execute([
                    $clientId, $fields['scheme_name'], $fields['sip_swp'], $fields['current_value'],
                    $fields['action_step'], $fields['recommended_scheme'], $fields['recommended_amount']
                ]);
                $newId = $pdo->lastInsertId();
                echo json_encode(['success' => true, 'inserted' => 1, 'new_id' => $newId]);
            }
        }
        exit;
    }

    if ($action === 'add_scheme_row') {
        // FIX 4: Prevent auto-creation after delete
        if (!empty($_POST['from_delete'])) {
            echo json_encode(['success' => true]);
            exit;
        }
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM client_schemes WHERE client_id = ? AND scheme_name = '' AND sip_swp = 0.00 AND current_value = 0.00 AND action_step = 'Continue' AND recommended_scheme = '' AND recommended_amount = ''");
        $stmt->execute([$clientId]);
        $emptyCount = $stmt->fetchColumn();
        if ($emptyCount == 0) {
            $stmt = $pdo->prepare("INSERT INTO client_schemes (client_id, scheme_name) VALUES (?, '')");
            $stmt->execute([$clientId]);
        }
        echo json_encode(['success' => true]);
        exit;
    }

    echo json_encode(['success' => false, 'error' => 'Unknown action']);
    exit;
}

// --- IMPORTANT: Fix for ID always being 0 ---
// Your table definition is missing AUTO_INCREMENT for the id column.
// This is why every new row gets id=0.

// FIX: Add this migration code ONCE to ensure correct auto-increment.
// You can run this manually in phpMyAdmin or add here for safety:

try {
    $pdo->exec("ALTER TABLE client_schemes MODIFY id INT(11) NOT NULL AUTO_INCREMENT");
} catch (Exception $e) {
    // Ignore if already set
}

// Helper for Indian amount formatting (with negatives)

?>

<!-- =========================
     4. Appropriate Scheme Selection
     ========================= -->

<style>
/* --- Table 4: Appropriate Scheme Selection Styles --- */
.report-table#schemeTable {
    width: 100%;
    border-collapse: collapse;
    margin-bottom: 18px;
    background: #fff;
    border-radius: 8px;
    box-shadow: 0 2px 8px rgba(0,0,0,0.04);
    overflow: hidden;
}
.report-table#schemeTable th,
.report-table#schemeTable td {
    border: 1px solid #e0e0e0;
    padding: 8px 10px;
    text-align: center;
    font-size: 14px;
    min-height: 44px;
    height: 44px;
    line-height: 1.3;
    vertical-align: middle;
    /* Allow wrapping for long text */
    white-space: normal;
    word-break: break-word;
    padding-top: 10px;
    padding-bottom: 10px;
}

/* Set specific widths for columns */
/* Present Schemes - Scheme Name (make much wider for long names) */
.report-table#schemeTable th:nth-child(2),
.report-table#schemeTable td:nth-child(2) {
    width: 370px;
    min-width: 300px;
    max-width: 600px;
    white-space: normal;
    word-break: break-word;
}

/* SIP / SWP (narrower) */
.report-table#schemeTable th:nth-child(3),
.report-table#schemeTable td:nth-child(3) {
    width: 90px;
    min-width: 70px;
    max-width: 110px;
    white-space: nowrap;
}

/* Value as of (narrower) */
.report-table#schemeTable th:nth-child(4),
.report-table#schemeTable td:nth-child(4) {
    width: 100px;
    min-width: 80px;
    max-width: 120px;
    white-space: nowrap;
}

/* Action Step */
.report-table#schemeTable th:nth-child(5),
.report-table#schemeTable td:nth-child(5) {
    width: 120px;
    min-width: 100px;
    max-width: 150px;
    white-space: nowrap;
}

/* Scheme Changes - Scheme Name (allow wrapping and enough height for 2 lines) */
.report-table#schemeTable th:nth-child(6),
.report-table#schemeTable td:nth-child(6) {
    width: 200px;
    min-width: 120px;
    max-width: 250px;
    white-space: normal;
    word-break: break-word;
    vertical-align: middle;
    padding-top: 10px;
    padding-bottom: 10px;
}

/* Make input fields in Scheme Changes - Scheme Name wrap and allow 2 rows */
.report-table#schemeTable td:nth-child(6) .scheme-input {
    min-height: 38px;
    height: auto;
    line-height: 1.3;
    display: block;
    white-space: normal;
    word-break: break-word;
    overflow-wrap: break-word;
    resize: none;
    padding-top: 6px;
    padding-bottom: 6px;
}

/* Amount (narrower) */
.report-table#schemeTable th:nth-child(7),
.report-table#schemeTable td:nth-child(7) {
    width: 80px;
    min-width: 60px;
    max-width: 100px;
    white-space: nowrap;
}

/* Make input fields expand to fit content and wrap text if needed */
.scheme-input {
    width: 100%;
    min-width: 0;
    max-width: 100%;
    padding: 6px 8px;
    font-size: 14px;
    border: none;
    background: transparent;
    text-align: center;
    outline: none;
    transition: background 0.2s;
    white-space: normal;
    word-break: break-word;
    overflow-wrap: break-word;
    min-height: 38px;
    height: 38px;
    line-height: 1.3;
    display: block;
    vertical-align: middle;
    /* Remove overflow hidden to allow full text */
    overflow: visible;
}

/* For select dropdowns, allow full width */
.action-dropdown {
    width: 100%;
    min-width: 0;
    max-width: 100%;
    padding: 6px 8px;
    font-size: 14px;
    border-radius: 4px;
    border: 1px solid #e0e0e0;
    background: #fff;
    outline: none;
    transition: border-color 0.2s;
}
.action-dropdown:focus {
    border-color: #0288D1;
}
#schemeTableActions {
    margin: 10px 0;
    display: flex;
    gap: 10px;
}
.wf-btn.btn-reject {
    background: #f39c12;
    color: #fff;
    border: none;
    border-radius: 5px;
    font-weight: 600;
    padding: 8px 16px;
    cursor: pointer;
    transition: background 0.2s;
}
.wf-btn.btn-reject:hover {
    background: #c0392b;
}
.wf-btn.btn-ready {
    background: #27ae60;
    color: #fff;
    border: none;
    border-radius: 5px;
    font-weight: 600;
    padding: 8px 16px;
    cursor: pointer;
    transition: background 0.2s;
}
.wf-btn.btn-ready:hover {
    background: #219150;
}
.scheme-checkbox-cell {
    width: 32px;
    text-align: center;
}
.scheme-row-checkbox {
    width: 18px;
    height: 18px;
    cursor: pointer;
}
.present-scheme-name input {
    min-width: 120px;
}
</style>

<h3>4. Appropriate Scheme Selection</h3>

<table class="report-table" id="schemeTable">
    <thead>
        <tr>
            <th style="display:none;" class="scheme-checkbox-header"></th>
            <th colspan="3" style=" background: #0288D1; color:white;">Present Schemes</th>
            <th rowspan="2" style=" background: #0288D1; color:white;">Action Step</th>
            <th colspan="2" style=" background: #219150; color:white;">Scheme Changes</th>
        </tr>
        <tr>
            <th style="display:none;" class="scheme-checkbox-header"></th>
            <th style=" background: #0288D1; color:white;">Scheme Name</th>
            <th style=" background: #0288D1; color:white;">SIP / SWP</th>
            <th style=" background: #0288D1; color:white;">
            Value as of<br>
           <?= htmlspecialchars($asOn) ?>
           </th>

            <th style="background: #219150; color:white;">Scheme Name</th>
            <th style="background: #219150; color:white;">Amount</th>
        </tr>
    </thead>
    <tbody>
    <?php if (empty($schemes)): ?>
        <tr>
            <td colspan="7" style="text-align:center; color:#dc3545; font-weight:600; padding:14px;">
                ⚠ No schemes found for this client.
            </td>
        </tr>
    <?php else: ?>
        <?php foreach ($schemes as $s): $schemeId = (int)$s['id']; ?>
        <tr>
            <!-- FIX 1: Put hidden scheme-id inside the checkbox cell -->
            <td class="scheme-checkbox-cell" style="display:none; text-align:center;">
                <input type="checkbox" class="scheme-row-checkbox">
                <input type="hidden" class="scheme-id" value="<?= $schemeId ?>">
            </td>
            <td class="present-scheme-name">
                <input type="text" class="scheme-input" style="border:none; text-align:center;background:transparent;" data-field="scheme_name" data-scheme-id="<?= $schemeId ?>" value="<?= htmlspecialchars($s['scheme_name']) ?>">
            </td>
            <td>
                <input type="text" class="scheme-input" style="border:none; text-align:center;background:transparent;"  data-field="sip_swp" data-scheme-id="<?= $schemeId ?>" value="<?= ($s['sip_swp'] !== null && $s['sip_swp'] !== '') ? htmlspecialchars(formatAmount($s['sip_swp'])) : '' ?>">
            </td>
            <td>
                <input type="text" class="scheme-input" style="border:none; text-align:center;background:transparent;" data-field="current_value" data-scheme-id="<?= $schemeId ?>" value="<?= ($s['current_value'] !== null && $s['current_value'] !== '') ? htmlspecialchars(formatAmount($s['current_value'])) : '' ?>">
            </td>
            <td>
                <select class="action-dropdown" data-field="action_step" data-scheme-id="<?= $schemeId ?>">
                    <?php
                    $steps = ['Continue','Drop','Switch','Partially Redeem','Under Observation','SIP Cancellation'];
                    foreach ($steps as $step):
                    ?>
                        <option value="<?= $step ?>" <?= ($s['action_step'] ?? 'Continue') === $step ? 'selected' : '' ?>>
                            <?= $step ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </td>
            <td>
                <textarea class="scheme-input scheme-textarea"
                    data-field="recommended_scheme"
                    data-scheme-id="<?= $schemeId ?>"
                    rows="2"><?= htmlspecialchars($s['recommended_scheme'] ?? '') ?></textarea>
            </td>
            <td>
                <input type="text" class="scheme-input" data-field="recommended_amount" data-scheme-id="<?= $schemeId ?>" value="<?= ($s['recommended_amount'] !== null && $s['recommended_amount'] !== '') ? htmlspecialchars(formatAmount($s['recommended_amount'])) : '' ?>">
            </td>
        </tr>
        <?php endforeach; ?>
    <?php endif; ?>
    </tbody>
</table>

<div id="schemeTableActions" style="margin: 10px 0; display: flex; gap: 10px;">
    <button type="button" id="toggleSchemeDeleteMode" class="wf-btn btn-reject" style="background:#f39c12;">🗑 Delete Mode</button>
    <button type="button" id="addSchemeRow" class="wf-btn btn-ready" style="background:#27ae60;">+ Add Row</button>
    <button type="button" id="deleteSelectedSchemes" class="wf-btn btn-reject" style="display:none;">Delete Selected</button>
</div>

<script>
// --- Scheme Table Manual Edit/Delete Mode Logic ---
let schemeDeleteMode = false;

function updateSchemeCheckboxesVisibility(show) {
    document.querySelectorAll('#schemeTable tbody tr').forEach(tr => {
        let cb = tr.querySelector('.scheme-row-checkbox');
        if (!cb) {
            const td = document.createElement('td');
            td.style.textAlign = 'center';
            td.style.width = '32px';
            td.className = 'scheme-checkbox-cell';
            cb = document.createElement('input');
            cb.type = 'checkbox';
            cb.className = 'scheme-row-checkbox';
            td.appendChild(cb);
            tr.insertBefore(td, tr.firstChild);
        }
        tr.querySelector('.scheme-checkbox-cell').style.display = show ? '' : 'none';
        cb.checked = false;
    });
    document.querySelectorAll('.scheme-checkbox-header').forEach(th => {
        th.style.display = show ? '' : 'none';
    });
}

document.getElementById('toggleSchemeDeleteMode').onclick = function() {
    schemeDeleteMode = !schemeDeleteMode;
    updateSchemeCheckboxesVisibility(schemeDeleteMode);
    document.getElementById('deleteSelectedSchemes').style.display = schemeDeleteMode ? '' : 'none';
    document.getElementById('addSchemeRow').style.display = schemeDeleteMode ? 'none' : '';
    this.textContent = schemeDeleteMode ? 'Cancel Delete Mode' : '🗑 Delete Mode';
    this.style.background = schemeDeleteMode ? '#c0392b' : '#f39c12';
};

document.getElementById('addSchemeRow').onclick = function() {
    const form = new FormData();
    form.append('table4_action', 'add_scheme_row');
    form.append('client_id', <?= (int)$clientId ?>);
    fetch('table4.php', {
        method: 'POST',
        body: form
    })  
    .then(r => r.json())
    .then(res => {
        if (res.success) location.reload();
    });
};

document.getElementById('deleteSelectedSchemes').onclick = function () {
    const checked = [...document.querySelectorAll('.scheme-row-checkbox:checked')];
    if (checked.length === 0) return;

    if (!confirm('Delete selected scheme rows permanently?')) return;

    const idsToDelete = checked.map(cb => {
        const tr = cb.closest('tr');
        return tr.querySelector('.scheme-id')?.value;
    }).filter(id => id && id !== "0");

    if (idsToDelete.length === 0) return;

    const form = new FormData();
    form.append('table4_action', 'delete_scheme_rows');
    form.append('client_id', <?= (int)$clientId ?>);
    form.append('scheme_ids', JSON.stringify(idsToDelete));

    fetch('table4.php', { method: 'POST', body: form })
        .then(r => r.json())
        .then(res => {
            if (res.success) {
                checked.forEach(cb => cb.closest('tr').remove());
            } else {
                alert('Delete failed: ' + (res.error || 'Unknown error'));
            }
        });
};

function autoSaveSchemeField(schemeId, field, value) {
    const form = new FormData();
    form.append('table4_action', 'save_scheme_field');
    form.append('client_id', <?= (int)$clientId ?>);
    form.append('scheme_id', schemeId);
    form.append('field', field);
    form.append('value', value);
    fetch('table4.php', {
        method: 'POST',
        body: form
    })
    .then(r => r.json())
    .then(res => {
        if (res.success && res.new_id) {
            const tr = document.querySelector('input[data-field="' + field + '"][data-scheme-id="0"]').closest('tr');
            if (tr) {
                tr.querySelectorAll('[data-scheme-id]').forEach(el => el.setAttribute('data-scheme-id', res.new_id));
                const hiddenId = tr.querySelector('.scheme-id');
                if (hiddenId) hiddenId.value = res.new_id;
            }
        }
    });
}

// Debounced autosave for textarea
document.querySelector('#schemeTable').addEventListener('input', function (e) {
    if (!e.target.classList.contains('scheme-textarea')) return;
    const field = e.target.getAttribute('data-field');
    const value = e.target.value;
    const schemeId = e.target.getAttribute('data-scheme-id') || 0;
    clearTimeout(e.target._saveTimer);
    e.target._saveTimer = setTimeout(() => {
        autoSaveSchemeField(schemeId, field, value);
    }, 600);
});

// Fallback for textarea autosave on change (for paste/autofill/focusout)
document.querySelector('#schemeTable').addEventListener('change', function (e) {
    if (!e.target.classList.contains('scheme-textarea')) return;
    const field = e.target.getAttribute('data-field');
    const value = e.target.value;
    const schemeId = e.target.getAttribute('data-scheme-id') || 0;
    autoSaveSchemeField(schemeId, field, value);
});

// Autosave on blur for all other inputs, and on change for select
document.querySelectorAll('#schemeTable').forEach(function(table) {
    table.addEventListener('blur', function(e) {
        const input = e.target;
        if (input.classList.contains('scheme-input') && !input.classList.contains('scheme-textarea')) {
            const field = input.getAttribute('data-field');
            let value = input.value;
            const schemeId = input.getAttribute('data-scheme-id') || 0;
            autoSaveSchemeField(schemeId, field, value);
        }
    }, true);

    table.addEventListener('change', function(e) {
        const select = e.target;
        if (select.classList.contains('action-dropdown')) {
            const field = select.getAttribute('data-field');
            const value = select.value;
            const schemeId = select.getAttribute('data-scheme-id') || 0;
            autoSaveSchemeField(schemeId, field, value);

            // --- NEW: If SIP Cancellation selected, copy Present Scheme Name to Scheme Changes ---
            if (value === 'SIP Cancellation') {
                const tr = select.closest('tr');
                const presentSchemeInput = tr.querySelector('input[data-field="scheme_name"]');
                const schemeChangesTextarea = tr.querySelector('textarea[data-field="recommended_scheme"]');
                if (presentSchemeInput && schemeChangesTextarea) {
                    schemeChangesTextarea.value = presentSchemeInput.value;
                    // Trigger autosave for textarea
                    autoSaveSchemeField(schemeId, 'recommended_scheme', presentSchemeInput.value);
                }
            }
        }
    }, true);
});

updateSchemeCheckboxesVisibility(false);
</script>
<!-- =========================
     End Appropriate Scheme Selection
     ========================= -->
