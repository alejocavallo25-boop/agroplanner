<?php
/* includes/presupuestos.php — Pedidos de presupuesto de insumos.
 *
 * El circuito: se arma el pedido (insumos, cantidades), se baja un Excel para
 * mandar a cada proveedor, el proveedor lo completa y lo devuelve, se sube, y
 * la pantalla compara las ofertas renglón por renglón.
 *
 * EL EXCEL ES UN .xlsx DE VERDAD, armado a mano (es un ZIP con XML adentro). No
 * el truco de HTML con extensión .xls que usan los reportes: ese archivo Excel
 * lo abre con una advertencia de "formato y extensión no coinciden", y al
 * guardarlo el proveedor puede devolver cualquier cosa. Un .xlsx se abre
 * limpio, se completa y vuelve como .xlsx, que el importador ya sabe leer.
 *
 * CÓMO SE RECONOCE CADA RENGLÓN AL VOLVER: la columna "Cód." trae el número de
 * renglón del pedido, y el encabezado trae el número de pedido (PP-000045). Se
 * busca por eso y no por la posición: el proveedor puede ordenar, agregar una
 * fila de aclaración o borrar las que no cotiza, y sigue andando.
 *
 * COMPARAR EN MONEDAS DISTINTAS: si todas las ofertas están en la misma moneda
 * se compara en esa. Si se mezclan, se pasa a dólares con el dólar de
 * referencia de la app SÓLO PARA COMPARAR, y la pantalla lo dice. No se guarda
 * nada convertido: cada oferta queda en la moneda en que vino.
 */

require_once __DIR__ . '/importador_insumos.php';   // imp_parse_xlsx, imp_a_numero, imp_normalizar

const PP_MAX_ITEMS = 150;

function pp_codigo(int $pedido_id): string
{
    return 'PP-' . str_pad((string)$pedido_id, 6, '0', STR_PAD_LEFT);
}

function pp_tablas_listas(PDO $pdo): bool
{
    static $listas = null;
    if ($listas === null) {
        try { $pdo->query("SELECT 1 FROM presupuesto_precios LIMIT 0"); $listas = true; }
        catch (Throwable $e) { $listas = false; }
    }
    return $listas;
}

/** "US$", "u$s", "dólares", "USD" → USD; "$", "pesos", "ARS" → ARS; si no dice, la de reserva. */
function pp_moneda_de(string $texto, string $reserva): string
{
    $t = mb_strtolower(trim($texto));
    if ($t === '') return $reserva;
    if (preg_match('/u\$s|us\$|usd|d[oó]lar/u', $t)) return 'USD';
    if (preg_match('/^\$|\$\s*$|pesos?|ars/u', $t)) return 'ARS';
    return $reserva;
}

/* ─── El pedido que se carga a mano ───────────────────────────────────────── */

/**
 * Valida el pedido que arma el productor. $insumos_propios: [id => true].
 * Devuelve [pedido, items, errores].
 */
function pp_normalizar_pedido(array $p, array $insumos_propios): array
{
    $err = [];
    $titulo = mb_substr(trim((string)($p['titulo'] ?? '')), 0, 150);
    if ($titulo === '') $err[] = 'Poné un nombre al pedido (ej: "Herbicidas campaña 26/27").';
    $limite = trim((string)($p['fecha_limite'] ?? ''));
    if ($limite !== '') {
        $d = DateTime::createFromFormat('Y-m-d', $limite);
        if (!$d || $d->format('Y-m-d') !== $limite) { $err[] = 'La fecha límite no es válida.'; $limite = ''; }
    }
    $pedido = [
        'titulo' => $titulo,
        'moneda' => ($p['moneda'] ?? '') === 'ARS' ? 'ARS' : 'USD',
        'fecha_limite' => $limite ?: null,
        'notas' => mb_substr(trim((string)($p['notas'] ?? '')), 0, 500) ?: null,
    ];

    $items = [];
    foreach ((array)($p['items'] ?? []) as $i) {
        if (!is_array($i)) continue;
        $id = (int)($i['insumo_id'] ?? 0) ?: null;
        if ($id && !isset($insumos_propios[$id])) $id = null;
        $desc = mb_substr(trim(preg_replace('/\s+/u', ' ', (string)($i['descripcion'] ?? ''))), 0, 200);
        if ($desc === '') continue;   // renglón vacío
        $cant = imp_a_numero((string)($i['cantidad'] ?? '')) ?? 0;
        if ($cant <= 0) { $err[] = "Falta la cantidad de \"$desc\"."; continue; }
        $items[] = [
            'insumo_id' => $id, 'descripcion' => $desc, 'cantidad' => round($cant, 3),
            'unidad' => mb_substr(trim((string)($i['unidad'] ?? '')), 0, 30) ?: null,
        ];
    }
    if (!$items && !$err) $err[] = 'Agregá al menos un insumo al pedido.';
    if (count($items) > PP_MAX_ITEMS) $err[] = 'Son demasiados renglones para un pedido: partilo en dos.';
    foreach ($items as $n => &$it) $it['orden'] = $n + 1;
    unset($it);
    return [$pedido, $items, array_values(array_unique($err))];
}

