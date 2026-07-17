<table class="report-table">
    <tr>
        <th>Goal/s</th>
        <th>Target Month and Year</th>
        <th>Current Amount (Rs)</th>
        <th>SIP/SWP</th>
        <th>Target Amount (Rs)</th>
        <th>Projected (Rs)</th>
        <th>Completion (%)</th>
        <th>Shortfall (Rs)</th>
        <th>Status</th>
    </tr>
    <?php 
    // Recalculate totals from individual goals instead of using stored values
    $calculatedGoalCurrent = 0;
    $calculatedSip = 0;
    $calculatedGoalTarget = 0;
    
    foreach ($goals as $g): 
        // Add to calculated totals
        $calculatedGoalCurrent += (float)($g['current_amount'] ?? 0);
        $calculatedSip += (float)($g['sip_swp'] ?? 0);
        $calculatedGoalTarget += (float)($g['target_amount'] ?? 0);

        // Always derive status from projected vs target
        $projected    = (float)($g['projected'] ?? 0);
        $targetAmount = (float)($g['target_amount'] ?? 0);

        $status = ($projected < $targetAmount) ? 'Invest More' : 'On Track';
        $statusClass = ($status === 'On Track') ? 'status-on' : 'status-off';
    ?>
        <tr>
            <td><?php echo htmlspecialchars($g['goal']); ?></td>
            <td><?php
                $displayDate = '';
                if (!empty($g['goal_date'])) {
                    $displayDate = date('M Y', strtotime($g['goal_date']));
                }
                echo htmlspecialchars($displayDate);
            ?></td>
            <td style="padding: 0;">
                <input type="text" 
                       class="goal-input" 
                       data-goal-id="<?php echo (int)$g['id']; ?>" 
                       data-field="current_amount"
                       value="<?php echo htmlspecialchars(formatAmount((float)$g['current_amount'])); ?>"
                       <?php echo $isLocked ? 'readonly' : ''; ?>
                       style="width: 100%; border: none; text-align: center; background: transparent; padding: 12px;">
            </td>
            <td style="padding: 0;">
                <input type="text" 
                       class="goal-input" 
                       data-goal-id="<?php echo (int)$g['id']; ?>" 
                       data-field="sip_swp"
                       value="<?php echo (float)$g['sip_swp'] == 0 ? '-' : htmlspecialchars(formatAmount((float)$g['sip_swp'])); ?>"
                       <?php echo $isLocked ? 'readonly' : ''; ?>
                       style="width: 100%; border: none; text-align: center; background: transparent; padding: 12px;">
            </td>
            <td style="padding: 0;">
                <input type="text" 
                       class="goal-input" 
                       data-goal-id="<?php echo (int)$g['id']; ?>" 
                       data-field="target_amount"
                       value="<?php echo htmlspecialchars(formatAmount((float)$g['target_amount'])); ?>"
                       <?php echo $isLocked ? 'readonly' : ''; ?>
                       style="width: 100%; border: none; text-align: center; background: transparent; padding: 12px;">
            </td>
            <td style="padding: 0;">
                <input type="text"
                       class="goal-input"
                       data-goal-id="<?php echo (int)$g['id']; ?>"
                       data-field="projected"
                       value="<?php echo htmlspecialchars(formatAmount((float)$g['projected'])); ?>"
                       <?php echo $isLocked ? 'readonly' : ''; ?>
                       style="width: 100%; border: none; text-align: center; background: transparent; padding: 12px;">
            </td>
            <td style="padding: 0;">
                <input type="text"
                       class="goal-input"
                       data-goal-id="<?php echo (int)$g['id']; ?>"
                       data-field="completion"
                       value="<?php echo htmlspecialchars((float)$g['completion']); ?>"
                       <?php echo $isLocked ? 'readonly' : ''; ?>
                       style="width: 100%; border: none; text-align: center; background: transparent; padding: 12px;">
            </td>
            <td style="padding: 0;">
                <input type="text"
                       class="goal-input"
                       data-goal-id="<?php echo (int)$g['id']; ?>"
                       data-field="shortfall"
                       value="<?php echo htmlspecialchars(formatAmount((float)$g['shortfall'])); ?>"
                       <?php echo $isLocked ? 'readonly' : ''; ?>
                       style="width: 100%; border: none; text-align: center; background: transparent; padding: 12px;">
            </td>
            <td style="padding: 0;">
                <select name="goal_status[<?php echo (int)$g['id']; ?>]" 
                        class="goal-status-dropdown <?php echo $statusClass; ?>" 
                        data-goal-id="<?php echo (int)$g['id']; ?>" <?php echo $isLocked ? 'disabled' : ''; ?>>
                    <option value="On Track" <?php echo ($status === 'On Track') ? 'selected' : ''; ?>>On Track</option>
                    <option value="Invest More" <?php echo ($status === 'Invest More') ? 'selected' : ''; ?>>Invest More</option>
                </select>
            </td>
        </tr>
    <?php endforeach; ?>
    <tr>
        <td><strong>Total</strong></td>
        <td></td>
        <td id="total-current-amount"><?php echo formatAmount($calculatedGoalCurrent); ?></td>
        <td id="total-sip-swp"><?php echo formatAmount($calculatedSip); ?></td>
        <td></td> <!-- Target total intentionally blank -->
        <td></td>
        <td></td>
        <td></td>
        <td></td>
    </tr>
</table>
<script>
// --- GOALS TABLE JS LOGIC (Table-2) ---

