/* ============================================================
   AssetDesk — Shared Client Scripts
   ============================================================ */

(function () {
    'use strict';

    document.querySelectorAll('.toggle-pw').forEach(btn => {
        btn.addEventListener('click', () => {
            const input = document.getElementById(btn.dataset.target);
            if (!input) return;
            const isPw = input.type === 'password';
            input.type = isPw ? 'text' : 'password';
            const icon = btn.querySelector('i');
            if (icon) {
                icon.classList.toggle('fa-eye', !isPw);
                icon.classList.toggle('fa-eye-slash', isPw);
            }
        });
    });

    document.querySelectorAll('[data-confirm]').forEach(el => {
        el.addEventListener('click', (e) => {
            if (!confirm(el.dataset.confirm)) e.preventDefault();
        });
    });

    document.querySelectorAll('.banner.success[data-autohide]').forEach(el => {
        setTimeout(() => {
            el.style.transition = 'opacity 0.4s';
            el.style.opacity = '0';
        }, 4000);
        setTimeout(() => el.remove(), 4500);
    });

    document.querySelectorAll('[data-filter-target]').forEach(input => {
        input.addEventListener('input', () => {
            const q = input.value.trim().toLowerCase();
            const table = document.querySelector(input.dataset.filterTarget);
            if (!table) return;
            table.querySelectorAll('tbody tr').forEach(row => {
                row.style.display = row.textContent.toLowerCase().includes(q) ? '' : 'none';
            });
        });
    });

    window.exportTableCSV = function (selector, filename) {
        const table = document.querySelector(selector);
        if (!table) return;
        const lines = [];
        table.querySelectorAll('tr').forEach(row => {
            if (row.style.display === 'none') return;
            const cells = [];
            row.querySelectorAll('th, td').forEach(cell => {
                const text = cell.innerText
                    .replace(/\s+/g, ' ')
                    .trim()
                    .replace(/"/g, '""');
                cells.push('"' + text + '"');
            });
            if (cells.length) lines.push(cells.join(','));
        });
        const blob = new Blob([lines.join('\n')], { type: 'text/csv;charset=utf-8;' });
        const link = document.createElement('a');
        link.href = URL.createObjectURL(blob);
        link.download = filename || 'export.csv';
        document.body.appendChild(link);
        link.click();
        document.body.removeChild(link);
        URL.revokeObjectURL(link.href);
    };

    document.querySelectorAll('a[href^="#"]').forEach(a => {
        a.addEventListener('click', function (e) {
            const target = document.querySelector(this.getAttribute('href'));
            if (target) {
                e.preventDefault();
                const top = target.getBoundingClientRect().top + window.pageYOffset - 80;
                window.scrollTo({ top, behavior: 'smooth' });
            }
        });
    });

})();