/* ─── El Excel para mandar ────────────────────────────────────────────────── */

/** "A" + 1 → "A1"; columna por número (0 = A). */
function pp_celda(int $col, int $fila): string
{
    $l = '';
    for ($n = $col + 1; $n > 0; $n = intdiv($n - 1, 26)) $l = chr(65 + ($n - 1) % 26) . $l;
    return $l . $fila;
}

function pp_xml(string $s): string
{
    // Fuera los caracteres de control que XML no admite: un nombre pegado de otro lado puede traerlos.
    $s = preg_replace('/[^\x09\x0A\x0D\x20-\x{D7FF}\x{E000}-\x{FFFD}]/u', '', $s);
    return htmlspecialchars($s, ENT_XML1 | ENT_QUOTES, 'UTF-8');
}

/**
 * El .xlsx del pedido, como bytes.
 *
 * Estilos (índice en cellXfs): 1 título · 2 rótulo · 3 encabezado de tabla ·
 * 4 texto con borde · 5 número con borde · 6 celda a completar (texto) ·
 * 7 celda a completar (número) · 8 total calculado · 9 nota gris.
 * La hoja va protegida SIN contraseña, sólo para que no se borre una columna
 * sin querer: las celdas amarillas quedan libres. Quien quiera, la desprotege.
 */
