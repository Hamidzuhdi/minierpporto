    </main>

    <!-- Bootstrap 5 JS Bundle -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>

    <!-- Toast container untuk notifikasi — selalu di depan modal -->
    <div id="globalToastContainer" style="position:fixed;top:16px;right:16px;z-index:9999;min-width:260px;max-width:360px;"></div>

    <script>
        // Highlight active menu
        $(document).ready(function() {
            let currentPath = window.location.pathname;
            $('.sidebar .nav-link').each(function() {
                if (this.href && currentPath.includes($(this).attr('href'))) {
                    $(this).addClass('active');
                }
            });
        });

        // Global showAlert — tampil di atas modal (z-index 9999)
        // Override semua definisi lokal di halaman karena footer.php di-include SETELAH script halaman
        function showAlert(type, message) {
            const colorMap = { success: '#198754', danger: '#dc3545', warning: '#fd7e14', info: '#0d6efd' };
            const iconMap  = { success: 'fa-check-circle', danger: 'fa-times-circle', warning: 'fa-exclamation-triangle', info: 'fa-info-circle' };
            const bg  = colorMap[type] || '#333';
            const ico = iconMap[type]  || 'fa-bell';
            const id  = 'toast_' + Date.now();
            const html = `
                <div id="${id}" style="background:${bg};color:#fff;padding:12px 16px;border-radius:8px;
                     margin-bottom:8px;box-shadow:0 4px 16px rgba(0,0,0,0.3);
                     display:flex;align-items:flex-start;gap:10px;opacity:0;transition:opacity .25s;">
                    <i class="fas ${ico}" style="margin-top:2px;flex-shrink:0;"></i>
                    <span style="flex:1;font-size:14px;line-height:1.4;">${message}</span>
                    <button onclick="document.getElementById('${id}').remove()"
                            style="background:none;border:none;color:#fff;font-size:18px;line-height:1;cursor:pointer;padding:0;flex-shrink:0;">&times;</button>
                </div>`;
            const $el = $(html).appendTo('#globalToastContainer');
            setTimeout(() => $el.css('opacity', 1), 10);
            setTimeout(() => { $el.css('opacity', 0); setTimeout(() => $el.remove(), 300); }, 4000);
        }
    </script>
</body>
</html>
