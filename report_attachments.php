<?php
// ...existing code for $canEditAttachments, $attDir, $existingFiles...
?>
<div class="card report-attachments-card" style="margin-top: 20px; border-left: 4px solid #17a2b8;">
    <label class="card-title">📂 Report Attachments</label>
    <?php if ($canEditAttachments): ?>
        <div style="margin-bottom: 15px; padding: 10px; background: #eefbff; border-radius: 4px;">
            <input type="file" id="ajax_attachment_upload" multiple style="width: auto;" onchange="uploadAttachment()">
            <span id="upload_spinner" style="display:none; margin-left: 10px; font-weight: bold; color: #0288D1;">
                ⏳ Uploading...
            </span>
        </div>
    <?php endif; ?>
    <ul id="attachment_list" style="list-style: none; padding: 0;">
        <?php if (empty($existingFiles)): ?>
            <li style="color: #777; font-style: italic;">No attachments uploaded yet.</li>
        <?php else: ?>
            <?php foreach ($existingFiles as $file): ?>
                <li style="margin-bottom: 8px; border-bottom: 1px solid #eee; padding-bottom: 5px; display: flex; justify-content: space-between;">
                    <span>📎 <strong><?php echo htmlspecialchars($file); ?></strong></span>
                    <?php if ($canEditAttachments): ?>
                        <a href="#" onclick="deleteAttachment('<?php echo htmlspecialchars($file); ?>', this); return false;" style="color: red; text-decoration: none; font-size: 12px;">🗑 Delete</a>
                    <?php else: ?>
                        <span style="font-size: 11px; color: #999;">(Read Only)</span>
                    <?php endif; ?>
                </li>
            <?php endforeach; ?>
        <?php endif; ?>
    </ul>
    <p style="font-size: 11px; color: #666;">Note: Files uploaded here will be automatically attached to the final email.</p>
</div>
<script src="public/js/report_attachments.js"></script>
