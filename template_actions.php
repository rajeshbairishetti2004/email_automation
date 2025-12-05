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

        /* ------------------------------------------------------------------
           WORKFLOW ACTIONS
           ------------------------------------------------------------------ */
        
        case 'save_draft':
            $clientId = (int)($_POST['client_id'] ?? 0);
            if ($clientId <= 0) throw new Exception("Invalid Client ID.");

            $stmt = $pdo->prepare("
                UPDATE clients SET 
                    report_state = 'draft', 
                    draft_at = NOW(), 
                    review_not_ok = 0, 
                    review_comment = NULL 
                WHERE id = :id
            ");
            $stmt->execute([':id' => $clientId]);
            echo json_encode(['success' => true, 'message' => 'Draft saved successfully.', 'updated_state' => 'draft']);
            break;

        case 'ready_for_review':
            $clientId = (int)($_POST['client_id'] ?? 0);
            if ($clientId <= 0) throw new Exception("Invalid Client ID.");

            $stmt = $pdo->prepare("
                UPDATE clients SET 
                    report_state = 'ready', 
                    ready_at = NOW(), 
                    review_not_ok = 0, 
                    review_comment = NULL 
                WHERE id = :id
            ");
            $stmt->execute([':id' => $clientId]);
            echo json_encode(['success' => true, 'message' => 'Report marked Ready for Review.', 'updated_state' => 'ready']);
            break;

        case 'approve_review':
            $clientId = (int)($_POST['client_id'] ?? 0);
            if ($clientId <= 0) throw new Exception("Invalid Client ID.");

            $stmt = $pdo->prepare("
                UPDATE clients SET 
                    report_state = 'reviewed', 
                    reviewed_at = NOW(), 
                    review_not_ok = 0, 
                    review_comment = NULL 
                WHERE id = :id
            ");
            $stmt->execute([':id' => $clientId]);
            echo json_encode(['success' => true, 'message' => 'Report Approved (Reviewed).', 'updated_state' => 'reviewed']);
            break;

        case 'review_not_ok':
            $clientId = (int)($_POST['client_id'] ?? 0);
            $comment  = trim($_POST['review_comment'] ?? '');
            if ($clientId <= 0) throw new Exception("Invalid Client ID.");
            if (empty($comment)) throw new Exception("A comment is required for rejection.");

            $stmt = $pdo->prepare("
                UPDATE clients SET 
                    report_state = 'draft', 
                    review_not_ok = 1, 
                    review_comment = :comment 
                WHERE id = :id
            ");
            $stmt->execute([':id' => $clientId, ':comment' => $comment]);
            echo json_encode(['success' => true, 'message' => 'Report rejected and moved back to Draft.', 'updated_state' => 'draft']);
            break;
            
        case 'email_sent':
             $clientId = (int)($_POST['client_id'] ?? 0);
             if ($clientId <= 0) throw new Exception("Invalid Client ID.");
             
             $stmt = $pdo->prepare("UPDATE clients SET report_state = 'sent', sent_at = NOW() WHERE id = :id");
             $stmt->execute([':id' => $clientId]);
             echo json_encode(['success' => true, 'message' => 'Report marked as Sent.', 'updated_state' => 'sent']);
             break;

        case 'save_template':
			$section = $_POST['section_type'] ?? '';
			$name = trim($_POST['template_name'] ?? '');
			$content = $_POST['template_content'] ?? '';

			if (empty($section) || empty($name) || empty($content)) {
				throw new Exception('Missing required fields (section, name, or content).');
			}

			$stmt = $pdo->prepare('INSERT INTO report_templates (name, section_type, content) VALUES (:name, :section_type, :content)');
			if ($stmt->execute([':name' => $name, ':section_type' => $section, ':content' => $content])) {
				echo json_encode(['success' => true]);
			} else {
				throw new Exception('Database insert failed.');
			}
			break;

        /* ------------------------------------------------------------------
           ATTACHMENT MANAGEMENT (File System Based - No DB Change)
           ------------------------------------------------------------------ */
        case 'upload_attachment':
            $clientId = (int)($_POST['client_id'] ?? 0);
            if ($clientId <= 0) throw new Exception("Invalid Client ID.");

            $baseDir = __DIR__ . '/uploads/attachments/client_' . $clientId;
            if (!is_dir($baseDir)) {
                mkdir($baseDir, 0777, true);
            }

            $savedFiles = [];

            if (isset($_FILES['files']) && is_array($_FILES['files']['name'])) {
                $count = count($_FILES['files']['name']);
                
                for ($i = 0; $i < $count; $i++) {
                    if ($_FILES['files']['error'][$i] === UPLOAD_ERR_OK) {
                        $rawName = basename($_FILES['files']['name'][$i]);
                        $fileName = preg_replace('/[^a-zA-Z0-9\._-]/', '', $rawName);
                        $targetPath = $baseDir . '/' . $fileName;

                        if (move_uploaded_file($_FILES['files']['tmp_name'][$i], $targetPath)) {
                            $savedFiles[] = $fileName;
                        }
                    }
                }
            }

            if (!empty($savedFiles)) {
                echo json_encode(['success' => true, 'files' => $savedFiles]);
            } else {
                throw new Exception("Upload failed or no valid files.");
            }
            break;

        case 'delete_attachment':
            $clientId = (int)($_POST['client_id'] ?? 0);
            $fileName = basename($_POST['file_name'] ?? '');
            
            if ($clientId <= 0 || empty($fileName)) throw new Exception("Invalid parameters.");

            $filePath = __DIR__ . '/uploads/attachments/client_' . $clientId . '/' . $fileName;

            if (file_exists($filePath)) {
                unlink($filePath);
                echo json_encode(['success' => true, 'message' => 'File deleted.']);
            } else {
                throw new Exception("File not found.");
            }
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