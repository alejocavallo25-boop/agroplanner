<?php
/**
 * api/importar_insumos.php
 *
 * Recibe un archivo (o texto pegado), lo interpreta y devuelve la lista de
 * insumos detectados para que el usuario los revise antes de guardar.
 *
 * Este endpoint NO escribe nada en la base. La escritura ocurre después, cuando
 * el usuario confirma, y entra por el POST `import_guardar` de insumos.php.
 * La separación es a propósito: acá se puede fallar sin consecuencias.
 *
 * Tres orígenes:
 *   archivo   subida de .xlsx / .csv / .txt / .pdf
 *   texto     lo que el usuario pegó en el cuadro de "pegar tabla"
 *   remapeo   la grilla ya leída + un mapeo de columnas corregido a mano. Vuelve
 *             al servidor en vez de recalcularse en el navegador para que las
 *             reglas de interpretación vivan en un solo lugar.
 */

require_once '../config/auth.php';
require_agricultura();
require_once '../config/database.php';
require_once '../includes/importador_insumos.php';

header('Content-Type: application/json; charset=utf-8');

/** Corta con un JSON de error. */
function imp_error(string $mensaje, int $codigo = 400): void
{
    http_response_code($codigo);
    echo json_encode(['ok' => false, 'error' => $mensaje], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    imp_error('Método no permitido.', 405);
}

// validate_csrf() responde HTML y acá se espera JSON, así que se verifica a mano.
if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
    imp_error('Token de seguridad vencido. Recargá la página e intentá de nuevo.', 403);
}

$usuario_id = $_SESSION['usuario_id'];
$origen     = $_POST['origen'] ?? 'archivo';
$MAX_BYTES  = 8 * 1024 * 1024;   // 8 MB: de sobra para un remito o una lista de precios

/** Inventario actual: permite proponer "sumar al stock" en vez de duplicar. */
function imp_inventario(PDO $pdo, int $usuario_id): array
{
    $stmt = $pdo->prepare("SELECT id, nombre FROM insumos WHERE usuario_id = ? AND estado = 'activo' ORDER BY nombre");
    $stmt->execute([$usuario_id]);
    return $stmt->fetchAll();
}

// ─────────────────────────────────────────────────────────────────────────────
// RAMA 1: RE-MAPEO DE UNA GRILLA YA LEÍDA
// ─────────────────────────────────────────────────────────────────────────────

if ($origen === 'remapeo') {
    $grid  = json_decode((string)($_POST['grid'] ?? '[]'), true);
    $mapeo = json_decode((string)($_POST['mapeo'] ?? '{}'), true);

    if (!is_array($grid) || !is_array($mapeo)) {
        imp_error('Datos de re-mapeo inválidos.');
    }
    if (count($grid) > IMP_MAX_FILAS) {
        imp_error('Demasiadas filas.');
    }

    // La grilla vuelve del navegador: se la reconstruye acotada en vez de confiar
    // en la forma con la que llegó.
    $limpia = [];
    foreach ($grid as $fila) {
        if (!is_array($fila)) continue;
        $celdas = [];
        foreach (array_slice($fila, 0, 60) as $celda) {
            $celdas[] = is_scalar($celda) ? mb_substr(trim((string)$celda), 0, 300, 'UTF-8') : '';
        }
        $limpia[] = $celdas;
    }

    $camposOk = array_keys(imp_diccionario_campos());
    $mapeoOk  = [];
    foreach ($mapeo as $campo => $col) {
        if (in_array($campo, $camposOk, true) && is_numeric($col) && $col >= 0 && $col < 60) {
            $mapeoOk[$campo] = (int)$col;
        }
    }

    $encabezado = (($_POST['encabezado'] ?? '') === '') ? null : max(0, (int)$_POST['encabezado']);

    $items = imp_grid_a_items($limpia, ['modo' => 'tabla', 'encabezado' => $encabezado, 'mapeo' => $mapeoOk]);
    $items = imp_buscar_coincidencias($items, imp_inventario($pdo, $usuario_id));

    echo json_encode(['ok' => true, 'items' => array_values($items)], JSON_UNESCAPED_UNICODE);
    exit;
}

// ─────────────────────────────────────────────────────────────────────────────
// RAMA 2: TEXTO PEGADO
// ─────────────────────────────────────────────────────────────────────────────

