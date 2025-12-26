<?php
require_once 'auth.php';
require_once 'db_config.php';

requireAuth();
header('Content-Type: application/json');

$pdo = getPdo();
$cycle = $_GET['cycle'] ?? '';

if (empty($cycle)) {
    echo json_encode(['success' => false, 'error' => 'No cycle selected']);
    exit;
}

try {
    // 1. Fetch Owners and their counts specifically for this cycle
    $ownerSql = "SELECT u.id, u.username AS name, COUNT(c.id) as client_count 
                 FROM users u 
                 JOIN clients c ON (c.assigned_to = u.id OR c.review_assigned_to = u.id)
                 WHERE c.review_cycle = :cycle
                 GROUP BY u.id 
                 ORDER BY u.username ASC";

    $stmt = $pdo->prepare($ownerSql);
    $stmt->execute([':cycle' => $cycle]);
    $owners = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // 2. Fetch Status counts specifically for this cycle to update the 3rd dropdown
    $statusSql = "SELECT report_state, COUNT(*) as count 
                  FROM clients 
                  WHERE review_cycle = :cycle 
                  GROUP BY report_state";
    $sStmt = $pdo->prepare($statusSql);
    $sStmt->execute([':cycle' => $cycle]);
    $statusCounts = $sStmt->fetchAll(PDO::FETCH_KEY_PAIR);

    echo json_encode([
        'success' => true,
        'owners' => $owners,
        'status_counts' => $statusCounts,
        'cycle_total' => array_sum($statusCounts)
    ]);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
