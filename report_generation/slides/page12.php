<?php
// slides/page12.php

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

/**
 * db_config.php location:
 * C:\xampp\htdocs\email_automation\db_config.php
 * slides/page12.php → ../../db_config.php
 */
require_once __DIR__ . '/../../db_config.php';

$pdo = getDbConnection();

// Normalize client_id: CLIENT_15392 → 15392
$clientIdRaw = $_SESSION['current_client_id'] ?? '';
$clientId    = (int) str_replace('CLIENT_', '', $clientIdRaw);

if ($clientId <= 0) {
    echo '<div class="page"><p style="color:red;">Invalid Client ID</p></div>';
    return;
}

// Fetch schemes (current_value stored in RUPEES)
$stmt = $pdo->prepare("
    SELECT scheme_name, current_value
    FROM client_reports.client_schemes
    WHERE client_id = :client_id
    ORDER BY scheme_name ASC
");
$stmt->execute(['client_id' => $clientId]);
$schemes = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Split schemes into two columns
$total = count($schemes);
$half  = (int) ceil($total / 2);

$leftSchemes  = array_slice($schemes, 0, $half);
$rightSchemes = array_slice($schemes, $half);
?>

<div class="page">

    <h1 style="text-align:center; color:#4F81BD; font-size:32px; margin-bottom:30px;">
        Current Schemes
    </h1>

    <div style="display:flex; justify-content:center; gap:40px;">

        <!-- LEFT TABLE -->
        <table style="width:45%; border-collapse:collapse; font-size:14px;">
            <tr style="background:#B4C7E7;">
                <th style="border:1px solid #000; padding:8px;">Scheme Name</th>
                <th style="border:1px solid #000; padding:8px;">Current Value</th>
            </tr>

            <?php if (empty($leftSchemes)): ?>
                <tr>
                    <td colspan="2" style="border:1px solid #000; padding:8px; text-align:center;">
                        No schemes available
                    </td>
                </tr>
            <?php else: ?>
                <?php foreach ($leftSchemes as $row): ?>
                    <tr>
                        <td style="border:1px solid #000; padding:8px;">
                            <?= htmlspecialchars($row['scheme_name']) ?>
                        </td>
                        <td style="border:1px solid #000; padding:8px; text-align:right;">
                            <?= number_format($row['current_value'] / 100000, 2) ?> lakhs
                        </td>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
        </table>

        <!-- RIGHT TABLE -->
        <table style="width:45%; border-collapse:collapse; font-size:14px;">
            <tr style="background:#B4C7E7;">
                <th style="border:1px solid #000; padding:8px;">Scheme Name</th>
                <th style="border:1px solid #000; padding:8px;">Current Value</th>
            </tr>

            <?php if (empty($rightSchemes)): ?>
                <tr>
                    <td colspan="2" style="border:1px solid #000; padding:8px;">&nbsp;</td>
                </tr>
            <?php else: ?>
                <?php foreach ($rightSchemes as $row): ?>
                    <tr>
                        <td style="border:1px solid #000; padding:8px;">
                            <?= htmlspecialchars($row['scheme_name']) ?>
                        </td>
                        <td style="border:1px solid #000; padding:8px; text-align:right;">
                            <?= number_format($row['current_value'] / 100000, 2) ?> lakhs
                        </td>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
        </table>

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

    <div class="page-number">Page 12 of 23</div>
</div>
