<?php
// Ensure variables exist (view_report.php should define $clientId and $reportState)
$clientId    = isset($clientId) ? (int)$clientId : (int)($_POST['client_id'] ?? 0);
$reportState = $reportState ?? ($client['report_state'] ?? 'draft');

// Allow editing unless the report was already sent
$canEditAttachments = ($reportState !== 'sent');

$attDir = __DIR__ . '/uploads/attachments/client_' . $clientId;
$existingFiles = [];
if (is_dir($attDir)) {
    $existingFiles = array_values(array_diff(scandir($attDir), ['.', '..']));
}
?>
<div class="card report-attachments-card" style="margin-top: 20px; border-left: 4px solid #17a2b8;">
    <label class="card-title">📂 Report Attachments</label>

    <?php if ($canEditAttachments): ?>
        <div style="margin:10px 0; display:flex; gap:10px; align-items:center;">
            <input type="file" id="ajax_attachment_upload" multiple style="flex:1;" onchange="uploadAttachment()">
            <span id="upload_spinner" style="display:none; margin-left:8px; color:#0288D1;">⏳ Uploading...</span>
        </div>
    <?php endif; ?>

    <ul id="attachment_list" style="list-style: none; padding: 0; margin: 0;">
        <?php if (empty($existingFiles)): ?>
            <li style="color: #777; font-style: italic; padding:10px 0;">No attachments uploaded yet.</li>
        <?php else: ?>
            <?php foreach ($existingFiles as $file): ?>
                <li style="margin-bottom: 8px; border-bottom: 1px solid #eee; padding-bottom: 5px; display:flex; justify-content:space-between; align-items:center;">
                    <div style="display:flex; gap:8px; align-items:center;">
                        <span>📎</span>
                        <a href="<?php echo 'uploads/attachments/client_' . (int)$clientId . '/' . rawurlencode($file); ?>" target="_blank" style="color:#0056b3; text-decoration:none;">
                            <strong><?php echo htmlspecialchars($file); ?></strong>
                        </a>
                    </div>
                    <?php if ($canEditAttachments): ?>
                        <a href="#" onclick="deleteAttachment('<?php echo addslashes($file); ?>', this); return false;" style="color: red; text-decoration: none; font-size: 12px;">🗑 Delete</a>
                    <?php else: ?>
                        <span style="font-size:12px; color:#999;">(Read only)</span>
                    <?php endif; ?>
                </li>
            <?php endforeach; ?>
        <?php endif; ?>
    </ul>

    <p style="font-size: 11px; color: #666; margin-top:8px;">Note: Files uploaded here will be automatically attached to the final email.</p>
</div>

<script src="public/js/report_attachments.js"></script>
