<?php
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $data = $_POST['image'];
    list($type, $data) = explode(';', $data);
    list(, $data)      = explode(',', $data);
    $data = base64_decode($data);
    // Guardar la imagen nativa generada
    file_put_contents('assets/img/apple-touch-icon.png', $data);
    echo "¡Ícono nativo PNG compatible con Apple generado e inyectado con éxito! Ya puedes cerrar esta pestaña.";
    exit;
}
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>Generando PNG estricto para Apple...</title>
</head>
<body style="background: #1e293b; color: white; font-family: sans-serif; text-align: center; padding-top: 50px;">
    <h2>Convirtiendo la hoja inteligente de AgroPlanner en una imagen estática para iPhone...</h2>
    <div style="margin: 20px auto; width: 150px; height: 150px; border: 2px dashed #10b981;">
        <canvas id="canvas" width="512" height="512" style="width: 100%; height: 100%;"></canvas>
    </div>
    <h3 id="status" style="color: #10b981;">Procesando...</h3>

    <script>
        // La misma hojita idéntica del template pero dibujada por computadora local
        const svgString = `<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 512 512' width='512' height='512'><rect width="512" height="512" fill="#ffffff" /><g transform="translate(102.4, 102.4) scale(0.6)"><path fill='#10b981' d='M471.3 6.7C477.7 .6 487-1.6 495.6 1.2 505.4 4.5 512 13.7 512 24l0 186.9c0 131.2-108.1 237.1-238.8 237.1-77 0-143.4-49.5-167.5-118.7-35.4 30.8-57.7 76.1-57.7 126.7 0 13.3-10.7 24-24 24S0 469.3 0 456C0 381.1 38.2 315.1 96.1 276.3 131.4 252.7 173.5 240 216 240l80 0c13.3 0 24-10.7 24-24s-10.7-24-24-24l-80 0c-39.7 0-77.3 8.8-111 24.5 23.3-70 89.2-120.5 167-120.5 66.4 0 115.8-22.1 148.7-44 19.2-12.8 35.5-28.1 50.7-45.3z'/></g></svg>`;
        
        const canvas = document.getElementById('canvas');
        const ctx = canvas.getContext('2d');
        const DOMURL = window.URL || window.webkitURL || window;
        const img = new Image();
        const svg = new Blob([svgString], {type: 'image/svg+xml;charset=utf-8'});
        const url = DOMURL.createObjectURL(svg);
        
        img.onload = function() {
            ctx.drawImage(img, 0, 0);
            DOMURL.revokeObjectURL(url);
            // Extraer a formato PNG puro
            const pngData = canvas.toDataURL('image/png');
            
            // Enviar a PHP para que lo guarde en tu propia carpeta
            fetch('generate_png.php', {
                method: 'POST',
                headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                body: 'image=' + encodeURIComponent(pngData)
            }).then(r => r.text()).then(t => {
                document.getElementById('status').innerText = t;
            });
        };
        img.src = url;
    </script>
</body>
</html>
