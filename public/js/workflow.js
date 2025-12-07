// Minimal workflow helpers used by the report page buttons.
function submitWorkflow(action) {
    if (!confirm("Are you sure you want to perform this action?")) return;
    const form = document.getElementById('reportForm');
    if (!form) {
        alert('Form not found.');
        return;
    }
    let workflowInput = document.getElementById('workflowActionInput');
    if (!workflowInput) {
        workflowInput = document.createElement('input');
        workflowInput.type = 'hidden';
        workflowInput.name = 'workflow_action';
        workflowInput.id = 'workflowActionInput';
        form.appendChild(workflowInput);
    }
    workflowInput.value = action;
    form.submit();
}

function openRejectModal() {
    const modal = document.getElementById('rejectModal');
    if (modal) modal.style.display = 'flex';
}

function closeRejectModal() {
    const modal = document.getElementById('rejectModal');
    if (modal) modal.style.display = 'none';
}

function submitRejection() {
    const commentEl = document.getElementById('rejectComment');
    if (!commentEl) { alert('Reject comment element not found'); return; }
    const comment = commentEl.value.trim();
    if (!comment) { alert('Please provide a comment to reject.'); return; }

    const form = document.getElementById('reportForm');
    if (!form) { alert('Form not found'); return; }

    let workflowInput = document.getElementById('workflowActionInput');
    if (!workflowInput) {
        workflowInput = document.createElement('input');
        workflowInput.type = 'hidden';
        workflowInput.name = 'workflow_action';
        workflowInput.id = 'workflowActionInput';
        form.appendChild(workflowInput);
    }

    let reviewCommentInput = document.getElementById('reviewCommentInput');
    if (!reviewCommentInput) {
        reviewCommentInput = document.createElement('input');
        reviewCommentInput.type = 'hidden';
        reviewCommentInput.name = 'review_comment';
        reviewCommentInput.id = 'reviewCommentInput';
        form.appendChild(reviewCommentInput);
    }

    workflowInput.value = 'review_not_ok';
    reviewCommentInput.value = comment;
    form.submit();
}
