<?php
// slides/page12.php

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../../db_config.php';

$pdo = getDbConnection();

// Normalize client_id: CLIENT_15392 → 15392
$clientIdRaw = $_SESSION['current_client_id'] ?? '';
$clientId    = (int) str_replace('CLIENT_', '', $clientIdRaw);

if ($clientId <= 0) {
    echo '<div class="page"><p style="color:red;">Invalid Client ID</p></div>';
    return;
}

// Fetch schemes
$stmt = $pdo->prepare("
    SELECT scheme_name, current_value
    FROM client_reports.client_schemes
    WHERE client_id = :client_id
    ORDER BY scheme_name ASC
");
$stmt->execute(['client_id' => $clientId]);
$schemes = $stmt->fetchAll(PDO::FETCH_ASSOC);

/**
 * SPLITTING LOGIC
 * - Up to 20 rows → max 8 on left
 * - More than 20 rows → split evenly
 */
$total = count($schemes);

if ($total <= 20) {
    $leftCount = min(8, $total);
} else {
    $leftCount = (int) ceil($total / 2);
}

$leftSchemes  = array_slice($schemes, 0, $leftCount);
$rightSchemes = array_slice($schemes, $leftCount);
?>

<div class="page" style="padding:40px 50px;">

    <h1 style="text-align:center; color:#4F81BD; font-size:32px; margin-bottom:30px;">
        Current Schemes
    </h1>

    <div style="display:flex; justify-content:center; gap:40px; align-items:flex-start;">

        <!-- LEFT TABLE -->
        <table style="width:45%; border-collapse:collapse; font-size:14px;">
            <tr style="background:#B4C7E7;">
                <th style="border:1px solid #000; padding:4px 8px; height:26px; line-height:26px;">
                    Scheme Name
                </th>
                <th style="border:1px solid #000; padding:4px 8px; height:26px; line-height:26px;">
                    Current Value
                </th>
            </tr>

            <?php foreach ($leftSchemes as $row): ?>
                <tr>
                    <td style="border:1px solid #000; padding:4px 8px; height:26px; line-height:26px;">
                        <?= htmlspecialchars($row['scheme_name']) ?>
                    </td>
                    <td style="border:1px solid #000; padding:4px 8px; height:26px; line-height:26px; text-align:right;">
                        <?= number_format($row['current_value'] / 100000, 2) ?> lakhs
                    </td>
                </tr>
            <?php endforeach; ?>
        </table>

        <!-- RIGHT TABLE (ONLY IF DATA EXISTS) -->
        <?php if (!empty($rightSchemes)): ?>
            <table style="width:45%; border-collapse:collapse; font-size:14px;">
                <tr style="background:#B4C7E7;">
                    <th style="border:1px solid #000; padding:4px 8px; height:26px; line-height:26px;">
                        Scheme Name
                    </th>
                    <th style="border:1px solid #000; padding:4px 8px; height:26px; line-height:26px;">
                        Current Value
                    </th>
                </tr>

                <?php foreach ($rightSchemes as $row): ?>
                    <tr>
                        <td style="border:1px solid #000; padding:4px 8px; height:26px; line-height:26px;">
                            <?= htmlspecialchars($row['scheme_name']) ?>
                        </td>
                        <td style="border:1px solid #000; padding:4px 8px; height:26px; line-height:26px; text-align:right;">
                            <?= number_format($row['current_value'] / 100000, 2) ?> lakhs
                        </td>
                    </tr>
                <?php endforeach; ?>
            </table>
        <?php endif; ?>

    </div>

    <!-- INTERPRETATION -->
    <div style="margin-top:40px;">
        <h3 style="color:#1F4E79;">Finance Doctor's interpretation:</h3>
        <p style="font-style:italic; color:#1F4E79; line-height:1.6;">
            In a fast growing economy, there is a lot of sector and cap rotation and therefore,
            we have selected mostly the best schemes in flexi cap, focused, multi cap and
            large + midcap categories
        </p>
    </div>

</div>
