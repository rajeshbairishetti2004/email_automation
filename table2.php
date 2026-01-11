<?php
// Section 2: Objectives Progress for guiding on appropriate schemes
// Expects: $goals, $clientId, $isLocked, formatAmount()
?>
<h3>2. Objectives Progress for guiding on appropriate schemes</h3>
<table class="report-table report-table-section">
    <tr class="report-table-header">
        <th style="width: 260px;">Goal/s</th>
        <th style="width: 160px;">Target Month and Year</th>
        <th style="width: 140px;">Current Amount (Rs)</th>
        <th style="width: 120px;">SIP/SWP</th>
        <th style="width: 140px;">Target Amount (Rs)</th>
        <th style="width: 110px;">Status</th>
    </tr>
    <?php 
    // Recalculate totals from individual goals instead of using stored values
    $calculatedGoalCurrent = 0;
    $calculatedSip = 0;
    $calculatedGoalTarget = 0;
    
    foreach ($goals as $g): 
        $calculatedGoalCurrent += (float)($g['current_amount'] ?? 0);
        $calculatedSip += (float)($g['sip_swp'] ?? 0);
        $calculatedGoalTarget += (float)($g['target_amount'] ?? 0);

        $shortfall = (float)($g['shortfall'] ?? 0);
        if ($shortfall > 0) {
            $newStatus = 'Invest More';
            $statusClass = 'status-off'; 
        } else {
            $newStatus = 'On Track';
            $statusClass = 'status-on';
        }
        // Use DB status directly. Normalize text for comparison.
        $dbStatus = trim($g['status'] ?? 'On Track');
        $dropdownClass = ($dbStatus === 'On Track') ? 'status-on' : 'status-off';
    ?>
        <tr>
        <td style="padding: 0; width: 260px;">
            <input type="text" class="goal-input scheme-edit" 
                data-goal-id="<?php echo (int)$g['id']; ?>" 
                data-field="goal"
                value="<?php echo htmlspecialchars($g['goal']); ?>"
                <?php echo $isLocked ? 'readonly' : ''; ?>
                style="width: 100%; border: none; text-align: center; background: transparent; padding: 12px;">
        </td>
        <td style="padding: 0; width: 160px;">
            <input type="text" class="goal-input scheme-edit" 
                data-goal-id="<?php echo (int)$g['id']; ?>" 
                data-field="goal_date"
                value="<?php echo htmlspecialchars($g['goal_date'] ?? ''); ?>"
                <?php echo $isLocked ? 'readonly' : ''; ?>
                style="width: 100%; border: none; text-align: center; background: transparent; padding: 12px;">
        </td>
            <td style="padding: 0; width: 140px;">
                <input type="text" 
                       class="goal-input" 
                       class="scheme-edit"
                       data-goal-id="<?php echo (int)$g['id']; ?>" 
                       data-field="current_amount"
                       value="<?php echo htmlspecialchars(formatAmount((float)$g['current_amount'])); ?>"
                       <?php echo $isLocked ? 'readonly' : ''; ?>
                       style="width: 100%; border: none; text-align: center; background: transparent; padding: 12px;">
            </td>
            <td style="padding: 0; width: 120px;">
                <input type="text" 
                       class="goal-input" 
                       class="scheme-edit"
                       data-goal-id="<?php echo (int)$g['id']; ?>" 
                       data-field="sip_swp"
                       value="<?php echo (float)$g['sip_swp'] == 0 ? '-' : htmlspecialchars(formatAmount((float)$g['sip_swp'])); ?>"
                       <?php echo $isLocked ? 'readonly' : ''; ?>
                       style="width: 100%; border: none; text-align: center; background: transparent; padding: 12px;">
            </td>
            <td style="padding: 0; width: 140px;">
                <input type="text" 
                       class="goal-input" 
                       class="scheme-edit"
                       data-goal-id="<?php echo (int)$g['id']; ?>" 
                       data-field="target_amount"
                       value="<?php echo htmlspecialchars(formatAmount((float)$g['target_amount'])); ?>"
                       <?php echo $isLocked ? 'readonly' : ''; ?>
                       style="width: 100%; border: none; text-align: center; background: transparent; padding: 12px;">
            </td>
            <td style="padding: 0; width: 110px;">
                <select name="goal_status[<?php echo (int)$g['id']; ?>]" 
                        class="goal-status-dropdown <?php echo $dropdownClass; ?>" 
                        data-goal-id="<?php echo (int)$g['id']; ?>" <?php echo $isLocked ? 'disabled' : ''; ?>>
                    <option value="On Track" <?php echo ($dbStatus === 'On Track') ? 'selected' : ''; ?>>On Track</option>
                    <option value="Invest More" <?php echo ($dbStatus === 'Invest More' || $dbStatus === 'Needs Attention') ? 'selected' : ''; ?>>Invest More</option>
                </select>
            </td>
        </tr>
    <?php endforeach; ?>
    <tr>
        <td style="width: 260px;"><strong>Total</strong></td>
        <td style="width: 160px;"></td>
        <td style="padding:0; width: 140px;">
            <input type="text"
               id="totalGoalCurrent"
               class="goal-input total-input"
               data-goal-id="<?php echo (int)$clientId; ?>"
               data-field="total_goal_current"
               value="<?php echo htmlspecialchars(formatAmount((float)$calculatedGoalCurrent)); ?>"
               readonly
               style="width:100%; border:none; text-align:center; background:transparent; padding:12px;">
        </td>
        <td style="padding:0; width: 120px;">
           <input type="text"
               id="totalSip"
               class="goal-input total-input"
               data-goal-id="<?php echo (int)$clientId; ?>"
               data-field="total_sip"
               value="<?php echo htmlspecialchars(formatAmount((float)$calculatedSip)); ?>"
               readonly
               style="width:100%; border:none; text-align:center; background:transparent; padding:12px;">
        </td>
        <td style="width: 140px;"></td>
        <td style="width: 110px;"></td>
    </tr>
