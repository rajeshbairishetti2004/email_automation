<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../database.php';

/* =========================
   CLIENT CONTEXT
========================= */
$clientKeyRaw = $_GET['client_id'] ?? $_SESSION['current_client_id'] ?? '';

if (preg_match('/(\d+)/', $clientKeyRaw, $m)) {
    $clientId = (int)$m[1];
} else {
    $clientId = 0;
}

if ($clientId <= 0) {
    echo "Client not found";
    exit;
}

$pdo = getSlidesPdo();

/* =========================
   SAVE (AJAX)
========================= */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['ajax'] ?? '') === 'save') {
    foreach ($_POST['recommended'] as $asset => $pct) {
        $stmt = $pdo->prepare("
            UPDATE slide8
            SET recommended_pct = ?
            WHERE client_id = ?
              AND asset = ?
        ");
        $stmt->execute([
            (float)$pct,
            $clientId,
            $asset
        ]);
    }

    if (isset($_POST['interpretation'])) {
        $_SESSION['slide8_interpretation'][$clientId] = trim($_POST['interpretation']);
    }

    echo json_encode(['success' => true]);
    exit;
}

/* =========================
   LOAD DATA
========================= */
$stmt = $pdo->prepare("
    SELECT asset, current_pct, recommended_pct
    FROM slide8
    WHERE client_id = ?
    ORDER BY id
");
$stmt->execute([$clientId]);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

if (!$rows) {
    echo "No allocation data found";
    exit;
}

/* =========================
   PREPARE DATA
========================= */
$labels = [];
$currentData = [];
$recommendedData = [];

foreach ($rows as $r) {
    $labels[] = $r['asset'];
    $currentData[] = (float)$r['current_pct'];
    $recommendedData[] = $r['recommended_pct'] !== null
        ? (float)$r['recommended_pct']
        : (float)$r['current_pct'];
}

$interpretation = $_SESSION['slide8_interpretation'][$clientId]
    ?? 'Different caps have the right percentage ranges. So, no change is recommended.';

/* =========================
   COLORS (CONSISTENT)
========================= */
$baseColors = [
    '#4f7df3', '#2eb85c', '#f9b115',
    '#e55353', '#9d5cf2', '#f567a1',
    '#4fd3d6', '#f99315'
];

$colors = [];
for ($i = 0; $i < count($labels); $i++) {
    $colors[] = $baseColors[$i % count($baseColors)];
}
?>

<!DOCTYPE html>
<html>
<head>
<meta charset="UTF-8">
<title>Equity MCAP allocation</title>

<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>

<style>
html,body{margin:0;height:100%;font-family:Calibri}
.slide{height:100%;position:relative}
.content{padding:40px 60px}

h1{
    text-align:center;
    color:#4F7DF3;
    font-size:42px;
    margin-bottom:30px
}

.charts{
    display:grid;
    grid-template-columns:1fr 1fr;
    gap:80px
}

.box{text-align:center}

.chart-row{
    display:flex;
    align-items:center;
    justify-content:center;
    gap:30px
}

.chart-wrap{
    width:240px;
    height:240px
}

.legend{
    text-align:left;
    font-size:14px;
    color:#0A3DBA
}

.legend-item{
    display:flex;
    align-items:center;
    margin-bottom:8px;
    white-space:nowrap
}

.legend-color{
    width:12px;
    height:12px;
    margin-right:8px;
    border-radius:2px
}

.interpretation{
    margin-top:50px;
    font-size:22px;
    color:#0A3DBA
}

.footer{
    position:absolute;
    bottom:0;
    height:10px;
    width:100%;
    background:#4DB6AC
}

.logo{
    position:absolute;
    right:40px;
    bottom:30px
}
.logo img{width:130px}
</style>
</head>

<body>
<div class="slide">
<div class="content">

<h1>Equity MCAP allocation</h1>

<div class="charts">

<!-- CURRENT -->
<div class="box">
    <div style="font-weight:600;color:#0A3DBA;font-size:20px;margin-bottom:12px;">Current</div>

    <div class="chart-row">
        <div class="chart-wrap">
            <canvas id="currentChart"></canvas>
        </div>

        <div class="legend">
            <?php foreach ($labels as $i => $a): ?>
                <div class="legend-item">
                    <span class="legend-color" style="background:<?= $colors[$i] ?>"></span>
                    <?= htmlspecialchars($a) ?> <?= number_format($currentData[$i],2) ?>%
                </div>
            <?php endforeach; ?>
        </div>
    </div>
</div>

<!-- RECOMMENDED -->
<div class="box">
    <div style="font-weight:600;color:#0A3DBA;font-size:20px;margin-bottom:12px;">Recommended</div>

    <div class="chart-row">
        <div class="chart-wrap">
            <canvas id="recommendedChart"></canvas>
        </div>

        <div class="legend">
            <?php foreach ($labels as $i => $a): ?>
                <div class="legend-item">
                    <span class="legend-color" style="background:<?= $colors[$i] ?>"></span>
                    <?= htmlspecialchars($a) ?> <?= number_format($recommendedData[$i],2) ?>%
                </div>
            <?php endforeach; ?>
        </div>
    </div>
</div>

</div>

<div class="interpretation">
    <strong>Finance Doctor’s interpretation:</strong><br>
    <?= htmlspecialchars($interpretation) ?>
</div>

</div>

<div class="logo">
    <img src="/email_automation/image.png">
</div>
<div class="footer"></div>
</div>

<script>
const labels = <?= json_encode($labels) ?>;
const currentData = <?= json_encode($currentData) ?>;
const recommendedData = <?= json_encode($recommendedData) ?>;
const colors = <?= json_encode($colors) ?>;

function renderDonut(canvasId, data) {
    return new Chart(document.getElementById(canvasId), {
        type: 'doughnut',
        data: {
            labels,
            datasets: [{
                data,
                backgroundColor: colors,
                borderWidth: 1
            }]
        },
        options: {
            cutout: '55%',
            responsive: true,
            plugins: {
                legend: { display: false },
                tooltip: {
                    callbacks: {
                        label(ctx) {
                            return `${ctx.label}: ${ctx.raw.toFixed(2)}%`;
                        }
                    }
                }
            }
        }
    });
}

renderDonut('currentChart', currentData);
renderDonut('recommendedChart', recommendedData);
</script>

</body>
</html>
