<?php
// save_schemes_ajax.php
// Handles auto-saving of New Schemes via AJAX

// 1. Load Environment & DB
require_once 'auth.php';
require_once 'db_config.php';

// 2. Set JSON Header
header('Content-Type: application/json');

// 3. Auth Check (Optional: Disable if testing, but recommended to keep)
// if (!isLoggedIn()) { echo json_encode(['status'=>'error', 'message'=>'Unauthorized']); exit; }

$pdo = getPdo();

try {
    // 4. Get JSON Input
    $input = file_get_contents('php://input');
    $data = json_decode($input, true);

    if (!isset($data['client_id']) || !isset($data['schemes'])) {
        throw new Exception("Missing client_id or schemes data");
    }

    $clientId = (int)$data['client_id'];
    $schemes = $data['schemes']; // Array of {name, amount}

    if ($clientId <= 0) {
        throw new Exception("Invalid Client ID: " . $clientId);
    }

    // 5. Transaction for safety
    $pdo->beginTransaction();

    // A. Delete existing schemes for this client
    $delStmt = $pdo->prepare("DELETE FROM client_new_schemes WHERE client_id = ?");
    $delStmt->execute([$clientId]);

    // B. Insert new schemes
    if (!empty($schemes)) {
        $insStmt = $pdo->prepare("INSERT INTO client_new_schemes (client_id, scheme_name, amount) VALUES (?, ?, ?)");
        
        foreach ($schemes as $s) {
            $name = trim($s['name'] ?? '');
            $amount = trim($s['amount'] ?? '');           
            // Only save valid rows
            if ($name !== '') {
                $insStmt->execute([$clientId, $name, $amount]);
            }
        }
    }

    $pdo->commit();
    echo json_encode(['status' => 'success', 'message' => 'Saved successfully']);

} catch (Exception $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    // Log error for debugging
    error_log("Save Schemes Error: " . $e->getMessage());
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
}
?>