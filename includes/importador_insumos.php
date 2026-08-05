<?php
/**
 * includes/importador_insumos.php
 *
 * Lectura de comprobantes (remitos, listas de precios, plantillas) para la carga
 * asistida de insumos. Todo el parseo es determinístico y local: no sale ni un byte
 * del servidor, no hay API de por medio y no hay dependencias fuera de las
 * extensiones que ya trae PHP (zip, zlib, simplexml, mbstring).
 *
 * Qué puede leer y qué no
 * ───────────────────────
 *   .csv / .txt   sí. Detecta el separador (coma, punto y coma o tabulación).
 *   .xlsx         sí. Es un ZIP con XML adentro; se abre con ZipArchive.
 *   .pdf digital  sí, si el texto está adentro del archivo (lo generó un software).
 *   .pdf escaneado / foto   NO. Ahí sólo hay píxeles: haría falta OCR, que es otra
 *                 tecnología entera y no vive en este archivo. Se devuelve un aviso
 *                 explicando el motivo en vez de una lista vacía sin explicación.
 *   .xls (viejo)  NO. Formato binario de Office 97; se le pide al usuario que lo
 *                 guarde como .xlsx o .csv, que es un clic.
 *
 * El resultado nunca se guarda solo: esto devuelve una propuesta que el usuario
 * revisa y confirma en pantalla. Por eso el parser prefiere arriesgar una fila de
 * más (que se destilda) antes que perder una fila real.
 */

// Tope de filas que se devuelven al navegador. Un remito real tiene decenas de
// líneas; si un archivo trae miles es una lista de precios entera y conviene
// cortar antes de armar una tabla que el navegador no va a poder mostrar.
const IMP_MAX_FILAS = 400;

// ─────────────────────────────────────────────────────────────────────────────
// NORMALIZACIÓN Y CONVERSIONES
// ─────────────────────────────────────────────────────────────────────────────

/** Minúsculas, sin acentos y sin puntuación: la forma en que se comparan textos. */
function imp_normalizar(string $s): string
{
    $s = mb_strtolower(trim($s), 'UTF-8');
    $s = strtr($s, [
        'á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ü' => 'u', 'ñ' => 'n',
        'à' => 'a', 'è' => 'e', 'ì' => 'i', 'ò' => 'o', 'ù' => 'u', 'â' => 'a', 'ê' => 'e',
    ]);
    $s = preg_replace('/[^a-z0-9]+/u', ' ', $s);
    return trim(preg_replace('/\s+/', ' ', $s));
}

/**
 * Texto a número, con criterio argentino: el punto separa miles y la coma decimales.
 * Tolera símbolos de moneda y espacios. Devuelve null si no hay nada numérico.
 */
function imp_a_numero($valor): ?float
{
    $s = trim((string)$valor);
    if ($s === '') return null;

    $s = preg_replace('/[^\d.,\-]/', '', $s);
    if ($s === '' || $s === '-') return null;

    $coma  = strrpos($s, ',');
    $punto = strrpos($s, '.');

    if ($coma !== false && $punto !== false) {
        // Gana el separador que aparece más a la derecha: ése es el decimal.
        if ($coma > $punto) {
            $s = str_replace(['.', ','], ['', '.'], $s);
        } else {
            $s = str_replace(',', '', $s);
        }
    } elseif ($coma !== false) {
        // Coma sola: en es-AR es el decimal (1.234,56 → acá llega como "1234,56").
        $s = str_replace(',', '.', $s);
    } elseif ($punto !== false) {
        // Punto solo: es separador de miles únicamente si el patrón es 1.234 / 1.234.567.
        if (preg_match('/^-?\d{1,3}(\.\d{3})+$/', $s)) {
            $s = str_replace('.', '', $s);
        }
    }

    return is_numeric($s) ? (float)$s : null;
}

/**
 * Número de serie de Excel a fecha ISO.
 *
 * Excel cuenta días desde el 30/12/1899, que es justo 25569 días antes del epoch
 * de Unix. Se descartan los seriales menores a 61 para no pisar el famoso bug del
 * 29/02/1900 que Excel arrastra por compatibilidad con Lotus 1-2-3: ninguna fecha
 * de vencimiento real vive ahí.
 */
function imp_serial_a_fecha($serial): ?string
{
    $serial = (float)$serial;
    if ($serial < 61 || $serial > 2958465) return null;
    return gmdate('Y-m-d', (int)(($serial - 25569) * 86400));
}

