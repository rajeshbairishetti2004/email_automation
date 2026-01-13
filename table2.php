<?php
// Section 2: Objectives Progress for guiding on appropriate schemes
// Expects: $goals, $clientId, $isLocked, formatAmount()

// Section 2: Goals
// Expects: $clientId, $goals, $isLocked

if (!isset($isLocked)) {
    require_once __DIR__ . '/db_config.php';
    $pdo = getPdo();
    $stmt = $pdo->prepare("SELECT report_state, review_not_ok FROM clients WHERE id = ?");
    $stmt->execute([$clientId]);
    $clientLock = $stmt->fetch(PDO::FETCH_ASSOC);
    $reportState = $clientLock['report_state'] ?? 'draft';
    $reviewNotOk = (int)($clientLock['review_not_ok'] ?? 0);
    $isLocked = (($reportState === 'reviewed' && $reviewNotOk === 0) || $reportState === 'sent');
}
?>
<h3>
    2. Objectives Progress for guiding on appropriate schemes
    <?php if ($isLocked): ?>
        <span title="Locked" style="margin-left:8px;color:#888;vertical-align:middle;">🔒</span>
    <?php endif; ?>
</h3>
<table class="report-table report-table-section" id="goalsTable">
    <tr class="report-table-header">
        <th style="width: 260px;">Goal/s</th>
        <th style="width: 160px;">Target Month and Year</th>
        <th style="width: 140px;">Current Amount (Rs)</th>
        <th style="width: 120px;">SIP/SWP</th>
        <th style="width: 140px;">Target Amount (Rs)</th>
        <th style="width: 110px;">Status</th>
    </tr>
    <?php 
    $calculatedGoalCurrent = 0;
    $calculatedSip = 0;
    foreach ($goals as $g): 
        $calculatedGoalCurrent += (float)($g['current_amount'] ?? 0);
        $calculatedSip += (float)($g['sip_swp'] ?? 0);
        $dbStatus = trim($g['status'] ?? 'On Track');
        $dropdownClass = ($dbStatus === 'On Track') ? 'status-on' : 'status-off';
    ?>
        <tr data-goal-row-id="<?php echo (int)$g['id']; ?>">
<td>
    <textarea style="padding: 0;" class="goal-input autosave-field goal-textarea"
        data-goal-id="<?= (int)$g['id']; ?>"
        data-field="goal"
        rows="2"
        <?= $isLocked ? 'readonly' : ''; ?>
    ><?= htmlspecialchars($g['goal'] ?? '') ?></textarea>
</td>

            <td style="padding: 0; width: 160px;">
                <input type="text" class="goal-input autosave-field" 
                    data-goal-id="<?php echo (int)$g['id']; ?>" 
                    data-field="goal_date"
                    value="<?php echo htmlspecialchars($g['goal_date'] ?? ''); ?>"
                    <?php echo $isLocked ? 'readonly' : ''; ?>>
            </td>
            <td style="padding: 0; width: 140px;">
                <input type="text" class="goal-input autosave-field calc-trigger" 
                    data-goal-id="<?php echo (int)$g['id']; ?>" 
                    data-field="current_amount"
                    value="<?php echo htmlspecialchars(formatAmount((float)$g['current_amount'])); ?>"
                    <?php echo $isLocked ? 'readonly' : ''; ?>>
            </td>
            <td style="padding: 0; width: 120px;">
                <input type="text" class="goal-input autosave-field calc-trigger" 
                    data-goal-id="<?php echo (int)$g['id']; ?>" 
                    data-field="sip_swp"
                    value="<?php echo (float)$g['sip_swp'] == 0 ? '-' : htmlspecialchars(formatAmount((float)$g['sip_swp'])); ?>"
                    <?php echo $isLocked ? 'readonly' : ''; ?>>
            </td>
            <td style="padding: 0; width: 140px;">
                <input type="text" class="goal-input autosave-field" 
                    data-goal-id="<?php echo (int)$g['id']; ?>" 
                    data-field="target_amount"
                    value="<?php echo htmlspecialchars(formatAmount((float)$g['target_amount'])); ?>"
                    <?php echo $isLocked ? 'readonly' : ''; ?>>
            </td>
            <td style="padding: 0; width: 110px; text-align: center;">
                <select class="goal-status-dropdown autosave-field <?php echo $dropdownClass; ?>" 
                        data-goal-id="<?php echo (int)$g['id']; ?>" 
                        data-field="status"
                        <?php echo $isLocked ? 'disabled' : ''; ?>>
                    <option value="On Track" <?php echo ($dbStatus === 'On Track') ? 'selected' : ''; ?>>On Track</option>
                    <option value="Invest More" <?php echo ($dbStatus === 'Invest More' || $dbStatus === 'Needs Attention') ? 'selected' : ''; ?>>Invest More</option>
                </select>
            </td>
        </tr>
    <?php endforeach; ?>
    <tr class="total-row">
        <td><strong>Total</strong></td>
        <td></td>
        <td style="padding:0;">
            <input type="text" id="totalGoalCurrent" class="goal-input" value="<?php echo htmlspecialchars(formatAmount((float)$calculatedGoalCurrent)); ?>" readonly>
        </td>
        <td style="padding:0;">
            <input type="text" id="totalSip" class="goal-input" value="<?php echo htmlspecialchars(formatAmount((float)$calculatedSip)); ?>" readonly>
        </td>
        <td></td>
        <td></td>
    </tr>
</table>

<style>

    .goal-textarea {
    resize: vertical;
    width: 100%;
    min-height: 48px;
}

