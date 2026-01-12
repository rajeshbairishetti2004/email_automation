<?php
// recommended_schemes.php
// Handles both display and AJAX saving for recommended schemes
// Can be included in view_report.php or called directly for AJAX

// ==============================================
// 1. AJAX HANDLING (when called directly)
// ==============================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ajax_action'])) {
    // Handle AJAX requests for recommended schemes
    require_once 'db_config.php';
    require_once 'auth.php';
    
    header('Content-Type: application/json');
    
    try {
        // Check authentication
        requireAuth();
        
        $ajax_action = $_POST['ajax_action'];
        
        if ($ajax_action === 'save_recommended_schemes') {
            $clientId = (int)($_POST['client_id'] ?? 0);
            
            if ($clientId <= 0) {
                throw new Exception('Invalid client ID');
            }
            
            $schemeNames = $_POST['new_scheme_name'] ?? [];
            $schemeAmounts = $_POST['new_scheme_amount'] ?? [];
            
            $pdo = getPdo();
            
            // Begin transaction
            $pdo->beginTransaction();
            
            // 1. Clear old entries for this client
            $delStmt = $pdo->prepare("DELETE FROM client_new_schemes WHERE client_id = ?");
            $delStmt->execute([$clientId]);
            
            // 2. Insert new schemes
            $insStmt = $pdo->prepare("INSERT INTO client_new_schemes (client_id, scheme_name, amount) VALUES (?, ?, ?)");
            
            foreach ($schemeNames as $index => $name) {
                $name = trim($name);
                $amount = trim($schemeAmounts[$index] ?? '');
                
                if ($name !== '') {
                    $insStmt->execute([$clientId, $name, $amount]);
                }
            }
            
            $pdo->commit();
            
            echo json_encode([
                'status' => 'success',
                'message' => 'Schemes saved successfully'
            ]);
            exit;
            
        } else {
            echo json_encode([
                'status' => 'error',
                'message' => 'Unknown action'
            ]);
            exit;
        }
        
    } catch (Exception $e) {
        if (isset($pdo) && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        
        error_log("Save schemes error: " . $e->getMessage());
        
        echo json_encode([
            'status' => 'error',
            'message' => $e->getMessage()
        ]);
        exit;
    }
}

// ==============================================
// 2. DISPLAY SECTION (when included in view_report.php)
// ==============================================
if (!isset($clientId)) {
    throw new Exception('clientId must be set before including recommended_schemes.php');
}