/** Texto a fecha ISO. Acepta d/m/Y, d-m-Y, Y-m-d y seriales de Excel. */
function imp_a_fecha($valor): ?string
{
    $s = trim((string)$valor);
    if ($s === '') return null;

    if (preg_match('/^(\d{4})-(\d{2})-(\d{2})/', $s, $m)) {
        return checkdate((int)$m[2], (int)$m[3], (int)$m[1]) ? "$m[1]-$m[2]-$m[3]" : null;
    }
    if (preg_match('#^(\d{1,2})[/\-.](\d{1,2})[/\-.](\d{2,4})$#', $s, $m)) {
        $d = (int)$m[1]; $mes = (int)$m[2]; $a = (int)$m[3];
        if ($a < 100) $a += ($a < 70) ? 2000 : 1900;
        return checkdate($mes, $d, $a) ? sprintf('%04d-%02d-%02d', $a, $mes, $d) : null;
    }
    // Serial pelado de Excel, por si la celda llegó sin formato de fecha.
    if (preg_match('/^\d{4,5}(\.\d+)?$/', $s)) {
        return imp_serial_a_fecha($s);
    }
    return null;
}

/**
 * Adivina el tipo de insumo por el nombre. Es una ayuda, no un veredicto: el
 * usuario lo corrige en un select antes de guardar.
 */
function imp_adivinar_tipo(string $nombre): string
{
    $n = imp_normalizar($nombre);
    $reglas = [
        'inoculante'   => ['inoculante', 'rizobium', 'bradyrhizobium', 'nodulizante'],
        'semilla'      => ['semilla', 'soja', 'maiz', 'trigo', 'girasol', 'sorgo', 'cebada', 'alfalfa', 'hibrido'],
        'fertilizante' => ['fertiliz', 'urea', 'uan', 'fosfato', 'nitrogeno', 'potasio', 'azufre', 'sulfato', 'map', 'dap', 'mez fis'],
        'agroquimico'  => ['glifosato', 'herbicida', 'insecticida', 'fungicida', 'curasemilla', 'atrazina', 'cipermetrina', 'clorpirifos', 'dicamba', 'metsulfuron', '2 4 d', 'coadyuvante'],
    ];
    foreach ($reglas as $tipo => $claves) {
        foreach ($claves as $clave) {
            if (strpos($n, $clave) !== false) return $tipo;
        }
    }
    return 'otro';
}

/** Texto libre de unidad al enum unidad_medida de la tabla insumos. */
function imp_adivinar_unidad(string $texto): string
{
    $u = imp_normalizar($texto);
    if ($u === '') return 'kg';
    if (preg_match('/\b(kg|kilo|kilos|kilogramo)/', $u))          return 'kg';
    if (preg_match('/\b(lt|l|lts|litro|litros)\b/', $u))          return 'lt';
    if (preg_match('/\b(bolsa|bolsas|bls|bidon|bidones|tambor)/', $u)) return 'bolsa';
    if (preg_match('/\b(dosis|ds)\b/', $u))                       return 'dosis';
    return 'kg';
}

// ─────────────────────────────────────────────────────────────────────────────
// LECTURA DE ARCHIVOS
// ─────────────────────────────────────────────────────────────────────────────

/** Lee un archivo de texto pasándolo a UTF-8 y sacándole el BOM si lo tiene. */
function imp_leer_texto(string $ruta): string
{
    $raw = (string)file_get_contents($ruta);
    if (strncmp($raw, "\xEF\xBB\xBF", 3) === 0) $raw = substr($raw, 3);
    if (!mb_check_encoding($raw, 'UTF-8')) {
        // Los Excel viejos y los sistemas de facturación locales suelen exportar
        // en Windows-1252; sin esto los acentos y la "ñ" llegan rotos.
        $raw = mb_convert_encoding($raw, 'UTF-8', 'Windows-1252');
    }
    return $raw;
}

/**
 * Adivina el separador de un CSV, o devuelve null si el texto no está separado
 * en columnas.
 *
 * No alcanza con contar apariciones: en un remito pegado a mano la coma aparece
 * en los importes ("45.300,00") y contarla a secas la elegía como separador,
 * partiendo cada renglón al medio. El criterio real es la *consistencia*: una
 * tabla de verdad tiene la misma cantidad de separadores en casi todas las filas.
 * Además ';' y la tabulación ganan ante la coma, porque en los exports argentinos
 * la coma es el separador decimal y por eso se elige otro para las columnas.
 */
function imp_detectar_separador(string $texto): ?string
{
    $lineas = [];
    foreach (preg_split('/\r\n|\r|\n/', $texto) as $l) {
        if (trim($l) !== '') $lineas[] = $l;
        if (count($lineas) >= 20) break;
    }
    if (!$lineas) return null;

    $minimo    = max(2, (int)ceil(count($lineas) * 0.6));
    $prioridad = [';' => 4, "\t" => 4, '|' => 2, ',' => 0];

    $mejor = null; $mejorPunt = 0;
    foreach ($prioridad as $sep => $bonus) {
        $conteos = [];
        foreach ($lineas as $l) {
            $n = substr_count($l, $sep);
            if ($n > 0) $conteos[] = $n;
        }
        if (count($conteos) < $minimo) continue;

        $frec = array_count_values($conteos);
        arsort($frec);
        $modaCant = (int)array_key_first($frec);
        $modaFrec = $frec[$modaCant];
        if ($modaFrec < $minimo) continue;

        $punt = $modaFrec * 5 + $modaCant * 3 + $bonus;
        if ($punt > $mejorPunt) { $mejorPunt = $punt; $mejor = $sep; }
    }
    return $mejor;
}

