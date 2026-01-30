<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../database.php';
require_once __DIR__ . '/../../db_config.php';

/* =====================================================
   DATABASE CONNECTIONS
===================================================== */
$slidesPdo = getSlidesPdo();   // slides DB → slide7 table
$mainPdo   = getPdo();         // client_reports DB → client_allocations

$clientKey = $_SESSION['current_client_id'] ?? '';
$clientId  = (int)str_replace('CLIENT_', '', $clientKey);

/* =====================================================
   INITIAL SEED: table3 → slide7 (RUNS ONCE PER CLIENT)
===================================================== */
$seedCheck = $slidesPdo->prepare(
    "SELECT COUNT(*) FROM slide7 WHERE client_id = ?"
);
$seedCheck->execute([$clientId]);

if ($seedCheck->fetchColumn() == 0) {

    // Fetch current allocation from table3 (client_allocations)
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

        // Initial: current == recommended
        $insert->execute([
            $clientId,
            ucfirst(strtolower($row['asset'])),
            $pct,
            $pct
        ]);
    }
}

/* =====================================================
   AJAX SAVE (RECOMMENDED ONLY)
===================================================== */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save_recommended') {
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
            WHERE client_id = ? AND asset = ?
        ");

        foreach ($rows as $r) {
            $update->execute([
                (float)$r['value'],
                $clientId,
                $r['asset']
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
   FETCH DATA FOR CHARTS
===================================================== */
$assetOrder = [];
$stmt = $slidesPdo->prepare("
    SELECT asset FROM slide7 WHERE client_id = ?
");
$stmt->execute([$clientId]);

foreach ($stmt->fetchAll() as $r) {
    $assetOrder[] = $r['asset'];
}

$colors     = ['#4f7df3', '#2eb85c', '#f9b115', '#e55353'];

$currentMap     = array_fill_keys($assetOrder, 0);
$recommendedMap = array_fill_keys($assetOrder, 0);

$stmt = $slidesPdo->prepare("
    SELECT asset, current_pct, recommended_pct
    FROM slide7
    WHERE client_id = ?
");
$stmt->execute([$clientId]);

foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
    $k = ucfirst(strtolower($r['asset']));
    if (isset($currentMap[$k])) {
        $currentMap[$k]     = (float)$r['current_pct'];
        $recommendedMap[$k] = (float)$r['recommended_pct'];
    }
}
?>
<!DOCTYPE html>
<html>
<head>
<meta charset="UTF-8">
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>

<script>
const pie3DPlugin = {
    id: 'pie3d',
    beforeDatasetDraw(chart) {
        const ctx = chart.ctx;
        ctx.save();
        ctx.shadowColor = 'rgba(0,0,0,0.30)';
        ctx.shadowBlur = 18;
        ctx.shadowOffsetY = 10;
    },
    afterDatasetDraw(chart) {
        chart.ctx.restore();
    }
};
</script>

<style>
    html, body {
    height: 100%;
}

body{margin:0;font-family:Calibri,"Segoe UI",Arial;background:#fff}
.slide{width:100%;height:100vh;padding:42px 70px 0px;box-sizing:border-box;position:relative}
.title{text-align:center;font-size:42px;color:#3B73E8;font-weight:600;margin-bottom:20px}
.charts-row{display:flex;justify-content:space-between;gap:60px}
.chart-box{width:46%;background:#fff;border-radius:12px;padding:22px 28px;box-shadow:0 4px 10px rgba(0,0,0,.06)}
.chart-title{text-align:center;font-weight:600;margin-bottom:18px}
.chart-content{display:flex;align-items:center;gap:22px;justify-content:center}
canvas{width:200px!important;height:200px!important}
.legend{display:flex;flex-direction:column;gap:10px;font-size:13.5px}
.legend-row{display:flex;align-items:center;gap:8px}
.color-box{width:14px;height:14px;border-radius:3px}
.legend input{width:54px;padding:3px;font-size:13px;text-align:right}
.interpretation-title{margin-top:22px;margin-bottom:6px;font-weight:600;font-size:30px}
.interpretation{margin-top:0;font-size:20px;color:#0b3cc1;font-style:italic;line-height:1.4}
.logo{position:absolute;right:40px;bottom:35px}
.logo img{width:120px}
.footer{position:absolute;left:0;right:0;bottom:0;height:10px;background:#21B6A8}
/* Soft validation feedback – no layout impact */
.rec-input.invalid {
    border: 1.5px solid #e55353;
    box-shadow: 0 0 4px rgba(229,83,83,0.4);
}

.rec-input.valid {
    border: 1.5px solid #2eb85c;
}

</style>
</head>

<body>
<div class="slide">
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
<?= $a ?> – <?= number_format($currentMap[$a],1) ?>%
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
<input type="number" class="rec-input" min="0" max="100"
value="<?= (int)$recommendedMap[$a] ?>"> %
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

<div class="logo"><img src="/image.png"></div>
<div class="footer"></div>
</div>
<script>
const labels = <?= json_encode($assetOrder) ?>;
const colors = <?= json_encode($colors) ?>;
const currentData = <?= json_encode(array_values($currentMap)) ?>;
let recommendedData = <?= json_encode(array_values($recommendedMap)) ?>;

/* ---------- CURRENT CHART ---------- */
new Chart(currentChart,{
    type:'pie',
    plugins:[pie3DPlugin],
    data:{
        labels,
        datasets:[{
            data:currentData,
            backgroundColor:colors,
            borderColor:'#fff',
            borderWidth:2
        }]
    },
    options:{plugins:{legend:{display:false}}}
});

/* ---------- RECOMMENDED CHART ---------- */
let recChart;
function renderRec(){
    if(!recChart){
        recChart=new Chart(recommendedChart,{
            type:'pie',
            plugins:[pie3DPlugin],
            data:{
                labels,
                datasets:[{
                    data:recommendedData,
                    backgroundColor:colors,
                    borderColor:'#fff',
                    borderWidth:2
                }]
            },
            options:{plugins:{legend:{display:false}}}
        });
    }else{
        recChart.data.datasets[0].data = recommendedData;
        recChart.update();
    }
}
renderRec();

/* ---------- VALIDATION + AUTOSAVE ---------- */

function saveToDB(){
    const payload = labels.map((a,i)=>({
        asset:a,
        value:recommendedData[i]
    }));
    const fd = new FormData();
    fd.append('action','save_recommended');
    fd.append('data',JSON.stringify(payload));

    fetch('',{method:'POST',body:fd});
}

/* ---------- INPUT HANDLER ---------- */
document.querySelectorAll('.rec-input').forEach((el,i)=>{
    el.addEventListener('input',()=>{
        recommendedData[i] = parseFloat(el.value) || 0;
        renderRec();
        validateAndSave();   // ✅ THIS WAS MISSING
    });
});
function notifyParent(valid) {
    if (window.parent !== window) {
        window.parent.postMessage({
            type: 'allocation-warning',
            valid: valid
        }, '*');
    }
}

function sendSlide7Status(isValid) {
    if (window.parent !== window) {
        window.parent.postMessage({
            type: 'slide-validation',
            slide: 7,
            valid: isValid
        }, '*');
    }
}

function validateAndSave() {
    const total = recommendedData.reduce((a, b) => a + b, 0);
    const isValid = Math.round(total) === 100;

    // 🔔 Notify parent on every change
    sendSlide7Status(isValid);

    // ❌ Do not save unless valid
    if (!isValid) return;

    // ✅ Save only when valid
    saveToDB();
}

</script>

</body>
</html>
