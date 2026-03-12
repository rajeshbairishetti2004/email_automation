<?php
// table4.php
// Section 4: Appropriate Scheme Selection
// Expects: $schemes, $asOn, $clientId

// --- Handle AJAX for save/delete scheme rows directly here ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['table4_action'])) {
    header('Content-Type: application/json');
    require_once __DIR__ . '/db_config.php';
    $pdo = getPdo();

    $action   = $_POST['table4_action'];
    $clientId = (int)($_POST['client_id'] ?? 0);

    if ($action === 'delete_scheme_rows') {
        $ids = json_decode($_POST['scheme_ids'] ?? '[]', true);
        if (!is_array($ids)) $ids = [];
        $ids = array_map('intval', $ids);
        $ids = array_filter($ids);
        $deleted = 0;
        if (!empty($ids)) {
            $placeholders = implode(',', array_fill(0, count($ids), '?'));
            $sql  = "DELETE FROM client_schemes WHERE client_id = ? AND id IN ($placeholders)";
            $stmt = $pdo->prepare($sql);
            $stmt->execute(array_merge([$clientId], $ids));
            $deleted = $stmt->rowCount();
        }
        echo json_encode(['success' => true, 'deleted' => $deleted]);
        exit;
    }

    if ($action === 'save_scheme_field') {
        $id    = (int)($_POST['scheme_id'] ?? 0);
        $field = $_POST['field'] ?? '';
        $value = $_POST['value'] ?? '';
        $allowedFields = ['scheme_name','sip_swp','current_value','action_step','recommended_scheme','recommended_amount'];
        if (!in_array($field, $allowedFields)) {
            echo json_encode(['success' => false, 'error' => 'Invalid field']);
            exit;
        }

        if (in_array($field, ['current_value','sip_swp','recommended_amount'])) {
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
    font-size: 14px; min-height: 44px; line-height: 1.3;
    vertical-align: middle; white-space: normal; word-break: break-word;
    padding-top: 10px; padding-bottom: 10px;
}
/* Col 2: Present Scheme Name */
.report-table#schemeTable th:nth-child(2),
.report-table#schemeTable td:nth-child(2) {
    width: 370px; min-width: 300px; max-width: 600px;
    white-space: normal; word-break: break-word;
}
/* Col 3: SIP/SWP */
.report-table#schemeTable th:nth-child(3),
.report-table#schemeTable td:nth-child(3) { width: 90px; min-width: 70px; max-width: 110px; white-space: nowrap; }
/* Col 4: Current Value */
.report-table#schemeTable th:nth-child(4),
.report-table#schemeTable td:nth-child(4) { width: 100px; min-width: 80px; max-width: 120px; white-space: nowrap; }
/* Col 5: Action Step */
.report-table#schemeTable th:nth-child(5),
.report-table#schemeTable td:nth-child(5) { width: 120px; min-width: 100px; max-width: 150px; white-space: nowrap; }
/* Col 6: Recommended Scheme Name */
.report-table#schemeTable th:nth-child(6),
.report-table#schemeTable td:nth-child(6) {
    width: 200px; min-width: 120px; max-width: 250px;
    white-space: normal; word-break: break-word;
    vertical-align: middle; padding-top: 6px; padding-bottom: 6px;
}
/* Col 7: Amount */
.report-table#schemeTable th:nth-child(7),
.report-table#schemeTable td:nth-child(7) { width: 80px; min-width: 60px; max-width: 100px; white-space: nowrap; }

/* ── Shared font baseline for every editable element in the table ── */
#schemeTable input.scheme-input,
#schemeTable textarea.scheme-textarea,
#schemeTable select.action-dropdown {
    font-family: inherit;
    font-size: 14px;
    font-weight: 400;
    color: #1a1a1a;
    line-height: 1.4;
}

/* Generic scheme input */
.scheme-input {
    width: 100%; min-width: 0; max-width: 100%;
    padding: 6px 8px; border: none; background: transparent;
    text-align: center; outline: none; transition: background 0.2s;
    white-space: normal; word-break: break-word; overflow-wrap: break-word;
    min-height: 38px; height: 38px;
    display: block; vertical-align: middle; overflow: visible;
}

/* Auto-height textarea for Recommended Scheme Name */
.scheme-textarea {
    width: 100%; min-width: 0; max-width: 100%;
    padding: 6px 8px; border: none; background: transparent;
    text-align: center; outline: none;
    white-space: pre-wrap; word-break: break-word; overflow-wrap: break-word;
    display: block; vertical-align: middle;
    height: auto; min-height: 38px;
    resize: none; overflow: hidden;
    box-sizing: border-box;
    transition: background 0.2s;
}

