<?php
// Section 3: Appropriate Asset Allocation
// Expects: $allocations, $clientId, $isLocked

require_once __DIR__ . '/db_config.php';
require_once __DIR__ . '/auth.php';


$pdo = getPdo();


$allocationComment = '';
$commentedBy = '';
$updatedAt = '';

$stmtComment = $pdo->prepare("
    SELECT commented_by, comment, updated_at 
    FROM client_allocation_comments 
    WHERE client_id = ?
");
$stmtComment->execute([$clientId]);
$rowComment = $stmtComment->fetch(PDO::FETCH_ASSOC);

if ($rowComment) {
    $allocationComment = $rowComment['comment'];
    $commentedBy = $rowComment['commented_by'];
    $updatedAt = $rowComment['updated_at'];
}

if (!isset($isLocked)) {

    $stmt = $pdo->prepare("SELECT report_state, review_not_ok FROM clients WHERE id = ?");
    $stmt->execute([$clientId]);
    $clientLock = $stmt->fetch(PDO::FETCH_ASSOC);
    $reportState = $clientLock['report_state'] ?? 'draft';
    $reviewNotOk = (int)($clientLock['review_not_ok'] ?? 0);
    $isLocked = (($reportState === 'reviewed' && $reviewNotOk === 0) || $reportState === 'sent');
}

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

// --- Handle AJAX save for allocation comment ---
if (
    $_SERVER['REQUEST_METHOD'] === 'POST'
    && isset($_POST['ajax_allocation_comment'])
    && $_POST['ajax_allocation_comment'] === '1'
) {

    header('Content-Type: application/json; charset=UTF-8');

    try {
        $pdo = getPdo();

        $clientId = (int)($_POST['client_id'] ?? 0);
        $comment  = trim($_POST['allocation_comment'] ?? '');

        if ($clientId <= 0) {
            throw new Exception("Invalid client ID");
        }
                // Prevent saving if report is locked
        $stmtLock = $pdo->prepare("SELECT report_state, review_not_ok FROM clients WHERE id = ?");
        $stmtLock->execute([$clientId]);
        $lockRow = $stmtLock->fetch(PDO::FETCH_ASSOC);

        $reportState = $lockRow['report_state'] ?? 'draft';
        $reviewNotOk = (int)($lockRow['review_not_ok'] ?? 0);

        $isLockedNow = (($reportState === 'reviewed' && $reviewNotOk === 0) || $reportState === 'sent');

        if ($isLockedNow) {
            echo json_encode(['success' => false]);
            exit;
        }


        // Fetch actual client name
        $stmtClient = $pdo->prepare("SELECT name FROM clients WHERE id = ?");
        $stmtClient->execute([$clientId]);
        $clientName = $stmtClient->fetchColumn() ?: 'Unknown Client';

        // Logged-in user
        $currentUser = getCurrentUser();
        $commentedBy = $currentUser['name']
            ?? $currentUser['username']
            ?? 'User';

        $stmt = $pdo->prepare("
            INSERT INTO client_allocation_comments 
            (client_id, client_name, commented_by, comment)
            VALUES (:client_id, :client_name, :commented_by, :comment)
            ON DUPLICATE KEY UPDATE
                client_name = VALUES(client_name),
                commented_by = VALUES(commented_by),
                comment = VALUES(comment)
        ");

        $stmt->execute([
            ':client_id'   => $clientId,
            ':client_name' => $clientName,
            ':commented_by' => $commentedBy,
            ':comment'     => $comment
        ]);

        echo json_encode(['success' => true]);
    } catch (Exception $e) {
        echo json_encode(['success' => false]);
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
<h3>
    3. Appropriate Asset Allocation
    <?php if (isset($isLocked) && $isLocked): ?>
        <span title="Locked" style="margin-left:8px;color:#888;vertical-align:middle;">🔒</span>
    <?php endif; ?>
</h3>
<div class="asset-allocation-main-container">

    <!-- LEFT SIDE – PIE CHART -->
    <div class="piechart-container">
        <div style="font-weight:600; font-size:16px; margin-bottom:20px; text-align:center;">
            Current Asset Allocation
        </div>

        <div class="piechart-legend-container">
            <canvas id="allocationChart" class="piechart-canvas"
                style="max-height: 240px; max-width: 240px;"></canvas>

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

    <!-- RIGHT SIDE – COMMENT BOX -->
    <div class="allocation-comment-wrapper">

        <div class="allocation-comment-heading">
            Advisory Recommendation
        </div>

        <div class="allocation-comment-box">
            <textarea
                class="allocation-comment-textarea"
                maxlength="500"
                <?= $isLocked ? 'readonly' : '' ?>
                placeholder="Write recommendation based on the asset allocation..."><?= htmlspecialchars($allocationComment) ?></textarea>

            <span class="allocation-char-count">0 / 500</span>
            <?php if (!empty($commentedBy)): ?>
                <div class="allocation-comment-user">
                    Last updated by <?= htmlspecialchars($commentedBy) ?>
                    on <?= date('d M Y, h:i A', strtotime($updatedAt)) ?>
                </div>
            <?php endif; ?>

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
    h3 {
        margin-top: 40px;
        margin-bottom: 40px;
    }

    .asset-allocation-main-container {
        width: 100%;
        max-width: 1200px;
        margin: 0 auto 30px auto;
        display: flex;
        flex-direction: row;
        gap: 40px;
        align-items: center;
        justify-content: center;
    }

    /* ===== Allocation Comment Box ===== */
    .allocation-comment-user {
        font-size: 12px;
        color: #888;
        margin-top: 6px;
    }

    .allocation-comment-box {
        background: #ffffff;
        border-radius: 14px;
        padding: 20px;
        position: relative;
        box-shadow: 0 4px 12px rgba(2, 136, 209, 0.08);
        border: 2px solid #0288D1;
        min-width: 320px;
        max-width: 520px;
        width: 100%;
    }

    /* LEFT arrow pointing to pie chart */
    .allocation-comment-box::before {
        content: "";
        position: absolute;
        left: -12px;
        top: 50px;
        width: 20px;
        height: 20px;
        background: #ffffff;
        border-left: 2px solid #0288D1;
        border-top: 2px solid #0288D1;
        transform: rotate(-45deg);
    }

    .allocation-comment-wrapper {
        min-width: 320px;
        max-width: 520px;
        width: 100%;
    }

    /* ===== Heading Above Bubble ===== */
    .allocation-comment-heading {
        font-size: 18px;
        font-weight: 600;
        color: #0288D1;
        margin-bottom: 10px;
        margin-left: 4px;
    }


    .comment-header h4 {
        margin: 0 0 12px 0;
        color: #0288D1;
        font-weight: 600;
        font-size: 15px;
    }

    .allocation-comment-wrapper {
        min-width: 320px;
        max-width: 520px;
        width: 100%;
    }



    .allocation-comment-textarea {
        width: 100%;
        min-height: 140px;
        border: none;
        /* REMOVE inner border */
        outline: none;
        resize: none;
        font-size: 16px;
        font-family: inherit;
        background: transparent;
        line-height: 1.6;
    }

    .allocation-comment-textarea:focus {
        outline: none;
    }

    /* Character counter */
    .allocation-char-count {
        display: block;
        text-align: right;
        font-size: 12px;
        color: #6b7280;
        margin-top: 8px;
    }

    .comment-footer {
        display: flex;
        justify-content: space-between;
        align-items: center;
        margin-top: 10px;
    }

    .allocation-comment-btn {
        background: #0288D1;
        color: #ffffff;
        border: none;
        padding: 6px 14px;
        border-radius: 4px;
        font-size: 13px;
        cursor: pointer;
    }

    .allocation-comment-btn:hover {
        background: #0277bd;
    }

    .report-section-container {
        background: #fff;
        border-radius: 8px;
        box-shadow: 0 2px 8px rgba(0, 0, 0, 0.04);
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

    #allocationChart,
    #recommendedAllocationChart {
        max-height: 260px;
        max-width: 100%;
        margin: 0 auto;
        display: block;
        background: #fff;
        border-radius: 8px;
        box-shadow: 0 1px 4px rgba(0, 0, 0, 0.03);
    }

    .piechart-container {
        background: #fff;
        border-radius: 12px;
        box-shadow: 0 2px 8px rgba(0, 0, 0, 0.06);
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
        margin-top: 10px;
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
        values: <?= json_encode(array_map(function ($a) use ($currentMap) {
                    return $currentMap[$a] ?? 0;
                }, $assetOrder)) ?>,
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
                legend: {
                    display: false
                },
                tooltip: {
                    enabled: true
                }
            }
        }
    });

    document.addEventListener('DOMContentLoaded', function() {

        const textarea = document.querySelector('.allocation-comment-textarea');
        const counter = document.querySelector('.allocation-char-count');

        if (!textarea) return;
        const isLocked = <?= $isLocked ? 'true' : 'false' ?>;

        counter.textContent = textarea.value.length + " / 500";

        textarea.addEventListener('input', function() {

            counter.textContent = this.value.length + " / 500";
            if (isLocked) return;

            // Auto expand
            this.style.height = "auto";
            this.style.height = this.scrollHeight + "px";

            clearTimeout(this.saveTimeout);
            this.saveTimeout = setTimeout(() => {

                fetch('table3.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded'
                    },
                    body: new URLSearchParams({
                        ajax_allocation_comment: '1',
                        client_id: <?= (int)$clientId ?>,
                        allocation_comment: this.value
                    })
                });

            }, 700);

        });

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
                    legend: {
                        display: false
                    },
                    tooltip: {
                        enabled: true
                    }
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
                setTimeout(() => {
                    status.style.display = 'none';
                }, 1200);
            })
            .catch(() => {
                status.textContent = '❌ Error';
                status.style.color = '#dc3545';
                setTimeout(() => {
                    status.style.display = 'none';
                }, 1200);
            });
    }
</script>