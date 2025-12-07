<?php
// ...existing code for $schemes, $asOn...
?>
<h3>4. Appropriate Scheme Selection</h3>
<table class="report-table scheme-selection-table">
    <tr>
        <th colspan="3">Present Schemes</th>
        <th rowspan="2">Action Step</th>
        <th colspan="2">Recommended Schemes</th>
    </tr>
    <tr>
        <th>Scheme Name</th>
        <th>SIP/SWP</th>
        <th>Value as of <?php echo htmlspecialchars($asOn); ?></th>
        <th>Scheme Name</th>
        <th>Amount</th>
    </tr>
    <?php foreach ($schemes as $s): 
        if ((float)$s['sip_swp'] == 0 && (float)$s['current_value'] == 0) continue;
    ?>
        <tr>
            <td><?php echo htmlspecialchars($s['scheme_name']); ?></td>
            <td><?php echo formatAmount((float)$s['sip_swp']); ?></td>
            <td><?php echo formatAmount((float)$s['current_value']); ?></td>
            <td>
                <select name="action_step[<?php echo (int)$s['id']; ?>]" 
                        class="action-dropdown" 
                        data-scheme-id="<?php echo (int)$s['id']; ?>">
                    <option value="Continue" <?php echo ($s['action_step'] ?? 'Continue') === 'Continue' ? 'selected' : ''; ?>>Continue</option>
                    <option value="Drop" <?php echo ($s['action_step'] ?? '') === 'Drop' ? 'selected' : ''; ?>>Drop</option>
                    <option value="Switch" <?php echo ($s['action_step'] ?? '') === 'Switch' ? 'selected' : ''; ?>>Switch</option>
                    <option value="Redeem" <?php echo ($s['action_step'] ?? '') === 'Redeem' ? 'selected' : ''; ?>>Redeem</option>
                    <option value="Partially Redeem" <?php echo ($s['action_step'] ?? '') === 'Partially Redeem' ? 'selected' : ''; ?>>Partially Redeem</option>
                </select>
            </td>
            <td>
                <input type="text" 
                       name="recommended_scheme[<?php echo (int)$s['id']; ?>]"
                       class="scheme-input" 
                       data-scheme-id="<?php echo (int)$s['id']; ?>"
                       data-field="recommended_scheme"
                       value="<?php echo htmlspecialchars($s['recommended_scheme'] ?? ''); ?>"
                       placeholder="Enter recommended scheme...">
            </td>
            <td>
                <input type="text" 
                       name="recommended_amount[<?php echo (int)$s['id']; ?>]"
                       class="scheme-input" 
                       data-scheme-id="<?php echo (int)$s['id']; ?>"
                       data-field="recommended_amount"
                       value="<?php echo htmlspecialchars($s['recommended_amount'] ?? ''); ?>"
                       placeholder="Amount / Note">
            </td>
        </tr>
    <?php endforeach; ?>
</table>
<script src="public/js/scheme_selection.js"></script>
