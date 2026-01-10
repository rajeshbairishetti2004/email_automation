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
        $deleted = 0;
        foreach ($ids as $id) {
            $id = (int)$id;
            if ($id > 0) {
                $stmt = $pdo->prepare("DELETE FROM client_schemes WHERE id = ? AND client_id = ?");
                $stmt->execute([$id, $clientId]);
                $deleted += $stmt->rowCount();
            }
        }
        echo json_encode(['success' => true, 'deleted' => $deleted]);
        exit;
    }

    if ($action === 'save_scheme_table') {
        $rows = json_decode($_POST['rows'] ?? '[]', true);
        if (!is_array($rows)) $rows = [];
        $updated = 0; $inserted = 0;
        foreach ($rows as $row) {
            $id = (int)($row['id'] ?? 0);
            $fields = [
                'scheme_name' => trim($row['scheme_name'] ?? ''),
                'sip_swp' => trim($row['sip_swp'] ?? ''),
                // FIX: Parse and store numeric value for current_value
                'current_value' => preg_replace('/[^\d.]/', '', str_replace(',', '', $row['current_value'] ?? '')),
                'action_step' => trim($row['action_step'] ?? ''),
                'recommended_scheme' => trim($row['recommended_scheme'] ?? ''),
                'recommended_amount' => trim($row['recommended_amount'] ?? '')
            ];
            if ($id > 0) {
                // Update
                $stmt = $pdo->prepare("UPDATE client_schemes SET scheme_name=?, sip_swp=?, current_value=?, action_step=?, recommended_scheme=?, recommended_amount=? WHERE id=? AND client_id=?");
                $stmt->execute([
                    $fields['scheme_name'], $fields['sip_swp'], $fields['current_value'],
                    $fields['action_step'], $fields['recommended_scheme'], $fields['recommended_amount'],
                    $id, $clientId
                ]);
                $updated += $stmt->rowCount();
            } else {
                // Insert
                $stmt = $pdo->prepare("INSERT INTO client_schemes (client_id, scheme_name, sip_swp, current_value, action_step, recommended_scheme, recommended_amount) VALUES (?, ?, ?, ?, ?, ?, ?)");
                $stmt->execute([
                    $clientId, $fields['scheme_name'], $fields['sip_swp'], $fields['current_value'],
                    $fields['action_step'], $fields['recommended_scheme'], $fields['recommended_amount']
                ]);
                $inserted += $stmt->rowCount();
            }
        }
        echo json_encode(['success' => true, 'updated' => $updated, 'inserted' => $inserted]);
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
            // Allow negative numbers
            $v = floatval(preg_replace('/[^0-9\.\-]/', '', $v));
            $value = $v * $multiplier;
        }
        if ($id > 0) {
            $stmt = $pdo->prepare("UPDATE client_schemes SET `$field` = ? WHERE id = ? AND client_id = ?");
            $stmt->execute([$value, $id, $clientId]);
            echo json_encode(['success' => true, 'updated' => $stmt->rowCount()]);
        } else {
            // Insert new row with only this field (others blank)
            $fields = ['scheme_name'=>'','sip_swp'=>'','current_value'=>'','action_step'=>'','recommended_scheme'=>'','recommended_amount'=>''];
            $fields[$field] = $value;
            $stmt = $pdo->prepare("INSERT INTO client_schemes (client_id, scheme_name, sip_swp, current_value, action_step, recommended_scheme, recommended_amount) VALUES (?, ?, ?, ?, ?, ?, ?)");
            $stmt->execute([
                $clientId, $fields['scheme_name'], $fields['sip_swp'], $fields['current_value'],
                $fields['action_step'], $fields['recommended_scheme'], $fields['recommended_amount']
            ]);
            $newId = $pdo->lastInsertId();
            echo json_encode(['success' => true, 'inserted' => 1, 'new_id' => $newId]);
        }
        exit;
    }

    echo json_encode(['success' => false, 'error' => 'Unknown action']);
    exit;
}
?>

<!-- =========================
     4. Appropriate Scheme Selection
     ========================= -->

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
            <td class="scheme-checkbox-cell" style="display:none; text-align:center;">
                <input type="checkbox" class="scheme-row-checkbox">
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
            <input type="hidden" class="scheme-id" value="<?= $schemeId ?>">
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

