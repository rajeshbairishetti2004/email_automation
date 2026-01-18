<?php
// report_generator/get_slide_history.php
require_once 'database.php';
header('Content-Type: application/json');

$client_id = 'MS_MUKTA_DUTTA';
$page = isset($_GET['page']) ? intval($_GET['page']) : 1;

if ($page < 1 || $page > 23) {
    echo json_encode([]);
    exit;
}

$history = getSlideHistory($client_id, $page, 10);
echo json_encode($history);
?>