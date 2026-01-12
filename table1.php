<?php
// table1.php
// Section 1: Current Situation
// Expects: $clientId, $asOn, $isLocked, $totalAmount, $profit, $cagr, $xirr, $isOlderThan1Year

require_once __DIR__ . '/db_config.php';
$pdo = getPdo();

// If $isLocked is not passed, check from database
if (!isset($isLocked)) {
    $stmt = $pdo->prepare("SELECT report_state, review_not_ok FROM clients WHERE id = ?");
    $stmt->execute([$clientId]);
    $clientLock = $stmt->fetch(PDO::FETCH_ASSOC);
    $reportState = $clientLock['report_state'] ?? 'draft';
    $reviewNotOk = (int)($clientLock['review_not_ok'] ?? 0);
    $isLocked = (($reportState === 'reviewed' && $reviewNotOk === 0) || $reportState === 'sent');
}

$stmt = $pdo->prepare("SELECT 
    total_amount, profit, cagr, xirr, absolute_return,
    is_older_than_1_year
    FROM clients WHERE id = ?");
$stmt->execute([$clientId]);
$client = $stmt->fetch(PDO::FETCH_ASSOC);

// Ensure we have all values, fallback to provided variables
$totalAmount = $client['total_amount'] ?? $totalAmount ?? 0;
$profit = $client['profit'] ?? $profit ?? 0;

// CRITICAL FIX: Always preserve the original CAGR value, even if it's NULL in database
// We'll get it from the original $cagr parameter passed from view_report.php
$originalCagr = $cagr ?? $client['cagr'] ?? 0;
// Store it in a session or variable that persists
$cagr = $originalCagr;

$xirr = $client['xirr'] ?? $xirr ?? 0;
$absoluteReturn = $client['absolute_return'] ?? $absoluteReturn ?? null;
$isOlderThan1Year = $client['is_older_than_1_year'] ?? $isOlderThan1Year ?? 1;

// Format asOn date
$asOnFormatted = $asOn;
$asOnDate = DateTime::createFromFormat('d/m/Y', (string)$asOn);
if (!$asOnDate instanceof DateTime) {
    $asOnDate = DateTime::createFromFormat('d-m-Y', (string)$asOn);
}
if ($asOnDate instanceof DateTime) {
    $asOnFormatted = $asOnDate->format('jS F Y');
}
?>

<style>
/* --- Table1: Current Situation Styles --- */
#currentSituationTable {
    border-collapse: collapse;
    width: 100%;
    margin-bottom: 16px;
    background: #fff;
    border-radius: 6px;
    overflow: hidden;
    box-shadow: 0 1px 4px rgba(0,0,0,0.04);
}
#currentSituationTable th, #currentSituationTable td {
    border: 1px solid #e0e0e0;
    padding: 12px;
    text-align: center;
    font-size: 15px;
}
#currentSituationTable th {
    background: #0288D1;
    color: white;
    font-weight: 600;
}
#currentSituationTable input[type="text"] {
    font-size: 15px;
    border: none;
    background: transparent;
    text-align: center;
    width: 100%;
    padding: 12px;
    outline: none;
}
#currentSituationTable input[readonly] {
    background: #f9f9fa;
    color: #555;
    cursor: not-allowed;
}
.saving-row {
    background-color: #fffde7 !important;
    transition: background 0.3s;
}
#autoSaveStatusCS {
    position: fixed;
    bottom: 20px;
    right: 20px;
    background: #28a745;
    color: #fff;
    padding: 8px 16px;
    border-radius: 4px;
    display: none;
    z-index: 1000;
    font-size: 14px;
    box-shadow: 0 2px 8px rgba(0,0,0,0.2);
}
.portfolio-tenure {
    margin-bottom: 20px;
    padding: 15px;
    background-color: #f8f9fa;
    border-radius: 4px;
    border-left: 4px solid #0288D1;
}
.portfolio-tenure label {
    font-weight: bold;
    display: block;
    margin-bottom: 10px;
    color: #333;
}
.radio-group {
    display: flex;
    gap: 20px;
    flex-wrap: wrap;
}
.radio-group input[type="radio"] {
    margin-right: 8px;
}
</style>

