<?php
// meeting_tracker.php
require_once 'db_config.php';
require_once 'auth.php';

// --- AJAX HANDLER: Save Meeting Info ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'save_meeting_status') {
    header('Content-Type: application/json');
    $pdo = getPdo();
    $clientId = (int)$_POST['client_id'];
    $status   = $_POST['status']; // 'yes' or 'no'
    $remarks  = trim($_POST['remarks'] ?? '');
    $userId   = (int)($_SESSION['user_id'] ?? 0);

    // Validate required fields
    if ($clientId <= 0 || !in_array($status, ['yes', 'no'])) {
        echo json_encode(['success' => false, 'error' => 'Invalid client or status']);
        exit;
    }

    try {
        $pdo->beginTransaction();

        // 1. Update the client record
        $stmt = $pdo->prepare("UPDATE clients SET meeting_status = :status, meeting_remarks = :remarks WHERE id = :id");
        if (!$stmt->execute([':status' => $status, ':remarks' => $remarks, ':id' => $clientId])) {
            throw new Exception('Failed to update client meeting status');
        }

        // 2. Log the meeting update
        $log = $pdo->prepare("INSERT INTO meeting_logs (client_id, status, remarks, updated_by) VALUES (?, ?, ?, ?)");
        if (!$log->execute([$clientId, $status, $remarks, $userId])) {
            throw new Exception('Failed to insert meeting log');
        }

        $pdo->commit();
        echo json_encode(['success' => true]);
    } catch (Exception $e) {
        $pdo->rollBack();
        error_log('Meeting Tracker Error: ' . $e->getMessage());
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    exit;
}
?>

<div id="meetingPromptOverlay" class="modal-overlay" style="display:none; background: rgba(0,0,0,0.7); z-index: 9999;">
    <div class="modal-box" style="border-top: 5px solid #0288D1; width: 450px;">
        <h3 style="color: #0288D1; margin-top: 0;"><i class="fa-solid fa-handshake"></i> Report Sent!</h3>
        <p style="font-size: 15px; font-weight: 600;">Has the client meeting been completed?</p>
        
        <div style="display: flex; gap: 10px; margin: 15px 0;">
            <button type="button" class="wf-btn btn-approve" id="btnMeetingYes" style="flex:1; padding: 12px;">Yes, Completed</button>
            <button type="button" class="wf-btn btn-reject" id="btnMeetingNo" style="flex:1; padding: 12px;">No / Scheduled Later</button>
        </div>

        <div id="remarksContainer" style="margin-top: 15px;">
            <label style="font-size: 12px; color: #666;">Meeting Remarks / Discussion Points:</label>
            <textarea id="meetingRemarks" class="large-textarea" style="min-height: 80px; margin-top: 5px;" placeholder="What was discussed?"></textarea>
        </div>

        <div class="modal-buttons">
            <button type="button" class="modal-btn modal-btn-confirm" onclick="submitMeetingData()">Save Meeting Status</button>
        </div>
    </div>
</div>

<script>
let selectedMeetingStatus = 'pending';

document.addEventListener('DOMContentLoaded', function() {
    const btnYes = document.getElementById('btnMeetingYes');
    const btnNo = document.getElementById('btnMeetingNo');

    if(btnYes && btnNo) {
        btnYes.onclick = () => {
            selectedMeetingStatus = 'yes';
            btnYes.style.transform = "scale(1.05)";
            btnYes.style.boxShadow = "0 0 10px rgba(40, 167, 69, 0.5)";
            btnNo.style.opacity = "0.5";
            btnNo.style.transform = "scale(1)";
        };
        btnNo.onclick = () => {
            selectedMeetingStatus = 'no';
            btnNo.style.transform = "scale(1.05)";
            btnNo.style.boxShadow = "0 0 10px rgba(220, 53, 69, 0.5)";
            btnYes.style.opacity = "0.5";
            btnYes.style.transform = "scale(1)";
        };
    }

    // Automatically trigger this prompt if the URL contains "sent=1"
    const urlParams = new URLSearchParams(window.location.search);
    if (urlParams.get('sent') === '1') {
        document.getElementById('meetingPromptOverlay').style.display = 'flex';
    }
});

function submitMeetingData() {
    if (selectedMeetingStatus === 'pending') {
        alert("Please select Yes or No for the meeting status.");
        return;
    }

    const clientId = new URLSearchParams(window.location.search).get('id');
    const remarks = document.getElementById('meetingRemarks').value;

    fetch('meeting_tracker.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: new URLSearchParams({
            action: 'save_meeting_status',
            client_id: clientId,
            status: selectedMeetingStatus,
            remarks: remarks
        })
    })
    .then(res => res.json())
    .then(data => {
        if (data.success) {
            document.getElementById('meetingPromptOverlay').style.display = 'none';
            showToast("Meeting status updated successfully!");
        } else {
            alert("Error: " + data.error);
        }
    });
}
</script>