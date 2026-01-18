<?php
// report_generator/upload_image.php
require_once 'database.php';
header('Content-Type: application/json');

// Only accept POST requests
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'error' => 'Invalid request method']);
    exit;
}

// Check if image was uploaded
if (!isset($_FILES['image']) || $_FILES['image']['error'] !== UPLOAD_ERR_OK) {
    echo json_encode(['success' => false, 'error' => 'No image uploaded or upload error']);
    exit;
}

// Validate required parameters
if (empty($_POST['page_number'])) {
    echo json_encode(['success' => false, 'error' => 'Page number is required']);
    exit;
}

// Set default client_id
$client_id = 'MS_MUKTA_DUTTA';
$page_number = intval($_POST['page_number']);
$alt_text = isset($_POST['alt_text']) ? trim($_POST['alt_text']) : '';

// Validate page number
if ($page_number < 1 || $page_number > 23) {
    echo json_encode(['success' => false, 'error' => 'Page number must be between 1 and 23']);
    exit;
}

// Validate file
$allowed_types = ['image/jpeg', 'image/jpg', 'image/png', 'image/gif', 'image/webp'];
$file_type = $_FILES['image']['type'];
if (!in_array($file_type, $allowed_types)) {
    echo json_encode(['success' => false, 'error' => 'Only JPG, PNG, GIF, and WebP images are allowed']);
    exit;
}

// Check file size (max 5MB)
$max_size = 5 * 1024 * 1024; // 5MB
if ($_FILES['image']['size'] > $max_size) {
    echo json_encode(['success' => false, 'error' => 'File size must be less than 5MB']);
    exit;
}

// Create uploads directory if it doesn't exist
$upload_dir = __DIR__ . '/uploads/';
if (!is_dir($upload_dir)) {
    mkdir($upload_dir, 0755, true);
}

// Generate unique filename
$original_name = basename($_FILES['image']['name']);
$extension = pathinfo($original_name, PATHINFO_EXTENSION);
$filename = 'page' . $page_number . '_' . time() . '_' . uniqid() . '.' . strtolower($extension);
$filepath = $upload_dir . $filename;

// Move uploaded file
if (move_uploaded_file($_FILES['image']['tmp_name'], $filepath)) {
    // Get image dimensions
    $dimensions = getimagesize($filepath);
    $width = $dimensions[0] ?? null;
    $height = $dimensions[1] ?? null;
    
    // Save to database
    $result = uploadImageToDatabase($client_id, $page_number, $filename, $alt_text, $width, $height);
    
    if ($result['success']) {
        echo json_encode([
            'success' => true,
            'path' => 'uploads/' . $filename,
            'image_id' => $result['image_id'],
            'filename' => $filename,
            'message' => 'Image uploaded successfully'
        ]);
    } else {
        // Delete file if database save failed
        if (file_exists($filepath)) {
            unlink($filepath);
        }
        echo json_encode(['success' => false, 'error' => $result['error']]);
    }
} else {
    echo json_encode(['success' => false, 'error' => 'Failed to save uploaded file']);
}
?>