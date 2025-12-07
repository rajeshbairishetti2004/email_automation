document.addEventListener('DOMContentLoaded', function() {
    // Example: Simple client-side validation
    const form = document.getElementById('registerForm');
    if (form) {
        form.addEventListener('submit', function(e) {
            const pwd = form.querySelector('input[name="password"]');
            const confirm = form.querySelector('input[name="confirm_password"]');
            if (pwd && confirm && pwd.value !== confirm.value) {
                e.preventDefault();
                alert('Passwords do not match!');
            }
        });
    }
    // Add more register-specific JS here
	function togglePasswordVisibility(fieldId) {
		const field = document.getElementById(fieldId);
		if (field.type === 'password') {
			field.type = 'text';
		} else {
			field.type = 'password';
		}
	}

	const usernameInput = document.getElementById('username');
	const feedbackDiv = document.getElementById('username-feedback');
	const availableInput = document.getElementById('username_available');
	let typingTimer;
	const doneTypingInterval = 500; // 0.5 seconds

	usernameInput.addEventListener('keyup', () => {
		clearTimeout(typingTimer);
		if (usernameInput.value) {
			typingTimer = setTimeout(checkUsernameAvailability, doneTypingInterval);
		} else {
			feedbackDiv.textContent = '';
			availableInput.value = 'false';
		}
	});

	function checkUsernameAvailability() {
		const username = usernameInput.value.trim();
		if (username.length < 3) {
			feedbackDiv.innerHTML = '<span class="text-danger">Username must be at least 3 characters.</span>';
			availableInput.value = 'false';
			return;
		}

		feedbackDiv.innerHTML = 'Checking...';

		// Send AJAX request to auth.php to check availability
		fetch(`auth.php?action=check_username&username=${encodeURIComponent(username)}`)
			.then(response => response.json())
			.then(data => {
				if (data.available) {
					feedbackDiv.innerHTML = '<span class="text-success">' + data.message + '</span>';
					availableInput.value = 'true';
				} else {
					feedbackDiv.innerHTML = '<span class="text-danger">' + data.message + '</span>';
					availableInput.value = 'false';
				}
			})
			.catch(error => {
				feedbackDiv.innerHTML = '<span class="text-danger">Error checking username.</span>';
				availableInput.value = 'false';
				console.error('AJAX Error:', error);
			});
	}
});
