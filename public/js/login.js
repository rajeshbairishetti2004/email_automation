document.addEventListener('DOMContentLoaded', function() {
    // Example: Show/hide password toggle
    const toggle = document.getElementById('togglePassword');
    const pwd = document.getElementById('password');
    if (toggle && pwd) {
        toggle.addEventListener('click', function() {
            pwd.type = pwd.type === 'password' ? 'text' : 'password';
        });
    }
    // Add more login-specific JS here
});