function pp_xlsx(array $pedido, array $items, string $solicita, string $para = '', string $firma = ''): string
{
    $filas = [];   // número de fila => [celdas xml]
    $txt = function (int $c, int $f, string $v, int $s) use (&$filas) {
        $filas[$f][$c] = '<c r="' . pp_celda($c, $f) . '" t="inlineStr" s="' . $s . '"><is><t xml:space="preserve">' . pp_xml($v) . '</t></is></c>';
    };
    $num = function (int $c, int $f, ?float $v, int $s) use (&$filas) {
        $filas[$f][$c] = '<c r="' . pp_celda($c, $f) . '" s="' . $s . '">' . ($v === null ? '' : '<v>' . rtrim(rtrim(number_format($v, 4, '.', ''), '0'), '.') . '</v>') . '</c>';
    };
    $form = function (int $c, int $f, string $formula, int $s) use (&$filas) {
        $filas[$f][$c] = '<c r="' . pp_celda($c, $f) . '" s="' . $s . '"><f>' . pp_xml($formula) . '</f></c>';
    };
    $vacia = function (int $c, int $f, int $s) use (&$filas) {
        $filas[$f][$c] = '<c r="' . pp_celda($c, $f) . '" s="' . $s . '"/>';
    };

    $txt(0, 1, 'PEDIDO DE PRESUPUESTO', 1);
    $txt(0, 2, 'Pedido', 2);            $txt(1, 2, pp_codigo((int)$pedido['id']), 4);
    $txt(4, 2, 'Fecha', 2);             $txt(5, 2, date('d/m/Y'), 4);
    $txt(0, 3, 'Solicita', 2);          $txt(1, 3, $solicita, 4);
    $txt(4, 3, 'Responder antes del', 2);
    $txt(5, 3, $pedido['fecha_limite'] ? date('d/m/Y', strtotime($pedido['fecha_limite'])) : '—', 4);
    $txt(0, 4, 'Detalle', 2);           $txt(1, 4, $pedido['titulo'] . ($pedido['notas'] ? ' · ' . $pedido['notas'] : ''), 4);
    $txt(0, 6, 'Completá las celdas amarillas y devolvé este mismo archivo. No cambies los códigos de la columna "Cód.".', 9);

    $txt(0, 7, 'Proveedor', 2);         $txt(1, 7, $para, 6); $vacia(2, 7, 6); $vacia(3, 7, 6);
    $txt(4, 7, 'Moneda (US$ o $)', 2);  $txt(5, 7, $pedido['moneda'] === 'USD' ? 'US$' : '$', 6);
    $txt(0, 8, 'Condición de pago', 2); $vacia(1, 8, 6); $vacia(2, 8, 6); $vacia(3, 8, 6);
    $txt(4, 8, 'Plazo de entrega', 2);  $vacia(5, 8, 6);
    $txt(0, 9, 'Validez de la oferta', 2); $vacia(1, 9, 6); $vacia(2, 9, 6); $vacia(3, 9, 6);

    $enc = ['Cód.', 'Insumo', 'Cantidad', 'Unidad', 'Precio unitario', 'Total', 'Marca / alternativa', 'Observaciones'];
    foreach ($enc as $c => $t) $txt($c, 11, $t, 3);
    $f = 12;
    foreach ($items as $it) {
        $num(0, $f, (float)$it['orden'], 5);
        $txt(1, $f, $it['descripcion'], 4);
        $num(2, $f, (float)$it['cantidad'], 5);
        $txt(3, $f, (string)($it['unidad'] ?? ''), 4);
        $num(4, $f, null, 7);
        $form(5, $f, 'IF(E' . $f . '="","",C' . $f . '*E' . $f . ')', 8);
        $vacia(6, $f, 6);
        $vacia(7, $f, 6);
        $f++;
    }
    $ultima = $f - 1;
    $txt(4, $f, 'Total', 2);
    $form(5, $f, 'SUM(F12:F' . $ultima . ')', 8);
    if ($firma !== '') $txt(0, $f + 2, $firma, 9);

    ksort($filas);
    $sheetData = '';
    foreach ($filas as $n => $celdas) {
        ksort($celdas);
        $alto = $n === 1 ? ' ht="24" customHeight="1"' : '';
        $sheetData .= '<row r="' . $n . '"' . $alto . '>' . implode('', $celdas) . '</row>';
    }

    $sheet = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
        . '<sheetViews><sheetView workbookViewId="0" showGridLines="0"><pane ySplit="11" topLeftCell="A12" activePane="bottomLeft" state="frozen"/></sheetView></sheetViews>'
        . '<cols><col min="1" max="1" width="7" customWidth="1"/><col min="2" max="2" width="44" customWidth="1"/>'
        . '<col min="3" max="3" width="12" customWidth="1"/><col min="4" max="4" width="10" customWidth="1"/>'
        . '<col min="5" max="5" width="18" customWidth="1"/><col min="6" max="6" width="16" customWidth="1"/>'
        . '<col min="7" max="7" width="26" customWidth="1"/><col min="8" max="8" width="32" customWidth="1"/></cols>'
        . '<sheetData>' . $sheetData . '</sheetData>'
        . '<sheetProtection sheet="1" objects="1" scenarios="1" formatColumns="0" formatRows="0" insertRows="0" sort="0" autoFilter="0"/>'
        . '<mergeCells count="5"><mergeCell ref="B4:H4"/><mergeCell ref="A6:H6"/><mergeCell ref="B7:D7"/><mergeCell ref="B8:D8"/><mergeCell ref="B9:D9"/></mergeCells>'
        . '<dataValidations count="1"><dataValidation type="list" allowBlank="1" showErrorMessage="1" sqref="F7"><formula1>"US$,$"</formula1></dataValidation></dataValidations>'
        . '<pageMargins left="0.5" right="0.5" top="0.6" bottom="0.6" header="0.3" footer="0.3"/>'
        . '<pageSetup orientation="landscape" paperSize="9" fitToWidth="1" fitToHeight="0"/>'
        . '</worksheet>';

    $styles = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
        . '<numFmts count="1"><numFmt numFmtId="164" formatCode="#,##0.00"/></numFmts>'
        . '<fonts count="5">'
        . '<font><sz val="11"/><name val="Calibri"/></font>'
        . '<font><b/><sz val="11"/><name val="Calibri"/></font>'
        . '<font><b/><sz val="16"/><color rgb="FF1F5E3B"/><name val="Calibri"/></font>'
        . '<font><i/><sz val="10"/><color rgb="FF64748B"/><name val="Calibri"/></font>'
        . '<font><b/><sz val="11"/><color rgb="FFFFFFFF"/><name val="Calibri"/></font>'
        . '</fonts>'
        . '<fills count="4"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill>'
        . '<fill><patternFill patternType="solid"><fgColor rgb="FFFFF4C2"/><bgColor indexed="64"/></patternFill></fill>'
        . '<fill><patternFill patternType="solid"><fgColor rgb="FF1F5E3B"/><bgColor indexed="64"/></patternFill></fill></fills>'
        . '<borders count="2"><border><left/><right/><top/><bottom/><diagonal/></border>'
        . '<border><left style="thin"><color rgb="FFCBD5E1"/></left><right style="thin"><color rgb="FFCBD5E1"/></right>'
        . '<top style="thin"><color rgb="FFCBD5E1"/></top><bottom style="thin"><color rgb="FFCBD5E1"/></bottom><diagonal/></border></borders>'
        . '<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
        . '<cellXfs count="10">'
        . '<xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/>'
        . '<xf numFmtId="0" fontId="2" fillId="0" borderId="0" xfId="0" applyFont="1"/>'
        . '<xf numFmtId="0" fontId="1" fillId="0" borderId="0" xfId="0" applyFont="1"/>'
        . '<xf numFmtId="0" fontId="4" fillId="3" borderId="1" xfId="0" applyFont="1" applyFill="1" applyBorder="1" applyAlignment="1"><alignment horizontal="center" vertical="center" wrapText="1"/></xf>'
        . '<xf numFmtId="0" fontId="0" fillId="0" borderId="1" xfId="0" applyBorder="1" applyAlignment="1"><alignment vertical="center" wrapText="1"/></xf>'
        . '<xf numFmtId="164" fontId="0" fillId="0" borderId="1" xfId="0" applyNumberFormat="1" applyBorder="1"/>'
        . '<xf numFmtId="0" fontId="0" fillId="2" borderId="1" xfId="0" applyFill="1" applyBorder="1" applyProtection="1"><protection locked="0"/></xf>'
        . '<xf numFmtId="164" fontId="0" fillId="2" borderId="1" xfId="0" applyNumberFormat="1" applyFill="1" applyBorder="1" applyProtection="1"><protection locked="0"/></xf>'
        . '<xf numFmtId="164" fontId="1" fillId="0" borderId="1" xfId="0" applyNumberFormat="1" applyFont="1" applyBorder="1"/>'
        . '<xf numFmtId="0" fontId="3" fillId="0" borderId="0" xfId="0" applyFont="1"/>'
        . '</cellXfs>'
        . '<cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles>'
        . '</styleSheet>';

    $archivos = [
        '[Content_Types].xml' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
            . '<Default Extension="xml" ContentType="application/xml"/>'
            . '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
            . '<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
            . '<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>'
            . '</Types>',
        '_rels/.rels' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
            . '</Relationships>',
        'xl/workbook.xml' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
            . '<sheets><sheet name="Presupuesto" sheetId="1" r:id="rId1"/></sheets>'
            . '<calcPr calcId="0" fullCalcOnLoad="1"/></workbook>',
        'xl/_rels/workbook.xml.rels' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>'
            . '<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>'
            . '</Relationships>',
        'xl/styles.xml' => $styles,
        'xl/worksheets/sheet1.xml' => $sheet,
    ];

    $tmp = tempnam(sys_get_temp_dir(), 'pp');
    $zip = new ZipArchive();
    if ($zip->open($tmp, ZipArchive::OVERWRITE) !== true) throw new RuntimeException('No se pudo armar el Excel.');
    foreach ($archivos as $nombre => $contenido) $zip->addFromString($nombre, $contenido);
    $zip->close();
    $bytes = (string)file_get_contents($tmp);
    @unlink($tmp);
    return $bytes;
}

