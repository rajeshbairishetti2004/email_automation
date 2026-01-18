<?php
// report_generator/save_page.php
require_once 'database.php';
header('Content-Type: application/json');

// Only accept POST requests
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'error' => 'Invalid request method']);
    exit;
}

// Get JSON data
$input = json_decode(file_get_contents('php://input'), true);

if (!$input) {
    echo json_encode(['success' => false, 'error' => 'Invalid JSON data']);
    exit;
}

// Validate required fields
$required = ['client_id', 'page_number', 'content'];
foreach ($required as $field) {
    if (empty($input[$field])) {
        echo json_encode(['success' => false, 'error' => "Missing required field: $field"]);
        exit;
    }
}

// Extract data
$client_id = trim($input['client_id']);
$page_number = intval($input['page_number']);
$content = $input['content'];
$title = isset($input['title']) ? trim($input['title']) : "Slide $page_number";
$bg_color = isset($input['bg_color']) ? $input['bg_color'] : '#ffffff';
$font_size = isset($input['font_size']) ? $input['font_size'] : '14px';
$tags = isset($input['tags']) ? $input['tags'] : '';
$notes = isset($input['notes']) ? $input['notes'] : '';

// Validate page number
if ($page_number < 1 || $page_number > 23) {
    echo json_encode(['success' => false, 'error' => 'Page number must be between 1 and 23']);
    exit;
}

// Save to database
$result = savePageToDatabase($client_id, $page_number, $content, $title, $bg_color, $font_size, $tags, $notes);

echo json_encode($result);
?>