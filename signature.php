<?php
// signature.php
// Renders the Signature / Closing Note section with static details of the logged-in user.
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

<style>
    /* CSS specific to Signature */
    .signature-card {
        padding: 15px;
        border: 1px solid #ddd;
        border-radius: 8px;
        background-color: #fff;
        box-shadow: 0 2px 4px rgba(0,0,0,0.05);
    }
    .signature-content {
        /* Ensure the content uses line breaks correctly */
        white-space: pre-wrap; 
        font-family: 'Inter', sans-serif;
        font-size: 14px;
        color: #333;
        padding: 5px;
    }
    .card-title {
        font-size: 16px;
        font-weight: 600;
        color: #0288D1;
        display: block;
        margin-bottom: 10px;
    }
</style>

<div class="card" style="margin-top: 20px;">
    <label class="card-title">Signature / Closing Note</label>
    
    <div id="signature_flash_container" class="signature-flash-container">
        </div>
    
    <div class="signature-card">
<div class="signature-content">
    <?php echo htmlspecialchars(trim($signatureBlock)); ?>
</div>
    </div>
    
    </div>