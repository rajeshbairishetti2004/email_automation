document.addEventListener('DOMContentLoaded', function() {
    const form = document.querySelector('form');
    if (!form) return;
    form.addEventListener('submit', function(e) {
        const pwd = form.querySelector('#password');
        const confirm = form.querySelector('#confirm_password');
        if (pwd.value.length < 6) {
            alert('New password must be at least 6 characters long.');
            e.preventDefault();
            return;
        }
        if (pwd.value !== confirm.value) {
            alert('Passwords do not match.');
            e.preventDefault();
        }
    });
});
