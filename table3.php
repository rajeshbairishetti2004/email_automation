<?php
// Section 3: Appropriate Asset Allocation
// Expects: $allocations
?>
<h3>3. Appropriate Asset Allocation</h3>
<div style="max-width: 100%; margin: 20px auto; display: flex; flex-direction:column; align-items: center;">
    <canvas id="allocationChart" style="max-height: 300px; max-width: 100%;"></canvas>
    <?php if (empty($allocations)): ?>
        <div style="color:#e55353; font-size:15px; margin-top:10px;">No asset allocation data available for this client.</div>
    <?php endif; ?>
</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script>
    // Asset allocation data for pie chart
    const allocationData = <?php 
        $hasGold = false;
        foreach ($allocations as $a) {
            if (stripos($a['asset'], 'Gold') !== false) {
                $hasGold = true;
                break;
            }
        }
        if (!$hasGold) {
            $allocations[] = ['asset' => 'Gold', 'share_pct' => 0];
        }
        $chartLabels = [];
        $chartValues = [];
        $chartColors = [];
        foreach ($allocations as $a) {
            $shareVal = (float)$a['share_pct'];
            $assetName = $a['asset'];
            if ($shareVal <= 0 && stripos($assetName, 'Gold') === false) {
                continue;
            }
            $chartLabels[] = $assetName . ' (' . number_format($shareVal, 2) . '%)';
            $chartValues[] = $shareVal;
            if (stripos($assetName, 'Equity') !== false) {
                $chartColors[] = '#36A2EB';
            } elseif (stripos($assetName, 'Debt') !== false) {
                $chartColors[] = '#2eb85c';
            } elseif (stripos($assetName, 'Gold') !== false) {
                $chartColors[] = '#f9b115';
            } else {
                $chartColors[] = '#e55353';
            }
        }
        echo json_encode([
            'labels' => $chartLabels,
            'values' => $chartValues,
            'colors' => $chartColors
        ]);
    ?>;
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
                legend: {
                    position: 'right',
                    align: 'center',
                    labels: {
                        usePointStyle: true,
                        boxWidth: 15,
                        padding: 10,
                        font: {
                            size: 13
                        }
                    }
                },
                tooltip: {
                    enabled: true
                }
            }
        }
    });
</script>


