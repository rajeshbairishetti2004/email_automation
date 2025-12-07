document.addEventListener('DOMContentLoaded', function() {
    const fileInput = document.getElementById('client_files');
    const previewList = document.createElement('ul');
    previewList.id = 'file-preview-list';
    previewList.style = 'list-style:none; margin-top:10px; padding:0;';
    if (fileInput && fileInput.parentNode) {
        fileInput.parentNode.appendChild(previewList);

        fileInput.addEventListener('change', function() {
            previewList.innerHTML = '';
            Array.from(fileInput.files).forEach(file => {
                const li = document.createElement('li');
                li.textContent = `${file.name} (${Math.round(file.size/1024)} KB)`;
                previewList.appendChild(li);
            });
        });
    }
});