// Parse shorthand number formats (30k, 1lakh, 2cr)
function parseShorthandNumber(value) {
    if (!value) return 0;
    value = value.toString().toLowerCase().trim();
    value = value.replace(/^rs\.?\s*/i, '').replace(/^₹\s*/i, '');
    if (value.match(/k$/)) {
        return parseFloat(value.replace(/k$/, '')) * 1000;
    } else if (value.match(/lakh?s?$/)) {
        return parseFloat(value.replace(/lakh?s?$/, '')) * 100000;
    } else if (value.match(/cr?s?$/)) {
        return parseFloat(value.replace(/cr?s?$/, '')) * 10000000;
    }
    return parseFloat(value.replace(/,/g, '')) || 0;
}

// Format number to Indian format for display
function formatIndianNumber(num) {
    if (num >= 10000000) {
        return 'Rs ' + (num / 10000000).toFixed(2) + ' Cr';
    } else if (num >= 100000) {
        return 'Rs ' + (num / 100000).toFixed(2) + ' lakhs';
    } else if (num >= 1000) {
        return 'Rs ' + (num / 1000).toFixed(2) + ' thousand';
    }
    return 'Rs ' + num.toFixed(0);
}

// Update totals based on current input values
function updateTotals() {
    let totalCurrent = 0;
    let totalSip = 0;
    document.querySelectorAll('.goal-input').forEach(function(input) {
        const field = input.getAttribute('data-field');
        const value = parseShorthandNumber(input.value);
        if (field === 'current_amount') {
            totalCurrent += value;
        } else if (field === 'sip_swp') {
            totalSip += value;
        }
    });
    const totalCurrentEl = document.getElementById('total-current-amount');
    const totalSipEl = document.getElementById('total-sip-swp');
    if (totalCurrentEl) totalCurrentEl.textContent = formatIndianNumber(totalCurrent);
    if (totalSipEl) totalSipEl.textContent = formatIndianNumber(totalSip);
}

// Auto-save Goal Inputs (Current Amount, SIP/SWP, Target Amount)
document.querySelectorAll('.goal-input').forEach(function(input) {
    input.addEventListener('input', function() {
        updateTotals();
        if (typeof goalsDirty !== 'undefined' && !reportLocked) goalsDirty = true;
    });
    if (typeof reportLocked !== 'undefined' && !reportLocked) {
        input.addEventListener('blur', function() {
            const goalId = this.getAttribute('data-goal-id');
            const field  = this.getAttribute('data-field');
            const value  = this.value;
            fetch('view_report.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
                body: new URLSearchParams({
                    ajax_goal_update: '1',
                    goal_id: goalId,
                    [field]: value
                })
            })
            .then(res => res.json())
            .then(data => {
                if(data.success) {
                    input.style.backgroundColor = "#e8f5e9";
                    setTimeout(() => input.style.backgroundColor = "transparent", 500);
                    updateTotals();
                    if (typeof goalsDirty !== 'undefined') goalsDirty = false;
                } else if (data.message) {
                    alert(data.message);
                }
            });
        });
    }
});

// Initialize totals on page load
updateTotals();

// Save Goals button handler
const saveGoalsBtn = document.getElementById('saveGoalsBtn');
if (saveGoalsBtn && typeof reportLocked !== 'undefined' && !reportLocked) {
    saveGoalsBtn.addEventListener('click', function() {
        const btn = this;
        const statusSpan = document.getElementById('saveGoalsStatus');
        btn.disabled = true;
        btn.textContent = '💾 Saving...';
        const savePromises = [];
        document.querySelectorAll('.goal-input').forEach(function(input) {
            const goalId = input.getAttribute('data-goal-id');
            const field  = input.getAttribute('data-field');
            const value  = input.value;
            const promise = fetch('view_report.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
                body: new URLSearchParams({
                    ajax_goal_update: '1',
                    goal_id: goalId,
                    [field]: value
                })
            })
            .then(res => res.json())
            .catch(err => ({success: false, error: err.message, field, goalId}));
            savePromises.push(promise);
        });
        Promise.all(savePromises)
            .then((results) => {
                btn.textContent = '💾 Save Goals';
                btn.disabled = false;
                const allSuccess = results.every(r => r && r.success);
                const failedResults = results.filter(r => !r || !r.success);
                if (allSuccess) {
                    statusSpan.textContent = '✓ All goals saved to database';
                    statusSpan.style.color = '#28a745';
                    statusSpan.style.display = 'inline';
                    document.querySelectorAll('.goal-input').forEach(input => {
                        input.style.backgroundColor = "#e8f5e9";
                        setTimeout(() => input.style.backgroundColor = "transparent", 1000);
                    });
                } else {
                    statusSpan.textContent = '⚠ ' + failedResults.length + ' field(s) failed - see red borders';
                    statusSpan.style.color = '#dc3545';
                    statusSpan.style.display = 'inline';
                    results.forEach((result, index) => {
                        const inputs = document.querySelectorAll('.goal-input');
                        if (inputs[index]) {
                            if (result && result.success) {
                                inputs[index].style.backgroundColor = "#e8f5e9";
                                setTimeout(() => inputs[index].style.backgroundColor = "transparent", 1000);
                            } else {
                                inputs[index].style.border = "2px solid #dc3545";
                                inputs[index].style.backgroundColor = "#ffe6e6";
                            }
                        }
                    });
                    alert('Some fields failed to save. Fields with red borders had errors.');
                }
                setTimeout(() => {
                    statusSpan.style.display = 'none';
                }, 5000);
                updateTotals();
            })
            .catch(err => {
                btn.textContent = '💾 Save Goals';
                btn.disabled = false;
                statusSpan.textContent = '❌ Error: ' + err.message;
                statusSpan.style.color = '#dc3545';
                statusSpan.style.display = 'inline';
                alert('Error saving goals: ' + err.message);
            });
    });
}
</script>
