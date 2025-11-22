<?php
// signature.php
// Renders the Signature / Closing Note section with RM selector and forms.
// Requires: $clientId, $signatureBlock, $allRMs

$signature_flash_container = isset($_GET['rm_added']) || isset($_GET['rm_add_error']);
?>

<style>
    /* CSS specific to Signature (styles are mostly shared) */
    .rm-action-button {
        display: inline-block;
        padding: 4px 8px;
        background-color: #007bff;
        color: white !important;
        border-radius: 4px;
        text-decoration: none;
        font-size: 13px;
        margin-left: 10px;
        transition: background-color 0.2s;
        line-height: normal;
    }
    .delete-rm-btn {
        color: red !important;
        font-weight: 600;
        text-decoration: none;
        padding: 2px 4px;
        border: 1px solid #f0f0f0;
        border-radius: 3px;
        cursor: pointer;
    }
    .rm-list-item {
        display: flex;
        justify-content: space-between;
        align-items: center;
        padding: 5px 0;
        border-bottom: 1px dashed #eee;
    }
</style>

<div class="card" style="margin-top: 20px;">
    <label class="card-title">Signature / Closing Note</label>
    
    <?php if (count($allRMs) === 0): ?>
        <div class="signature-flash-container">
            <div class="flash-message flash-error" style="opacity: 1; margin-top: 5px;">
                You must **add a Relationship Manager** before using the dynamic signature feature.
            </div>
        </div>
        <form method="POST" style="padding: 15px; border: 1px dashed #DDD;">
            <input type="hidden" name="action_add_rm" value="1">
            <input type="hidden" name="client_id" value="<?php echo (int)$clientId; ?>">
            <h4 style="margin-top: 0; margin-bottom: 10px;">Add New Relationship Manager</h4>
            
            <input type="text" name="rm_name" placeholder="Name (e.g., Vivek Sharma)" required style="margin-bottom: 8px;">
            <input type="text" name="rm_designation" placeholder="Designation (e.g., Relationship Manager)" value="Relationship Manager" style="margin-bottom: 8px;">
            <input type="text" name="rm_mobile" placeholder="Mobile (e.g., 888 4091 666)" required style="margin-bottom: 8px;">
            <input type="email" name="rm_email" placeholder="Email (e.g., vivek.sharma@...)" required style="margin-bottom: 15px;">
            
            <button type="submit" class="rm-action-button" style="width: auto;">
                ➕ Add & Set as Default
            </button>
            <p style="font-size: 12px; color: #999; margin-top: 5px;">
                The first RM added will be set as the default automatically.
            </p>
        </form>

    <?php else: ?>
        <div id="signature_flash_container" class="signature-flash-container">
            <?php if (isset($_GET['rm_added'])): ?>
                <div class="flash-message flash-success" style="opacity: 1;">✅ Relationship Manager added successfully!</div>
            <?php elseif (isset($_GET['rm_add_error'])): ?>
                <div class="flash-message flash-error" style="opacity: 1;">❌ Failed to add RM: <?php echo htmlspecialchars($_GET['rm_add_error']); ?></div>
            <?php endif; ?>
        </div>

        <div style="margin-bottom: 10px; display: flex; align-items: center; flex-wrap: wrap;">
            <label for="rm_selector" style="font-size: 14px; font-weight: normal; margin-top: 0; margin-right: 10px;">
                Select Default RM:
            </label>
            <select id="rm_selector" data-client-id="<?php echo (int)$clientId; ?>" style="width: 160px; padding: 5px;">
                <option value="0">--- Use Saved Text ---</option>
                <?php foreach ($allRMs as $currentRM): ?>
                    <option value="<?php echo (int)$currentRM['id']; ?>"
                            data-name="<?php echo htmlspecialchars($currentRM['name']); ?>">
                        <?php echo htmlspecialchars($currentRM['name']); ?>
                        <?php echo ($currentRM['is_default'] == 1) ? ' (Default)' : ''; ?>
                    </option>
                <?php endforeach; ?>
            </select>
            <a href="#" id="add_rm_toggle_btn" class="rm-action-button" style="margin-left: 10px;">
                + Add New RM
            </a>
            <a href="#" id="view_rm_list_toggle" class="rm-action-button" style="margin-left: 10px;">
                View/Delete RMs
            </a>
        </div>
        
        <div id="rm_management_list" style="display: none; padding: 15px; border: 1px dashed #DDD; margin-bottom: 15px;">
            <h4 style="margin-top: 0; margin-bottom: 10px;">Manage Relationship Managers</h4>
            <ul style="list-style: none; padding: 0; margin: 0;">
                <?php foreach ($allRMs as $rmItem): ?>
                    <li class="rm-list-item" data-rm-id="<?php echo (int)$rmItem['id']; ?>">
                        <span>
                            <strong><?php echo htmlspecialchars($rmItem['name']); ?></strong>
                            (<?php echo htmlspecialchars($rmItem['designation']); ?>)
                            <?php echo ($rmItem['is_default'] == 1) ? ' <span style="color: green; font-weight: 600;">(Default)</span>' : ''; ?>
                        </span>
                        <a href="#" 
                           class="delete-rm-btn" 
                           data-rm-id="<?php echo (int)$rmItem['id']; ?>" 
                           data-rm-name="<?php echo htmlspecialchars($rmItem['name']); ?>" 
                           title="Delete this Relationship Manager">
                            [Delete]
                        </a>
                    </li>
                <?php endforeach; ?>
            </ul>
        </div>

        <div id="add_rm_container" style="display: none; padding: 15px; border: 1px dashed #DDD; margin-bottom: 15px;">
            <form method="POST">
                <input type="hidden" name="action_add_rm" value="1">
                <input type="hidden" name="client_id" value="<?php echo (int)$clientId; ?>">
                <h4 style="margin-top: 0; margin-bottom: 10px;">Add New Relationship Manager</h4>
                
                <input type="text" name="rm_name" placeholder="Name (Required)" required style="margin-bottom: 8px;">
                <input type="text" name="rm_designation" placeholder="Designation" value="Relationship Manager" style="margin-bottom: 8px;">
                <input type="text" name="rm_mobile" placeholder="Mobile (Required)" required style="margin-bottom: 8px;">
                <input type="email" name="rm_email" placeholder="Email (Required)" required style="margin-bottom: 15px;">
                <button type="submit" class="rm-action-button" style="width: auto;">
                    ➕ Add RM
                </button>
            </form>
        </div>

        <textarea name="signature_block"
                class="large-textarea" 
                data-field="signature" 
                data-client-id="<?php echo (int)$clientId; ?>"
                id="signature_textarea"
                placeholder="Write your signature block here..."><?php echo htmlspecialchars($signatureBlock); ?></textarea>
    <?php endif; ?>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    // --- RM Logic ---
    // Toggle Add New RM Form visibility
    const addRmToggleBtn = document.getElementById('add_rm_toggle_btn');
    if (addRmToggleBtn) {
        addRmToggleBtn.addEventListener('click', function(e) {
            e.preventDefault();
            const container = document.getElementById('add_rm_container');
            container.style.display = (container.style.display === 'none' || container.style.display === '') ? 'block' : 'none';
        });
    }

    // Toggle RM Management List visibility
    const viewRmListToggle = document.getElementById('view_rm_list_toggle');
    if (viewRmListToggle) {
        viewRmListToggle.addEventListener('click', function(e) {
            e.preventDefault();
            const list = document.getElementById('rm_management_list');
            if (list.style.display === 'none' || list.style.display === '') {
                list.style.display = 'block';
                this.textContent = 'Hide RMs';
            } else {
                list.style.display = 'none';
                this.textContent = 'View/Delete RMs';
            }
        });
    }

    // DELETE RM LOGIC
    document.addEventListener('click', function(e) {
        if (e.target && e.target.classList.contains('delete-rm-btn')) {
            e.preventDefault();
            const rmId = e.target.getAttribute('data-rm-id');
            const rmName = e.target.getAttribute('data-rm-name');
            const clientId = document.querySelector('input[name="client_id"]').value;

            if (!confirm(`Are you sure you want to delete Relationship Manager: ${rmName}? This action cannot be undone.`)) {
                return;
            }

            fetch('view_report.php?id=' + encodeURIComponent(clientId), {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8'
                },
                body: new URLSearchParams({
                    ajax_action: 'delete_rm',
                    rm_id: rmId
                })
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    // showContextualFlash is defined in view_report.php
                    if (typeof showContextualFlash === 'function') {
                        showContextualFlash('success', `✅ RM ${rmName} deleted. Reloading list...`, 'signature_flash_container');
                    }
                    window.location.reload(); 
                } else {
                    if (typeof showContextualFlash === 'function') {
                        showContextualFlash('error', `❌ Failed to delete RM: ${data.error}`, 'signature_flash_container');
                    }
                }
            })
            .catch(err => {
                if (typeof showContextualFlash === 'function') {
                    showContextualFlash('error', 'Network error during deletion.', 'signature_flash_container');
                }
                console.error('Delete Error:', err);
            });
        }
    });

    // RM Selector Change (Loads RM signature into the textarea)
    const rmSelector = document.getElementById('rm_selector');
    if (rmSelector) {
        rmSelector.addEventListener('change', function() {
            const rmId = this.value;
            const textarea = document.getElementById('signature_textarea');
            const clientId = document.querySelector('input[name="client_id"]').value;
            
            if (rmId > 0) {
                fetch('view_report.php?id=' + encodeURIComponent(clientId), {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
                    body: new URLSearchParams({ ajax_action: 'load_rm', rm_id: rmId })
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        textarea.value = data.signature_block;
                        if (typeof showContextualFlash === 'function') {
                            showContextualFlash('success', `✅ Loaded signature for ${data.rm_name}. Auto-saving...`, 'signature_flash_container');
                        }
                        // Manually trigger the auto-save mechanism for the signature field
                        textarea.dispatchEvent(new Event('blur'));
                    } else {
                        if (typeof showContextualFlash === 'function') {
                            showContextualFlash('error', `❌ Error loading signature: ${data.error}`, 'signature_flash_container');
                        }
                    }
                })
                .catch(err => {
                    if (typeof showContextualFlash === 'function') {
                        showContextualFlash('error', 'Network error loading RM data.', 'signature_flash_container');
                    }
                    console.error('RM Load Error:', err);
                });
            } else {
                // Option "--- Use Saved Text ---" selected (ID 0). 
                if (typeof showContextualFlash === 'function') {
                    showContextualFlash('success', 'Using client-specific saved signature.', 'signature_flash_container');
                }
                // Trigger blur to save the text currently in the box (if edited)
                textarea.dispatchEvent(new Event('blur'));
            }
        });
    }
});
</script>