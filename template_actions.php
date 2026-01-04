<?php
// template_actions.php
// Handles all AJAX actions related to RMs and File Attachments.

require_once 'db_config.php';

if (!function_exists('formatAnnexureLabel')) {
    function formatAnnexureLabel($filename, $clientName = '') {
        $name = pathinfo($filename, PATHINFO_FILENAME);
        if ($name === '') {
            return $filename;
        }
        return $name;
    }
}

$pdo = getPdo();
$clientId = (int)($_GET['id'] ?? 0); // Get client ID from query string
$ajax_action = $_POST['ajax_action'] ?? null;

// Check if this is a valid AJAX POST action
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !$ajax_action) {
    return;
}

header('Content-Type: application/json');

try {
    switch ($ajax_action) {
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

            $stmt = $pdo->prepare("UPDATE clients SET report_state = 'draft', draft_at = NOW(), review_not_ok = 0, review_comment = NULL WHERE id = :id");
            $stmt->execute([':id' => $clientId]);
            echo json_encode(['success' => true, 'message' => 'Draft saved successfully.', 'updated_state' => 'draft']);
            break;

        case 'ready_for_review':
            $clientId = (int)($_POST['client_id'] ?? 0);
            if ($clientId <= 0) throw new Exception("Invalid Client ID.");

            $stmt = $pdo->prepare("UPDATE clients SET report_state = 'ready', ready_at = NOW(), review_not_ok = 0, review_comment = NULL WHERE id = :id");
            $stmt->execute([':id' => $clientId]);
            echo json_encode(['success' => true, 'message' => 'Report marked Ready for Review.', 'updated_state' => 'ready']);
            break;

        case 'approve_review':
            $clientId = (int)($_POST['client_id'] ?? 0);
            if ($clientId <= 0) throw new Exception("Invalid Client ID.");

            $stmt = $pdo->prepare("UPDATE clients SET report_state = 'reviewed', reviewed_at = NOW(), review_not_ok = 0, review_comment = NULL WHERE id = :id");
            $stmt->execute([':id' => $clientId]);
            echo json_encode(['success' => true, 'message' => 'Report Approved (Reviewed).', 'updated_state' => 'reviewed']);
            break;

        case 'review_not_ok':
            $clientId = (int)($_POST['client_id'] ?? 0);
            $comment  = trim($_POST['review_comment'] ?? '');
            if ($clientId <= 0) throw new Exception("Invalid Client ID.");
            if (empty($comment)) throw new Exception("A comment is required for rejection.");

            $stmt = $pdo->prepare("UPDATE clients SET report_state = 'draft', review_not_ok = 1, review_comment = :comment WHERE id = :id");
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

        /* ------------------------------------------------------------------
           ATTACHMENT MANAGEMENT
           ------------------------------------------------------------------ */
        case 'upload_attachment':
            $clientId = (int)($_POST['client_id'] ?? 0);
            if ($clientId <= 0) throw new Exception("Invalid Client ID.");

            $stmtClient = $pdo->prepare("SELECT name FROM clients WHERE id = :id LIMIT 1");
            $stmtClient->execute([':id' => $clientId]);
            $targetClientName = $stmtClient->fetchColumn();
            
            if (!$targetClientName) throw new Exception("Client not found with ID: " . $clientId);

            $baseDir = __DIR__ . '/uploads/attachments/client_' . $clientId;
            if (!is_dir($baseDir)) mkdir($baseDir, 0777, true);

            $savedFiles = [];
            if (isset($_FILES['files']) && is_array($_FILES['files']['name'])) {
                for ($i = 0; $i < count($_FILES['files']['name']); $i++) {
                    if ($_FILES['files']['error'][$i] === UPLOAD_ERR_OK) {
                        $rawName = basename($_FILES['files']['name'][$i]);
                        
                        // Security check: ensure client name is in filename
                        $normalizedFile = strtolower(preg_replace('/[^a-zA-Z0-9]/', '', $rawName));
                        $normalizedClient = strtolower(preg_replace('/[^a-zA-Z0-9]/', '', $targetClientName));
                        
                        if (strpos($normalizedFile, $normalizedClient) === false) {
                            $nameParts = preg_split('/\s+/', $targetClientName);
                            $partFound = false;
                            foreach ($nameParts as $part) {
                                $normalizedPart = strtolower(preg_replace('/[^a-zA-Z0-9]/', '', $part));
                                if (!empty($normalizedPart) && strpos($normalizedFile, $normalizedPart) !== false) {
                                    $partFound = true;
                                    break;
                                }
                            }
                            if (!$partFound) throw new Exception("❌ Security Alert: Filename must contain the client's name.");
                        }

                        $fileBase = preg_replace('/\.[^.]+$/', '', $rawName);
                        $fileName = preg_replace('/[^\w\s\._-]/u', '', $fileBase) . '.pdf';
                        $targetPath = $baseDir . '/' . $fileName;

                        if (move_uploaded_file($_FILES['files']['tmp_name'][$i], $targetPath)) {
                            $savedFiles[] = $fileName;
                        }
                    }
                }
            }
            echo json_encode(['success' => true, 'files' => $savedFiles]);
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

        case 'rename_attachment':
            $clientId = (int)($_POST['client_id'] ?? 0);
            $oldName = basename($_POST['old_name'] ?? '');
            $newNameRaw = $_POST['new_name'] ?? '';

            if ($clientId <= 0 || $oldName === '' || trim($newNameRaw) === '') throw new Exception('Invalid parameters for rename.');

            $newBase = preg_replace('/\.[^.]+$/', '', $newNameRaw);
            $newName = preg_replace('/[^\w\s\.\-_]/u', '', $newBase) . '.pdf';

            $baseDir = __DIR__ . '/uploads/attachments/client_' . $clientId . '/';
            if (file_exists($baseDir . $newName)) throw new Exception('A file with the new name already exists.');

            if (rename($baseDir . $oldName, $baseDir . $newName)) {
                $stmt = $pdo->prepare('SELECT name FROM clients WHERE id = :id');
                $stmt->execute([':id' => $clientId]);
                $clientName = (string)($stmt->fetchColumn() ?? '');
                echo json_encode(['success' => true, 'file_name' => $newName, 'display_label' => formatAnnexureLabel($newName, $clientName)]);
            } else {
                throw new Exception('Filesystem rename failed.');
            }
            break;
    }
} catch (Throwable $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
exit;