document.addEventListener('DOMContentLoaded', function() {
    document.querySelectorAll('.action-dropdown, .scheme-input').forEach(function(element) {
        const eventType = element.classList.contains('action-dropdown') ? 'change' : 'blur';
        element.addEventListener(eventType, function() {
            const schemeId = element.getAttribute('data-scheme-id');
            const field = element.getAttribute('data-field') || 'action_step';
            const value = element.value.trim();
            if (schemeId) {
                const postBody = {
                    ajax_scheme: '1',
                    scheme_id: schemeId,
                };
                postBody[field] = value;
                fetch('view_report.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8'
                    },
                    body: new URLSearchParams(postBody)
                })
                .then(response => response.json())
                .then(data => {
                    if (!data.success) {
                        alert('Save failed: ' + (data.error || 'Unknown error'));
                    }
                })
                .catch(err => console.error(err));
            }
        });
    });
});
