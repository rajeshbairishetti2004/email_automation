function uploadAttachment() {
    const fileInput = document.getElementById('ajax_attachment_upload');
    const files = fileInput.files;
    const clientId = document.querySelector('.client-report').getAttribute('data-client-id');
    const list = document.getElementById('attachment_list');
    if (files.length === 0) { alert("Please select at least one file."); return; }
    const formData = new FormData();
    formData.append('ajax_action', 'upload_attachment');
    formData.append('client_id', clientId);
    for (let i = 0; i < files.length; i++) {
        formData.append('files[]', files[i]);
    }
    document.getElementById('upload_spinner').style.display = 'inline';
    fetch('template_actions.php', {
        method: 'POST',
        body: formData
    })
    .then(response => response.json())
    .then(data => {
        document.getElementById('upload_spinner').style.display = 'none';
        if (data.success && data.files) {
            const emptyMsg = Array.from(list.children).find(li => li.textContent.includes('No attachments'));
            if (emptyMsg) emptyMsg.remove();
            data.files.forEach(fileName => {
                const li = document.createElement('li');
                li.style.cssText = "margin-bottom: 8px; border-bottom: 1px solid #eee; padding-bottom: 5px; display: flex; justify-content: space-between;";
                li.innerHTML = `
                    <span>📎 <strong>${fileName}</strong></span>
                    <a href="#" onclick="deleteAttachment('${fileName}', this); return false;" style="color: red; text-decoration: none; font-size: 12px;">🗑 Delete</a>
                `;
                list.appendChild(li);
            });
            fileInput.value = '';
        } else {
            alert('Error: ' + (data.error || 'Unknown error'));
        }
    })
    .catch(err => {
        document.getElementById('upload_spinner').style.display = 'none';
        alert('Upload error. Please try again.');
    });
}

function deleteAttachment(fileName, el) {
    if(!confirm("Are you sure you want to delete " + fileName + "?")) return;
    const clientId = document.querySelector('.client-report').getAttribute('data-client-id');
    fetch('template_actions.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
        body: new URLSearchParams({
            ajax_action: 'delete_attachment',
            client_id: clientId,
            file_name: fileName
        })
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            if (el) {
                const li = el.closest('li');
                if (li) li.remove();
            } else {
                window.location.reload();
            }
        } else {
            alert('Error: ' + data.error);
        }
    })
    .catch(err => alert('Delete error.'));
}
