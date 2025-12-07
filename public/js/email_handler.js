document.addEventListener('DOMContentLoaded', function() {
    // Validate email fields before submitting
    const form = document.getElementById('sendEmailForm');
    if (form) {
        form.addEventListener('submit', function(e) {
            const toEmail = form.querySelector('#recipient_email');
            const fromEmail = form.querySelector('#from_email');
            if (!toEmail.value.match(/^[^@]+@[^@]+\.[^@]+$/)) {
                alert('Please enter a valid recipient email address.');
                e.preventDefault();
                return;
            }
            if (!fromEmail.value.match(/^[^@]+@[^@]+\.[^@]+$/)) {
                alert('Please select a valid sender email address.');
                e.preventDefault();
                return;
            }
        });
    }

    // Preview selected attachments
    const input = document.getElementById('email_attachments_input');
    const list = document.getElementById('selected_attachment_list');
    if (input && list) {
        input.addEventListener('change', function() {
            list.innerHTML = '';
            Array.from(input.files).forEach((file, idx) => {
                const li = document.createElement('li');
                li.style.cssText = "margin-bottom: 6px; display: flex; align-items: center;";
                li.innerHTML = `<span>📎 <strong>${file.name}</strong></span>
                    <a href="#" style="color:red; margin-left:10px; font-size:12px;" onclick="removeSelectedFile(${idx});return false;">🗑 Remove</a>`;
                list.appendChild(li);
            });
        });
    }
});

// Remove file from input (by recreating FileList)
function removeSelectedFile(idx) {
    const input = document.getElementById('email_attachments_input');
    const list = document.getElementById('selected_attachment_list');
    const dt = new DataTransfer();
    Array.from(input.files).forEach((file, i) => {
        if (i !== idx) dt.items.add(file);
    });
    input.files = dt.files;
    // Refresh the list
    list.innerHTML = '';
    Array.from(input.files).forEach((file, idx) => {
        const li = document.createElement('li');
        li.style.cssText = "margin-bottom: 6px; display: flex; align-items: center;";
        li.innerHTML = `<span>📎 <strong>${file.name}</strong></span>
            <a href="#" style="color:red; margin-left:10px; font-size:12px;" onclick="removeSelectedFile(${idx});return false;">🗑 Remove</a>`;
        list.appendChild(li);
    });
}
