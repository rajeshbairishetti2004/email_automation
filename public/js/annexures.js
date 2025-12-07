// No block-specific JS for annexures
document.addEventListener('DOMContentLoaded', function() {
    // Filtering logic
    const filterInput = document.getElementById('annexureFilter');
    const annexureList = document.querySelector('.annexures-list');
    if (filterInput && annexureList) {
        filterInput.addEventListener('input', function() {
            const filter = filterInput.value.toLowerCase();
            annexureList.querySelectorAll('li').forEach(li => {
                const text = li.textContent.toLowerCase();
                li.style.display = text.includes(filter) ? '' : 'none';
            });
        });
    }

    // Preview logic (assume each li has a .preview-btn and data-filename)
    annexureList && annexureList.querySelectorAll('.preview-btn').forEach(btn => {
        btn.addEventListener('click', function(e) {
            e.preventDefault();
            const fileName = btn.closest('li').getAttribute('data-filename');
            if (fileName) {
                // Show preview modal (simple implementation)
                let modal = document.getElementById('annexurePreviewModal');
                if (!modal) {
                    modal = document.createElement('div');
                    modal.id = 'annexurePreviewModal';
                    modal.style.cssText = 'position:fixed;top:0;left:0;width:100vw;height:100vh;background:rgba(0,0,0,0.7);display:flex;align-items:center;justify-content:center;z-index:9999;';
                    modal.innerHTML = `
                        <div style="background:#fff;padding:20px;border-radius:8px;max-width:80vw;max-height:80vh;overflow:auto;position:relative;">
                            <button id="closeAnnexurePreview" style="position:absolute;top:10px;right:10px;">Close</button>
                            <iframe src="uploads/attachments/client_${annexureList.getAttribute('data-client-id')}/${fileName}" style="width:70vw;height:70vh;border:none;"></iframe>
                        </div>
                    `;
                    document.body.appendChild(modal);
                    document.getElementById('closeAnnexurePreview').onclick = function() {
                        modal.remove();
                    };
                }
            }
        });
    });
});
