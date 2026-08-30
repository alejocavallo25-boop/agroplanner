<?php
/**
 * api/reporte_excel.php
 *
 * Exportación a planilla de los mismos reportes que ofrece reporte_pdf.php.
 *
 * No genera un .xlsx de verdad: sirve una tabla HTML con el content-type de
 * Excel, que es el patrón que ya usaba la app y que Excel y LibreOffice abren
 * sin problema. La ventaja frente a armar un xlsx a mano es que no hace falta ninguna
 * dependencia; la desventaja es que no hay varias hojas ni formato de celda
 * real, y por eso los números se escriben con coma decimal y sin separador de
 * miles, que es como los interpreta Excel en configuración regional argentina.
 *
 * Cada reporte se declara como datos —título, columnas y filas— y un único
 * renderizador los pinta. Antes era un if/elseif con el HTML repetido adentro
 * de cada rama, y agregar un tipo significaba copiar cien líneas.
 */

require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/rentabilidad.php';

$usuario_id = $_SESSION['usuario_id'];

$tipo     = $_GET['tipo'] ?? 'operaciones';
$campania = !empty($_GET['campania']) ? trim($_GET['campania']) : null;
$grupo    = !empty($_GET['grupo'])    ? trim($_GET['grupo'])    : null;
$lote_id  = !empty($_GET['lote_id'])  ? (int)$_GET['lote_id']   : null;
$cultivo  = !empty($_GET['cultivo'])  ? trim($_GET['cultivo'])  : null;
$t_insumo = !empty($_GET['t_insumo']) ? trim($_GET['t_insumo']) : null;
$dep_id   = isset($_GET['dep_id']) && $_GET['dep_id'] !== '' ? (int)$_GET['dep_id'] : null;

// ─────────────────────────────────────────────────────────────────────────────
// FORMATO
// ─────────────────────────────────────────────────────────────────────────────

/** Fecha a d/m/Y, o guion si no hay. */
function xls_fecha($v): string
{
    return $v ? date('d/m/Y', strtotime((string)$v)) : '—';
}

/**
 * Número con coma decimal y sin separador de miles.
 *
 * El separador de miles se omite a propósito: al abrir el archivo, Excel lo
 * interpretaría como parte del texto y la celda dejaría de ser numérica, así que
 * no se podría sumar ni graficar. Sin él, entra como número.
 */
function xls_num($v, int $dec = 2): string
{
    return number_format((float)$v, $dec, ',', '');
}

/** Texto plano, escapado. */
function xls_txt($v): string
{
    $s = trim((string)$v);
    return $s === '' ? '—' : htmlspecialchars($s);
}

// ─────────────────────────────────────────────────────────────────────────────
// DATOS: cada tipo arma titulo, columnas y filas
// ─────────────────────────────────────────────────────────────────────────────

$titulo   = '';
$columnas = [];   // ['t' => encabezado, 'a' => alineación ('i'|'c'|'d')]
$filas    = [];   // cada fila: lista de celdas ya formateadas
$resumen  = [];   // ['etiqueta' => valor] que se imprime arriba de la tabla

