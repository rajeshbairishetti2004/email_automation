<?php
// report_generator/database.php

// Include the root database configuration
require_once __DIR__ . '/../db_config.php';

// Get PDO connection from db_config.php
function getDbConnection() {
    try {
        return getPdo(); // Function from db_config.php
    } catch (Exception $e) {
        error_log("Database connection failed: " . $e->getMessage());
        die("Database connection failed. Please check configuration.");
    }
}

// Function to get client-specific pages
function getClientPages($client_id = 'MS_MUKTA_DUTTA') {
    $pdo = getDbConnection();
    
    try {
        $stmt = $pdo->prepare("SELECT * FROM portfolio_slides WHERE client_id = ? ORDER BY page_number");
        $stmt->execute([$client_id]);
        $pages = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $pages[$row['page_number']] = $row;
        }
        return $pages;
    } catch (PDOException $e) {
        error_log("Error getting pages: " . $e->getMessage());
        return [];
    }
}

// In database.php, add this function
function getClientDetailsFromClientsTable($client_id) {
    $pdo = getDbConnection();
    
    try {
        // Extract numeric ID from CLIENT_X format
        if (strpos($client_id, 'CLIENT_') === 0) {
            $numeric_id = str_replace('CLIENT_', '', $client_id);
            
            $stmt = $pdo->prepare("SELECT * FROM clients WHERE id = ?");
            $stmt->execute([$numeric_id]);
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($result) {
                return [
                    'client_id' => $client_id,
                    'client_name' => $result['name'] ?? 'Client',
                    'client_email' => $result['email'] ?? '',
                    'phone' => $result['phone'] ?? '',
                    'risk_profile' => 'Moderate', // Default or from another table
                    'investment_horizon' => 'Long-term (7+ years)', // Default
                    'portfolio_value' => $result['total_amount'] ?? null,
                    'created_at' => date('Y-m-d H:i:s')
                ];
            }
        }
        
        return null;
    } catch (PDOException $e) {
        error_log("Error getting client details: " . $e->getMessage());
        return null;
    }
}

// Update getClientInfo function to try multiple sources
function getClientInfo($client_id) {
    // First try the client_info table
    $pdo = getDbConnection();
    
    try {
        $stmt = $pdo->prepare("SELECT * FROM client_info WHERE client_id = ?");
        $stmt->execute([$client_id]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($result) {
            return $result;
        }
        
        // If not found in client_info, try clients table
        $client_details = getClientDetailsFromClientsTable($client_id);
        if ($client_details) {
            return $client_details;
        }
        
        // Return default structure if no client found
        return [
            'client_id' => $client_id,
            'client_name' => 'Client',
            'client_email' => '',
            'phone' => '',
            'risk_profile' => 'Moderate',
            'investment_horizon' => 'Long-term (7+ years)',
            'portfolio_value' => null,
            'created_at' => date('Y-m-d H:i:s')
        ];
    } catch (PDOException $e) {
        error_log("Error getting client info: " . $e->getMessage());
        return getClientDetailsFromClientsTable($client_id) ?? [
            'client_id' => $client_id,
            'client_name' => 'Client',
            'client_email' => '',
            'phone' => '',
            'risk_profile' => 'Moderate',
            'investment_horizon' => 'Long-term (7+ years)',
            'portfolio_value' => null,
            'created_at' => date('Y-m-d H:i:s')
        ];
    }
}

// Function to get image URL
function getImageUrl($filename) {
    if (empty($filename)) return '';
    
    // Check if file exists in uploads folder
    $upload_dir = __DIR__ . '/uploads/';
    if (!is_dir($upload_dir)) {
        mkdir($upload_dir, 0755, true);
    }
    
    $root_path = $upload_dir . $filename;
    $relative_path = 'uploads/' . $filename;
    
    if (file_exists($root_path)) {
        return $relative_path;
    }
    return ''; // Return empty if file doesn't exist
}

// Function to save page
function savePageToDatabase(
    $client_id,
    $page_number,
    $content,
    $title,
    $preview_text = null,
    $bg_color = '#ffffff',
    $font_size = '14px',
    $tags = '',
    $notes = ''
) {
    $pdo = getDbConnection();

    if (empty($title)) {
        $title = "Slide " . $page_number;
    }

    $sql = "
        INSERT INTO portfolio_slides
        (client_id, page_number, title, content, preview_text, bg_color, font_size, tags, notes, updated_at)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, CURRENT_TIMESTAMP)
        ON DUPLICATE KEY UPDATE
            title = VALUES(title),
            content = VALUES(content),
            preview_text = VALUES(preview_text),
            bg_color = VALUES(bg_color),
            font_size = VALUES(font_size),
            tags = VALUES(tags),
            notes = VALUES(notes),
            updated_at = CURRENT_TIMESTAMP
    ";

    $stmt = $pdo->prepare($sql);
    $stmt->execute([
        $client_id,
        $page_number,
        $title,
        $content,
        $preview_text,
        $bg_color,
        $font_size,
        $tags,
        $notes
    ]);

    return ['success' => true];
}

