<?php
// recommendations.php
// Logic to cross-reference client schemes with master strategy and provide sticky alerts

if (!isset($pdo) || !isset($clientId)) {
    return; // Safety check for inclusion
}

// Stop execution if report is Locked (Reviewed or Sent)
if (isset($isLocked) && $isLocked) {
    return;
}

// 1. Fetch the master strategy list
$masterStmt = $pdo->query("SELECT scheme_name, category FROM master_schemes");
$masterSchemes = $masterStmt->fetchAll(PDO::FETCH_ASSOC);

// 2. Identify matches
$strategyMatches = [];
if (isset($schemes) && is_array($schemes)) {
    foreach ($schemes as $s) {
        $clientSchemeName = trim($s['scheme_name']);
        foreach ($masterSchemes as $ms) {
            if (strcasecmp($clientSchemeName, trim($ms['scheme_name'])) === 0) {
                $strategyMatches[] = [
                    'id'       => $s['id'],
                    'name'     => $clientSchemeName,
                    'category' => $ms['category']
                ];
            }
        }
    }
}
?>
<script>
window.CLIENT_ID = <?= (int)$clientId ?>;
</script>

<style>
/* 1. Toggle Button at Bottom Right */
.strategy-toggle-trigger {
    position: fixed;
    bottom: 24px;
    right: 24px;
    width: 56px;
    height: 56px;
    background: #0288D1;
    color: white;
    border: none;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    box-shadow: 0 4px 12px rgba(0,0,0,0.2);
    cursor: pointer;
    z-index: 10000;
    font-size: 24px;
    transition: all 0.3s ease;
}
.strategy-toggle-trigger:hover {
    transform: scale(1.1);
    background: #0277bd;
}

/* 2. Container (Hidden by default, aligned to right) */
.strategy-sticky-container {
    position: fixed;
    bottom: 90px;
    right: 24px;
    width: 320px;
    z-index: 9999;
    display: none;
    flex-direction: column;
    gap: 12px;
    max-height: 70vh;
    overflow-y: auto;
    padding: 10px;
}

/* 3. Header for Close All */
.strategy-header {
    display: flex;
    justify-content: flex-end;
    margin-bottom: 5px;
}
.btn-close-all {
    background: #475569;
    color: white;
    border: none;
    padding: 6px 14px;
    border-radius: 20px;
    font-size: 11px;
    cursor: pointer;
    font-weight: bold;
    box-shadow: 0 2px 4px rgba(0,0,0,0.1);
}

/* Card Styling */
.strategy-popup-card {
    background: #ffffff;
    border-radius: 12px;
    padding: 16px;
    box-shadow: 0 10px 25px rgba(0, 0, 0, 0.15);
    border: 1px solid #e2e8f0;
    border-left: 6px solid #cbd5e1;
    animation: slideInRight 0.4s cubic-bezier(0.175, 0.885, 0.32, 1.275);
    transition: all 0.3s ease;
}

@keyframes slideInRight {
    from { opacity: 0; transform: translateX(50px); }
    to   { opacity: 1; transform: translateX(0); }
}

