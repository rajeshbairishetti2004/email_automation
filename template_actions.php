<?php
// template_actions.php
// Handles all AJAX actions related to RMs, Workflow, and File Attachments.

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

if (!function_exists('getRelationshipManagerCount')) {
    function getRelationshipManagerCount() {
        $pdo = getPdo();
        $stmt = $pdo->query("SELECT COUNT(*) FROM relationship_managers");
        return (int)$stmt->fetchColumn();
    }
}

if (!function_exists('generateSignatureBlock')) {
    function generateSignatureBlock($rm) {
        $name = $rm['name'] ?? 'Relationship Manager';
        $designation = $rm['designation'] ?? 'Relationship Manager';
        $mobile = $rm['mobile'] ?? 'N/A';
        $email = $rm['email'] ?? 'N/A';
        
        return "Regards,\n\n{$name},\n{$designation},\nFinance Doctor Private Limited.\n\nMobile - {$mobile}.\nEmail - {$email}\nUrl: www.financedoctor.in";
    }
}

$pdo = getPdo();
$clientId = (int)($_POST['client_id'] ?? ($_GET['id'] ?? 0)); // Accept from POST or GET for flexibility
$ajax_action = $_POST['ajax_action'] ?? null;

// Check if this is a valid AJAX POST action
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !$ajax_action) {
    exit;
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

        // --- WORKFLOW ACTIONS (AJAX only, not used in new workflow, but keep for legacy) ---
        case 'save_draft':
        case 'ready_for_review':
        case 'approve_review':
        case 'review_not_ok':
        case 'email_sent':
            // These should NOT be called directly anymore, but keep for AJAX fallback
            $clientId = (int)($_POST['client_id'] ?? 0);
            if ($clientId <= 0) throw new Exception("Invalid Client ID.");
            if ($ajax_action === 'save_draft') {
                $pdo->prepare("UPDATE clients SET report_state = 'draft', draft_at = NOW(), review_not_ok = 0, review_comment = NULL WHERE id = :id")->execute([':id' => $clientId]);
                echo json_encode(['success' => true, 'message' => 'Draft saved successfully.', 'updated_state' => 'draft']);
            } elseif ($ajax_action === 'ready_for_review') {
                $pdo->prepare("UPDATE clients SET report_state = 'ready', ready_at = NOW(), review_not_ok = 0, review_comment = NULL WHERE id = :id")->execute([':id' => $clientId]);
                echo json_encode(['success' => true, 'message' => 'Report marked Ready for Review.', 'updated_state' => 'ready']);
            } elseif ($ajax_action === 'approve_review') {
                $pdo->prepare("UPDATE clients SET report_state = 'reviewed', reviewed_at = NOW(), review_not_ok = 0, review_comment = NULL WHERE id = :id")->execute([':id' => $clientId]);
                echo json_encode(['success' => true, 'message' => 'Report Approved (Reviewed).', 'updated_state' => 'reviewed']);
            } elseif ($ajax_action === 'review_not_ok') {
                $comment = trim($_POST['review_comment'] ?? '');
                if (empty($comment)) throw new Exception("A comment is required for rejection.");
                $pdo->prepare("UPDATE clients SET report_state = 'draft', review_not_ok = 1, review_comment = :comment WHERE id = :id")->execute([':id' => $clientId, ':comment' => $comment]);
                echo json_encode(['success' => true, 'message' => 'Report rejected and moved back to Draft.', 'updated_state' => 'draft']);
            } elseif ($ajax_action === 'email_sent') {
                $pdo->prepare("UPDATE clients SET report_state = 'sent', sent_at = NOW() WHERE id = :id")->execute([':id' => $clientId]);
                echo json_encode(['success' => true, 'message' => 'Report marked as Sent.', 'updated_state' => 'sent']);
            }
            break;

        /* ------------------------------------------------------------------
           ATTACHMENT MANAGEMENT
           ------------------------------------------------------------------ */

    }
} catch (Throwable $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
exit;