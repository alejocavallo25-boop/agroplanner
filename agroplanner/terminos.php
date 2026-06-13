<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Términos y Condiciones - AgroPlanner</title>
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
            background: var(--bg-color);
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
        
        <h1><i class="fas fa-file-contract"></i> Términos y Condiciones de Uso</h1>
        <p style="font-size: 0.85em; font-style: italic;">Última actualización: <?php echo date('d/m/Y'); ?></p>
        
        <h2>1. Aceptación de los Términos</h2>
        <p>Al acceder y utilizar AgroPlanner, aceptas cumplir con estos términos y condiciones. Si no estás de acuerdo con alguna parte de los términos, no debes utilizar nuestra plataforma.</p>

        <h2>2. Descripción del Servicio</h2>
        <p>AgroPlanner es una herramienta de gestión y planificación agrícola. Los resultados, cálculos financieros y proyecciones son de carácter orientativo y dependen de la exactitud de los datos ingresados por el usuario. No constituyen asesoramiento financiero ni agronómico profesional.</p>

        <h2>3. Cuentas de Usuario</h2>
        <p>Eres responsable de mantener la confidencialidad de tu cuenta y contraseña. Nos reservamos el derecho de suspender o cancelar cuentas que incumplan estos términos o realicen un uso indebido de la plataforma (ej. introducir datos falsos intencionalmente, intentar vulnerar la seguridad, etc.).</p>

        <h2>4. Propiedad Intelectual</h2>
        <p>Todo el contenido, diseño, código fuente y marca de AgroPlanner son propiedad exclusiva de los desarrolladores de la plataforma. Queda terminantemente prohibida su reproducción, copia, o distribución sin autorización expresa.</p>

        <h2>5. Limitación de Responsabilidad</h2>
        <p>AgroPlanner no se hace responsable por decisiones económicas, productivas o comerciales tomadas en base a la información o cálculos proporcionados por la plataforma. El uso de la herramienta y sus resultados es bajo tu propio riesgo y responsabilidad.</p>

        <h2>6. Modificaciones</h2>
        <p>Nos reservamos el derecho de modificar o reemplazar estos términos en cualquier momento. El uso continuado de la aplicación después de cualquier cambio constituye la aceptación de los nuevos términos.</p>
    </div>
</body>
</html>
