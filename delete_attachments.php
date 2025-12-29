<?php
// delete_attachments.php: Deletes all attachments and annexures for a client
require_once 'auth.php';
require_once 'db_config.php';
requireAuth();

$clientId = isset($_GET['client_id']) ? (int)$_GET['client_id'] : 0;
if ($clientId <= 0) {
    echo json_encode(['success' => false, 'error' => 'Invalid client id']);
    exit;
}

$pdo = getPdo();

// Delete files from filesystem
$attDir = __DIR__ . '/uploads/attachments/client_' . $clientId;
if (is_dir($attDir)) {
    $files = scandir($attDir);
    foreach ($files as $file) {
        if ($file !== '.' && $file !== '..') {
            @unlink($attDir . '/' . $file);
        }
    }
}

// Delete annexures from DB
$pdo->prepare('DELETE FROM client_annexures WHERE client_id = ?')->execute([$clientId]);

echo json_encode(['success' => true]);
exit;