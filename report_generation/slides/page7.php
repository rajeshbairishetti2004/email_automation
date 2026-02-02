<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../database.php';
require_once __DIR__ . '/../../db_config.php';

/* =====================================================
   DATABASE CONNECTIONS
===================================================== */
$slidesPdo = getSlidesPdo();
$mainPdo   = getPdo();

$clientKey = $_GET['client_id'] ?? ($_SESSION['current_client_id'] ?? '');
$clientId  = (int)str_replace('CLIENT_', '', $clientKey);

/* =====================================================
   INITIAL SEED (RUNS ONCE PER CLIENT)
===================================================== */
$seedCheck = $slidesPdo->prepare(
    "SELECT COUNT(*) FROM slide7 WHERE client_id = ?"
);
$seedCheck->execute([$clientId]);

if ($seedCheck->fetchColumn() == 0) {

    $src = $mainPdo->prepare("
        SELECT asset, share_pct
        FROM client_allocations
        WHERE client_id = ?
    ");
    $src->execute([$clientId]);

    $insert = $slidesPdo->prepare("
        INSERT INTO slide7
        (client_id, asset, current_pct, recommended_pct, updated_at)
        VALUES (?, ?, ?, ?, NOW())
    ");

    foreach ($src->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $pct = (float)$row['share_pct'];
        $insert->execute([
            $clientId,
            ucfirst(strtolower($row['asset'])),
            $pct,
            $pct
        ]);
    }
}

/* =====================================================
   SAVE RECOMMENDED (AJAX)
===================================================== */
if (
    $_SERVER['REQUEST_METHOD'] === 'POST'
    && ($_POST['action'] ?? '') === 'save_recommended'
) {

    header('Content-Type: application/json');

    $rows = json_decode($_POST['data'] ?? '[]', true);
    if (!is_array($rows)) {
        echo json_encode(['success' => false]);
        exit;
    }

    $slidesPdo->beginTransaction();
    try {
        $update = $slidesPdo->prepare("
    UPDATE slide7
    SET recommended_pct = ?, updated_at = NOW()
    WHERE id = ?
");


        foreach ($rows as $r) {
            $update->execute([
                (float)$r['value'],
                (int)$r['id']
            ]);
        }


        $slidesPdo->commit();
        echo json_encode(['success' => true]);
    } catch (Exception $e) {
        $slidesPdo->rollBack();
        echo json_encode(['success' => false]);
    }
    exit;
}

/* =====================================================
   FETCH DATA (SINGLE SOURCE OF TRUTH)
===================================================== */
$assetOrder      = [];
$ids             = [];
$currentData     = [];
$recommendedData = [];

$stmt = $slidesPdo->prepare("
    SELECT id, asset, current_pct, recommended_pct
    FROM slide7
    WHERE client_id = ?
    ORDER BY id
");
$stmt->execute([$clientId]);

foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
    $assetOrder[]      = $r['asset'];
    $ids[]             = (int)$r['id'];
    $currentData[]     = (float)$r['current_pct'];
    $recommendedData[] = (float)$r['recommended_pct'];
}

$colors = ['#4f7df3', '#2eb85c', '#f9b115', '#e55353'];
?>
<!DOCTYPE html>
<html>

<head>
    <meta charset="UTF-8">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>

    <style>
        html,
        body {
            height: 100%;
            margin: 0;
            font-family: Calibri, "Segoe UI", Arial;
            background: #fff
        }

        .slide {
            height: 100vh;
            padding: 42px 70px 0;
            position: relative;
            box-sizing: border-box
        }

        .title {
            text-align: center;
            font-size: 42px;
            color: #3B73E8;
            font-weight: 600;
            margin-bottom: 20px
        }

        .edit-controls {
            position: absolute;
            top: 30px;
            right: 40px
        }

        .edit-controls button {
            padding: 8px 18px;
            border: none;
            border-radius: 6px;
            font-size: 14px;
            font-weight: 600;
            cursor: pointer
        }

        #editBtn {
            background: #3B73E8;
            color: #fff
        }

        #saveBtn {
            background: #2eb85c;
            color: #fff;
            display: none
        }

        .charts-row {
            display: flex;
            justify-content: space-between;
            gap: 60px
        }

        .chart-box {
            width: 46%;
            background: #fff;
            border-radius: 12px;
            padding: 22px 28px;
            box-shadow: 0 4px 10px rgba(0, 0, 0, .06)
        }

        .chart-title {
            text-align: center;
            font-weight: 600;
            margin-bottom: 18px
        }

        .chart-content {
            display: flex;
            gap: 22px;
            justify-content: center;
            align-items: center
        }

        canvas {
            width: 200px !important;
            height: 200px !important
        }

        .legend {
            display: flex;
            flex-direction: column;
            gap: 10px;
            font-size: 13.5px
        }

        .legend-row {
            display: flex;
            align-items: center;
            gap: 8px
        }

        .color-box {
            width: 14px;
            height: 14px;
            border-radius: 3px
        }

        .legend input {
            width: 54px;
            padding: 3px;
            text-align: right
        }

        .interpretation-title {
            margin-top: 22px;
            font-size: 30px;
            font-weight: 600
        }

        .interpretation {
            font-size: 20px;
            color: #0b3cc1;
            font-style: italic
        }

        .logo {
            position: absolute;
            right: 40px;
            bottom: 35px
        }

        .logo img {
            width: 120px
        }

        .footer {
            position: absolute;
            left: 0;
            right: 0;
            bottom: 0;
            height: 10px;
            background: #21B6A8
        }
    </style>
</head>

<body>
    <div class="slide">

        <div class="edit-controls">
            <button id="editBtn">Edit</button>
            <button id="saveBtn">Save</button>
        </div>

        <div class="title">Asset Allocation – Current vs Recommended</div>

        <div class="charts-row">

            <div class="chart-box">
                <div class="chart-title">Current Allocation</div>
                <div class="chart-content">
                    <canvas id="currentChart"></canvas>
                    <div class="legend">
                        <?php foreach ($assetOrder as $i => $a): ?>
                            <div class="legend-row">
                                <span class="color-box" style="background:<?= $colors[$i] ?>"></span>
                                <?= $a ?> – <?= number_format($currentData[$i], 1) ?>%
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>

            <div class="chart-box">
                <div class="chart-title">Recommended Allocation</div>
                <div class="chart-content">
                    <canvas id="recommendedChart"></canvas>
                    <div class="legend">
                        <?php foreach ($assetOrder as $i => $a): ?>
                            <div class="legend-row">
                                <span class="color-box" style="background:<?= $colors[$i] ?>"></span>
                                <?= $a ?>
                                <input
                                    type="number"
                                    class="rec-input"
                                    data-id="<?= $ids[$i] ?>"
                                    min="0"
                                    max="100"
                                    value="<?= (int)$recommendedData[$i] ?>"
                                    disabled> %

                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>

        </div>

        <div class="interpretation-title">Finance Doctor’s interpretation:</div>
        <div class="interpretation">
            To build up global wealth & precious metals, gradually move towards the recommended allocation.<br>
            As a first step, reduce Indian equity allocation slightly and reinvest into global equity.
        </div>

        <div class="logo"><img 
    src="/email_automation/image.png"
    alt="Finance Doctor"
    style="
        width: 140px;
        height: auto;
        display: block;
        margin-left: 10px;
    "
></div>
        <div class="footer"></div>
    </div>

    <script>
        const labels = <?= json_encode($assetOrder) ?>;
        const rowIds = <?= json_encode($ids) ?>;
        const colors = <?= json_encode($colors) ?>;
        const currentData = <?= json_encode($currentData) ?>;
        let recommendedData = <?= json_encode($recommendedData) ?>;

        new Chart(currentChart, {
            type: 'pie',
            data: {
                labels,
                datasets: [{
                    data: currentData,
                    backgroundColor: colors
                }]
            },
            options: {
                plugins: {
                    legend: {
                        display: false
                    }
                }
            }
        });

        let recChart = new Chart(recommendedChart, {
            type: 'pie',
            data: {
                labels,
                datasets: [{
                    data: recommendedData,
                    backgroundColor: colors
                }]
            },
            options: {
                plugins: {
                    legend: {
                        display: false
                    }
                }
            }
        });

        let editMode = false;
        const editBtn = document.getElementById('editBtn');
        const saveBtn = document.getElementById('saveBtn');
        const inputs = document.querySelectorAll('.rec-input');

        editBtn.onclick = () => {
            editMode = true;
            inputs.forEach(i => i.disabled = false);
            editBtn.style.display = 'none';
            saveBtn.style.display = 'inline-block';
        };

        saveBtn.onclick = async () => {
            const total = [...document.querySelectorAll('.rec-input')]
                .reduce((s, i) => s + (parseFloat(i.value) || 0), 0);

            if (Math.round(total) !== 100) {
                alert('Recommended allocation must total 100%');
                return;
            }

            await saveToDB(); // ✅ USE THE CORRECT FUNCTION

            editMode = false;
            inputs.forEach(i => i.disabled = true);
            saveBtn.style.display = 'none';
            editBtn.style.display = 'inline-block';
        };


        function saveToDB() {
            const payload = [];

            document.querySelectorAll('.rec-input').forEach(input => {
                payload.push({
                    id: parseInt(input.dataset.id),
                    value: parseFloat(input.value) || 0
                });
            });

            const fd = new FormData();
            fd.append('action', 'save_recommended');
            fd.append('data', JSON.stringify(payload));

            return fetch('slides/page7.php', {
                method: 'POST',
                body: fd
            });
        }


        inputs.forEach((el, i) => {
            el.oninput = () => {
                if (!editMode) return;
                recommendedData[i] = parseFloat(el.value) || 0;
                recChart.data.datasets[0].data = recommendedData;
                recChart.update();
            };
        });
    </script>
</body>

</html>