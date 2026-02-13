<?php
// slides/page12.php

ob_start(); // IMPORTANT: buffer all output

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../../db_config.php';
$pdo = getDbConnection();

/* =====================================================
   AJAX SAVE HANDLER — MUST BE FIRST
===================================================== */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ajax_save'])) {

    // Kill all buffered output to avoid HTML in JSON
    while (ob_get_level()) {
        ob_end_clean();
    }

    header('Content-Type: application/json');
    header('X-Content-Type-Options: nosniff');

    $clientIdRaw = $_POST['client_id'] ?? '';
    if (strpos($clientIdRaw, 'CLIENT_') === 0) {
        $clientId = (int) str_replace('CLIENT_', '', $clientIdRaw);
    } else {
        $clientId = (int) $clientIdRaw;
    }

    if ($clientId <= 0) {
        echo json_encode(['success' => false, 'message' => 'Invalid client ID']);
        exit;
    }

    $text = trim($_POST['interpretation'] ?? '');

    try {
        $stmt = $pdo->prepare("
            INSERT INTO slides.slide12 (client_id, para)
            VALUES (?, ?)
            ON DUPLICATE KEY UPDATE para = VALUES(para)
        ");
        $stmt->execute([$clientId, $text]);

        echo json_encode(['success' => true]);
    } catch (Throwable $e) {
        echo json_encode([
            'success' => false,
            'message' => $e->getMessage()
        ]);
    }

    exit;
}

/* =====================================================
   CLIENT ID NORMALIZATION (NORMAL PAGE LOAD)
===================================================== */
$clientIdRaw = $_SESSION['current_client_id'] ?? $_GET['client_id'] ?? '';
if (strpos($clientIdRaw, 'CLIENT_') === 0) {
    $clientId = (int) str_replace('CLIENT_', '', $clientIdRaw);
} else {
    $clientId = (int) $clientIdRaw;
}

if ($clientId <= 0) {
    echo '<div class="page"><p style="color:red;">Invalid Client ID</p></div>';
    return;
}

/* =====================================================
   DEFAULT INTERPRETATION
===================================================== */
$defaultInterpretation =
"In a fast growing economy, there is a lot of sector and cap rotation and therefore,
we have selected mostly the best schemes in flexi cap, focused, multi cap and
large + midcap categories";

/* =====================================================
   FETCH / INSERT INTERPRETATION
===================================================== */
$stmt = $pdo->prepare("SELECT para FROM slides.slide12 WHERE client_id = ?");
$stmt->execute([$clientId]);
$interpretation = $stmt->fetchColumn();

if (!$interpretation) {
    $interpretation = $defaultInterpretation;
    $pdo->prepare("
        INSERT INTO slides.slide12 (client_id, para)
        VALUES (?, ?)
        ON DUPLICATE KEY UPDATE para = VALUES(para)
    ")->execute([$clientId, $defaultInterpretation]);
}

/* =====================================================
   FETCH SCHEMES
===================================================== */
$stmt = $pdo->prepare("
    SELECT scheme_name, current_value
    FROM client_reports.client_schemes
    WHERE client_id = ?
    ORDER BY scheme_name ASC
");
$stmt->execute([$clientId]);
$schemes = $stmt->fetchAll(PDO::FETCH_ASSOC);

/* =====================================================
   SPLITTING LOGIC
===================================================== */
$total = count($schemes);
$leftCount = ($total <= 20) ? min(8, $total) : (int) ceil($total / 2);
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
                <th style="border:1px solid #000;">Scheme Name</th>
                <th style="border:1px solid #000;">Current Value</th>
            </tr>
            <?php foreach ($leftSchemes as $row): ?>
                <tr>
                    <td style="border:1px solid #000; padding:4px;">
                        <?= htmlspecialchars($row['scheme_name']) ?>
                    </td>
                    <td style="border:1px solid #000; padding:4px; text-align:right;">
                        <?= number_format($row['current_value'] / 100000, 2) ?> lakhs
                    </td>
                </tr>
            <?php endforeach; ?>
        </table>

        <!-- RIGHT TABLE -->
        <?php if (!empty($rightSchemes)): ?>
            <table style="width:45%; border-collapse:collapse; font-size:14px;">
                <tr style="background:#B4C7E7;">
                    <th style="border:1px solid #000;">Scheme Name</th>
                    <th style="border:1px solid #000;">Current Value</th>
                </tr>
                <?php foreach ($rightSchemes as $row): ?>
                    <tr>
                        <td style="border:1px solid #000; padding:4px;">
                            <?= htmlspecialchars($row['scheme_name']) ?>
                        </td>
                        <td style="border:1px solid #000; padding:4px; text-align:right;">
                            <?= number_format($row['current_value'] / 100000, 2) ?> lakhs
                        </td>
                    </tr>
                <?php endforeach; ?>
            </table>
        <?php endif; ?>

    </div>


<!-- INTERPRETATION -->
<div style="margin-top:40px; overflow:hidden;">

    <h3 style="color:#1F4E79;">Finance Doctor's interpretation:</h3>

    <!-- LOGO (floated right) -->
<!-- LOGO (floated right, bottom-aligned visually) -->
<div class="logo"
     style="
        float:right;
        margin-left:20px;
        margin-top:70px;   /* 👈 pushes logo down */
        width:160px;
     ">
    <img src="/email_automation/image.png"
         alt="Finance Doctor"
         style="width:100%; height:auto; opacity:0.9;">
</div>


    <!-- TEXT -->
    <p id="interpretationText"
       style="font-style:italic; color:#1F4E79; line-height:1.6;">
        <?= nl2br(htmlspecialchars($interpretation)) ?>
    </p>

</div>


</div>


<script>
/* =====================================================
   INDEX.PHP EDIT / SAVE HOOKS
===================================================== */

let editEnabled = false;

function enableEdit() {
    const el = document.getElementById('interpretationText');
    el.contentEditable = true;
    el.style.background = '#fff3cd';
    el.focus();
    editEnabled = true;
}

function saveSlide() {
    if (!editEnabled) return;

    const el = document.getElementById('interpretationText');
    const text = el.innerText.trim();

    fetch(window.location.href, {
        method: 'POST',
        headers: {
            'Content-Type': 'application/x-www-form-urlencoded',
            'X-Requested-With': 'XMLHttpRequest'
        },
        body: new URLSearchParams({
            ajax_save: '1',
            client_id: '<?= $clientId ?>',
            interpretation: text
        })
    })
    .then(r => r.json())
    .then(data => {
        if (data.success) {
            el.contentEditable = false;
            el.style.background = 'transparent';
            editEnabled = false;
        } else {
            alert('Save failed: ' + data.message);
        }
    })
    .catch(err => alert('Save error: ' + err.message));
}
</script>
