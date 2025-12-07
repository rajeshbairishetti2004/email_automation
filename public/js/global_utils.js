function showToast(msg) {
    const toast = document.getElementById('toast');
    toast.textContent = msg;
    toast.classList.add('show');
    setTimeout(() => {
        toast.classList.remove('show');
    }, 3000);
}

function showContextualFlash(type, message, containerId) {
    const container = document.getElementById(containerId);
    if (!container) {
        showToast(message);
        return;
    }
    const cssClass = type === 'success' ? 'flash-success' : 'flash-error';
    const icon = type === 'success' ? '✅' : '❌';
    container.innerHTML = `<div class="flash-message ${cssClass}" style="opacity: 1; transition: opacity 0.5s ease;">${icon} ${message}</div>`;
    setTimeout(() => {
        const flashMsg = container.querySelector('.flash-message');
        if (flashMsg) {
            flashMsg.style.opacity = '0';
            setTimeout(() => {
                container.innerHTML = '';
            }, 500);
        }
    }, 3000);
}

function autoResizeTextarea(element) {
    element.style.height = 'auto';
    element.style.height = (element.scrollHeight + 2) + 'px';
}
