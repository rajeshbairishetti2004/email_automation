<?php
require_once 'db_config.php';
$pdo = getPdo();

session_start();
$cycle = $_GET['cycle'] ?? '';
$owner = $_GET['owner'] ?? '';
$state = $_GET['state'] ?? '';

$whereParts = [];
$params = [];
if ($cycle !== '') {
    $whereParts[] = 'c.review_cycle = ?';
    $params[] = $cycle;
}
if ($owner !== '' && $owner !== 'all') {
    if ($owner === 'mine') {
        $myId = $_SESSION['user_id'] ?? 0;
        $whereParts[] = '(c.assigned_to = ? OR c.review_assigned_to = ?)';
        $params[] = $myId;
        $params[] = $myId;
    } elseif (ctype_digit($owner)) {
        $whereParts[] = '(c.assigned_to = ? OR c.review_assigned_to = ?)';

        $params[] = (int)$owner;
        $params[] = (int)$owner;
    }
}
if ($state !== '') {
    $whereParts[] = 'c.report_state = ?';
    $params[] = $state;
}
$where = $whereParts ? 'WHERE ' . implode(' AND ', $whereParts) : '';

// Cycle options
$cycleSql = "SELECT c.review_cycle, COUNT(*) as total FROM clients c $where GROUP BY c.review_cycle";
$cycleStmt = $pdo->prepare($cycleSql);
$cycleStmt->execute($params);
$cycleOptions = '<option value="">All Cycles</option>';
$cycleMap = [];
foreach ($cycleStmt as $row) {
    $cycleMap[$row['review_cycle']] = $row['total'];
}
foreach (["RJ", "RM", "RF"] as $cycleVal) {
    $count = $cycleMap[$cycleVal] ?? 0;
    $cycleOptions .= '<option value="' . $cycleVal . '">' . $cycleVal . ' (' . $count . ")</option>";
}

// Owner options (fix: only show owners with clients in filtered set)
$ownerSql = "SELECT u.id, u.username, COUNT(c.id) as total 
             FROM users u 
             INNER JOIN clients c ON (c.assigned_to = u.id OR c.review_assigned_to = u.id) 
             $where 
             GROUP BY u.id, u.username 
             HAVING total > 0";
$ownerStmt = $pdo->prepare($ownerSql);
$ownerStmt->execute($params);
$ownerOptions = '<option value="all">All Owners / Global View</option>';
$ownerOptions .= '<option value="mine">My Reports</option>';
foreach ($ownerStmt as $row) {
    $ownerOptions .= '<option value="' . (int)$row['id'] . '">' . htmlspecialchars($row['username']) . ' (' . $row['total'] . ")</option>";
}

// State options
$stateSql = "SELECT c.report_state, COUNT(*) as total FROM clients c $where GROUP BY c.report_state";
$stateStmt = $pdo->prepare($stateSql);
$stateStmt->execute($params);
$stateMap = [];
foreach ($stateStmt as $row) {
    $stateMap[$row['report_state']] = $row['total'];
}
$states = [
    '' => 'All States',
    'pending' => 'Review Not Started',
    'draft' => 'Draft',
    'ready' => 'Ready',
    'reviewed' => 'Reviewed',
    'sent' => 'Sent',
];
$stateOptions = '';
foreach ($states as $val => $label) {
    $count = $val === '' ? array_sum($stateMap) : ($stateMap[$val] ?? 0);
    $stateOptions .= '<option value="' . $val . '">' . $label . ' (' . $count . ")</option>";
}

echo json_encode([
    'cycleOptions' => $cycleOptions,
    'ownerOptions' => $ownerOptions,
    'stateOptions' => $stateOptions
]);