/* --- Table 2 styles (matching Table 1) --- */
.report-table-section {
    width: 100%;
    border-collapse: collapse;
    margin: 20px 0;
    background: #fff;
    border-radius: 8px;
    box-shadow: 0 2px 8px rgba(0,0,0,0.04);
    overflow: hidden;
}

.report-table-section th, .report-table-section td {
    border: 1px solid #e3eaf3;
    padding: 12px 10px;
    text-align: left;
    font-size: 15px;
    font-family: 'Inter', Arial, sans-serif;
    background: #fff;
    border-radius: 0;
}

.report-table-section th {
    background-color: #0288D1;
    color: #fff;
    font-weight: 600;
    font-size: 16px;
    text-align: center;
    border-radius: 0;
    letter-spacing: 0.5px;
}

.report-table-section .report-table-header th {
    background-color: #0288D1;
    color: #fff;
    font-weight: 600;
    font-size: 16px;
    text-align: center;
    border-radius: 0;
}

.report-table-section tr:not(.report-table-header):hover {
    background: #f6fbff;
}

.goal-input {
    width: 100%;
    border: none;
    text-align: center;
    background: transparent;
    padding: 12px;
    font-size: 15px;
    font-family: 'Inter', Arial, sans-serif;
}

.scheme-edit {
    cursor: pointer;
}

.status-on {
    background-color: #0aa914 !important;
    color: #fff !important;
    border-radius: 6px;
    padding: 6px 16px;
    display: inline-block;
    font-weight: 600;
    text-align: center;
    border: none;
}

.status-off {
    background-color: #f44d0b !important;
    color: #fff !important;
    border-radius: 6px;
    padding: 6px 16px;
    display: inline-block;
    font-weight: 600;
    text-align: center;
    border: none;
}

/* Make sure select doesn't override background for selected option */
.goal-status-dropdown {
    border: none;   
    font-weight: 600;
    font-size: 15px;
    padding: 6px 16px;
    border-radius: 6px;
    color: #fff;
    background: transparent;
    /* Remove default arrow for custom look if needed */
    /* appearance: none; -webkit-appearance: none; -moz-appearance: none; */
}

.goal-status-dropdown.status-on {
    background-color: #0aa914 !important;
    color: #fff !important;
}

.goal-status-dropdown.status-off {
    background-color: #f44d0b !important;
    color: #fff !important;
}

/* Remove border radius from all cells */
.report-table-section th, .report-table-section td {
    border-radius: 0 !important;
}
</style>

<script>
(function() {
    /* ---------- Parse Indian currency safely ---------- */
    function parseIndianMoney(val) {
        if (!val || val === '-') return 0;
        let v = val.toString().toLowerCase().replace(/rs\.?/g, '').replace(/,/g, '').trim();
        let multiplier = 1;
        if (v.includes('cr')) { multiplier = 10000000; v = v.replace('cr', ''); }
        else if (v.includes('lakh')) { multiplier = 100000; v = v.replace('lakh', ''); }
        else if (v.includes('k')) { multiplier = 1000; v = v.replace('k', ''); }
        const num = parseFloat(v);
        return isNaN(num) ? 0 : num * multiplier;
    }

    /* ---------- Format nicely ---------- */
    function formatIndianMoney(num) {
        if (num >= 10000000) return 'Rs.' + (num / 10000000).toFixed(2) + ' Cr';
        if (num >= 100000)   return 'Rs.' + (num / 100000).toFixed(2) + ' lakhs';
        if (num >= 1000)     return 'Rs.' + (num / 1000).toFixed(0) + 'k';
        return 'Rs.' + num.toLocaleString('en-IN');
    }

    function performAutoSave(element) {
        const goalId = element.getAttribute('data-goal-id');
        const field = element.getAttribute('data-field');
        const value = element.value;

        if (!goalId || !field) return;

        // Visual feedback: briefly highlight row
        const row = element.closest('tr');
        row.classList.add('saving-indicator');

        const params = new URLSearchParams();
        params.append('ajax_goal_update', '1');
        params.append('goal_id', goalId);
        params.append(field, value);

        fetch('view_report.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
            body: params
        })
        .then(response => response.json())
        .then(data => {
            row.classList.remove('saving-indicator');
            if(data.success && field === 'status') {
                element.classList.remove('status-on', 'status-off');
                element.classList.add(value === 'On Track' ? 'status-on' : 'status-off');
            }
        })
        .catch(error => {
            row.classList.remove('saving-indicator');
            console.error('Error saving:', error);
        });
    }

    function updateCalculatedTotals() {
        let totalSip = 0;
        let totalCurrent = 0;
        document.querySelectorAll('input[data-field="sip_swp"]').forEach(el => {
            totalSip += parseIndianMoney(el.value);
        });
        document.querySelectorAll('input[data-field="current_amount"]').forEach(el => {
            totalCurrent += parseIndianMoney(el.value);
        });
        document.getElementById('totalSip').value = formatIndianMoney(totalSip);
        document.getElementById('totalGoalCurrent').value = formatIndianMoney(totalCurrent);
    }

    // Attach autosave on blur for text inputs, on change for selects
    document.querySelectorAll('.autosave-field').forEach(field => {
        const eventType = field.tagName === 'SELECT' ? 'change' : 'blur';
        field.addEventListener(eventType, function() {
            if (this.readOnly || this.disabled) return;
            performAutoSave(this);
        });
    });

    // Real-time total updates
    document.querySelectorAll('.calc-trigger').forEach(input => {
        input.addEventListener('input', updateCalculatedTotals);
    });
})();
</script>
