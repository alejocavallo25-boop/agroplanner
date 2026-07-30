<?php
$current_page = basename($_SERVER['PHP_SELF']);

function is_active($page, $current_page) {
    return $page === $current_page ? 'active' : '';
}
?>
<aside class="sidebar">
    <div class="brand">
        <i class="fas fa-leaf"></i>
        <span>AgroPlanner</span>
    </div>
    
    <nav>
        <?php if (!empty($_SESSION['modulos']['agricultura'])): ?>
        <?php $is_agri_active = (in_array($current_page, ['index.php', 'lotes.php', 'operaciones.php', 'alquileres.php', 'insumos.php', 'produccion.php'])); ?>
        <div class="nav-section nav-section-agri <?= $is_agri_active ? 'active' : '' ?>">
            <div class="nav-section-header" onclick="toggleSection(this)">
                <div class="nav-section-title"><i class="fas fa-wheat-awn"></i> Agricultura</div>
                <i class="fas fa-chevron-down nav-chevron"></i>
            </div>
            <div class="nav-section-links">
                <a href="index.php"        class="nav-link <?= is_active('index.php', $current_page) ?>"><i class="fas fa-home"></i> Panel General</a>
                <a href="lotes.php"        class="nav-link <?= is_active('lotes.php', $current_page) ?>"><i class="fas fa-map-marked-alt"></i> Lotes y Cultivos</a>
                <a href="operaciones.php"  class="nav-link <?= is_active('operaciones.php', $current_page) ?>"><i class="fas fa-tractor"></i> Costos y Labores</a>
                <a href="alquileres.php"   class="nav-link <?= is_active('alquileres.php', $current_page) ?>"><i class="fas fa-file-contract"></i> Alquileres</a>
                <a href="insumos.php"      class="nav-link <?= is_active('insumos.php', $current_page) ?>"><i class="fas fa-warehouse"></i> Insumos (Stock)</a>
                <a href="produccion.php"   class="nav-link <?= is_active('produccion.php', $current_page) ?>"><i class="fas fa-seedling"></i> Producción y Ventas</a>
            </div>
        </div>
        <?php endif; ?>

        <?php if (!empty($_SESSION['modulos']['tambo'])): ?>
        <?php $is_tambo_active = (in_array($current_page, ['tambo.php', 'tambo_produccion.php', 'tambo_egresos.php', 'tambo_comparativa.php'])); ?>
        <div class="nav-section nav-section-tambo <?= $is_tambo_active ? 'active' : '' ?>">
            <div class="nav-section-header" onclick="toggleSection(this)">
                <div class="nav-section-title"><i class="fas fa-cow"></i> Tambo</div>
                <i class="fas fa-chevron-down nav-chevron"></i>
            </div>
            <div class="nav-section-links">
                <a href="tambo.php"            class="nav-link nav-link-tambo <?= is_active('tambo.php', $current_page) ?>"><i class="fas fa-tachometer-alt"></i> Panel General</a>
                <a href="tambo_produccion.php" class="nav-link nav-link-tambo <?= is_active('tambo_produccion.php', $current_page) ?>"><i class="fas fa-tint"></i> Ingresos</a>
                <a href="tambo_egresos.php"    class="nav-link nav-link-tambo <?= is_active('tambo_egresos.php', $current_page) ?>"><i class="fas fa-arrow-trend-down"></i> Costos</a>
                <a href="tambo_comparativa.php" class="nav-link nav-link-tambo <?= is_active('tambo_comparativa.php', $current_page) ?>"><i class="fas fa-code-compare"></i> Comparativa</a>
            </div>
        </div>
        <?php endif; ?>

        <?php if (!empty($_SESSION['modulos']['ganaderia'])): ?>
        <?php $is_gana_active = (in_array($current_page, ['ganaderia.php', 'ganaderia_feedlot.php'])); ?>
        <div class="nav-section nav-section-gana <?= $is_gana_active ? 'active' : '' ?>">
            <div class="nav-section-header" onclick="toggleSection(this)">
                <div class="nav-section-title"><i class="fas fa-bullseye"></i> Ganadería</div>
                <i class="fas fa-chevron-down nav-chevron"></i>
            </div>
            <div class="nav-section-links">
                <a href="ganaderia.php"             class="nav-link nav-link-gana <?= is_active('ganaderia.php', $current_page) ?>"><i class="fas fa-tachometer-alt"></i> Tablero Ganadero</a>
                <a href="ganaderia_feedlot.php"     class="nav-link nav-link-gana <?= is_active('ganaderia_feedlot.php', $current_page) ?>"><i class="fas fa-calculator"></i> Simulador</a>
            </div>
        </div>
        <?php endif; ?>

        <?php if (isset($_SESSION['role']) && $_SESSION['role'] === 'admin'): ?>
        <div class="nav-section">
            <div class="nav-section-header">Administración</div>
            <a href="admin.php" class="nav-link nav-link-admin <?= is_active('admin.php', $current_page) ?>">
                <i class="fas fa-users-cog"></i> Usuarios
            </a>
        </div>
        <?php endif; ?>

        <?php
        $ua = $_SERVER['HTTP_USER_AGENT'] ?? '';
        $isAppleDevice = preg_match('/iPad|iPhone|iPod/i', $ua) || (preg_match('/Mac/i', $ua) && preg_match('/Mobile/i', $ua));
        $displayBtn = $isAppleDevice ? 'flex' : 'none';
        ?>
        <a href="#" id="installAppBtn" class="nav-link nav-link-utility" style="display: <?= $displayBtn ?>;">
            <i class="fas fa-cloud-download-alt"></i> Anclar App
        </a>
    </nav>

    <div class="sidebar-user">
        <div class="sidebar-user-avatar">
            <?= htmlspecialchars(strtoupper(substr($_SESSION['username'] ?? 'U', 0, 1))) ?>
        </div>
        <div class="sidebar-user-info">
            <span class="sidebar-user-name"><?= htmlspecialchars($_SESSION['username'] ?? 'Usuario') ?></span>
            <span class="sidebar-user-role"><?= htmlspecialchars($_SESSION['role'] ?? 'Invitado') ?></span>
        </div>
        <a href="logout.php" class="sidebar-logout" title="Cerrar Sesión">
            <i class="fas fa-sign-out-alt"></i>
        </a>
    </div>