/**
 * CSV/TSV/texto pegado a grilla de celdas.
 *
 * Si no hay un separador consistente, cada renglón queda como una única celda y
 * el mapeo posterior lo trabaja en "modo línea" con expresiones regulares. Ese es
 * el camino que recorre un remito tipeado o copiado de un mail.
 */
function imp_parse_texto_plano(string $texto): array
{
    $texto = str_replace(["\r\n", "\r"], "\n", $texto);
    $sep = imp_detectar_separador($texto);
    $grid = [];

    // str_getcsv línea por línea en vez de fgetcsv: acá el texto puede venir de un
    // textarea, no sólo de un archivo, y así los dos caminos comparten código.
    foreach (explode("\n", $texto) as $linea) {
        if (trim($linea) === '') continue;

        if ($sep === null) {
            $grid[] = [trim(preg_replace('/[ \t]+/', ' ', $linea))];
        } else {
            $celdas = str_getcsv($linea, $sep, '"', "\\");
            $celdas = array_map(fn($c) => trim((string)$c), $celdas);
            if (implode('', $celdas) === '') continue;
            $grid[] = $celdas;
        }

        if (count($grid) >= IMP_MAX_FILAS) break;
    }
    return $grid;
}

/** Convierte "A" → 0, "B" → 1, "AA" → 26. */
function imp_columna_a_indice(string $ref): int
{
    $letras = preg_replace('/[^A-Z]/', '', strtoupper($ref));
    $n = 0;
    for ($i = 0, $len = strlen($letras); $i < $len; $i++) {
        $n = $n * 26 + (ord($letras[$i]) - 64);
    }
    return max(0, $n - 1);
}

/**
 * .xlsx a grilla de celdas.
 *
 * Un .xlsx es un ZIP: las cadenas de texto viven compartidas en sharedStrings.xml
 * y la hoja sólo guarda índices. Los formatos de fecha se resuelven mirando el
 * numFmtId del estilo de cada celda, porque en el XML una fecha es un número suelto.
 */