// If newSchemes is not passed, fetch it
if (!isset($newSchemes)) {
    require_once 'db_config.php';
    $pdo = getPdo();
    $stmt = $pdo->prepare("SELECT * FROM client_new_schemes WHERE client_id = ?");
    $stmt->execute([$clientId]);
    $newSchemes = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

if (!isset($isLocked)) {
    require_once __DIR__ . '/db_config.php';
    $pdo = getPdo();
    $stmt = $pdo->prepare("SELECT report_state, review_not_ok FROM clients WHERE id = ?");
    $stmt->execute([$clientId]);
    $clientLock = $stmt->fetch(PDO::FETCH_ASSOC);
    $reportState = $clientLock['report_state'] ?? 'draft';
    $reviewNotOk = (int)($clientLock['review_not_ok'] ?? 0);
    $isLocked = (($reportState === 'reviewed' && $reviewNotOk === 0) || $reportState === 'sent');
}
?>

<style>
/* --- Recommended Schemes Table Styles --- */
.recommended-schemes-table {
    width: 100%;
    border-collapse: collapse;
    margin-bottom: 18px;
    background: #fff;
    border-radius: 8px;
    box-shadow: 0 2px 8px rgba(0,0,0,0.04);
    overflow: hidden;
}
.recommended-schemes-table th, .recommended-schemes-table td {
    border: 1px solid #e0e0e0;
    padding: 8px 10px;
    text-align: center;
    font-size: 14px;
}
.recommended-schemes-table th {
    background: #0288D1;
    font-weight: 600;
}
.recommended-scheme-input {
    width: 100%;
    padding: 6px 8px;
    font-size: 14px;
    border: none;
    background: transparent;
    text-align: center;
    outline: none;
    transition: background 0.2s;
}
.recommended-scheme-input:focus {
    background: #e3f2fd;
}
.recommended-schemes-actions {
    margin: 10px 0;
    display: flex;
    gap: 10px;
}
.wf-btn.btn-add {
    background: #27ae60;
    color: #fff;
    border: none;
    border-radius: 5px;
    font-weight: 600;
    padding: 8px 16px;
    cursor: pointer;
    transition: background 0.2s;
}
.wf-btn.btn-add:hover {
    background: #219150;
}
.wf-btn.btn-delete {
    background: #f39c12;
    color: #fff;
    border: none;
    border-radius: 5px;
    font-weight: 600;
    padding: 8px 16px;
    cursor: pointer;
    transition: background 0.2s;
}
.wf-btn.btn-delete:hover {
    background: #c0392b;
}
</style>

<div class="section-card" style="margin-top: 20px; margin-bottom: 20px; padding: 20px; background: white; border-radius: 8px; box-shadow: 0 2px 4px rgba(0,0,0,0.05);">
    <h3 style="color: #2c3e50; border-bottom: 2px solid #3498db; padding-bottom: 10px; margin-bottom: 20px;">
        Recommended Schemes
        <?php if ($isLocked): ?>
            <span title="Locked" style="margin-left:8px;color:#888;vertical-align:middle;">🔒</span>
        <?php endif; ?>
    </h3>
    <table class="table recommended-schemes-table" id="newSchemesTable" style="width: 100%; margin-bottom: 15px;">
        <thead>
            <tr style="background: #f8f9fa;">
                <th style="padding: 10px;">Scheme Name</th>
                <th style="padding: 10px;">Amount (₹)</th>
                <th style="width: 50px;"></th>
            </tr>
        </thead>
        <tbody id="newSchemesBody">
            <?php if (!empty($newSchemes)): ?>
                <?php foreach ($newSchemes as $ns): ?>
                <tr>
                    <td>
                        <input type="text" 
                               name="new_scheme_name[]" 
                               value="<?php echo htmlspecialchars($ns['scheme_name']); ?>" 
                               class="form-control scheme-input recommended-scheme-input" 
                               placeholder="e.g. HDFC Top 100" 
                               style="width: 100%; padding: 8px; border: 1px solid #ddd; border-radius: 4px;"
                               <?php echo $isLocked ? 'readonly' : ''; ?>>
                    </td>
                    <td>
                        <input type="text" 
                               name="new_scheme_amount[]" 
                               value="<?php echo htmlspecialchars($ns['amount']); ?>" 
                               class="form-control scheme-input recommended-scheme-input" 
                               placeholder="Enter amount" 
                               style="width: 100%; padding: 8px; border: 1px solid #ddd; border-radius: 4px;"
                               <?php echo $isLocked ? 'readonly' : ''; ?>>
                    </td>
                    <td>
                        <button type="button" 
                                onclick="removeRowAndSave(this)" 
                                style="background: #ff4d4d; color: white; border: none; padding: 5px 10px; border-radius: 4px; cursor: pointer;"
                                <?php echo $isLocked ? 'disabled' : ''; ?>>
                            &times;
                        </button>
                    </td>
                </tr>
                <?php endforeach; ?>
            <?php endif; ?>
        </tbody>
    </table>
    <button type="button" 
            onclick="addNewSchemeRow()" 
            style="background: #27ae60; color: white; border: none; padding: 8px 15px; border-radius: 4px; cursor: pointer; font-weight: 500;"
            <?php echo $isLocked ? 'disabled' : ''; ?>>
        + Add Scheme
    </button>
</div>

<script>
// Recommended Schemes JavaScript Functions
const currentClientId = <?php echo isset($client['id']) ? (int)$client['id'] : (isset($clientId) ? (int)$clientId : 0); ?>;
const recommendedSchemesLocked = <?php echo $isLocked ? 'true' : 'false'; ?>;

function addNewSchemeRow() {
    if (recommendedSchemesLocked) return;
    
    const tbody = document.getElementById('newSchemesBody');
    const row = `
        <tr>
            <td>
                <input type="text" 
                       name="new_scheme_name[]" 
                       class="form-control scheme-input recommended-scheme-input" 
                       placeholder="e.g. HDFC Top 100" 
                       oninput="debouncedSave()" 
                       style="width: 100%; padding: 8px; border: 1px solid #ddd; border-radius: 4px;">
            </td>
            <td>
                <input type="text" 
                       name="new_scheme_amount[]" 
                       class="form-control scheme-input recommended-scheme-input" 
                       placeholder="Enter amount" 
                       oninput="debouncedSave()" 
                       style="width: 100%; padding: 8px; border: 1px solid #ddd; border-radius: 4px;">
            </td>
            <td>
                <button type="button" 
                        onclick="removeRowAndSave(this)" 
                        style="background: #ff4d4d; color: white; border: none; padding: 5px 10px; border-radius: 4px; cursor: pointer;">
                    &times;
                </button>
            </td>
        </tr>
    `;
    tbody.insertAdjacentHTML('beforeend', row);
}

function removeRowAndSave(btn) {
    if (recommendedSchemesLocked) return;
    btn.closest('tr').remove();
    saveSchemesNow(); // Save immediately on delete
}

// Debounce: Wait 1 second after typing stops before saving
let saveTimer;
function debouncedSave() {
    if (recommendedSchemesLocked) return;
    
    // Show "Saving..." indicator
    const title = document.querySelector('.section-card h3');
    if(title && !title.innerHTML.includes('Saving')) {
         title.innerHTML = "Recommended Schemes <span style='color:orange; font-size:12px;'>(Saving...)</span>";
    }
    
    clearTimeout(saveTimer);
    saveTimer = setTimeout(saveSchemesNow, 1000);
}

function saveSchemesNow() {
    if (recommendedSchemesLocked) return;

    if (currentClientId <= 0) {
        console.error("Cannot save: Invalid Client ID");
        return;
    }

    // Collect Data
    const names = document.getElementsByName('new_scheme_name[]');
    const amounts = document.getElementsByName('new_scheme_amount[]');
    
    // Create FormData instead of JSON
    const formData = new FormData();
    formData.append('ajax_action', 'save_recommended_schemes');
    formData.append('client_id', currentClientId);
    
    for (let i = 0; i < names.length; i++) {
        formData.append('new_scheme_name[]', names[i].value);
        formData.append('new_scheme_amount[]', amounts[i].value);
    }

    // Send AJAX Request to the same file
    fetch('recommended_schemes.php', {
        method: 'POST',
        body: formData
    })
    .then(response => response.json())
    .then(data => {
        const title = document.querySelector('.section-card h3');
        if(data.status === 'success') {
            title.innerHTML = "Recommended Schemes <span style='color:green; font-size:12px;'>✓ Saved</span>";
            setTimeout(() => { title.innerHTML = "Recommended Schemes"; }, 2000);
        } else {
            title.innerHTML = "Recommended Schemes <span style='color:red; font-size:12px;'>⚠ Error</span>";
            console.error('Server Error:', data.message);
        }
    })
    .catch(error => {
        console.error('Network Error:', error);
    });
}

// Attach listener to existing inputs on load
document.addEventListener('DOMContentLoaded', function() {
    if (!recommendedSchemesLocked) {
        document.querySelectorAll('.scheme-input').forEach(input => {
            input.addEventListener('input', debouncedSave);
        });
    }
});
</script>