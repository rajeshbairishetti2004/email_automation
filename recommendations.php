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

// 1. Fetch the client's is_usa flag to determine their region
$clientRegionStmt = $pdo->prepare("SELECT is_usa FROM clients WHERE id = ? LIMIT 1");
$clientRegionStmt->execute([$clientId]);
$clientRegionRow  = $clientRegionStmt->fetch(PDO::FETCH_ASSOC);
$clientIsUsa      = isset($clientRegionRow['is_usa']) ? (int)$clientRegionRow['is_usa'] : 0;

// 2. Fetch master schemes filtered by the CLIENT'S region (is_usa)
$masterStmt = $pdo->prepare("SELECT scheme_name, category FROM master_schemes WHERE is_usa = ?");
$masterStmt->execute([$clientIsUsa]);
$masterSchemes = $masterStmt->fetchAll(PDO::FETCH_ASSOC);

// 3. If $schemes is not set or empty, re-fetch directly from DB as fallback
if (!isset($schemes) || !is_array($schemes) || empty($schemes)) {
    $schemesFallbackStmt = $pdo->prepare("
        SELECT id, scheme_name
        FROM client_schemes
        WHERE client_id = ? AND scheme_name != ''
    ");
    $schemesFallbackStmt->execute([$clientId]);
    $schemes = $schemesFallbackStmt->fetchAll(PDO::FETCH_ASSOC);
}

// 4. Match function:
//    Priority 1 — Exact match (case-insensitive)
//    Priority 2 — Client scheme CONTAINS the master name (e.g. "Kotak Multi Asset Allocation Fund Reg (G)" contains "Kotak Multi Asset")
//    Priority 3 — Master name CONTAINS the client scheme name
function matchSchemeName(string $clientName, string $masterName): bool
{
    $c = strtolower(trim($clientName));
    $m = strtolower(trim($masterName));

    if ($c === $m)                  return true; // exact
    if (strpos($c, $m) !== false)   return true; // client contains master
    if (strpos($m, $c) !== false)   return true; // master contains client
    return false;
}

// 5. Identify matches — deduplicate by BOTH scheme ID and scheme name
$strategyMatches     = [];
$alreadyMatched      = []; // prevent duplicate cards for same client scheme ID
$alreadyMatchedNames = []; // prevent duplicate cards for same scheme name

foreach ($schemes as $s) {
    $clientSchemeName = trim($s['scheme_name']);
    if (empty($clientSchemeName)) continue;
    if (isset($alreadyMatched[$s['id']])) continue;

    // Skip if we've already added a card for this scheme name (case-insensitive)
    $nameKey = strtolower($clientSchemeName);
    if (isset($alreadyMatchedNames[$nameKey])) continue;

    foreach ($masterSchemes as $ms) {
        if (matchSchemeName($clientSchemeName, $ms['scheme_name'])) {
            $strategyMatches[]             = [
                'id'       => $s['id'],
                'name'     => $clientSchemeName,
                'category' => $ms['category']
            ];
            $alreadyMatched[$s['id']]      = true;
            $alreadyMatchedNames[$nameKey] = true;
            break; // one master match per client scheme is enough
        }
    }
}

$hasMatches = !empty($strategyMatches);
$matchCount = count($strategyMatches);
?>
<script>
window.CLIENT_ID = <?= (int)$clientId ?>;
</script>

<style>
/* Toggle Button at Bottom Right */
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
    position: fixed; /* ensure fixed even if parent is relative */
}
.strategy-toggle-trigger:hover {
    transform: scale(1.1);
    background: #0277bd;
}
.strategy-toggle-trigger.no-matches {
    background: #94a3b8;
    cursor: default;
}
.strategy-toggle-trigger.no-matches:hover {
    transform: none;
    background: #94a3b8;
}

/* Badge on bulb */
.strategy-badge {
    position: absolute;
    top: -4px;
    right: -4px;
    background: #ef4444;
    color: white;
    font-size: 10px;
    font-weight: 700;
    width: 18px;
    height: 18px;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    border: 2px solid white;
    line-height: 1;
}

/* Popup Container */
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

/* Header */
.strategy-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
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

