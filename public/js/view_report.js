// --- Header Dropdown Toggle ---
function toggleDropdown() {
    const dropdown = document.getElementById('profileDropdown');
    const isVisible = dropdown.style.display === 'block';
    document.querySelectorAll('.profile-dropdown').forEach(d => {
        d.style.display = 'none';
    });
    if (!isVisible) {
        dropdown.style.display = 'block';
    }
}

document.addEventListener('click', function(event) {
    const profilePic = document.querySelector('.profile-pic');
    const dropdown = document.getElementById('profileDropdown');
    if (profilePic && dropdown) {
        const isClickInsidePic = profilePic.contains(event.target);
        const isClickInsideDropdown = dropdown.contains(event.target);
        if (!isClickInsidePic && !isClickInsideDropdown) {
            dropdown.style.display = 'none';
        }
    }
});

// --- Toast Utility ---
function showToast(msg) {
    const toast = document.getElementById('toast');
    toast.textContent = msg;
    toast.classList.add('show');
    setTimeout(() => {
        toast.classList.remove('show');
    }, 3000);
}

// --- Contextual Flash Utility ---
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

// --- Auto-resize Textarea ---
function autoResizeTextarea(element) {
    element.style.height = 'auto';
    element.style.height = (element.scrollHeight + 2) + 'px';
}

document.addEventListener('DOMContentLoaded', function() {
    // Auto-resize for all relevant textareas
    const resizableTextareas = document.querySelectorAll('.large-textarea, .seamless-input, .rat-main-textarea');
    resizableTextareas.forEach(textarea => {
        autoResizeTextarea(textarea);
        textarea.addEventListener('input', function() { autoResizeTextarea(this); });
        window.addEventListener('resize', function() { autoResizeTextarea(textarea); });
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
});