// Add checkboxes to each row (hidden by default)
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
    // Add/remove header cell
    document.querySelectorAll('.scheme-checkbox-header').forEach(th => {
        th.style.display = show ? '' : 'none';
    });
}

// Toggle Delete Mode
document.getElementById('toggleSchemeDeleteMode').onclick = function() {
    schemeDeleteMode = !schemeDeleteMode;
    updateSchemeCheckboxesVisibility(schemeDeleteMode);
    document.getElementById('deleteSelectedSchemes').style.display = schemeDeleteMode ? '' : 'none';
    document.getElementById('addSchemeRow').style.display = schemeDeleteMode ? 'none' : '';
    this.textContent = schemeDeleteMode ? 'Cancel Delete Mode' : '🗑 Delete Mode';
    this.style.background = schemeDeleteMode ? '#c0392b' : '#f39c12';
};

// Add Row
document.getElementById('addSchemeRow').onclick = function() {
    const tbody = document.querySelector('#schemeTable tbody');
    const tr = document.createElement('tr');
    tr.innerHTML =
        '<td class="scheme-checkbox-cell" style="display:none;text-align:center;"><input type="checkbox" class="scheme-row-checkbox"></td>' +
        '<td class="present-scheme-name"><input type="text" class="scheme-input" data-field="scheme_name" placeholder="Scheme Name"></td>' +
        '<td><input type="text" class="scheme-input" data-field="sip_swp" placeholder="SIP/SWP"></td>' +
        '<td><input type="text" class="scheme-input" data-field="current_value" placeholder="Value"></td>' +
        '<td><select class="action-dropdown" data-field="action_step">' +
            '<option value="Continue">Continue</option>' +
            '<option value="Drop">Drop</option>' +
            '<option value="Switch">Switch</option>' +
            '<option value="Partially Redeem">Partially Redeem</option>' +
            '<option value="Under Observation">Under Observation</option>' +
        '</select></td>' +
        '<td><input type="text" class="scheme-input" data-field="recommended_scheme" placeholder="Recommended Scheme"></td>' +
        '<td><input type="text" class="scheme-input" data-field="recommended_amount" placeholder="Amount/Note"></td>' +
        '<input type="hidden" class="scheme-id" value="0">';
    tbody.appendChild(tr);
};

// Delete Selected
document.getElementById('deleteSelectedSchemes').onclick = function() {
    const checked = Array.from(document.querySelectorAll('.scheme-row-checkbox:checked'));
    if (checked.length === 0) return;
    if (!confirm('Delete selected scheme rows?')) return;
    // Collect IDs for DB deletion (only for rows with .scheme-id > 0)
    const idsToDelete = [];
    checked.forEach(cb => {
        const tr = cb.closest('tr');
        const id = tr.querySelector('.scheme-id')?.value;
        if (id && id !== "0") idsToDelete.push(id);
        tr.remove();
    });
    if (idsToDelete.length > 0) {
        const form = new FormData();
        form.append('table4_action', 'delete_scheme_rows');
        form.append('client_id', <?= (int)$clientId ?>);
        form.append('scheme_ids', JSON.stringify(idsToDelete));
        fetch('table4.php', {
            method: 'POST',
            body: form
        })
        .then(r => r.json())
        .then(res => {
            if (!res.success) alert('Error deleting from database: ' + (res.error || ''));
        });
    }
};

// --- Auto-save on blur/change for all fields ---
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
        // Optionally handle new_id for new rows
        if (res.success && res.new_id) {
            // Update hidden .scheme-id and all data-scheme-id attributes in this row
            const tr = document.querySelector('input[data-field="' + field + '"][data-scheme-id="0"]').closest('tr');
            if (tr) {
                tr.querySelectorAll('[data-scheme-id]').forEach(el => el.setAttribute('data-scheme-id', res.new_id));
                const hiddenId = tr.querySelector('.scheme-id');
                if (hiddenId) hiddenId.value = res.new_id;
            }
        }
    });
}

// Attach auto-save listeners
document.querySelectorAll('#schemeTable').forEach(function(table) {
    table.addEventListener('blur', function(e) {
        const input = e.target;
        if (input.classList.contains('scheme-input')) {
            const field = input.getAttribute('data-field');
            let value = input.value;
            // Do not strip units here, send as-is for PHP to parse
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

// On page load, hide checkboxes
updateSchemeCheckboxesVisibility(false);
</script>
<!-- =========================
     End Appropriate Scheme Selection
     ========================= -->
