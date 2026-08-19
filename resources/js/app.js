function loginPrompt() {
    alert('Vui lòng đăng nhập để thêm sản phẩm vào danh sách yêu thích.');
}

function toggleAccountMenu() {
    const menu = document.getElementById('accountMenu');
    if (!menu) {
        return;
    }

    menu.style.display = menu.style.display === 'block' ? 'none' : 'block';
}

document.addEventListener('DOMContentLoaded', () => {
    document.addEventListener('click', (event) => {
        const menu = document.getElementById('accountMenu');
        if (!menu) {
            return;
        }

        const clickedButton = event.target && event.target.closest && event.target.closest('button[aria-label="Account"]');
        if (clickedButton) {
            return;
        }

        if (!menu.contains(event.target)) {
            menu.style.display = 'none';
        }
    });

    const yearNode = document.getElementById('currentYear');

    if (yearNode) {
        yearNode.textContent = new Date().getFullYear();
    }
});
