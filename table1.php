<?php
// --- Table1: Current Situation Section ---
// All PHP variables needed for this section should be defined here or passed in.
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
    font-weight: 600;
}
#currentSituationTable input[type="text"] {
    font-size: 15px;
    border: none;
    background: transparent;
    text-align: center;
    width: 100%;
    padding: 12px;
}
#currentSituationTable input[readonly] {
    background: #f9f9f9;
    color: #888;
}
#saveCurrentSituation {
    transition: background-color 0.2s ease, box-shadow 0.2s ease;
}
#saveCurrentSituation:not(:disabled):hover {
    background-color: #28a745 !important;
    box-shadow: 0 0 0 2px rgba(40, 167, 69, 0.15);
}
#saveCurrentSituation:disabled {
    opacity: 0.6;
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
}
</style>

<div style="margin-bottom: 20px; padding: 15px; background-color: #f8f9fa; border-radius: 4px;">
    <label style="font-weight: bold; display: block; margin-bottom: 10px;">Portfolio Tenure:</label>
    <label style="margin-right: 20px;">
        <input type="radio" class="cs-autosave-radio" name="is_older_than_1_year" value="1" <?php echo ($isOlderThan1Year == 1) ? 'checked' : ''; ?> <?php echo $isLocked ? 'disabled' : ''; ?>>
        More than 1 year
    </label>
    <label>
        <input type="radio" class="cs-autosave-radio" name="is_older_than_1_year" value="0" <?php echo ($isOlderThan1Year == 0) ? 'checked' : ''; ?> <?php echo $isLocked ? 'disabled' : ''; ?>>
        Less than 1 year
    </label>
</div>

<h3>1. Current Situation</h3>
<table class="report-table" id="currentSituationTable">
    <tr><th colspan="2">Current Situation as of <?php echo htmlspecialchars($asOnFormatted); ?></th></tr>
    <tr>
        <td>Total Amount</td>
        <td style="padding: 0;">
            <input type="text"
                class="current-input cs-autosave-field"
                id="totalAmountCell"
                data-field="total_amount"
                value="<?php echo htmlspecialchars(formatAmount((float)$totalAmount)); ?>"
                <?php echo $isLocked ? 'readonly' : ''; ?>>
        </td>
    </tr>
    <tr>
        <td id="returnLabel">
            <?php echo ($isOlderThan1Year == 0) ? 'Absolute Return of schemes' : 'CAGR of current schemes'; ?>
        </td>
        <td style="padding: 0;">
            <input type="text"
                class="current-input cs-autosave-field"
                data-field="<?php echo ($isOlderThan1Year == 0) ? 'absolute_return' : 'cagr'; ?>"
                id="returnValueCell"
                value="<?php
                    if ($isOlderThan1Year == 0) {
                        echo ($absoluteReturn !== null) ? htmlspecialchars(formatPercent($absoluteReturn)) : '';
                    } else {
                        echo htmlspecialchars(formatPercent($cagr));
                    }
                ?>"
                <?php echo $isLocked ? 'readonly' : ''; ?>>
        </td>
    </tr>
    <?php $showXirr = ($isOlderThan1Year == 1 && $xirr != 0); ?>
    <tr id="xirrRow" style="<?php echo $showXirr ? '' : 'display:none;'; ?>">
        <td>XIRR of all schemes since inception</td>
        <td style="padding: 0;">
            <input type="text"
                class="current-input cs-autosave-field"
                data-field="xirr"
                id="xirrValueCell"
                value="<?php echo htmlspecialchars(formatPercent($xirr)); ?>"
                <?php echo $isLocked ? 'readonly' : ''; ?>>
        </td>
    </tr>
    <tr>
        <td>Profit since inception</td>
        <td style="padding: 0;">
            <input type="text"
                class="current-input cs-autosave-field"
                data-field="profit"
                value="<?php echo htmlspecialchars(formatAmount((float)$profit)); ?>"
                <?php echo $isLocked ? 'readonly' : ''; ?>>
        </td>
    </tr>
</table>

<div id="autoSaveStatusCS">✓ Saved Current Situation</div>

<script>
(function() {
    function parseAmount(val) {
        if (!val || val === '-') return 0;
        let v = val.toString().toLowerCase().trim();
        v = v.replace(/₹/g, '').replace(/rs\.?/gi, '').replace(/,/g, '').trim();
        if (v.includes('cr')) return parseFloat(v) * 10000000;
        if (v.includes('lakh')) return parseFloat(v) * 100000;
        if (v.includes('k')) return parseFloat(v) * 1000;
        return parseFloat(v) || 0;
    }
    function parsePercent(val) {
        if (!val || val === '') return null;
        return parseFloat(val.toString().replace('%', '').trim());
    }
    function autoSaveCurrentSituation() {
        if (<?= $isLocked ? 'true' : 'false' ?>) return;
        const table = document.getElementById('currentSituationTable');
        table.classList.add('saving-row');
        const payload = {
            client_id: <?= (int)$clientId ?>,
            is_older_than_1_year: document.querySelector('input[name="is_older_than_1_year"]:checked').value,
            total_amount: parseAmount(document.getElementById('totalAmountCell').value),
            profit: parseAmount(document.querySelector('[data-field="profit"]').value),
            cagr: parsePercent(document.getElementById('returnValueCell').getAttribute('data-field') === 'cagr' ? document.getElementById('returnValueCell').value : null),
            absolute_return: parsePercent(document.getElementById('returnValueCell').getAttribute('data-field') === 'absolute_return' ? document.getElementById('returnValueCell').value : null),
            xirr: parsePercent(document.getElementById('xirrValueCell')?.value)
        };
        fetch('save_current_situation.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(payload)
        })
        .then(r => r.json())
        .then(res => {
            if (res.success) {
                table.classList.remove('saving-row');
                const toast = document.getElementById('autoSaveStatusCS');
                toast.style.display = 'block';
                setTimeout(() => toast.style.display = 'none', 2000);
            }
        })
        .catch(err => console.error('Auto-save error:', err));
    }
    // Text inputs (blur)
    document.querySelectorAll('.cs-autosave-field').forEach(input => {
        input.addEventListener('blur', autoSaveCurrentSituation);
    });
    // Radio buttons (change)
    document.querySelectorAll('.cs-autosave-radio').forEach(radio => {
        radio.addEventListener('change', function() {
            const val = this.value;
            const label = document.getElementById('returnLabel');
            const cell = document.getElementById('returnValueCell');
            const xirrRow = document.getElementById('xirrRow');
            if (val === '0') {
                label.textContent = 'Absolute Return of schemes';
                cell.setAttribute('data-field', 'absolute_return');
                xirrRow.style.display = 'none';
            } else {
                label.textContent = 'CAGR of current schemes';
                cell.setAttribute('data-field', 'cagr');
                xirrRow.style.display = '';
            }
            autoSaveCurrentSituation();
        });
    });
})();
</script>