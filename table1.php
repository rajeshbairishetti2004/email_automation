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
</style>

<!-- Portfolio Tenure Radio Buttons -->
<div style="margin-bottom: 20px; padding: 15px; background-color: #f8f9fa; border-radius: 4px;">
    <label style="font-weight: bold; display: block; margin-bottom: 10px;">Portfolio Tenure:</label>
    <label style="margin-right: 20px;">
        <input type="radio" name="is_older_than_1_year" value="1" <?php echo ($isOlderThan1Year == 1) ? 'checked' : ''; ?>>
        More than 1 year
    </label>
    <label>
        <input type="radio" name="is_older_than_1_year" value="0" <?php echo ($isOlderThan1Year == 0) ? 'checked' : ''; ?>>
        Less than 1 year
    </label>
</div>

<h3>1. Current Situation</h3>
<table class="report-table" id="currentSituationTable">
    <tr><th colspan="2">Current Situation as of <?php echo htmlspecialchars($asOnFormatted); ?></th></tr>
    <tr>
        <td>Total Amount </td>
        <td style="padding: 0;">
            <input type="text"
                class="current-input"
                id="totalAmountCell"
                data-goal-id="0"
                data-field="total_amount"
                data-raw="<?php echo (float)$totalAmount; ?>"
                value="<?php echo htmlspecialchars(formatAmount((float)$totalAmount)); ?>"
                <?php echo $isLocked ? 'readonly' : ''; ?>
                style="width: 100%; border: none; text-align: center; background: transparent; padding: 12px;">
        </td>
    </tr>
    <tr>
        <!-- label switched dynamically by JS -->
        <td id="returnLabel">
            <?php echo ($isOlderThan1Year == 0) ? 'Absolute Return of schemes' : 'CAGR of current schemes'; ?>
        </td>
        <td style="padding: 0;">
            <input type="text"
                class="current-input"
                data-goal-id="0"
                data-field="<?php echo ($isOlderThan1Year == 0) ? 'absolute_return' : 'cagr'; ?>"
                id="returnValueCell"
                data-raw="<?php echo ($isOlderThan1Year == 0) ? (float)$absoluteReturn : (float)$cagr; ?>"
                value="<?php
                    if ($isOlderThan1Year == 0) {
                        echo ($absoluteReturn !== null)
                            ? htmlspecialchars(formatPercent($absoluteReturn))
                            : '';
                    } else {
                        echo htmlspecialchars(formatPercent($cagr));
                    }
                ?>"
                <?php echo $isLocked ? 'readonly' : ''; ?>
                style="width:100%; border:none; text-align:center; background:transparent; padding:12px;">
        </td>
    </tr>
    <?php
        // XIRR row visibility
        $showXirr = ($isOlderThan1Year == 1 && $xirr != 0);
    ?>
    <tr id="xirrRow" style="<?php echo $showXirr ? '' : 'display:none;'; ?>">
        <td>XIRR of all schemes since inception</td>
        <td style="padding: 0;">
            <input type="text"
                class="current-input"
                data-goal-id="0"
                data-field="xirr"
                id="xirrValueCell"
                data-raw="<?php echo (float)$xirr; ?>"
                value="<?php echo htmlspecialchars(formatPercent($xirr)); ?>"
                <?php echo $isLocked ? 'readonly' : ''; ?>
                style="width:100%; border:none; text-align:center; background:transparent; padding:12px;">
        </td>
    </tr>
    <tr>
        <td>Profit since inception</td>
        <td style="padding: 0;">
            <input type="text"
                class="current-input"
                data-goal-id="0"
                data-field="profit"
                data-raw="<?php echo (float)$profit; ?>"
                value="<?php echo htmlspecialchars(formatAmount((float)$profit)); ?>"
                <?php echo $isLocked ? 'readonly' : ''; ?>
                style="width:100%; border:none; text-align:center; background:transparent; padding:12px;">
        </td>
    </tr>
</table>

<div style="margin: 10px 0; text-align: right;">
    <button type="button"
            id="saveCurrentSituation"
            class="wf-btn btn-ready"
            style="padding: 8px 16px; font-size: 14px;"
            <?php echo $isLocked ? 'disabled' : ''; ?>>
        💾 Save Current Situation
    </button>
    <span id="saveCurrentSituationStatus"
          style="margin-left: 10px; font-size: 13px; color: #28a745; display: none;">
        ✓ Saved
    </span>
</div>
<span id="currentSituationStatus"
      style="margin-left:10px;font-size:13px;"></span>
<script>
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
    if (!val) return null;
    return parseFloat(val.replace('%', '').trim());
}
document.getElementById('saveCurrentSituation').addEventListener('click', function () {
    const payload = {
        client_id: <?= (int)$clientId ?>,
        total_amount: parseAmount(document.getElementById('totalAmountCell').value),
        profit: parseAmount(document.querySelector('[data-field="profit"]').value),
        cagr: parsePercent(document.querySelector('[data-field="cagr"]')?.value),
        absolute_return: parsePercent(document.querySelector('[data-field="absolute_return"]')?.value),
        xirr: parsePercent(document.getElementById('xirrValueCell')?.value)
    };
    fetch('save_current_situation.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(payload)
    })
    .then(r => r.json())
    .then(res => {
        const statusEl = document.getElementById('saveCurrentSituationStatus');
        if (res.success) {
            statusEl.style.display = 'inline';
            setTimeout(() => {
                statusEl.style.display = 'none';
            }, 2000);
        } else {
            document.getElementById('currentSituationStatus').textContent = '❌ Save failed';
        }
    })
    .catch(() => {
        document.getElementById('currentSituationStatus').textContent = '❌ Save failed';
    });
});
const btn = document.getElementById('saveCurrentSituation');
btn.disabled = true;
setTimeout(() => btn.disabled = false, 1500);
</script>