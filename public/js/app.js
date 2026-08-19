document.addEventListener('DOMContentLoaded', () => {
    const yearNode = document.getElementById('currentYear');

    if (yearNode) {
        yearNode.textContent = new Date().getFullYear();
    }
});
