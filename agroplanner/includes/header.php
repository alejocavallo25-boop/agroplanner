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
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="assets/css/style.css?v=<?= @filemtime(__DIR__ . '/../assets/css/style.css') ?: '1' ?>">
    <!-- Favicon: La hojita idéntica a AgroPlanner (Vectorial y Transparente) -->
    <link rel="icon" type="image/svg+xml" href="data:image/svg+xml,<svg xmlns='http://www.w3.org/2000/svg' viewBox='-30 -30 572 572'><path fill='%2310b981' d='M471.3 6.7C477.7 .6 487-1.6 495.6 1.2 505.4 4.5 512 13.7 512 24l0 186.9c0 131.2-108.1 237.1-238.8 237.1-77 0-143.4-49.5-167.5-118.7-35.4 30.8-57.7 76.1-57.7 126.7 0 13.3-10.7 24-24 24S0 469.3 0 456C0 381.1 38.2 315.1 96.1 276.3 131.4 252.7 173.5 240 216 240l80 0c13.3 0 24-10.7 24-24s-10.7-24-24-24l-80 0c-39.7 0-77.3 8.8-111 24.5 23.3-70 89.2-120.5 167-120.5 66.4 0 115.8-22.1 148.7-44 19.2-12.8 35.5-28.1 50.7-45.3z'/></svg>">
    
    <!-- PWA -->
    <link rel="manifest" href="manifest.json">
    <meta name="theme-color" content="#10b981">
    
    <!-- PWA iOS (Apple) específicas -->
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
    <meta name="apple-mobile-web-app-title" content="AgroPlanner">
    <link rel="apple-touch-icon" href="assets/img/apple-touch-icon.png?v=5">
    
    <script>
      if ('serviceWorker' in navigator) {
        window.addEventListener('load', function() {
          navigator.serviceWorker.register('sw.js').then(function(registration) {
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
<div id="overlay"></div>

<?php require_once 'sidebar.php'; ?>

<main class="main-content">
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
