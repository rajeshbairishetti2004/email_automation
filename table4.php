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
        // FIX 3: Ensure IDs are integers and not empty
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
        if ($field === 'current_value' || $field === 'sip_swp') {
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
.report-table#schemeTable th, .report-table#schemeTable td {
    border: 1px solid #e0e0e0;
    padding: 8px 10px;
    text-align: center;
    font-size: 14px;
}
.report-table#schemeTable th {
    background: #0288D1;
    font-weight: 600;
}
.scheme-input {
    width: 100%;
    padding: 6px 8px;
    font-size: 14px;
    border: none;
    background: transparent;
    text-align: center;
    outline: none;
    transition: background 0.2s;
}
.scheme-input:focus {
    background: #e3f2fd;
}
.action-dropdown {
    width: 100%;
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
            <th colspan="3">Present Schemes</th>
            <th rowspan="2">Action Step</th>
            <th colspan="2">Scheme Changes</th>
        </tr>
        <tr>
            <th style="display:none;" class="scheme-checkbox-header"></th>
            <th>Scheme Name</th>
            <th>SIP / SWP</th>
            <th>Value as of <?= htmlspecialchars($asOn) ?></th>
            <th>Scheme Name</th>
            <th>Amount</th>
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
                <input type="text" class="scheme-input" style="border:none; text-align:center;background:transparent;"  data-field="sip_swp" data-scheme-id="<?= $schemeId ?>" value="<?= ((float)$s['sip_swp'] > 0) ? htmlspecialchars(formatAmount($s['sip_swp'])) : '-' ?>">
            </td>
            <td>
                <input type="text" class="scheme-input" style="border:none; text-align:center;background:transparent;" data-field="current_value" data-scheme-id="<?= $schemeId ?>" value="<?= htmlspecialchars(formatAmount((float)$s['current_value'])) ?>">
            </td>
            <td>
                <select class="action-dropdown" data-field="action_step" data-scheme-id="<?= $schemeId ?>">
                    <?php
                    $steps = ['Continue','Drop','Switch','Partially Redeem','Under Observation'];
                    foreach ($steps as $step):
                    ?>
                        <option value="<?= $step ?>" <?= ($s['action_step'] ?? 'Continue') === $step ? 'selected' : '' ?>>
                            <?= $step ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </td>
            <td>
                <input type="text" class="scheme-input"  data-field="recommended_scheme" data-scheme-id="<?= $schemeId ?>" value="<?= htmlspecialchars($s['recommended_scheme'] ?? '') ?>">
            </td>
            <td>
                <input type="text" class="scheme-input" data-field="recommended_amount" data-scheme-id="<?= $schemeId ?>" value="<?= htmlspecialchars($s['recommended_amount'] ?? '') ?>">
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
    <!-- Removed Save All Changes button -->
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

// FIX 2: Only remove row after server confirms delete
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
                // ✅ NOW remove rows from UI
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

document.querySelectorAll('#schemeTable').forEach(function(table) {
    table.addEventListener('blur', function(e) {
        const input = e.target;
        if (input.classList.contains('scheme-input')) {
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
        }
    }, true);
});

updateSchemeCheckboxesVisibility(false);
</script>
<!-- =========================
     End Appropriate Scheme Selection
     ========================= -->
