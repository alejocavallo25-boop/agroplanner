<?php
// Security Headers
header("X-Frame-Options: DENY");
header("X-Content-Type-Options: nosniff");
header("Referrer-Policy: strict-origin-when-cross-origin");
header("Strict-Transport-Security: max-age=31536000; includeSubDomains");
header("Content-Security-Policy: default-src 'self'; script-src 'self' 'unsafe-inline' https://cdn.jsdelivr.net https://unpkg.com; style-src 'self' 'unsafe-inline' https://fonts.googleapis.com https://cdnjs.cloudflare.com https://unpkg.com; font-src 'self' https://fonts.gstatic.com https://cdnjs.cloudflare.com; img-src 'self' data: https://ui-avatars.com https://*.tile.openstreetmap.org https://server.arcgisonline.com https://unpkg.com https://tilecache.rainviewer.com; connect-src 'self' https://dolarapi.com https://api.open-meteo.com https://nominatim.openstreetmap.org https://api.rainviewer.com;");
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Planificador Agrícola</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Public+Sans:wght@400;500;600;700&family=Newsreader:opsz,wght@6..72,400;6..72,500&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="assets/css/style.css?v=<?= @filemtime(__DIR__ . '/../assets/css/style.css') ?: '1' ?>">
    <?php require __DIR__ . '/favicon.php'; ?>

    <!-- PWA -->
    <link rel="manifest" href="manifest.json">
    <?php /* Pinta la barra del navegador en el teléfono: iba en el esmeralda de
             la paleta vieja y desentonaba con la aplicación entera. */ ?>
    <meta name="theme-color" content="#195c2e">
    <meta name="mobile-web-app-capable" content="yes">

    <!-- PWA iOS (Apple) específicas -->
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
    <meta name="apple-mobile-web-app-title" content="AgroPlanner">
    <link rel="apple-touch-icon" href="assets/img/apple-touch-icon.png?v=6">
    
    <script>
      /* updateViaCache 'none': que el navegador no use su propia caché para este
         archivo. El servidor manda los estáticos con siete días, y el sw.js es
         justamente lo que actualiza todo lo demás, así que cachearlo una semana
         significa que una corrección tarda una semana en llegar. Pasó de verdad
         en producción: el archivo nuevo estaba subido y el navegador seguía
         usando el anterior. Del lado del servidor lo tapa el .htaccess.

         La dirección va SIN versión a propósito. Se probó con ?v= y trae un
         problema peor: campo.html registra el mismo archivo, y dos direcciones
         distintas para el mismo alcance se pisan entre sí —cada página deshace
         el registro de la otra y el service worker queda reinstalándose—. Tiene
         que ser la misma cadena en los dos lados. */
      if ('serviceWorker' in navigator) {
        window.addEventListener('load', function() {
          navigator.serviceWorker.register('sw.js', { updateViaCache: 'none' })
            .then(function(registration) {
              console.log('ServiceWorker registration successful with scope: ', registration.scope);
            }, function(err) {
              console.log('ServiceWorker registration failed: ', err);
            });
        });
      }
    </script>
    <script src="assets/js/offline.js" defer></script>
</head>
<body>
<?php /* Salto al contenido. Sin esto, quien navega con teclado tiene que pasar
         por los 12 links del menú ANTES de llegar al contenido, en cada página y
         cada vez. Sólo se ve al enfocarlo, así que no cambia nada visualmente. */ ?>
<a href="#contenido-principal" class="ap-skip">Saltar al contenido</a>

<div id="overlay"></div>

<?php require_once 'sidebar.php'; ?>

<main class="main-content" id="contenido-principal" tabindex="-1">
    <header>
        <div class="header-left">
            <button class="mobile-menu-btn" id="menuToggle" aria-label="Abrir menú"><i class="fas fa-bars"></i></button>
            <h1 class="page-title"><?php echo isset($page_title) ? $page_title : 'Dashboard'; ?></h1>
        </div>
        <div class="header-right" style="display: flex; align-items: center; gap: 15px;">
            <div id="offline-status-banner" style="display: none; padding: 6px 12px; border-radius: 20px; font-size: 0.85rem; font-weight: 500; align-items: center; gap: 8px;"></div>
            <!-- El usuario y logout ahora están en el sidebar -->
        </div>
    </header>

    <?php if (function_exists('es_solo_lectura') && es_solo_lectura()): ?>
    <!-- Aviso de cuenta demo. El bloqueo de verdad está en config/auth.php: esto es
         para que el visitante entienda por qué no puede guardar, y para no tentarlo
         con botones que no van a funcionar. -->
    <div class="demo-banner" role="status">
        <i class="fas fa-eye" aria-hidden="true"></i>
        <span><strong>Cuenta de demostración.</strong> Podés recorrer todo el sistema y ver los datos; guardar, editar y borrar están deshabilitados.</span>
    </div>
    <style>
        .demo-banner{display:flex;align-items:center;gap:10px;margin:0 0 18px;padding:11px 16px;
            border:1px solid oklch(0.470 0.120 70 / .40);background:oklch(0.470 0.120 70 / .09);
            border-radius:10px;
            color:var(--se-warning);font-size:.92rem;line-height:1.45}
        .demo-banner i{color:var(--se-warning);flex:none}
        /* controles neutralizados: se ven, se entiende que existen, pero no responden */
        .demo-ro{opacity:.45 !important;cursor:not-allowed !important;pointer-events:none !important}
        /* Sin override por prefers-color-scheme: la app es de tema claro fijo, así que
           el amarillo claro que había acá quedaba ilegible sobre el banner. */
    </style>
    <script>
    (function(){
        // Marca los controles que escriben. Es cosmético y de cortesía: aunque alguien
        // lo desarme desde la consola, el POST lo sigue rechazando el servidor.
        function aplicar(){
            document.querySelectorAll('form').forEach(function(f){
                var ajax = (f.querySelector('[name="ajax"]') || {}).value || '';
                if (ajax === 'get_egresos_mes') return;
                if (f.method && f.method.toLowerCase() !== 'post') return;
                f.addEventListener('submit', function(e){
                    e.preventDefault();
                    alert('Cuenta de demostración: podés ver todo, pero no guardar cambios.');
                }, true);
                f.querySelectorAll('button[type="submit"], input[type="submit"], button:not([type])')
                 .forEach(function(b){ b.classList.add('demo-ro'); b.setAttribute('title','No disponible en la demo'); });
            });
        }
        if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', aplicar);
        else aplicar();
    })();
    </script>
    <?php endif; ?>

    <?php
    if (function_exists('get_flash')) {
        $flash = get_flash();
        if ($flash):
            $bg = $flash['type'] === 'success' ? 'rgba(16,185,129,0.1)' : 'rgba(239,68,68,0.1)';
            $color = $flash['type'] === 'success' ? 'var(--accent)' : '#ef4444';
            $border = $flash['type'] === 'success' ? 'rgba(16,185,129,0.3)' : 'rgba(239,68,68,0.3)';
            $icon = $flash['type'] === 'success' ? 'fa-check-circle' : 'fa-exclamation-circle';
    ?>
    <div style="background:<?= $bg ?>; color:<?= $color ?>; padding:12px 20px; border-radius:10px; border:1px solid <?= $border ?>; margin-bottom:20px; font-size:.95rem; display: flex; align-items: center; gap: 10px;">
        <i class="fas <?= $icon ?>"></i>
        <?= htmlspecialchars($flash['message']) ?>
    </div>
    <?php 
        endif;
    }
    ?>