/* Category Tints */
.popup-recommended { border-left-color: #22c55e; background: #f0fdf4; }
.popup-observation  { border-left-color: #f59e0b; background: #fffbeb; }
.popup-drop         { border-left-color: #ef4444; background: #fef2f2; }

.popup-title {
    font-size: 10px;
    font-weight: 800;
    text-transform: uppercase;
    color: #64748b;
    margin-bottom: 8px;
    display: block;
}

.popup-name {
    font-size: 13px;
    font-weight: 700;
    color: #0f172a;
    display: block;
    margin-bottom: 12px;
    line-height: 1.3;
}

.popup-actions { display: flex; gap: 8px; }
.btn-action {
    flex: 1;
    padding: 8px 10px;
    border-radius: 6px;
    font-size: 11px;
    font-weight: 700;
    cursor: pointer;
    border: none;
    transition: all 0.2s;
}
.btn-accept { background: #16a34a; color: white; }
.btn-reject { background: #cbd5e1; color: #475569; }

/* Table Highlighting */
<?php foreach ($strategyMatches as $match): ?>
tr[data-scheme-name="<?php echo htmlspecialchars($match['name']); ?>"] {
    background-color: <?php
        echo ($match['category'] === 'drop')
            ? 'rgba(239, 68, 68, 0.05)'
            : (($match['category'] === 'recommended')
                ? 'rgba(34, 197, 94, 0.05)'
                : 'rgba(245, 158, 11, 0.05)');
    ?> !important;
}
<?php endforeach; ?>
</style>

<button type="button" class="strategy-toggle-trigger" id="strategyToggleBtn"
        onclick="toggleRecommendations()" title="Show Recommendations">
    💡
</button>

<div class="strategy-sticky-container" id="strategyMainContainer">
    <div class="strategy-header">
        <button type="button" class="btn-close-all" onclick="toggleRecommendations()">✕ Close All</button>
    </div>

    <?php foreach ($strategyMatches as $match): ?>
        <?php
        $class = ''; $title = ''; $targetVal = ''; $icon = '';
        switch ($match['category']) {
            case 'recommended':
                $class = 'popup-recommended';
                $title = 'Suggested Action: Recommended';
                $targetVal = 'Continue';
                $icon = '⭐';
                break;
            case 'observation':
                $class = 'popup-observation';
                $title = 'Suggested Action: Under Observation';
                $targetVal = 'Under Observation';
                $icon = '👁️';
                break;
            case 'drop':
                $class = 'popup-drop';
                $title = 'Suggested Action: Drop';
                $targetVal = 'Drop';
                $icon = '🚫';
                break;
        }
        ?>
        <div class="strategy-popup-card <?php echo $class; ?>" id="strategy-popup-<?php echo $match['id']; ?>">
            <span class="popup-title"><?php echo $icon; ?> <?php echo $title; ?></span>
            <span class="popup-name"><?php echo htmlspecialchars($match['name']); ?></span>
            <div class="popup-actions">
                <button type="button" class="btn-action btn-accept"
                        onclick="applyStrategy(<?php echo $match['id']; ?>, '<?php echo $targetVal; ?>')">Accept</button>
                <button type="button" class="btn-action btn-reject"
                        onclick="dismissStrategy(<?php echo $match['id']; ?>, '<?php echo addslashes($match['name']); ?>')">Reject</button>
            </div>
        </div>
    <?php endforeach; ?>
</div>

<script>
// -------------------------------------------------------
// FIX: renderRecommendedChart — null guard prevents the
// "Cannot read properties of null (reading 'getContext')"
// error that fires when the canvas element doesn't exist
// or the script runs before the DOM is ready.
// -------------------------------------------------------
function renderRecommendedChart(canvasId, labels, data, colors) {
    // Guard: if no canvasId provided, try the default
    const id = canvasId || 'recommendedChart';
    const canvas = document.getElementById(id);

    if (!canvas) {
        // Canvas simply isn't present on this page — silently skip
        console.warn('renderRecommendedChart: canvas #' + id + ' not found, skipping.');
        return;
    }

    const ctx = canvas.getContext('2d');
    if (!ctx) {
        console.warn('renderRecommendedChart: could not get 2d context for #' + id);
        return;
    }

    // Destroy previous chart instance on this canvas if one exists
    if (canvas._chartInstance) {
        canvas._chartInstance.destroy();
    }

    canvas._chartInstance = new Chart(ctx, {
        type: 'doughnut',
        data: {
            labels: labels || [],
            datasets: [{
                data: data || [],
                backgroundColor: colors || [
                    '#0288D1', '#27ae60', '#f39c12', '#e74c3c',
                    '#9b59b6', '#1abc9c', '#e67e22', '#2ecc71'
                ],
                borderWidth: 2,
                borderColor: '#fff'
            }]
        },
        options: {
            responsive: true,
            plugins: {
                legend: {
                    position: 'bottom',
                    labels: { font: { size: 11 } }
                },
                tooltip: {
                    callbacks: {
                        label: function(context) {
                            const val = context.parsed;
                            const total = context.dataset.data.reduce((a, b) => a + b, 0);
                            const pct = total > 0 ? ((val / total) * 100).toFixed(1) : 0;
                            return context.label + ': ' + pct + '%';
                        }
                    }
                }
            }
        }
    });
}

// -------------------------------------------------------
// FIX: Wrap the initial chart render call in DOMContentLoaded
// so the canvas element is guaranteed to exist before we
// attempt to call getContext() on it.
// -------------------------------------------------------
document.addEventListener('DOMContentLoaded', function() {
    // Only render if a canvas with id="recommendedChart" actually exists
    const chartCanvas = document.getElementById('recommendedChart');
    if (chartCanvas) {
        // Extract data attributes if the canvas carries them
        const labelsAttr = chartCanvas.getAttribute('data-labels');
        const dataAttr   = chartCanvas.getAttribute('data-values');
        const colorsAttr = chartCanvas.getAttribute('data-colors');

        try {
            const labels = labelsAttr ? JSON.parse(labelsAttr) : [];
            const values = dataAttr   ? JSON.parse(dataAttr)   : [];
            const colors = colorsAttr ? JSON.parse(colorsAttr) : undefined;
            if (labels.length && values.length) {
                renderRecommendedChart('recommendedChart', labels, values, colors);
            }
        } catch (e) {
            console.warn('renderRecommendedChart: could not parse data attributes', e);
        }
    }
});

// -------------------------------------------------------
// Auto-save scheme row on dropdown change
// -------------------------------------------------------
document.addEventListener('change', function(e) {
    if (e.target.classList.contains('action-dropdown')) {
        autoSaveSchemeRow(e.target.closest('tr'));
    }
});

function autoSaveSchemeRow(row) {
    if (!row || !window.CLIENT_ID) return;

    const idInput = row.querySelector('.scheme-id');

    const payload = {
        action:    'save_scheme_table',
        client_id: window.CLIENT_ID,
        rows: [{
            id:                 idInput ? idInput.value : 0,
            scheme_name:        row.querySelector('[data-field="scheme_name"]')?.value        || '',
            sip_swp:            row.querySelector('[data-field="sip_swp"]')?.value            || '',
            current_value:      row.querySelector('[data-field="current_value"]')?.value      || '',
            action_step:        row.querySelector('.action-dropdown')?.value                  || '',
            recommended_scheme: row.querySelector('[data-field="recommended_scheme"]')?.value || '',
            recommended_amount: row.querySelector('[data-field="recommended_amount"]')?.value || ''
        }]
    };

    fetch('parsers.php', {
        method:  'POST',
        headers: { 'Content-Type': 'application/json' },
        body:    JSON.stringify(payload)
    })
    .then(res => res.json())
    .then(resp => {
        if (!resp.success) console.error('Auto-save failed', resp);
    })
    .catch(err => console.error('Auto-save error', err));
}

/**
 * Toggles the recommendation panel visibility
 */
function toggleRecommendations() {
    const container = document.getElementById('strategyMainContainer');
    const trigger   = document.getElementById('strategyToggleBtn');

    if (container.style.display === 'flex') {
        container.style.display = 'none';
        trigger.style.background = '#0288D1';
        trigger.innerHTML = '💡';
    } else {
        container.style.display = 'flex';
        trigger.style.background = '#475569';
        trigger.innerHTML = '✕';
    }
}

/**
 * Updates the dropdown in the main table and saves via existing AJAX
 */
function applyStrategy(schemeId, value) {
    const row = Array.from(document.querySelectorAll('input.scheme-id'))
        .find(el => el.value == schemeId)
        ?.closest('tr');

    if (!row) return;

    const actionDropdown        = row.querySelector('.action-dropdown');
    const presentSchemeInput    = row.querySelector('[data-field="scheme_name"]');
    const recommendedSchemeInput = row.querySelector('[data-field="recommended_scheme"]');

    if (!actionDropdown) return;

    // 1. Set Action Step
    actionDropdown.value = value;

    // 2. Auto-fill Recommended Scheme
    if (value !== 'Continue' && presentSchemeInput && recommendedSchemeInput) {
        recommendedSchemeInput.value = presentSchemeInput.value;
    }

    // 3. Auto-save immediately
    autoSaveSchemeRow(row);

    // 4. Visual feedback
    actionDropdown.style.backgroundColor = '#dcfce7';
    setTimeout(() => { actionDropdown.style.backgroundColor = 'transparent'; }, 1200);

    // 5. Remove popup
    dismissStrategy(schemeId, null);

    if (typeof showToast === 'function') {
        showToast('"' + value + '" saved successfully');
    }
}

/**
 * Removes card and checks if panel should auto-close
 */
function dismissStrategy(id, schemeName) {
    const card = document.getElementById('strategy-popup-' + id);
    if (card) {
        card.style.opacity   = '0';
        card.style.transform = 'translateX(20px)';
        setTimeout(() => {
            card.remove();
            const remaining = document.querySelectorAll('.strategy-popup-card');
            if (remaining.length === 0) {
                toggleRecommendations();
                const btn = document.getElementById('strategyToggleBtn');
                if (btn) btn.style.display = 'none';
            }
        }, 300);
    }

    if (schemeName) {
        const row = document.querySelector(`tr[data-scheme-name="${CSS.escape(schemeName)}"]`);
        if (row) row.style.setProperty('background-color', 'transparent', 'important');
    }
}
</script>