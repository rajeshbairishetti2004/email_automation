<?php
// signature.php
// Renders the Signature / Closing Note section.
// Requires: $clientId, $signatureBlock, $DEFAULT_SIGNATURE (from view_report.php scope)

$clientId = (int)($clientId ?? 0);
$signatureBlock = $signatureBlock ?? ''; // Use current stored signature, if available

// Check if the current signature block is empty (meaning it hasn't been explicitly saved)
// If it's empty, use the dynamic default from view_report.php's logic.
if (empty(trim($signatureBlock))) {
    // The $DEFAULT_SIGNATURE is a pre-calculated string from view_report.php (using logged-in user details)
    $signatureBlock = $DEFAULT_SIGNATURE ?? 'No signature details available.';
}
?>

<div class="card" style="margin-top: 20px;">
    <label class="card-title">Signature / Closing Note</label>
    
    <div id="signature_flash_container" class="signature-flash-container"></div>
    
    <textarea name="signature_block" 
              id="signature_block"
              class="large-textarea" 
              data-field="signature_block" 
              data-client-id="<?php echo (int)$clientId; ?>"
              placeholder="Enter signature here..."
              style="min-height: 150px;"><?php echo htmlspecialchars(trim($signatureBlock)); ?></textarea>

    <p style="font-size: 12px; color: #666; margin-top: 8px;">
        💡 You can edit this signature for this specific report. It will auto-save when you click outside.
    </p>
</div>