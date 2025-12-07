document.addEventListener('DOMContentLoaded', function() {
    document.querySelectorAll('.delete-template-btn').forEach(btn => {
        btn.addEventListener('click', function(e) {
            e.preventDefault();
            const selectorId = this.getAttribute('data-template-id-attr');
            const templateSection = this.getAttribute('data-template-section');
            const selector = document.getElementById(selectorId);
            const templateId = selector.value;
            if (templateId === '0' || templateId === 0) {
                showContextualFlash('error', '❌ Please select a template name to delete.', `${templateSection}_flash_container`);
                return;
            }
            const templateName = selector.options[selector.selectedIndex].text;
            const clientId = document.querySelector('input[name="client_id"]').value;
            if (!confirm(`Are you sure you want to delete the template "${templateName}"?`)) return;
            fetch('view_report.php?id=' + encodeURIComponent(clientId), {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
                body: new URLSearchParams({
                    ajax_action: 'delete_template',
                    template_id: templateId
                })
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    window.location.href = window.location.href.split('?')[0] + '?id=' + clientId + '&deleted=1';
                } else {
                    showContextualFlash('error', `❌ Failed to delete template: ${data.error}`, `${templateSection}_flash_container`);
                }
            })
            .catch(err => {
                showContextualFlash('error', 'Network error during template deletion.', `${templateSection}_flash_container`);
            });
        });
    });
});