function imp_parse_xlsx(string $ruta): array
{
    if (!class_exists('ZipArchive')) {
        throw new RuntimeException('El servidor no tiene la extensión zip de PHP, necesaria para leer .xlsx. Guardá el archivo como .csv.');
    }
    $zip = new ZipArchive();
    if ($zip->open($ruta) !== true) {
        throw new RuntimeException('No se pudo abrir el .xlsx. ¿Está completo el archivo?');
    }

    try {
        // ── Qué hoja leer: la primera del libro, resuelta por relaciones ──────
        $destino = 'xl/worksheets/sheet1.xml';
        $wb   = $zip->getFromName('xl/workbook.xml');
        $rels = $zip->getFromName('xl/_rels/workbook.xml.rels');
        if ($wb !== false && $rels !== false) {
            $xwb = @simplexml_load_string($wb);
            $xr  = @simplexml_load_string($rels);
            if ($xwb !== false && $xr !== false && isset($xwb->sheets->sheet[0])) {
                $rid = (string)$xwb->sheets->sheet[0]->attributes('r', true)['id'];
                foreach ($xr->Relationship as $rel) {
                    if ((string)$rel['Id'] === $rid) {
                        $destino = 'xl/' . ltrim((string)$rel['Target'], '/');
                        break;
                    }
                }
            }
        }

        // ── Guarda contra zip bombs: un remito no pesa 200 MB descomprimido ───
        $stat = $zip->statName($destino);
        if ($stat === false) {
            throw new RuntimeException('El .xlsx no tiene hojas legibles.');
        }
        if ($stat['size'] > 60 * 1024 * 1024) {
            throw new RuntimeException('La hoja es demasiado grande para procesar. Recortala o exportá sólo las filas que necesitás.');
        }

        // ── Textos compartidos ───────────────────────────────────────────────
        $textos = [];
        $ss = $zip->getFromName('xl/sharedStrings.xml');
        if ($ss !== false) {
            $xss = @simplexml_load_string($ss);
            if ($xss !== false) {
                foreach ($xss->si as $si) {
                    // Una celda con formato mixto se parte en varios <r><t>.
                    $txt = isset($si->t) ? (string)$si->t : '';
                    if ($txt === '' && isset($si->r)) {
                        foreach ($si->r as $r) $txt .= (string)$r->t;
                    }
                    $textos[] = $txt;
                }
            }
        }

        // ── Qué estilos representan fechas ───────────────────────────────────
        $estiloEsFecha = [];
        $st = $zip->getFromName('xl/styles.xml');
        if ($st !== false) {
            $xst = @simplexml_load_string($st);
            if ($xst !== false) {
                // Formatos de fecha que Office trae de fábrica.
                $ids = array_flip([14,15,16,17,18,19,20,21,22,27,28,29,30,31,32,33,34,35,36,45,46,47,50,51,52,53,54,55,56,57,58]);
                if (isset($xst->numFmts->numFmt)) {
                    foreach ($xst->numFmts->numFmt as $nf) {
                        $code = (string)$nf['formatCode'];
                        // Comillas o símbolo de moneda ⇒ es un formato de importe
                        // ("USD" tiene una D que si no confundiría al patrón).
                        if (!preg_match('/["$€]/', $code) && preg_match('/(y{2,}|d{1,4}|m{3,})/i', $code)) {
                            $ids[(int)$nf['numFmtId']] = true;
                        }
                    }
                }
                if (isset($xst->cellXfs->xf)) {
                    $i = 0;
                    foreach ($xst->cellXfs->xf as $xf) {
                        $estiloEsFecha[$i++] = isset($ids[(int)$xf['numFmtId']]);
                    }
                }
            }
        }

        // ── La hoja ──────────────────────────────────────────────────────────
        $hoja = $zip->getFromName($destino);
        if ($hoja === false) {
            throw new RuntimeException('El .xlsx no tiene hojas legibles.');
        }
        $xh = @simplexml_load_string($hoja);
        if ($xh === false || !isset($xh->sheetData)) {
            throw new RuntimeException('No se pudo interpretar la hoja del .xlsx.');
        }

        $grid = [];
        foreach ($xh->sheetData->row as $fila) {
            $celdas = [];
            $maxCol = -1;
            foreach ($fila->c as $c) {
                $idx  = isset($c['r']) ? imp_columna_a_indice((string)$c['r']) : $maxCol + 1;
                $tipo = (string)$c['t'];

                if ($tipo === 's') {                       // índice a sharedStrings
                    $v = $textos[(int)$c->v] ?? '';
                } elseif ($tipo === 'inlineStr') {
                    $v = isset($c->is->t) ? (string)$c->is->t : '';
                } elseif ($tipo === 'b') {
                    $v = ((string)$c->v === '1') ? 'SI' : 'NO';
                } else {
                    $v = isset($c->v) ? (string)$c->v : '';
                    $estilo = isset($c['s']) ? (int)$c['s'] : -1;
                    if ($v !== '' && is_numeric($v) && !empty($estiloEsFecha[$estilo])) {
                        $v = imp_serial_a_fecha($v) ?? $v;
                    }
                }

                $celdas[$idx] = trim($v);
                if ($idx > $maxCol) $maxCol = $idx;
            }
            if (!$celdas) continue;

            // Relleno los huecos para que todas las filas tengan la misma forma.
            $plana = [];
            for ($i = 0; $i <= $maxCol; $i++) $plana[] = $celdas[$i] ?? '';
            if (implode('', $plana) === '') continue;

            $grid[] = $plana;
            if (count($grid) >= IMP_MAX_FILAS) break;
        }
        return $grid;
    } finally {
        $zip->close();
    }
}

/**
 * Saca el texto de un stream de contenido de PDF.
 *
 * Un PDF no guarda "líneas": guarda órdenes de dibujo. Los operadores Tj y TJ
 * pintan texto y los Td/TD/Tm/T* mueven el cursor. Se recorre el stream a mano
 * acumulando lo que se pinta y cortando renglón cuando el cursor baja en Y.
 */
