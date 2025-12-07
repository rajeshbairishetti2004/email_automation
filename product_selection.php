<?php
$allocations = $allocations ?? [];
$totalTargetAmount = 0.0;
foreach ($allocations as $a) {
    if (isset($a['target_amount'])) {
        $totalTargetAmount += (float)$a['target_amount'];
    }
}
?>
<h3>3. Appropriate Product Selection at a macro level</h3>
<table class="report-table product-selection-table small">
    <tr>
        <th>Asset</th>
        <th>Share%</th>
        <?php if ($totalTargetAmount > 0): ?>
            <th>Target Amount</th>
        <?php endif; ?>
    </tr>
    <?php
    $hasGold = false;
    foreach ($allocations as $a) {
        if (isset($a['asset']) && stripos($a['asset'], 'Gold') !== false) {
            $hasGold = true;
            break;
        }
    }
    if (!$hasGold) {
        $allocations[] = ['asset' => 'Gold', 'share_pct' => 0];
    }
    $sumShare = 0.0;
    foreach ($allocations as $a):
        $shareVal = isset($a['share_pct']) ? (float)$a['share_pct'] : 0.0;
        $assetName = isset($a['asset']) ? $a['asset'] : '';
        $targetAmount = isset($a['target_amount']) ? (float)$a['target_amount'] : null;
        if ($shareVal <= 0 && stripos($assetName, 'Gold') === false) continue;
        $sumShare += $shareVal;
    ?>
        <tr>
            <td><?php echo htmlspecialchars($assetName); ?></td>
            <td><?php echo number_format($shareVal, 2); ?></td>
            <?php if ($totalTargetAmount > 0): ?>
                <td><?php echo $targetAmount !== null ? number_format($targetAmount, 2) : '-'; ?></td>
            <?php endif; ?>
        </tr>
    <?php endforeach; ?>
    <tr style="font-weight: bold; background-color: #f8f9fa;">
        <td>Total</td>
        <td><?php echo number_format($sumShare, 2); ?></td>
        <?php if ($totalTargetAmount > 0): ?>
            <td></td>
        <?php endif; ?>
    </tr>
</table>
<script src="public/js/product_selection.js"></script>
