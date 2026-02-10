<?php
// report_generator/slides/page8.php

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../database.php';
require_once __DIR__ . '/../../db_config.php';

/* =====================================================
   DATABASE CONNECTION
===================================================== */
$slidesPdo = getSlidesPdo();

// Get client ID from URL
$clientKey = $_GET['client_id'] ?? ($_SESSION['current_client_id'] ?? '');
$clientId = (int)str_replace('CLIENT_', '', $clientKey);

/* =====================================================
   AJAX HANDLER SECTION - MUST BE AT THE TOP
===================================================== */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    // IMPORTANT: We need to output ONLY JSON and exit
    header('Content-Type: application/json');

    // Get client ID from POST or GET
    $ajaxClientKey = '';

    if (!empty($_POST['client_id'])) {
        $ajaxClientKey = $_POST['client_id'];
    } elseif (!empty($_GET['client_id'])) {
        $ajaxClientKey = $_GET['client_id'];
    } elseif (!empty($_SESSION['current_client_id'])) {
        $ajaxClientKey = $_SESSION['current_client_id'];
    }
    $ajaxClientId = 0;
    if (preg_match('/(\d+)/', $ajaxClientKey, $m)) {
        $ajaxClientId = (int)$m[1];
    }

    $response = ['success' => false, 'message' => 'Unknown action'];

    switch ($_POST['action']) {
        case 'add_asset':
            $assetName = trim($_POST['asset_name'] ?? '');
            $recommendedPct = (float)($_POST['recommended_pct'] ?? 0);

            if (empty($assetName)) {
                $response = ['success' => false, 'message' => 'Asset name is required'];
                break;
            }

            if ($ajaxClientId <= 0) {
                echo json_encode([
                    'success' => false,
                    'message' => 'Client context lost. Please reload the page.'
                ]);
                exit;
            }


            try {
                // Check if asset already exists
                $checkStmt = $slidesPdo->prepare("SELECT id FROM slide8 WHERE client_id = ? AND LOWER(asset) = LOWER(?)");
                $checkStmt->execute([$ajaxClientId, $assetName]);

                if ($checkStmt->rowCount() > 0) {
                    $response = ['success' => false, 'message' => 'Asset "' . $assetName . '" already exists'];
                    break;
                }

                // Add only to recommended (current will remain 0)
                $insert = $slidesPdo->prepare("
                    INSERT INTO slide8 
                    (client_id, asset, current_pct, recommended_pct, updated_at)
                    VALUES (?, ?, 0, ?, NOW())
                ");
                $insert->execute([
                    $ajaxClientId,
                    ucfirst(strtolower($assetName)),
                    $recommendedPct
                ]);

                $newId = $slidesPdo->lastInsertId();
                $response = [
                    'success' => true,
                    'id' => $newId,
                    'asset' => ucfirst(strtolower($assetName)),
                    'current_pct' => 0,
                    'recommended_pct' => $recommendedPct
                ];
            } catch (Exception $e) {
                $response = ['success' => false, 'message' => 'Failed to add asset: ' . $e->getMessage()];
            }
            break;

        case 'delete_asset':
            $id = (int)($_POST['id'] ?? 0);

            if ($id <= 0) {
                $response = ['success' => false, 'message' => 'Invalid asset ID'];
                break;
            }

            try {
                $delete = $slidesPdo->prepare("DELETE FROM slide8 WHERE id = ? AND client_id = ?");
                $delete->execute([$id, $ajaxClientId]);
                $response = ['success' => true];
            } catch (Exception $e) {
                $response = ['success' => false, 'message' => 'Failed to delete asset'];
            }
            break;

        case 'update_asset_name':
            $id = (int)($_POST['id'] ?? 0);
            $assetName = trim($_POST['asset_name'] ?? '');

            if ($id <= 0 || empty($assetName)) {
                $response = ['success' => false, 'message' => 'Invalid data'];
                break;
            }

            try {
                $update = $slidesPdo->prepare("
                    UPDATE slide8
                    SET asset = ?, updated_at = NOW()
                    WHERE id = ? AND client_id = ?
                ");
                $update->execute([
                    ucfirst(strtolower($assetName)),
                    $id,
                    $ajaxClientId
                ]);
                $response = ['success' => true];
            } catch (Exception $e) {
                $response = ['success' => false, 'message' => 'Failed to update asset name'];
            }
            break;



        case 'save_recommended':
            $rows = json_decode($_POST['data'] ?? '[]', true);
            if (!is_array($rows)) {
                $response = ['success' => false, 'message' => 'Invalid data format'];
                break;
            }

            $interpretation = trim($_POST['interpretation'] ?? '');
            $newAssets = json_decode($_POST['new_assets'] ?? '[]', true);

            if (!empty($newAssets)) {
                $insert = $slidesPdo->prepare("
        INSERT INTO slide8
        (client_id, asset, current_pct, recommended_pct, updated_at)
        VALUES (?, ?, 0, ?, NOW())
    ");

                foreach ($newAssets as $a) {
                    $insert->execute([
                        $ajaxClientId,
                        $a['asset'],
                        (float)$a['recommended']
                    ]);
                }
            }


            $slidesPdo->beginTransaction();
            try {
                // Update recommended percentages
                $update = $slidesPdo->prepare("
                    UPDATE slide8
                    SET recommended_pct = ?, updated_at = NOW()
                    WHERE id = ?
                ");

                foreach ($rows as $r) {
                    $update->execute([
                        (float)$r['value'],
                        (int)$r['id']
                    ]);
                }

                // Save interpretation to session
                if ($interpretation !== '') {
                    $_SESSION['slide8_interpretation'][$ajaxClientId] = $interpretation;
                }

                $slidesPdo->commit();
                $response = ['success' => true];
            } catch (Exception $e) {
                $slidesPdo->rollBack();
                $response = ['success' => false, 'message' => 'Failed to save changes: ' . $e->getMessage()];
            }
            break;
    }

    // Output JSON and exit - NO HTML after this
    echo json_encode($response);
    exit;
}

/* =====================================================
   REGULAR PAGE LOAD - ONLY REACHED IF NOT AJAX
===================================================== */

/* =====================================================
   INITIAL SEED (RUNS ONCE PER CLIENT)
===================================================== */
$seedCheck = $slidesPdo->prepare(
    "SELECT COUNT(*) FROM slide8 WHERE client_id = ?"
);
$seedCheck->execute([$clientId]);

if ($seedCheck->fetchColumn() == 0) {
    // Default sample data for MCAP allocation
    $defaultAssets = [
        'Large Cap' => 40.0,
        'Mid Cap' => 30.0,
        'Small Cap' => 20.0,
        'Micro Cap' => 10.0
    ];

    $insert = $slidesPdo->prepare("
        INSERT INTO slide8
        (client_id, asset, current_pct, recommended_pct, updated_at)
        VALUES (?, ?, ?, ?, NOW())
    ");

    foreach ($defaultAssets as $asset => $pct) {
        $insert->execute([
            $clientId,
            $asset,
            $pct,
            $pct
        ]);
    }
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
    FROM slide8
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

// Get interpretation
$interpretation = $_SESSION['slide8_interpretation'][$clientId]
    ?? 'Different caps have the right percentage ranges. So, no change is recommended.';
?>

<!DOCTYPE html>
<html>

<head>
    <meta charset="UTF-8">
    <title>Equity MCAP allocation</title>
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <style>
        html,
        body {
            margin: 0;
            height: 100%;
            font-family: Calibri;
            background: #fff
        }

        .slide {
            height: 100vh;
            position: relative;
            padding: 40px 60px;
            box-sizing: border-box
        }

        /* Title */
        h1 {
            text-align: center;
            color: #4F7DF3;
            font-size: 42px;
            margin-bottom: 5px;
            margin-top: 0px
        }

        /* Edit Controls */
        .edit-controls {
            position: absolute;
            top: 30px;
            right: 60px;
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
            background: #4F7DF3;
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

        /* Charts Layout */
        .charts {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 20px
        }

        .box {
            text-align: center
        }

        .chart-row {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 30px
        }

        .chart-wrap {
            width: 240px;
            height: 240px
        }

        /* Legend */
        .legend {
            text-align: left;
            font-size: 14px;
            color: #0A3DBA
        }

        .legend-item {
            display: flex;
            align-items: center;
            margin-bottom: 8px;
            white-space: nowrap
        }

        .legend-color {
            width: 12px;
            height: 12px;
            margin-right: 8px;
            border-radius: 2px
        }

        /* Editable Legend */
        .editable-legend {
            display: flex;
            flex-direction: column;
            gap: 0px;
        }

        .legend-row {
            display: flex;
            align-items: center;
            gap: 0;
            padding: 0;
        }

        .color-box {
            width: 14px;
            height: 14px;
            border-radius: 3px;
            flex-shrink: 0;
        }

        .legend-row input {
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
            width: 90px;
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
            border-bottom: 1px solid #4F7DF3;
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

        /* Interpretation */
        .interpretation {
            margin-top: 50px;
            font-size: 22px;
            color: #0A3DBA;
            position: relative;
        }

        .interpretation-edit {
            width: 100%;
            border: 1px solid #ddd;
            border-radius: 5px;
            padding: 10px;
            font-size: 20px;
            font-family: Calibri;
            color: #0A3DBA;
            background: #fff;
            display: none;
            box-sizing: border-box;
        }

        .footer {
            position: absolute;
            bottom: 0;
            height: 10px;
            width: 100%;
            background: #4DB6AC
        }

        .logo {
            position: absolute;
            right: 40px;
            bottom: 30px
        }

        .logo img {
            width: 130px
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
            color: #4F7DF3;
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
        <!-- Edit Controls -->
        <div class="edit-controls">
            <button id="editBtn">Edit</button>
            <button id="addAssetBtn">+ Add Asset</button>
            <button id="saveBtn">Save</button>
        </div>

        <h1>Equity MCAP allocation</h1>

        <div class="charts">
            <!-- CURRENT -->
            <div class="box">
                <div style="font-weight:600;color:#0A3DBA;font-size:20px;margin-bottom:12px;">Current</div>
                <div class="chart-row">
                    <div class="chart-wrap">
                        <canvas id="currentChart"></canvas>
                    </div>
                    <div class="legend" id="currentLegend">
                        <?php foreach ($assetOrder as $i => $a): ?>
                            <div class="legend-item">
                                <span class="legend-color" style="background:<?= $colors[$i] ?>"></span>
                                <?= htmlspecialchars($a) ?> <?= number_format($currentData[$i], 2) ?>%
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
                    <div class="editable-legend" id="recommendedLegend">
                        <?php foreach ($assetOrder as $i => $a): ?>
                            <div class="legend-row">
                                <span class="color-box" style="background:<?= $colors[$i] ?>"></span>
                                <input
                                    type="text"
                                    class="asset-name-input"
                                    data-id="<?= $ids[$i] ?>"
                                    value="<?= htmlspecialchars($a) ?>"
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

        <div class="interpretation">
            <strong>Finance Doctor's interpretation:</strong><br>
            <div id="interpretationText"><?= htmlspecialchars($interpretation) ?></div>
            <textarea id="interpretationEdit" class="interpretation-edit" rows="3"><?= htmlspecialchars($interpretation) ?></textarea>
        </div>

        <div class="logo">
            <img src="/email_automation/image.png">
        </div>
        <div class="footer"></div>
    </div>

    <!-- Add Asset Modal -->
    <div id="addAssetModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">Add New Equity Category</div>
            <div class="form-group">
                <label for="assetName">Category Name:</label>
                <input type="text" id="assetName" placeholder="e.g., Mega Cap, Blue Chip">
            </div>
            <div class="form-group">
                <label for="recommendedPct">Recommended Percentage:</label>
                <input type="number" id="recommendedPct" min="0" max="100" step="0.1" value="0">
            </div>
            <div class="modal-buttons">
                <button id="cancelBtn">Cancel</button>
                <button id="submitAssetBtn">Add Category</button>
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

            if (currentChartInstance) {
                currentChartInstance.destroy();
            }

            currentChartInstance = new Chart(ctx, {
                type: 'doughnut',
                data: {
                    labels: labels,
                    datasets: [{
                        data: currentData,
                        backgroundColor: colors,
                        borderWidth: 1
                    }]
                },
                options: {
                    cutout: '55%',
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

            if (recommendedChartInstance) {
                recommendedChartInstance.destroy();
            }

            recommendedChartInstance = new Chart(ctx, {
                type: 'doughnut',
                data: {
                    labels: labels,
                    datasets: [{
                        data: recommendedData,
                        backgroundColor: colors,
                        borderWidth: 1
                    }]
                },
                options: {
                    cutout: '55%',
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
        const interpretationText = document.getElementById('interpretationText');
        const interpretationEdit = document.getElementById('interpretationEdit');

        let editMode = false;

        // Edit Mode Toggle
        editBtn.onclick = () => {
            editMode = !editMode;
            const inputs = document.querySelectorAll('.rec-input, .asset-name-input');
            const deleteBtns = document.querySelectorAll('.delete-asset-btn');

            inputs.forEach(i => i.disabled = !editMode);
            deleteBtns.forEach(btn => btn.style.display = editMode ? 'block' : 'none');

            // Toggle interpretation display
            if (editMode) {
                interpretationText.style.display = 'none';
                interpretationEdit.style.display = 'block';
                editBtn.style.display = 'none';
                addAssetBtn.style.display = 'none';
                saveBtn.style.display = 'inline-block';
            } else {
                interpretationText.style.display = 'block';
                interpretationEdit.style.display = 'none';
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

        // Simple function to get current page URL
        function getAjaxUrl() {
            return '/email_automation/report_generation/slides/page8.php';
        }


        // Add New Asset
        submitAssetBtn.onclick = async () => {
            const assetName = document.getElementById('assetName').value.trim();
            const recommendedPct = parseFloat(document.getElementById('recommendedPct').value) || 0;

            console.log('Add Asset:', {
                assetName,
                recommendedPct
            });

            if (!assetName) {
                alert('Please enter a category name');
                return;
            }

            if (recommendedPct < 0 || recommendedPct > 100) {
                alert('Percentage must be between 0 and 100');
                return;
            }

            const urlParams = new URLSearchParams(window.location.search);
            const clientId = urlParams.get('client_id');

            console.log('Client ID:', clientId);

            const formData = new FormData();
            formData.append('action', 'add_asset');
            formData.append('asset_name', assetName);
            formData.append('recommended_pct', recommendedPct);

            if (clientId) {
                formData.append('client_id', clientId);
            }

            try {
                // Use the current page URL for AJAX
                console.log('Sending POST to:', getAjaxUrl());

                const response = await fetch(getAjaxUrl(), {
                    method: 'POST',
                    body: formData
                });


                console.log('Response status:', response.status);

                // Check if response is JSON
                const contentType = response.headers.get('content-type');
                if (contentType && contentType.includes('application/json')) {
                    const result = await response.json();
                    console.log('Parsed result:', result);

                    if (result.success) {
                        // Add to arrays
                        labels.push(result.asset);
                        const tempId = 'tmp_' + Date.now();
                        rowIds.push(tempId);

                        currentData.push(0);
                        recommendedData.push(result.recommended_pct);

                        // Add a new color
                        const baseColors = ['#4f7df3', '#2eb85c', '#f9b115', '#e55353', '#9d5cf2', '#f567a1', '#4fd3d6', '#f99315'];
                        colors.push(baseColors[(labels.length - 1) % baseColors.length]);

                        // Update charts
                        updateCurrentChart();
                        updateRecommendedChart();

                        // Update legends
                        updateLegends();

                        // Close modal
                        modal.style.display = 'none';

                        alert('Category added successfully!');
                    } else {
                        alert(result.message || 'Failed to add category');
                    }
                } else {
                    // Not JSON - probably HTML
                    const text = await response.text();
                    console.error('Server returned HTML instead of JSON');
                    console.error('Response (first 500 chars):', text.substring(0, 500));

                    // Check if we can find error messages in the HTML
                    const errorMatch = text.match(/<b>.*?error<\/b>:(.*?)<br/i) ||
                        text.match(/error:(.*?)</i) ||
                        text.match(/message['"]?\s*:\s*['"](.*?)['"]/i);

                    if (errorMatch && errorMatch[1]) {
                        throw new Error('Server error: ' + errorMatch[1].trim());
                    } else {
                        throw new Error('Server returned HTML instead of JSON. The AJAX handler might not be working.');
                    }
                }
            } catch (error) {
                console.error('Error adding category:', error);
                alert('Error adding category: ' + error.message);
            }
        };

        // Delete Asset
        document.addEventListener('click', async (e) => {
            if (e.target.classList.contains('delete-asset-btn')) {
                if (!confirm('Are you sure you want to delete this category?')) {
                    return;
                }

                const id = e.target.dataset.id;
                const index = rowIds.indexOf(id);

                if (index === -1) return;

                // 🔥 UNSAVED ROW → UI ONLY
                if (id.startsWith('tmp_')) {
                    labels.splice(index, 1);
                    rowIds.splice(index, 1);
                    currentData.splice(index, 1);
                    recommendedData.splice(index, 1);
                    colors.splice(index, 1);

                    updateCurrentChart();
                    updateRecommendedChart();
                    updateLegends();
                    return;
                }

                // SAVED ROW → DELETE FROM DB


                if (index === -1) return;
                const urlParams = new URLSearchParams(window.location.search);
                const clientId = urlParams.get('client_id');
                const formData = new FormData();
                formData.append('action', 'delete_asset');
                formData.append('id', id);

                if (clientId) {
                    formData.append('client_id', clientId);
                }

                try {
                    const response = await fetch(getAjaxUrl(), {
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
                        updateCurrentChart();
                        updateRecommendedChart();

                        // Update legends
                        updateLegends();

                        alert('Category deleted successfully!');
                    } else {
                        alert(result.message || 'Failed to delete category');
                    }
                } catch (error) {
                    console.error('Error deleting category:', error);
                    alert('Error deleting category: ' + error.message);
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

                const urlParams = new URLSearchParams(window.location.search);
                const clientId = urlParams.get('client_id');

                const formData = new FormData();
                formData.append('action', 'update_asset_name');
                formData.append('id', id);
                formData.append('asset_name', newName);

                if (clientId) {
                    formData.append('client_id', clientId);
                }

                try {
                    const response = await fetch(getAjaxUrl(), {
                        method: 'POST',
                        body: formData
                    });

                    const result = await response.json();

                    if (result.success) {
                        // Update the label in the array
                        labels[index] = newName;

                        // Update both charts
                        updateCurrentChart();
                        updateRecommendedChart();

                        // Update the current legend
                        const currentLegendItems = document.querySelectorAll('#currentLegend .legend-item');
                        if (currentLegendItems[index]) {
                            currentLegendItems[index].innerHTML = `
                        <span class="legend-color" style="background:${colors[index]}"></span>
                        ${newName} ${currentData[index].toFixed(2)}%
                    `;
                        }

                        alert('Category name updated successfully!');
                    } else {
                        alert(result.message || 'Failed to update category name');
                        e.target.value = labels[index];
                    }
                } catch (error) {
                    console.error('Error updating category name:', error);
                    alert('Error updating category name: ' + error.message);
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

            // Get interpretation text
            const newInterpretation = interpretationEdit.value.trim();

            const urlParams = new URLSearchParams(window.location.search);
            const clientId = urlParams.get('client_id');

            // Save to database
            const formData = new FormData();
            const newAssets = [];

            rowIds.forEach((id, i) => {
                if (id.startsWith('tmp_')) {
                    newAssets.push({
                        asset: labels[i],
                        recommended: recommendedData[i]
                    });
                }
            });
            formData.append('new_assets', JSON.stringify(newAssets));


            formData.append('action', 'save_recommended');
            formData.append('data', JSON.stringify(payload));
            formData.append('interpretation', newInterpretation);

            if (clientId) {
                formData.append('client_id', clientId);
            }

            try {
                const response = await fetch(getAjaxUrl(), {
                    method: 'POST',
                    body: formData
                });

                const result = await response.json();

                if (result.success) {
                    // Update interpretation display
                    interpretationText.textContent = newInterpretation;

                    // Exit edit mode
                    editMode = false;
                    const inputs = document.querySelectorAll('.rec-input, .asset-name-input');
                    const deleteBtns = document.querySelectorAll('.delete-asset-btn');

                    inputs.forEach(i => i.disabled = true);
                    deleteBtns.forEach(btn => btn.style.display = 'none');

                    interpretationText.style.display = 'block';
                    interpretationEdit.style.display = 'none';
                    editBtn.style.display = 'inline-block';
                    addAssetBtn.style.display = 'inline-block';
                    saveBtn.style.display = 'none';

                    alert('Changes saved successfully!');
                } else {
                    alert(result.message || 'Failed to save changes');
                }
            } catch (error) {
                console.error('Error saving changes:', error);
                alert('Error saving changes: ' + error.message);
            }
        };

        // Function to update legends
        function updateLegends() {
            // Update current legend
            const currentLegend = document.getElementById('currentLegend');
            currentLegend.innerHTML = '';

            labels.forEach((asset, i) => {
                const row = document.createElement('div');
                row.className = 'legend-item';
                row.innerHTML = `
            <span class="legend-color" style="background:${colors[i]}"></span>
            ${asset} ${currentData[i].toFixed(2)}%
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
            <button class="delete-asset-btn" data-id="${rowIds[i]}" title="Delete category" 
                    style="${editMode ? 'display: block' : 'display: none'}">×</button>
        `;
                recommendedLegend.appendChild(row);
            });

            // Re-attach event listeners for the new inputs
            attachEventListeners();
        }

        // Attach event listeners to inputs
        function attachEventListeners() {
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

            document.querySelectorAll('.asset-name-input').forEach(input => {
                input.addEventListener('change', async (e) => {
                    if (editMode) {
                        const id = parseInt(e.target.dataset.id);
                        const newName = e.target.value.trim();
                        const index = rowIds.indexOf(id);

                        if (index === -1 || !newName) return;

                        const urlParams = new URLSearchParams(window.location.search);
                        const clientId = urlParams.get('client_id');

                        const formData = new FormData();
                        formData.append('action', 'update_asset_name');
                        formData.append('id', id);
                        formData.append('asset_name', newName);

                        if (clientId) {
                            formData.append('client_id', clientId);
                        }

                        try {
                            const response = await fetch(getAjaxUrl(), {
                                method: 'POST',
                                body: formData
                            });

                            const result = await response.json();

                            if (result.success) {
                                labels[index] = newName;
                                updateCurrentChart();
                                updateRecommendedChart();

                                const currentLegendItems = document.querySelectorAll('#currentLegend .legend-item');
                                if (currentLegendItems[index]) {
                                    currentLegendItems[index].innerHTML = `
                                <span class="legend-color" style="background:${colors[index]}"></span>
                                ${newName} ${currentData[index].toFixed(2)}%
                            `;
                                }
                            } else {
                                e.target.value = labels[index];
                                alert(result.message || 'Failed to update category name');
                            }
                        } catch (error) {
                            e.target.value = labels[index];
                            console.error('Error updating category name:', error);
                            alert('Error updating category name: ' + error.message);
                        }
                    }
                });
            });
        }

        // Functions for the parent window (index.php) to call
        window.enableEdit = function() {
            editBtn.click();
        };

        window.saveSlide = function() {
            saveBtn.click();
        };

        // Initialize legends and attach event listeners
        updateLegends();
    </script>
</body>

</html>