function imp_pdf_stream_a_texto(string $c): string
{
    $out = '';
    $chunk = '';
    $nums = [];
    $ultimaY = null;
    $n = strlen($c);
    $i = 0;

    while ($i < $n) {
        $ch = $c[$i];

        // Cadena literal: (texto con \( escapes \))
        if ($ch === '(') {
            $prof = 1; $i++; $s = '';
            while ($i < $n && $prof > 0) {
                $d = $c[$i];
                if ($d === '\\') {
                    $i++;
                    $e = $i < $n ? $c[$i] : '';
                    $mapa = ['n' => "\n", 'r' => "\r", 't' => "\t", 'b' => "\b", 'f' => "\f", '(' => '(', ')' => ')', '\\' => '\\'];
                    if (isset($mapa[$e])) { $s .= $mapa[$e]; $i++; }
                    elseif ($e !== '' && ctype_digit($e)) {
                        $oct = '';
                        while ($i < $n && ctype_digit($c[$i]) && strlen($oct) < 3) { $oct .= $c[$i]; $i++; }
                        $s .= chr(octdec($oct) & 0xFF);
                    } elseif ($e === "\n") { $i++; }
                    else { $s .= $e; $i++; }
                    continue;
                }
                if ($d === '(') { $prof++; $s .= $d; $i++; continue; }
                if ($d === ')') { $prof--; if ($prof > 0) $s .= $d; $i++; continue; }
                $s .= $d; $i++;
            }
            $chunk .= $s;
            continue;
        }

        // Cadena hexadecimal: <48656C6C6F>
        if ($ch === '<' && $i + 1 < $n && $c[$i + 1] !== '<') {
            $fin = strpos($c, '>', $i);
            if ($fin === false) break;
            $hex = preg_replace('/[^0-9A-Fa-f]/', '', substr($c, $i + 1, $fin - $i - 1));
            if (strlen($hex) % 2) $hex .= '0';
            $chunk .= (string)@hex2bin($hex);
            $i = $fin + 1;
            continue;
        }

        // Número (operando de un operador que viene después)
        if ($ch === '-' || $ch === '+' || $ch === '.' || ctype_digit($ch)) {
            $num = '';
            while ($i < $n && (ctype_digit($c[$i]) || strpos('+-.', $c[$i]) !== false)) { $num .= $c[$i]; $i++; }
            $nums[] = (float)$num;
            continue;
        }

        // Operador
        if (ctype_alpha($ch) || $ch === "'" || $ch === '"') {
            $op = '';
            while ($i < $n && (ctype_alpha($c[$i]) || $c[$i] === "'" || $c[$i] === '"' || $c[$i] === '*')) { $op .= $c[$i]; $i++; }

            if ($op === 'Tj' || $op === 'TJ') {
                $out .= $chunk; $chunk = '';
            } elseif ($op === "'" || $op === '"' || $op === 'T*') {
                $out .= $chunk . "\n"; $chunk = '';
            } elseif ($op === 'Td' || $op === 'TD') {
                // tx ty Td → si ty es 0 seguimos en el mismo renglón.
                $ty = count($nums) >= 1 ? end($nums) : 0.0;
                $out .= $chunk . (abs($ty) > 0.01 ? "\n" : ' ');
                $chunk = '';
            } elseif ($op === 'Tm') {
                // a b c d e f Tm → f es la coordenada Y.
                $y = count($nums) >= 6 ? $nums[count($nums) - 1] : null;
                $salto = ($ultimaY === null || $y === null || abs($y - $ultimaY) > 0.01);
                $out .= $chunk . ($salto ? "\n" : ' ');
                $chunk = '';
                if ($y !== null) $ultimaY = $y;
            } elseif ($op === 'ET' || $op === 'BT') {
                $out .= $chunk . "\n"; $chunk = '';
            }
            $nums = [];
            continue;
        }

        $i++;
    }
    return $out . $chunk;
}

/**
 * .pdf a grilla. Devuelve una celda por renglón: el PDF pierde la noción de
 * columnas, así que el mapeo posterior trabaja en "modo línea" con expresiones
 * regulares en vez de por columna.
 */
function imp_parse_pdf(string $ruta, ?string &$aviso = null): array
{
    $raw = (string)file_get_contents($ruta);

    if (strpos($raw, '/Encrypt') !== false) {
        throw new RuntimeException('El PDF está protegido con contraseña. Guardalo sin protección y volvé a subirlo.');
    }

    $texto = '';
    if (preg_match_all('/stream\r?\n?(.*?)endstream/s', $raw, $m)) {
        foreach ($m[1] as $s) {
            // La mayoría de los streams vienen comprimidos con FlateDecode. Se
            // prueban las dos variantes de zlib y, si ninguna anda, se asume que
            // el stream estaba en claro (o que es una imagen, y no aporta texto).
            $plano = @gzuncompress($s);
            if ($plano === false) $plano = @gzinflate($s);
            if ($plano === false) $plano = $s;
            if (strpos($plano, 'Tj') !== false || strpos($plano, 'TJ') !== false) {
                $texto .= imp_pdf_stream_a_texto($plano) . "\n";
            }
        }
    }

    $lineas = [];
    foreach (preg_split('/\n+/', $texto) as $l) {
        $l = trim(preg_replace('/[ \t]+/', ' ', $l));
        if ($l !== '') $lineas[] = [$l];
        if (count($lineas) >= IMP_MAX_FILAS) break;
    }

    if (!$lineas) {
        $aviso = 'Este PDF no tiene texto adentro: es una imagen escaneada o una foto guardada como PDF. '
               . 'Para leerlo haría falta OCR, que no es parte de este importador. '
               . 'Probá pedirle al proveedor el comprobante en Excel, CSV o PDF digital, o cargá los ítems con el botón de pegar tabla.';
    }
    return $lineas;
}

