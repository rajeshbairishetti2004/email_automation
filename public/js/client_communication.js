document.addEventListener('DOMContentLoaded', function() {
    const resizableTextareas = document.querySelectorAll('.large-textarea, .seamless-input, .rat-main-textarea');
    resizableTextareas.forEach(textarea => {
        autoResizeTextarea(textarea);
        textarea.addEventListener('input', function() { autoResizeTextarea(this); });
        window.addEventListener('resize', function() { autoResizeTextarea(textarea); });
    });
    document.querySelectorAll('.large-textarea').forEach(function(textarea) {
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
    // Handle disappearing flash messages
    const flashMessages = document.querySelectorAll('.flash-message');
    flashMessages.forEach(function(message) {
        setTimeout(() => {
            message.style.opacity = '0';
            message.style.marginTop = '-50px'; 
        }, 3000); 
        setTimeout(() => {
            message.remove();
        }, 3500); 
    });

    function bindTemplate(selectorId, textareaId) {
        const selector = document.getElementById(selectorId);
        const textarea = document.getElementById(textareaId);
        if (!selector || !textarea) return;
        selector.addEventListener('change', function() {
            const content = (selector.options[selector.selectedIndex] || {}).getAttribute('data-content') || '';
            textarea.value = content;
            textarea.dispatchEvent(new Event('input'));
        });
    }

    bindTemplate('greeting_template_selector', 'greeting_textarea');
    bindTemplate('intro_template_selector', 'intro_textarea');
    bindTemplate('closing_template_selector', 'closing_textarea');
});
