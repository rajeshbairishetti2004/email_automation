document.addEventListener('DOMContentLoaded', function() {
    document.querySelectorAll('.goal-status-dropdown').forEach(function(select) {
        select.addEventListener('change', function() {
            const goalId = this.getAttribute('data-goal-id');
            const newStatus = this.value;
            const self = this;

            // Update visual style immediately
            if (newStatus === 'On Track') {
                self.classList.remove('status-off');
                self.classList.add('status-on');
            } else {
                self.classList.remove('status-on');
                self.classList.add('status-off');
            }

            // Save to DB
            fetch('view_report.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
                body: new URLSearchParams({
                    ajax_goal_status: '1',
                    goal_id: goalId,
                    status: newStatus
                })
            })
            .then(res => res.json())
            .then(data => {
                if (!data.success) {
                    alert('Failed to save status.');
                }
            })
            .catch(err => console.error(err));
        });
    });
});
