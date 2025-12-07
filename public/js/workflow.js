function submitWorkflow(action) {
    if(!confirm("Are you sure you want to perform this action?")) return;
    document.getElementById('workflowActionInput').value = action;
    document.getElementById('reportForm').submit();
}

function openRejectModal() {
    document.getElementById('rejectModal').style.display = 'flex';
}
function closeRejectModal() {
    document.getElementById('rejectModal').style.display = 'none';
}
function submitRejection() {
    const comment = document.getElementById('rejectComment').value.trim();
    if(!comment) {
        alert("Comment is required for rejection.");
        return;
    }
    document.getElementById('workflowActionInput').value = 'review_not_ok';
    document.getElementById('reviewCommentInput').value = comment;
    document.getElementById('reportForm').submit();
}