/* Region badge */
.strategy-region-badge {
    display: inline-flex;
    align-items: center;
    gap: 5px;
    padding: 4px 10px;
    border-radius: 20px;
    font-size: 10px;
    font-weight: 800;
    text-transform: uppercase;
    letter-spacing: 0.4px;
    white-space: nowrap;
}
.strategy-region-badge.usa {
    background: #dbeafe;
    color: #1d4ed8;
    border: 1px solid #93c5fd;
}
.strategy-region-badge.india {
    background: #fef3c7;
    color: #92400e;
    border: 1px solid #fcd34d;
}

/* Card */
.strategy-popup-card {
    background: #ffffff;
    border-radius: 12px;
    padding: 16px;
    box-shadow: 0 10px 25px rgba(0,0,0,0.15);
    border: 1px solid #e2e8f0;
    border-left: 6px solid #cbd5e1;
    animation: slideInRight 0.4s cubic-bezier(0.175, 0.885, 0.32, 1.275);
    transition: all 0.3s ease;
}

@keyframes slideInRight {
    from { opacity: 0; transform: translateX(50px); }
    to   { opacity: 1; transform: translateX(0); }
}

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
.btn-action    { flex: 1; padding: 8px 10px; border-radius: 6px; font-size: 11px; font-weight: 700; cursor: pointer; border: none; transition: all 0.2s; }
.btn-accept    { background: #16a34a; color: white; }
.btn-reject    { background: #cbd5e1; color: #475569; }

/* Empty state */
.strategy-empty-state {
    background: #f8fafc;
    border-radius: 12px;
    padding: 20px 16px;
    text-align: center;
    color: #64748b;
    font-size: 13px;
    border: 1px dashed #cbd5e1;
}

/* Table row highlighting — region-aware */
<?php foreach ($strategyMatches as $match): ?>
tr[data-scheme-name="<?php echo htmlspecialchars($match['name']); ?>"] {
    background-color: <?php
        echo ($match['category'] === 'drop')        ? 'rgba(239, 68, 68, 0.05)'  :
             (($match['category'] === 'recommended') ? 'rgba(34, 197, 94, 0.05)'  :
                                                       'rgba(245, 158, 11, 0.05)');
    ?> !important;
}
<?php endforeach; ?>
</style>

<!-- Bulb button — always rendered -->
<button type="button"
        class="strategy-toggle-trigger <?php echo $hasMatches ? '' : 'no-matches'; ?>"
        id="strategyToggleBtn"
        onclick="toggleRecommendations()"
        title="<?php echo $hasMatches
            ? $matchCount . ' Strategy Alert(s) — ' . ($clientIsUsa ? 'USA/Canada' : 'India & Others')
            : 'No strategy alerts for this client'; ?>"
        style="position:fixed; bottom:24px; right:24px;">
    💡
    <?php if ($hasMatches): ?>
        <span class="strategy-badge"><?php echo $matchCount; ?></span>
    <?php endif; ?>
</button>

<!-- Popup container — always rendered -->
<div class="strategy-sticky-container" id="strategyMainContainer">
    <div class="strategy-header">
        <span class="strategy-region-badge <?php echo $clientIsUsa ? 'usa' : 'india'; ?>">
            <?php echo $clientIsUsa ? ' USA / Canada' : 'India &amp; Others'; ?>
        </span>
        <button type="button" class="btn-close-all" onclick="toggleRecommendations()">✕ Close</button>
    </div>

    <?php if ($hasMatches): ?>

        <?php foreach ($strategyMatches as $match):
            $class = ''; $title = ''; $targetVal = ''; $icon = '';
            switch ($match['category']) {
                case 'recommended':
                    $class = 'popup-recommended'; $title = 'Suggested Action: Recommended';
                    $targetVal = 'Continue';          $icon  = '⭐'; break;
                case 'observation':
                    $class = 'popup-observation';  $title = 'Suggested Action: Under Observation';
                    $targetVal = 'Under Observation'; $icon  = '👁️'; break;
                case 'drop':
                    $class = 'popup-drop';         $title = 'Suggested Action: Drop';
                    $targetVal = 'Drop';              $icon  = '🚫'; break;
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

    <?php else: ?>
        <div class="strategy-empty-state">
            <div style="font-size:28px; margin-bottom:8px;">💡</div>
            <strong style="display:block; margin-bottom:4px; color:#334155;">No Strategy Alerts</strong>
            None of this client's schemes match the strategy board for
            <strong><?php echo $clientIsUsa ? 'USA / Canada' : 'India &amp; Others'; ?></strong>.
        </div>
    <?php endif; ?>
</div>

<script>

document.addEventListener('change', function(e) {
    if (e.target.classList.contains('action-dropdown')) {
        autoSaveSchemeRow(e.target.closest('tr'));
    }
});

function autoSaveSchemeRow(row) {
    if (!row || !window.CLIENT_ID) return;

    const idInput = row.querySelector('.scheme-id');

    const payload = {
        action: 'save_scheme_table',
        client_id: window.CLIENT_ID,
        rows: [{
            id:                   idInput ? idInput.value : 0,
            scheme_name:          row.querySelector('[data-field="scheme_name"]')?.value        || '',
            sip_swp:              row.querySelector('[data-field="sip_swp"]')?.value            || '',
            current_value:        row.querySelector('[data-field="current_value"]')?.value      || '',
            action_step:          row.querySelector('.action-dropdown')?.value                  || '',
            recommended_scheme:   row.querySelector('[data-field="recommended_scheme"]')?.value || '',
            recommended_amount:   row.querySelector('[data-field="recommended_amount"]')?.value || ''
        }]
    };

    fetch('parsers.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(payload)
    })
    .then(res => res.json())
    .then(resp => { if (!resp.success) console.error('Auto-save failed', resp); })
    .catch(err  => console.error('Auto-save error', err));
}

// ── Toggle panel ──────────────────────────────────────────────────────────────
function toggleRecommendations() {
    const container = document.getElementById('strategyMainContainer');
    const trigger   = document.getElementById('strategyToggleBtn');
    if (!container || !trigger) return;

    const isOpen     = container.style.display === 'flex';
    const hasMatches = <?php echo $hasMatches ? 'true' : 'false'; ?>;
    const count      = <?php echo $matchCount; ?>;

    if (isOpen) {
        container.style.display  = 'none';
        trigger.style.background = hasMatches ? '#0288D1' : '#94a3b8';
        trigger.innerHTML        = '💡' + (hasMatches
            ? `<span class="strategy-badge">${count}</span>`
            : '');
    } else {
        container.style.display  = 'flex';
        trigger.style.background = '#475569';
        trigger.innerHTML        = '✕';
    }
}

// ── Apply strategy ────────────────────────────────────────────────────────────
function applyStrategy(schemeId, value) {
    const row = Array.from(document.querySelectorAll('input.scheme-id'))
        .find(el => el.value == schemeId)
        ?.closest('tr');

    if (!row) return;

    const actionDropdown         = row.querySelector('.action-dropdown');
    const presentSchemeInput     = row.querySelector('[data-field="scheme_name"]');
    const recommendedSchemeInput = row.querySelector('[data-field="recommended_scheme"]');

    if (!actionDropdown) return;

    actionDropdown.value = value;

    if (value !== 'Continue' && presentSchemeInput && recommendedSchemeInput) {
        recommendedSchemeInput.value = presentSchemeInput.value;
    }

    autoSaveSchemeRow(row);

    actionDropdown.style.backgroundColor = '#dcfce7';
    setTimeout(() => { actionDropdown.style.backgroundColor = 'transparent'; }, 1200);

    dismissStrategy(schemeId, null);

    if (typeof showToast === 'function') showToast(`"${value}" saved successfully`);
}

// ── Dismiss a card ────────────────────────────────────────────────────────────
function dismissStrategy(id, schemeName) {
    const card = document.getElementById('strategy-popup-' + id);
    if (card) {
        card.style.opacity   = '0';
        card.style.transform = 'translateX(20px)';
        setTimeout(() => {
            card.remove();
            const remaining = document.querySelectorAll('.strategy-popup-card');
            if (remaining.length === 0) {
                const container = document.getElementById('strategyMainContainer');
                const trigger   = document.getElementById('strategyToggleBtn');
                if (container) container.style.display = 'none';
                if (trigger) {
                    trigger.style.background = '#94a3b8';
                    trigger.innerHTML        = '💡';
                    trigger.classList.add('no-matches');
                    trigger.onclick          = null;
                    trigger.title            = 'All strategy alerts handled';
                }
            }
        }, 300);
    }

    if (schemeName) {
        const row = document.querySelector(`tr[data-scheme-name="${CSS.escape(schemeName)}"]`);
        if (row) row.style.setProperty('background-color', 'transparent', 'important');
    }
}
</script>