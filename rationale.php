<?php
// rationale.php
// Rationale Editor - Single Block
// Features: Shared System Templates, Inline Save/Edit Form

// 1. Fetch ONLY Rationale Templates
$rationaleTemplates = $templates['rationale'] ?? [];

// 2. Current Rationale Text
$currentRationale = $rationaleStored ?? ''; 
$clientId = isset($clientId) ? (int)$clientId : (int)($_POST['client_id'] ?? 0);
?>

<div class="card rationale-card" style="margin-top:20px;">
    <label class="card-title">Rationale</label>

    <div id="rationale_controls" class="rationale-controls">
        <select id="rationale_template_selector" class="styled-input">
            <option value="0" data-content="">--- Select Rationale Template ---</option>
            <?php foreach ($rationaleTemplates as $tpl): ?>
                <option value="<?php echo (int)$tpl['id']; ?>" data-content="<?php echo htmlspecialchars($tpl['content']); ?>">
                    <?php echo htmlspecialchars($tpl['name']); ?>
                </option>
            <?php endforeach; ?>
        </select>

        <input type="text" id="rationale_template_name" placeholder="Template name (for save)" class="styled-input" style="display:none;">

        <!-- Three separate buttons: Edit, Save, Delete -->
        <button type="button" id="rationale_edit_btn" class="rationale-btn">Edit</button>
        <button type="button" id="rationale_save_btn" class="rationale-btn primary" disabled>Save</button>
        <button type="button" id="rationale_delete_btn" class="rationale-btn danger" disabled>Delete</button>
    </div>

    <div id="rationale_flash_container"></div>

    <textarea id="rationale_textarea" name="rationale" data-client-id="<?php echo $clientId; ?>" data-field="rationale_text" class="large-textarea" rows="8" placeholder="Write your rationale here..." readonly><?php echo htmlspecialchars($currentRationale); ?></textarea>

    <p class="rationale-hint">Changes saved automatically when you leave the textarea (or use Save for templates).</p>
</div>

<link rel="stylesheet" href="public/css/rationale.css">
<script src="public/js/rationale.js"></script>