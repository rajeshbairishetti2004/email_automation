<?php
// report_generator/delete_image.php
require_once 'database.php';
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'error' => 'Invalid method']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);

if (empty($input['image_id']) || empty($input['client_id'])) {
    echo json_encode(['success' => false, 'error' => 'Missing required fields']);
    exit;
}

$image_id = intval($input['image_id']);
$client_id = trim($input['client_id']);

$result = deleteImageFromDatabase($image_id, $client_id);

if ($result['success']) {
    // Also delete the physical file
    $filepath = __DIR__ . '/uploads/' . $result['filename'];
    if (file_exists($filepath)) {
        unlink($filepath);
    }
    echo json_encode(['success' => true, 'message' => 'Image deleted successfully']);
} else {
    echo json_encode(['success' => false, 'error' => $result['error']]);
}
?>