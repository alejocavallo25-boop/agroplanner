<?php
$current_page = basename($_SERVER['PHP_SELF']);

function is_active($page, $current_page) {
    return $page === $current_page ? 'active' : '';
}
?>
<!--
    Las etiquetas de texto van envueltas en <span class="nav-txt"> a propósito:
    cuando la barra está colapsada en modo rail el CSS las desvanece y deja sólo
    los iconos. Sin el span no habría forma de ocultar el texto sin ocultar
    también el icono, porque serían el mismo nodo de texto suelto.
-->
<aside class="sidebar">
    <div class="brand">
        <i class="fas fa-leaf"></i>
        <span class="nav-txt">AgroPlanner</span>
    </div>

    <nav>
        <?php if (!empty($_SESSION['modulos']['agricultura'])): ?>
        <?php $is_agri_active = (in_array($current_page, ['index.php', 'lotes.php', 'operaciones.php', 'alquileres.php', 'insumos.php', 'produccion.php'])); ?>
        <div class="nav-section nav-section-agri <?= $is_agri_active ? 'active' : '' ?>">
            <div class="nav-section-header" onclick="toggleSection(this)">
                <div class="nav-section-title"><i class="fas fa-wheat-awn"></i> <span class="nav-txt">Agricultura</span></div>
                <i class="fas fa-chevron-down nav-chevron"></i>
            </div>
            <div class="nav-section-links">
                <a href="index.php"        class="nav-link <?= is_active('index.php', $current_page) ?>"><i class="fas fa-home"></i> <span class="nav-txt">Panel General</span></a>
                <a href="lotes.php"        class="nav-link <?= is_active('lotes.php', $current_page) ?>"><i class="fas fa-map-marked-alt"></i> <span class="nav-txt">Lotes y Cultivos</span></a>
                <a href="operaciones.php"  class="nav-link <?= is_active('operaciones.php', $current_page) ?>"><i class="fas fa-tractor"></i> <span class="nav-txt">Costos y Labores</span></a>
                <a href="alquileres.php"   class="nav-link <?= is_active('alquileres.php', $current_page) ?>"><i class="fas fa-file-contract"></i> <span class="nav-txt">Alquileres</span></a>
                <a href="insumos.php"      class="nav-link <?= is_active('insumos.php', $current_page) ?>"><i class="fas fa-warehouse"></i> <span class="nav-txt">Insumos (Stock)</span></a>
                <a href="produccion.php"   class="nav-link <?= is_active('produccion.php', $current_page) ?>"><i class="fas fa-seedling"></i> <span class="nav-txt">Producción y Ventas</span></a>
            </div>
        </div>
        <?php endif; ?>

        <?php if (!empty($_SESSION['modulos']['tambo'])): ?>
        <?php $is_tambo_active = (in_array($current_page, ['tambo.php', 'tambo_produccion.php', 'tambo_egresos.php', 'tambo_comparativa.php'])); ?>
        <div class="nav-section nav-section-tambo <?= $is_tambo_active ? 'active' : '' ?>">
            <div class="nav-section-header" onclick="toggleSection(this)">
                <div class="nav-section-title"><i class="fas fa-cow"></i> <span class="nav-txt">Tambo</span></div>
                <i class="fas fa-chevron-down nav-chevron"></i>
            </div>
            <div class="nav-section-links">
                <a href="tambo.php"             class="nav-link nav-link-tambo <?= is_active('tambo.php', $current_page) ?>"><i class="fas fa-tachometer-alt"></i> <span class="nav-txt">Panel General</span></a>
                <a href="tambo_produccion.php"  class="nav-link nav-link-tambo <?= is_active('tambo_produccion.php', $current_page) ?>"><i class="fas fa-tint"></i> <span class="nav-txt">Ingresos</span></a>
                <a href="tambo_egresos.php"     class="nav-link nav-link-tambo <?= is_active('tambo_egresos.php', $current_page) ?>"><i class="fas fa-arrow-trend-down"></i> <span class="nav-txt">Costos</span></a>
                <a href="tambo_comparativa.php" class="nav-link nav-link-tambo <?= is_active('tambo_comparativa.php', $current_page) ?>"><i class="fas fa-code-compare"></i> <span class="nav-txt">Comparativa</span></a>
            </div>
        </div>
        <?php endif; ?>

        <?php if (!empty($_SESSION['modulos']['ganaderia'])): ?>
        <?php $is_gana_active = (in_array($current_page, ['ganaderia.php', 'ganaderia_feedlot.php'])); ?>
        <div class="nav-section nav-section-gana <?= $is_gana_active ? 'active' : '' ?>">
            <div class="nav-section-header" onclick="toggleSection(this)">
                <div class="nav-section-title"><i class="fas fa-bullseye"></i> <span class="nav-txt">Ganadería</span></div>
                <i class="fas fa-chevron-down nav-chevron"></i>
            </div>
            <div class="nav-section-links">
                <a href="ganaderia.php"         class="nav-link nav-link-gana <?= is_active('ganaderia.php', $current_page) ?>"><i class="fas fa-tachometer-alt"></i> <span class="nav-txt">Tablero Ganadero</span></a>
                <a href="ganaderia_feedlot.php" class="nav-link nav-link-gana <?= is_active('ganaderia_feedlot.php', $current_page) ?>"><i class="fas fa-calculator"></i> <span class="nav-txt">Simulador</span></a>
            </div>
        </div>
        <?php endif; ?>

        <?php if (isset($_SESSION['role']) && $_SESSION['role'] === 'admin'): ?>
        <div class="nav-section">
            <!-- Este encabezado no tiene icono ni despliega nada: en modo rail se
                 oculta entero, porque un rótulo suelto sin icono no se entiende. -->
            <div class="nav-section-header nav-section-header-plain"><span class="nav-txt">Administración</span></div>
            <a href="admin.php" class="nav-link nav-link-admin <?= is_active('admin.php', $current_page) ?>">
                <i class="fas fa-users-cog"></i> <span class="nav-txt">Usuarios</span>
            </a>
        </div>
        <?php endif; ?>

        <?php
        $ua = $_SERVER['HTTP_USER_AGENT'] ?? '';
        $isAppleDevice = preg_match('/iPad|iPhone|iPod/i', $ua) || (preg_match('/Mac/i', $ua) && preg_match('/Mobile/i', $ua));
        $displayBtn = $isAppleDevice ? 'flex' : 'none';
        ?>
        <a href="#" id="installAppBtn" class="nav-link nav-link-utility" style="display: <?= $displayBtn ?>;">
            <i class="fas fa-cloud-download-alt"></i> <span class="nav-txt">Anclar App</span>
        </a>
    </nav>

    <div class="sidebar-user">
        <div class="sidebar-user-avatar">
            <?= htmlspecialchars(strtoupper(substr($_SESSION['username'] ?? 'U', 0, 1))) ?>
        </div>
        <div class="sidebar-user-info">
            <span class="sidebar-user-name"><?= htmlspecialchars($_SESSION['username'] ?? 'Usuario') ?></span>
            <?php /* Mostraba el valor crudo del enum, que está en inglés: debajo del
                     nombre decía "User". Se traduce acá y no en la base porque el
                     enum lo usan las consultas de permisos. */
            $roles = ['admin' => 'Administrador', 'user' => 'Usuario'];
            $rol   = $_SESSION['role'] ?? '';
            ?>
            <span class="sidebar-user-role"><?= htmlspecialchars($roles[$rol] ?? 'Invitado') ?></span>
        </div>
        <a href="logout.php" class="sidebar-logout" title="Cerrar Sesión">
            <i class="fas fa-sign-out-alt"></i>
        </a>
    </div>