// ─────────────────────────────────────────────────────────────────────────────
// MAPEO: DE LA GRILLA A LOS ÍTEMS
// ─────────────────────────────────────────────────────────────────────────────

/** Palabras que identifican cada campo en un encabezado de tabla. */
function imp_diccionario_campos(): array
{
    return [
        'nombre'      => ['nombre', 'descripcion', 'denominacion', 'detalle', 'producto', 'insumo', 'articulo', 'item', 'concepto', 'mercaderia'],
        'cantidad'    => ['cantidad', 'cant', 'ctd', 'stock', 'unidades', 'bultos'],
        'unidad'      => ['unidad', 'um', 'medida', 'presentacion', 'envase'],
        'precio'      => ['precio', 'unitario', 'costo', 'p unit', 'punit'],
        'vencimiento' => ['vencimiento', 'vto', 'vence', 'caducidad', 'expira'],
        'tipo'        => ['tipo', 'rubro', 'categoria', 'familia'],
        'referencia'  => ['referencia', 'ref', 'codigo', 'cod', 'sku'],
    ];
}

/**
 * Busca la fila de encabezado y mapea columna → campo.
 *
 * Devuelve ['modo' => 'tabla'|'linea', 'encabezado' => int|null, 'mapeo' => [campo => col]].
 * Si no hay encabezado reconocible pero sí varias columnas, deduce por el contenido:
 * la columna con textos más largos es el nombre y la numérica de al lado, la cantidad.
 */
function imp_mapear(array $grid): array
{
    $anchoMax = 0;
    foreach ($grid as $fila) $anchoMax = max($anchoMax, count($fila));

    if ($anchoMax <= 1) {
        return ['modo' => 'linea', 'encabezado' => null, 'mapeo' => []];
    }

    $dic = imp_diccionario_campos();

    // ── 1. Encabezado explícito ──────────────────────────────────────────────
    $mejorFila = null; $mejorMapeo = []; $mejorPuntaje = 0;
    $limite = min(count($grid), 25);   // el encabezado siempre está arriba

    for ($f = 0; $f < $limite; $f++) {
        $mapeo = []; $puntaje = 0;
        foreach ($grid[$f] as $col => $celda) {
            $n = imp_normalizar((string)$celda);
            if ($n === '') continue;
            foreach ($dic as $campo => $claves) {
                if (isset($mapeo[$campo])) continue;
                foreach ($claves as $clave) {
                    if ($n === $clave || strpos($n, $clave) === 0) {
                        $mapeo[$campo] = $col;
                        $puntaje++;
                        break 2;
                    }
                }
            }
        }
        // Sin nombre no hay tabla que valga; y con un solo acierto es casualidad.
        if (isset($mapeo['nombre']) && $puntaje >= 2 && $puntaje > $mejorPuntaje) {
            $mejorPuntaje = $puntaje; $mejorFila = $f; $mejorMapeo = $mapeo;
        }
    }

    if ($mejorFila !== null) {
        return ['modo' => 'tabla', 'encabezado' => $mejorFila, 'mapeo' => $mejorMapeo];
    }

    // ── 2. Sin encabezado: se deduce mirando el contenido ─────────────────────
    $largoTexto = array_fill(0, $anchoMax, 0);
    $esNumero   = array_fill(0, $anchoMax, 0);
    $conDecimal = array_fill(0, $anchoMax, 0);
    $filas = 0;

    foreach (array_slice($grid, 0, 40) as $fila) {
        $filas++;
        for ($c = 0; $c < $anchoMax; $c++) {
            $v = trim((string)($fila[$c] ?? ''));
            if ($v === '') continue;
            if (imp_a_numero($v) !== null && preg_match('/^[\s$.,\-\d]+$/', $v)) {
                $esNumero[$c]++;
                if (preg_match('/[.,]\d{2}\b/', $v)) $conDecimal[$c]++;
            } else {
                $largoTexto[$c] += mb_strlen($v, 'UTF-8');
            }
        }
    }
    if ($filas === 0) return ['modo' => 'linea', 'encabezado' => null, 'mapeo' => []];

    $mapeo = [];
    $colNombre = array_keys($largoTexto, max($largoTexto))[0];
    if ($largoTexto[$colNombre] > 0) $mapeo['nombre'] = $colNombre;

    // Entre las numéricas: la que tiene decimales parece precio, la entera cantidad.
    $candPrecio = null; $candCantidad = null;
    for ($c = 0; $c < $anchoMax; $c++) {
        if ($c === $colNombre || $esNumero[$c] < max(1, (int)($filas * 0.4))) continue;
        if ($conDecimal[$c] > $esNumero[$c] / 2) {
            if ($candPrecio === null) $candPrecio = $c;
        } elseif ($candCantidad === null) {
            $candCantidad = $c;
        }
    }
    if ($candCantidad !== null) $mapeo['cantidad'] = $candCantidad;
    if ($candPrecio !== null)   $mapeo['precio']   = $candPrecio;

    if (!isset($mapeo['nombre'])) {
        return ['modo' => 'linea', 'encabezado' => null, 'mapeo' => []];
    }
    return ['modo' => 'tabla', 'encabezado' => null, 'mapeo' => $mapeo];
}

