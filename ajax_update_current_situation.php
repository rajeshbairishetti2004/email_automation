<?php
require_once 'auth.php';
require_once 'db_config.php';

requireAuth();
$pdo = getPdo();

$data = json_decode(file_get_contents('php://input'), true);
if (!$data || empty($data['client_id'])) {
    echo json_encode(['success' => false, 'error' => 'Invalid request']);
    exit;
}

$clientId = (int)$data['client_id'];

function cleanAmount($v) {
    // Accepts numbers or numeric strings, strips formatting, returns float
    if (is_numeric($v)) return (float)$v;
    $v = preg_replace('/[^0-9.\-eE]/', '', (string)$v); // keep numbers, dot, minus, exponent
    return (float)$v;
}

$sql = "
    UPDATE clients SET
        total_amount     = :total_amount,
        absolute_return  = :absolute_return,
        cagr             = :cagr,
        xirr             = :xirr,
        profit           = :profit,
        updated_at       = NOW()
    WHERE id = :id
";

$stmt = $pdo->prepare($sql);
try {
    $stmt->execute([
        ':total_amount'    => cleanAmount($data['total_amount'] ?? 0),
        ':absolute_return' => cleanAmount($data['absolute_return'] ?? 0),
        ':cagr'            => cleanAmount($data['cagr'] ?? 0),
        ':xirr'            => cleanAmount($data['xirr'] ?? 0),
        ':profit'          => cleanAmount($data['profit'] ?? 0),
        ':id'              => $clientId,
    ]);
    echo json_encode(['success' => true]);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}

