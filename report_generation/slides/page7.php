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
   ADD NEW ASSET (AJAX)
===================================================== */
if (
    $_SERVER['REQUEST_METHOD'] === 'POST'
    && ($_POST['action'] ?? '') === 'add_asset'
) {
    header('Content-Type: application/json');

    $assetName = trim($_POST['asset_name'] ?? '');
    $recommendedPct = (float)($_POST['recommended_pct'] ?? 0);

    if (empty($assetName)) {
        echo json_encode(['success' => false, 'message' => 'Asset name is required']);
        exit;
    }

    try {
        // Add only to recommended (current will remain 0)
        $insert = $slidesPdo->prepare("
            INSERT INTO slide7 
            (client_id, asset, current_pct, recommended_pct, updated_at)
            VALUES (?, ?, 0, ?, NOW())
        ");
        $insert->execute([
            $clientId,
            ucfirst(strtolower($assetName)),
            $recommendedPct
        ]);

        $newId = $slidesPdo->lastInsertId();
        echo json_encode([
            'success' => true,
            'id' => $newId,
            'asset' => ucfirst(strtolower($assetName)),
            'current_pct' => 0,
            'recommended_pct' => $recommendedPct
        ]);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => 'Failed to add asset']);
    }
    exit;
}

/* =====================================================
   DELETE ASSET (AJAX)
===================================================== */
if (
    $_SERVER['REQUEST_METHOD'] === 'POST'
    && ($_POST['action'] ?? '') === 'delete_asset'
) {
    header('Content-Type: application/json');

    $id = (int)($_POST['id'] ?? 0);

    if ($id <= 0) {
        echo json_encode(['success' => false, 'message' => 'Invalid asset ID']);
        exit;
    }

    try {
        // Delete the entire row (both current and recommended)
        $delete = $slidesPdo->prepare("DELETE FROM slide7 WHERE id = ? AND client_id = ?");
        $delete->execute([$id, $clientId]);

        echo json_encode(['success' => true]);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => 'Failed to delete asset']);
    }
    exit;
}

/* =====================================================
   UPDATE ASSET NAME (AJAX)
===================================================== */
if (
    $_SERVER['REQUEST_METHOD'] === 'POST'
    && ($_POST['action'] ?? '') === 'update_asset_name'
) {
    header('Content-Type: application/json');

    $id = (int)($_POST['id'] ?? 0);
    $assetName = trim($_POST['asset_name'] ?? '');

    if ($id <= 0 || empty($assetName)) {
        echo json_encode(['success' => false, 'message' => 'Invalid data']);
        exit;
    }

    try {
        $update = $slidesPdo->prepare("
            UPDATE slide7
            SET asset = ?, updated_at = NOW()
            WHERE id = ? AND client_id = ?
        ");
        $update->execute([
            ucfirst(strtolower($assetName)),
            $id,
            $clientId
        ]);

        echo json_encode(['success' => true]);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => 'Failed to update asset name']);
    }
    exit;
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

