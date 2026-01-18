<?php
require_once 'database.php';
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'DELETE') {
    echo json_encode(['success' => false, 'error' => 'Invalid method']);
    exit;
}

$page = isset($_GET['page']) ? intval($_GET['page']) : 0;
$client_id = 'MS_MUKTA_DUTTA';

if ($page < 1 || $page > 23) {
    echo json_encode(['success' => false, 'error' => 'Invalid page number']);
    exit;
}

$pdo = getDbConnection();

try {
    $sql = "UPDATE portfolio_slides SET content = '', images = NULL WHERE client_id = ? AND page_number = ?";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$client_id, $page]);
    echo json_encode(['success' => true, 'message' => 'Slide cleared']);
} catch (PDOException $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
?>