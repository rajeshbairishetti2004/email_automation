<?php
// template_actions.php
// Handles workflow actions only

require_once 'db_config.php';

$ajax_action = $_POST['ajax_action'] ?? null;

// Check if this is a valid AJAX POST action
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !$ajax_action) {
    return;
}

header('Content-Type: application/json');

try {
    $pdo = getPdo();
    
    switch ($ajax_action) {
        /* ------------------------------------------------------------------
           WORKFLOW ACTIONS ONLY
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

        default:
            echo json_encode(['success' => false, 'error' => 'Unknown action: ' . $ajax_action]);
            break;
    }
} catch (Throwable $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
exit;