if ($tipo === 'operaciones') {
    $titulo = 'Reporte de Costos y Labores' . ($campania ? " - Campaña $campania" : '');

    /* El LEFT JOIN a cultivos existe para poder filtrar por especie: el cultivo
       de una operación puede estar en la ficha del cultivo o escrito en la
       propia operación, y hay que mirar los dos. */
    $query = "SELECT o.fecha, o.grupo_gasto, o.grupo_descripcion, o.tipo_componente,
                     l.nombre AS lote, o.campania_operacion, o.cultivo_operacion,
                     i.nombre AS insumo, o.proveedor_servicio,
                     o.cantidad_ha, o.precio_unitario, o.costo_total
              FROM operaciones o
              JOIN lotes l ON o.lote_id = l.id
              LEFT JOIN insumos i ON o.insumo_id = i.id
              LEFT JOIN cultivos c ON o.cultivo_id = c.id
              WHERE o.usuario_id = ?";
    $params = [$usuario_id];
    if ($campania)                     { $query .= " AND o.campania_operacion = ?"; $params[] = $campania; }
    if ($grupo && $grupo !== 'todos')  { $query .= " AND o.grupo_gasto = ?";        $params[] = $grupo; }
    if ($lote_id)                      { $query .= " AND o.lote_id = ?";            $params[] = $lote_id; }
    /* Calcado de getGlobalStats(): si el recorte no fuera idéntico, el Excel
       traería filas que el panel no cuenta y los totales no cerrarían. */
    if ($cultivo) {
        $query .= " AND COALESCE(NULLIF(c.nombre, ''), NULLIF(o.cultivo_operacion, ''), 'Sin Especificar')"
                . " COLLATE utf8mb4_unicode_ci = ?";
        $params[] = $cultivo;
    }
    $query .= " ORDER BY o.fecha DESC";

    $stmt = $pdo->prepare($query);
    $stmt->execute($params);
    $rows = $stmt->fetchAll();

    $columnas = [
        ['t' => 'Fecha', 'a' => 'c'], ['t' => 'Grupo'], ['t' => 'Tipo'], ['t' => 'Detalle'],
        ['t' => 'Lote'], ['t' => 'Campaña'],
        ['t' => 'Cant/Ha', 'a' => 'd'], ['t' => 'Precio Unit.', 'a' => 'd'], ['t' => 'Costo Total', 'a' => 'd'],
    ];
    $total = 0;
    foreach ($rows as $r) {
        $total += (float)$r['costo_total'];
        $filas[] = [
            xls_fecha($r['fecha']),
            xls_txt($r['grupo_descripcion'] ?: $r['grupo_gasto']),
            $r['tipo_componente'] === 'labor' ? 'Labor' : 'Insumo',
            xls_txt($r['tipo_componente'] === 'labor' ? $r['proveedor_servicio'] : $r['insumo']),
            xls_txt($r['lote']),
            xls_txt($r['campania_operacion']),
            xls_num($r['cantidad_ha'], 4),
            xls_num($r['precio_unitario']),
            xls_num($r['costo_total']),
        ];
    }
    $resumen = ['Registros' => count($rows), 'Costo total' => '$' . xls_num($total)];

} elseif ($tipo === 'tambo_egresos') {
    $titulo = 'Reporte de Egresos del Tambo';

    $query  = "SELECT fecha, categoria, subcategoria, concepto, cantidad, unidad,
                      precio_unitario, monto, moneda, notas
               FROM tambo_egresos WHERE usuario_id = ?";
    $params = [$usuario_id];
    if ($grupo && $grupo !== 'todos') { $query .= " AND categoria = ?"; $params[] = $grupo; }
    $query .= " ORDER BY fecha DESC";

    $stmt = $pdo->prepare($query);
    $stmt->execute($params);
    $rows = $stmt->fetchAll();

    $columnas = [
        ['t' => 'Fecha', 'a' => 'c'], ['t' => 'Categoría'], ['t' => 'Subcategoría'], ['t' => 'Concepto'],
        ['t' => 'Cantidad', 'a' => 'd'], ['t' => 'Unidad'],
        ['t' => 'Precio Unit.', 'a' => 'd'], ['t' => 'Monto', 'a' => 'd'], ['t' => 'Moneda', 'a' => 'c'],
    ];
    $totARS = 0; $totUSD = 0;
    foreach ($rows as $r) {
        if ($r['moneda'] === 'USD') $totUSD += (float)$r['monto']; else $totARS += (float)$r['monto'];
        $filas[] = [
            xls_fecha($r['fecha']), xls_txt($r['categoria']), xls_txt($r['subcategoria']), xls_txt($r['concepto']),
            $r['cantidad'] !== null ? xls_num($r['cantidad']) : '—',
            xls_txt($r['unidad']),
            $r['precio_unitario'] !== null ? xls_num($r['precio_unitario']) : '—',
            xls_num($r['monto']),
            xls_txt($r['moneda']),
        ];
    }
    $resumen = ['Registros' => count($rows), 'Total ARS' => '$' . xls_num($totARS)];
    if ($totUSD > 0) $resumen['Total USD'] = 'US$' . xls_num($totUSD);

} elseif ($tipo === 'insumos') {
    $titulo = 'Inventario Actual de Insumos';

    $query = "SELECT i.nombre, i.tipo_insumo, i.unidad_medida, i.stock_actual,
                     i.unidad_stock, i.precio_estimado_usd, i.fecha_vencimiento,
                     i.stock_minimo, d.nombre AS deposito
              FROM insumos i
              LEFT JOIN depositos d ON i.deposito_id = d.id
              WHERE i.usuario_id = ? AND i.estado = 'activo'";
    $params = [$usuario_id];
    if ($t_insumo && $t_insumo !== 'todos') { $query .= " AND i.tipo_insumo = ?"; $params[] = $t_insumo; }
    if ($dep_id === -1)   { $query .= " AND i.deposito_id IS NULL"; }
    elseif ($dep_id)      { $query .= " AND i.deposito_id = ?"; $params[] = $dep_id; }
    $query .= " ORDER BY i.tipo_insumo, i.nombre";

    $stmt = $pdo->prepare($query);
    $stmt->execute($params);
    $rows = $stmt->fetchAll();

    $columnas = [
        ['t' => 'Producto'], ['t' => 'Tipo'], ['t' => 'Depósito'],
        ['t' => 'Stock', 'a' => 'd'], ['t' => 'Unidad'],
        ['t' => 'Precio USD', 'a' => 'd'], ['t' => 'Valor USD', 'a' => 'd'],
        ['t' => 'Stock mínimo', 'a' => 'd'], ['t' => 'Vencimiento', 'a' => 'c'], ['t' => 'Estado', 'a' => 'c'],
    ];
    $hoy = date('Y-m-d');
    $valorTotal = 0; $vencidos = 0;
    foreach ($rows as $r) {
        $valor = (float)($r['stock_actual'] ?? 0) * (float)$r['precio_estimado_usd'];
        $valorTotal += $valor;

        $estado = 'OK';
        if (!empty($r['fecha_vencimiento']) && $r['fecha_vencimiento'] < $hoy) { $estado = 'Vencido'; $vencidos++; }
        elseif ($r['stock_minimo'] !== null && (float)($r['stock_actual'] ?? 0) <= (float)$r['stock_minimo']) {
            $estado = 'Stock bajo';
        }

        $filas[] = [
            xls_txt($r['nombre']), ucfirst(xls_txt($r['tipo_insumo'])), xls_txt($r['deposito']),
            xls_num($r['stock_actual']),
            xls_txt($r['unidad_stock'] ?: $r['unidad_medida']),
            xls_num($r['precio_estimado_usd'], 4),
            xls_num($valor),
            $r['stock_minimo'] !== null ? xls_num($r['stock_minimo']) : '—',
            xls_fecha($r['fecha_vencimiento']),
            $estado,
        ];
    }
    $resumen = ['Productos' => count($rows), 'Valor total' => 'US$' . xls_num($valorTotal)];
    if ($vencidos > 0) $resumen['Vencidos'] = $vencidos;

} elseif ($tipo === 'alquileres') {
    $titulo = 'Reporte de Alquileres Pagados' . ($campania ? " - Campaña $campania" : '');

    $query = "SELECT a.fecha_pago, a.nivel_imputacion, a.campania,
                     l.nombre AS lote_nombre, c.nombre AS cultivo_nombre,
                     a.monto_pagado, a.moneda, a.notas
              FROM alquileres a
              LEFT JOIN lotes l    ON a.lote_id    = l.id
              LEFT JOIN cultivos c ON a.cultivo_id = c.id
              WHERE a.usuario_id = ?";
    $params = [$usuario_id];
    if ($campania) { $query .= " AND a.campania = ?"; $params[] = $campania; }
    $query .= " ORDER BY a.fecha_pago DESC";

    $stmt = $pdo->prepare($query);
    $stmt->execute($params);
    $rows = $stmt->fetchAll();

    $columnas = [
        ['t' => 'Fecha de pago', 'a' => 'c'], ['t' => 'Nivel'], ['t' => 'Campaña'],
        ['t' => 'Lote'], ['t' => 'Cultivo'],
        ['t' => 'Monto', 'a' => 'd'], ['t' => 'Moneda', 'a' => 'c'], ['t' => 'Notas'],
    ];
    $totARS = 0; $totUSD = 0;
    foreach ($rows as $r) {
        if ($r['moneda'] === 'USD') $totUSD += (float)$r['monto_pagado']; else $totARS += (float)$r['monto_pagado'];
        $filas[] = [
            xls_fecha($r['fecha_pago']), xls_txt($r['nivel_imputacion']), xls_txt($r['campania']),
            xls_txt($r['lote_nombre']), xls_txt($r['cultivo_nombre']),
            xls_num($r['monto_pagado']), xls_txt($r['moneda']), xls_txt($r['notas']),
        ];
    }
    $resumen = ['Pagos' => count($rows)];
    if ($totUSD > 0) $resumen['Total USD'] = 'US$' . xls_num($totUSD);
    if ($totARS > 0) $resumen['Total ARS'] = '$'   . xls_num($totARS);

} elseif ($tipo === 'produccion') {
    $titulo = 'Producción y Ventas' . ($campania ? " - Campaña $campania" : '');

    $query = "SELECT pv.fecha_venta, pv.campania_vendida,
                     l.nombre AS lote_nombre,
                     COALESCE(NULLIF(c.nombre, ''), NULLIF(pv.cultivo_vendido, ''), 'Sin especificar') AS cultivo,
                     pv.kg_cosechados, pv.precio_kg, pv.ingreso_total, pv.notas
              FROM produccion_ventas pv
              LEFT JOIN lotes l    ON pv.lote_id    = l.id
              LEFT JOIN cultivos c ON pv.cultivo_id = c.id
              WHERE pv.usuario_id = ?";
    $params = [$usuario_id];
    if ($campania) { $query .= " AND pv.campania_vendida = ?"; $params[] = $campania; }
    if ($lote_id)  { $query .= " AND pv.lote_id = ?";          $params[] = $lote_id; }
    $query .= " ORDER BY pv.fecha_venta DESC";

    $stmt = $pdo->prepare($query);
    $stmt->execute($params);
    $rows = $stmt->fetchAll();

    $columnas = [
        ['t' => 'Fecha de venta', 'a' => 'c'], ['t' => 'Campaña'], ['t' => 'Lote'], ['t' => 'Cultivo'],
        ['t' => 'Kg cosechados', 'a' => 'd'], ['t' => 'Precio por kg', 'a' => 'd'],
        ['t' => 'Ingreso total', 'a' => 'd'], ['t' => 'Notas'],
    ];
    $totKg = 0; $totIngreso = 0;
    foreach ($rows as $r) {
        $totKg      += (float)$r['kg_cosechados'];
        $totIngreso += (float)$r['ingreso_total'];
        $filas[] = [
            xls_fecha($r['fecha_venta']), xls_txt($r['campania_vendida']),
            xls_txt($r['lote_nombre']), xls_txt($r['cultivo']),
            xls_num($r['kg_cosechados']), xls_num($r['precio_kg'], 4), xls_num($r['ingreso_total']),
            xls_txt($r['notas']),
        ];
    }
    $resumen = [
        'Ventas'        => count($rows),
        'Kg totales'    => xls_num($totKg),
        'Ingreso total' => '$' . xls_num($totIngreso),
    ];
    // Precio promedio real obtenido, que casi nunca coincide con el de pizarra.
    if ($totKg > 0) $resumen['Precio promedio por kg'] = '$' . xls_num($totIngreso / $totKg, 4);

} elseif ($tipo === 'dashboard') {
    require_agricultura();
    require_once __DIR__ . '/../controllers/DashboardController.php';

    $ciclo_sel   = !empty($_GET['ciclo'])   ? trim($_GET['ciclo'])   : null;
    $lote_sel    = !empty($_GET['lote'])    ? (int)$_GET['lote']     : null;
    $cultivo_sel = !empty($_GET['cultivo']) ? trim($_GET['cultivo']) : null;

    $controller = new DashboardController($pdo, $usuario_id);
    if (!$ciclo_sel) {
        $ciclos = $controller->getCiclos();
        $ciclo_sel = $ciclos[0] ?? null;
    }

    $titulo = 'Panel General Agrícola' . ($ciclo_sel ? " - Campaña $ciclo_sel" : '');
    $stats    = $controller->getGlobalStats($ciclo_sel, $lote_sel, $cultivo_sel);
    $cultivos = $controller->getCultivosData($ciclo_sel, $lote_sel, $cultivo_sel);

    $columnas = [
        ['t' => 'Cultivo'], ['t' => 'Lote'], ['t' => 'Sup. (ha)', 'a' => 'd'],
        ['t' => 'Ingreso', 'a' => 'd'], ['t' => 'Costo directo', 'a' => 'd'], ['t' => 'Alquiler', 'a' => 'd'],
        ['t' => 'Margen', 'a' => 'd'],
        ['t' => 'Ingreso/ha', 'a' => 'd'], ['t' => 'Costo/ha', 'a' => 'd'], ['t' => 'Margen/ha', 'a' => 'd'],
        ['t' => 'Rinde indif. (kg/ha)', 'a' => 'd'],
    ];
    foreach ($cultivos as $especie => $d) {
        foreach ($d['lotes'] as $l) {
            // Misma función que usa el PDF: una sola cuenta, un solo resultado.
            $c = rentabilidad_lote($l);
            $filas[] = [
                xls_txt($especie), xls_txt($l['nombre']), xls_num($c['sup'], 1),
                xls_num($c['ingreso']), xls_num($c['costo_directo']), xls_num($c['alquiler']),
                xls_num($c['margen_total']),
                xls_num($c['ingreso_ha']), xls_num($c['costo_ha']), xls_num($c['margen_ha']),
                xls_num($c['rinde_indiferencia_ha'], 0),
            ];
        }
    }
    $resumen = [
        'Ingresos'        => '$' . xls_num($stats['ingresos'] ?? 0),
        'Costos directos' => '$' . xls_num($stats['costos_directos'] ?? 0),
        'Alquileres'      => '$' . xls_num($stats['costos_alquiler'] ?? 0),
        'Margen neto'     => '$' . xls_num($stats['margen_neto'] ?? 0),
        'Hectáreas'       => xls_num($stats['hectareas'] ?? 0, 1),
        'Lotes'           => count($filas),
    ];

} else {
    header('Content-Type: text/plain; charset=utf-8');
    http_response_code(400);
    echo 'Tipo de reporte no reconocido: ' . htmlspecialchars($tipo);
    exit;
}

