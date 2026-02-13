<?php
// report_generator/save_client_info.php
require_once 'database.php';
header('Content-Type: application/json');

// Only accept POST requests
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'error' => 'Invalid request method']);
    exit;
}

// Get JSON data
$input = json_decode(file_get_contents('php://input'), true);

if (!$input) {
    echo json_encode(['success' => false, 'error' => 'Invalid JSON data']);
    exit;
}

// Validate required fields
$required = ['client_id', 'client_name'];
foreach ($required as $field) {
    if (empty($input[$field])) {
        echo json_encode(['success' => false, 'error' => "Missing required field: $field"]);
        exit;
    }
}

// Extract data
$client_id = trim($input['client_id']);
$client_name = trim($input['client_name']);
$client_email = isset($input['client_email']) ? trim($input['client_email']) : null;
$phone = isset($input['phone']) ? trim($input['phone']) : null;
$risk_profile = isset($input['risk_profile']) ? trim($input['risk_profile']) : null;
$investment_horizon = isset($input['investment_horizon']) ? trim($input['investment_horizon']) : null;
$portfolio_value = isset($input['portfolio_value']) ? trim($input['portfolio_value']) : null;

// Save to database
$result = saveClientInfoToDatabase(
    $client_id, 
    $client_name, 
    $client_email, 
    $phone, 
    $risk_profile, 
    $investment_horizon, 
    $portfolio_value
);

echo json_encode($result);
?>