/* ─── La respuesta del proveedor ──────────────────────────────────────────── */

/**
 * Lee el Excel (ya pasado a grilla) que devolvió un proveedor.
 *
 * Devuelve ['codigo' => 'PP-…'|null, 'proveedor', 'moneda_texto', 'condicion_pago',
 * 'plazo_entrega', 'validez', 'precios' => [orden => ['precio','marca','observacion']],
 * 'error' => null|string].
 */
function pp_leer_respuesta(array $grid): array
{
    $r = ['codigo' => null, 'proveedor' => '', 'moneda_texto' => '', 'condicion_pago' => '',
          'plazo_entrega' => '', 'validez' => '', 'precios' => [], 'error' => null];
    $n = fn($s) => imp_normalizar((string)$s);

    $rotulos = ['proveedor' => 'proveedor', 'moneda' => 'moneda_texto', 'condicion de pago' => 'condicion_pago',
                'plazo de entrega' => 'plazo_entrega', 'validez de la oferta' => 'validez'];
    $es_rotulo = function (string $t) use ($rotulos): bool {
        foreach (array_keys($rotulos) as $rot) if ($t === $rot || str_starts_with($t, $rot . ' ')) return true;
        return false;
    };
    /* El valor de un rótulo: la primera celda no vacía a su derecha, pero sin
       pasar al rótulo siguiente. Si "Condición de pago" quedó vacía, a la
       derecha está "Plazo de entrega", y antes se guardaba eso como condición. */
    $valor = function (array $fila, int $col) use ($es_rotulo, $n): string {
        for ($c = $col + 1; $c < count($fila); $c++) {
            $v = trim((string)$fila[$c]);
            if ($v === '') continue;
            return $es_rotulo($n($v)) ? '' : $v;
        }
        return '';
    };

    $enc = null; $cols = [];
    foreach ($grid as $f => $fila) {
        foreach ($fila as $c => $celda) {
            $t = $n($celda);
            if ($r['codigo'] === null && preg_match('/\bPP-\d{6}\b/', (string)$celda, $m)) $r['codigo'] = $m[0];
            foreach ($rotulos as $rot => $campo) {
                if ($t === $rot || str_starts_with($t, $rot . ' ')) {
                    // "Moneda (US$ o $)" es el rótulo; el valor está a la derecha.
                    if ($r[$campo] === '') $r[$campo] = mb_substr($valor($fila, $c), 0, 150);
                }
            }
        }
        // El encabezado de la tabla: "Cód." y "Precio unitario" en la misma fila.
        if ($enc === null) {
            $mapa = [];
            foreach ($fila as $c => $celda) {
                $t = $n($celda);
                if ($t === 'cod' || $t === 'codigo') $mapa['cod'] = $c;
                elseif (str_starts_with($t, 'precio')) $mapa['precio'] = $c;
                elseif (str_starts_with($t, 'marca')) $mapa['marca'] = $c;
                elseif (str_starts_with($t, 'observ')) $mapa['obs'] = $c;
            }
            if (isset($mapa['cod'], $mapa['precio'])) { $enc = $f; $cols = $mapa; }
        }
    }

    if ($enc === null) {
        $r['error'] = 'Este archivo no tiene la tabla del pedido (las columnas "Cód." y "Precio unitario"). '
                    . 'Si el proveedor mandó su propio formato, cargá los precios a mano.';
        return $r;
    }

    for ($f = $enc + 1; $f < count($grid); $f++) {
        $fila = $grid[$f];
        $cod = trim((string)($fila[$cols['cod']] ?? ''));
        if (!preg_match('/^\d+(?:[.,]0+)?$/', $cod)) continue;   // filas de total, aclaraciones
        $orden = (int)$cod;
        $precio = imp_a_numero((string)($fila[$cols['precio']] ?? ''));
        if ($precio === null || $precio <= 0) continue;          // no lo cotizó
        $r['precios'][$orden] = [
            'precio' => round($precio, 4),
            'marca' => isset($cols['marca']) ? (mb_substr(trim((string)($fila[$cols['marca']] ?? '')), 0, 150) ?: null) : null,
            'observacion' => isset($cols['obs']) ? (mb_substr(trim((string)($fila[$cols['obs']] ?? '')), 0, 255) ?: null) : null,
        ];
    }
    if (!$r['precios']) $r['error'] = 'El archivo vino sin ningún precio cargado.';
    return $r;
}

