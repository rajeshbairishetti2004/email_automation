<?php
// save_current_situation.php
header('Content-Type: application/json');
require_once __DIR__ . '/db_config.php';

$data = json_decode(file_get_contents('php://input'), true);
$clientId = (int)($data['client_id'] ?? 0);

if ($clientId <= 0) {
    echo json_encode(['success' => false, 'error' => 'Invalid client ID']);
    exit;
}

$pdo = getPdo();

// First, get current values to preserve them
$stmt = $pdo->prepare("SELECT cagr, absolute_return, xirr FROM clients WHERE id = ?");
$stmt->execute([$clientId]);
$currentValues = $stmt->fetch(PDO::FETCH_ASSOC);

// Prepare update data
$updateFields = [];
$params = [':id' => $clientId];

// Always update these fields
$updateFields[] = 'is_older_than_1_year = :is_older_than_1_year';
$params[':is_older_than_1_year'] = (int)($data['is_older_than_1_year'] ?? 1);

$updateFields[] = 'total_amount = :total_amount';
$params[':total_amount'] = (float)($data['total_amount'] ?? 0);

$updateFields[] = 'profit = :profit';
$params[':profit'] = (float)($data['profit'] ?? 0);

// Handle return fields - DO NOT NULL out values, preserve them
if (($data['is_older_than_1_year'] ?? 1) == 0) {
    // Less than 1 year: update absolute return, preserve existing CAGR
    if (isset($data['absolute_return'])) {
        $updateFields[] = 'absolute_return = :absolute_return';
        $params[':absolute_return'] = $data['absolute_return'] !== null ? (float)$data['absolute_return'] : null;
    }
    // Keep existing CAGR value, don't set to NULL
    if (isset($data['cagr']) && $data['cagr'] !== null) {
        $updateFields[] = 'cagr = :cagr';
        $params[':cagr'] = (float)$data['cagr'];
    } else {
        // Preserve existing CAGR from database
        $updateFields[] = 'cagr = :preserve_cagr';
        $params[':preserve_cagr'] = $currentValues['cagr'] !== null ? (float)$currentValues['cagr'] : null;
    }
} else {
    // More than 1 year: update CAGR, preserve existing absolute return
    if (isset($data['cagr'])) {
        $updateFields[] = 'cagr = :cagr';
        $params[':cagr'] = $data['cagr'] !== null ? (float)$data['cagr'] : null;
    }
    // Keep existing absolute return value, don't set to NULL
    if (isset($data['absolute_return']) && $data['absolute_return'] !== null) {
        $updateFields[] = 'absolute_return = :absolute_return';
        $params[':absolute_return'] = (float)$data['absolute_return'];
    } else {
        // Preserve existing absolute return from database
        $updateFields[] = 'absolute_return = :preserve_absolute_return';
        $params[':preserve_absolute_return'] = $currentValues['absolute_return'] !== null ? (float)$currentValues['absolute_return'] : null;
    }
}

// Handle XIRR - always update if provided
if (isset($data['xirr'])) {
    $updateFields[] = 'xirr = :xirr';
    $params[':xirr'] = $data['xirr'] !== null ? (float)$data['xirr'] : null;
}

// Update timestamp
$updateFields[] = 'updated_at = NOW()';

// Execute update
try {
    $sql = "UPDATE clients SET " . implode(', ', $updateFields) . " WHERE id = :id";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    
    echo json_encode(['success' => true, 'message' => 'Current situation updated']);
} catch (PDOException $e) {
    error_log("Save Current Situation Error: " . $e->getMessage());
    echo json_encode(['success' => false, 'error' => 'Database update failed']);
}