<?php
require_once 'auth.php';
require_once 'db_config.php';

requireAuth();
$pdo = getPdo();

$data = json_decode(file_get_contents('php://input'), true);

if (!$data || empty($data['client_id'])) {
    echo json_encode(['success' => false, 'error' => 'Invalid payload']);
    exit;
}

$stmt = $pdo->prepare("
    UPDATE clients SET
        total_amount = :total_amount,
        profit = :profit,
        cagr = :cagr,
        absolute_return = :absolute_return,
        xirr = :xirr,
        updated_at = NOW()
    WHERE id = :client_id
");

$stmt->execute([
    ':total_amount'     => $data['total_amount'],
    ':profit'           => $data['profit'],
    ':cagr'             => $data['cagr'],
    ':absolute_return'  => $data['absolute_return'],
    ':xirr'             => $data['xirr'],
    ':client_id'        => $data['client_id'],
]);

echo json_encode(['success' => true]);
