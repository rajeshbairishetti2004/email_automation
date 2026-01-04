<?php
require_once 'auth.php';
require_once 'db_config.php';

requireAuth();

$data = json_decode(file_get_contents('php://input'), true);
$pdo  = getPdo();

if (empty($data['client_id']) || empty($data['schemes'])) {
    echo json_encode(['success' => false, 'message' => 'Invalid payload']);
    exit;
}

$stmt = $pdo->prepare("
    UPDATE client_schemes SET
        sip_swp = :sip,
        current_value = :current,
        action_step = :action,
        recommended_scheme = :rec_scheme,
        recommended_amount = :rec_amount
    WHERE id = :id AND client_id = :client_id
");

foreach ($data['schemes'] as $id => $row) {
    $stmt->execute([
        ':sip'        => $row['sip_swp'] ?? 0,
        ':current'    => $row['current_value'] ?? 0,
        ':action'     => $row['action_step'] ?? 'Continue',
        ':rec_scheme' => $row['recommended_scheme'] ?? null,
        ':rec_amount' => $row['recommended_amount'] ?? null,
        ':id'         => (int)$id,
        ':client_id'  => (int)$data['client_id']
    ]);
}

echo json_encode(['success' => true]);
exit;