if ($origen === 'texto') {
    $texto = (string)($_POST['texto'] ?? '');
    if (trim($texto) === '') {
        imp_error('No pegaste ningún texto.');
    }
    if (strlen($texto) > $MAX_BYTES) {
        imp_error('El texto es demasiado largo. Pegá de a partes.');
    }
    $tipo     = 'texto';
    $fuente   = $texto;
    $etiqueta = 'texto pegado';

// ─────────────────────────────────────────────────────────────────────────────
// RAMA 3: ARCHIVO SUBIDO
// ─────────────────────────────────────────────────────────────────────────────

} else {
    if (!isset($_FILES['archivo'])) {
        imp_error('No llegó ningún archivo.');
    }

    $f = $_FILES['archivo'];
    if ($f['error'] !== UPLOAD_ERR_OK) {
        $motivos = [
            UPLOAD_ERR_INI_SIZE   => 'El archivo supera el tamaño máximo que acepta el servidor.',
            UPLOAD_ERR_FORM_SIZE  => 'El archivo supera el tamaño máximo permitido.',
            UPLOAD_ERR_PARTIAL    => 'La subida se cortó por la mitad. Probá de nuevo.',
            UPLOAD_ERR_NO_FILE    => 'No seleccionaste ningún archivo.',
            UPLOAD_ERR_NO_TMP_DIR => 'El servidor no tiene carpeta temporal configurada.',
            UPLOAD_ERR_CANT_WRITE => 'El servidor no pudo escribir el archivo temporal.',
        ];
        imp_error($motivos[$f['error']] ?? 'No se pudo subir el archivo.');
    }

    if (!is_uploaded_file($f['tmp_name'])) {
        imp_error('Archivo inválido.');
    }
    if ($f['size'] <= 0) {
        imp_error('El archivo está vacío.');
    }
    if ($f['size'] > $MAX_BYTES) {
        imp_error('El archivo pesa más de 8 MB. Si es una lista de precios completa, exportá sólo las filas que necesitás.');
    }

    $ext      = strtolower(pathinfo((string)$f['name'], PATHINFO_EXTENSION));
    $etiqueta = basename((string)$f['name']);

    // Una foto es justamente el caso que este importador no cubre. Se explica el
    // porqué en vez de devolver "formato no soportado", que no le dice nada a nadie.
    if (in_array($ext, ['jpg', 'jpeg', 'png', 'heic', 'webp', 'gif', 'bmp', 'tif', 'tiff'], true)) {
        imp_error('Eso es una foto, y una foto son píxeles: para leerla haría falta OCR, que no forma parte de este importador. '
                . 'Funciona con Excel, CSV y PDF digital (los que genera un sistema, no los escaneados). '
                . 'Si tenés el remito sólo en papel, usá "Pegar tabla" y escribí los renglones.');
    }
    if ($ext === 'xls') {
        imp_error('El formato .xls es el binario viejo de Office y no se puede leer sin librerías externas. '
                . 'Abrilo en Excel y usá "Guardar como" → .xlsx o .csv: es un clic y lo lee sin problema.');
    }

    $mapa = ['csv' => 'csv', 'txt' => 'csv', 'tsv' => 'csv', 'xlsx' => 'xlsx', 'xlsm' => 'xlsx', 'pdf' => 'pdf'];
    if (!isset($mapa[$ext])) {
        imp_error('Formato no reconocido (.' . $ext . '). Se aceptan .xlsx, .csv, .txt y .pdf.');
    }

    $tipo   = $mapa[$ext];
    $fuente = $f['tmp_name'];

    // El contenido tiene que coincidir con la extensión.
    if (class_exists('finfo')) {
        $mime = (new finfo(FILEINFO_MIME_TYPE))->file($f['tmp_name']) ?: '';
        if ($tipo === 'pdf') {
            $ok = ($mime === 'application/pdf');
        } elseif ($tipo === 'xlsx') {
            $ok = in_array($mime, ['application/zip', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'], true);
        } else {
            $ok = (strncmp($mime, 'text/', 5) === 0)
               || in_array($mime, ['application/csv', 'application/vnd.ms-excel', 'application/octet-stream'], true);
        }
        if (!$ok) {
            if (strncmp($mime, 'image/', 6) === 0) {
                imp_error('El archivo es una imagen aunque tenga otra extensión. Este importador lee Excel, CSV y PDF digital, no fotos.');
            }
            imp_error('El contenido del archivo no coincide con su extensión (.' . $ext . ').');
        }
    }
}

// ─────────────────────────────────────────────────────────────────────────────
// PROCESAMIENTO
// ─────────────────────────────────────────────────────────────────────────────

try {
    $resultado = imp_procesar($tipo, $fuente, imp_inventario($pdo, $usuario_id));
} catch (RuntimeException $e) {
    // Errores previstos del parser: el mensaje ya está escrito para el usuario.
    imp_error($e->getMessage());
} catch (Throwable $e) {
    error_log('[importar_insumos] ' . $e->getMessage());
    imp_error('No se pudo procesar el archivo. Quedó el detalle en el log del servidor.', 500);
}

echo json_encode([
    'ok'         => true,
    'archivo'    => $etiqueta,
    'tipo'       => $tipo,
    'modo'       => $resultado['modo'],
    'encabezado' => $resultado['encabezado'],
    'mapeo'      => $resultado['mapeo'],
    'grid'       => $resultado['grid'],
    'items'      => $resultado['items'],
    'aviso'      => $resultado['aviso'],
], JSON_UNESCAPED_UNICODE);