// ─────────────────────────────────────────────────────────────────────────────
// SALIDA
// ─────────────────────────────────────────────────────────────────────────────

$nombreArchivo = 'AgroPlanner_' . $tipo . '_' . date('Ymd_His') . '.xls';
header('Content-Type: application/vnd.ms-excel; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $nombreArchivo . '"');
header('Pragma: no-cache');
header('Expires: 0');

$alineacion = ['i' => 'left', 'c' => 'center', 'd' => 'right'];
$anchoTabla = max(1, count($columnas));
?>
<html xmlns:o="urn:schemas-microsoft-com:office:office" xmlns:x="urn:schemas-microsoft-com:office:excel" xmlns="http://www.w3.org/TR/REC-html40">
<head>
<meta charset="utf-8">
<style>
    table, th, td { border: 1px solid #cbd5e1; border-collapse: collapse; padding: 5px;
                    font-family: Arial, sans-serif; font-size: 12px; }
    .title-main { font-family: Arial; font-size: 22px; font-weight: bold; color: #10b981; border: none; }
    .title-sub  { font-family: Arial; font-size: 13px; font-style: italic; color: #64748b; border: none; }
    .head       { background-color: #0f172a; color: #ffffff; font-weight: bold; text-align: center; }
    .res-lbl    { background-color: #f1f5f9; font-weight: bold; }
</style>
</head>
<body>
    <table>
        <tr><td colspan="<?= $anchoTabla ?>" class="title-main">AGROPLANNER</td></tr>
        <tr><td colspan="<?= $anchoTabla ?>" class="title-sub"><?= htmlspecialchars($titulo) ?></td></tr>
        <tr><td colspan="<?= $anchoTabla ?>" class="title-sub">Generado el <?= date('d/m/Y H:i') ?></td></tr>
        <tr><td colspan="<?= $anchoTabla ?>" style="border:none;"></td></tr>

        <?php foreach ($resumen as $etiqueta => $valor): ?>
        <tr>
            <td class="res-lbl"><?= htmlspecialchars($etiqueta) ?></td>
            <td colspan="<?= max(1, $anchoTabla - 1) ?>"><?= htmlspecialchars((string)$valor) ?></td>
        </tr>
        <?php endforeach; ?>
        <tr><td colspan="<?= $anchoTabla ?>" style="border:none;"></td></tr>

        <tr>
            <?php foreach ($columnas as $col): ?>
            <th class="head"><?= htmlspecialchars($col['t']) ?></th>
            <?php endforeach; ?>
        </tr>

        <?php if (!$filas): ?>
        <tr><td colspan="<?= $anchoTabla ?>" style="text-align:center; color:#64748b;">
            No hay registros para los filtros elegidos.
        </td></tr>
        <?php endif; ?>

        <?php foreach ($filas as $fila): ?>
        <tr>
            <?php foreach ($fila as $i => $celda): ?>
            <td style="text-align: <?= $alineacion[$columnas[$i]['a'] ?? 'i'] ?>;"><?= $celda ?></td>
            <?php endforeach; ?>
        </tr>
        <?php endforeach; ?>
    </table>
</body>
</html>
