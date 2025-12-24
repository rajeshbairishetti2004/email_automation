<?php
// recommendations.php
// Logic to cross-reference client schemes with master strategy and provide sticky alerts

if (!isset($pdo) || !isset($clientId)) {
    return; // Safety check for inclusion
}

// 1. Fetch the master strategy list
$masterStmt = $pdo->query("SELECT scheme_name, category FROM master_schemes");
$masterSchemes = $masterStmt->fetchAll(PDO::FETCH_ASSOC);

// 2. Identify matches and grab their specific client_scheme IDs for linking
$strategyMatches = [];
if (isset($schemes) && is_array($schemes)) {
    foreach ($schemes as $s) {
        $clientSchemeName = trim($s['scheme_name']);
        foreach ($masterSchemes as $ms) {
            if (strcasecmp($clientSchemeName, trim($ms['scheme_name'])) === 0) {
                $strategyMatches[] = [
                    'id' => $s['id'], // ID from client_schemes table for the Accept trigger
                    'name' => $clientSchemeName,
                    'category' => $ms['category']
                ];
            }
        }
    }
}
?>

<style>
/* Sticky Widget Container */
.strategy-sticky-container {
    position: fixed;
    bottom: 24px;
    left: 24px; /* Placed on the left side to avoid toast/other notifications */
    width: 320px;
    z-index: 9999;
    display: flex;
    flex-direction: column;
    gap: 12px;
}

/* Modal-like Card Styling */
.strategy-popup-card {
    background: #ffffff;
    border-radius: 12px;
    padding: 16px;
    box-shadow: 0 10px 25px rgba(0, 0, 0, 0.15);
    border: 1px solid #e2e8f0;
    border-left: 6px solid #cbd5e1;
    animation: slideInLeft 0.4s cubic-bezier(0.175, 0.885, 0.32, 1.275);
    transition: all 0.3s ease;
}

@keyframes slideInLeft {
    from { opacity: 0; transform: translateX(-50px); }
    to { opacity: 1; transform: translateX(0); }
}

/* Category Tints */
.popup-recommended { border-left-color: #22c55e; background: #f0fdf4; }
.popup-observation { border-left-color: #f59e0b; background: #fffbeb; }
.popup-drop { border-left-color: #ef4444; background: #fef2f2; }

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

/* Accept/Reject Buttons */
.popup-actions {
    display: flex;
    gap: 8px;
}

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
.btn-action:hover { opacity: 0.9; transform: scale(1.02); }

/* Table Highlighting */
<?php foreach ($strategyMatches as $match): ?>
tr[data-scheme-name="<?php echo htmlspecialchars($match['name']); ?>"] {
    background-color: <?php 
        echo ($match['category'] === 'drop') ? 'rgba(239, 68, 68, 0.05)' : 
             (($match['category'] === 'recommended') ? 'rgba(34, 197, 94, 0.05)' : 'rgba(245, 158, 11, 0.05)'); 
    ?> !important;
}
<?php endforeach; ?>
</style>

<div class="strategy-sticky-container">
    <?php foreach ($strategyMatches as $match): ?>
        <?php 
            $class = ''; $title = ''; $targetVal = ''; $icon = '';
            switch($match['category']) {
                case 'recommended':
                    $class = 'popup-recommended'; $title = 'Suggested Action: Recommended'; 
                    $targetVal = 'Recommended'; $icon = '⭐'; break;
                case 'observation':
                    $class = 'popup-observation'; $title = 'Suggested Action: Under Observation'; 
                    $targetVal = 'Under Observation'; $icon = '👁️'; break;
                case 'drop':
                    $class = 'popup-drop'; $title = 'Suggested Action: Drop'; 
                    $targetVal = 'Drop'; $icon = '🚫'; break;
            }
        ?>
        <div class="strategy-popup-card <?php echo $class; ?>" id="strategy-popup-<?php echo $match['id']; ?>">
            <span class="popup-title"><?php echo $icon; ?> <?php echo $title; ?></span>
            <span class="popup-name"><?php echo htmlspecialchars($match['name']); ?></span>
            <div class="popup-actions">
                <button type="button" class="btn-action btn-accept" 
                        onclick="applyStrategy(<?php echo $match['id']; ?>, '<?php echo $targetVal; ?>')">Accept</button>
                <button type="button" class="btn-action btn-reject" 
                        onclick="dismissStrategy(<?php echo $match['id']; ?>)">Reject</button>
            </div>
        </div>
    <?php endforeach; ?>
</div>

<script>
/**
 * Finds the dropdown in the main table and updates it
 */
function applyStrategy(schemeId, value) {
    // 1. Locate the dropdown in the client schemes table using data-scheme-id
    const dropdown = document.querySelector(`select[data-scheme-id="${schemeId}"]`);
    
    if (dropdown) {
        dropdown.value = value;
        
        // 2. Trigger the existing 'change' event listener so it auto-saves via AJAX
        const event = new Event('change', { bubbles: true });
        dropdown.dispatchEvent(event);
        
        // 3. Visual feedback in the table
        dropdown.parentElement.style.backgroundColor = "#dcfce7";
        setTimeout(() => { dropdown.parentElement.style.backgroundColor = "transparent"; }, 1500);

        // 4. Remove the sticky card
        dismissStrategy(schemeId);
        
        if(typeof showToast === 'function') {
            showToast(`Applied "${value}" to scheme.`);
        }
    } else {
        alert("Could not find the scheme in the table. Please refresh.");
    }
}

/**
 * Removes the card from the UI
 */
function dismissStrategy(id) {
    const card = document.getElementById('strategy-popup-' + id);
    if (card) {
        card.style.opacity = '0';
        card.style.transform = 'translateX(-20px)';
        setTimeout(() => { card.remove(); }, 300);
    }
}
</script>