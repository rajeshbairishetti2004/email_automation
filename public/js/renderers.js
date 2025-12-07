// Example: Toggle visibility of annexures section
document.addEventListener('DOMContentLoaded', function() {
    const annexureHeader = document.querySelector('.client-report h3');
    const annexureList = document.querySelector('.client-report ul');
    if (annexureHeader && annexureList) {
        annexureHeader.style.cursor = 'pointer';
        annexureHeader.addEventListener('click', function() {
            annexureList.style.display = annexureList.style.display === 'none' ? '' : 'none';
        });
    }
});

// Example: Copy signature block to clipboard
document.addEventListener('DOMContentLoaded', function() {
    const sigTextarea = document.querySelector('textarea[name="signature_block_display"]');
    if (sigTextarea) {
        sigTextarea.addEventListener('dblclick', function() {
            sigTextarea.select();
            document.execCommand('copy');
            alert('Signature copied to clipboard!');
        });
    }
});
