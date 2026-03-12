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

        // =================================================================
        // CRITICAL FIX: JS now sends the RAW number stored in data-raw,
        // NOT the formatted display string. parseIndianNumber() is kept as
        // a safety net for human-typed values like "5 lakhs" or "10k".
        // If the value is already a plain number (e.g. "711547.60"),
        // parseIndianNumber() returns it unchanged because is_numeric()
        // catches it first — multiplier stays at 1.
        // =================================================================
        if (in_array($field, ['current_value', 'sip_swp', 'recommended_amount'])) {
            $value = parseIndianNumber($value);
        }

        if ($id > 0) {
            $stmt = $pdo->prepare("UPDATE client_schemes SET `$field` = ? WHERE id = ? AND client_id = ?");
            $stmt->execute([$value, $id, $clientId]);
            echo json_encode(['success' => true, 'updated' => $stmt->rowCount()]);
        } else {
            if ($field !== 'scheme_name' && (!isset($_POST['scheme_name']) || trim($_POST['scheme_name']) === '')) {
                echo json_encode(['success' => false, 'error' => 'Scheme name required for new row']);
                exit;
            }
            $stmt = $pdo->prepare("SELECT id FROM client_schemes WHERE client_id = ? AND scheme_name = '' AND sip_swp = 0.00 AND current_value = 0.00 AND action_step = 'Continue' AND recommended_scheme = '' AND recommended_amount = '' LIMIT 1");
            $stmt->execute([$clientId]);
            $existingEmptyId = $stmt->fetchColumn();

            if ($existingEmptyId) {
                $stmt = $pdo->prepare("UPDATE client_schemes SET `$field` = ? WHERE id = ? AND client_id = ?");
                $stmt->execute([$value, $existingEmptyId, $clientId]);
                echo json_encode(['success' => true, 'new_id' => $existingEmptyId]);
            } else {
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

try {
    $pdo->exec("ALTER TABLE client_schemes MODIFY id INT(11) NOT NULL AUTO_INCREMENT");
} catch (Exception $e) {}
?>

<style>
.report-table#schemeTable {
    width: 100%; border-collapse: collapse; margin-bottom: 18px;
    background: #fff; border-radius: 8px;
    box-shadow: 0 2px 8px rgba(0,0,0,0.04); overflow: hidden;
}
.report-table#schemeTable th,
.report-table#schemeTable td {
    border: 1px solid #e0e0e0; padding: 8px 10px; text-align: center;
    font-size: 14px; min-height: 44px; height: 44px; line-height: 1.3;
    vertical-align: middle; white-space: normal; word-break: break-word;
    padding-top: 10px; padding-bottom: 10px;
}
.report-table#schemeTable th:nth-child(2),
.report-table#schemeTable td:nth-child(2) {
    width: 370px; min-width: 300px; max-width: 600px;
    white-space: normal; word-break: break-word;
}
.report-table#schemeTable th:nth-child(3),
.report-table#schemeTable td:nth-child(3) { width: 90px; min-width: 70px; max-width: 110px; white-space: nowrap; }
.report-table#schemeTable th:nth-child(4),
.report-table#schemeTable td:nth-child(4) { width: 100px; min-width: 80px; max-width: 120px; white-space: nowrap; }
.report-table#schemeTable th:nth-child(5),
.report-table#schemeTable td:nth-child(5) { width: 120px; min-width: 100px; max-width: 150px; white-space: nowrap; }
.report-table#schemeTable th:nth-child(6),
.report-table#schemeTable td:nth-child(6) {
    width: 200px; min-width: 120px; max-width: 250px;
    white-space: normal; word-break: break-word;
    vertical-align: middle; padding-top: 10px; padding-bottom: 10px;
}
.report-table#schemeTable td:nth-child(6) .scheme-input {
    min-height: 38px; height: auto; line-height: 1.3; display: block;
    white-space: normal; word-break: break-word; overflow-wrap: break-word;
    resize: none; padding-top: 6px; padding-bottom: 6px;
}
.report-table#schemeTable th:nth-child(7),
.report-table#schemeTable td:nth-child(7) { width: 80px; min-width: 60px; max-width: 100px; white-space: nowrap; }
.scheme-input {
    width: 100%; min-width: 0; max-width: 100%;
    padding: 6px 8px; font-size: 14px; border: none; background: transparent;
    text-align: center; outline: none; transition: background 0.2s;
    white-space: normal; word-break: break-word; overflow-wrap: break-word;
    min-height: 38px; height: 38px; line-height: 1.3;
    display: block; vertical-align: middle; overflow: visible;
}
.action-dropdown {
    width: 100%; min-width: 0; max-width: 100%;
    padding: 6px 8px; font-size: 14px; border-radius: 4px;
    border: 1px solid #e0e0e0; background: #fff; outline: none;
    transition: border-color 0.2s;
}
.action-dropdown:focus { border-color: #0288D1; }
#schemeTableActions { margin: 10px 0; display: flex; gap: 10px; }
.wf-btn.btn-reject {
    background: #f39c12; color: #fff; border: none; border-radius: 5px;
    font-weight: 600; padding: 8px 16px; cursor: pointer; transition: background 0.2s;
}
.wf-btn.btn-reject:hover { background: #c0392b; }
.wf-btn.btn-ready {
    background: #27ae60; color: #fff; border: none; border-radius: 5px;
    font-weight: 600; padding: 8px 16px; cursor: pointer; transition: background 0.2s;
}
.wf-btn.btn-ready:hover { background: #219150; }
.scheme-checkbox-cell { width: 32px; text-align: center; }
.scheme-row-checkbox { width: 18px; height: 18px; cursor: pointer; }
.present-scheme-name input { min-width: 120px; }
</style>

<h3>4. Appropriate Scheme Selection</h3>

<table class="report-table" id="schemeTable">
    <thead>
        <tr>
            <th style="display:none;" class="scheme-checkbox-header"></th>
            <th colspan="3" style="background: #0288D1; color:white;">Present Schemes</th>
            <th rowspan="2" style="background: #0288D1; color:white;">Action Step</th>
            <th colspan="2" style="background: #219150; color:white;">Scheme Changes</th>
        </tr>
        <tr>
            <th style="display:none;" class="scheme-checkbox-header"></th>
            <th style="background: #0288D1; color:white;">Scheme Name</th>
            <th style="background: #0288D1; color:white;">SIP / SWP</th>
            <th style="background: #0288D1; color:white;">Value as of<br><?= htmlspecialchars($asOn) ?></th>
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
        <?php
            // data-raw = plain DB number (e.g. 711547.60)
            // value    = formatted display (e.g. "7.12 lakh")
            // JS sends data-raw to PHP on save — never the display string.
            $sipRaw    = $s['sip_swp'] ?? '';
            $cvRaw     = $s['current_value'] ?? '';
            $raRaw     = $s['recommended_amount'] ?? '';
            $sipDisplay = ($sipRaw !== null && $sipRaw !== '') ? htmlspecialchars(formatAmount($sipRaw)) : '';
            $cvDisplay  = ($cvRaw  !== null && $cvRaw  !== '') ? htmlspecialchars(formatAmount($cvRaw))  : '';
            // recommended_amount may be text like "5 Lakhs" — only format if numeric
            $raDisplay  = (is_numeric($raRaw) && $raRaw !== '') ? htmlspecialchars(formatAmount($raRaw)) : htmlspecialchars($raRaw);
        ?>
        <tr>
            <td class="scheme-checkbox-cell" style="display:none; text-align:center;">
                <input type="checkbox" class="scheme-row-checkbox">
                <input type="hidden" class="scheme-id" value="<?= $schemeId ?>">
            </td>
            <td class="present-scheme-name">
                <input type="text"
                    class="scheme-input"
                    style="border:none; text-align:center; background:transparent;"
                    data-field="scheme_name"
                    data-scheme-id="<?= $schemeId ?>"
                    value="<?= htmlspecialchars($s['scheme_name']) ?>">
            </td>
            <td>
                <input type="text"
                    class="scheme-input scheme-number-input"
                    style="border:none; text-align:center; background:transparent;"
                    data-field="sip_swp"
                    data-scheme-id="<?= $schemeId ?>"
                    data-raw="<?= htmlspecialchars($sipRaw) ?>"
                    value="<?= $sipDisplay ?>">
            </td>
            <td>
                <input type="text"
                    class="scheme-input scheme-number-input"
                    style="border:none; text-align:center; background:transparent;"
                    data-field="current_value"
                    data-scheme-id="<?= $schemeId ?>"
                    data-raw="<?= htmlspecialchars($cvRaw) ?>"
                    value="<?= $cvDisplay ?>">
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
                <input type="text"
                    class="scheme-input scheme-number-input"
                    data-field="recommended_amount"
                    data-scheme-id="<?= $schemeId ?>"
                    data-raw="<?= htmlspecialchars($raRaw) ?>"
                    value="<?= $raDisplay ?>">
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
(function () {

    // ==========================================================================
    // HOW THIS FIX WORKS
    // ==========================================================================
    // Problem: Two blur listeners fight over number inputs:
    //   A) table4.php's own blur handler (this file)
    //   B) view_report.php's generic .scheme-input/.action-dropdown blur handler
    //      which sends the formatted display string to view_report.php's
    //      ajax_scheme handler — that handler has NO number parser and
    //      stores the raw string, corrupting the DB.
    //
    // Fix strategy:
    //   1. Mark scheme-number-input elements with data-t4-managed="true".
    //      view_report.php's listener should skip elements with this attribute
    //      (add one line there — see note at bottom of this script).
    //   2. Our blur handler runs in CAPTURE phase (fires before bubble listeners).
    //      - For number inputs: always stopImmediatePropagation() so B never fires.
    //      - If unchanged: restore display, skip save.
    //      - If changed: parse, reformat, update data-raw, send raw to PHP.
    //   3. data-raw holds the plain DB number. We ALWAYS send data-raw to PHP.
    //      PHP's parseIndianNumber() passes plain numbers through unchanged.
    // ==========================================================================

    // Tag all number inputs so view_report.php can identify and skip them
    document.querySelectorAll('#schemeTable .scheme-number-input').forEach(function (input) {
        input.setAttribute('data-t4-managed', 'true');
    });

    // ---- JS number helpers ----
    function parseIndianNumberJS(value) {
        if (value === null || value === undefined || value === '') return '';
        let v = String(value).toLowerCase().trim().replace(/[₹\s,]/g, '');
        let multiplier = 1;
        if      (v.includes('cr'))   { multiplier = 10000000; v = v.replace(/cr/g,   ''); }
        else if (v.includes('lakh')) { multiplier = 100000;   v = v.replace(/lakh/g, ''); }
        else if (v.includes('lac'))  { multiplier = 100000;   v = v.replace(/lac/g,  ''); }
        else if (v.includes('k'))    { multiplier = 1000;     v = v.replace(/k/g,    ''); }
        v = v.replace(/[^0-9.\-]/g, '');
        const num = parseFloat(v || '0') * multiplier;
        return isNaN(num) ? '' : String(num);
    }

    function formatAmountJS(value) {
        const n = parseFloat(value);
        if (isNaN(n) || value === '' || value === null) return String(value ?? '');
        if (n === 0) return '0';
        const sign = n < 0 ? '-' : '';
        const abs  = Math.abs(n);
        let f;
        if      (abs >= 10000000) f = _t(abs / 10000000, 2) + ' Cr';
        else if (abs >= 100000)   f = _t(abs / 100000,   2) + ' lakh';
        else if (abs >= 1000)     f = _t(abs / 1000,     2) + 'k';
        else                      f = Math.round(abs).toString();
        return sign + f;
    }

    function _t(num, dec) { return parseFloat(num.toFixed(dec)).toString(); }

    // ---- POST to table4.php ----
    function autoSaveSchemeField(schemeId, field, value) {
        const form = new FormData();
        form.append('table4_action', 'save_scheme_field');
        form.append('client_id', <?= (int)$clientId ?>);
        form.append('scheme_id', schemeId);
        form.append('field', field);
        form.append('value', value);
        fetch('table4.php', { method: 'POST', body: form })
            .then(r => r.json())
            .then(res => {
                if (res.success && res.new_id) {
                    const tr = document.querySelector('[data-field="' + field + '"][data-scheme-id="0"]')?.closest('tr');
                    if (tr) {
                        tr.querySelectorAll('[data-scheme-id]').forEach(el => el.setAttribute('data-scheme-id', res.new_id));
                        const h = tr.querySelector('.scheme-id');
                        if (h) h.value = res.new_id;
                    }
                }
            })
            .catch(err => console.error('table4 save error:', err));
    }

    const schemeTable = document.querySelector('#schemeTable');

    // ---- FOCUS: show raw number for editing ----
    schemeTable.addEventListener('focus', function (e) {
        const input = e.target;
        if (!input.classList.contains('scheme-number-input')) return;
        input.setAttribute('data-display-backup', input.value);
        const raw = input.getAttribute('data-raw') ?? '';
        input.value = raw;
        input.select();
    }, true);

    // ---- BLUR (CAPTURE — fires before view_report.php's bubble listener) ----
    schemeTable.addEventListener('blur', function (e) {
        const input = e.target;

        // ── Number inputs ──────────────────────────────────────────────────
        if (input.classList.contains('scheme-number-input')) {
            // Always stop propagation — view_report.php must NOT also handle this
            e.stopImmediatePropagation();

            const field    = input.getAttribute('data-field');
            const schemeId = input.getAttribute('data-scheme-id') || 0;
            const oldRaw   = input.getAttribute('data-raw') ?? '';
            const typed    = input.value.trim();

            if (typed === '' || typed === oldRaw) {
                // Unchanged — restore formatted display, skip save
                input.value = input.getAttribute('data-display-backup') || formatAmountJS(oldRaw);
                return;
            }

            // User typed a new value
            const parsedRaw = parseIndianNumberJS(typed);
            const newDisplay = formatAmountJS(parsedRaw !== '' ? parsedRaw : typed);

            input.setAttribute('data-raw', parsedRaw !== '' ? parsedRaw : typed);
            input.value = newDisplay;
            input.setAttribute('data-display-backup', newDisplay);

            // Send RAW number to PHP
            autoSaveSchemeField(schemeId, field, parsedRaw !== '' ? parsedRaw : typed);
            return;
        }

        // ── scheme_name text input ─────────────────────────────────────────
        if (input.classList.contains('scheme-input') && !input.classList.contains('scheme-textarea')) {
            const field = input.getAttribute('data-field');
            if (field === 'scheme_name') {
                autoSaveSchemeField(input.getAttribute('data-scheme-id') || 0, field, input.value);
                e.stopImmediatePropagation(); // prevent double-save from view_report.php
            }
        }

    }, true);

    // ---- CHANGE: action_step dropdown + textarea fallback ----
    schemeTable.addEventListener('change', function (e) {
        if (e.target.classList.contains('scheme-textarea')) {
            autoSaveSchemeField(
                e.target.getAttribute('data-scheme-id') || 0,
                e.target.getAttribute('data-field'),
                e.target.value
            );
            return;
        }

        if (e.target.classList.contains('action-dropdown')) {
            const schemeId = e.target.getAttribute('data-scheme-id') || 0;
            autoSaveSchemeField(schemeId, 'action_step', e.target.value);
            e.stopImmediatePropagation(); // prevent view_report.php double-save

            if (e.target.value === 'SIP Cancellation') {
                const tr = e.target.closest('tr');
                const nameInput   = tr.querySelector('input[data-field="scheme_name"]');
                const recTextarea = tr.querySelector('textarea[data-field="recommended_scheme"]');
                if (nameInput && recTextarea) {
                    recTextarea.value = nameInput.value;
                    autoSaveSchemeField(schemeId, 'recommended_scheme', nameInput.value);
                }
            }
        }
    }, true);

    // ---- Textarea: debounced input ----
    schemeTable.addEventListener('input', function (e) {
        if (!e.target.classList.contains('scheme-textarea')) return;
        clearTimeout(e.target._saveTimer);
        e.target._saveTimer = setTimeout(() => {
            autoSaveSchemeField(
                e.target.getAttribute('data-scheme-id') || 0,
                e.target.getAttribute('data-field'),
                e.target.value
            );
        }, 600);
    });

    // ---- Delete mode ----
    let schemeDeleteMode = false;

    function updateSchemeCheckboxesVisibility(show) {
        document.querySelectorAll('#schemeTable tbody tr').forEach(tr => {
            let cb = tr.querySelector('.scheme-row-checkbox');
            if (!cb) {
                const td = document.createElement('td');
                td.style.cssText = 'text-align:center; width:32px;';
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

    document.getElementById('toggleSchemeDeleteMode').onclick = function () {
        schemeDeleteMode = !schemeDeleteMode;
        updateSchemeCheckboxesVisibility(schemeDeleteMode);
        document.getElementById('deleteSelectedSchemes').style.display = schemeDeleteMode ? '' : 'none';
        document.getElementById('addSchemeRow').style.display = schemeDeleteMode ? 'none' : '';
        this.textContent      = schemeDeleteMode ? 'Cancel Delete Mode' : '🗑 Delete Mode';
        this.style.background = schemeDeleteMode ? '#c0392b' : '#f39c12';
    };

    document.getElementById('addSchemeRow').onclick = function () {
        const form = new FormData();
        form.append('table4_action', 'add_scheme_row');
        form.append('client_id', <?= (int)$clientId ?>);
        fetch('table4.php', { method: 'POST', body: form })
            .then(r => r.json())
            .then(res => { if (res.success) location.reload(); });
    };

    document.getElementById('deleteSelectedSchemes').onclick = function () {
        const checked = [...document.querySelectorAll('.scheme-row-checkbox:checked')];
        if (checked.length === 0) return;
        if (!confirm('Delete selected scheme rows permanently?')) return;
        const idsToDelete = checked
            .map(cb => cb.closest('tr').querySelector('.scheme-id')?.value)
            .filter(id => id && id !== '0');
        if (idsToDelete.length === 0) return;
        const form = new FormData();
        form.append('table4_action', 'delete_scheme_rows');
        form.append('client_id', <?= (int)$clientId ?>);
        form.append('scheme_ids', JSON.stringify(idsToDelete));
        fetch('table4.php', { method: 'POST', body: form })
            .then(r => r.json())
            .then(res => {
                if (res.success) checked.forEach(cb => cb.closest('tr').remove());
                else alert('Delete failed: ' + (res.error || 'Unknown error'));
            });
    };

    updateSchemeCheckboxesVisibility(false);

    // ==========================================================================
    // REQUIRED CHANGE IN view_report.php
    // ==========================================================================
    // Find this block in view_report.php (around line 650+):
    //
    //   document.querySelectorAll('.action-dropdown, .scheme-input').forEach(function(element) {
    //       const eventType = element.classList.contains('action-dropdown') ? 'change' : 'blur';
    //       element.addEventListener(eventType, function() {
    //           const schemeId = element.getAttribute('data-scheme-id');
    //           ...fetch('view_report.php', ...)
    //
    // Add this ONE LINE at the very top of the callback, before anything else:
    //
    //   if (element.getAttribute('data-t4-managed') === 'true') return;
    //
    // This prevents view_report.php from also saving number inputs that
    // table4.php already handles, eliminating the double-save entirely.
    // ==========================================================================

})();
</script>
<!-- End: Appropriate Scheme Selection -->