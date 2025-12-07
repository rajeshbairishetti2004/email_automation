document.addEventListener('DOMContentLoaded', function() {
    // Auto-focus next input on digit entry
    document.querySelectorAll('.otp-input').forEach((input, idx, arr) => {
        input.addEventListener('input', function() {
            if (this.value.length === 1 && arr[idx + 1]) {
                arr[idx + 1].focus();
            }
        });
    });

    // Resend OTP timer example
    const resendBtn = document.getElementById('resendOtpBtn');
    const timerSpan = document.getElementById('otpResendTimer');
    if (resendBtn && timerSpan) {
        let timer = 30;
        resendBtn.disabled = true;
        timerSpan.textContent = timer;
        const interval = setInterval(() => {
            timer--;
            timerSpan.textContent = timer;
            if (timer <= 0) {
                clearInterval(interval);
                resendBtn.disabled = false;
                timerSpan.textContent = '';
            }
        }, 1000);
    }
});