.action-dropdown {
    width: 100%; min-width: 0; max-width: 100%;
    padding: 6px 8px; border-radius: 4px;
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
            <th colspan="3" style="background:#0288D1; color:white;">Present Schemes</th>
            <th rowspan="2" style="background:#0288D1; color:white;">Action Step</th>
            <th colspan="2" style="background:#219150; color:white;">Scheme Changes</th>
        </tr>
        <tr>
            <th style="display:none;" class="scheme-checkbox-header"></th>
            <th style="background:#0288D1; color:white;">Scheme Name</th>
            <th style="background:#0288D1; color:white;">SIP / SWP</th>
            <th style="background:#0288D1; color:white;">Value as of<br><?= htmlspecialchars($asOn) ?></th>
            <th style="background:#219150; color:white;">Scheme Name</th>
            <th style="background:#219150; color:white;">Amount</th>
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
            $sipRaw    = $s['sip_swp']            ?? '';
            $cvRaw     = $s['current_value']       ?? '';
            $raRaw     = $s['recommended_amount']  ?? '';
            $sipDisplay = ($sipRaw !== null && $sipRaw !== '') ? htmlspecialchars(formatAmount($sipRaw)) : '';
            $cvDisplay  = ($cvRaw  !== null && $cvRaw  !== '') ? htmlspecialchars(formatAmount($cvRaw))  : '';
            $raDisplay  = (is_numeric($raRaw) && $raRaw !== '' && (float)$raRaw != 0) ? htmlspecialchars(formatAmount($raRaw)) : (((float)$raRaw == 0 && $raRaw !== '') ? '' : htmlspecialchars($raRaw));
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
                <textarea class="scheme-textarea"
                    data-field="recommended_scheme"
                    data-scheme-id="<?= $schemeId ?>"
                    rows="1"><?= htmlspecialchars($s['recommended_scheme'] ?? '') ?></textarea>
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

<div id="schemeTableActions" style="margin:10px 0; display:flex; gap:10px;">
    <button type="button" id="toggleSchemeDeleteMode" class="wf-btn btn-reject" style="background:#f39c12;">🗑 Delete Mode</button>
    <button type="button" id="addSchemeRow" class="wf-btn btn-ready" style="background:#27ae60;">+ Add Row</button>
    <button type="button" id="deleteSelectedSchemes" class="wf-btn btn-reject" style="display:none;">Delete Selected</button>
</div>

<script>
(function () {

    // =========================================================================
    // AUTO-HEIGHT HELPER
    // =========================================================================
    function autoResizeTextarea(ta) {
        if (!ta) return;
        ta.style.height = 'auto';
        ta.style.height = Math.max(38, ta.scrollHeight) + 'px';
    }

    // Run on page load for all pre-filled textareas
    document.querySelectorAll('#schemeTable .scheme-textarea').forEach(autoResizeTextarea);

    // Tag number inputs so view_report.php can skip them
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

    // allowZero=true  → show '0' (used for sip_swp, current_value)
    // allowZero=false → show ''  (used for recommended_amount)
    function formatAmountJS(value, allowZero = false) {
        const n = parseFloat(value);
        if (isNaN(n) || value === '' || value === null) return String(value ?? '');
        if (n === 0) return allowZero ? '0' : '';
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

    // ---- POST a single field to table4.php ----
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

    // ---- BLUR (CAPTURE) ----
    schemeTable.addEventListener('blur', function (e) {
        const input = e.target;

        if (input.classList.contains('scheme-number-input')) {
            e.stopImmediatePropagation();
            const field    = input.getAttribute('data-field');
            const schemeId = input.getAttribute('data-scheme-id') || 0;
            const oldRaw   = input.getAttribute('data-raw') ?? '';
            const typed    = input.value.trim();

            if (typed === '' || typed === oldRaw) {
                input.value = input.getAttribute('data-display-backup') || formatAmountJS(oldRaw, field !== 'recommended_amount');
                return;
            }

            const parsedRaw  = parseIndianNumberJS(typed);
            const newDisplay = formatAmountJS(parsedRaw !== '' ? parsedRaw : typed, field !== 'recommended_amount');
            input.setAttribute('data-raw', parsedRaw !== '' ? parsedRaw : typed);
            input.value = newDisplay;
            input.setAttribute('data-display-backup', newDisplay);
            autoSaveSchemeField(schemeId, field, parsedRaw !== '' ? parsedRaw : typed);
            return;
        }

        if (input.classList.contains('scheme-input') && !input.classList.contains('scheme-textarea')) {
            const field = input.getAttribute('data-field');
            if (field === 'scheme_name') {
                autoSaveSchemeField(input.getAttribute('data-scheme-id') || 0, field, input.value);
                e.stopImmediatePropagation();
            }
        }
    }, true);

    // ---- CHANGE: action dropdown + textarea ----
    schemeTable.addEventListener('change', function (e) {

        if (e.target.classList.contains('scheme-textarea')) {
            autoResizeTextarea(e.target);
            autoSaveSchemeField(
                e.target.getAttribute('data-scheme-id') || 0,
                e.target.getAttribute('data-field'),
                e.target.value
            );
            return;
        }

        if (e.target.classList.contains('action-dropdown')) {
            const schemeId    = e.target.getAttribute('data-scheme-id') || 0;
            const action      = e.target.value;
            const tr          = e.target.closest('tr');

            autoSaveSchemeField(schemeId, 'action_step', action);
            e.stopImmediatePropagation();

            const nameInput   = tr.querySelector('input[data-field="scheme_name"]');
            const cvInput     = tr.querySelector('input[data-field="current_value"]');
            const sipInput    = tr.querySelector('input[data-field="sip_swp"]');
            const recTextarea = tr.querySelector('textarea[data-field="recommended_scheme"]');
            const amountInput = tr.querySelector('input[data-field="recommended_amount"]');

            const schemeName  = nameInput ? nameInput.value.trim() : '';
            const cvRaw       = cvInput  ? parseFloat(cvInput.getAttribute('data-raw')  || '0') : 0;
            const sipRaw      = sipInput ? parseFloat(sipInput.getAttribute('data-raw') || '0') : 0;

            // =================================================================
            // MAPPING RULES
            // -----------------------------------------------------------------
            // Drop
            //   → recommended_scheme = present scheme name
            //   → recommended_amount = -(full current value)         [cvRaw]
            //
            // SIP Cancellation
            //   → recommended_scheme = present scheme name
            //   → recommended_amount = -(SIP / SWP amount)           [sipRaw]
            //
            // Partially Redeem
            //   → recommended_scheme = present scheme name
            //   → recommended_amount = -(half current value)         [cvRaw / 2]
            //
            // Switch / Under Observation / Continue
            //   → recommended_scheme = '' (cleared)
            //   → recommended_amount = '' (cleared)
            // =================================================================

            if (action === 'Drop') {

                if (recTextarea) {
                    recTextarea.value = schemeName;
                    autoResizeTextarea(recTextarea);
                    autoSaveSchemeField(schemeId, 'recommended_scheme', schemeName);
                }
                if (amountInput && cvRaw !== 0) {
                    const negRaw     = -Math.abs(cvRaw);
                    const negDisplay = '-' + formatAmountJS(Math.abs(cvRaw));
                    amountInput.setAttribute('data-raw', negRaw);
                    amountInput.value = negDisplay;
                    autoSaveSchemeField(schemeId, 'recommended_amount', String(negRaw));
                }

            } else if (action === 'SIP Cancellation') {

                if (recTextarea) {
                    recTextarea.value = schemeName;
                    autoResizeTextarea(recTextarea);
                    autoSaveSchemeField(schemeId, 'recommended_scheme', schemeName);
                }
                // Amount = negative SIP/SWP value (NOT current value)
                if (amountInput && sipRaw !== 0) {
                    const negRaw     = -Math.abs(sipRaw);
                    const negDisplay = '-' + formatAmountJS(Math.abs(sipRaw));
                    amountInput.setAttribute('data-raw', negRaw);
                    amountInput.value = negDisplay;
                    autoSaveSchemeField(schemeId, 'recommended_amount', String(negRaw));
                }

            } else if (action === 'Partially Redeem') {

                if (recTextarea) {
                    recTextarea.value = schemeName;
                    autoResizeTextarea(recTextarea);
                    autoSaveSchemeField(schemeId, 'recommended_scheme', schemeName);
                }
                if (amountInput && cvRaw !== 0) {
                    const halfRaw     = -(Math.abs(cvRaw) / 2);
                    const halfDisplay = '-' + formatAmountJS(Math.abs(cvRaw) / 2);
                    amountInput.setAttribute('data-raw', halfRaw);
                    amountInput.value = halfDisplay;
                    autoSaveSchemeField(schemeId, 'recommended_amount', String(halfRaw));
                }

            } else {
                // Switch / Under Observation / Continue → clear both fields
                if (recTextarea) {
                    recTextarea.value = '';
                    autoResizeTextarea(recTextarea);
                    autoSaveSchemeField(schemeId, 'recommended_scheme', '');
                }
                if (amountInput) {
                    amountInput.setAttribute('data-raw', '');
                    amountInput.value = '';
                    autoSaveSchemeField(schemeId, 'recommended_amount', '');
                }
            }
        }
    }, true);

    // ---- Textarea: auto-resize on input + debounced save ----
    schemeTable.addEventListener('input', function (e) {
        if (!e.target.classList.contains('scheme-textarea')) return;
        autoResizeTextarea(e.target);
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
    // In the block that registers blur/change on .action-dropdown / .scheme-input,
    // add this ONE LINE at the very top of the callback:
    //
    //   if (element.getAttribute('data-t4-managed') === 'true') return;
    //
    // This prevents view_report.php from double-saving fields table4.php owns.
    // ==========================================================================

})();
</script>
<!-- End: Appropriate Scheme Selection -->