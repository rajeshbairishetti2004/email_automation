<?php
// Section 3: Appropriate Asset Allocation
// Expects: $allocations, $clientId

require_once __DIR__ . '/db_config.php';

// --- Handle AJAX save for recommended allocations (CRUD) ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['client_id']) && isset($_POST['recommended_share_pct'])) {
    header('Content-Type: application/json; charset=UTF-8');
    try {
        $pdo = getPdo();
        $clientId = (int)$_POST['client_id'];
        if ($clientId <= 0) throw new Exception('Invalid client ID');

        $assets = $_POST['recommended_asset'] ?? [];
        $share_pcts = $_POST['recommended_share_pct'] ?? [];

        if (!is_array($assets) || !is_array($share_pcts)) throw new Exception('Invalid input');

        $updated = 0;
        foreach ($assets as $idx => $asset) {
            $asset = trim($asset);
            $share = isset($share_pcts[$idx]) ? floatval($share_pcts[$idx]) : null;

            // Try to update, if not exists, insert
            $stmt = $pdo->prepare("SELECT id FROM client_allocations WHERE client_id = ? AND asset = ?");
            $stmt->execute([$clientId, $asset]);
            $rowId = $stmt->fetchColumn();

            if ($rowId) {
                $stmt2 = $pdo->prepare("UPDATE client_allocations SET recommended_asset = ?, recommended_share_pct = ? WHERE id = ?");
                $stmt2->execute([$asset, $share, $rowId]);
                $updated += $stmt2->rowCount();
            } else {
                $stmt2 = $pdo->prepare("INSERT INTO client_allocations (client_id, asset, share_pct, recommended_asset, recommended_share_pct) VALUES (?, ?, 0, ?, ?)");
                $stmt2->execute([$clientId, $asset, $asset, $share]);
                $updated += $stmt2->rowCount();
            }
        }
        echo json_encode(['success' => true, 'updated' => $updated]);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

// --- Prepare asset order and values for both charts ---
$assetOrder = ['Equity', 'Debt', 'Gold', 'Others'];
$currentMap = [];
foreach ($allocations as $a) {
    $currentMap[ucfirst(strtolower($a['asset']))] = (float)$a['share_pct'];
}
$recommendedMap = [];
$pdo = getPdo();
$stmt = $pdo->prepare("SELECT asset, recommended_share_pct FROM client_allocations WHERE client_id = ?");
$stmt->execute([$clientId]);
foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
    $recommendedMap[ucfirst(strtolower($row['asset']))] = $row['recommended_share_pct'] !== null ? (float)$row['recommended_share_pct'] : '';
}
?>
<h3>3. Appropriate Asset Allocation</h3>
<div class="asset-allocation-main-container">
    <div class="piechart-container">
        <div style="font-weight:600; font-size:16px; margin-bottom:20px; text-align:center;">Current Asset Allocation</div>
        <div class="piechart-legend-container">
            <canvas id="allocationChart" class="piechart-canvas" style="max-height: 240px; max-width: 240px;"></canvas>
            <div class="piechart-legend">
                <?php foreach ($assetOrder as $asset): ?>
                    <div class="legend-row">
                        <span class="legend-color" style="background:
                            <?= $asset === 'Equity' ? '#36A2EB' : ($asset === 'Debt' ? '#2eb85c' : ($asset === 'Gold' ? '#f9b115' : '#e55353')) ?>;"></span>
                        <span class="legend-label"><?= $asset ?>:</span>
                        <span class="legend-value"><?= number_format($currentMap[$asset] ?? 0, 2) ?>%</span>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
<!--     
    <div class="piechart-container">
        <div style="font-weight:600; font-size:16px; margin-bottom:20px; text-align:center;">Recommended Asset Allocation</div>
        <form id="recommendedAllocationForm" style="margin-bottom: 8px;">
            <div class="piechart-legend-container">
                <canvas id="recommendedAllocationChart" class="piechart-canvas" style="max-height: 240px; max-width: 240px;"></canvas>
                <div class="piechart-legend">
                    <?php foreach ($assetOrder as $idx => $asset): ?>
                        <div class="legend-row">
                            <span class="legend-color" style="background:
                                <?= $asset === 'Equity' ? '#36A2EB' : ($asset === 'Debt' ? '#2eb85c' : ($asset === 'Gold' ? '#f9b115' : '#e55353')) ?>;"></span>
                            <label class="legend-label" style="width: 70px;"><?= $asset ?></label>
                            <input
                                type="hidden"
                                name="recommended_asset[<?= $idx ?>]"
                                value="<?= $asset ?>"
                            >
                            <input
                                type="number"
                                step="1"
                                min="0"
                                max="100"
                                name="recommended_share_pct[<?= $idx ?>]"
                                value="<?= htmlspecialchars($recommendedMap[$asset] ?? '') ?>"
                                style="width:70px; text-align:right; margin-left:6px;"
                                class="recommended-allocation-input"
                                data-asset="<?= $asset ?>"
                            > %
                        </div>
                    <?php endforeach; ?>
                    <input type="hidden" name="client_id" value="<?= (int)$clientId ?>">
                </div>
            </div>
        </form>
        <span id="recommendedAllocStatus" style="margin-left:10px; font-size:13px; display:none;"></span>
    </div> -->