/**
 * Lee una línea suelta (PDF o texto sin columnas) buscando el patrón típico de
 * un renglón de remito: cantidad adelante, descripción después y, si aparece,
 * un importe al final.
 *
 *     1    Trasmission Shaft Pompe 3" or 4"          →  cant 1, nombre "Trasmission…"
 *     2 bolsas Urea granulada        $ 45.300,00     →  cant 2, nombre "Urea…", precio 45300
 */
function imp_linea_a_item(string $linea): ?array
{
    $l = trim($linea);
    if ($l === '' || mb_strlen($l, 'UTF-8') < 4) return null;

    // Encabezados y pies de página del comprobante: no son mercadería.
    $n = imp_normalizar($l);
    $ruido = ['cantidad denominacion', 'observaciones', 'documento no valido', 'iva responsable',
              'transporte', 'cuit', 'remito', 'factura', 'fecha', 'total', 'subtotal', 'domicilio',
              'condicion de venta', 'imprenta', 'c a i', 'senores', 'sr es'];
    foreach ($ruido as $r) {
        if (strpos($n, $r) === 0) return null;
    }

    $precio = null;
    // Importe al final, con símbolo o con dos decimales. Se recorta antes de
    // quedarse con el nombre para que no termine pegado a la descripción.
    if (preg_match('/\s(\$\s*[\d.,]+|\d{1,3}(?:\.\d{3})+(?:,\d{2})?|\d+,\d{2})\s*$/u', $l, $m)) {
        $p = imp_a_numero($m[1]);
        if ($p !== null && $p > 0) {
            $precio = $p;
            $l = trim(substr($l, 0, -strlen($m[0])));
        }
    }

    // Cantidad al principio, con o sin unidad pegada.
    if (!preg_match('/^(\d{1,6}(?:[.,]\d{1,3})?)\s*(kgs?|kilos?|lts?|litros?|bolsas?|bidones?|tambores?|un|u|ds|dosis|cajas?|x)?\s+(.{3,})$/iu', $l, $m)) {
        return null;
    }

    $cantidad = imp_a_numero($m[1]);
    $unidad   = trim($m[2] ?? '');
    $nombre   = trim($m[3]);

    // Una descripción que es puro número o código no es un insumo.
    if ($nombre === '' || !preg_match('/\p{L}{3,}/u', $nombre)) return null;

    // Referencias sueltas al final (2008-7633-680) se guardan aparte del nombre.
    $referencia = '';
    if (preg_match('/\s((?:[A-Z0-9]{2,}-){1,}[A-Z0-9]{2,})\s*$/u', $nombre, $mr)) {
        $referencia = $mr[1];
        $nombre = trim(substr($nombre, 0, -strlen($mr[0])));
    }

    return [
        'nombre'      => $nombre,
        'cantidad'    => $cantidad,
        'unidad'      => $unidad,
        'precio'      => $precio,
        'vencimiento' => null,
        'tipo'        => null,
        'referencia'  => $referencia,
    ];
}

/** Aplica el mapeo sobre la grilla y devuelve la lista de ítems propuestos. */
function imp_grid_a_items(array $grid, array $info): array
{
    $items = [];

    if ($info['modo'] === 'linea') {
        foreach ($grid as $fila) {
            $item = imp_linea_a_item((string)($fila[0] ?? ''));
            if ($item) $items[] = $item;
        }
    } else {
        $mapeo = $info['mapeo'];
        $desde = ($info['encabezado'] === null) ? 0 : $info['encabezado'] + 1;

        foreach (array_slice($grid, $desde) as $fila) {
            $val = function (string $campo) use ($fila, $mapeo) {
                if (!isset($mapeo[$campo])) return '';
                return trim((string)($fila[$mapeo[$campo]] ?? ''));
            };

            $nombre = $val('nombre');
            if ($nombre === '' || !preg_match('/\p{L}{2,}/u', $nombre)) continue;

            // Fila de totales colada al final de la tabla.
            if (preg_match('/^(total|subtotal|iva|neto)\b/i', $nombre)) continue;

            $items[] = [
                'nombre'      => $nombre,
                'cantidad'    => imp_a_numero($val('cantidad')),
                'unidad'      => $val('unidad'),
                'precio'      => imp_a_numero($val('precio')),
                'vencimiento' => imp_a_fecha($val('vencimiento')),
                'tipo'        => $val('tipo'),
                'referencia'  => $val('referencia'),
            ];
        }
    }

    // Normalización final y valores por defecto editables en pantalla.
    $tipos = ['semilla', 'fertilizante', 'agroquimico', 'inoculante', 'otro'];
    foreach ($items as &$it) {
        $tipoTexto = imp_normalizar((string)$it['tipo']);
        $it['tipo']          = in_array($tipoTexto, $tipos, true) ? $tipoTexto : imp_adivinar_tipo($it['nombre']);
        $it['unidad_medida'] = imp_adivinar_unidad((string)$it['unidad']);
        $it['unidad_stock']  = trim((string)$it['unidad']);
        $it['cantidad']      = $it['cantidad'] !== null ? max(0, $it['cantidad']) : 0;
        $it['precio']        = $it['precio']   !== null ? max(0, $it['precio'])   : 0;
        $it['nombre']        = mb_substr(preg_replace('/\s+/', ' ', $it['nombre']), 0, 150, 'UTF-8');
        unset($it['unidad']);
    }
    unset($it);

    return $items;
}