// Function to save client info
function saveClientInfoToDatabase($client_id, $client_name, $client_email = null, $phone = null, $risk_profile = null, $investment_horizon = null, $portfolio_value = null) {
    $pdo = getDbConnection();
    
    try {
        // Clean portfolio value
        if (!empty($portfolio_value)) {
            $portfolio_value = floatval(str_replace(['₹', ',', ' '], '', $portfolio_value));
        }
        
        $sql = "INSERT INTO client_info (client_id, client_name, client_email, phone, risk_profile, investment_horizon, portfolio_value, updated_at) 
                VALUES (?, ?, ?, ?, ?, ?, ?, CURRENT_TIMESTAMP)
                ON DUPLICATE KEY UPDATE 
                client_name = VALUES(client_name),
                client_email = VALUES(client_email),
                phone = VALUES(phone),
                risk_profile = VALUES(risk_profile),
                investment_horizon = VALUES(investment_horizon),
                portfolio_value = VALUES(portfolio_value),
                updated_at = CURRENT_TIMESTAMP";
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$client_id, $client_name, $client_email, $phone, $risk_profile, $investment_horizon, $portfolio_value]);
        
        return ['success' => true, 'message' => 'Client info saved successfully'];
    } catch (PDOException $e) {
        error_log("Error saving client info: " . $e->getMessage());
        return ['success' => false, 'error' => 'Database error: ' . $e->getMessage()];
    }
}

// Function to upload image
function uploadImageToDatabase($client_id, $page_number, $filename, $alt_text = '', $width = null, $height = null) {
    $pdo = getDbConnection();
    
    try {
        // First, ensure the page exists
        $page_check = $pdo->prepare("SELECT COUNT(*) FROM portfolio_slides WHERE client_id = ? AND page_number = ?");
        $page_check->execute([$client_id, $page_number]);
        
        if ($page_check->fetchColumn() == 0) {
            // Create a placeholder page
            $create_page = $pdo->prepare("INSERT INTO portfolio_slides (client_id, page_number, title, content, updated_at) VALUES (?, ?, ?, '', CURRENT_TIMESTAMP)");
            $create_page->execute([$client_id, $page_number, "Slide " . $page_number]);
        }
        
        // Insert image record
        $sql = "INSERT INTO slide_images (client_id, page_number, filename, alt_text, width, height, uploaded_at) 
                VALUES (?, ?, ?, ?, ?, ?, CURRENT_TIMESTAMP)";
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$client_id, $page_number, $filename, $alt_text, $width, $height]);
        
        $image_id = $pdo->lastInsertId();
        
        // Get existing images for this page
        $page_sql = "SELECT images FROM portfolio_slides WHERE client_id = ? AND page_number = ?";
        $page_stmt = $pdo->prepare($page_sql);
        $page_stmt->execute([$client_id, $page_number]);
        $images_json = $page_stmt->fetchColumn();
        
        $images = $images_json ? json_decode($images_json, true) : [];
        $images[] = [
            'id' => $image_id,
            'filename' => $filename,
            'alt' => $alt_text,
            'width' => $width,
            'height' => $height,
            'uploaded_at' => date('Y-m-d H:i:s')
        ];
        
        // Update page
        $update_sql = "UPDATE portfolio_slides SET images = ? WHERE client_id = ? AND page_number = ?";
        $update_stmt = $pdo->prepare($update_sql);
        $update_stmt->execute([json_encode($images), $client_id, $page_number]);
        
        return ['success' => true, 'image_id' => $image_id, 'filename' => $filename];
    } catch (PDOException $e) {
        error_log("Error uploading image: " . $e->getMessage());
        return ['success' => false, 'error' => 'Database error: ' . $e->getMessage()];
    }
}

// Function to delete image
function deleteImageFromDatabase($image_id, $client_id) {
    $pdo = getDbConnection();
    
    try {
        // Get image info before deleting
        $stmt = $pdo->prepare("SELECT filename, page_number FROM slide_images WHERE id = ? AND client_id = ?");
        $stmt->execute([$image_id, $client_id]);
        $image = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$image) {
            return ['success' => false, 'error' => 'Image not found'];
        }
        
        // Delete from database
        $delete_stmt = $pdo->prepare("DELETE FROM slide_images WHERE id = ? AND client_id = ?");
        $delete_stmt->execute([$image_id, $client_id]);
        
        // Remove from page images JSON
        $page_sql = "SELECT images FROM portfolio_slides WHERE client_id = ? AND page_number = ?";
        $page_stmt = $pdo->prepare($page_sql);
        $page_stmt->execute([$client_id, $image['page_number']]);
        $images_json = $page_stmt->fetchColumn();
        
        if ($images_json) {
            $images = json_decode($images_json, true);
            if (is_array($images)) {
                $images = array_filter($images, function($img) use ($image_id) {
                    return isset($img['id']) && $img['id'] != $image_id;
                });
                
                $update_sql = "UPDATE portfolio_slides SET images = ? WHERE client_id = ? AND page_number = ?";
                $update_stmt = $pdo->prepare($update_sql);
                $update_stmt->execute([json_encode(array_values($images)), $client_id, $image['page_number']]);
            }
        }
        
        return ['success' => true, 'filename' => $image['filename']];
    } catch (PDOException $e) {
        error_log("Error deleting image: " . $e->getMessage());
        return ['success' => false, 'error' => 'Database error: ' . $e->getMessage()];
    }
}

// Function to get slide history
function getSlideHistory($client_id, $page_number, $limit = 10) {
    $pdo = getDbConnection();
    
    try {
        $sql = "SELECT * FROM slide_history 
                WHERE client_id = ? AND page_number = ? 
                ORDER BY changed_at DESC 
                LIMIT ?";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$client_id, $page_number, $limit]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        error_log("Error getting history: " . $e->getMessage());
        return [];
    }
}

// Get database connection for use in other files
$conn = getDbConnection();
?>