</aside>


<script>
(function() {
    // Lógica para el botón "Instalar/Anclar App" (PWA)
    let deferredPrompt;
    const installBtn = document.getElementById('installAppBtn');
    
    // PHP le dice a JS si es iOS
    const isIOS = <?= $isAppleDevice ? 'true' : 'false' ?>;
    
    // Detectar si la App ya está instalada y corriendo en pantalla completa
    const isStandalone = window.matchMedia('(display-mode: standalone)').matches || window.navigator.standalone === true;
    

    if (isStandalone) {
        if(installBtn) installBtn.style.display = 'none';
        return; // Salir, ya está instalada
    }

    if (isIOS) {
        if(installBtn) {
            installBtn.style.display = 'flex';
            installBtn.addEventListener('click', (e) => {
                e.preventDefault();
                alert('📱 En iPhone: Toca el botón "Compartir" en Safari (el pequeño cuadrado con la flecha hacia arriba) y selecciona la opción "Agregar a inicio" para instalar AgroPlanner.');
            });
        }
    } else {
        // Lógica Normal para Android y Desktop Chrome
        window.addEventListener('beforeinstallprompt', (e) => {
            e.preventDefault();
            deferredPrompt = e;
            if(installBtn) installBtn.style.display = 'flex';
        });

        if(installBtn) {
            installBtn.addEventListener('click', async (e) => {
                e.preventDefault();
                installBtn.style.display = 'none';
                if (!deferredPrompt) return;
                
                deferredPrompt.prompt();
                const { outcome } = await deferredPrompt.userChoice;
                if (outcome !== 'accepted') { installBtn.style.display = 'flex'; }
                deferredPrompt = null;
            });
        }
    }
    window.toggleSection = function(header) {
        const section = header.parentElement;
        section.classList.toggle('active');
    };

})();
</script>