// Generate colors based on number of assets
$baseColors = ['#4f7df3', '#2eb85c', '#f9b115', '#e55353', '#9d5cf2', '#f567a1', '#4fd3d6', '#f99315'];
$colors = [];
for ($i = 0; $i < count($assetOrder); $i++) {
    $colors[] = $baseColors[$i % count($baseColors)];
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
            height: 100%;
            margin: 0;
            font-family: Calibri, "Segoe UI", Arial;
            background: #fff;
        }

        .slide {
            height: 100vh;
            padding: 42px 70px 0;
            position: relative;
            box-sizing: border-box;
        }

        .title {
            text-align: center;
            font-size: 42px;
            color: #3B73E8;
            font-weight: 600;
            margin-bottom: 20px;
        }

        .edit-controls {
            position: absolute;
            top: 30px;
            right: 40px;
            display: flex;
            gap: 10px;
        }

        .edit-controls button {
            padding: 8px 18px;
            border: none;
            border-radius: 6px;
            font-size: 14px;
            font-weight: 600;
            cursor: pointer;
        }

        #editBtn {
            background: #3B73E8;
            color: #fff;
        }

        #addAssetBtn {
            background: #9d5cf2;
            color: #fff;
        }

        #saveBtn {
            background: #2eb85c;
            color: #fff;
            display: none;
        }

        .charts-row {
            display: flex;
            justify-content: space-between;
            gap: 60px;
        }

        .chart-box {
            width: 46%;
            background: #fff;
            border-radius: 12px;
            padding: 22px 28px;
            box-shadow: 0 4px 10px rgba(0, 0, 0, .06);
        }

        .chart-title {
            text-align: center;
            font-weight: 600;
            margin-bottom: 18px;
            font-size: 24px;
        }

        .chart-content {
            display: flex;
            gap: 22px;
            justify-content: center;
            align-items: center;
        }

        .chart-canvas-wrapper {
            width: 240px;
            height: 240px;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .chart-canvas-wrapper canvas {
            width: 100% !important;
            height: 100% !important;
        }

        .legend {
            display: flex;
            flex-direction: column;
            gap: 10px;
            font-size: 13.5px;
            max-height: 200px;
            overflow-y: auto;
            padding-right: 5px;
        }

        .legend-row {
            display: flex;
            align-items: center;
            gap: 3px;
            padding: 5px 0;
        }

        .color-box {
            width: 14px;
            height: 14px;
            border-radius: 3px;
            flex-shrink: 0;
        }

        .legend input {
            padding: 3px 2px;
            border: none;
            background: transparent;
            font-family: inherit;
            font-size: inherit;
            color: inherit;
            margin: 0;
        }

        .rec-input {
            width: 40px;
            text-align: center;
        }

        .asset-name-input {
            width: 85px;
            text-align: left;
            border-bottom: 1px solid transparent;
            border-radius: 0;
            padding-left: 0;
            padding-right: 0;
        }

        .asset-name-input:disabled {
            border-bottom: 1px solid transparent;
            cursor: default;
        }

        .asset-name-input:enabled {
            border-bottom: 1px solid #ddd;
        }

        .asset-name-input:enabled:focus {
            outline: none;
            border-bottom: 1px solid #3B73E8;
            background: #f5f8ff;
        }

        .delete-asset-btn {
            background: #e55353;
            color: white;
            border: none;
            border-radius: 4px;
            width: 24px;
            height: 24px;
            cursor: pointer;
            font-size: 12px;
            display: none;
            flex-shrink: 0;
            margin-left: 2px;
        }

        .delete-asset-btn:hover {
            background: #d44242;
        }

        .interpretation-title {
            margin-top: 22px;
            font-size: 30px;
            font-weight: 600;
        }

        .interpretation {
            font-size: 20px;
            color: #0b3cc1;
            font-style: italic;
        }

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

        /* Modal styles */
        .modal {
            display: none;
            position: fixed;
            z-index: 1000;
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            background-color: rgba(0, 0, 0, 0.5);
        }

        .modal-content {
            background-color: white;
            margin: 15% auto;
            padding: 30px;
            border-radius: 10px;
            width: 400px;
            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.2);
        }

        .modal-header {
            font-size: 24px;
            font-weight: bold;
            color: #3B73E8;
            margin-bottom: 20px;
            text-align: center;
        }

        .form-group {
            margin-bottom: 20px;
        }

        .form-group label {
            display: block;
            margin-bottom: 8px;
            font-weight: 600;
        }

        .form-group input {
            width: 100%;
            padding: 10px;
            border: 1px solid #ddd;
            border-radius: 5px;
            box-sizing: border-box;
        }

        .modal-buttons {
            display: flex;
            gap: 10px;
            justify-content: flex-end;
        }

        .modal-buttons button {
            padding: 10px 20px;
            border: none;
            border-radius: 5px;
            cursor: pointer;
            font-weight: 600;
        }

        #cancelBtn {
            background: #e55353;
            color: white;
        }

        #submitAssetBtn {
            background: #2eb85c;
            color: white;
        }
    </style>
</head>

