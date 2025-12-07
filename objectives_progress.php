<?php
// ...existing code for $goals, $totalGoalCurrent, $totalSip, $totalGoalTarget...
?>
<h3>2. Objectives Progress for guiding on appropriate schemes</h3>
<table class="report-table objectives-progress-table">
    <tr>
        <th>Goal/s</th>
        <th>Target Year</th>
        <th>Current Amount (Rs)</th>
        <th>SIP/SWP</th>
        <th>Target Amount (Rs)</th>
        <th>Status</th>
    </tr>
    <?php foreach ($goals as $g): 
        $projected    = (float)($g['projected'] ?? 0);
        $targetAmount = (float)($g['target_amount'] ?? 0);
        $dbStatus = trim($g['status'] ?? 'On Track');
        $dropdownClass = ($dbStatus === 'On Track') ? 'status-on' : 'status-off';
    ?>
        <tr>
            <td><?php echo htmlspecialchars($g['goal']); ?></td>
            <td><?php
                $year = '';
                if (!empty($g['goal_date'])) {
                    $year = substr($g['goal_date'], -4);
                }
                echo htmlspecialchars($year);
            ?></td>
            <td><?php echo formatAmount((float)$g['current_amount']); ?></td>
            <td><?php echo formatAmount((float)$g['sip_swp']); ?></td>
            <td><?php echo formatAmount((float)$g['target_amount']); ?></td>
            <td style="padding: 0;">
                <select name="goal_status[<?php echo (int)$g['id']; ?>]" 
                        class="goal-status-dropdown <?php echo $dropdownClass; ?>" 
                        data-goal-id="<?php echo (int)$g['id']; ?>">
                    <option value="On Track" <?php echo ($dbStatus === 'On Track') ? 'selected' : ''; ?>>On Track</option>
                    <option value="Invest More" <?php echo ($dbStatus === 'Invest More' || $dbStatus === 'Needs Attention') ? 'selected' : ''; ?>>Invest More</option>
                </select>
            </td>
        </tr>
    <?php endforeach; ?>
    <tr>
        <td><strong>Total</strong></td>
        <td></td>
        <td><?php echo formatAmount($totalGoalCurrent); ?></td>
        <td><?php echo formatAmount($totalSip); ?></td>
        <td><!-- intentionally left blank: aggregate Target Amount not shown --></td>
        <td></td>
    </tr>
</table>
<script src="public/js/objectives_progress.js"></script>
