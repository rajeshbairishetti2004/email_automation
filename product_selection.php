<?php
// ...existing code for $allocations...
?>
<h3>3. Appropriate Product Selection at a macro level</h3>
<table class="report-table product-selection-table small">
    <tr>
        <th>Asset</th>
        <th>Share%</th>
    </tr>
    <?php
    $hasGold = false;
    foreach ($allocations as $a) {
        if (stripos($a['asset'], 'Gold') !== false) {
            $hasGold = true;
            break;
        }
    }
    if (!$hasGold) {
        $allocations[] = ['asset' => 'Gold', 'share_pct' => 0];
    }
    $sumShare = 0.0;
    foreach ($allocations as $a):
        $shareVal = (float)$a['share_pct'];
        $assetName = $a['asset'];
        if ($shareVal <= 0 && stripos($assetName, 'Gold') === false) continue;
        $sumShare += $shareVal;
    ?>
        <tr>
            <td><?php echo htmlspecialchars($assetName); ?></td>
            <td><?php echo number_format($shareVal, 2); ?></td>
        </tr>
    <?php endforeach; ?>
    <tr style="font-weight: bold; background-color: #f8f9fa;">
        <td>Total</td>
        <td><?php echo number_format($sumShare, 2); ?></td>
    </tr>
</table>
<script src="public/js/product_selection.js"></script>
