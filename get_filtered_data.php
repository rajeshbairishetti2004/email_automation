<?php
require_once 'auth.php';
require_once 'db_config.php';

requireAuth();
header('Content-Type: application/json');

$state = $_GET['state'] ?? '';
$pdo = getPdo();

try {
    // 1. Fetch Owners (RMs) linked to the selected state
    $ownerQuery = "SELECT u.id, u.username AS name, COUNT(c.id) as client_count 
                   FROM users u 
                   JOIN clients c ON (c.assigned_to = u.id OR c.review_assigned_to = u.id)
                   WHERE c.review_cycle = :state
                   GROUP BY u.id 
                   ORDER BY u.username ASC";
    $stmt = $pdo->prepare($ownerQuery);
    $stmt->execute([':state' => $state]);
    $owners = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // 2. Fetch the total client count for this specific state to update labels
    $totalQuery = "SELECT COUNT(*) FROM clients WHERE review_cycle = :state";
    $tStmt = $pdo->prepare($totalQuery);
    $tStmt->execute([':state' => $state]);
    $totalInState = $tStmt->fetchColumn();

    echo json_encode([
        'success' => true,
        'state_total' => (int)$totalInState,
        'owners' => $owners
    ]);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
