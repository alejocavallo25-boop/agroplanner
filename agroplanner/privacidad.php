<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Política de Privacidad - AgroPlanner</title>
    <link rel="stylesheet" href="assets/css/style.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        body { 
            padding: 40px 20px;
            justify-content: flex-start;
            align-items: center;
            display: flex;
            flex-direction: column;
            min-height: 100vh;
            background: var(--bg-color); /* asumiendo que está en style.css */
        }
        .content-wrapper {
            width: 100%; max-width: 800px;
            background: rgba(0, 0, 0, 0.25);
            border: 1px solid rgba(255, 255, 255, 0.08);
            border-radius: 20px;
            padding: 40px;
            backdrop-filter: blur(12px);
            color: var(--text-primary);
            line-height: 1.6;
            margin-top: 20px;
        }
        h1, h2 { color: var(--accent); margin-bottom: 20px; font-weight: 700; }
        h1 { display: flex; align-items: center; gap: 10px; }
        h2 { margin-top: 30px; font-size: 1.3em; }
        p, li { color: var(--text-muted); margin-bottom: 15px; font-size: 0.95em; }
        .back-link {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            margin-bottom: 20px;
            color: var(--text-muted);
            text-decoration: none;
            font-weight: 600;
            transition: color 0.2s;
            padding: 8px 16px;
            background: rgba(255,255,255,0.05);
            border-radius: 12px;
            border: 1px solid rgba(255,255,255,0.1);
        }
        .back-link:hover { color: var(--text-primary); background: rgba(255,255,255,0.1); }
    </style>
</head>
<body>
    <div class="content-wrapper">
        <a href="javascript:history.back()" class="back-link"><i class="fas fa-arrow-left"></i> Volver</a>
        
        <h1><i class="fas fa-user-shield"></i> Política de Privacidad</h1>
        <p style="font-size: 0.85em; font-style: italic;">Última actualización: <?php echo date('d/m/Y'); ?></p>
        
        <h2>1. Información que recopilamos</h2>
        <p>En AgroPlanner recopilamos la información necesaria para brindarte el mejor servicio de planificación agrícola. Esto incluye tu nombre, correo electrónico, y los datos operativos que ingreses en la plataforma (lotes, insumos, labores, operaciones, etc.).</p>

        <h2>2. Uso de la información</h2>
        <p>Los datos recopilamos son utilizados exclusivamente para el funcionamiento de la plataforma, calcular márgenes, costos y mostrar las métricas en tu tablero. No vendemos ni compartimos tu información personal o productiva con terceros para fines comerciales.</p>

        <h2>3. Seguridad de los datos</h2>
        <p>Implementamos medidas de seguridad para proteger tu información contra acceso no autorizado, alteración, divulgación o destrucción. Tus contraseñas están encriptadas y el acceso a tus datos productivos está restringido a tu usuario.</p>

        <h2>4. Cookies y Sesiones</h2>
        <p>Utilizamos cookies esenciales y variables de sesión para mantener tu cuenta conectada y garantizar la seguridad de la plataforma (por ejemplo, validaciones CSRF).</p>

        <h2>5. Tus derechos</h2>
        <p>Tienes derecho a acceder, corregir o eliminar tu información personal y productiva en cualquier momento contactando con el administrador de la plataforma.</p>

        <h2>6. Cambios en la Política de Privacidad</h2>
        <p>Podemos actualizar nuestra Política de Privacidad ocasionalmente. Te notificaremos sobre cualquier cambio publicando la nueva política en esta página.</p>
    </div>
</body>
</html>
