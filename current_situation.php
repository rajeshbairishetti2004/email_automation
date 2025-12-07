<?php
$asOnFormatted = $asOnFormatted ?? '';
// ...existing code for $asOnFormatted...
?>
<h3>1. Current Situation</h3>
<table class="report-table current-situation-table">
    <tr><th colspan="2">Current Situation as of <?php echo htmlspecialchars($asOnFormatted); ?></th></tr>
    <tr>
        <td>Total Amount </td>
        <td><?php echo formatAmount($totalAmount); ?></td>
    </tr>
    <?php if ($isOlderThan1Year): ?>
        <tr>
            <td>CAGR of current schemes</td>
            <td><?php echo formatPercent($cagr); ?></td>
        </tr>
        <?php if ($xirr != 0): ?>
            <tr>
                <td>XIRR of all schemes since inception</td>
                <td><?php echo formatPercent($xirr); ?></td>
            </tr>
        <?php endif; ?>
    <?php else: ?>
        <tr>
            <td>Absolute Return of Schemes</td>
            <td>
                <?php
                if ($absoluteReturn !== null) {
                    echo formatAmount($absoluteReturn);
                } else {
                    echo 'N/A';
                }
                ?>
            </td>
        </tr>
    <?php endif; ?>
    <tr>
        <td>Profit since inception</td>
        <td><?php echo formatAmount($profit); ?></td>
    </tr>
</table>
<script src="public/js/current_situation.js"></script>