/* ─── Con la base ─────────────────────────────────────────────────────────── */

/** El pedido, sólo si es de este usuario. */
function pp_pedido(PDO $pdo, int $usuario_id, int $id): ?array
{
    $st = $pdo->prepare("SELECT * FROM presupuesto_pedidos WHERE id = ? AND usuario_id = ?");
    $st->execute([$id, $usuario_id]);
    return $st->fetch(PDO::FETCH_ASSOC) ?: null;
}

/** "PP-000045" → el pedido 45, si es de este usuario. */
function pp_pedido_por_codigo(PDO $pdo, int $usuario_id, string $codigo): ?array
{
    return preg_match('/^PP-(\d{6})$/', $codigo, $m) ? pp_pedido($pdo, $usuario_id, (int)$m[1]) : null;
}

function pp_items(PDO $pdo, int $pedido_id): array
{
    $st = $pdo->prepare("SELECT * FROM presupuesto_items WHERE pedido_id = ? ORDER BY orden");
    $st->execute([$pedido_id]);
    return $st->fetchAll(PDO::FETCH_ASSOC);
}

function pp_ofertas(PDO $pdo, int $pedido_id): array
{
    $st = $pdo->prepare("SELECT * FROM presupuesto_ofertas WHERE pedido_id = ? ORDER BY creado_en, id");
    $st->execute([$pedido_id]);
    return $st->fetchAll(PDO::FETCH_ASSOC);
}

