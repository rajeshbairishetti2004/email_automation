<?php
// template_actions.php
// Handles all AJAX actions related to RMs, Templates, and File Attachments.

require_once 'db_config.php';

// Ensure no accidental output before headers
ob_clean();
header('Content-Type: application/json');

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
// Get client ID from POST first (for uploads), then fallback to GET
$clientId = (int)($_POST['client_id'] ?? $_GET['id'] ?? 0);
$ajax_action = $_POST['ajax_action'] ?? null;

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !$ajax_action) {
    echo json_encode(['success' => false, 'error' => 'Invalid Request']);
    exit;
}

try {
    switch ($ajax_action) {
        /* Restored File Attachment Actions */
        case 'upload_attachment':
            if ($clientId <= 0) throw new Exception("Invalid Client ID.");

            // FETCH TARGET CLIENT NAME for security validation
            $stmtClient = $pdo->prepare("SELECT name FROM clients WHERE id = :id LIMIT 1");
            $stmtClient->execute([':id' => $clientId]);
            $targetClientName = $stmtClient->fetchColumn();
            
            if (!$targetClientName) throw new Exception("Client not found.");

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
                        
                        // Force .pdf extension and sanitize
                        $fileBase = preg_replace('/\.[^.]+$/', '', $rawName);
                        $fileName = preg_replace('/[^\w\s\._-]/u', '', $fileBase) . '.pdf';
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
                throw new Exception("No files were successfully uploaded.");
            }
            break;

        case 'rename_attachment':
            $oldName = basename($_POST['old_name'] ?? '');
            $newNameRaw = $_POST['new_name'] ?? '';

            if ($clientId <= 0 || empty($oldName) || empty($newNameRaw)) {
                throw new Exception('Invalid parameters for rename.');
            }

            $newName = preg_replace('/[^\w\s\.\-_]/u', '', preg_replace('/\.[^.]+$/', '', $newNameRaw)) . '.pdf';
            $baseDir = __DIR__ . '/uploads/attachments/client_' . $clientId . '/';
            
            if (!file_exists($baseDir . $oldName)) throw new Exception('Original file not found.');
            if (file_exists($baseDir . $newName)) throw new Exception('Target filename already exists.');

            if (rename($baseDir . $oldName, $baseDir . $newName)) {
                echo json_encode([
                    'success' => true, 
                    'file_name' => $newName,
                    'display_label' => formatAnnexureLabel($newName)
                ]);
            } else {
                throw new Exception('Rename failed.');
            }
            break;

        case 'delete_attachment':
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

        /* Standard Template Actions */
        case 'load_template':
            $templateId = (int)($_POST['template_id'] ?? 0);
            $stmt = $pdo->prepare("SELECT content FROM report_templates WHERE id = ?");
            $stmt->execute([$templateId]);
            $content = $stmt->fetchColumn();
            echo json_encode(['success' => true, 'content' => $content]);
            break;

        case 'delete_template':
            $templateId = (int)($_POST['template_id'] ?? 0);
            $stmt = $pdo->prepare("DELETE FROM report_templates WHERE id = ?");
            $stmt->execute([$templateId]);
            echo json_encode(['success' => true, 'message' => 'Deleted']);
            break;

        case 'save_template':
            $stmt = $pdo->prepare("INSERT INTO report_templates (name, section_type, content) VALUES (?, ?, ?)");
            $stmt->execute([$_POST['template_name'], $_POST['section_type'], $_POST['template_content']]);
            echo json_encode(['success' => true]);
            break;

        default:
            echo json_encode(['success' => false, 'error' => 'Unknown action: ' . $ajax_action]);
            break;
    }
} catch (Throwable $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
exit;