</aside>


<script>
// El desplegable de las secciones se define acá arriba, fuera y antes del bloque
// de la PWA. Ese bloque corta con `return` cuando la app ya está anclada, y como
// toggleSection vivía adentro se quedaba sin definir justo en ese caso: en la app
// instalada el menú no abría ni cerraba nada y los onclick tiraban ReferenceError.
window.toggleSection = function (header) {
    header.parentElement.classList.toggle('active');
};

/* ─── Abrir la barra con un toque, en los equipos sin hover ───────────────────
 *
 * En pantalla ancha la barra es un rail de iconos que se abre al pasar el mouse.
 * Pero una laptop con pantalla táctil informa hover:none aunque tenga mouse, y
 * ahí no hay nada que la abra: quedaría un rail de iconos sin texto y sin forma
 * de saber qué es cada uno.
 *
 * Así que en esos equipos el primer toque la abre y el segundo —o tocar afuera,
 * o elegir un enlace— la cierra. Donde sí hay hover no se engancha nada: el CSS
 * ya alcanza y un toque de más sería un estado pegado que después hay que cerrar
 * a mano, que es justo lo que se está arreglando.
 */
(function () {
    const barra = document.querySelector('.sidebar');
    if (!barra) return;

    const conHover = window.matchMedia('(hover: hover)').matches;
    const anchaYSinHover = () => window.innerWidth >= 769 && !conHover;
    if (!anchaYSinHover()) return;

    const cerrar = () => barra.classList.remove('sidebar--abierta');

    barra.addEventListener('click', function (e) {
        // Un enlace navega: no hay que abrir ni cerrar, la página se va.
        if (e.target.closest('a')) return;
        // El encabezado de sección tiene lo suyo; sólo se abre si estaba cerrada.
        if (barra.classList.contains('sidebar--abierta')) {
            if (!e.target.closest('.nav-section-header')) cerrar();
        } else {
            barra.classList.add('sidebar--abierta');
        }
    });

    // Tocar fuera la cierra, como cualquier menú.
    document.addEventListener('click', function (e) {
        if (!e.target.closest('.sidebar')) cerrar();
    });

    // Al girar el equipo puede aparecer el cajón de abajo de 769: se limpia.
    window.addEventListener('resize', function () {
        if (window.innerWidth < 769) cerrar();
    });
})();

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
})();
</script>