// ─────────────────────────────────────────────────────────────────────────────
// COINCIDENCIAS CON EL INVENTARIO
// ─────────────────────────────────────────────────────────────────────────────

/**
 * Marca cada ítem con el insumo existente al que se parece, para que la pantalla
 * pueda ofrecer "sumar al stock de X" en vez de crear un duplicado.
 *
 * El criterio es conservador a propósito: ante la duda propone crear uno nuevo,
 * porque sumarle stock al insumo equivocado ensucia la valuación del depósito y
 * es más difícil de detectar que un duplicado a la vista.
 */
function imp_buscar_coincidencias(array $items, array $inventario): array
{
    $normalizado = [];
    foreach ($inventario as $ins) {
        $normalizado[] = ['id' => (int)$ins['id'], 'nombre' => $ins['nombre'], 'norm' => imp_normalizar($ins['nombre'])];
    }

    foreach ($items as &$it) {
        $it['match_id']     = null;
        $it['match_nombre'] = null;

        $objetivo = imp_normalizar($it['nombre']);
        if ($objetivo === '') continue;

        $mejor = null; $mejorPunt = 0.0;
        foreach ($normalizado as $cand) {
            if ($cand['norm'] === '') continue;

            if ($cand['norm'] === $objetivo) { $mejor = $cand; $mejorPunt = 1.0; break; }

            similar_text($objetivo, $cand['norm'], $pct);
            $punt = $pct / 100;

            // Que uno contenga al otro vale mucho ("urea" vs "urea granulada").
            if (strpos($cand['norm'], $objetivo) !== false || strpos($objetivo, $cand['norm']) !== false) {
                $punt = max($punt, 0.90);
            }
            if ($punt > $mejorPunt) { $mejorPunt = $punt; $mejor = $cand; }
        }

        if ($mejor !== null && $mejorPunt >= 0.86) {
            $it['match_id']     = $mejor['id'];
            $it['match_nombre'] = $mejor['nombre'];
        }
    }
    unset($it);

    return $items;
}

// ─────────────────────────────────────────────────────────────────────────────
// ENTRADA ÚNICA
// ─────────────────────────────────────────────────────────────────────────────

/**
 * Procesa un origen y devuelve todo lo que la pantalla necesita.
 *
 * @param string $tipo  'csv' | 'xlsx' | 'pdf' | 'texto'
 * @param string $ruta  ruta al archivo (o el texto pegado, si $tipo === 'texto')
 */
function imp_procesar(string $tipo, string $ruta, array $inventario): array
{
    $aviso = null;

    switch ($tipo) {
        case 'xlsx':
            $grid = imp_parse_xlsx($ruta);
            break;
        case 'pdf':
            $grid = imp_parse_pdf($ruta, $aviso);
            break;
        case 'texto':
            $grid = imp_parse_texto_plano($ruta);
            break;
        case 'csv':
        default:
            $grid = imp_parse_texto_plano(imp_leer_texto($ruta));
            break;
    }

    $info  = imp_mapear($grid);
    $items = imp_grid_a_items($grid, $info);
    $items = imp_buscar_coincidencias($items, $inventario);

    if (!$items && $aviso === null) {
        $aviso = $grid
            ? 'Se leyó el archivo pero no se reconoció ninguna fila de insumos. Revisá que haya una columna con el nombre o descripción del producto.'
            : 'El archivo está vacío o no se pudo leer su contenido.';
    }

    return [
        'modo'       => $info['modo'],
        'encabezado' => $info['encabezado'],
        'mapeo'      => $info['mapeo'],
        'grid'       => $grid,
        'items'      => array_values($items),
        'aviso'      => $aviso,
    ];
}