<div class="portfolio-tenure">
    <label>Portfolio Tenure:</label>
    <div class="radio-group">
        <label>
            <input type="radio" class="cs-autosave-radio" name="is_older_than_1_year" value="1" 
                <?= ($isOlderThan1Year == 1) ? 'checked' : '' ?> 
                <?= $isLocked ? 'disabled' : '' ?>>
            More than 1 year
        </label>
        <label>
            <input type="radio" class="cs-autosave-radio" name="is_older_than_1_year" value="0" 
                <?= ($isOlderThan1Year == 0) ? 'checked' : '' ?> 
                <?= $isLocked ? 'disabled' : '' ?>>
            Less than 1 year
        </label>
    </div>
</div>

<h3>
    1. Current Situation
    <?php if (isset($isLocked) && $isLocked): ?>
        <span title="Locked" style="margin-left:8px;color:#888;vertical-align:middle;">🔒</span>
    <?php endif; ?>
</h3>
<table class="report-table" id="currentSituationTable">
    <tr>
        <th colspan="2">Current Situation as of <?= htmlspecialchars($asOnFormatted) ?></th>
    </tr>
    <tr>
        <td>Total Amount</td>
        <td style="padding: 0;">
            <input type="text"
                class="current-input cs-autosave-field"
                id="totalAmountCell"
                data-field="total_amount"
                value="<?= htmlspecialchars(formatAmount((float)$totalAmount)) ?>"
                <?= $isLocked ? 'readonly' : '' ?>>
        </td>
    </tr>
    <tr>
        <td id="returnLabel">
            <?= ($isOlderThan1Year == 0) ? 'Absolute Return of schemes' : 'CAGR of current schemes' ?>
        </td>
        <td style="padding: 0;">
            <input type="text"
                class="current-input cs-autosave-field"
                data-field="<?= ($isOlderThan1Year == 0) ? 'absolute_return' : 'cagr' ?>"
                id="returnValueCell"
                data-raw-cagr="<?= htmlspecialchars((float)$cagr) ?>"
                data-raw-absolute="<?= $absoluteReturn !== null ? htmlspecialchars((float)$absoluteReturn) : '' ?>"
                value="<?php
                    if ($isOlderThan1Year == 0) {
                        echo ($absoluteReturn !== null) ? htmlspecialchars(formatPercent((float)$absoluteReturn)) : '';
                    } else {
                        echo htmlspecialchars(formatPercent((float)$cagr));
                    }
                ?>"
                <?= $isLocked ? 'readonly' : '' ?>>
        </td>
    </tr>
    <?php 
    // Show XIRR row only if portfolio is more than 1 year AND we have non-zero XIRR
    $xirrValue = (float)$xirr;
    $showXirr = ($isOlderThan1Year == 1 && $xirrValue != 0);
    ?>
    <tr id="xirrRow" style="<?= $showXirr ? '' : 'display:none;' ?>">
        <td>XIRR of all schemes since inception</td>
        <td style="padding: 0;">
            <input type="text"
                class="current-input cs-autosave-field"
                data-field="xirr"
                id="xirrValueCell"
                data-raw-xirr="<?= $xirrValue ?>"
                value="<?= $xirrValue != 0 ? htmlspecialchars(formatPercent($xirrValue)) : '' ?>"
                <?= $isLocked ? 'readonly' : '' ?>>
        </td>
    </tr>
    <tr>
        <td>Profit since inception</td>
        <td style="padding: 0;">
            <input type="text"
                class="current-input cs-autosave-field"
                data-field="profit"
                value="<?= htmlspecialchars(formatAmount((float)$profit)) ?>"
                <?= $isLocked ? 'readonly' : '' ?>>
        </td>
    </tr>
</table>

<div id="autoSaveStatusCS">✓ Saved Current Situation</div>

