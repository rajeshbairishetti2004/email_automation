document.addEventListener('DOMContentLoaded', function() {
    document.querySelectorAll('.signature-textarea').forEach(function(textarea) {
        textarea.addEventListener('blur', function() {
            const clientId = textarea.getAttribute('data-client-id');
            const field = textarea.getAttribute('data-field');
            const value = textarea.value.trim();
            if (clientId && field) {
                fetch('view_report.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8'
                    },
                    body: new URLSearchParams({
                        ajax: '1',
                        client_id: clientId,
                        field: field,
                        value: value
                    })
                })
                .then(response => response.json())
                .then(data => {
                    if (!data.success) {
                        alert('Save failed: ' + (data.error || 'Unknown error'));
                    }
                })
                .catch(err => {
                    console.error('Save error:', err);
                });
            }
        });
    });
});