</div>
<style>
/* --- Table 3: Asset Allocation Chart Styles --- */
h3{
    margin-top: 40px;
    margin-bottom:40px;
}
.asset-allocation-main-container {
    width: 100%;
    max-width: 1100px;
    margin: 0 auto 30px auto;
    padding: 0;
    display: flex;
    flex-direction: row;
    gap: 32px;
    justify-content: center;
}
.report-section-container {
    background: #fff;
    border-radius: 8px;
    box-shadow: 0 2px 8px rgba(0,0,0,0.04);
    padding: 24px 18px;
    margin: 20px 0;
}
.alloc-btn {
    background: #0288D1;
    color: #fff;
    border: none;
    border-radius: 5px;
    font-weight: 600;
    padding: 7px 18px;
    cursor: pointer;
    transition: background 0.2s;
    font-size: 14px;
}
.alloc-btn:hover {
    background: #0277bd;
}
#allocationChart, #recommendedAllocationChart {
    max-height: 260px;
    max-width: 100%;
    margin: 0 auto;
    display: block;
    background: #fff;
    border-radius: 8px;
    box-shadow: 0 1px 4px rgba(0,0,0,0.03);
}
.piechart-container {
    background: #fff;
    border-radius: 12px;
    box-shadow: 0 2px 8px rgba(0,0,0,0.06);
    padding: 18px 12px 18px 12px;
    margin-bottom: 0;
    min-width: 320px;
    max-width: 520px;
    width: 100%;
    display: flex;
    flex-direction: column;
    align-items: center;
}
.piechart-legend-container {
    display: flex;
    flex-direction: row;
    align-items: flex-start;
    justify-content: center;
    gap: 18px;
}
.piechart-canvas {
    max-height: 240px !important;
    max-width: 240px !important;
    min-width: 160px;
    min-height: 160px;
}
.piechart-legend {
    display: flex;
    flex-direction: column;
    gap: 10px;
    min-width: 140px;
    margin-left: 8px;
    margin-top:10px;
}
.legend-row {
    display: flex;
    align-items: center;
    gap: 7px;
    font-size: 14px;
}
.legend-color {
    display: inline-block;
    width: 16px;
    height: 16px;
    border-radius: 3px;
    margin-right: 4px;
}
.legend-label {
    min-width: 60px;
    font-weight: 500;
}
.legend-value {
    min-width: 50px;
    text-align: right;
    font-family: monospace;
}
@media (max-width: 900px) {
    .asset-allocation-main-container {
        flex-direction: column;
        gap: 24px;
        max-width: 100%;
        padding: 0 2vw;
    }
    .piechart-container {
        min-width: 0;
        max-width: 100%;
        width: 100%;
        margin-bottom: 18px;
    }
    .piechart-legend-container {
        flex-direction: column;
        align-items: center;
        gap: 10px;
    }
    .piechart-legend {
        margin-left: 0;
    }
    .piechart-canvas {
        max-width: 100vw !important;
        max-height: 180px !important;
    }
}
</style>
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script>
    // --- Current Asset Allocation Pie Chart ---
    const allocationData = {
        labels: <?= json_encode($assetOrder) ?>,
        values: <?= json_encode(array_map(function($a) use ($currentMap) { return $currentMap[$a] ?? 0; }, $assetOrder)) ?>,
        colors: ['#36A2EB', '#2eb85c', '#f9b115', '#e55353']
    };
    const ctx = document.getElementById('allocationChart').getContext('2d');
    new Chart(ctx, {
        type: 'pie',
        data: {
            labels: allocationData.labels,
            datasets: [{
                data: allocationData.values,
                backgroundColor: allocationData.colors,
                borderColor: '#fff',
                borderWidth: 2
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: true,
            plugins: {
                legend: { display: false },
                tooltip: { enabled: true }
            }
        }
    });

    // --- Recommended Asset Allocation Pie Chart ---
    function renderRecommendedChart() {
        const labels = [];
        const values = [];
        const colors = ['#36A2EB', '#2eb85c', '#f9b115', '#e55353'];
        document.querySelectorAll('.recommended-allocation-input').forEach(function(input, idx) {
            const asset = input.getAttribute('data-asset');
            const val = parseFloat(input.value) || 0;
            labels.push(asset + ' (' + val.toFixed(2) + '%)');
            values.push(val);
        });
        const ctx2 = document.getElementById('recommendedAllocationChart').getContext('2d');
        if (window.recommendedChart) window.recommendedChart.destroy();
        window.recommendedChart = new Chart(ctx2, {
            type: 'pie',
            data: {
                labels: labels,
                datasets: [{
                    data: values,
                    backgroundColor: colors,
                    borderColor: '#fff',
                    borderWidth: 2
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: true,
                plugins: {
                    legend: { display: false },
                    tooltip: { enabled: true }
                }
            }
        });
    }

    // Initial render
    renderRecommendedChart();

    // --- Auto-save on input change ---
    document.querySelectorAll('.recommended-allocation-input').forEach(function(input) {
        input.addEventListener('input', function() {
            renderRecommendedChart();
            autoSaveRecommendedAlloc();
        });
    });

    function autoSaveRecommendedAlloc() {
        const form = document.getElementById('recommendedAllocationForm');
        const status = document.getElementById('recommendedAllocStatus');
        const data = new FormData(form);
        status.style.display = 'inline';
        status.textContent = 'Saving...';
        fetch('table3.php', {
            method: 'POST',
            body: data
        })
        .then(r => r.json())
        .then(res => {
            if (res.success) {
                status.textContent = '✓ Saved';
                status.style.color = '#28a745';
            } else {
                status.textContent = '❌ Error';
                status.style.color = '#dc3545';
            }
            setTimeout(() => { status.style.display = 'none'; }, 1200);
        })
        .catch(() => {
            status.textContent = '❌ Error';
            status.style.color = '#dc3545';
            setTimeout(() => { status.style.display = 'none'; }, 1200);
        });
    }
</script>