<script>
(function() {
    // Helper functions
    function parseAmount(val) {
        if (!val || val === '-' || val.trim() === '') return 0;
        let v = val.toString().toLowerCase().trim();
        v = v.replace(/₹/g, '').replace(/rs\.?/gi, '').replace(/,/g, '').trim();
        if (v.includes('cr')) return parseFloat(v) * 10000000;
        if (v.includes('lakh')) return parseFloat(v) * 100000;
        if (v.includes('k')) return parseFloat(v) * 1000;
        return parseFloat(v) || 0;
    }
    
    function parsePercent(val) {
        if (!val || val === '' || val.trim() === '') return null;
        const cleaned = val.toString().replace('%', '').trim();
        const num = parseFloat(cleaned);
        return isNaN(num) ? null : num;
    }
    
    function formatPercent(num) {
        if (num === null || num === '' || isNaN(num)) return '';
        return parseFloat(num).toFixed(2) + '%';
    }
    
    // Store original values in JavaScript variables
    const originalCagr = <?= json_encode((float)$cagr) ?>;
    const originalAbsoluteReturn = <?= json_encode($absoluteReturn !== null ? (float)$absoluteReturn : null) ?>;
    const originalXirr = <?= json_encode((float)$xirr) ?>;
    
    // Store current values (will be updated when user edits)
    let currentCagr = originalCagr;
    let currentAbsoluteReturn = originalAbsoluteReturn;
    let currentXirr = originalXirr;
    
    // LOCK CHECK: Pass lock status from PHP to JS
    const reportLocked = <?= $isLocked ? 'true' : 'false' ?>;
    
    // Auto-save function
    function autoSaveCurrentSituation() {
        if (reportLocked) return;
        
        const table = document.getElementById('currentSituationTable');
        table.classList.add('saving-row');
        
        const isOlderThan1Year = document.querySelector('input[name="is_older_than_1_year"]:checked').value;
        const returnValueCell = document.getElementById('returnValueCell');
        
        // Prepare payload
        const payload = {
            client_id: <?= (int)$clientId ?>,
            is_older_than_1_year: isOlderThan1Year,
            total_amount: parseAmount(document.getElementById('totalAmountCell').value),
            profit: parseAmount(document.querySelector('[data-field="profit"]').value)
        };
        
        if (isOlderThan1Year === '1') {
            // More than 1 year: use CAGR
            // Get value from input field OR use stored currentCagr
            const inputCagr = parsePercent(returnValueCell.value);
            payload.cagr = inputCagr !== null ? inputCagr : currentCagr;
            payload.absolute_return = null; // Clear absolute return
            
            // Include XIRR only for more than 1 year
            const xirrInput = document.getElementById('xirrValueCell');
            if (xirrInput) {
                const inputXirr = parsePercent(xirrInput.value);
                payload.xirr = inputXirr !== null ? inputXirr : currentXirr;
            } else {
                payload.xirr = currentXirr;
            }
        } else {
            // Less than 1 year: use Absolute Return
            const inputAbsolute = parsePercent(returnValueCell.value);
            payload.absolute_return = inputAbsolute !== null ? inputAbsolute : currentAbsoluteReturn;
            payload.cagr = null; // Clear CAGR
            payload.xirr = null; // Clear XIRR for less than 1 year
        }
        
        // Send request
        fetch('save_current_situation.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(payload)
        })
        .then(r => r.json())
        .then(res => {
            table.classList.remove('saving-row');
            const toast = document.getElementById('autoSaveStatusCS');
            if (res.success) {
                toast.textContent = '✓ Saved Current Situation';
                toast.style.background = '#28a745';
                toast.style.display = 'block';
                
                // Update stored values after successful save
                if (isOlderThan1Year === '1') {
                    currentCagr = payload.cagr;
                    currentXirr = payload.xirr;
                } else {
                    currentAbsoluteReturn = payload.absolute_return;
                }
                
                // Also update the data attributes
                returnValueCell.setAttribute('data-raw-cagr', currentCagr || '');
                returnValueCell.setAttribute('data-raw-absolute', currentAbsoluteReturn || '');
                const xirrInput = document.getElementById('xirrValueCell');
                if (xirrInput) {
                    xirrInput.setAttribute('data-raw-xirr', currentXirr || '');
                }
            } else {
                toast.textContent = '✗ Save failed: ' + (res.error || 'Unknown error');
                toast.style.background = '#dc3545';
                toast.style.display = 'block';
            }
            setTimeout(() => toast.style.display = 'none', 3000);
        })
        .catch(err => {
            console.error('Auto-save error:', err);
            table.classList.remove('saving-row');
            const toast = document.getElementById('autoSaveStatusCS');
            toast.textContent = '✗ Network error';
            toast.style.background = '#dc3545';
            toast.style.display = 'block';
            setTimeout(() => toast.style.display = 'none', 3000);
        });
    }
    
    // Update UI when radio button changes
    function updateCurrentSituationUI() {
        const selected = document.querySelector('input[name="is_older_than_1_year"]:checked');
        if (!selected) return;
        
        const val = selected.value;
        const returnLabel = document.getElementById('returnLabel');
        const returnValueCell = document.getElementById('returnValueCell');
        const xirrRow = document.getElementById('xirrRow');
        
        if (val === '0') {
            // Less than 1 year: Show Absolute Return
            if (returnLabel) returnLabel.textContent = 'Absolute Return of schemes';
            if (returnValueCell) {
                returnValueCell.setAttribute('data-field', 'absolute_return');
                // Use currentAbsoluteReturn (which preserves edits) or fallback to original
                const displayValue = currentAbsoluteReturn !== null ? currentAbsoluteReturn : originalAbsoluteReturn;
                returnValueCell.value = displayValue !== null ? formatPercent(displayValue) : '';
            }
            // Hide XIRR row for less than 1 year
            if (xirrRow) {
                xirrRow.style.display = 'none';
            }
        } else {
            // More than 1 year: Show CAGR
            if (returnLabel) returnLabel.textContent = 'CAGR of current schemes';
            if (returnValueCell) {
                returnValueCell.setAttribute('data-field', 'cagr');
                // Use currentCagr (which preserves edits) or fallback to original
                const displayValue = currentCagr !== null ? currentCagr : originalCagr;
                returnValueCell.value = displayValue !== null ? formatPercent(displayValue) : '';
            }
            // Show XIRR row only if we have a non-zero XIRR value
            if (xirrRow) {
                const xirrInput = document.getElementById('xirrValueCell');
                let xirrValue = currentXirr;
                
                // Check if user has edited XIRR
                if (xirrInput && xirrInput.value) {
                    const parsed = parsePercent(xirrInput.value);
                    if (parsed !== null) {
                        xirrValue = parsed;
                    }
                }
                
                // Show row only if XIRR has a non-zero value
                const shouldShow = xirrValue !== null && 
                                   xirrValue !== undefined && 
                                   xirrValue !== 0 && 
                                   !isNaN(xirrValue);
                
                xirrRow.style.display = shouldShow ? '' : 'none';
            }
        }
    }
    
    // Store input values when user edits them
    function storeEditedValues() {
        const returnValueCell = document.getElementById('returnValueCell');
        const returnField = returnValueCell.getAttribute('data-field');
        const returnValue = parsePercent(returnValueCell.value);
        
        if (returnField === 'cagr' && returnValue !== null) {
            currentCagr = returnValue;
        } else if (returnField === 'absolute_return' && returnValue !== null) {
            currentAbsoluteReturn = returnValue;
        }
        
        const xirrInput = document.getElementById('xirrValueCell');
        if (xirrInput) {
            const xirrValue = parsePercent(xirrInput.value);
            if (xirrValue !== null) {
                currentXirr = xirrValue;
            }
        }
    }
    
    // Initialize UI on page load
    updateCurrentSituationUI();
    
    // Event listeners
    document.querySelectorAll('.cs-autosave-field').forEach(input => {
        input.addEventListener('blur', function() {
            if (reportLocked) return; // Don't save if locked
            storeEditedValues();
            autoSaveCurrentSituation();
        });
    });
    document.querySelectorAll('.cs-autosave-radio').forEach(radio => {
        radio.addEventListener('change', function() {
            if (reportLocked) return;
            updateCurrentSituationUI();
            autoSaveCurrentSituation();
        });
    });
    
    // Also save on Enter key in inputs
    document.querySelectorAll('.cs-autosave-field').forEach(input => {
        input.addEventListener('keypress', function(e) {
            if (e.key === 'Enter') {
                e.preventDefault();
                this.blur(); // Triggers the blur event
            }
        });
    });
    
    // Store values on input (for immediate UI updates)
    const returnValueCell = document.getElementById('returnValueCell');
    if (returnValueCell) {
        returnValueCell.addEventListener('input', function() {
            storeEditedValues();
        });
    }
    
    const xirrInput = document.getElementById('xirrValueCell');
    if (xirrInput) {
        xirrInput.addEventListener('input', function() {
            storeEditedValues();
            
            // Update XIRR row visibility in real-time
            const xirrRow = document.getElementById('xirrRow');
            if (xirrRow) {
                const selected = document.querySelector('input[name="is_older_than_1_year"]:checked');
                // Only update visibility if "More than 1 year" is selected
                if (selected && selected.value === '1') {
                    const parsedValue = parsePercent(this.value);
                    const hasValue = parsedValue !== null && parsedValue !== 0;
                    xirrRow.style.display = hasValue ? '' : 'none';
                }
            }
        });
    }
})();
</script>