function pp_precios(PDO $pdo, array $ofertas): array
{
    if (!$ofertas) return [];
    $ids = array_column($ofertas, 'id');
    $st = $pdo->prepare("SELECT * FROM presupuesto_precios WHERE oferta_id IN (" . implode(',', array_fill(0, count($ids), '?')) . ")");
    $st->execute($ids);
    $p = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) $p[$r['oferta_id']][$r['item_id']] = $r;
    return $p;
}

/** Guarda una oferta con sus precios. Si ya había una del mismo proveedor en este pedido, la reemplaza. */
function pp_guardar_oferta(PDO $pdo, int $pedido_id, array $o, array $precios, ?int $editar = null): bool
{
    $reemplazo = false;
    if ($editar) {
        $pdo->prepare("UPDATE presupuesto_ofertas SET proveedor = ?, moneda = ?, condicion_pago = ?, plazo_entrega = ?, validez = ? WHERE id = ? AND pedido_id = ?")
            ->execute([$o['proveedor'], $o['moneda'], $o['condicion_pago'], $o['plazo_entrega'], $o['validez'], $editar, $pedido_id]);
        $pdo->prepare("DELETE FROM presupuesto_precios WHERE oferta_id = ?")->execute([$editar]);
        $id = $editar;
    } else {
        $st = $pdo->prepare("SELECT id FROM presupuesto_ofertas WHERE pedido_id = ? AND proveedor = ?");
        $st->execute([$pedido_id, $o['proveedor']]);
        foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $viejo) {
            $pdo->prepare("DELETE FROM presupuesto_ofertas WHERE id = ?")->execute([$viejo]);
            $reemplazo = true;
        }
        $pdo->prepare("INSERT INTO presupuesto_ofertas (pedido_id, proveedor, moneda, condicion_pago, plazo_entrega, validez, origen, archivo)
                       VALUES (?, ?, ?, ?, ?, ?, ?, ?)")
            ->execute([$pedido_id, $o['proveedor'], $o['moneda'], $o['condicion_pago'], $o['plazo_entrega'], $o['validez'], $o['origen'], $o['archivo']]);
        $id = (int)$pdo->lastInsertId();
    }
    $ins = $pdo->prepare("INSERT INTO presupuesto_precios (oferta_id, item_id, precio, marca, observacion) VALUES (?, ?, ?, ?, ?)");
    foreach ($precios as $item_id => $p) $ins->execute([$id, $item_id, $p['precio'], $p['marca'], $p['observacion']]);
    return $reemplazo;
}

/**
 * Lee el archivo que devolvió un proveedor y lo guarda como oferta. Lo usan la
 * pantalla (presupuestos.php) y el chat (api/presupuesto_chat.php), así los
 * dos leen igual.
 *
 * $pedido: el pedido al que se sube, o null para deducirlo del número PP-…
 * escrito en el archivo (es lo que pasa en el chat, que no sabe de qué pedido es).
 * Tira RuntimeException con el motivo, escrito para el productor.
 *
 * @return array{pedido: array, proveedor: string, cotizados: int, renglones: int, reemplazo: bool}
 */
