<?php
// renderers.php
// - All rendering functions for displaying reports

require_once 'db_config.php';

/* ---------- RENDER SINGLE CLIENT REPORT ---------- */

function renderClientReport(
    array $clientData,
    string $greetingBase,
    string $introText,
    string $closingText,
    string $rationaleText,
    array $annexureLines,
    int $clientId
) {

    $name       = $clientData['name'];
    $asOn       = $clientData['as_on'] ?? '';
    $allocation = $clientData['allocation'] ?? [];
    $schemes    = $clientData['schemes'] ?? [];
    $goals      = $clientData['goals'] ?? [];

    $totals  = $clientData['current']['totals'] ?? ['purchase' => 0, 'current' => 0, 'profit' => 0, 'cagr_weighted' => 0, 'xirr_weighted' => 0];
    $summary = $clientData['current']['summary'] ?? null;

    $totalAmount = $totals['current'];
    $profit      = $summary['profit'] ?? $totals['profit'];
    $cagr        = $totals['cagr_weighted'];
    $xirr        = $summary['xirr'] ?? $totals['xirr_weighted'];

    $totalSip = 0;
    foreach ($goals as $g) {
        $totalSip += $g['running_sip'] ?? 0;
    }

    $totalGoalCurrent = 0;
    $totalGoalTarget  = 0;
    foreach ($goals as $g) {
        $totalGoalCurrent += $g['current_value'];
        $totalGoalTarget  += $g['target_amount'];
    }

    // Build client message from parts

    $base = trim($greetingBase);
    $firstName = '';
    if (!empty($name)) {
        $firstName = explode(' ', trim($name))[0];
    }

if ($base !== '') {
    if (strpos($base, '{{name}}') !== false) {
        $fullGreeting = str_replace('{{name}}', $firstName, $base);
    } else {
        $fullGreeting = rtrim($base) . ' ' . $firstName;
    }
    $fullGreeting .= ',';
} else {
    $fullGreeting = '';
}

    

    // Merge into single client message
    $client_message = $fullGreeting . "\n\n" . $introText . "\n\n" . $closingText;

    // Default signature (Hardcoded here, but dynamic in view_report/email_handler)
    $DEFAULT_SIGNATURE = "Regards,\n\nVivek Sharma,\nRelationship Manager,\nFinance Doctor Private Limited.\n\nMobile - 888 4091 666.\nEmail - vivek.sharma@financedoctor.in\nUrl: www.financedoctor.in";
    $signature_block = $DEFAULT_SIGNATURE;

?>
    <?php
    $asOnFormatted = $asOn;
    $asOnDate = DateTime::createFromFormat('d/m/Y', $asOn);
    if ($asOnDate instanceof DateTime) {
        // Display as "17th November 2025"
        $asOnFormatted = $asOnDate->format('jS F Y');
    }
    ?>

    <div class="client-report" data-client-id="<?php echo (int)$clientId; ?>">

        <div class="card">
            <label class="card-title">Client Communication</label>
            <textarea name="client_message_display" class="large-textarea" placeholder="Write your greeting, introduction, and closing remarks here..." readonly><?php echo htmlspecialchars($client_message); ?></textarea>
        </div>

        <h3>1. Current Situation</h3>
        <table class="report-table">
            <tr>
                <th colspan="2">Current Situation as of <?php echo htmlspecialchars($asOnFormatted); ?></th>
            </tr>
            <tr>
                <td>Total Amount</td>
                <td><?php echo formatAmount($totalAmount); ?></td>
            </tr>
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
            <tr>
                <td>Profit since inception</td>
                <td><?php echo formatAmount($profit); ?></td>
            </tr>
        </table>

        <h3>2. Objectives Progress for guiding on appropriate schemes</h3>
        <table class="report-table">
            <tr>
                <th>Goal/s</th>
                <th>Target Year</th>
                <th>Current Amount (Rs)</th>
                <th>SIP/SWP</th>
                <th>Target Amount (Rs)</th>
                <th>Status</th>
            </tr>
            <?php foreach ($goals as $g): ?>
                <tr>
                    <td><?php echo htmlspecialchars($g['goal']); ?></td>
                    <td><?php echo htmlspecialchars(substr($g['goal_date'], -4)); ?></td>
                    <td><?php echo formatAmount($g['current_value']); ?></td>
                    <td><?php echo formatAmount($g['running_sip'] ?? 0); ?></td>
                    <td><?php echo formatAmount($g['target_amount']); ?></td>
                    <td class="<?php echo ($g['status'] === 'On Track') ? 'status-on' : 'status-off'; ?>">
                        <?php echo ($g['status'] === 'Needs Attention') ? 'Invest More' : htmlspecialchars($g['status']); ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            <tr>
                <td><strong>Total</strong></td>
                <td></td>
                <td><?php echo formatAmount($totalGoalCurrent); ?></td>
                <td><?php echo formatAmount($totalSip); ?></td>
                <td></td>
                <td></td>
            </tr>
        </table>

        <h3>3. Appropriate Product Selection at a macro level</h3>
        <table class="report-table small">
            <tr>
                <th>Asset</th>
                <th>Share%</th>
            </tr>
            <?php
            $sumShare = 0.0;
            foreach ($allocation as $asset => $share):
                // 1. Force float conversion to handle strings like "0.34"
                $shareVal = (float)$share;

                // 2. Hide row ONLY if value is 0 (keep 0.34, 0.01, etc.)
                if ($shareVal <= 0) continue;

                $sumShare += $shareVal;
            ?>
                <tr>
                    <td><?php echo htmlspecialchars($asset); ?></td>
                    <td><?php echo number_format($shareVal, 2); ?></td>
                </tr>
            <?php endforeach; ?>

            <tr style="font-weight: bold; background-color: #f8f9fa;">
                <td>Total</td>
                <td><?php echo number_format($sumShare, 2); ?></td>
            </tr>
        </table>

        <h3>4. Appropriate Scheme Selection</h3>
        <table class="report-table">
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
            <?php
            foreach ($schemes as $s):
            ?>
                <tr>
                    <td><?php echo htmlspecialchars($s['scheme']); ?></td>
                    <td><?php echo formatAmount($s['sip_swp'] ?? 0); ?></td>
                    <td><?php echo formatAmount((float)$s['current_value']); ?></td>
                    <td>Continue</td>
                    <td>-</td>
                    <td>-</td>
                </tr>
            <?php endforeach; ?>
        </table>

        <div class="card" style="margin-top: 20px;">
            <label class="card-title">Rationale</label>
            <textarea name="rationale_display" class="large-textarea" placeholder="Write your rationale here..." readonly><?php echo htmlspecialchars($rationaleText); ?></textarea>
        </div>

        <div class="card" style="margin-top: 20px;">
            <label class="card-title">Signature / Closing Note</label>
            <textarea name="signature_block_display" class="large-textarea" placeholder="Write your signature block here..." readonly><?php echo htmlspecialchars($signature_block); ?></textarea>
        </div>

        <?php if (!empty($annexureLines)): ?>
            <h3>Annexures</h3>
            <ul>
                <?php foreach ($annexureLines as $line):
                    $line = trim($line);
                    if ($line === '') continue;
                ?>
                    <li><?php echo htmlspecialchars($line); ?></li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
    </div>
<?php
}

// Only declare formatPercent if it does not already exist
if (!function_exists('formatPercent')) {
    function formatPercent($value)
    {
        if ($value === null || $value === '' || !is_numeric($value)) return '';
        return number_format((float)$value, 2) . '%';
    }
}
?>