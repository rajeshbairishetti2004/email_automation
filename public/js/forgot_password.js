document.addEventListener('DOMContentLoaded', function() {
    const form = document.querySelector('form');
    if (!form) return;
    form.addEventListener('submit', function(e) {
        const email = form.querySelector('#email');
        if (!email.value.match(/^[^@]+@[^@]+\.[^@]+$/)) {
            alert('Please enter a valid email address.');
            e.preventDefault();
        }
    });
});
