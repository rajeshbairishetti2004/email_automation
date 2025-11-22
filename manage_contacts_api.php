<?php
// manage_contacts_api.php
// Endpoint to handle AJAX requests for adding and deleting email contacts persistently.

require_once 'db_config.php';

header('Content-Type: application/json');

// Check for required POST data
if (!isset($_POST['action'], $_POST['listType'])) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'Missing required parameters.']);
    exit;
}

$action = $_POST['action'];
$listType = $_POST['listType'];
$clientId = isset($_POST['clientId']) ? (int)$_POST['clientId'] : null;

try {
    if ($action === 'add') {
        if (!isset($_POST['email'])) {
            http_response_code(400);
            echo json_encode(['status' => 'error', 'message' => 'Missing email for add action.']);
            exit;
        }
        $email = trim($_POST['email']);
        
        // Execute the database insertion
        $success = saveNewEmailContact($email, $listType, $clientId);
        
        if ($success) {
            echo json_encode(['status' => 'success', 'message' => "Email '$email' added successfully."]);
        } else {
            // This usually happens if the email already exists due to INSERT IGNORE
            echo json_encode(['status' => 'success', 'message' => "Email '$email' already exists or could not be added."]);
        }

    } elseif ($action === 'delete') {
        if (!isset($_POST['emails'])) {
            http_response_code(400);
            echo json_encode(['status' => 'error', 'message' => 'Missing emails for delete action.']);
            exit;
        }
        // Emails come as a comma-separated string from JavaScript
        $emailsToDelete = array_map('trim', explode(',', $_POST['emails']));
        
        // Execute the database deletion
        $deletedCount = deleteEmailContacts($emailsToDelete, $listType, $clientId);
        
        echo json_encode(['status' => 'success', 'message' => "$deletedCount email(s) deleted successfully."]);

    } else {
        http_response_code(400);
        echo json_encode(['status' => 'error', 'message' => 'Invalid action specified.']);
    }

} catch (PDOException $e) {
    // Log the error and return a generic server error message
    error_log("DB Error in manage_contacts_api: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'Database operation failed.']);
}
?>