<body>
    <div class="slide">
        <div class="edit-controls">
            <button id="editBtn">Edit</button>
            <button id="addAssetBtn">+ Add Asset</button>
            <button id="saveBtn">Save</button>
        </div>

        <div class="title">Asset Allocation – Current vs Recommended</div>

        <div class="charts-row">
            <div class="chart-box">
                <div class="chart-title">Current Allocation</div>
                <div class="chart-content">
                    <div class="chart-canvas-wrapper">
                        <canvas id="currentChart"></canvas>
                    </div>

                    <div class="legend" id="currentLegend">
                        <?php foreach ($assetOrder as $i => $a): ?>
                            <div class="legend-row">
                                <span class="color-box" style="background:<?= $colors[$i] ?>"></span>
                                <span class="asset-name-display"><?= $a ?></span> – <?= number_format($currentData[$i], 1) ?>%
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>

            <div class="chart-box">
                <div class="chart-title">Recommended Allocation</div>
                <div class="chart-content">
                    <div class="chart-canvas-wrapper">
                        <canvas id="recommendedChart"></canvas>
                    </div>

                    <div class="legend" id="recommendedLegend">
                        <?php foreach ($assetOrder as $i => $a): ?>
                            <div class="legend-row">
                                <span class="color-box" style="background:<?= $colors[$i] ?>"></span>
                                <input
                                    type="text"
                                    class="asset-name-input"
                                    data-id="<?= $ids[$i] ?>"
                                    value="<?= $a ?>"
                                    disabled>
                                <input
                                    type="number"
                                    class="rec-input"
                                    data-id="<?= $ids[$i] ?>"
                                    min="0"
                                    max="100"
                                    step="0.1"
                                    value="<?= number_format($recommendedData[$i], 1) ?>"
                                    disabled> %
                                <button class="delete-asset-btn" data-id="<?= $ids[$i] ?>" title="Delete asset">×</button>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
        </div>

        <div class="interpretation-title">Finance Doctor's interpretation:</div>
        <div class="interpretation">
            To build up global wealth & precious metals, gradually move towards the recommended allocation.<br>
            As a first step, reduce Indian equity allocation slightly and reinvest into global equity.
        </div>

        <div class="logo"><img src="/email_automation/image.png"></div>
        <div class="footer"></div>
    </div>

    <!-- Add Asset Modal -->
    <div id="addAssetModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">Add New Asset</div>
            <div class="form-group">
                <label for="assetName">Asset Name:</label>
                <input type="text" id="assetName" placeholder="e.g., Gold, Real Estate, Crypto">
            </div>
            <div class="form-group">
                <label for="recommendedPct">Recommended Percentage:</label>
                <input type="number" id="recommendedPct" min="0" max="100" step="0.1" value="0">
            </div>
            <div class="modal-buttons">
                <button id="cancelBtn">Cancel</button>
                <button id="submitAssetBtn">Add Asset</button>
            </div>
        </div>
    </div>

    <script>
        // These are the initial data from PHP
        const initialLabels = <?= json_encode($assetOrder) ?>;
        const initialCurrentData = <?= json_encode($currentData) ?>;
        const initialRowIds = <?= json_encode($ids) ?>;
        const initialColors = <?= json_encode($colors) ?>;
        const initialRecommendedData = <?= json_encode($recommendedData) ?>;

        // We'll use these for manipulation
        let labels = [...initialLabels];
        let currentData = [...initialCurrentData];
        let rowIds = [...initialRowIds];
        let colors = [...initialColors];
        let recommendedData = [...initialRecommendedData];

        // Chart instances
        let currentChartInstance = null;
        let recommendedChartInstance = null;

        function updateCurrentChart() {
            const ctx = document.getElementById('currentChart').getContext('2d');
            
            // Destroy existing chart if it exists
            if (currentChartInstance) {
                currentChartInstance.destroy();
            }
            
            // Create new chart
            currentChartInstance = new Chart(ctx, {
                type: 'pie',
                data: {
                    labels: initialLabels,
                    datasets: [{
                        data: initialCurrentData,
                        backgroundColor: initialColors,
                        borderWidth: 1
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: true,
                    plugins: {
                        legend: {
                            display: false
                        },
                        tooltip: {
                            callbacks: {
                                label: function(context) {
                                    const label = context.label || '';
                                    const value = context.raw || 0;
                                    return `${label}: ${value.toFixed(1)}%`;
                                }
                            }
                        }
                    }
                }
            });
        }

        function updateRecommendedChart() {
            const ctx = document.getElementById('recommendedChart').getContext('2d');
            
            // Destroy existing chart if it exists
            if (recommendedChartInstance) {
                recommendedChartInstance.destroy();
            }
            
            // Create new chart
            recommendedChartInstance = new Chart(ctx, {
                type: 'pie',
                data: {
                    labels: labels,
                    datasets: [{
                        data: recommendedData,
                        backgroundColor: colors,
                        borderWidth: 1
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: true,
                    plugins: {
                        legend: {
                            display: false
                        },
                        tooltip: {
                            callbacks: {
                                label: function(context) {
                                    const label = context.label || '';
                                    const value = context.raw || 0;
                                    return `${label}: ${value.toFixed(1)}%`;
                                }
                            }
                        }
                    }
                }
            });
        }

        // Initial chart creation
        updateCurrentChart();
        updateRecommendedChart();

        // DOM Elements
        const editBtn = document.getElementById('editBtn');
        const addAssetBtn = document.getElementById('addAssetBtn');
        const saveBtn = document.getElementById('saveBtn');
        const modal = document.getElementById('addAssetModal');
        const cancelBtn = document.getElementById('cancelBtn');
        const submitAssetBtn = document.getElementById('submitAssetBtn');
        let editMode = false;

        // Edit Mode Toggle
        editBtn.onclick = () => {
            editMode = !editMode;
            const inputs = document.querySelectorAll('.rec-input, .asset-name-input');
            const deleteBtns = document.querySelectorAll('.delete-asset-btn');
            
            inputs.forEach(i => i.disabled = !editMode);
            deleteBtns.forEach(btn => btn.style.display = editMode ? 'block' : 'none');
            
            if (editMode) {
                editBtn.style.display = 'none';
                addAssetBtn.style.display = 'none';
                saveBtn.style.display = 'inline-block';
            } else {
                editBtn.style.display = 'inline-block';
                addAssetBtn.style.display = 'inline-block';
                saveBtn.style.display = 'none';
            }
        };

        // Modal Controls
        addAssetBtn.onclick = () => {
            document.getElementById('assetName').value = '';
            document.getElementById('recommendedPct').value = '0';
            modal.style.display = 'block';
        };

        cancelBtn.onclick = () => {
            modal.style.display = 'none';
        };

        // Close modal when clicking outside
        window.onclick = (event) => {
            if (event.target === modal) {
                modal.style.display = 'none';
            }
        };

        // Add New Asset
        submitAssetBtn.onclick = async () => {
            const assetName = document.getElementById('assetName').value.trim();
            const recommendedPct = parseFloat(document.getElementById('recommendedPct').value) || 0;
            
            if (!assetName) {
                alert('Please enter an asset name');
                return;
            }
            
            if (recommendedPct < 0 || recommendedPct > 100) {
                alert('Percentage must be between 0 and 100');
                return;
            }
            
            const formData = new FormData();
            formData.append('action', 'add_asset');
            formData.append('asset_name', assetName);
            formData.append('recommended_pct', recommendedPct);
            
            try {
                const response = await fetch('slides/page7.php', {
                    method: 'POST',
                    body: formData
                });
                
                const result = await response.json();
                
                if (result.success) {
                    // Add to arrays
                    labels.push(result.asset);
                    rowIds.push(result.id);
                    currentData.push(0); // Current is always 0 for new assets
                    recommendedData.push(result.recommended_pct);
                    
                    // Add a new color
                    const baseColors = ['#4f7df3', '#2eb85c', '#f9b115', '#e55353', '#9d5cf2', '#f567a1', '#4fd3d6', '#f99315'];
                    colors.push(baseColors[labels.length % baseColors.length]);
                    
                    // Update charts
                    updateRecommendedChart();
                    
                    // Update legends
                    updateLegends();
                    
                    // Close modal
                    modal.style.display = 'none';
                    
                    // Show success message
                    alert('Asset added successfully!');
                } else {
                    alert(result.message || 'Failed to add asset');
                }
            } catch (error) {
                alert('Error adding asset');
                console.error(error);
            }
        };

        // Delete Asset
        document.addEventListener('click', async (e) => {
            if (e.target.classList.contains('delete-asset-btn')) {
                if (!confirm('Are you sure you want to delete this asset?')) {
                    return;
                }
                
                const id = parseInt(e.target.dataset.id);
                const index = rowIds.indexOf(id);
                
                if (index === -1) return;
                
                const formData = new FormData();
                formData.append('action', 'delete_asset');
                formData.append('id', id);
                
                try {
                    const response = await fetch('slides/page7.php', {
                        method: 'POST',
                        body: formData
                    });
                    
                    const result = await response.json();
                    
                    if (result.success) {
                        // Remove from arrays
                        labels.splice(index, 1);
                        rowIds.splice(index, 1);
                        currentData.splice(index, 1);
                        recommendedData.splice(index, 1);
                        colors.splice(index, 1);
                        
                        // Update charts
                        updateRecommendedChart();
                        
                        // Update legends
                        updateLegends();
                        
                        alert('Asset deleted successfully!');
                    } else {
                        alert(result.message || 'Failed to delete asset');
                    }
                } catch (error) {
                    alert('Error deleting asset');
                    console.error(error);
                }
            }
        });

        // Update Asset Name
        document.addEventListener('change', async (e) => {
            if (e.target.classList.contains('asset-name-input') && editMode) {
                const id = parseInt(e.target.dataset.id);
                const newName = e.target.value.trim();
                const index = rowIds.indexOf(id);
                
                if (index === -1 || !newName) return;
                
                const formData = new FormData();
                formData.append('action', 'update_asset_name');
                formData.append('id', id);
                formData.append('asset_name', newName);
                
                try {
                    const response = await fetch('slides/page7.php', {
                        method: 'POST',
                        body: formData
                    });
                    
                    const result = await response.json();
                    
                    if (result.success) {
                        // Update the label in the array
                        labels[index] = newName;
                        
                        // Update the chart
                        updateRecommendedChart();
                        
                        // Also update the current legend if the asset exists there
                        const currentLegendItems = document.querySelectorAll('#currentLegend .legend-row');
                        if (currentLegendItems[index]) {
                            const nameSpan = currentLegendItems[index].querySelector('.asset-name-display');
                            if (nameSpan) {
                                nameSpan.textContent = newName;
                            }
                        }
                        
                        alert('Asset name updated successfully!');
                    } else {
                        alert(result.message || 'Failed to update asset name');
                        // Revert the input value
                        e.target.value = labels[index];
                    }
                } catch (error) {
                    alert('Error updating asset name');
                    console.error(error);
                    // Revert the input value
                    e.target.value = labels[index];
                }
            }
        });

        // Update Input Values in Real-time
        document.addEventListener('input', (e) => {
            if (e.target.classList.contains('rec-input') && editMode) {
                const index = rowIds.indexOf(parseInt(e.target.dataset.id));
                if (index !== -1) {
                    recommendedData[index] = parseFloat(e.target.value) || 0;
                    if (recommendedChartInstance) {
                        recommendedChartInstance.data.datasets[0].data = recommendedData;
                        recommendedChartInstance.update();
                    }
                }
            }
        });

        // Save Recommended Allocations
        saveBtn.onclick = async () => {
            // Validate total is 100%
            const total = [...document.querySelectorAll('.rec-input')]
                .reduce((s, i) => s + (parseFloat(i.value) || 0), 0);
            
            if (Math.abs(total - 100) > 0.1) {
                alert(`Recommended allocation must total 100% (Current: ${total.toFixed(1)}%)`);
                return;
            }
            
            // Prepare payload for percentages
            const payload = [];
            document.querySelectorAll('.rec-input').forEach(input => {
                payload.push({
                    id: parseInt(input.dataset.id),
                    value: parseFloat(input.value) || 0
                });
            });
            
            // Save to database
            const formData = new FormData();
            formData.append('action', 'save_recommended');
            formData.append('data', JSON.stringify(payload));
            
            try {
                const response = await fetch('slides/page7.php', {
                    method: 'POST',
                    body: formData
                });
                
                const result = await response.json();
                
                if (result.success) {
                    // Update recommended chart
                    if (recommendedChartInstance) {
                        recommendedChartInstance.data.datasets[0].data = recommendedData;
                        recommendedChartInstance.update();
                    }
                    
                    // Exit edit mode
                    editMode = false;
                    const inputs = document.querySelectorAll('.rec-input, .asset-name-input');
                    const deleteBtns = document.querySelectorAll('.delete-asset-btn');
                    
                    inputs.forEach(i => i.disabled = true);
                    deleteBtns.forEach(btn => btn.style.display = 'none');
                    
                    editBtn.style.display = 'inline-block';
                    addAssetBtn.style.display = 'inline-block';
                    saveBtn.style.display = 'none';
                    
                    alert('Changes saved successfully!');
                } else {
                    alert('Failed to save changes');
                }
            } catch (error) {
                alert('Error saving changes');
                console.error(error);
            }
        };

        // Function to update legends
        function updateLegends() {
            // Update current legend
            const currentLegend = document.getElementById('currentLegend');
            currentLegend.innerHTML = '';
            
            labels.forEach((asset, i) => {
                const row = document.createElement('div');
                row.className = 'legend-row';
                row.innerHTML = `
                    <span class="color-box" style="background:${colors[i]}"></span>
                    <span class="asset-name-display">${asset}</span> – ${currentData[i].toFixed(1)}%
                `;
                currentLegend.appendChild(row);
            });
            
            // Update recommended legend
            const recommendedLegend = document.getElementById('recommendedLegend');
            recommendedLegend.innerHTML = '';
            
            labels.forEach((asset, i) => {
                const row = document.createElement('div');
                row.className = 'legend-row';
                row.innerHTML = `
                    <span class="color-box" style="background:${colors[i]}"></span>
                    <input
                        type="text"
                        class="asset-name-input"
                        data-id="${rowIds[i]}"
                        value="${asset}"
                        ${editMode ? '' : 'disabled'}>
                    <input
                        type="number"
                        class="rec-input"
                        data-id="${rowIds[i]}"
                        min="0"
                        max="100"
                        step="0.1"
                        value="${recommendedData[i].toFixed(1)}"
                        ${editMode ? '' : 'disabled'}> %
                    <button class="delete-asset-btn" data-id="${rowIds[i]}" title="Delete asset" 
                            style="${editMode ? 'display: block' : 'display: none'}">×</button>
                `;
                recommendedLegend.appendChild(row);
            });
            
            // Re-attach event listeners for the new inputs
            attachEventListeners();
        }

        // Attach event listeners to inputs
        function attachEventListeners() {
            // Re-attach input event for percentage inputs
            document.querySelectorAll('.rec-input').forEach(input => {
                input.addEventListener('input', (e) => {
                    if (editMode) {
                        const index = rowIds.indexOf(parseInt(e.target.dataset.id));
                        if (index !== -1) {
                            recommendedData[index] = parseFloat(e.target.value) || 0;
                            if (recommendedChartInstance) {
                                recommendedChartInstance.data.datasets[0].data = recommendedData;
                                recommendedChartInstance.update();
                            }
                        }
                    }
                });
            });
            
            // Re-attach change event for asset name inputs
            document.querySelectorAll('.asset-name-input').forEach(input => {
                input.addEventListener('change', async (e) => {
                    if (editMode) {
                        const id = parseInt(e.target.dataset.id);
                        const newName = e.target.value.trim();
                        const index = rowIds.indexOf(id);
                        
                        if (index === -1 || !newName) return;
                        
                        const formData = new FormData();
                        formData.append('action', 'update_asset_name');
                        formData.append('id', id);
                        formData.append('asset_name', newName);
                        
                        try {
                            const response = await fetch('slides/page7.php', {
                                method: 'POST',
                                body: formData
                            });
                            
                            const result = await response.json();
                            
                            if (result.success) {
                                labels[index] = newName;
                                updateRecommendedChart();
                                
                                // Update current legend
                                const currentLegendItems = document.querySelectorAll('#currentLegend .legend-row');
                                if (currentLegendItems[index]) {
                                    const nameSpan = currentLegendItems[index].querySelector('.asset-name-display');
                                    if (nameSpan) {
                                        nameSpan.textContent = newName;
                                    }
                                }
                            } else {
                                e.target.value = labels[index];
                                alert(result.message || 'Failed to update asset name');
                            }
                        } catch (error) {
                            e.target.value = labels[index];
                            alert('Error updating asset name');
                            console.error(error);
                        }
                    }
                });
            });
        }

        // Initialize legends and attach event listeners
        updateLegends();
    </script>
</body>
</html>