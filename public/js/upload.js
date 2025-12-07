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
