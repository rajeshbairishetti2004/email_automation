<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../../db_config.php';
require_once __DIR__ . '/../../vendor/autoload.php';

use PhpOffice\PhpSpreadsheet\IOFactory;

$slidesPdo = getSlidesPdo();
$mainPdo   = getPdo();

$clientKey = $_GET['client_id'] ?? ($_SESSION['current_client_id'] ?? '');
$clientId  = (int)str_replace('CLIENT_', '', $clientKey);

if ($clientId <= 0) {
    echo "Invalid Client";
    exit;
}
/* =====================================================
   AJAX SAVE (MUST BE BEFORE ANY HTML OUTPUT)
===================================================== */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    header('Content-Type: application/json');

    $currentVal = (float)($_POST['current'] ?? 0);
    $recommendedVal = (float)($_POST['recommended'] ?? 0);
    $interpretation = trim($_POST['interpretation'] ?? '');

    try {

        $stmt = $slidesPdo->prepare("
            UPDATE slide10
            SET current_pct = ?, 
                recommended_pct = ?, 
                interpretation = ?,
                updated_at = NOW()
            WHERE client_id = ?
        ");

        $stmt->execute([
            $currentVal,
            $recommendedVal,
            $interpretation,
            $clientId
        ]);

        echo json_encode(['success' => true]);
        exit;
    } catch (Throwable $e) {

        echo json_encode([
            'success' => false,
            'error' => $e->getMessage()
        ]);
        exit;
    }
}

/* =====================================================
   EXTRACT GLOBAL EQUITY % FROM EXCEL
===================================================== */
function getGlobalEquityPercentage($clientId)
{
    $uploadDir = __DIR__ . "/../../uploads";
    $files = glob($uploadDir . "/*allocation*.xlsx");

    if (!$files) return 0;

    $filePath = end($files);

    $spreadsheet = IOFactory::load($filePath);

    foreach ($spreadsheet->getSheetNames() as $i => $name) {
        if (stripos($name, 'script') !== false) {

            $sheet = $spreadsheet->getSheet($i);
            $rows  = $sheet->toArray(null, true, true, true);

            foreach ($rows as $row) {
                $firstCol = trim($row['A'] ?? '');
                if (
                    stripos($firstCol, 'equity') !== false &&
                    stripos($firstCol, 'global') !== false
                ) {

                    foreach ($row as $cell) {
                        if (is_numeric($cell)) {
                            return (float)$cell;
                        }
                    }
                }
            }
        }
    }

    return 0;
}

