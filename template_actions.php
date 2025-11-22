<?php
// template_actions.php
// Handles all AJAX actions related to RMs and Templates (loading, deleting, etc.).

require_once 'db_config.php';

$pdo = getPdo();
$clientId = (int)($_GET['id'] ?? 0); // Get client ID from query string
$ajax_action = $_POST['ajax_action'] ?? null;

// Check if this is a valid AJAX POST action targeting templates/RMs
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !$ajax_action) {
    // If not a recognized POST action, return silently.
    return;
}

// Ensure the necessary headers for JSON response
header('Content-Type: application/json');

try {
    switch ($ajax_action) {
        case 'load_template':
            $templateId = (int)($_POST['template_id'] ?? 0);
            
            $content = getTemplateContent($templateId);

            if (!$content) {
                throw new Exception("Template content not found for ID: " . $templateId);
            }
            echo json_encode(['success' => true, 'content' => $content]);
            break;

        case 'delete_template':
            $templateId = (int)($_POST['template_id'] ?? 0);
            
            if ($templateId <= 0) {
                throw new Exception("Invalid Template ID for deletion.");
            }
            
            if (deleteTemplate($templateId)) {
                echo json_encode(['success' => true, 'message' => 'Template deleted successfully.']);
            } else {
                throw new Exception("Database operation failed during deletion.");
            }
            break;

        case 'load_rm':
            $rmId = (int)($_POST['rm_id'] ?? 0);
            
            $stmt = $pdo->prepare("SELECT * FROM relationship_managers WHERE id = :rm_id");
            $stmt->execute([':rm_id' => $rmId]);
            $rm = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$rm) {
                throw new Exception("Relationship Manager not found for ID: " . $rmId);
            }

            $newSignature = generateSignatureBlock($rm);
            echo json_encode(['success' => true, 'signature_block' => $newSignature, 'rm_name' => $rm['name']]);
            break;

        case 'delete_rm':
            $rmId = (int)($_POST['rm_id'] ?? 0);
            
            if ($rmId <= 0) {
                throw new Exception("Invalid RM ID for deletion.");
            }
            
            $rmCount = getRelationshipManagerCount();
            if ($rmCount <= 1) {
                throw new Exception("Cannot delete: At least one Relationship Manager must remain in the system.");
            }
            
            $stmtCheck = $pdo->prepare("SELECT is_default FROM relationship_managers WHERE id = :rm_id");
            $stmtCheck->execute([':rm_id' => $rmId]);
            $isDefault = $stmtCheck->fetchColumn();

            $stmtDelete = $pdo->prepare("DELETE FROM relationship_managers WHERE id = :rm_id");
            $stmtDelete->execute([':rm_id' => $rmId]);
            
            if ($isDefault == 1) {
                $pdo->exec("UPDATE relationship_managers SET is_default = 1 ORDER BY id ASC LIMIT 1");
            }

            echo json_encode(['success' => true, 'rm_id' => $rmId]);
            break;

        default:
            // If the action is POST but not one of the specific AJAX actions above, 
            // the main script (view_report.php) should handle it.
            return;
    }
} catch (Throwable $e) {
    // Catch-all for AJAX errors
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}

// Exit immediately after processing an AJAX request
exit;
?>