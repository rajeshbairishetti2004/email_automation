<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../database.php';
require_once __DIR__ . '/../../db_config.php';

$clientKey = $_SESSION['current_client_id'] ?? '';
$clientId = (int)str_replace('CLIENT_', '', $clientKey);

$pdo = getPdo();

$assetOrder = ['Equity', 'Debt', 'Gold', 'Others'];
$colors = ['#4f7df3', '#2eb85c', '#f9b115', '#e55353'];

/* Fetch current allocation */
$currentMap = array_fill_keys($assetOrder, 0);
$stmt = $pdo->prepare("SELECT asset, share_pct FROM client_allocations WHERE client_id=?");
$stmt->execute([$clientId]);
foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
    $k = ucfirst(strtolower($r['asset']));
    if (isset($currentMap[$k])) $currentMap[$k] = (float)$r['share_pct'];
}

/* Fetch recommended allocation */
$recommendedMap = $currentMap;
$stmt = $pdo->prepare("SELECT asset, recommended_share_pct FROM client_allocations WHERE client_id=?");
$stmt->execute([$clientId]);
foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
    if ($r['recommended_share_pct'] !== null) {
        $k = ucfirst(strtolower($r['asset']));
        if (isset($recommendedMap[$k])) $recommendedMap[$k] = (float)$r['recommended_share_pct'];
    }
}
?>
<!DOCTYPE html>
<html>

<head>
    <meta charset="UTF-8">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>

    <style>
        body {
            margin: 0;
            font-family: Calibri, "Segoe UI", Arial, sans-serif;
            background: #fff;
        }

        .title {
            text-align: center;
            font-size: 42px;
            color: #3B73E8;
            font-weight: 600;
            margin-bottom: 20px;
        }

        .slide {
            width: 100%;
            height: 100%;
            padding: 42px 70px;
            box-sizing: border-box;
            position: relative;
        }

        h1 {
            text-align: center;
            color: #4f7df3;
            margin-bottom: 36px;
            font-size: 30px;
        }

        /* === CHART ROW === */
        .charts-row {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            gap: 60px;
        }

        /* === CHART BOX === */
        .chart-box {
            width: 46%;
            background: #fff;
            border-radius: 12px;
            padding: 22px 28px;
            box-shadow: 0 4px 10px rgba(0, 0, 0, 0.06);
        }

        .chart-title {
            text-align: center;
            font-weight: 600;
            margin-bottom: 18px;
            font-size: 16px;
        }

        .chart-content {
            display: flex;
            align-items: center;
            gap: 22px;
            justify-content: center;
        }

        /* Smaller pies */
        canvas {
            width: 200px !important;
            height: 200px !important;
        }

        /* Legend */
        .legend {
            display: flex;
            flex-direction: column;
            gap: 10px;
            font-size: 13.5px;
        }

        .legend-row {
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .color-box {
            width: 14px;
            height: 14px;
            border-radius: 3px;
        }

        .legend input {
            width: 54px;
            padding: 3px;
            font-size: 13px;
            text-align: right;
        }

        /* Interpretation */
        .interpretation {
            margin-top: 1px;
            margin-bottom: 5px;
            font-size: 20px;
            color: #0b3cc1;
            font-style: italic;
            line-height: 1.45;
        }

        .interpretation-title {
            margin-top: 20px;
            font-weight: 600;
            font-size: 30px;
        }

        /* Logo */
        .logo {
            position: absolute;
            right: 40px;
            bottom: 35px;
        }

        .logo img {
            width: 120px;
        }

        .footer {
            position: absolute;
            left: 0;
            right: 0;
            bottom: 0;
            height: 10px;
            background: #21B6A8;
        }
    </style>
</head>

<body>
    <div class="slide">
        <div class="title">Asset Allocation – Current vs Recommended</div>

        <div class="charts-row">

            <!-- CURRENT -->
            <div class="chart-box">
                <div class="chart-title">Current Allocation</div>
                <div class="chart-content">
                    <canvas id="currentChart"></canvas>
                    <div class="legend">
                        <?php foreach ($assetOrder as $i => $a): ?>
                            <div class="legend-row">
                                <span class="color-box" style="background:<?= $colors[$i] ?>"></span>
                                <?= $a ?> – <?= number_format($currentMap[$a], 1) ?>%
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>

            <!-- RECOMMENDED -->
            <div class="chart-box">
                <div class="chart-title">Recommended Allocation</div>
                <div class="chart-content">
                    <canvas id="recommendedChart"></canvas>
                    <div class="legend">
                        <?php foreach ($assetOrder as $i => $a): ?>
                            <div class="legend-row">
                                <span class="color-box" style="background:<?= $colors[$i] ?>"></span>
                                <?= $a ?>
                                <input type="number"
                                    class="rec-input"
                                    min="0" max="100"
                                    value="<?= (int)$recommendedMap[$a] ?>"> %
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>

        </div>

        <div class="interpretation-title">Finance Doctor’s interpretation:</div>
        <div class="interpretation">
            <br>
            To build up global wealth & precious metals, gradually move towards the recommended allocation.<br>
            As a first step, reduce Indian equity allocation slightly and reinvest into global equity.
        </div>

        <div class="logo">
            <img src="/image.png" alt="Finance Doctor">
        </div>

        <div class="footer"></div>

    </div>

    <script>
        const labels = <?= json_encode($assetOrder) ?>;
        const colors = <?= json_encode($colors) ?>;

        const currentData = <?= json_encode(array_values($currentMap)) ?>;
        let recommendedData = <?= json_encode(array_values($recommendedMap)) ?>;

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

        let recChart;

        function renderRec() {
            if (recChart) recChart.destroy();
            recChart = new Chart(recommendedChart, {
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
        }
        renderRec();

        document.querySelectorAll('.rec-input').forEach((el, i) => {
            el.addEventListener('input', () => {
                recommendedData[i] = parseFloat(el.value) || 0;
                renderRec();
            });
        });
    </script>

</body>

</html>