/* =====================================================
   INITIAL SEED
===================================================== */
$stmt = $slidesPdo->prepare("SELECT * FROM slide10 WHERE client_id = ?");
$stmt->execute([$clientId]);
$data = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$data) {

    $current = getGlobalEquityPercentage($clientId);

    $slidesPdo->prepare("
        INSERT INTO slide10 (client_id, current_pct, recommended_pct,interpretation)
        VALUES (?, ?, ?, ?)
    ")->execute([$clientId, $current, 0, 'We are recommending an increase in global allocation and after discussion, it can be implemented gradually.']);

    $recommended = 0;
} else {
    $current     = (float)$data['current_pct'];
    $recommended = (float)$data['recommended_pct'];
}

?>

<!DOCTYPE html>
<html>

<head>
    <meta charset="UTF-8">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <style>
        html,
        body {
            margin: 0;
            height: 100%;
        }

        .slide {
            min-height: 100%;
            padding: 42px 70px 0;
            position: relative;
            box-sizing: border-box;
        }


        .title {
            text-align: center;
            font-size: 42px;
            color: #3B73E8;
            font-weight: 600;
            margin-bottom: 40px;
        }

        .chart-box {
            width: 500px;
            margin: 0 auto;

        }

        .interpretation {
            margin-top: 40px;
            color: #0b3cc1;
            font-size: 20px;
            font-style: italic;
        }

        .logo {
            position: absolute;
            right: 40px;
            bottom: 40px;
        }

        .logo img {
            width: 130px;
        }

        .footer {
            position: absolute;
            left: 0;
            right: 0;
            bottom: 0;
            height: 12px;
            background: #21B6A8;
        }

        .rec-input {
            margin-top: 20px;
            font-size: 18px;
        }
    </style>
</head>

<body>
    <div class="slide">

        <div class="title">Global Wealth</div>

        <div class="chart-box">
            <canvas id="barChart"></canvas>
            <div id="overlayInputs" style="display:none; text-align:center; margin-top:15px;">
                Current:
                <input type="number" id="editCurrent" style="width:70px;"> %
                &nbsp;&nbsp;&nbsp;
                Recommended:
                <input type="number" id="editRecommended" style="width:70px;"> %
            </div>




        </div>

        <div class="interpretation">
            <strong>Finance Doctor’s interpretation:</strong><br>
            <div id="interpretationText">
                <?= nl2br(htmlspecialchars($data['interpretation'] ??
                    'We are recommending an increase in global allocation and after discussion, it can be implemented gradually.')) ?>
            </div>
        </div>


        <div class="logo">
            <img src="/email_automation/image.png">
        </div>

        <div class="footer"></div>
    </div>

    <script>
        let editEnabled = false;

        const ctx = document.getElementById('barChart');

        const currentVal = <?= $current ?>;
        const recommendedVal = <?= $recommended ?>;

        let dynamicMax = Math.max(currentVal, recommendedVal, 5);

        let chart = new Chart(ctx, {
            type: 'bar',
            data: {
                labels: [
                    'Current (' + currentVal + '%)',
                    'Recommended (' + recommendedVal + '%)'
                ],
                datasets: [{
                    data: [currentVal, recommendedVal],
                    backgroundColor: ['#cccccc', '#00ff00']
                }]
            },
            options: {
                scales: {
                    x: {
                        ticks: {
                            color: '#1f2d5a', // X-axis label color (Current / Recommended)
                            font: {
                                size: 14,
                                weight: 'bold'
                            }
                        },
                        grid: {
                            color: '#e0e0e0'
                        }
                    },
                    y: {
                        beginAtZero: true,
                        max: dynamicMax,
                        ticks: {
                            color: '#1f2d5a', // Y-axis numbers color
                            font: {
                                size: 13,
                                weight: 'bold'
                            }
                        },
                        grid: {
                            color: '#e0e0e0'
                        }
                    }
                },
                plugins: {
                    legend: {
                        display: false
                    }
                }
            }

        });

        function enableEdit() {

            editEnabled = true;

            document.getElementById('interpretationText').contentEditable = true;
            document.getElementById('interpretationText').style.background = '#fff3cd';

            document.getElementById('overlayInputs').style.display = 'block';

            document.getElementById('editCurrent').value = chart.data.datasets[0].data[0];
            document.getElementById('editRecommended').value = chart.data.datasets[0].data[1];

            document.getElementById('editCurrent').oninput = updateChartLive;
            document.getElementById('editRecommended').oninput = updateChartLive;
        }


        function updateChartLive() {

            let newCurrent = parseFloat(document.getElementById('editCurrent').value) || 0;
            let newRecommended = parseFloat(document.getElementById('editRecommended').value) || 0;

            chart.data.datasets[0].data = [newCurrent, newRecommended];

            chart.data.labels = [
                'Current (' + newCurrent + '%)',
                'Recommended (' + newRecommended + '%)'
            ];

            chart.options.scales.y.max = Math.min(Math.max(newCurrent, newRecommended, 5), 100);

            chart.update();
        }




        function saveSlide() {

            let currentVal = parseFloat(document.getElementById('editCurrent').value) || 0;
            let recommendedVal = parseFloat(document.getElementById('editRecommended').value) || 0;
            let interpretation = document.getElementById('interpretationText').innerText;

            fetch('slides/page10.php?client_id=<?= $clientKey ?>', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded'
                    },
                    body: new URLSearchParams({
                        current: currentVal,
                        recommended: recommendedVal,
                        interpretation: interpretation
                    })
                })
                .then(r => r.json())
                .then(d => {
                    if (d.success) {

                        document.getElementById('overlayInputs').style.display = 'none';

                        document.getElementById('interpretationText').contentEditable = false;
                        document.getElementById('interpretationText').style.background = 'transparent';

                        editEnabled = false;

                        alert("Global Wealth saved successfully ✅");
                    }
                });
        }
    </script>

</body>

</html>