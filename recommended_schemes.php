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
?>

<div class="section-card" style="margin-top: 20px; margin-bottom: 20px; padding: 20px; background: white; border-radius: 8px; box-shadow: 0 2px 4px rgba(0,0,0,0.05);">
    <h3 style="color: #2c3e50; border-bottom: 2px solid #3498db; padding-bottom: 10px; margin-bottom: 20px;">
        Recommended Schemes
    </h3>
    <table class="table" id="newSchemesTable" style="width: 100%; margin-bottom: 15px;">
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
                               class="form-control scheme-input" 
                               placeholder="e.g. HDFC Top 100" 
                               style="width: 100%; padding: 8px; border: 1px solid #ddd; border-radius: 4px;">
                    </td>
                    <td>
                        <input type="text" 
                               name="new_scheme_amount[]" 
                               value="<?php echo htmlspecialchars($ns['amount']); ?>" 
                               class="form-control scheme-input" 
                               placeholder="Enter amount" 
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
                <?php endforeach; ?>
            <?php endif; ?>
        </tbody>
    </table>
    <button type="button" 
            onclick="addNewSchemeRow()" 
            style="background: #27ae60; color: white; border: none; padding: 8px 15px; border-radius: 4px; cursor: pointer; font-weight: 500;">
        + Add Scheme
    </button>
</div>

<script>
// Recommended Schemes JavaScript Functions
const currentClientId = <?php echo isset($client['id']) ? (int)$client['id'] : (isset($clientId) ? (int)$clientId : 0); ?>;

function addNewSchemeRow() {
    const tbody = document.getElementById('newSchemesBody');
    const row = `
        <tr>
            <td>
                <input type="text" 
                       name="new_scheme_name[]" 
                       class="form-control scheme-input" 
                       placeholder="e.g. HDFC Top 100" 
                       oninput="debouncedSave()" 
                       style="width: 100%; padding: 8px; border: 1px solid #ddd; border-radius: 4px;">
            </td>
            <td>
                <input type="text" 
                       name="new_scheme_amount[]" 
                       class="form-control scheme-input" 
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
    btn.closest('tr').remove();
    saveSchemesNow(); // Save immediately on delete
}

// Debounce: Wait 1 second after typing stops before saving
let saveTimer;
function debouncedSave() {
    // Show "Saving..." indicator
    const title = document.querySelector('.section-card h3');
    if(title && !title.innerHTML.includes('Saving')) {
         title.innerHTML = "Recommended Schemes <span style='color:orange; font-size:12px;'>(Saving...)</span>";
    }
    
    clearTimeout(saveTimer);
    saveTimer = setTimeout(saveSchemesNow, 1000);
}

function saveSchemesNow() {
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
    document.querySelectorAll('.scheme-input').forEach(input => {
        input.addEventListener('input', debouncedSave);
    });
});
</script>