function pp_cargar_respuesta(PDO $pdo, int $usuario_id, string $ruta, string $nombre, ?array $pedido): array
{
    $ext = strtolower(pathinfo($nombre, PATHINFO_EXTENSION));
    if (in_array($ext, ['xlsx', 'xlsm'], true)) {
        $grillas = array_column(imp_parse_xlsx($ruta), 'grid');
    } elseif (in_array($ext, ['csv', 'txt'], true)) {
        $grillas = [imp_parse_texto_plano(imp_leer_texto($ruta))];
    } elseif ($ext === 'xls') {
        throw new RuntimeException('es el formato viejo de Excel (.xls). Pedile al proveedor que lo guarde como .xlsx, o cargá los precios a mano.');
    } else {
        throw new RuntimeException('no es un Excel. Si el proveedor mandó un PDF o una foto, cargá los precios a mano en el pedido.');
    }

    // La primera hoja que tenga la tabla del pedido.
    $r = null;
    foreach ($grillas as $g) { $r = pp_leer_respuesta($g); if (!$r['error']) break; }
    if (!$r || $r['error']) throw new RuntimeException(lcfirst($r['error'] ?? 'no se pudo leer.'));

    if ($pedido === null) {
        if ($r['codigo'] === null) throw new RuntimeException('no tiene número de pedido: subilo desde el pedido, en Presupuestos.');
        $pedido = pp_pedido_por_codigo($pdo, $usuario_id, $r['codigo']);
        if (!$pedido) throw new RuntimeException('es del pedido ' . $r['codigo'] . ', que no está en esta cuenta.');
    } elseif ($r['codigo'] !== null && $r['codigo'] !== pp_codigo((int)$pedido['id'])) {
        throw new RuntimeException('es la respuesta de otro pedido (' . $r['codigo'] . ').');
    }

    $items = pp_items($pdo, (int)$pedido['id']);
    $por_orden = array_column($items, null, 'orden');
    $precios = [];
    foreach ($r['precios'] as $orden => $p) if (isset($por_orden[$orden])) $precios[$por_orden[$orden]['id']] = $p;
    if (!$precios) throw new RuntimeException('ningún código coincide con los renglones del pedido.');

    $texto = fn($v, $max) => mb_substr(trim((string)$v), 0, $max) ?: null;
    $proveedor = mb_substr(trim($r['proveedor']), 0, 150) ?: mb_substr(pathinfo($nombre, PATHINFO_FILENAME), 0, 150);
    $pdo->beginTransaction();
    try {
        $reemplazo = pp_guardar_oferta($pdo, (int)$pedido['id'], [
            'proveedor' => $proveedor, 'moneda' => pp_moneda_de($r['moneda_texto'], $pedido['moneda']),
            'condicion_pago' => $texto($r['condicion_pago'], 150), 'plazo_entrega' => $texto($r['plazo_entrega'], 100),
            'validez' => $texto($r['validez'], 100), 'origen' => 'excel', 'archivo' => mb_substr($nombre, 0, 255),
        ], $precios);
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
    return ['pedido' => $pedido, 'proveedor' => $proveedor, 'cotizados' => count($precios),
            'renglones' => count($items), 'reemplazo' => $reemplazo];
}

/* ─── La comparación ──────────────────────────────────────────────────────── */

/**
 * $items: [['id','orden','descripcion','cantidad','unidad']]
 * $ofertas: [['id','proveedor','moneda', ...]]
 * $precios: [oferta_id => [item_id => ['precio', 'marca', 'observacion']]]
 * $cotizacion: dólar de referencia, para comparar si hay monedas mezcladas.
 */
function pp_comparar(array $items, array $ofertas, array $precios, float $cotizacion): array
{
    $monedas = array_unique(array_column($ofertas, 'moneda'));
    $mezcla = count($monedas) > 1;
    $moneda_cmp = $mezcla ? 'USD' : ($monedas[0] ?? 'USD');
    $puede = !$mezcla || $cotizacion > 0;
    // Valor para comparar: en la moneda común, o pasado a dólares si se mezclan.
    $cmp = function (float $v, string $mon) use ($mezcla, $cotizacion): ?float {
        if (!$mezcla || $mon === 'USD') return $v;
        return $cotizacion > 0 ? $v / $cotizacion : null;
    };

    $filas = [];
    $tot = [];
    foreach ($ofertas as $o) $tot[$o['id']] = ['total' => 0.0, 'total_cmp' => 0.0, 'cotizados' => 0];

    $combinacion = 0.0;
    $combinacion_completa = true;
    foreach ($items as $it) {
        $celdas = [];
        $mejor = null;
        foreach ($ofertas as $o) {
            $p = $precios[$o['id']][$it['id']] ?? null;
            if (!$p) { $celdas[$o['id']] = null; continue; }
            $total = (float)$p['precio'] * (float)$it['cantidad'];
            $vc = $cmp($total, $o['moneda']);
            $celdas[$o['id']] = $p + ['total' => $total, 'total_cmp' => $vc, 'mejor' => false];
            $tot[$o['id']]['total'] += $total;
            $tot[$o['id']]['cotizados']++;
            if ($vc !== null) $tot[$o['id']]['total_cmp'] += $vc;
            if ($vc !== null && ($mejor === null || $vc < $celdas[$mejor]['total_cmp'] - 0.0001)) $mejor = $o['id'];
        }
        if ($mejor !== null) {
            $celdas[$mejor]['mejor'] = true;
            $combinacion += $celdas[$mejor]['total_cmp'];
        } else {
            $combinacion_completa = false;
        }
        $filas[] = ['item' => $it, 'celdas' => $celdas, 'mejor' => $mejor];
    }

    // La mejor oferta COMPLETA (que cotizó todo): contra esa se mide la combinación.
    $n = count($items);
    $mejor_completa = null;
    foreach ($ofertas as $o) {
        if ($tot[$o['id']]['cotizados'] < $n || !$puede) continue;
        if ($mejor_completa === null || $tot[$o['id']]['total_cmp'] < $tot[$mejor_completa]['total_cmp']) $mejor_completa = $o['id'];
    }
    foreach ($tot as &$t) { $t['total'] = round($t['total'], 2); $t['total_cmp'] = round($t['total_cmp'], 2); }
    unset($t);

    return [
        'filas' => $filas,
        'totales' => $tot,
        'mezcla' => $mezcla,
        'moneda_cmp' => $moneda_cmp,
        'puede_comparar' => $puede,
        'mejor_completa' => $mejor_completa,
        'combinacion' => ($combinacion_completa && $puede) ? round($combinacion, 2) : null,
        'ahorro_combinando' => ($combinacion_completa && $puede && $mejor_completa !== null)
            ? round($tot[$mejor_completa]['total_cmp'] - $combinacion, 2) : null,
    ];
}

/**
 * La comparación en palabras, para el chat. No calcula nada: todo sale de
 * pp_comparar(), lo mismo que muestra la pantalla. Si el chat y la pantalla
 * dijeran distinto, estaría mal el chat.
 *
 * @return array{respuesta: string, detalle: string}
 */
function pp_resumen_chat(array $pedido, array $items, array $ofertas, array $cmp, float $cotizacion): array
{
    $plata = fn($n, $mon) => ($mon === 'USD' ? 'US$ ' : '$ ') . number_format((float)$n, 2, ',', '.');
    $por_id = array_column($ofertas, null, 'id');
    $n_items = count($items);
    $titulo = '"' . $pedido['titulo'] . '" (' . pp_codigo((int)$pedido['id']) . ')';

    if (count($ofertas) === 1) {
        $o = $ofertas[0];
        $t = $cmp['totales'][$o['id']];
        return [
            'respuesta' => "Para $titulo tenés una sola oferta: {$o['proveedor']}, " . $plata($t['total'], $o['moneda'])
                         . " (cotizó {$t['cotizados']} de $n_items).",
            'detalle' => 'Cuando te manden las otras, mandámelas y las comparo.',
        ];
    }

    $partes = [];
    if (!$cmp['puede_comparar']) {
        return [
            'respuesta' => "Para $titulo tenés " . count($ofertas) . ' ofertas, en pesos y en dólares.',
            'detalle' => 'No hay ninguna cotización del dólar cargada, así que no las puedo comparar. Cargala y volvé a preguntarme.',
        ];
    }
    if ($cmp['mejor_completa'] !== null) {
        $o = $por_id[$cmp['mejor_completa']];
        $respuesta = "Para $titulo tenés " . count($ofertas) . ' ofertas. La completa más barata es '
                   . $o['proveedor'] . ', ' . $plata($cmp['totales'][$o['id']]['total'], $o['moneda']) . '.';
    } else {
        $respuesta = "Para $titulo tenés " . count($ofertas) . ' ofertas, pero ningún proveedor cotizó todos los renglones.';
    }
    if ($cmp['combinacion'] !== null && ($cmp['ahorro_combinando'] ?? 0) > 0.005) {
        $partes[] = 'Comprando cada insumo al más barato: ' . $plata($cmp['combinacion'], $cmp['moneda_cmp'])
                  . ', ahorrás ' . $plata($cmp['ahorro_combinando'], $cmp['moneda_cmp']) . '.';
    }
    $mejores = [];
    foreach ($cmp['filas'] as $f) {
        if ($f['mejor'] === null) continue;
        $o = $por_id[$f['mejor']];
        $mejores[] = $f['item']['descripcion'] . ', ' . $o['proveedor'] . ' ' . $plata($f['celdas'][$o['id']]['precio'], $o['moneda']);
    }
    if ($mejores) {
        $extra = count($mejores) > 6 ? ' y ' . (count($mejores) - 6) . ' más' : '';
        $partes[] = 'El más barato por insumo: ' . implode('; ', array_slice($mejores, 0, 6)) . $extra . '.';
    }
    foreach ($ofertas as $o) {
        $faltan = $n_items - $cmp['totales'][$o['id']]['cotizados'];
        if ($faltan > 0) $partes[] = $o['proveedor'] . ' no cotizó ' . $faltan . ($faltan === 1 ? ' renglón.' : ' renglones.');
    }
    if ($cmp['mezcla']) {
        $partes[] = 'Las ofertas en pesos las pasé a dólares con el dólar de referencia ('
                  . $plata($cotizacion, 'ARS') . ') sólo para compararlas.';
    }
    return ['respuesta' => $respuesta, 'detalle' => implode(' ', $partes)];
}
