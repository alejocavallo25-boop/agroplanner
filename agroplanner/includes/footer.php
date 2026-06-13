    <footer style="margin-top: auto; width: 100%; padding-top: 40px; padding-bottom: 20px; display: flex; justify-content: center; align-items: center; text-align: center;">
        <div style="background: rgba(0,0,0,0.25); border: 1px solid rgba(255,255,255,0.08); padding: 10px 24px; border-radius: 30px; display: inline-flex; align-items: center; gap: 8px; font-size: 0.85rem; color: var(--text-muted); backdrop-filter: blur(12px); box-shadow: 0 4px 16px rgba(0,0,0,0.15); transition: transform 0.2s; flex-wrap: wrap; justify-content: center;">
            <i class="fas fa-code" style="color: var(--accent); font-size: 0.9em;"></i>
            <span>Plataforma desarrollada por</span>
            <a href="https://cafra.site/" target="_blank" style="color: var(--text-primary); text-decoration: none; font-weight: 700; display: inline-flex; align-items: center; gap: 6px; padding: 2px 8px; background: rgba(59, 130, 246, 0.15); border-radius: 12px; border: 1px solid rgba(59, 130, 246, 0.3); transition: all 0.2s;">
                CaFra <i class="fas fa-external-link-alt" style="color: #3b82f6; font-size: 1.0em; filter: drop-shadow(0 0 4px rgba(59, 130, 246, 0.4));"></i>
            </a>
            <span style="margin: 0 4px; color: rgba(255,255,255,0.15);">|</span>
            <a href="terminos.php" style="color: var(--text-muted); text-decoration: none; transition: color 0.2s;" onmouseover="this.style.color='var(--text-primary)'" onmouseout="this.style.color='var(--text-muted)'">Términos</a>
            <span style="margin: 0 2px; color: rgba(255,255,255,0.15);">-</span>
            <a href="privacidad.php" style="color: var(--text-muted); text-decoration: none; transition: color 0.2s;" onmouseover="this.style.color='var(--text-primary)'" onmouseout="this.style.color='var(--text-muted)'">Privacidad</a>
        </div>
    </footer>
</main> <!-- Cierra .main-content -->
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<?php if(isset($scripts)) echo $scripts; ?>
<script>
document.addEventListener('DOMContentLoaded', function() {
    const menuToggle = document.getElementById('menuToggle');
    const sidebar = document.querySelector('.sidebar');
    const overlay = document.getElementById('overlay');

    if (menuToggle && sidebar && overlay) {
        menuToggle.addEventListener('click', () => {
            sidebar.classList.add('active');
            overlay.classList.add('active');
        });

        overlay.addEventListener('click', () => {
            sidebar.classList.remove('active');
            overlay.classList.remove('active');
        });
    }
});
</script>
</body>
</html>