</table>
<style>
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
    background-color: #43e584;
}

.status-off {
    background-color: #ef5f5f;
}

/* Remove border radius from all cells */
.report-table-section th, .report-table-section td {
    border-radius: 0 !important;
}
</style>
<script>
// --- Table 2 JS (move from view_report.php) ---
/* ---------- Parse Indian currency safely ---------- */
function parseIndianMoney(val) {
    if (!val) return 0;

    val = val.toString().toLowerCase().replace(/rs\.?/g, '').trim();

    let multiplier = 1;
    if (val.includes('cr')) {
        multiplier = 10000000;
        val = val.replace('cr', '');
    } else if (val.includes('lakh')) {
        multiplier = 100000;
        val = val.replace('lakh', '');
    } else if (val.includes('k')) {
        multiplier = 1000;
        val = val.replace('k', '');
    }

    val = val.replace(/,/g, '').trim();
    const num = parseFloat(val);
    return isNaN(num) ? 0 : num * multiplier;
}

/* ---------- Format nicely ---------- */
function formatIndianMoney(num) {
    if (num >= 10000000) return 'Rs.' + (num / 10000000).toFixed(2) + ' Cr';
    if (num >= 100000)   return 'Rs.' + (num / 100000).toFixed(2) + ' lakhs';
    if (num >= 1000)     return 'Rs.' + (num / 1000).toFixed(0) + 'k Attachments';
    return 'Rs.' + num.toLocaleString('en-IN');
}

/* ---------- Recalculate Totals ---------- */
function recalcTotals() {
    let totalSip = 0;
    let totalCurrent = 0;

    // SIP total
    document.querySelectorAll('input[data-field="sip_swp"]').forEach(el => {
        totalSip += parseIndianMoney(el.value);
    });

    // Current amount total (optional but good)
    document.querySelectorAll('input[data-field="current_amount"]').forEach(el => {
        totalCurrent += parseIndianMoney(el.value);
    });

    if (document.getElementById('totalSip')) {
        document.getElementById('totalSip').value = formatIndianMoney(totalSip);
    }

    if (document.getElementById('totalGoalCurrent')) {
        document.getElementById('totalGoalCurrent').value = formatIndianMoney(totalCurrent);
    }
}

/* ---------- Attach live listeners ---------- */
document.querySelectorAll(
    'input[data-field="sip_swp"], input[data-field="current_amount"]'
).forEach(input => {
    input.addEventListener('input', recalcTotals);
});
</script>
