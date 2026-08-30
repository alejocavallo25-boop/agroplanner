<?php
/**
 * MOTOR DE CONSULTAS
 * ==================
 *
 * Responde preguntas escritas en castellano sobre los números de la campaña:
 * "¿cuánto fue el costo por hectárea en la 26/27 en el lote 1?"
 *
 * Es determinista a propósito. Se siente como una IA —tolera errores de tipeo,
 * entiende sinónimos, contesta con una frase armada y propone repreguntas— pero
 * NO adivina: mapea la pregunta a una métrica de un catálogo cerrado y el número
 * lo calcula DashboardController contra la base, igual que el panel.
 *
 * Esa separación es la que importa. Un modelo de lenguaje que devuelve un margen
 * inventado rompe la única promesa del producto ("datos en los que confía") de
 * una forma que no se recupera. Acá el peor caso posible es interpretar mal la
 * pregunta: se ve, se corrige, y nunca inventa plata.
 *
 * Si más adelante se le pone un LLM adelante, que sólo elija métrica y filtros;
 * el cálculo se queda de este lado.
 */

/** Minúsculas, sin acentos y sin puntuación: todo se compara en este plano. */
function motor_normalizar(string $s): string {
    $s = mb_strtolower(trim($s), 'UTF-8');
    $s = strtr($s, [
        'á'=>'a','é'=>'e','í'=>'i','ó'=>'o','ú'=>'u','ü'=>'u','ñ'=>'n',
        'à'=>'a','è'=>'e','ì'=>'i','ò'=>'o','ù'=>'u',
    ]);
    $s = preg_replace('/[¿?¡!.,;:()"\']+/u', ' ', $s);
    $s = preg_replace('/\s+/u', ' ', $s);
    return trim($s);
}

/**
 * Catálogo de métricas. Es el vocabulario completo que el motor sabe contestar:
 * si algo no está acá, el motor lo dice en vez de improvisar.
 *
 * Cada clave existe tal cual en lo que devuelve DashboardController::getGlobalStats().
 */
function motor_metricas(): array {
    return [
        'margen_neto' => [
            'etiqueta' => 'margen neto',
            'formato'  => 'dinero',
            'sinonimos' => ['margen neto','margen','cuanto gano','cuanto gane','cuanto gana',
                            'ganancia','ganancias','rentabilidad','resultado','utilidad',
                            'cuanto me queda','cuanto pierdo'],
            'explicacion' => 'Lo que queda después de restarle a los ingresos las labores, los insumos y los alquileres.',
        ],
        'costo_por_ha' => [
            'etiqueta' => 'costo por hectárea',
            'formato'  => 'dinero',
            'sinonimos' => ['costo por hectarea','costo por ha','costo x ha','costo/ha','costo ha',
                            'costos por hectarea','costos por ha','cuanto cuesta la hectarea',
                            'gasto por hectarea','costo unitario por hectarea'],
            'explicacion' => 'Todos los costos de la campaña (labores, insumos y alquiler) divididos por la superficie trabajada.',
        ],
        'costo_por_kg' => [
            'etiqueta' => 'costo por kilo',
            'formato'  => 'dinero_fino',
            'sinonimos' => ['costo por kilo','costo por kg','costo x kg','costo/kg',
                            'cuanto cuesta el kilo','costo unitario por kilo'],
            'explicacion' => 'Cuánto cuesta producir un kilo: los costos totales divididos por los kilos producidos.',
        ],
        'costos_directos' => [
            'etiqueta' => 'costos de laboreo',
            'formato'  => 'dinero',
            'sinonimos' => ['costos de laboreo','costo de laboreo','costos directos','costos',
                            'costo','gastos','gasto','cuanto gaste','cuanto gasto','labores e insumos'],
            'explicacion' => 'Insumos más labores directas. No incluye los alquileres.',
        ],
        'costos_alquiler' => [
            'etiqueta' => 'alquileres pagados',
            'formato'  => 'dinero',
            'sinonimos' => ['alquiler','alquileres','arrendamiento','arriendo',
                            'cuanto pague de alquiler','pagos de alquiler'],
            'explicacion' => 'Los pagos de alquiler efectivamente registrados para esa campaña.',
        ],
        'ingresos' => [
            'etiqueta' => 'ingresos',
            'formato'  => 'dinero',
            'sinonimos' => ['ingresos','ingreso','ventas','venta','facturacion','facture',
                            'cuanto vendi','cuanto factura','cuanto entro'],
            'explicacion' => 'El total de las ventas registradas de esa campaña.',
        ],
        'rendimiento_ha' => [
            'etiqueta' => 'rinde promedio',
            'formato'  => 'kg_ha',
            'sinonimos' => ['rinde promedio','rinde','rendimiento','kilos por hectarea','kg por hectarea',
                            'kg/ha','cuanto rindio','cuanto rinde','productividad'],
            'explicacion' => 'Los kilos producidos divididos por la superficie trabajada.',
        ],
        'punto_equilibrio_kg_ha' => [
            'etiqueta' => 'rinde de indiferencia',
            'formato'  => 'kg_ha',
            'sinonimos' => ['rinde de indiferencia','indiferencia','punto de equilibrio','equilibrio',
                            'breakeven','break even','cuanto necesito para no perder',
                            'cuanto tengo que rendir'],
            'explicacion' => 'Los kilos por hectárea que hacen falta para cubrir todos los gastos. Por debajo de ese número la campaña da pérdida.',
        ],
        'hectareas' => [
            'etiqueta' => 'superficie trabajada',
            'formato'  => 'ha',
            'sinonimos' => ['hectareas','superficie','cuantas hectareas','ha trabajadas','area'],
            'explicacion' => 'Las hectáreas con actividad registrada en esa campaña.',
        ],
        'kg' => [
            'etiqueta' => 'producción total',
            'formato'  => 'kg',
            'sinonimos' => ['produccion','produccion total','kilos','kilos totales','cuanto produje',
                            'cuantos kilos','volumen'],
            'explicacion' => 'El total de kilos producidos y registrados en la campaña.',
        ],
    ];
}

/**
 * Dimensiones del gasto. Salen tal cual de los enum de `operaciones`, así que el
 * vocabulario del motor y el de la base no pueden desincronizarse.
 */
function motor_grupos(): array {
    return [
        'siembra'       => ['etiqueta' => 'siembra',       'sinonimos' => ['siembra','sembrar','sembre','sembrado']],
        'cosecha'       => ['etiqueta' => 'cosecha',       'sinonimos' => ['cosecha','cosechar','coseche','trilla']],
        'pulverizacion' => ['etiqueta' => 'pulverización', 'sinonimos' => ['pulverizacion','pulverizar','pulverizado','fumigacion','fumigar','aplicacion']],
        'fertilizacion' => ['etiqueta' => 'fertilización', 'sinonimos' => ['fertilizacion','fertilizar','fertilizado']],
        'otros'         => ['etiqueta' => 'otros gastos',  'sinonimos' => ['otros gastos','otros']],
    ];
}

/**
 * Tipo de componente: las MISMAS tres opciones que ofrece el formulario de Costos
 * y Labores, con las mismas etiquetas.
 *
 * El enum de la base tiene ocho valores, pero cuatro (semilla, fertilizante,
 * agroquimico, maquinaria) quedaron de una versión vieja y ningún formulario los
 * escribe. Cuando el motor los ofrecía como categorías, preguntar "cuánto gasté
 * en maquinaria" devolvía cero para siempre: la categoría existía en el enum pero
 * no en el producto. Ahora el catálogo es el del formulario.
 *
 * 'db' es una lista porque una opción del formulario puede guardarse con más de
 * un valor: elegir "Insumo" guarda multi_insumo (operaciones.php lo traduce antes
 * del INSERT), y las filas viejas quedaron como insumo a secas.
 */
function motor_componentes(): array {
    return [
        'labor' => [
            'etiqueta'  => 'mano de obra',
            'db'        => ['labor'],
            'sinonimos' => ['labor','labores','mano de obra','jornal','jornales','contratista','trabajo'],
        ],
        'insumo' => [
            'etiqueta'  => 'insumos',
            'db'        => ['insumo','multi_insumo'],
            'sinonimos' => ['insumo','insumos'],
        ],
        'receta_labor' => [
            'etiqueta'  => 'aplicaciones (labor + insumos)',
            'db'        => ['receta_labor'],
            'sinonimos' => ['receta','recetas','aplicacion con insumos','labor con insumos'],
        ],
    ];
}

/**
 * Rubro del insumo, tal como lo clasifica el catálogo de Insumos.
 *
 * Semillas, fertilizantes y agroquímicos SÍ existen como categoría —lo que no
 * existe es que sean un tipo de componente de la operación—: viven en
 * insumos.tipo_insumo, y se llega a ellos desde la operación por sus renglones.
 * Por eso el gasto de este catálogo se calcula distinto: no es el costo_total de
 * la operación sino el de cada renglón. Ver motor_gasto_por_insumo().
 */
function motor_tipos_insumo(): array {
    /* Dos etiquetas por rubro, y no es redundancia. 'etiqueta' es para hablar del
       gasto —"gastaste $X en fertilizantes"— y va en plural. 'singular' es para la
       ficha de UN insumo, y es la palabra exacta del desplegable del formulario:
       "Tipo: fertilizantes" para una bolsa de urea se lee como si fueran varias. */
    return [
        'semilla'      => ['etiqueta' => 'semillas',      'singular' => 'Semilla',      'sinonimos' => ['semilla','semillas']],
        'fertilizante' => ['etiqueta' => 'fertilizantes', 'singular' => 'Fertilizante', 'sinonimos' => ['fertilizante','fertilizantes','urea','fosfato']],
        'agroquimico'  => ['etiqueta' => 'agroquímicos',  'singular' => 'Agroquímico',  'sinonimos' => ['agroquimico','agroquimicos','herbicida','herbicidas','glifosato','curasemilla','insecticida','fungicida']],
        'inoculante'   => ['etiqueta' => 'inoculantes',   'singular' => 'Inoculante',   'sinonimos' => ['inoculante','inoculantes']],
        'otro'         => ['etiqueta' => 'otros insumos', 'singular' => 'Otro',         'sinonimos' => ['otros insumos']],
    ];
}

/** Devuelve [clave, etiqueta] de la dimensión nombrada, o null. */
function motor_detectar_dimension(string $texto, array $catalogo): ?array {
    $mejor = null; $largo = 0;
    foreach ($catalogo as $clave => $d) {
        foreach ($d['sinonimos'] as $syn) {
            $s = motor_normalizar($syn);
            if (motor_coincide($texto, $s) && mb_strlen($s) > $largo) {
                $mejor = ['clave' => $clave, 'etiqueta' => $d['etiqueta']];
                $largo = mb_strlen($s);
            }
        }
    }
    return $mejor;
}

/** ¿Está pidiendo un ranking? "¿en qué gasté más?" */
function motor_pide_ranking(string $texto): bool {
    foreach (['mayor gasto','gasto mas grande','mas caro','en que se me va',
              'ranking de costos','mayores costos'] as $p) {
        if (strpos($texto, $p) !== false) return true;
    }
    /* "en qué gasté más", "en qué LOTE gasté más", "dónde pagué más": es la misma
       pregunta con cualquier cosa en el medio, así que se acepta el hueco en vez
       de escribir una lista de frases que nunca termina. */
    return (bool)preg_match(
        '/(^|\s)(en que|en cual|donde|cual|que)\s.{0,22}?(gaste|gasto|pague|invert\w*)\s.{0,14}?mas(\s|$)/u',
        $texto
    );
}

/**
 * Un ranking de gasto se puede abrir por etapa o por lote, y la diferencia está
 * en si la pregunta nombra el lote. "En qué gasté más" es por etapa; "en qué
 * LOTE gasté más" es por lote, y contestar el total —como pasaba antes— es
 * peor que no contestar: es un número correcto respondiendo otra pregunta.
 *
 * Si ya se está mirando un lote solo, abrir por lote no dice nada: se vuelve a
 * la etapa.
 */
function motor_ranking_por_lote(string $texto, ?int $loteFiltrado): bool {
    if ($loteFiltrado !== null) return false;
    return (bool)preg_match('/(^|\s)(lote|lotes|campo|campos|potrero|potreros)(\s|$)/u', $texto);
}

/**
 * Gasto filtrado por dimensión.
 *
 * El WHERE de campaña, lote y cultivo es CALCADO del de getGlobalStats(): si acá
 * se filtrara distinto, el chat contestaría un número que no cierra con el del
 * panel, y un número que no cierra con otro del mismo producto destruye la
 * confianza más rápido que no tener la función.
 *
 * @param array $extra  ['col' => 'grupo_gasto'|'tipo_componente'|'proveedor_servicio',
 *                       'val' => string, o 'vals' => string[] cuando una misma
 *                       opción del formulario se guarda con más de un valor]
 */
function motor_gasto(PDO $pdo, int $uid, string $ciclo, ?int $lote, ?string $cultivo, ?array $extra): float {
    $f = motor_factor_moneda($pdo, $uid, 'o');
    $sql = "SELECT SUM(o.costo_total * $f) AS total
            FROM operaciones o
            LEFT JOIN cultivos c ON o.cultivo_id = c.id "
         . dolar_sql_join('o', 'fecha', 'dmo')
         . " WHERE (o.campania_operacion = ? OR c.ciclo = ?) AND o.usuario_id = ?";
    $params = [$ciclo, $ciclo, $uid];

    if ($lote !== null) { $sql .= " AND o.lote_id = ?"; $params[] = $lote; }
    if ($cultivo !== null) {
        $sql .= " AND COALESCE(NULLIF(c.nombre, ''), NULLIF(o.cultivo_operacion, ''), 'Sin Especificar') COLLATE utf8mb4_unicode_ci = ?";
        $params[] = $cultivo;
    }
    if ($extra !== null) {
        // La columna nunca viene del usuario: sale de un catálogo fijo de acá abajo.
        $cols = ['grupo_gasto', 'tipo_componente', 'proveedor_servicio'];
        if (!in_array($extra['col'], $cols, true)) return 0.0;
        /* Los valores tampoco vienen del usuario, pero igual van por parámetro:
           los placeholders se arman contando, no concatenando lo que llegó. */
        $vals = $extra['vals'] ?? [$extra['val']];
        $sql .= " AND o." . $extra['col'] . " IN (" . implode(',', array_fill(0, count($vals), '?')) . ")";
        foreach ($vals as $v) $params[] = $v;
    }

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return (float)($stmt->fetch()['total'] ?? 0);
}

/**
 * La moneda en que el motor contesta. Es la del panel, no la de los datos.
 *
 * Vive en un estático de request y no viaja como parámetro por la misma razón que
 * en el controlador: es una sola decisión que toman todas las respuestas, y
 * enhebrarla por cada función de consulta era cambiar veinte firmas para configurar
 * una cosa. Si el chat contestara en pesos mientras el panel está en dólares,
 * volveríamos a tener dos números para lo mismo — que es todo lo que se viene
 * evitando.
 */
function motor_moneda(?string $set = null): string {
    static $m = 'ARS';
    if ($set !== null) $m = ($set === 'USD') ? 'USD' : 'ARS';
    return $m;
}

/** El factor de conversión para las consultas del motor, ya con la cotización. */
function motor_factor_moneda(PDO $pdo, int $uid, string $tabla): string {
    static $ref = null;
    if ($ref === null) {
        dolar_asegurar_tabla($pdo);
        $ref = dolar_referencia($pdo, $uid)['valor'];
    }
    $alias = 'dm' . substr($tabla, 0, 1);
    return dolar_sql_factor($tabla . '.moneda', motor_moneda(), $ref, $alias);
}

/**
 * Gasto por rubro del catálogo de insumos: semillas, fertilizantes, agroquímicos.
 *
 * No se puede sumar costo_total como en motor_gasto(): una operación de insumos
 * agrupa varios renglones que pueden ser de rubros distintos —una siembra lleva
 * semilla Y curasemilla en la misma carga—, así que sumar la operación entera
 * cargaría todo al rubro del primero.
 *
 * Pero tampoco se suman los renglones en crudo. El renglón guarda un precio con
 * dos decimales, y al reconstruir el importe desde ahí se pierden centavos contra
 * el costo_total de la operación, que es el número que leen el panel, los reportes
 * y todo lo demás. Ese hueco haría que "cuánto gasté en fertilizantes" y "cuánto
 * gasté en insumos" no cierren entre sí por unos pesos, que es peor que no tener
 * el desglose.
 *
 * Así que el renglón se usa para el PESO RELATIVO y el importe sale del
 * costo_total: la parte de cada rubro es su proporción dentro de la operación. Los
 * rubros de una operación suman exactamente su costo_total, siempre.
 *
 * En las recetas el costo_total incluye además la mano de obra, así que se le
 * descuenta igual que en getGlobalStats(): sólo se reparte la parte de insumos.
 */
function motor_gasto_por_insumo(PDO $pdo, int $uid, string $ciclo, ?int $lote, ?string $cultivo, string $tipoInsumo): float {
    $f = motor_factor_moneda($pdo, $uid, 'o');
    $sql = "SELECT SUM(
                (CASE WHEN o.tipo_componente = 'receta_labor'
                      THEN o.costo_total - (o.precio_unitario * o.cantidad_ha)
                      ELSE o.costo_total END)
                * ((oi.cantidad_ha * oi.precio_unitario) / tot.suma)
                * $f
            ) AS total
            FROM operaciones o
            INNER JOIN operacion_insumos oi ON oi.operacion_id = o.id
            INNER JOIN insumos i ON oi.insumo_id = i.id
            INNER JOIN (
                SELECT operacion_id, SUM(cantidad_ha * precio_unitario) AS suma
                  FROM operacion_insumos
                 GROUP BY operacion_id
            ) tot ON tot.operacion_id = o.id
            LEFT JOIN cultivos c ON o.cultivo_id = c.id "
            . dolar_sql_join('o', 'fecha', 'dmo')
            . " WHERE (o.campania_operacion = ? OR c.ciclo = ?) AND o.usuario_id = ?
              AND i.tipo_insumo = ? AND tot.suma > 0";
    $params = [$ciclo, $ciclo, $uid, $tipoInsumo];

    if ($lote !== null) { $sql .= " AND o.lote_id = ?"; $params[] = $lote; }
    if ($cultivo !== null) {
        $sql .= " AND COALESCE(NULLIF(c.nombre, ''), NULLIF(o.cultivo_operacion, ''), 'Sin Especificar') COLLATE utf8mb4_unicode_ci = ?";
        $params[] = $cultivo;
    }

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return (float)($stmt->fetch()['total'] ?? 0);
}

/** El gasto de cada grupo, de mayor a menor. */
function motor_ranking_grupos(PDO $pdo, int $uid, string $ciclo, ?int $lote, ?string $cultivo): array {
    $f = motor_factor_moneda($pdo, $uid, 'o');
    $sql = "SELECT o.grupo_gasto AS g, SUM(o.costo_total * $f) AS total
            FROM operaciones o
            LEFT JOIN cultivos c ON o.cultivo_id = c.id "
         . dolar_sql_join('o', 'fecha', 'dmo')
         . " WHERE (o.campania_operacion = ? OR c.ciclo = ?) AND o.usuario_id = ?";
    $params = [$ciclo, $ciclo, $uid];
    if ($lote !== null) { $sql .= " AND o.lote_id = ?"; $params[] = $lote; }
    if ($cultivo !== null) {
        $sql .= " AND COALESCE(NULLIF(c.nombre, ''), NULLIF(o.cultivo_operacion, ''), 'Sin Especificar') COLLATE utf8mb4_unicode_ci = ?";
        $params[] = $cultivo;
    }
    $sql .= " GROUP BY o.grupo_gasto HAVING total > 0 ORDER BY total DESC";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll();
}

/**
 * Lo mismo pero abierto por lote: "¿en qué lote se me está yendo la plata?".
 *
 * No lleva filtro de lote, obviamente: el lote es acá la dimensión que se abre.
 * El nombre sale del JOIN y no de la sesión, pero el WHERE sigue atado al
 * usuario, así que nunca puede aparecer el lote de otro.
 */
function motor_ranking_lotes_gasto(PDO $pdo, int $uid, string $ciclo, ?string $cultivo): array {
    $f = motor_factor_moneda($pdo, $uid, 'o');
    $sql = "SELECT l.nombre AS n, SUM(o.costo_total * $f) AS total
            FROM operaciones o
            LEFT JOIN cultivos c ON o.cultivo_id = c.id
            INNER JOIN lotes l ON o.lote_id = l.id "
         . dolar_sql_join('o', 'fecha', 'dmo')
         . " WHERE (o.campania_operacion = ? OR c.ciclo = ?) AND o.usuario_id = ?";
    $params = [$ciclo, $ciclo, $uid];
    if ($cultivo !== null) {
        $sql .= " AND COALESCE(NULLIF(c.nombre, ''), NULLIF(o.cultivo_operacion, ''), 'Sin Especificar') COLLATE utf8mb4_unicode_ci = ?";
        $params[] = $cultivo;
    }
    $sql .= " GROUP BY l.id, l.nombre HAVING total > 0 ORDER BY total DESC";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll();
}

/** Proveedores con gasto en la campaña, para poder reconocerlos por nombre. */
function motor_proveedores(PDO $pdo, int $uid, string $ciclo): array {
    $stmt = $pdo->prepare(
        "SELECT DISTINCT o.proveedor_servicio AS p
         FROM operaciones o
         LEFT JOIN cultivos c ON o.cultivo_id = c.id
         WHERE (o.campania_operacion = ? OR c.ciclo = ?) AND o.usuario_id = ?
           AND o.proveedor_servicio IS NOT NULL AND o.proveedor_servicio <> ''"
    );
    $stmt->execute([$ciclo, $ciclo, $uid]);
    return array_column($stmt->fetchAll(), 'p');
}

/** Meses en castellano, para las preguntas temporales. */
function motor_meses(): array {
    return ['enero'=>1,'febrero'=>2,'marzo'=>3,'abril'=>4,'mayo'=>5,'junio'=>6,
            'julio'=>7,'agosto'=>8,'septiembre'=>9,'setiembre'=>9,'octubre'=>10,
            'noviembre'=>11,'diciembre'=>12];
}

/**
 * Reconoce un mes en la pregunta y le resuelve el año.
 *
 * Si el productor dice "agosto" a secas, el año no está escrito en ningún lado.
 * En vez de asumir el actual —que puede no tener nada cargado y devolver un cero
 * engañoso— se busca el agosto MÁS RECIENTE con gastos suyos. Es lo que quiso
 * decir el 99% de las veces, y si no hay ninguno recién ahí cae al año corriente.
 */
function motor_detectar_mes(PDO $pdo, int $uid, string $texto): ?array {
    // Un solo camino de detección para no tener dos criterios distintos que se
    // puedan desincronizar: se reusa el plural y se toma el primero.
    $todos = motor_detectar_meses($pdo, $uid, $texto);
    if ($todos) return $todos[0];

    // "este mes" / "el mes pasado" no nombran el mes pero lo determinan igual.
    if (strpos($texto, 'este mes') !== false) {
        return motor_rango_mes((int)date('n'), (int)date('Y'));
    }
    if (strpos($texto, 'mes pasado') !== false) {
        $t = strtotime('-1 month');
        return motor_rango_mes((int)date('n', $t), (int)date('Y', $t));
    }
    return null;
}

function motor_rango_mes(int $mes, int $anio): array {
    $desde = sprintf('%04d-%02d-01', $anio, $mes);
    $hasta = date('Y-m-t', strtotime($desde));
    $nombres = array_flip(motor_meses());
    return ['desde' => $desde, 'hasta' => $hasta,
            'etiqueta' => ($nombres[$mes] ?? $mes) . ' de ' . $anio];
}

/**
 * Gasto de un período. Mismo criterio de alcance que el resto: se acota por
 * usuario y, si vienen, por lote y cultivo. Acá NO se filtra por campaña: la
 * pregunta es por fecha, y una campaña se cruza con dos años calendario.
 */
function motor_gasto_periodo(PDO $pdo, int $uid, array $rango, ?int $lote, ?string $cultivo): float {
    $f = motor_factor_moneda($pdo, $uid, 'o');
    $sql = "SELECT SUM(o.costo_total * $f) AS total
            FROM operaciones o
            LEFT JOIN cultivos c ON o.cultivo_id = c.id "
         . dolar_sql_join('o', 'fecha', 'dmo')
         . " WHERE o.usuario_id = ? AND o.fecha BETWEEN ? AND ?";
    $params = [$uid, $rango['desde'], $rango['hasta']];
    if ($lote !== null) { $sql .= " AND o.lote_id = ?"; $params[] = $lote; }
    if ($cultivo !== null) {
        $sql .= " AND COALESCE(NULLIF(c.nombre, ''), NULLIF(o.cultivo_operacion, ''), 'Sin Especificar') COLLATE utf8mb4_unicode_ci = ?";
        $params[] = $cultivo;
    }
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return (float)($stmt->fetch()['total'] ?? 0);
}

/**
 * Todos los meses nombrados, no sólo el primero. Es lo que permite entender
 * "¿gasté más en agosto que en julio?" como una comparación y no como una
 * consulta de agosto con ruido al final.
 */
function motor_detectar_meses(PDO $pdo, int $uid, string $texto): array {
    $out = [];
    $pos = [];

    foreach (motor_meses() as $nombre => $num) {
        /* Los meses se buscan LITERALES, sin tolerancia a tipeo. "junio" y "julio"
           están a distancia 1 de edición, así que con la comparación difusa
           "gasté más en agosto que en julio" detectaba junio y comparaba el mes
           equivocado. En nombres de mes el error de tipeo es raro y la confusión
           entre ellos es constante: no compensa. */
        $p = mb_strpos($texto, $nombre);
        if ($p === false) continue;
        if (isset($out[$num])) continue;   // setiembre/septiembre son el mismo mes
        $pos[$num] = $p;

        $anio = null;
        // Un año pegado al nombre del mes manda sobre el general de la frase.
        if (preg_match('/' . preg_quote($nombre, '/') . '\s*(?:de\s*)?(20\d{2})/u', $texto, $m)) {
            $anio = (int)$m[1];
        } elseif (preg_match('/\b(20\d{2})\b/', $texto, $m)) {
            $anio = (int)$m[1];
        } else {
            $stmt = $pdo->prepare("SELECT MAX(YEAR(fecha)) a FROM operaciones WHERE usuario_id = ? AND MONTH(fecha) = ?");
            $stmt->execute([$uid, $num]);
            $anio = (int)($stmt->fetch()['a'] ?? 0) ?: (int)date('Y');
        }
        $out[$num] = motor_rango_mes($num, $anio);
    }

    /* En el orden en que aparecen escritos, no en el del calendario. "agosto
       contra julio" tiene que comparar agosto CON julio, y no al revés: quién va
       primero decide de qué lado cae el juicio. */
    uksort($out, fn($a, $b) => $pos[$a] <=> $pos[$b]);
    return array_values($out);
}

/**
 * Todos los lotes nombrados, en el orden en que aparecen escritos.
 *
 * El orden importa: "compará La rubia con El bajo" tiene que responder sobre La
 * rubia primero. Iterando el catálogo salía al revés y la respuesta contestaba
 * bien los números pero invertía el sujeto de la frase.
 */
function motor_detectar_lotes(string $texto, array $lotes): array {
    $out = [];
    foreach ($lotes as $l) {
        $n = motor_normalizar($l['nombre']);
        $p = mb_strpos($texto, $n);
        if ($p === false) {
            if (!motor_coincide($texto, $n)) continue;
            $p = PHP_INT_MAX;   // reconocido por aproximación: va al final
        }
        $out[] = ['pos' => $p, 'lote' => $l];
    }
    usort($out, fn($a, $b) => $a['pos'] <=> $b['pos']);
    return array_column($out, 'lote');
}

/**
 * Compara dos escenarios sobre la misma métrica.
 *
 * Un "escenario" es cualquier recorte: una campaña, un mes, un lote, un cultivo.
 * Al generalizarlo así, comparar meses o lotes no necesitó código nuevo de
 * cálculo — es el mismo getGlobalStats con distintos filtros.
 *
 * @param array $a  ['ciclo','lote','cultivo','rango','etiqueta']
 */
function motor_comparar($ctrl, string $mk, array $a, array $b): array {
    $cm = motor_metricas()[$mk] ?? motor_metricas()['margen_neto'];
    $sA = $ctrl->getGlobalStats($a['ciclo'] ?? null, $a['lote'] ?? null, $a['cultivo'] ?? null, $a['rango'] ?? null);
    $sB = $ctrl->getGlobalStats($b['ciclo'] ?? null, $b['lote'] ?? null, $b['cultivo'] ?? null, $b['rango'] ?? null);
    $vA = (float)($sA[$mk] ?? 0);
    $vB = (float)($sB[$mk] ?? 0);

    // En los costos, "más" no es una buena noticia: el juicio se invierte.
    $esCosto = in_array($mk, ['costo_por_ha','costo_por_kg','costos_directos','costos_alquiler'], true);
    if ($esCosto) {
        $juicio = $vA < $vB ? 'Gastaste menos' : ($vA > $vB ? 'Gastaste más' : 'Gastaste lo mismo');
    } else {
        $juicio = $vA > $vB ? 'Te fue mejor' : ($vA < $vB ? 'Te fue peor' : 'Quedó igual');
    }

    return [
        'texto'   => $juicio . ' en ' . $a['etiqueta'] . ' que en ' . $b['etiqueta'] . ': '
                   . $cm['etiqueta'] . ' de ' . motor_formatear($vA, $cm['formato'])
                   . ' contra ' . motor_formatear($vB, $cm['formato']) . '.',
        'detalle' => 'Diferencia: ' . motor_variacion($vB, $vA) . '.',
        'valor'   => $vA,
    ];
}

/** ¿Está pidiendo comparar? */
function motor_pide_comparar(string $texto): bool {
    foreach (['compara','comparar','comparame','versus',' vs ','contra','mejor que','peor que',
              'me fue mejor','me fue peor','diferencia entre'] as $p) {
        if (strpos($texto, $p) !== false) return true;
    }
    return false;
}

/**
 * ¿Pide abrir el resultado por lote o por cultivo? 'lote' | 'cultivo' | null.
 *
 * Es distinto de "cuál es mi mejor lote", que ya existe y elige un ganador.
 * Acá se pide la lista completa: cuánto dejó CADA uno. Uno responde "quién
 * ganó" y el otro "cómo le fue a cada cual", y el productor quiere las dos
 * cosas en momentos distintos.
 *
 * Se exigen las frases con "por" o "cada" a propósito: "en qué lote gasté más"
 * es el ranking que ya existe, y no tiene que caer acá.
 */
function motor_pide_desglose_dimension(string $t): ?string {
    foreach (['por lote','por cada lote','en cada lote','lote por lote',
              'de cada lote','para cada lote','por los lotes',
              /* "comparación de lotes" es esto y no la comparación entre
                 campañas: pedir comparar los lotes es pedir verlos a todos con
                 su número al lado. Comparar DOS lotes nombrados sigue yendo a la
                 otra rama, que es la que sabe enfrentarlos de a pares. */
              'comparacion de lotes','comparacion entre lotes','compara los lotes',
              'comparar los lotes','comparar lotes','compara mis lotes',
              'comparar mis lotes','comparame los lotes'] as $p) {
        if (strpos($t, $p) !== false) return 'lote';
    }
    foreach (['por cultivo','por cada cultivo','en cada cultivo','cultivo por cultivo',
              'de cada cultivo','para cada cultivo','por especie','por los cultivos',
              'comparacion de cultivos','compara los cultivos','comparar los cultivos',
              'comparar cultivos'] as $p) {
        if (strpos($t, $p) !== false) return 'cultivo';
    }
    return null;
}

/**
 * ¿Pregunta si el alquiler está acorde, o hasta cuánto podría pagar?
 *
 * Son la misma cuenta vista desde dos lados: lo que la hectárea deja después
 * del laboreo es, a la vez, el techo de lo que se puede pagar y la vara contra
 * la que se mide lo que se paga hoy.
 *
 * No se contesta "conviene" ni "está bien": eso depende de si hay otro campo
 * disponible, de la relación con el dueño y de cuánto riesgo se quiere tomar.
 * Se da el número y la comparación; el juicio lo hace el productor.
 */
function motor_pide_alquiler_potencial(string $t): bool {
    if (!preg_match('/(alquiler|alquileres|arrendamiento|renta|arriendo)/u', $t)) return false;
    foreach (['acorde','potencial','esta bien','está bien','vale lo que','justo',
              'hasta cuanto','hasta cuánto','cuanto puedo pagar','cuánto puedo pagar',
              'cuanto podria pagar','maximo que puedo','máximo que puedo','techo',
              'caro','barato','sobrepago','estoy pagando de mas','pagando de mas',
              'me conviene pagar','conviene pagar'] as $p) {
        if (strpos($t, $p) !== false) return true;
    }
    return false;
}

/** ¿Pregunta por el stock, el precio del catálogo o el vencimiento? */
function motor_pide_stock(string $t): bool {
    foreach (['stock','inventario','cuanto me queda de','cuanto tengo de',
              'que tengo cargado','que insumos tengo','vencimiento','vencen',
              'por vencer','precio de los insumos','precios de los insumos',
              'compara los precios','comparar los precios','a cuanto tengo'] as $p) {
        if (strpos($t, $p) !== false) return true;
    }
    return false;
}

/** ¿Pregunta cuánto insumo se USÓ, que es otra cosa que cuánto queda? */
function motor_pide_consumo(string $t): bool {
    foreach (['cuanto use','cuantos kilos use','cuantos kg use','cuanta use','cuantos use',
              'cuanto apliq','cuanto puse','cuanto lleva','cuanto llevo',
              'kg de semilla','kilos de semilla','cantidad de semilla',
              'cuanto consumi','consumo de',
              // "tirar" es como se dice en el campo, y faltaba.
              'tire','tiro de','tiraste','cuanto tire','apliqu','use de','usaste'] as $p) {
        if (strpos($t, $p) !== false) return true;
    }
    return false;
}

/** ¿La pregunta pide una CANTIDAD y no plata? */
function motor_pide_unidad(string $t): bool {
    /* Una unidad de medida en la frase cambia lo que se está preguntando:
       "cuánto gasté en semillas" quiere pesos, "cuántos kg de semilla" quiere
       kilos. Sin esto la rama del gasto se quedaba con las dos y contestaba en
       plata una pregunta hecha en kilos, que es contestar otra cosa. */
    return (bool)preg_match(
        '/(^|\s)(kg|kilo|kilos|kilogramo|kilogramos|lt|lts|litro|litros|'
        . 'bolsa|bolsas|dosis|unidad|unidades|cantidad)(\s|$)/u', $t);
}

/** ¿Pregunta a qué PRECIO compró un insumo, no cuánto gastó en total? */
function motor_pide_precio_insumo(string $t): bool {
    foreach (['cuanto pague cada','cuanto pague el kg','cuanto pague el litro',
              'a que precio pague','a que precio compre','a cuanto pague',
              'a cuanto compre','precio que pague','precio unitario',
              'cuanto me salio el kg','cuanto me salio el litro',
              'a cuanto me salio'] as $p) {
        if (strpos($t, $p) !== false) return true;
    }
    return false;
}

/** ¿Pide el mejor o el peor lote? Devuelve 'mejor' | 'peor' | null. */
function motor_pide_ranking_lotes(string $texto): ?string {
    foreach (['mejor lote','lote mas rentable','lote que mas rinde','cual lote rinde mas',
              'que lote me rinde mas','cual es mi mejor'] as $p) {
        if (strpos($texto, $p) !== false) return 'mejor';
    }
    foreach (['peor lote','lote menos rentable','lote que menos rinde','cual lote rinde menos'] as $p) {
        if (strpos($texto, $p) !== false) return 'peor';
    }
    return null;
}

/** Variación porcentual redactada, que es lo que uno realmente quiere leer. */
function motor_variacion(float $a, float $b): string {
    if ($a == 0.0) return $b == 0.0 ? 'sin cambios' : 'sin base para comparar';
    $pct = (($b - $a) / abs($a)) * 100;
    $signo = $pct >= 0 ? 'más' : 'menos';
    return number_format(abs($pct), 1, ',', '.') . '% ' . $signo;
}

/** Da formato es-AR según el tipo de número. */
function motor_formatear($valor, string $formato): string {
    // El mismo símbolo que el panel: "US$" cuando se está mirando en dólares.
    $s = motor_moneda() === 'USD' ? 'US$' : '$';
    switch ($formato) {
        case 'dinero':      return $s . number_format((float)$valor, 2, ',', '.');
        case 'dinero_fino': return $s . number_format((float)$valor, 4, ',', '.');
        case 'kg_ha':       return number_format((float)$valor, 0, ',', '.') . ' kg/ha';
        case 'kg':          return number_format((float)$valor, 0, ',', '.') . ' kg';
        case 'ha':          return number_format((float)$valor, 1, ',', '.') . ' ha';
        default:            return (string)$valor;
    }
}

/**
 * ¿Aparece la frase en el texto, aunque esté mal tipeada?
 *
 * Primero se busca literal. Si no está y la frase es de una sola palabra larga,
 * se compara token por token con distancia de edición: "hectarea" tiene demasiadas
 * formas de escribirse mal como para exigir que salga perfecta.
 */
function motor_coincide(string $texto, string $frase): bool {
    if ($frase === '') return false;
    if (strpos($texto, $frase) !== false) return true;
    if (mb_strlen($frase) < 5) return false;

    // Las frases que arrancan con una pregunta ("cuánto gané", "cuánto gasté")
    // sólo valen literales. Están armadas con palabras comunísimas y a distancia
    // corta de cualquier otra cosa: con tolerancia, "cuanto sale el tractor"
    // pasaba por "cuánto gané" y contestaba el margen. Lo que sí tolera error es
    // el vocabulario propio del campo —hectárea, indiferencia, el nombre del
    // lote—, que es justo donde el productor se equivoca escribiendo.
    $apertura = explode(' ', $frase)[0];
    if (in_array($apertura, ['cuanto','cuantos','cuantas','cual','que','como'], true)) {
        return false;
    }

    // Se comparan ventanas del texto del mismo largo en palabras que la frase.
    // Comparar palabra por palabra no alcanzaba: "costo por hectrea" no se parecía
    // a ninguna palabra suelta de "costo por hectarea", así que ganaba la métrica
    // "hectareas" y contestaba la superficie en lugar del costo.
    $palabras = explode(' ', $texto);
    $n = count(explode(' ', $frase));

    // Tolerancia por tramos y no proporcional: la transposición ("rubai" por
    // "rubia") es el error de tipeo más frecuente y Levenshtein la cuenta como
    // distancia 2, así que con tolerancia 1 se escapaba justo el caso común.
    $largo = mb_strlen($frase);
    $tolerancia = $largo < 8 ? 1 : ($largo < 16 ? 2 : 3);

    for ($i = 0; $i + $n <= count($palabras); $i++) {
        $ventana = implode(' ', array_slice($palabras, $i, $n));
        if (mb_strlen($ventana) < 4) continue;
        if (levenshtein($ventana, $frase) <= $tolerancia) return true;
    }
    return false;
}

/**
 * Elige la métrica. Gana el sinónimo más largo que coincida: "costo por hectarea"
 * le tiene que ganar a "costo", que también está en el texto.
 */
function motor_detectar_metrica(string $texto): ?string {
    $mejor = null; $mejorLargo = 0;
    foreach (motor_metricas() as $clave => $m) {
        foreach ($m['sinonimos'] as $syn) {
            $synN = motor_normalizar($syn);
            if (motor_coincide($texto, $synN) && mb_strlen($synN) > $mejorLargo) {
                $mejor = $clave; $mejorLargo = mb_strlen($synN);
            }
        }
    }
    return $mejor;
}

/** Campaña: acepta 26/27, 26-27, 2627 y "26 27". */
function motor_detectar_campania(string $texto, array $ciclos): ?string {
    foreach ($ciclos as $c) {
        if (strpos($texto, motor_normalizar($c)) !== false) return $c;
    }
    if (preg_match('/\b(\d{2})\s*[\/\-]?\s*(\d{2})\b/', $texto, $m)) {
        $cand = $m[1] . '/' . $m[2];
        foreach ($ciclos as $c) {
            if (motor_normalizar($c) === $cand) return $c;
        }
    }
    return null;
}

/** Lote: por nombre exacto, y si no por nombre aproximado (tolera el tipeo). */
function motor_detectar_lote(string $texto, array $lotes): ?array {
    foreach ($lotes as $l) {
        if (strpos($texto, motor_normalizar($l['nombre'])) !== false) return $l;
    }
    // Mismo criterio de ventanas que las métricas: los nombres de lote son de
    // varias palabras ("La rubia") y se tipean mal igual que todo lo demás.
    foreach ($lotes as $l) {
        if (motor_coincide($texto, motor_normalizar($l['nombre']))) return $l;
    }
    return null;
}

function motor_detectar_cultivo(string $texto, array $cultivos): ?string {
    foreach ($cultivos as $c) {
        if ($c !== '' && strpos($texto, motor_normalizar($c)) !== false) return $c;
    }
    return null;
}

/** ¿Está preguntando qué significa algo, en vez de pidiendo el número? */
function motor_pide_definicion(string $texto): bool {
    foreach (['que es','que significa','que quiere decir','explicame','como se calcula','a que se refiere'] as $p) {
        if (strpos($texto, $p) !== false) return true;
    }
    return false;
}

/**
 * Resuelve una pregunta.
 *
 * Devuelve siempre la misma forma, así el front no tiene que ramificar:
 *   ok, tipo, respuesta, detalle, valor, filtros, link, sugerencias
 */
function motor_responder(PDO $pdo, int $usuarioId, string $pregunta, array $contexto = []): array {
    require_once __DIR__ . '/../controllers/DashboardController.php';
    $texto = motor_normalizar($pregunta);

    /* ── En qué moneda quiere leerlo ─────────────────────────────────────────
       Antes que nada, porque decide en qué moneda se calcula TODO lo que sigue:
       el controlador y las consultas de este archivo se arman con ella.

       No se toca cuando hay una carga a medias ni cuando la frase es un alta: en
       "cargá un gasto en dólares", el "en dólares" es la moneda con la que se
       pagó —un dato que se guarda— y no cómo se quiere leer un total. Confundir
       las dos cosas escribiría un gasto en la moneda equivocada, que es mucho
       peor que no entender la pregunta. */
    if (empty($contexto['alta']) && !motor_pide_alta($texto)) {
        [$resto, $pedida] = motor_separar_moneda($texto);
        if ($pedida !== null) {
            motor_moneda($pedida);
            // Sola, la frase es "lo mismo pero en dólares": se rehace la anterior.
            if ($resto === '' || motor_resto_es_relleno($resto)) {
                return motor_misma_pregunta_en($pdo, $usuarioId, $contexto, $pedida);
            }
            // Con pregunta propia, sigue de largo ya sin la moneda adentro.
            $texto = $resto;
        }
    }

    $ctrl  = new DashboardController($pdo, $usuarioId);
    // Todo lo que se calcule por el controlador sale en la misma moneda que el
    // panel, igual que las consultas directas de este archivo.
    $ctrl->setMoneda(motor_moneda());

    if ($texto === '') {
        return motor_sin_entender($ctrl, 'Escribí una pregunta sobre tus números.');
    }

    /* ── Lo social ───────────────────────────────────────────────────────────
       Va primero, pero NO se queda con la pregunta: "hola, ¿cuánto gasté?" tiene
       que contestar el gasto. Sólo responde como charla cuando la frase es
       únicamente social — si no, sigue de largo y el saludo se ignora. */
    $nombre  = motor_nombre($pdo, $usuarioId);
    $vocativo = $nombre ? ', ' . $nombre : '';

    // "llamame Alejo" — se guarda y se confirma usándolo, que es la prueba.
    $nuevoNombre = motor_detectar_nombre_propio($pregunta);
    if ($nuevoNombre !== null) {
        motor_guardar_nombre($pdo, $usuarioId, $nuevoNombre);
        return [
            'ok' => true, 'tipo' => 'social',
            'respuesta' => 'Listo, ' . $nuevoNombre . '. Te llamo así de ahora en más.',
            'detalle' => 'Preguntame lo que quieras de tu campaña.',
            'valor' => null, 'filtros' => [], 'link' => null,
            'sugerencias' => ['¿Cuál es mi margen neto?', '¿En qué gasté más?', '¿Cuánto gasté en siembra?'],
        ];
    }

    if (motor_pide_ayuda($texto)) {
        $cat = motor_metricas();
        return [
            'ok' => true, 'tipo' => 'social',
            /* Acá sí va el nombre: es la única respuesta donde el productor está
               preguntando QUIÉN contesta. En el resto no hace falta repetirlo —
               un asistente que se nombra en cada frase cansa a la tercera. */
            'respuesta' => 'Soy Cafrita' . $vocativo . '. Te llevo las cuentas del campo.',
            'detalle' => 'Puedo darte ' . implode(', ', array_map(fn($m) => $m['etiqueta'], $cat))
                       . '. Y desglosar el gasto por etapa, por tipo de insumo o por proveedor, '
                       . 'filtrar por lote, cultivo o mes, abrir cualquier número por lote o por '
                       . 'cultivo, y comparar campañas, lotes o meses entre sí. '
                       . 'También te digo el clima y la lluvia de cada lote, y a cuánto está el grano '
                       . 'en la pizarra: con eso te saco cuánto te deja la hectárea a ese precio. '
                       . 'Y te armo el reporte o el Excel de lo que quieras: toda la campaña, un lote, '
                       . 'un cultivo, o sólo una etapa como siembra o fertilización. '
                       . 'Del catálogo de insumos te digo qué tenés en stock, a qué precio, qué está '
                       . 'por debajo del mínimo o vencido, y cuánto usaste de cada uno en cada lote.',
            'valor' => null, 'filtros' => [], 'link' => null,
            'sugerencias' => ['¿En qué gasté más?', '¿Cómo está el clima?', '¿Cuánto gasté en agosto?'],
        ];
    }

    // Sólo social si NO hay además una pregunta real escondida en la frase.
    $solo_social = (motor_es_saludo($texto) || motor_es_gracias($texto) || motor_es_despedida($texto))
                 && motor_detectar_metrica($texto) === null
                 && !motor_pide_ranking($texto)
                 && motor_detectar_dimension($texto, motor_grupos()) === null
                 && motor_detectar_mes($pdo, $usuarioId, $texto) === null;

    if ($solo_social) {
        if (motor_es_despedida($texto)) {
            return [
                'ok' => true, 'tipo' => 'social',
                'respuesta' => 'Cuando quieras' . $vocativo . '. Acá estoy.',
                'detalle' => '', 'valor' => null, 'filtros' => [], 'link' => null, 'sugerencias' => [],
            ];
        }
        if (motor_es_gracias($texto)) {
            return [
                'ok' => true, 'tipo' => 'social',
                'respuesta' => 'De nada' . $vocativo . '.',
                'detalle' => '¿Querés ver algo más?', 'valor' => null, 'filtros' => [], 'link' => null,
                'sugerencias' => ['¿En qué gasté más?', '¿Cuál es mi mejor lote?'],
            ];
        }

        /* Un saludo con un número adentro sirve; uno sin nada es cortesía vacía.
           Y el saludo sigue la hora del productor, que arranca a las 7 en la
           oficina del campo y vuelve a mirar de noche. */
        $saludo = motor_saludo_horario($texto) . $vocativo . '.';
        $detalle = 'Preguntame lo que quieras de tu campaña.';
        $ciclos0 = $ctrl->getCiclos();
        if (!empty($ciclos0)) {
            $s = $ctrl->getGlobalStats($ciclos0[0]);
            if ((float)$s['ingresos'] !== 0.0 || (float)$s['costos_directos'] !== 0.0) {
                $m = (float)$s['margen_neto'];
                /* Se reacciona al número, no sólo se informa. Pero con cuidado:
                   festejar una pérdida es peor que no decir nada, así que el
                   margen negativo se nombra derecho, sin adornos ni ánimo falso. */
                $saludo .= $m >= 0
                    ? ' La campaña ' . $ciclos0[0] . ' va con ' . motor_formatear($m, 'dinero') . ' de margen.'
                    : ' Ojo que la campaña ' . $ciclos0[0] . ' va con ' . motor_formatear($m, 'dinero') . ': está en pérdida.';
                /* El saludo cierra con lo más llamativo de la campaña. Es la
                   diferencia entre que te salude y que te ponga al día. */
                $hs = motor_analisis($pdo, $ctrl, $usuarioId, $ciclos0[0], null, null);
                $detalle = $hs
                    ? $hs[0]['txt']
                    : ($m >= 0
                        ? 'Preguntame por el detalle: costos, rinde, lotes o cualquier mes.'
                        : 'Si querés vemos dónde se va la plata: preguntame en qué gastaste más.');
            }
        }
        return [
            'ok' => true, 'tipo' => 'social',
            'respuesta' => $saludo, 'detalle' => $detalle,
            'valor' => null, 'filtros' => [], 'link' => null,
            /* Va el clima entre los atajos del saludo: es lo que nadie adivina
               que se puede preguntar, y es lo primero que uno mira a la mañana. */
            'sugerencias' => ['¿En qué gasté más?', '¿Cómo está el clima?', '¿Cuál es mi mejor lote?'],
        ];
    }

    $ciclos = $ctrl->getCiclos();
    if (empty($ciclos)) {
        return [
            'ok' => false, 'tipo' => 'sin_datos',
            'respuesta' => 'Todavía no hay ninguna campaña cargada.',
            'detalle' => 'Cargá un lote y un cultivo con su ciclo y vuelvo a poder contestarte.',
            'valor' => null, 'filtros' => [], 'link' => 'lotes.php', 'sugerencias' => [],
        ];
    }

    $metrica = motor_detectar_metrica($texto);

    // La campaña por defecto es la más reciente, que es la que el productor mira.
    $campania = motor_detectar_campania($texto, $ciclos)
             ?? ($contexto['ciclo'] ?? null)
             ?? $ciclos[0];

    $lotes    = $ctrl->getLotesDelCiclo($campania);
    $cultivos = $ctrl->getCultivosDelCiclo($campania);
    $lote     = motor_detectar_lote($texto, $lotes);
    $cultivo  = motor_detectar_cultivo($texto, $cultivos);

    /* ── Memoria de la conversación ──────────────────────────────────────────
       Sin esto, "¿y el margen?" después de preguntar por un lote volvía al total
       de la campaña, que es justo lo que uno NO preguntó. Arrastrar el filtro es
       lo que hace que se sienta una charla y no un buscador.

       El riesgo del arrastre es quedar pegado a un lote sin darse cuenta, así que
       hay dos salidas: frases que lo limpian explícitamente, y el chip de contexto
       visible en la interfaz. Nunca hay un filtro activo que no se vea. */
    $limpia_contexto = false;
    foreach (['en total','en general','todos los lotes','todo el campo','general','de todo'] as $frase) {
        if (strpos($texto, $frase) !== false) { $limpia_contexto = true; break; }
    }

    if (!$limpia_contexto) {
        if ($lote === null && !empty($contexto['lote'])) {
            foreach ($lotes as $l) {
                if ((int)$l['id'] === (int)$contexto['lote']) { $lote = $l; break; }
            }
        }
        if ($cultivo === null && !empty($contexto['cultivo'])
            && in_array($contexto['cultivo'], $cultivos, true)) {
            $cultivo = $contexto['cultivo'];
        }
    }

    /* Si la frase no nombra métrica pero sí cambia el alcance —"y en total",
       "¿y en el Lote 2?"— se retoma la última. En una charla eso es lo obvio: se
       está pidiendo el mismo número con otro recorte.

       Sólo se retoma cuando hay una señal real de continuidad: un lote, un
       cultivo, una campaña, una frase que limpia el filtro, o un "y" al empezar.
       Sin esa condición, "cuánto sale el tractor" heredaría la métrica anterior y
       contestaría cualquier cosa con total seguridad, que es peor que no saber. */
    if ($metrica === null && !empty($contexto['metrica'])
        && isset(motor_metricas()[$contexto['metrica']])) {
        $continua = $limpia_contexto
                 || $lote !== null
                 || $cultivo !== null
                 || motor_detectar_campania($texto, $ciclos) !== null
                 || preg_match('/^y\b/u', $texto) === 1;
        if ($continua) {
            $metrica = $contexto['metrica'];
        }
    }

    $loteId = $lote['id'] ?? null;

    /* ── Alta guiada ─────────────────────────────────────────────────────────
       Va ANTES que todo lo demás: si hay una carga a medias, lo que el productor
       escribió es la respuesta a la pregunta pendiente, no una consulta nueva.
       Sin esto, contestar "cosecha" en medio del formulario se interpretaría como
       "cuánto gasté en cosecha" y se perdería lo que venía cargando. */
    $enCurso = $contexto['alta'] ?? null;

    $arranca = motor_pide_alta_guiada($texto, $pregunta);

    if (is_array($enCurso) || $arranca !== null) {
        $slots = is_array($enCurso) ? $enCurso : [];

        /* Para CARGAR se usan todos los lotes del productor, no los de la
           campaña. Un lote entra en la lista de la campaña recién cuando ya
           tiene algo cargado, así que al registrar el primer gasto del ciclo
           "todos" devolvía sólo los que ya habían recibido algo —muchas veces
           uno— y nombrar un lote todavía vacío no lo encontraba. El formulario
           de Costos y Labores siempre ofreció todos; acá faltaba. */
        $lotes = motor_lotes_del_usuario($pdo, $usuarioId) ?: $lotes;

        if (motor_alta_cancelada($texto)) {
            return [
                'ok' => true, 'tipo' => 'alta_cancelada',
                'respuesta' => 'Listo, lo dejamos. No guardé nada.',
                'detalle' => '', 'valor' => null,
                'alta_pendiente' => null,
                'filtros' => [], 'link' => null, 'sugerencias' => [],
            ];
        }

        /* Qué se está cargando. Viaja en los casilleros para que no haya que
           volver a deducirlo en cada vuelta: contestar "Semilla" a mitad de un alta
           de insumo no dice por sí solo qué formulario se está llenando. */
        $slots['que'] = $slots['que'] ?? ($arranca ?? 'gasto');

        /* Corregir un casillero. Va antes del reparto por tipo de alta para que
           valga en los cuatro formularios: se vacía el casillero y el paso a
           paso lo vuelve a preguntar solo, sin tocar el resto de lo cargado. */
        $corregir = motor_alta_pide_correccion($texto, $slots['que']);
        if ($corregir !== null) {
            $campos = motor_alta_campos($slots['que']);
            if ($corregir === '') {
                /* Dijo que algo está mal pero no dijo qué: se pregunta, con la
                   lista de lo que se puede tocar. Sólo los casilleros que ESTE
                   formulario usa: ofrecer "corregir el insumo" en un gasto de
                   mano de obra manda a un paso que ni siquiera se preguntó. */
                $pasosC = motor_alta_pasos($slots['que']);
                $ofrecer = [];
                foreach ($campos as $campo => $d) {
                    if (!array_key_exists($campo, $slots)) continue;
                    if (isset($pasosC[$campo]) && !motor_alta_paso_aplica($pasosC[$campo], $slots)) continue;
                    $ofrecer[] = 'Corregir ' . $d['etiqueta'];
                }
                return [
                    'ok' => true, 'tipo' => 'alta_paso',
                    'respuesta' => '¿Qué querés corregir?',
                    'detalle' => 'Tocá el dato que está mal y te lo vuelvo a preguntar. '
                               . 'El resto queda como está.',
                    'valor' => null,
                    'alta_pendiente' => $slots,
                    'filtros' => [], 'link' => null,
                    'sugerencias' => $ofrecer,
                ];
            }
            unset($slots[$corregir]);
            /* El reparto depende del monto y de cuántos lotes hay: si se corrige
               alguno de esos, el que había deja de tener sentido. */
            if (in_array($corregir, ['costo_total', 'lotes'], true)) unset($slots['reparto']);
            // Y el insumo arrastra su cantidad y su vínculo con el catálogo.
            if ($corregir === 'insumo_nombre') {
                unset($slots['insumo_id'], $slots['insumo_cantidad'], $slots['insumo_unidad']);
            }
        }

        if ($slots['que'] === 'insumo') {
            return motor_alta_insumo($pdo, $usuarioId, $slots, $texto, $pregunta, is_array($enCurso));
        }
        if ($slots['que'] === 'alquiler') {
            return motor_alta_alquiler($pdo, $usuarioId, $slots, $texto, $pregunta, is_array($enCurso), $lotes, $campania);
        }
        if ($slots['que'] === 'venta') {
            return motor_alta_venta($pdo, $usuarioId, $slots, $texto, $pregunta, is_array($enCurso), $lotes, $campania);
        }

        $pasos = motor_alta_pasos();
        // Por qué no se aceptó la fecha, si es que dijo una. Vacío si no hubo problema.
        $avisoFecha = '';

        /* Se interpreta la respuesta SEGÚN EL CASILLERO que se preguntó. "cosecha"
           es la etapa cuando se preguntó la etapa, y no otra cosa: acotar la
           interpretación al paso es lo que hace que el paso a paso no se confunda. */
        if (is_array($enCurso)) {
            foreach ($pasos as $campo => $paso) {
                if (isset($slots[$campo])) continue;
                if (!motor_alta_paso_aplica($paso, $slots)) continue;

                if ($campo === 'grupo_gasto') {
                    $g = motor_detectar_dimension($texto, motor_grupos());
                    if ($g) $slots['grupo_gasto'] = $g['clave'];
                } elseif ($campo === 'tipo_componente') {
                    /* Alcanza con distinguir dos cosas, y el productor puede decirlo
                       de muchas maneras. Nombrar un rubro también cuenta: quien
                       contesta "fertilizante" está diciendo que fue un insumo. */
                    if (motor_detectar_dimension($texto, motor_tipos_insumo())
                        || preg_match('/(^|\s)(insumo|insumos|producto|comp[ré]|compra)/u', $texto)) {
                        $slots['tipo_componente'] = 'insumo';
                    } elseif (preg_match('/(^|\s)(mano de obra|labor|labores|jornal|contratista|trabajo|servicio)/u', $texto)) {
                        $slots['tipo_componente'] = 'labor';
                    }
                } elseif ($campo === 'insumo_nombre') {
                    $n = trim(preg_replace('/\s+/u', ' ', $pregunta));

                    if (motor_normalizar($n) === motor_normalizar(MOTOR_INSUMO_LIBRE)) {
                        /* Tocó "No está en la lista". No se llena el casillero: se
                           vuelve a preguntar, ahora sin los chips, para que escriba. */
                        $slots['insumo_libre'] = 1;
                    } else {
                        /* ¿Es uno del catálogo? Se compara contra el nombre exacto y
                           después con tolerancia, igual que con los lotes: el chip
                           manda el nombre tal cual, pero también se puede tipear. */
                        $elegido = null;
                        foreach (motor_insumos_catalogo($pdo, $usuarioId) as $ins) {
                            if (motor_normalizar($ins['nombre']) === motor_normalizar($n)) { $elegido = $ins; break; }
                        }
                        if (!$elegido && empty($slots['insumo_libre'])) {
                            foreach (motor_insumos_catalogo($pdo, $usuarioId) as $ins) {
                                if (motor_coincide(motor_normalizar($n), motor_normalizar($ins['nombre']))) { $elegido = $ins; break; }
                            }
                        }

                        if ($elegido) {
                            $slots['insumo_id']     = (int)$elegido['id'];
                            $slots['insumo_nombre'] = $elegido['nombre'];
                            $slots['insumo_unidad'] = $elegido['unidad_medida'];
                        } elseif ($n !== '' && mb_strlen($n) <= 150) {
                            /* Escrito a mano: se guarda tal cual y NO se vincula. Es
                               la opción "Sin Descontar Stock" del formulario. */
                            $slots['insumo_id']     = 0;
                            $slots['insumo_nombre'] = $n;
                        }
                    }
                } elseif ($campo === 'insumo_cantidad') {
                    $c = motor_detectar_monto($pregunta);
                    if ($c === null && preg_match('/(\d+(?:[.,]\d+)?)/u', $pregunta, $mm)) {
                        $c = (float)str_replace(',', '.', $mm[1]);
                    }
                    if ($c !== null && $c > 0) $slots['insumo_cantidad'] = $c;
                } elseif ($campo === 'lotes') {
                    /* "todos" primero: si el productor lo dice, no hace falta que
                       ninguno de los nombres aparezca en la frase. */
                    if (preg_match('/(^|\s)(todos|todo el campo|todos los lotes|en todos|cada lote|cada uno de los lotes|lote por lote)(\s|$)/u', $texto)) {
                        $slots['lotes'] = array_map(fn($l) => (int)$l['id'], $lotes);
                    } else {
                        $ls = motor_detectar_lotes($texto, $lotes);
                        if ($ls) $slots['lotes'] = array_map(fn($l) => (int)$l['id'], $ls);
                    }
                } elseif ($campo === 'reparto') {
                    if (preg_match('/(hectarea|hectareas|por ha|\/ha)/u', $texto))      $slots['reparto'] = 'hectarea';
                    elseif (preg_match('/(total|todo junto|entre todos|repart)/u', $texto)) $slots['reparto'] = 'total';
                } elseif ($campo === 'costo_total') {
                    // Acá sí vale un número pelado: se preguntó cuánto fue.
                    $m = motor_detectar_monto($pregunta);
                    if ($m === null && preg_match('/\b(\d+)\b/', $pregunta, $mm)) $m = (float)$mm[1];
                    if ($m !== null && $m > 0) $slots['costo_total'] = $m;
                } elseif ($campo === 'fecha') {
                    /* Todo lo resuelve motor_detectar_fecha(), incluidos "hoy" y
                       "ayer": tenerlo en un solo lugar es lo que evita que acá
                       "anteayer" diga una cosa y allá otra. Si devuelve null no se
                       llena el casillero y se vuelve a preguntar, con el aviso de
                       abajo explicando por qué. */
                    $f = motor_detectar_fecha($pregunta);
                    if ($f !== null) $slots['fecha'] = $f;
                    else $avisoFecha = motor_aviso_fecha($pregunta);
                }
                break;   // un dato por vuelta
            }
        }

        // ¿Qué falta todavía?
        if ($falta = motor_alta_siguiente($slots, $lotes, $avisoFecha,
                                          motor_insumos_catalogo($pdo, $usuarioId))) return $falta;

        // Están todos: se arma la MISMA confirmación que el modo de una sola frase.
        $prop = motor_armar_alta($slots, $lotes, $campania);
        return [
            'ok' => true, 'tipo' => 'propuesta_alta',
            'respuesta' => 'Listo. Confirmame que está bien:',
            'detalle' => $prop['detalle'],
            'valor' => $prop['datos']['costo_total'],
            'alta' => $prop['datos'],
            'alta_pendiente' => null,   // el formulario se cierra
            'filtros' => ['ciclo' => $campania, 'lote' => count($prop['datos']['lotes']) === 1 ? $prop['datos']['lotes'][0] : null,
                          'cultivo' => null, 'metrica' => null],
            'link' => null, 'sugerencias' => [],
        ];
    }

    /* ── Alta de gasto ───────────────────────────────────────────────────────
       Devuelve una PROPUESTA, nunca escribe. El guardado lo hace api/registrar.php
       por POST, con los campos ya confirmados. */
    if (motor_pide_alta($texto)) {
        // Misma razón que en el alta guiada: para cargar valen todos los lotes.
        $lotes = motor_lotes_del_usuario($pdo, $usuarioId) ?: $lotes;
        $slots = motor_interpretar_alta($texto, $pregunta, $lotes, $lote);

        /* Lo que la frase no traiga lo pregunta el paso a paso desde donde quedó,
           en vez de un cartel sin salida. El lote nunca se da por supuesto aunque
           haya uno solo: acá se está por escribir, y un gasto en el campo
           equivocado no se nota hasta que el margen ya salió mal. */
        if ($falta = motor_alta_siguiente($slots, $lotes, '',
                                          motor_insumos_catalogo($pdo, $usuarioId))) return $falta;

        $prop = motor_armar_alta($slots, $lotes, $campania);
        return [
            'ok' => true, 'tipo' => 'propuesta_alta',
            'respuesta' => 'Antes de guardar, confirmame que entendí bien:',
            'detalle' => $prop['detalle'],
            'valor' => $prop['datos']['costo_total'],
            // Lo que se guarda es ESTO, no la frase: el guardado no vuelve a interpretar.
            'alta' => $prop['datos'],
            'filtros' => ['ciclo' => $campania,
                          'lote' => count($prop['datos']['lotes']) === 1 ? $prop['datos']['lotes'][0] : null,
                          'cultivo' => $cultivo, 'metrica' => null],
            'link' => null, 'sugerencias' => [],
        ];
    }

    /* ── Lo que no se puede contestar con datos ──────────────────────────────
       Va temprano, antes de que cualquier otra rama se quede con la pregunta y
       vuelva con un número de otra cosa. Decir qué falta y qué sí hay es más
       útil que un "no entendí", y mucho más honesto que un número que parece
       una respuesta. */
    if ($fuera = motor_tema_sin_datos($texto)) {
        return [
            'ok' => false, 'tipo' => 'fuera_de_alcance',
            'respuesta' => 'No te puedo decir ' . $fuera['tema'] . ': me falta un dato.',
            'detalle' => $fuera['falta'] . ' ' . $fuera['puedo'],
            'valor' => null,
            'filtros' => ['ciclo' => $campania, 'lote' => $loteId, 'cultivo' => $cultivo, 'metrica' => null],
            'link' => motor_link($campania, $loteId, $cultivo),
            'sugerencias' => ['¿A cuánto está el trigo?', '¿Cuánto gané por lote?', '¿En qué gasté más?'],
        ];
    }

    /* ── Análisis ────────────────────────────────────────────────────────────
       "¿Cómo viene la campaña?" — el motor mira los números y dice lo llamativo,
       ordenado por lo que se puede hacer al respecto. */
    /* "Cómo me fue" pide el análisis general, pero "cómo me fue EN CADA LOTE"
       pide la lista abierta. Sin este guardia gana el análisis por estar
       primero, y devuelve un resumen de campaña a quien preguntó por lote. */
    if (motor_pide_analisis($texto) && motor_pide_desglose_dimension($texto) === null) {
        $hallazgos = motor_analisis($pdo, $ctrl, $usuarioId, $campania, $loteId, $cultivo);

        if (!$hallazgos) {
            return [
                'ok' => false, 'tipo' => 'sin_datos',
                'respuesta' => 'Todavía no tengo suficiente cargado en ' . $campania . ' para sacar conclusiones.',
                'detalle' => 'Con operaciones y ventas registradas te puedo decir dónde estás parado.',
                'valor' => null,
                'filtros' => ['ciclo' => $campania, 'lote' => $loteId, 'cultivo' => $cultivo, 'metrica' => null],
                'link' => motor_link($campania, $loteId, $cultivo), 'sugerencias' => [],
            ];
        }

        $st  = $ctrl->getGlobalStats($campania, $loteId, $cultivo);
        $mg  = (float)$st['margen_neto'];
        $cab = 'La campaña ' . $campania . ' va con ' . motor_formatear($mg, 'dinero') . ' de margen'
             . ($mg < 0 ? ', en pérdida.' : '.');

        // Tres como máximo: una lista larga deja de ser un análisis y vuelve a ser
        // un volcado de datos, que es de lo que se venía escapando.
        $top = array_slice($hallazgos, 0, 3);
        $lineas = array_map(fn($x) => '• ' . $x['txt'], $top);

        return [
            'ok' => true, 'tipo' => 'analisis',
            'respuesta' => $cab,
            'detalle' => implode("\n", $lineas),
            'valor' => $mg,
            'filtros' => ['ciclo' => $campania, 'lote' => $loteId, 'cultivo' => $cultivo, 'metrica' => 'margen_neto'],
            'link' => motor_link($campania, $loteId, $cultivo),
            'sugerencias' => ['¿En qué gasté más?', '¿Cuál es mi mejor lote?', '¿Me fue mejor que el año pasado?'],
        ];
    }

    /* ── Clima y pronóstico ──────────────────────────────────────────────────
       Va ANTES de la lluvia a propósito: "¿va a llover?" lleva la palabra
       "llover" y caería en el archivo histórico, contestando con el pasado una
       pregunta sobre el futuro. Es la peor forma de equivocarse, porque el
       número que devuelve parece bueno. */
    if (motor_pide_clima($texto)) {
        $lote_clima = $lote;
        if ($lote_clima === null) {
            $todos = $ctrl->getLotesDelCiclo($campania);
            if (count($todos) === 1) {
                $lote_clima = $todos[0];
            } else {
                $nombres = array_map(fn($l) => $l['nombre'], $todos);
                return [
                    'ok' => false, 'tipo' => 'falta_dato',
                    'respuesta' => '¿De qué lote querés saber el clima?',
                    'detalle' => $nombres ? 'Tenés: ' . implode(', ', $nombres) . '.'
                                          : 'Todavía no hay lotes cargados.',
                    'valor' => null,
                    'filtros' => ['ciclo' => $campania, 'lote' => null, 'cultivo' => $cultivo, 'metrica' => null],
                    'link' => 'lotes.php',
                    'sugerencias' => array_map(fn($n) => '¿Cómo está el clima en ' . $n . '?', array_slice($nombres, 0, 3)),
                ];
            }
        }

        $st = $pdo->prepare("SELECT id, nombre, latitud, longitud FROM lotes WHERE id = ? AND usuario_id = ?");
        $st->execute([(int)$lote_clima['id'], $usuarioId]);
        $lt = $st->fetch();

        if (!$lt || !$lt['latitud'] || !$lt['longitud']) {
            return [
                'ok' => false, 'tipo' => 'falta_dato',
                'respuesta' => 'El lote ' . ($lt['nombre'] ?? '') . ' no tiene ubicación cargada, '
                             . 'así que no puedo traer el clima.',
                'detalle' => 'Marcá el lote en el mapa desde Lotes y Cultivos y vuelvo a poder contestarte.',
                'valor' => null,
                'filtros' => ['ciclo' => $campania, 'lote' => (int)$lote_clima['id'], 'cultivo' => $cultivo, 'metrica' => null],
                'link' => 'lotes.php', 'sugerencias' => [],
            ];
        }

        $cl = motor_clima($lt);
        if ($cl === null) {
            return [
                'ok' => false, 'tipo' => 'sin_datos',
                'respuesta' => 'No pude traer el clima en este momento.',
                'detalle' => 'El clima lo consulto a un servicio meteorológico externo; '
                           . 'si no hay internet o está caído, no lo tengo. Probá de nuevo en un rato.',
                'valor' => null,
                'filtros' => ['ciclo' => $campania, 'lote' => (int)$lt['id'], 'cultivo' => $cultivo, 'metrica' => null],
                'link' => 'clima_historico.php?id=' . (int)$lt['id'], 'sugerencias' => [],
            ];
        }

        $num = fn($v, $d = 0) => number_format($v, $d, ',', '.');

        $partes = [];
        if ($cl['humedad'] !== null) $partes[] = 'humedad ' . $cl['humedad'] . '%';
        if ($cl['viento']  !== null) $partes[] = 'viento ' . $num($cl['viento']) . ' km/h';
        if ($cl['mm_ahora'] > 0)     $partes[] = 'cayendo ' . $num($cl['mm_ahora'], 1) . ' mm';

        /* Se nombran los días en vez de dar la fecha: "el jueves" se ubica solo,
           "04/09" hay que ir a mirarlo al calendario. */
        $semana = ['Sun' => 'el domingo', 'Mon' => 'el lunes', 'Tue' => 'el martes',
                   'Wed' => 'el miércoles', 'Thu' => 'el jueves', 'Fri' => 'el viernes',
                   'Sat' => 'el sábado'];
        $prons  = [];
        $helada = null;
        $minima = null;
        foreach ($cl['dias'] as $i => $d) {
            if ($i === 0) continue;                 // hoy ya está en el estado actual
            $ts  = strtotime($d['fecha']);
            $etq = $i === 1 ? 'mañana' : ($semana[date('D', $ts)] ?? date('d/m', $ts));
            $t   = $etq . ' ' . $num($d['max']) . '°/' . $num($d['min']) . '°';
            if ($d['mm'] >= 1.0) {
                $t .= ' con ' . $num($d['mm'], 1) . ' mm';
                if ($d['prob'] !== null) $t .= ' (' . $d['prob'] . '%)';
            }
            $prons[] = $t;
            if ($helada === null && $d['min'] !== null && $d['min'] <= 3) {
                $helada = ['etq' => $etq, 'min' => $d['min']];
            }
            if ($d['min'] !== null && ($minima === null || $d['min'] < $minima)) $minima = $d['min'];
        }

        $detalle = ($partes ? ucfirst(implode(', ', $partes)) . '. ' : '')
                 . ($prons ? 'Para adelante: ' . implode('; ', $prons) . '. ' : '')
                 . 'Es el pronóstico para las coordenadas del lote, no un dato que hayas cargado vos.';

        /* Si preguntó puntualmente por heladas, la respuesta arranca contestando
           eso. Devolver el parte completo sin decir sí o no es no escuchar la
           pregunta, y encima es la que se hace cuando hay algo en juego. */
        if (strpos($texto, 'helada') !== false) {
            $encabezado = $helada
                ? 'Sí: en ' . $lt['nombre'] . ', ' . $helada['etq'] . ' la mínima baja a '
                  . $num($helada['min'], 1) . '°.'
                : 'No se esperan heladas en ' . $lt['nombre'] . ' en los próximos días'
                  . ($minima !== null ? ': la mínima más baja es de ' . $num($minima, 1) . '°' : '') . '.';
        } else {
            $encabezado = 'En ' . $lt['nombre'] . ' ahora hay ' . $num($cl['temp'], 1) . '°, '
                        . $cl['desc'] . '.';
            if ($helada) {
                $detalle = ($partes ? ucfirst(implode(', ', $partes)) . '. ' : '')
                         . ($prons ? 'Para adelante: ' . implode('; ', $prons) . '. ' : '')
                         . 'Ojo que ' . $helada['etq'] . ' la mínima baja a ' . $num($helada['min'], 1)
                         . '°, que es temperatura de helada. '
                         . 'Es el pronóstico para las coordenadas del lote, no un dato que hayas cargado vos.';
            }
        }

        return [
            'ok' => true, 'tipo' => 'clima',
            'respuesta' => $encabezado,
            'detalle' => $detalle,
            'valor' => $cl['temp'],
            'filtros' => ['ciclo' => $campania, 'lote' => (int)$lt['id'], 'cultivo' => $cultivo, 'metrica' => null],
            'link' => 'clima_historico.php?id=' . (int)$lt['id'],
            'sugerencias' => ['¿Va a llover?', '¿Cuánto llovió este mes?', '¿Cuál es mi mejor lote?'],
        ];
    }

    /* ── Lluvia ──────────────────────────────────────────────────────────────
       La lluvia es de un lugar, no de una campaña: sin lote no hay respuesta
       posible. Si el productor tiene uno solo se asume ése; si tiene varios se
       pregunta cuál, que es mejor que elegir por él y contestar de otro campo. */
    if (motor_pide_lluvia($texto)) {
        $lote_lluvia = $lote;
        if ($lote_lluvia === null) {
            $todos = $ctrl->getLotesDelCiclo($campania);
            if (count($todos) === 1) {
                $lote_lluvia = $todos[0];
            } else {
                $nombres = array_map(fn($l) => $l['nombre'], $todos);
                return [
                    'ok' => false, 'tipo' => 'falta_dato',
                    'respuesta' => '¿De qué lote querés saber la lluvia?',
                    'detalle' => $nombres ? 'Tenés: ' . implode(', ', $nombres) . '.'
                                          : 'Todavía no hay lotes cargados.',
                    'valor' => null,
                    'filtros' => ['ciclo' => $campania, 'lote' => null, 'cultivo' => $cultivo, 'metrica' => null],
                    'link' => 'lotes.php',
                    'sugerencias' => array_map(fn($n) => '¿Cuánto llovió en ' . $n . '?', array_slice($nombres, 0, 3)),
                ];
            }
        }

        // Hace falta el lote completo: getLotesDelCiclo no trae las coordenadas.
        $st = $pdo->prepare("SELECT id, nombre, latitud, longitud FROM lotes WHERE id = ? AND usuario_id = ?");
        $st->execute([(int)$lote_lluvia['id'], $usuarioId]);
        $lt = $st->fetch();

        if (!$lt || !$lt['latitud'] || !$lt['longitud']) {
            return [
                'ok' => false, 'tipo' => 'falta_dato',
                'respuesta' => 'El lote ' . ($lt['nombre'] ?? '') . ' no tiene ubicación cargada, '
                             . 'así que no puedo traer la lluvia.',
                'detalle' => 'Marcá el lote en el mapa desde Lotes y Cultivos y vuelvo a poder contestarte.',
                'valor' => null,
                'filtros' => ['ciclo' => $campania, 'lote' => (int)$lote_lluvia['id'], 'cultivo' => $cultivo, 'metrica' => null],
                'link' => 'lotes.php', 'sugerencias' => [],
            ];
        }

        /* El archivo histórico va unos días atrás: pedirle fechas futuras devuelve
           vacío. Preguntar por el mes en curso es normal —"¿cuánto llovió este
           mes?"— así que el final del rango se recorta a lo que ya existe en vez
           de fallar. */
        $tope = date('Y-m-d', strtotime('-2 days'));

        $r = motor_detectar_mes($pdo, $usuarioId, $texto);
        if ($r) {
            $desde = $r['desde'];
            $hasta = min($r['hasta'], $tope);
            $etiqueta = 'en ' . $r['etiqueta'];
            // Mes todavía en curso: se dice, para que 20 días no se lean como 31.
            if ($hasta < $r['hasta']) {
                $etiqueta .= ' (hasta el ' . date('d/m', strtotime($hasta)) . ', el mes no terminó)';
            }
            if ($desde > $tope) {
                return [
                    'ok' => false, 'tipo' => 'sin_datos',
                    'respuesta' => 'Todavía no hay registro de lluvia para ' . $r['etiqueta'] . '.',
                    'detalle' => 'El archivo meteorológico llega hasta hace un par de días.',
                    'valor' => null,
                    'filtros' => ['ciclo' => $campania, 'lote' => (int)$lt['id'], 'cultivo' => $cultivo, 'metrica' => null],
                    'link' => 'clima_historico.php?id=' . (int)$lt['id'], 'sugerencias' => [],
                ];
            }
        } else {
            $hasta = $tope;
            $desde = date('Y-m-d', strtotime($hasta . ' -1 year'));
            $etiqueta = 'en los últimos 12 meses';
        }

        $ll = motor_lluvia($lt, $desde, $hasta);
        if ($ll === null) {
            return [
                'ok' => false, 'tipo' => 'sin_datos',
                'respuesta' => 'No pude traer los datos de lluvia en este momento.',
                'detalle' => 'La lluvia la consulto a un servicio meteorológico externo; '
                           . 'si no hay internet o está caído, no la tengo. Probá de nuevo en un rato.',
                'valor' => null,
                'filtros' => ['ciclo' => $campania, 'lote' => (int)$lt['id'], 'cultivo' => $cultivo, 'metrica' => null],
                'link' => 'clima_historico.php?id=' . (int)$lt['id'], 'sugerencias' => [],
            ];
        }

        $prom = $r ? null : $ll['mm'] / 12;
        return [
            'ok' => true, 'tipo' => 'lluvia',
            'respuesta' => 'En ' . $lt['nombre'] . ', ' . $etiqueta . ' llovieron '
                         . number_format($ll['mm'], 0, ',', '.') . ' mm.',
            'detalle' => $ll['dias'] . ($ll['dias'] === 1 ? ' día con lluvia' : ' días con lluvia')
                       . ($prom !== null ? ', un promedio de ' . number_format($prom, 0, ',', '.') . ' mm por mes' : '')
                       . '. Es el registro histórico para las coordenadas del lote, no un dato que hayas cargado vos.',
            'valor' => $ll['mm'],
            'filtros' => ['ciclo' => $campania, 'lote' => (int)$lt['id'], 'cultivo' => $cultivo, 'metrica' => null],
            'link' => 'clima_historico.php?id=' . (int)$lt['id'],
            'sugerencias' => ['¿Cuánto llovió en enero?', '¿Cuál es mi mejor lote?', '¿En qué gasté más?'],
        ];
    }

    /* ── Reportes ────────────────────────────────────────────────────────────
       Va antes que precio y que gasto-por-etapa: "el excel de los costos de
       siembra" nombra una etapa, y sin esto lo atendería la rama del gasto
       devolviendo un número en vez del archivo que se pidió. */
    if (motor_pide_reporte($texto)) {
        $excel = motor_reporte_es_excel($texto);
        $grupo = motor_detectar_dimension($texto, motor_grupos());

        /* Dos reportes distintos. El de costos es el renglón por renglón de las
           operaciones; el del panel es el resumen por cultivo y lote con
           márgenes. Se elige por lo que nombró: si habla de costos, o de una
           etapa, quiere el detalle. */
        $deCostos = $grupo !== null
                 || preg_match('/costo|gasto|labor|operacion|insumo/u', $texto);

        if ($deCostos) {
            $q = ['tipo' => 'operaciones', 'campania' => $campania];
            // motor_detectar_dimension devuelve ['clave','etiqueta'], no un string.
            if ($grupo)   $q['grupo']   = $grupo['clave'];
            if ($loteId)  $q['lote_id'] = $loteId;
            if ($cultivo) $q['cultivo'] = $cultivo;
            $que = 'los costos';
        } else {
            $q = ['tipo' => 'dashboard', 'ciclo' => $campania];
            if ($loteId)  $q['lote']    = $loteId;
            if ($cultivo) $q['cultivo'] = $cultivo;
            $que = 'el panel';
        }

        /* El reporte sale en la moneda en la que se viene hablando. Si Cafrita
           acaba de contestar en dólares y el archivo llega en pesos, son dos
           números distintos para lo mismo sin nada que lo explique. */
        if (motor_moneda() === 'USD') $q['moneda'] = 'USD';

        /* Los dos formatos van siempre, con el que pidió adelante. Los dos
           endpoints leen los mismos parámetros, así que es el mismo recorte visto
           de dos maneras: el PDF para mirarlo o imprimirlo, el Excel para sacarle
           cuentas. Antes, pedir "el reporte" daba sólo el PDF y había que saber
           que existía la palabra "excel" para conseguir el otro. */
        $urlPdf   = 'api/reporte_pdf.php?'   . http_build_query($q);
        $urlExcel = 'api/reporte_excel.php?' . http_build_query($q);
        $url      = $excel ? $urlExcel : $urlPdf;

        // Qué recorte quedó, dicho con las mismas palabras con que se pidió.
        $recorte = [];
        if ($grupo) $recorte[] = 'sólo ' . $grupo['etiqueta'];
        if ($loteId) {
            $ln = null;
            foreach ($ctrl->getLotesDelCiclo($campania) as $l) {
                if ((int)$l['id'] === (int)$loteId) { $ln = $l['nombre']; break; }
            }
            if ($ln) $recorte[] = 'del lote ' . $ln;
        }
        if ($cultivo) $recorte[] = 'de ' . $cultivo;

        $cab = ($excel ? 'Te armo el Excel con ' : 'Te armo el reporte con ') . $que
             . ' de la campaña ' . $campania
             . ($recorte ? ', ' . implode(', ', $recorte) : '') . '.';

        /* El total va en la respuesta y no sólo en el archivo: si el recorte
           salió mal se nota acá, antes de abrir nada. */
        $det = [];
        if ($deCostos) {
            $extra = $grupo ? ['col' => 'grupo_gasto', 'val' => $grupo['clave']] : null;
            $suma  = motor_gasto($pdo, $usuarioId, $campania, $loteId, $cultivo, $extra);
            $det[] = $suma > 0
                ? 'Suma ' . motor_formatear($suma, 'dinero') . '.'
                : 'Ojo que ese recorte no tiene nada cargado: el archivo va a salir vacío.';
        }
        $det[] = $excel
            ? 'Se abre con Excel o con cualquier planilla de cálculo.'
            : 'Se abre en el navegador y desde ahí lo podés imprimir o guardar como PDF.';
        $det[] = $excel
            ? 'Si lo querés para mirar o imprimir, tenés el reporte acá abajo.'
            : 'Y si preferís hacerle cuentas, abajo lo tenés en Excel.';

        return [
            'ok' => true, 'tipo' => 'reporte',
            'respuesta' => $cab,
            'detalle' => implode(' ', $det),
            'valor' => null,
            'filtros' => ['ciclo' => $campania, 'lote' => $loteId, 'cultivo' => $cultivo, 'metrica' => null],
            'link' => $url,
            'link_texto' => $excel ? 'Descargar el Excel' : 'Abrir el reporte',
            'link_icono' => $excel ? 'fa-file-excel' : 'fa-file-lines',
            'link_alt' => $excel ? $urlPdf : $urlExcel,
            'link_alt_texto' => $excel ? 'Verlo como reporte' : 'Descargarlo en Excel',
            'link_alt_icono' => $excel ? 'fa-file-lines' : 'fa-file-excel',
            'sugerencias' => ['Pasame el excel de siembra', 'Reporte de la campaña', '¿En qué gasté más?'],
        ];
    }

    /* ── Precio de pizarra y qué deja la hectárea ────────────────────────────
       La cotización sola es un dato de diario. Cruzada con el rinde y el costo
       del productor pasa a ser su número. */
    /* Dos formas de entrar. La lista de frases cubre lo genérico ("a cuánto
       está", "si vendo"); la segunda cubre lo que la lista no puede prever:
       cualquier frase con palabra de precio que además nombre un grano con
       pizarra. Salió de motor_consultas_fallidas — "precio de la soja hoy" era
       una pregunta real que no entraba por ninguna frase de la lista. La
       consulta al catálogo va segunda a propósito: sólo corre si ya apareció
       una palabra de precio, así no se pega a la base en cada mensaje. */
    $mencionaGrano = preg_match('/precio|cotiza|vale|valen|pagan|pagando/u', $texto)
                   ? motor_cultivo_cotizable($pdo, $texto) : null;

    if (motor_pide_precio($texto) || $mencionaGrano !== null) {
        $especie = $mencionaGrano ?? motor_cultivo_cotizable($pdo, $texto);

        /* Si no nombró el grano, se usa el de la campaña. Con más de uno se
           pregunta: valuar la producción entera al precio de un solo cultivo
           daría un número prolijo y equivocado. */
        if ($especie === null) {
            if ($cultivo !== null) {
                $especie = motor_normalizar(explode(' ', trim($cultivo))[0]);
            } else {
                /* getCultivosData devuelve el cultivo como CLAVE del arreglo, no
                   como campo de cada fila. */
                $datos = $ctrl->getCultivosData($campania);
                $esp = array_values(array_filter(
                    array_keys($datos),
                    fn($e) => $e !== '' && $e !== 'Sin Especificar'
                ));
                if (count($esp) === 1) {
                    $especie = motor_normalizar(explode(' ', trim($esp[0]))[0]);
                } elseif (count($esp) > 1) {
                    return [
                        'ok' => false, 'tipo' => 'falta_dato',
                        'respuesta' => '¿De qué cultivo querés el precio?',
                        'detalle' => 'En ' . $campania . ' tenés: ' . implode(', ', $esp) . '.',
                        'valor' => null,
                        'filtros' => ['ciclo' => $campania, 'lote' => $loteId, 'cultivo' => null, 'metrica' => null],
                        'link' => motor_link($campania, $loteId, null),
                        'sugerencias' => array_map(
                            fn($e) => '¿A cuánto está ' . motor_articulo_cultivo($e) . ' '
                                    . mb_strtolower($e, 'UTF-8') . '?',
                            array_slice($esp, 0, 3)),
                    ];
                }
            }
        }

        if ($especie === null) {
            return [
                'ok' => false, 'tipo' => 'falta_dato',
                'respuesta' => '¿De qué grano querés el precio?',
                'detalle' => 'Tengo la pizarra de Rosario para trigo, soja, maíz, sorgo y girasol.',
                'valor' => null,
                'filtros' => ['ciclo' => $campania, 'lote' => $loteId, 'cultivo' => $cultivo, 'metrica' => null],
                'link' => null,
                'sugerencias' => ['¿A cuánto está la soja?', '¿A cuánto está el trigo?', '¿A cuánto está el maíz?'],
            ];
        }

        $ref = motor_precio_referencia($pdo, ucfirst($especie));
        if ($ref === null) {
            return [
                'ok' => false, 'tipo' => 'sin_datos',
                'respuesta' => 'No tengo la cotización de ' . $especie . ' en este momento.',
                'detalle' => 'La pizarra la baja un proceso automático desde la Cámara de Rosario. '
                           . 'Si viene fallando, en el panel se ve la última que entró.',
                'valor' => null,
                'filtros' => ['ciclo' => $campania, 'lote' => $loteId, 'cultivo' => $cultivo, 'metrica' => null],
                'link' => motor_link($campania, $loteId, $cultivo), 'sugerencias' => [],
            ];
        }

        /* La pizarra viene en pesos; el panel puede estar en dólares. Convertir
           acá es la misma regla que el resto: se guarda lo que se pagó y se
           convierte al mostrar. */
        $precio_kg = motor_a_moneda_actual($pdo, $usuarioId, $ref['precio_kg']);
        $fechaTxt  = date('d/m', strtotime($ref['fecha']));

        /* El kilo en pesos son cientos ($320,40) y en dólares son centavos
           ($0,2117): con un formato fijo, uno de los dos queda mal. Se elige por
           la magnitud y así sirve en las dos monedas. */
        $fmtPrecio = $precio_kg < 10 ? 'dinero_fino' : 'dinero';

        $st       = $ctrl->getGlobalStats($campania, $loteId, $cultivo);
        $rinde    = (float)$st['rendimiento_ha'];
        $costoHa  = (float)$st['costo_por_ha'];

        $art      = motor_articulo_cultivo($especie);
        $cabecera = ucfirst($art) . ' ' . $especie . ' está a '
                  . motor_formatear($precio_kg, $fmtPrecio) . ' el kilo.';
        $partes   = ['Pizarra de Rosario del ' . $fechaTxt . '.'];

        /* Sin rinde cargado no hay cuenta por hectárea que hacer, y media cuenta
           es peor que ninguna: se dice qué falta. */
        if ($rinde > 0) {
            $ingresoHa = $rinde * $precio_kg;
            $quedaHa   = $ingresoHa - $costoHa;

            $cabecera = 'A ' . motor_formatear($precio_kg, $fmtPrecio) . ' el kilo de ' . $especie
                      . ', la hectárea te deja ' . motor_formatear($quedaHa, 'dinero') . '.';
            $partes[] = 'Con tu rinde de ' . motor_formatear($rinde, 'kg_ha') . ' la hectárea da '
                      . motor_formatear($ingresoHa, 'dinero') . ', y el costo es de '
                      . motor_formatear($costoHa, 'dinero') . ' por hectárea (laboreo más alquiler).';
            if ($quedaHa < 0) {
                $partes[] = 'A este precio no cubrís el costo.';
            }

            // Con qué precio vendió de verdad: dice si la pizarra de hoy está mejor.
            $kg = (float)$st['kg'];
            if ($kg > 0 && (float)$st['ingresos'] > 0) {
                $vendido = (float)$st['ingresos'] / $kg;
                $partes[] = 'Lo que ya vendiste salió a '
                          . motor_formatear($vendido, $vendido < 10 ? 'dinero_fino' : 'dinero')
                          . ' el kilo en promedio.';
            }
            $valor = $quedaHa;
        } else {
            $partes[] = 'Todavía no tenés producción cargada en ' . $campania . ', '
                      . 'así que no puedo decirte cuánto deja la hectárea.';
            $valor = $precio_kg;
        }

        return [
            'ok' => true, 'tipo' => 'precio',
            'respuesta' => $cabecera,
            'detalle' => implode(' ', $partes)
                       . ' Es el precio de referencia de la Cámara, no lo que te paguen a vos.'
                       . (motor_dolar_estimado($pdo, $usuarioId)
                          ? ' Ojo que no tenés cotización del dólar cargada para este mes, '
                            . 'así que el pasaje a dólares es aproximado.'
                          : ''),
            'valor' => $valor,
            'filtros' => ['ciclo' => $campania, 'lote' => $loteId, 'cultivo' => $cultivo, 'metrica' => null],
            'link' => motor_link($campania, $loteId, $cultivo),
            'sugerencias' => ['¿Cuál es mi rinde de indiferencia?', '¿En qué gasté más?', '¿Cuál es mi mejor lote?'],
        ];
    }

    /* ── Comparar campañas ───────────────────────────────────────────────────
       "¿me fue mejor que el año pasado?", "compará 25/26 con 26/27".
       Un número solo no dice nada; comparado con el de antes es un juicio. */
    $otras = [];
    foreach ($ciclos as $c) {
        if ($c !== $campania && strpos($texto, motor_normalizar($c)) !== false) $otras[] = $c;
    }
    $quiere_anterior = false;
    foreach (['año pasado','ano pasado','anio pasado','campania anterior','campana anterior',
              'la anterior','el año anterior'] as $p) {
        if (strpos($texto, $p) !== false) { $quiere_anterior = true; break; }
    }

    $meses_nombrados = motor_detectar_meses($pdo, $usuarioId, $texto);
    $lotes_nombrados = motor_detectar_lotes($texto, $lotes);

    /* El guardia del stock: "comparar" también aparece en "compará los precios
       de los insumos", que no es una comparación entre campañas sino la lista
       del catálogo. Sin esto gana esta rama por estar primero y contesta que no
       hay otra campaña con la que comparar, que no tiene nada que ver. */
    if ((motor_pide_comparar($texto) || $quiere_anterior || $otras
        || count($meses_nombrados) >= 2 || count($lotes_nombrados) >= 2)
        && !motor_pide_stock($texto) && !motor_pide_consumo($texto)
        /* "Compará los lotes" sin nombrar dos es pedir la lista de todos, no un
           enfrentamiento entre campañas. Con dos lotes nombrados sigue entrando
           acá, que es la rama que sabe ponerlos frente a frente. */
        && !(motor_pide_desglose_dimension($texto) !== null && count($lotes_nombrados) < 2)) {

        $mk  = $metrica ?? ($contexto['metrica'] ?? 'margen_neto');
        $sug = ['¿Y el costo por hectárea?', '¿En qué gasté más?', '¿Cuál es mi mejor lote?'];

        /* El eje de comparación lo decide lo que la pregunta nombra. Dos meses
           comparan meses; dos lotes, lotes; si no, campañas. Antes sólo existía
           el último caso y todo lo demás caía ahí, contestando otra cosa. */
        $a = $b = null;

        if (count($meses_nombrados) >= 2) {
            // Se comparan por fecha, sin campaña: un mes no pertenece a una campaña.
            $a = ['ciclo' => null, 'lote' => $loteId, 'cultivo' => $cultivo,
                  'rango' => $meses_nombrados[0], 'etiqueta' => $meses_nombrados[0]['etiqueta']];
            $b = ['ciclo' => null, 'lote' => $loteId, 'cultivo' => $cultivo,
                  'rango' => $meses_nombrados[1], 'etiqueta' => $meses_nombrados[1]['etiqueta']];
            if ($mk === 'margen_neto' && $metrica === null) $mk = 'costos_directos';

        } elseif (count($lotes_nombrados) >= 2) {
            $a = ['ciclo' => $campania, 'lote' => (int)$lotes_nombrados[0]['id'], 'cultivo' => $cultivo,
                  'rango' => null, 'etiqueta' => $lotes_nombrados[0]['nombre']];
            $b = ['ciclo' => $campania, 'lote' => (int)$lotes_nombrados[1]['id'], 'cultivo' => $cultivo,
                  'rango' => null, 'etiqueta' => $lotes_nombrados[1]['nombre']];

        } else {
            // getCiclos() viene de más nueva a más vieja: la siguiente es la anterior.
            $ref = $otras[0] ?? null;
            if ($ref === null) {
                $i = array_search($campania, $ciclos, true);
                if ($i !== false && isset($ciclos[$i + 1])) $ref = $ciclos[$i + 1];
            }
            if ($ref === null) {
                return [
                    'ok' => false, 'tipo' => 'sin_datos',
                    'respuesta' => 'No tengo otra campaña con la que comparar ' . $campania . '.',
                    'detalle' => 'Hace falta al menos una campaña anterior cargada.',
                    'valor' => null,
                    'filtros' => ['ciclo' => $campania, 'lote' => $loteId, 'cultivo' => $cultivo, 'metrica' => $mk],
                    'link' => motor_link($campania, $loteId, $cultivo), 'sugerencias' => $sug,
                ];
            }
            $a = ['ciclo' => $campania, 'lote' => $loteId, 'cultivo' => $cultivo, 'rango' => null, 'etiqueta' => $campania];
            $b = ['ciclo' => $ref, 'lote' => null, 'cultivo' => null, 'rango' => null, 'etiqueta' => $ref];
        }

        $cmp = motor_comparar($ctrl, $mk, $a, $b);
        return [
            'ok' => true, 'tipo' => 'comparacion',
            'respuesta' => $cmp['texto'],
            'detalle' => $cmp['detalle'],
            'valor' => $cmp['valor'],
            'filtros' => ['ciclo' => $campania, 'lote' => $a['lote'], 'cultivo' => $cultivo, 'metrica' => $mk],
            'link' => motor_link($campania, $a['lote'], $cultivo),
            'sugerencias' => $sug,
        ];
    }

    /* ── Mejor / peor lote ───────────────────────────────────────────────────
       Se ordena por margen POR HECTÁREA y no por margen total: si no, gana
       siempre el lote más grande y la respuesta no dice nada útil. */
    /* ── El alquiler contra lo que el lote deja ──────────────────────────────
       "¿Está acorde al potencial?" y "¿hasta cuánto puedo pagar?" son la misma
       cuenta: lo que la hectárea deja después del laboreo es el techo del
       alquiler, y contra ese techo se mide lo que se paga hoy.

       Va por lote y no por campaña. Un promedio entre un campo propio y uno
       alquilado no describe a ninguno, y acá el número se usa para negociar un
       contrato puntual. */
    if (motor_pide_alquiler_potencial($texto)) {
        $filas = [];
        foreach (motor_lotes_del_usuario($pdo, $usuarioId) as $l) {
            $s   = $ctrl->getGlobalStats($campania, (int)$l['id'], $cultivo);
            $ha  = (float)$s['hectareas'];
            $alq = (float)$s['costos_alquiler'];
            if ($ha <= 0 || $alq <= 0) continue;   // sin alquiler no hay nada que medir
            $ing = (float)$s['ingresos']       / $ha;
            $lab = (float)$s['costos_directos'] / $ha;
            $filas[] = [
                'nombre' => $l['nombre'],
                'ha'     => $ha,
                'alq'    => $alq / $ha,
                'ing'    => $ing,
                'lab'    => $lab,
                'techo'  => $ing - $lab,          // lo que queda para el alquiler
                'kg'     => (float)$s['kg'] / $ha,
            ];
        }

        if (!$filas) {
            return [
                'ok' => false, 'tipo' => 'sin_datos',
                'respuesta' => 'No tengo alquileres cargados en ' . $campania . ' para comparar.',
                'detalle' => 'Hace falta el pago del alquiler y la producción del lote: sin lo que '
                           . 'la hectárea deja no hay contra qué medirlo.',
                'valor' => null,
                'filtros' => ['ciclo' => $campania, 'lote' => $loteId, 'cultivo' => $cultivo, 'metrica' => 'costos_alquiler'],
                'link' => 'alquileres.php', 'sugerencias' => [],
            ];
        }

        /* El alquiler se pacta en quintales, así que además de pesos se dice a
           cuántos kilos de grano equivale. Es el idioma en que se negocia. */
        $ref = null;
        if ($cultivo) $ref = motor_precio_referencia($pdo, ucfirst(motor_normalizar(explode(' ', trim($cultivo))[0])));
        if ($ref === null) {
            foreach ($ctrl->getCultivosDelCiclo($campania) as $e) {
                $ref = motor_precio_referencia($pdo, ucfirst(motor_normalizar(explode(' ', trim($e))[0])));
                if ($ref !== null) break;
            }
        }
        $precioKg = $ref ? motor_a_moneda_actual($pdo, $usuarioId, $ref['precio_kg']) : 0;

        $lineas = [];
        foreach ($filas as $f) {
            $usa = $f['techo'] > 0 ? $f['alq'] / $f['techo'] * 100 : null;
            $t = '• ' . $f['nombre'] . ': pagás ' . motor_formatear($f['alq'], 'dinero') . '/ha';
            if ($precioKg > 0) {
                $t .= ' (' . number_format($f['alq'] / $precioKg, 0, ',', '.') . ' kg de grano)';
            }
            $t .= '. La hectárea deja ' . motor_formatear($f['techo'], 'dinero')
                . ' después del laboreo, así que el techo es ése';
            $t .= $usa === null
                ? '. Con lo que produjo, el alquiler no se cubre.'
                : ' y estás usando el ' . number_format($usa, 0, ',', '.') . '%.';
            $lineas[] = $t;
        }

        // El encabezado habla del lote si se preguntó por uno; si no, del conjunto.
        $peor = $filas[0];
        foreach ($filas as $f) {
            $a = $f['techo'] > 0 ? $f['alq'] / $f['techo'] : PHP_INT_MAX;
            $b = $peor['techo'] > 0 ? $peor['alq'] / $peor['techo'] : PHP_INT_MAX;
            if ($a > $b) $peor = $f;
        }
        $pctPeor = $peor['techo'] > 0 ? $peor['alq'] / $peor['techo'] * 100 : null;

        /* Las dos preguntas comparten la cuenta pero no lo que quieren escuchar:
           "¿está acorde?" quiere saber cómo viene lo que ya paga, y "¿hasta
           cuánto?" quiere el techo. El encabezado sigue a la pregunta. */
        $quiereTecho = (bool)preg_match('/(hasta cuanto|hasta cuánto|cuanto puedo pagar|'
                                      . 'cuánto puedo pagar|cuanto podria|maximo|máximo|techo)/u', $texto);

        if (count($filas) === 1) {
            $cab = 'En ' . $peor['nombre'] . ' podrías pagar hasta '
                 . motor_formatear($peor['techo'], 'dinero') . '/ha antes de perder plata, '
                 . 'y pagás ' . motor_formatear($peor['alq'], 'dinero') . '.';
        } elseif ($quiereTecho) {
            $techos = array_column($filas, 'techo');
            $tmin = min($techos); $tmax = max($techos);
            $cab = round($tmin) === round($tmax)
                ? 'Podrías pagar hasta ' . motor_formatear($tmax, 'dinero')
                  . '/ha antes de que el lote pierda plata.'
                : 'El techo va de ' . motor_formatear($tmin, 'dinero') . ' a '
                  . motor_formatear($tmax, 'dinero') . ' por hectárea, según el lote.';
        } else {
            $cab = 'El alquiler más ajustado es el de ' . $peor['nombre']
                 . ($pctPeor !== null ? ': se lleva el ' . number_format($pctPeor, 0, ',', '.')
                                      . '% de lo que deja la hectárea.' : '.');
        }

        return [
            'ok' => true, 'tipo' => 'alquiler_potencial',
            'respuesta' => $cab,
            'detalle' => implode("\n", $lineas)
                       . "\nEl techo es lo que produce la hectárea menos el laboreo: por encima "
                       . 'de eso el lote pierde. Cuánto por debajo conviene quedarse ya depende '
                       . 'de vos, no de un número.',
            'valor' => $peor['techo'],
            'filtros' => ['ciclo' => $campania, 'lote' => $loteId, 'cultivo' => $cultivo, 'metrica' => 'costos_alquiler'],
            'link' => 'alquileres.php',
            'sugerencias' => ['¿Qué porcentaje fue el alquiler?', '¿Cuánto gané por lote?', '¿Cuál es mi mejor lote?'],
        ];
    }

    /* ── Qué porcentaje representa el alquiler ───────────────────────────────
       "¿Qué porcentaje fue de alquiler?" devolvía el monto pagado: el número
       correcto de otra pregunta. Los desgloses por etapa y por rubro ya dan su
       porcentaje en el detalle, pero el alquiler no es una etapa —es un costo
       aparte— y quedaba sin puerta. */
    if (preg_match('/(porcentaje|por ciento|%|que parte|cuanta parte)/u', $texto)
        && preg_match('/(alquiler|alquileres|arrendamiento|renta)/u', $texto)) {
        $s     = $ctrl->getGlobalStats($campania, $loteId, $cultivo);
        $alq   = (float)$s['costos_alquiler'];
        $lab   = (float)$s['costos_directos'];
        $total = $alq + $lab;

        if ($total <= 0) {
            return [
                'ok' => false, 'tipo' => 'sin_datos',
                'respuesta' => 'Todavía no hay costos cargados en ' . $campania . '.',
                'detalle' => 'Con alquileres y labores registrados te digo cuánto pesa cada uno.',
                'valor' => null,
                'filtros' => ['ciclo' => $campania, 'lote' => $loteId, 'cultivo' => $cultivo, 'metrica' => 'costos_alquiler'],
                'link' => motor_link($campania, $loteId, $cultivo), 'sugerencias' => [],
            ];
        }

        $pct  = $alq / $total * 100;
        $ing  = (float)$s['ingresos'];
        $extra = $ing > 0
            ? ' Y ' . number_format($alq / $ing * 100, 1, ',', '.') . '% de tus ingresos.'
            : '';

        return [
            'ok' => true, 'tipo' => 'porcentaje',
            /* "De tus costos" era ambiguo: el desglose por etapa usa como base
               los costos de laboreo SOLOS, y acá la base incluye el alquiler.
               Dos porcentajes con distinta base tienen que decir cuál es. */
            'respuesta' => 'En ' . $campania . ', el alquiler fue el '
                         . number_format($pct, 1, ',', '.') . '% de tus costos totales.',
            'detalle' => motor_formatear($alq, 'dinero') . ' de alquiler sobre '
                       . motor_formatear($total, 'dinero') . ' de costo total ('
                       . motor_formatear($lab, 'dinero') . ' de laboreo).' . $extra,
            'valor' => $pct,
            'filtros' => ['ciclo' => $campania, 'lote' => $loteId, 'cultivo' => $cultivo, 'metrica' => 'costos_alquiler'],
            'link' => 'alquileres.php',
            'sugerencias' => ['¿Cuánto gané por lote?', '¿En qué gasté más?', '¿Cuál es mi mejor lote?'],
        ];
    }

    /* ── Insumos: cuánto usé, cuánto queda, a qué precio ─────────────────────
       Va antes del desglose por dimensión y de la métrica: "cuántos kg de
       semilla usé en cada lote" nombra un lote y un rubro, y sin esto lo
       atendería el desglose de plata devolviendo pesos donde se pidieron kilos. */
    $rubroTxt = motor_detectar_dimension($texto, motor_tipos_insumo());
    /* Nombrar una unidad junto a un rubro también es preguntar por la cantidad:
       "cuántos kg de semilla tiré" no lleva ninguno de los verbos de la lista,
       y sin esta puerta caía en la rama del gasto y devolvía pesos. */
    $pideCantidad = motor_pide_consumo($texto)
                 || (motor_pide_unidad($texto) && $rubroTxt !== null);

    if ($pideCantidad || motor_pide_stock($texto) || motor_pide_precio_insumo($texto)) {
        $rubro  = $rubroTxt;
        $unIns  = motor_insumo_nombrado($pdo, $usuarioId, $texto);
        $unidad = fn($u) => $u ? ' ' . $u : '';
        $cant   = fn($v) => number_format((float)$v, 2, ',', '.');

        // ── A qué precio se compró, que no es lo mismo que cuánto se gastó ───
        if (motor_pide_precio_insumo($texto)) {
            $filas = motor_precio_pagado_insumo($pdo, $usuarioId, $campania,
                                                $rubro['clave'] ?? null, $unIns['id'] ?? null,
                                                $loteId, $cultivo);
            if (!$filas) {
                return [
                    'ok' => false, 'tipo' => 'sin_datos',
                    'respuesta' => 'No tengo precios cargados de '
                                 . ($unIns['nombre'] ?? ($rubro['etiqueta'] ?? 'insumos'))
                                 . ' en ' . $campania . '.',
                    'detalle' => 'El precio sale de lo que se escribió al registrar cada compra, '
                               . 'así que sólo lo tengo de los insumos elegidos del catálogo.',
                    'valor' => null,
                    'filtros' => ['ciclo' => $campania, 'lote' => $loteId, 'cultivo' => $cultivo, 'metrica' => null],
                    'link' => 'insumos.php', 'sugerencias' => [],
                ];
            }
            $lineas = [];
            foreach ($filas as $f) {
                $prom = (float)$f['cantidad'] > 0 ? (float)$f['gastado'] / (float)$f['cantidad'] : 0;
                $t = '• ' . $f['insumo'] . ': ' . motor_formatear($prom, $prom < 10 ? 'dinero_fino' : 'dinero')
                   . ($f['unidad'] ? ' el ' . $f['unidad'] : '');
                // El rango sólo se dice si hay rango: repetirlo igual es ruido.
                if ((float)$f['minimo'] != (float)$f['maximo']) {
                    $t .= ' (entre ' . motor_formatear((float)$f['minimo'], 'dinero')
                        . ' y ' . motor_formatear((float)$f['maximo'], 'dinero')
                        . ' en ' . $f['compras'] . ' compras)';
                }
                $t .= ' — ' . $cant($f['cantidad']) . $unidad($f['unidad'])
                    . ' por ' . motor_formatear((float)$f['gastado'], 'dinero');
                $lineas[] = $t;
            }
            $unaSola = count($filas) === 1;
            $pr0 = (float)$filas[0]['cantidad'] > 0
                 ? (float)$filas[0]['gastado'] / (float)$filas[0]['cantidad'] : 0;
            return [
                'ok' => true, 'tipo' => 'precio_insumo',
                'respuesta' => $unaSola
                    ? 'Pagaste ' . motor_formatear($pr0, $pr0 < 10 ? 'dinero_fino' : 'dinero')
                      . ($filas[0]['unidad'] ? ' el ' . $filas[0]['unidad'] : '')
                      . ' de ' . $filas[0]['insumo'] . ' en ' . $campania . '.'
                    : 'Lo que pagaste por unidad en ' . $campania . ':',
                'detalle' => implode("\n", $lineas)
                           . "\nEs el promedio ponderado por cantidad de lo que escribiste al "
                           . 'registrar cada compra, no el precio de referencia del catálogo.',
                'valor' => $pr0,
                'filtros' => ['ciclo' => $campania, 'lote' => $loteId, 'cultivo' => $cultivo, 'metrica' => null],
                'link' => 'insumos.php',
                'sugerencias' => ['¿Cuántos kg de semilla usé?', '¿Qué tengo en stock?', '¿En qué gasté más?'],
            ];
        }

        // ── Consumo: cuánto se aplicó, abierto por lote ──────────────────────
        if ($pideCantidad) {
            $filas = motor_consumo_por_lote($pdo, $usuarioId, $campania,
                                            $rubro['clave'] ?? null, $unIns['id'] ?? null,
                                            $loteId, $cultivo);
            if (!$filas) {
                $qué = $unIns ? $unIns['nombre'] : ($rubro ? $rubro['etiqueta'] : 'insumos del catálogo');
                return [
                    'ok' => false, 'tipo' => 'sin_datos',
                    'respuesta' => 'No tengo consumo de ' . $qué . ' cargado en ' . $campania . '.',
                    'detalle' => 'Sólo puedo contarlo cuando el insumo se elige del catálogo: '
                               . 'los que se escriben a mano no descuentan stock ni quedan asociados a una ficha.',
                    'valor' => null,
                    'filtros' => ['ciclo' => $campania, 'lote' => $loteId, 'cultivo' => $cultivo, 'metrica' => null],
                    'link' => 'insumos.php', 'sugerencias' => [],
                ];
            }

            /* La superficie de cada lote, para poder decir la dosis además del
               total: un insumo se piensa en kg por hectárea, y "12.000 kg" no
               dice nada si no se sabe sobre cuántas hectáreas fueron. */
            $sup = [];
            foreach (motor_lotes_del_usuario($pdo, $usuarioId) as $l) {
                $sup[$l['nombre']] = (float)$l['superficie'];
            }

            $porLote = [];
            $total   = 0.0; $uni = null;
            foreach ($filas as $f) {
                $t = $f['insumo'] . ' ' . $cant($f['cantidad']) . $unidad($f['unidad']);
                $ha = $sup[$f['lote']] ?? 0;
                if ($ha > 0) {
                    $t .= ' (' . $cant((float)$f['cantidad'] / $ha) . $unidad($f['unidad']) . '/ha)';
                }
                $porLote[$f['lote']][] = $t;
                $total += (float)$f['cantidad'];
                $uni = $uni ?? $f['unidad'];
            }
            $lineas = [];
            foreach ($porLote as $lote => $items) {
                $lineas[] = '• ' . $lote . ': ' . implode(' · ', $items);
            }

            $qué  = $unIns ? $unIns['nombre'] : ($rubro ? $rubro['etiqueta'] : 'insumos');
            $unico = count($filas) === 1 || $unIns !== null;
            return [
                'ok' => true, 'tipo' => 'consumo_insumo',
                'respuesta' => 'En ' . $campania . ' usaste ' . $cant($total) . $unidad($uni)
                             . ' de ' . $qué . ($unico ? '.' : ', repartidos así:'),
                'detalle' => implode("\n", $lineas)
                           . "\nEs lo aplicado según las operaciones cargadas, con la misma cuenta "
                           . 'con la que se descuenta el stock.',
                'valor' => $total,
                'filtros' => ['ciclo' => $campania, 'lote' => $loteId, 'cultivo' => $cultivo, 'metrica' => null],
                'link' => 'insumos.php',
                'sugerencias' => ['¿Qué tengo en stock?', '¿Cuánto gasté en semillas?', '¿En qué gasté más?'],
            ];
        }

        // ── Stock y precios del catálogo ─────────────────────────────────────
        $items = motor_stock_insumos($pdo, $usuarioId, $rubro['clave'] ?? null, $unIns['id'] ?? null);
        if (!$items) {
            return [
                'ok' => false, 'tipo' => 'sin_datos',
                'respuesta' => $rubro || $unIns
                    ? 'No tengo ' . ($unIns['nombre'] ?? $rubro['etiqueta']) . ' en el catálogo.'
                    : 'Todavía no tenés insumos cargados en el catálogo.',
                'detalle' => 'Se cargan desde Insumos, con su stock, su precio y su vencimiento.',
                'valor' => null,
                'filtros' => ['ciclo' => $campania, 'lote' => $loteId, 'cultivo' => $cultivo, 'metrica' => null],
                'link' => 'insumos.php', 'sugerencias' => [],
            ];
        }

        /* El precio del catálogo está en dólares por definición: la columna se
           llama precio_estimado_usd. Se convierte para mostrarlo en la moneda
           que se esté mirando, igual que todo lo demás. */
        /* Un solo formato para toda la lista, elegido por el precio más chico:
           mezclar $12,00 con $6,5000 en renglones consecutivos se lee como si
           fueran magnitudes distintas cuando son la misma. */
        /* precio_estimado_usd está en dólares: va la conversión DESDE dólares.
           Con la otra el catálogo mostraba un dólar como si fuera un peso. */
        $precios = array_map(fn($i) => motor_desde_usd($pdo, $usuarioId, (float)$i['precio']), $items);
        $menor   = min(array_filter($precios) ?: [1]);
        $fmtP    = $menor < 10 ? 'dinero_fino' : 'dinero';

        $lineas = []; $bajos = []; $vencidos = []; $conVenc = [];
        $hoy = date('Y-m-d');
        foreach ($items as $n => $i) {
            $pr = $precios[$n];
            if ($i['fecha_vencimiento']) $conVenc[] = $n;
            $t  = '• ' . $i['nombre'] . ': ' . $cant($i['stock_actual']) . $unidad($i['unidad']);
            if ($pr > 0) {
                $t .= ' a ' . motor_formatear($pr, $fmtP)
                    . ($i['unidad'] ? ' el ' . $i['unidad'] : '');
                // Lo que hay en el galpón, valuado: es la pregunta que sigue.
                $t .= ' (' . motor_formatear($pr * (float)$i['stock_actual'], 'dinero') . ' en total)';
            }
            if ($i['deposito']) $t .= ' — ' . $i['deposito'];
            $lineas[] = $t;

            if ($i['stock_minimo'] !== null && (float)$i['stock_actual'] < (float)$i['stock_minimo']) {
                $bajos[] = $i['nombre'];
            }
            if ($i['fecha_vencimiento'] && $i['fecha_vencimiento'] < $hoy) {
                $vencidos[] = $i['nombre'] . ' (venció el ' . date('d/m/Y', strtotime($i['fecha_vencimiento'])) . ')';
            }
        }

        $valor = 0.0;
        foreach ($items as $n2 => $i) {
            // Se reusan los precios ya convertidos: recalcularlos era la puerta
            // por la que se coló la conversión al revés.
            $valor += $precios[$n2] * (float)$i['stock_actual'];
        }

        /* Si preguntó por vencimientos, la lista se recorta a los insumos que
           tienen fecha: los demás no son parte de esa pregunta y sólo la tapan. */
        $porVence = preg_match('/vencimiento|vencen|vence|por vencer|vencid/u', $texto);
        if ($porVence && $conVenc) {
            $lineas = array_values(array_intersect_key($lineas, array_flip($conVenc)));
        }

        $cierre = [];
        // Con la pregunta puesta en los vencimientos, el encabezado ya los nombra.
        if ($vencidos && !$porVence) $cierre[] = 'Ojo que ya venció: ' . implode(', ', $vencidos) . '.';
        if ($bajos)    $cierre[] = 'Por debajo del mínimo que fijaste: ' . implode(', ', $bajos) . '.';
        if (motor_dolar_estimado($pdo, $usuarioId)) {
            $cierre[] = 'No tenés cotización del dólar cargada para este mes, '
                      . 'así que el pasaje a dólares es aproximado.';
        }

        /* Si preguntó puntualmente por vencimientos, la respuesta arranca por
           ahí. Listarle todo el catálogo y esconder el vencido en la última
           línea es no contestar lo que preguntó. */
        if ($porVence) {
            $encabezado = $vencidos
                ? 'Tenés vencido: ' . implode(', ', $vencidos) . '.'
                : 'No tenés nada vencido en el catálogo.';
        } elseif (count($items) === 1) {
            $encabezado = 'Tenés ' . $cant($items[0]['stock_actual']) . $unidad($items[0]['unidad'])
                        . ' de ' . $items[0]['nombre'] . '.';
        } else {
            $encabezado = 'Tenés ' . count($items) . ' insumos en el catálogo, '
                        . motor_formatear($valor, 'dinero') . ' en total.';
        }

        return [
            'ok' => true, 'tipo' => 'stock_insumos',
            'respuesta' => $encabezado,
            'detalle' => implode("\n", $lineas) . ($cierre ? "\n" . implode(' ', $cierre) : ''),
            'valor' => $valor,
            'filtros' => ['ciclo' => $campania, 'lote' => $loteId, 'cultivo' => $cultivo, 'metrica' => null],
            'link' => 'insumos.php',
            'sugerencias' => ['¿Cuántos kg de semilla usé en cada lote?', '¿Qué está por vencer?', '¿En qué gasté más?'],
        ];
    }

    /* ── Resultado abierto por lote o por cultivo ────────────────────────────
       "¿cuánto gané por lote?", "¿cómo me fue en cada cultivo?". Va antes de
       los rankings porque es la lista completa y no la elección de un ganador,
       y antes de la métrica genérica porque si no devolvería un solo total. */
    $dim = motor_pide_desglose_dimension($texto);
    if ($dim !== null) {
        // La métrica que nombre; si no nombra ninguna, el margen, que es lo que
        // se quiere saber cuando se pregunta "cómo me fue".
        $metClave = motor_detectar_metrica($texto) ?? 'margen_neto';
        $cat      = motor_metricas()[$metClave] ?? motor_metricas()['margen_neto'];

        $items = $dim === 'lote'
            ? array_map(fn($l) => ['nombre' => $l['nombre'], 'id' => (int)$l['id']],
                        $ctrl->getLotesDelCiclo($campania))
            : array_map(fn($c) => ['nombre' => $c, 'id' => null],
                        $ctrl->getCultivosDelCiclo($campania));

        $filas = [];
        foreach ($items as $it) {
            $s = $dim === 'lote'
                ? $ctrl->getGlobalStats($campania, $it['id'], $cultivo)
                : $ctrl->getGlobalStats($campania, $loteId, $it['nombre']);
            $ha  = (float)($s['hectareas'] ?? 0);
            $val = (float)($s[$metClave] ?? 0);
            // Sin superficie ni valor no hay nada que mostrar; la fila sólo hace ruido.
            if ($ha <= 0 && $val == 0.0) continue;
            $filas[] = ['nombre' => $it['nombre'], 'id' => $it['id'],
                        'val' => $val, 'ha' => $ha,
                        'porHa' => $ha > 0 ? $val / $ha : null];
        }

        if (!$filas) {
            return [
                'ok' => false, 'tipo' => 'sin_datos',
                'respuesta' => 'Todavía no hay nada cargado en ' . $campania
                             . ' para abrirlo por ' . $dim . '.',
                'detalle' => 'Con operaciones y ventas registradas te lo puedo desglosar.',
                'valor' => null,
                'filtros' => ['ciclo' => $campania, 'lote' => $loteId, 'cultivo' => $cultivo, 'metrica' => $metClave],
                'link' => motor_link($campania, $loteId, $cultivo), 'sugerencias' => [],
            ];
        }

        // De mayor a menor: lo que interesa primero, sin tener que buscarlo.
        usort($filas, fn($a, $b) => $b['val'] <=> $a['val']);
        $total = array_sum(array_column($filas, 'val'));

        /* El por hectárea va al lado del total porque sin él la lista engaña:
           un lote grande siempre parece mejor que uno chico. */
        $lineas = array_map(function ($f) use ($cat) {
            $t = '• ' . $f['nombre'] . ': ' . motor_formatear($f['val'], $cat['formato']);
            if ($f['porHa'] !== null) {
                $t .= ' (' . motor_formatear($f['porHa'], $cat['formato']) . '/ha'
                    . ' sobre ' . motor_formatear($f['ha'], 'ha') . ')';
            }
            return $t;
        }, $filas);

        $etq = $dim === 'lote' ? 'lote' : 'cultivo';
        return [
            'ok' => true, 'tipo' => 'desglose_dimension',
            'respuesta' => 'En ' . $campania . ', ' . $cat['etiqueta'] . ' por ' . $etq
                         . ': ' . motor_formatear($total, $cat['formato']) . ' en total.',
            'detalle' => implode("\n", $lineas),
            'valor' => $total,
            'filtros' => ['ciclo' => $campania, 'lote' => $loteId, 'cultivo' => $cultivo, 'metrica' => $metClave],
            'link' => motor_link($campania, $loteId, $cultivo),
            'sugerencias' => $dim === 'lote'
                ? ['¿Cuál es mi mejor lote?', '¿Cuánto gané por cultivo?', '¿En qué gasté más?']
                : ['¿Cuánto gané por lote?', '¿Cuál es mi mejor lote?', '¿En qué gasté más?'],
        ];
    }

    $orden = motor_pide_ranking_lotes($texto);
    if ($orden !== null) {
        if (count($lotes) < 2) {
            return [
                'ok' => false, 'tipo' => 'sin_datos',
                'respuesta' => 'Necesito al menos dos lotes en ' . $campania . ' para poder compararlos.',
                'detalle' => '', 'valor' => null,
                'filtros' => ['ciclo' => $campania, 'lote' => null, 'cultivo' => $cultivo, 'metrica' => 'margen_neto'],
                'link' => motor_link($campania, null, $cultivo), 'sugerencias' => [],
            ];
        }
        $filas = [];
        foreach ($lotes as $l) {
            $s = $ctrl->getGlobalStats($campania, (int)$l['id'], $cultivo);
            $ha = (float)$s['hectareas'];
            if ($ha <= 0) continue;
            $filas[] = ['nombre' => $l['nombre'], 'id' => (int)$l['id'],
                        'mha' => (float)$s['margen_neto'] / $ha];
        }
        if (!$filas) {
            return [
                'ok' => false, 'tipo' => 'sin_datos',
                'respuesta' => 'Todavía no hay datos suficientes para comparar los lotes de ' . $campania . '.',
                'detalle' => '', 'valor' => null,
                'filtros' => ['ciclo' => $campania, 'lote' => null, 'cultivo' => $cultivo, 'metrica' => 'margen_neto'],
                'link' => motor_link($campania, null, $cultivo), 'sugerencias' => [],
            ];
        }
        usort($filas, fn($a, $b) => $orden === 'mejor' ? $b['mha'] <=> $a['mha'] : $a['mha'] <=> $b['mha']);
        $top = $filas[0];
        $lista = array_map(fn($f) => $f['nombre'] . ' ' . motor_formatear($f['mha'], 'dinero') . '/ha',
                           array_slice($filas, 0, 3));
        return [
            'ok' => true, 'tipo' => 'ranking_lotes',
            'respuesta' => 'Tu ' . ($orden === 'mejor' ? 'mejor' : 'peor') . ' lote en ' . $campania
                         . ' es ' . $top['nombre'] . ': ' . motor_formatear($top['mha'], 'dinero') . ' de margen por hectárea.',
            'detalle' => 'Ordenados por margen por hectárea: ' . implode(' · ', $lista) . '.',
            'valor' => $top['mha'],
            'filtros' => ['ciclo' => $campania, 'lote' => $top['id'], 'cultivo' => $cultivo, 'metrica' => 'margen_neto'],
            'link' => motor_link($campania, $top['id'], $cultivo),
            'sugerencias' => ['¿En qué gasté más?', '¿Y el costo por hectárea?', '¿Cuál es mi peor lote?'],
        ];
    }

    /* ── Cualquier métrica de un período ─────────────────────────────────────
       "¿cuánto gasté en agosto?", "¿cuánto vendí en agosto?", "el rinde de mayo".
       Ya no es un caso especial que sólo sabe sumar costos: el período es un
       filtro más y lo resuelve el mismo getGlobalStats que usa el panel, así que
       las 10 métricas quedan disponibles por fecha sin escribir una consulta
       nueva por cada una.

       Si la pregunta no nombra campaña, se mira sólo por fecha: una campaña
       cruza dos años calendario y "agosto" no es "agosto de la 25/26". */
    $rango = motor_detectar_mes($pdo, $usuarioId, $texto);
    if ($rango !== null) {
        $mk  = $metrica ?? ($contexto['metrica'] ?? 'costos_directos');
        $cm  = motor_metricas()[$mk] ?? motor_metricas()['costos_directos'];
        $cicloExplicito = motor_detectar_campania($texto, $ciclos);
        $st  = $ctrl->getGlobalStats($cicloExplicito, $loteId, $cultivo, $rango);
        $val = (float)($st[$mk] ?? 0);

        $hayAlgo = ((float)$st['costos_directos'] !== 0.0 || (float)$st['ingresos'] !== 0.0
                    || (float)$st['kg'] !== 0.0 || (float)$st['costos_alquiler'] !== 0.0);

        if (!$hayAlgo) {
            return [
                'ok' => false, 'tipo' => 'sin_datos',
                'respuesta' => 'No hay movimientos registrados en ' . $rango['etiqueta'] . '.',
                'detalle' => 'Puede que no se haya cargado nada de ese mes.',
                'valor' => null,
                'filtros' => ['ciclo' => $campania, 'lote' => $loteId, 'cultivo' => $cultivo, 'metrica' => $mk],
                'link' => motor_link($campania, $loteId, $cultivo),
                'sugerencias' => motor_sugerencias_periodo(),
            ];
        }

        return [
            'ok' => true, 'tipo' => 'periodo',
            'respuesta' => 'En ' . $rango['etiqueta'] . ($lote ? ', en ' . $lote['nombre'] : '')
                         . ', ' . $cm['etiqueta'] . ': ' . motor_formatear($val, $cm['formato']) . '.',
            'detalle' => 'Se cuenta por fecha del movimiento, así que puede cruzar dos campañas.',
            'valor' => $val,
            'filtros' => ['ciclo' => $campania, 'lote' => $loteId, 'cultivo' => $cultivo, 'metrica' => $mk],
            'link' => motor_link($campania, $loteId, $cultivo),
            'sugerencias' => motor_sugerencias_periodo(),
        ];
    }

    /* ── Gasto por dimensión ─────────────────────────────────────────────────
       "¿Cuánto gasté en siembra?", "¿en semillas?", "¿cuánto le pagué a Ponso?"
       Se resuelve antes que la métrica genérica: cuando la pregunta nombra una
       etapa, un tipo de insumo o un proveedor, el productor quiere ESE recorte,
       no el total de laboreo. */
    $grupo   = motor_detectar_dimension($texto, motor_grupos());
    /* El rubro del insumo se busca ANTES que el tipo de componente: "semillas" es
       más específico que "insumos", y quien pregunta por semillas quiere las
       semillas, no todos los insumos. */
    $rubro   = $grupo ? null : motor_detectar_dimension($texto, motor_tipos_insumo());
    $comp    = ($grupo || $rubro) ? null : motor_detectar_dimension($texto, motor_componentes());

    $prov = null;
    if (!$grupo && !$rubro && !$comp) {
        foreach (motor_proveedores($pdo, $usuarioId, $campania) as $p) {
            if ($p !== '' && motor_coincide($texto, motor_normalizar($p))) {
                $prov = $p; break;
            }
        }
    }

    if ($grupo || $rubro || $comp || $prov) {
        $extra = null;
        if ($grupo)      { $extra = ['col' => 'grupo_gasto',     'val'  => $grupo['clave']]; $qué = 'en ' . $grupo['etiqueta']; }
        elseif ($rubro)  {                                                                   $qué = 'en ' . $rubro['etiqueta']; }
        elseif ($comp)   { $extra = ['col' => 'tipo_componente', 'vals' => motor_componentes()[$comp['clave']]['db']];
                                                                                             $qué = 'en ' . $comp['etiqueta']; }
        else             { $extra = ['col' => 'proveedor_servicio', 'val' => $prov];          $qué = 'con ' . $prov; }

        /* El rubro no se filtra sobre la operación sino sobre sus renglones, así
           que va por su propia consulta. */
        $monto = $rubro
            ? motor_gasto_por_insumo($pdo, $usuarioId, $campania, $loteId, $cultivo, $rubro['clave'])
            : motor_gasto($pdo, $usuarioId, $campania, $loteId, $cultivo, $extra);
        $total = motor_gasto($pdo, $usuarioId, $campania, $loteId, $cultivo, null);
        $pct   = $total > 0 ? ($monto / $total) * 100 : 0;

        if ($monto <= 0) {
            return [
                'ok' => false, 'tipo' => 'sin_datos',
                'respuesta' => 'No hay gastos registrados ' . $qué . ' en '
                             . motor_frase_filtros($campania, $lote, $cultivo) . '.',
                /* Con los rubros hay una segunda explicación posible, y callarla
                   haría creer que no se compró nada: el rubro sale del catálogo de
                   Insumos, así que un insumo escrito a mano en la operación (la
                   opción "Ingresar Texto Manual") no tiene rubro y no se cuenta. */
                'detalle' => $rubro
                    ? 'Puede ser que no se haya cargado, o que esos insumos se hayan escrito a mano en la operación: los que no salen del catálogo de Insumos no tienen rubro.'
                    : 'Puede ser que todavía no se haya cargado esa operación.',
                'valor' => null,
                'filtros' => ['ciclo' => $campania, 'lote' => $loteId, 'cultivo' => $cultivo, 'metrica' => 'costos_directos'],
                'link' => motor_link($campania, $loteId, $cultivo),
                'sugerencias' => motor_sugerencias_gasto(),
            ];
        }

        return [
            'ok' => true, 'tipo' => 'gasto',
            'respuesta' => 'En ' . motor_frase_filtros($campania, $lote, $cultivo) . ' gastaste '
                         . motor_formatear($monto, 'dinero') . ' ' . $qué . '.',
            'detalle' => 'Es el ' . number_format($pct, 1, ',', '.') . '% de los '
                       . motor_formatear($total, 'dinero') . ' de costos de laboreo.',
            'valor' => $monto,
            'filtros' => ['ciclo' => $campania, 'lote' => $loteId, 'cultivo' => $cultivo, 'metrica' => 'costos_directos'],
            'link' => motor_link($campania, $loteId, $cultivo),
            'sugerencias' => motor_sugerencias_gasto(),
        ];
    }

    /* Preguntó por una etapa que no existe ("gasté en riego"). Caer al total de
       laboreo sería contestar con seguridad algo que nadie preguntó: el productor
       se lleva $31.000 creyendo que gastó eso en riego. Mejor decir que no está. */
    if (preg_match('/\bgast\w*\s+en\s+(?:el\s+|la\s+|los\s+|las\s+)?([a-z]{4,})/u', $texto, $m)) {
        $palabra = $m[1];
        $neutras = ['total','general','campania','campana','lote','cultivo','todo','esta','este','mi','ese'];
        $es_entidad = ($lote && strpos(motor_normalizar($lote['nombre']), $palabra) !== false)
                   || ($cultivo && strpos(motor_normalizar($cultivo), $palabra) !== false);
        if (!in_array($palabra, $neutras, true) && !$es_entidad) {
            return [
                'ok' => false, 'tipo' => 'sin_entender',
                'respuesta' => 'No tengo "' . $palabra . '" como categoría de gasto.',
                'detalle' => 'Puedo desglosar por ' . motor_frase_categorias() . ', o por proveedor.',
                'valor' => null,
                'filtros' => ['ciclo' => $campania, 'lote' => $loteId, 'cultivo' => $cultivo, 'metrica' => null],
                'link' => motor_link($campania, $loteId, $cultivo),
                'sugerencias' => motor_sugerencias_gasto(),
            ];
        }
    }

    /* ── Ranking: "¿en qué gasté más?" ─────────────────────────────────────── */
    if (motor_pide_ranking($texto)) {

        /* Por lote, cuando la pregunta lo nombra. Es la versión que más se usa
           en la práctica: el productor no compara etapas, compara campos. */
        if (motor_ranking_por_lote($texto, $loteId)) {
            $filas = motor_ranking_lotes_gasto($pdo, $usuarioId, $campania, $cultivo);
            if (!$filas) {
                return [
                    'ok' => false, 'tipo' => 'sin_datos',
                    'respuesta' => 'Todavía no hay gastos cargados en ' . motor_frase_filtros($campania, null, $cultivo) . '.',
                    'detalle' => '', 'valor' => null,
                    'filtros' => ['ciclo' => $campania, 'lote' => null, 'cultivo' => $cultivo, 'metrica' => 'costos_directos'],
                    'link' => motor_link($campania, null, $cultivo),
                    'sugerencias' => motor_sugerencias_gasto(),
                ];
            }
            $tot = array_sum(array_column($filas, 'total'));
            $lineas = [];
            foreach (array_slice($filas, 0, 4) as $f) {
                $pct = $tot > 0 ? ((float)$f['total'] / $tot) * 100 : 0;
                $lineas[] = $f['n'] . ' ' . motor_formatear($f['total'], 'dinero')
                          . ' (' . number_format($pct, 0, ',', '.') . '%)';
            }
            /* Con un solo lote el ranking no es un ranking: se dice el número y
               listo, en vez de fingir una comparación que no existe. */
            $detalle = count($filas) > 1
                ? 'El desglose: ' . implode(' · ', $lineas) . '.'
                : 'Es el único lote con gastos cargados en esta campaña.';
            return [
                'ok' => true, 'tipo' => 'ranking_lotes',
                'respuesta' => 'El lote donde más gastaste fue ' . $filas[0]['n'] . ': '
                             . motor_formatear($filas[0]['total'], 'dinero') . '.',
                'detalle' => $detalle,
                'valor' => (float)$filas[0]['total'],
                'filtros' => ['ciclo' => $campania, 'lote' => null, 'cultivo' => $cultivo, 'metrica' => 'costos_directos'],
                'link' => motor_link($campania, null, $cultivo),
                'sugerencias' => motor_sugerencias_gasto(),
            ];
        }

        $filas = motor_ranking_grupos($pdo, $usuarioId, $campania, $loteId, $cultivo);
        if (!$filas) {
            return [
                'ok' => false, 'tipo' => 'sin_datos',
                'respuesta' => 'Todavía no hay gastos cargados en ' . motor_frase_filtros($campania, $lote, $cultivo) . '.',
                'detalle' => '', 'valor' => null,
                'filtros' => ['ciclo' => $campania, 'lote' => $loteId, 'cultivo' => $cultivo, 'metrica' => 'costos_directos'],
                'link' => motor_link($campania, $loteId, $cultivo),
                'sugerencias' => motor_sugerencias_gasto(),
            ];
        }
        $cat = motor_grupos();
        $tot = array_sum(array_column($filas, 'total'));
        $lineas = [];
        foreach (array_slice($filas, 0, 3) as $f) {
            $et  = $cat[$f['g']]['etiqueta'] ?? $f['g'];
            $pct = $tot > 0 ? ((float)$f['total'] / $tot) * 100 : 0;
            $lineas[] = $et . ' ' . motor_formatear($f['total'], 'dinero')
                      . ' (' . number_format($pct, 0, ',', '.') . '%)';
        }
        $primero = $cat[$filas[0]['g']]['etiqueta'] ?? $filas[0]['g'];
        return [
            'ok' => true, 'tipo' => 'ranking',
            'respuesta' => 'Donde más gastaste fue en ' . $primero . ': '
                         . motor_formatear($filas[0]['total'], 'dinero') . '.',
            'detalle' => 'El desglose: ' . implode(' · ', $lineas) . '.',
            'valor' => (float)$filas[0]['total'],
            'filtros' => ['ciclo' => $campania, 'lote' => $loteId, 'cultivo' => $cultivo, 'metrica' => 'costos_directos'],
            'link' => motor_link($campania, $loteId, $cultivo),
            'sugerencias' => motor_sugerencias_gasto(),
        ];
    }

    if ($metrica === null) {
        return motor_sin_entender_cerca($ctrl, $texto, $nombre);
    }

    $cat = motor_metricas()[$metrica];

    // "¿Qué es el rinde de indiferencia?" — se explica Y se da el número propio.
    // Enseñar con el dato del productor adentro vale mucho más que una definición suelta.
    if (motor_pide_definicion($texto)) {
        $stats = $ctrl->getGlobalStats($campania, $lote['id'] ?? null, $cultivo);
        $valor = $stats[$metrica] ?? null;
        return [
            'ok' => true, 'tipo' => 'definicion',
            'respuesta' => ucfirst($cat['etiqueta']) . ': ' . $cat['explicacion'],
            'detalle' => $valor !== null
                ? 'En tu campaña ' . $campania . ' es ' . motor_formatear($valor, $cat['formato']) . '.'
                : '',
            'valor' => $valor,
            'filtros' => ['ciclo' => $campania, 'lote' => $lote['id'] ?? null, 'cultivo' => $cultivo, 'metrica' => $metrica],
            'link' => motor_link($campania, $lote['id'] ?? null, $cultivo),
            'sugerencias' => motor_sugerencias($metrica, $campania, $lote),
        ];
    }

    $stats = $ctrl->getGlobalStats($campania, $lote['id'] ?? null, $cultivo);
    if (!array_key_exists($metrica, $stats)) {
        return motor_sin_entender($ctrl, 'Esa métrica todavía no la sé calcular.');
    }
    $valor = $stats[$metrica];

    // Sin superficie ni kilos no hay nada real que informar: decirlo es más útil
    // que devolver un $0 que se lee como si fuera el resultado.
    $vacio = ((float)$stats['hectareas'] === 0.0 && (float)$stats['kg'] === 0.0 && (float)$stats['ingresos'] === 0.0);
    if ($vacio) {
        return [
            'ok' => false, 'tipo' => 'sin_datos',
            'respuesta' => 'No hay datos cargados para ' . motor_frase_filtros($campania, $lote, $cultivo) . '.',
            'detalle' => 'Registrá operaciones o producción y te lo calculo.',
            'valor' => null,
            'filtros' => ['ciclo' => $campania, 'lote' => $lote['id'] ?? null, 'cultivo' => $cultivo, 'metrica' => $metrica],
            'link' => motor_link($campania, $lote['id'] ?? null, $cultivo),
            'sugerencias' => motor_sugerencias($metrica, $campania, $lote),
        ];
    }

    // Honestidad del dato: varias métricas son un cociente, y cuando el divisor
    // es cero el controlador devuelve 0. Ese 0 NO es la respuesta —es la falta de
    // un dato— y presentarlo como si fuera el resultado es peor que no contestar.
    $requiere = [
        'costo_por_ha'            => ['hectareas', 'superficie registrada'],
        'rendimiento_ha'          => ['hectareas', 'superficie registrada'],
        'costo_por_kg'            => ['kg', 'kilos producidos'],
        'punto_equilibrio_kg_ha'  => ['kg', 'producción registrada'],
    ];
    if (isset($requiere[$metrica]) && (float)$stats[$requiere[$metrica][0]] === 0.0) {
        return [
            'ok' => false, 'tipo' => 'sin_datos',
            'respuesta' => 'Todavía no puedo calcular el ' . $cat['etiqueta'] . ' de '
                         . motor_frase_filtros($campania, $lote, $cultivo) . '.',
            'detalle' => 'Hace falta ' . $requiere[$metrica][1] . ' para ese cálculo.',
            'valor' => null,
            'filtros' => ['ciclo' => $campania, 'lote' => $lote['id'] ?? null, 'cultivo' => $cultivo, 'metrica' => $metrica],
            'link' => motor_link($campania, $lote['id'] ?? null, $cultivo),
            'sugerencias' => motor_sugerencias($metrica, $campania, $lote),
        ];
    }

    $respuesta = 'En ' . motor_frase_filtros($campania, $lote, $cultivo) . ', '
               . $cat['etiqueta'] . ': ' . motor_formatear($valor, $cat['formato']) . '.';

    return [
        'ok' => true, 'tipo' => 'metrica',
        'respuesta' => $respuesta,
        'detalle' => motor_contexto($metrica, $stats),
        'valor' => $valor,
        'filtros' => ['ciclo' => $campania, 'lote' => $lote['id'] ?? null, 'cultivo' => $cultivo, 'metrica' => $metrica],
        'link' => motor_link($campania, $lote['id'] ?? null, $cultivo),
        'sugerencias' => motor_sugerencias($metrica, $campania, $lote),
    ];
}

/** "la campaña 26/27, en el Lote 1, en trigo" */
function motor_frase_filtros(string $campania, ?array $lote, ?string $cultivo): string {
    $p = 'la campaña ' . $campania;
    if ($lote) {
        // Sin artículo si el nombre ya trae uno: "en el La rubia" no es castellano.
        $primera = mb_strtolower(explode(' ', trim($lote['nombre']))[0], 'UTF-8');
        $trae_articulo = in_array($primera, ['el','la','los','las'], true);
        $p .= ', en ' . ($trae_articulo ? '' : 'el ') . $lote['nombre'];
    }
    if ($cultivo) $p .= ', en ' . mb_strtolower($cultivo, 'UTF-8');
    return $p;
}

/**
 * La línea de apoyo: un número sin referencia no dice nada. Sobre todo el rinde,
 * que sólo significa algo comparado con el de indiferencia.
 */
function motor_contexto(string $metrica, array $stats): string {
    $ha = (float)$stats['hectareas'];
    $kg = (float)$stats['kg'];

    if ($metrica === 'rendimiento_ha' && $stats['punto_equilibrio_kg_ha'] > 0) {
        $dif = $stats['rendimiento_ha'] - $stats['punto_equilibrio_kg_ha'];
        $sobre = $dif >= 0 ? 'por encima' : 'por debajo';
        return 'Estás ' . number_format(abs($dif), 0, ',', '.') . ' kg/ha ' . $sobre
             . ' del rinde de indiferencia (' . number_format($stats['punto_equilibrio_kg_ha'], 0, ',', '.') . ' kg/ha).';
    }
    if ($metrica === 'punto_equilibrio_kg_ha' && $stats['rendimiento_ha'] > 0) {
        $dif = $stats['rendimiento_ha'] - $stats['punto_equilibrio_kg_ha'];
        return $dif >= 0
            ? 'Tu rinde real lo supera por ' . number_format($dif, 0, ',', '.') . ' kg/ha.'
            : 'Tu rinde real está ' . number_format(abs($dif), 0, ',', '.') . ' kg/ha por debajo.';
    }
    if ($metrica === 'margen_neto') {
        return 'Sale de ' . motor_formatear($stats['ingresos'], 'dinero') . ' de ingresos menos '
             . motor_formatear($stats['costos_directos'], 'dinero') . ' de laboreo y '
             . motor_formatear($stats['costos_alquiler'], 'dinero') . ' de alquileres.';
    }
    $partes = [];
    if ($ha > 0) $partes[] = motor_formatear($ha, 'ha') . ' trabajadas';
    if ($kg > 0) $partes[] = motor_formatear($kg, 'kg') . ' producidos';
    return $partes ? 'Sobre ' . implode(' y ', $partes) . '.' : '';
}

/** Repreguntas: lo que uno querría saber justo después. */
function motor_sugerencias(string $metrica, string $campania, ?array $lote): array {
    $orden = ['margen_neto','costo_por_ha','rendimiento_ha','punto_equilibrio_kg_ha','ingresos','costos_directos'];
    $cat = motor_metricas();
    $out = [];
    foreach ($orden as $k) {
        if ($k === $metrica || !isset($cat[$k])) continue;
        $out[] = '¿Y el ' . $cat[$k]['etiqueta'] . ($lote ? ' en el ' . $lote['nombre'] : '') . '?';
        if (count($out) >= 3) break;
    }
    return $out;
}

/* =====================================================================
   ALTA DE GASTOS POR CHAT

   Es lo único del motor que TOCA los datos, y por eso funciona distinto a todo
   lo demás.

   La regla: el motor nunca escribe a partir de una interpretación. Interpreta,
   MUESTRA lo que entendió, y sólo escribe lo que el productor confirmó — y
   escribe los campos confirmados, no la frase original. Así el guardado no puede
   entender algo distinto de lo que se aprobó en pantalla.

   El motivo es que acá el error cambia de categoría. Si el motor lee mal una
   consulta, muestra un número equivocado: se ve y se corrige. Si escribe mal,
   deja un registro falso en la contabilidad, en silencio, y contamina todos los
   márgenes de ahí en adelante.

   El guardado va por POST (api/registrar.php): así hereda el token CSRF y el
   bloqueo de cuentas de demostración, que es una lista blanca sobre POST.
   ===================================================================== */

/** Monto en formato argentino: 15.000,50 · $15000 · 15.000 */
function motor_detectar_monto(string $texto): ?float {
    // Se pide el separador de miles o los decimales para no confundir un monto
    // con una cantidad ("100 litros") ni con un año.
    if (preg_match('/\$\s*([\d.]+(?:,\d+)?)/u', $texto, $m)
     || preg_match('/\b(\d{1,3}(?:\.\d{3})+(?:,\d+)?)\b/u', $texto, $m)
     || preg_match('/\b(\d+,\d{1,2})\b/u', $texto, $m)) {
        $n = str_replace('.', '', $m[1]);
        $n = str_replace(',', '.', $n);
        return (float)$n;
    }
    // Un número pelado de 4 cifras o más: alcanza para un gasto, no para una cantidad.
    if (preg_match('/\b(\d{4,})\b/u', $texto, $m)) {
        $v = (float)$m[1];
        if ($v >= 1000 && $v < 100000000) return $v;
    }
    return null;
}

/**
 * El texto listo para leerle una fecha: minúsculas y sin acentos, pero CON la
 * puntuación intacta.
 *
 * No sirve motor_normalizar() acá: borra la barra de "14/08" y el punto, que en
 * una fecha son el significado. Y no sirve el texto crudo tampoco: los botones del
 * chat mandan su etiqueta tal cual, así que "Hoy" con mayúscula no coincidía con
 * "hoy" y la pregunta volvía como si el productor no hubiera contestado nada.
 */
function motor_texto_fecha(string $texto): string {
    $t = mb_strtolower($texto, 'UTF-8');
    return strtr($t, ['á'=>'a','é'=>'e','í'=>'i','ó'=>'o','ú'=>'u','ü'=>'u','ñ'=>'n']);
}

/**
 * Fecha dicha en castellano, o null si no reconoció ninguna.
 *
 * Devuelve null y no "hoy" cuando no entiende: una fecha inventada se guarda igual
 * de bien que una dicha, y después no hay forma de distinguirlas. Quien llama
 * decide qué hacer con el null —el paso a paso vuelve a preguntar—.
 *
 * Nunca devuelve una fecha que no existe ni una futura. Un gasto es plata que ya
 * salió: si la cuenta da mañana, lo que se entendió está mal.
 */
function motor_detectar_fecha(string $texto): ?string {
    $t = motor_texto_fecha($texto);

    /* anteayer ANTES que ayer: "anteayer" contiene "ayer", y con el orden al revés
       la rama de anteayer no se ejecutaba nunca — anteayer devolvía ayer. */
    if (preg_match('/(^|\s)anteayer(\s|$)/u', $t)) return date('Y-m-d', strtotime('-2 days'));
    if (preg_match('/(^|\s)ayer(\s|$)/u', $t))     return date('Y-m-d', strtotime('-1 day'));
    if (preg_match('/(^|\s)hoy(\s|$)/u', $t))      return date('Y-m-d');

    $d = $mes = $anio = null;

    // "el 14 de agosto" / "14 de agosto de 2026"
    if (preg_match('/\b(\d{1,2})\s+de\s+([a-z]+)(?:\s+de\s+(\d{4}))?/u', $t, $m)) {
        $mesNum = motor_meses()[$m[2]] ?? null;
        if ($mesNum) { $d = (int)$m[1]; $mes = $mesNum; $anio = isset($m[3]) ? (int)$m[3] : null; }
    }
    // "14/08" o "14/08/2026"
    if ($d === null && preg_match('%\b(\d{1,2})/(\d{1,2})(?:/(\d{2,4}))?\b%u', $t, $m)) {
        $d = (int)$m[1]; $mes = (int)$m[2];
        if (isset($m[3])) $anio = (int)(strlen($m[3]) === 2 ? '20' . $m[3] : $m[3]);
    }
    if ($d === null) return null;

    /* Sin año dicho: el actual, salvo que eso caiga adelante. En enero, "20 de
       diciembre" es el diciembre que pasó, no el que viene dentro de once meses. */
    $explicito = $anio !== null;
    if (!$explicito) $anio = (int)date('Y');

    if (!checkdate($mes, $d, $anio)) return null;   // 31 de febrero y compañía
    $f = sprintf('%04d-%02d-%02d', $anio, $mes, $d);

    if (!$explicito && $f > date('Y-m-d')) {
        $anio--;
        if (!checkdate($mes, $d, $anio)) return null;   // un 29 de febrero que se corrió
        $f = sprintf('%04d-%02d-%02d', $anio, $mes, $d);
    }

    // Con el año dicho por el productor no se corrige nada: se rechaza y se avisa.
    return $f > date('Y-m-d') ? null : $f;
}

/** ¿Está pidiendo registrar algo, y no consultarlo? */
function motor_pide_alta(string $t): bool {
    // "cuánto gasté" es una consulta aunque tenga la palabra gasté: la pregunta
    // gana siempre, porque equivocarse para el lado de consultar no rompe nada.
    if (preg_match('/^\s*(cuanto|cuantos|cuantas|cual|que|como)\b/u', $t)) return false;

    // Pedir una opinión tampoco es cargar, y esas frases no llevan interrogativo.
    if (motor_es_consulta_de_consejo($t)) return false;

    // Órdenes: no tienen otra lectura posible. "anotá", "cargá", "registrá".
    foreach (['carga','cargar','carg','anota','anotar','registra','registrar',
              'sumar','agrega','agregar','poner','pone','meter'] as $p) {
        if (preg_match('/(^|\s)' . preg_quote($p, '/') . '/u', $t)) return true;
    }

    /* Verbos que sirven para las dos cosas. "gasté 20.000 en siembra" carga;
       "en qué gasté más" pregunta, y no la agarra el filtro de arriba porque la
       pregunta no arranca la frase. Dos señales la resuelven: una palabra de
       pregunta en cualquier lugar la vuelve consulta, y sin ningún número no hay
       nada que cargar. Ante la duda queda como consulta: contestar de más no
       rompe nada, proponer un alta que nadie pidió sí molesta. */
    foreach (['gaste','pague','compre'] as $p) {
        if (!preg_match('/(^|\s)' . preg_quote($p, '/') . '/u', $t)) continue;
        if (preg_match('/(^|\s)(cuanto|cuantos|cuantas|cual|cuales|que|como|donde|cuando)(\s|$)/u', $t)) return false;
        return (bool)preg_match('/\d/u', $t);
    }
    return false;
}

/**
 * Lee una frase suelta y llena los casilleros que pueda.
 *
 * NO decide si alcanza ni arma la propuesta: devuelve casilleros, los mismos que
 * usa el paso a paso. Así los dos caminos son uno solo — una frase completa llena
 * todo y va derecho a confirmar; una a la que le falta algo llena lo que dijo y
 * el paso a paso pregunta el resto. Antes eran dos funciones que armaban la misma
 * pantalla por separado, y la que faltaba terminaba en un cartel sin salida.
 */
function motor_interpretar_alta(string $texto, string $original, array $lotes, ?array $loteCtx): array {
    /* El monto y la fecha se leen del texto ORIGINAL, no del normalizado: la
       normalización saca puntos y comas para poder comparar palabras, y eso
       convierte "15.000" en "15 000" y "14/08" en "14 08". Justo los dos datos
       donde la puntuación ES el significado. */
    /* 'que' va desde el arranque para que no haya que volver a deducirlo en cada
       vuelta. Sin él, si el paso a paso siguiera desde acá, la respuesta "Insumo"
       al paso del tipo se releería como el arranque de un alta de catálogo y se
       cambiaría de formulario a mitad de camino. */
    $slots = ['que' => 'gasto'];

    $monto = motor_detectar_monto($original);
    if ($monto !== null && $monto > 0) $slots['costo_total'] = $monto;

    if ($grupo = motor_detectar_dimension($texto, motor_grupos())) $slots['grupo_gasto'] = $grupo['clave'];

    // Varios lotes de una sola frase: "para todos" o nombrándolos.
    if (preg_match('/(^|\s)(todos|todos los lotes|todo el campo|en todos)(\s|$)/u', $texto)) {
        $slots['lotes'] = array_map(fn($l) => (int)$l['id'], $lotes);
    } elseif ($ls = motor_detectar_lotes($texto, $lotes)) {
        $slots['lotes'] = array_map(fn($l) => (int)$l['id'], $ls);
    } elseif ($loteCtx) {
        $slots['lotes'] = [(int)$loteCtx['id']];
    }

    /* El tipo se deduce de lo que nombró, con las mismas dos opciones del
       formulario. Antes se adivinaba a partir de la etapa —siembra→semilla,
       cosecha→maquinaria— y eso tenía dos problemas: esos valores del enum no los
       suma el desglose de costos del panel, así que la plata cargada por el chat
       desaparecía del gráfico; y se le mostraba al productor un "Tipo: maquinaria"
       que él nunca dijo, para que lo aprobara como dato suyo.
       Ahora sólo hay tipo distinto si él nombró un insumo. Si no dijo nada, es
       mano de obra: es lo que el propio formulario tiene elegido por defecto. */
    $rubro = motor_detectar_dimension($texto, motor_tipos_insumo());
    $comp  = $rubro ? null : motor_detectar_dimension($texto, motor_componentes());
    if ($rubro) {
        $slots['tipo_componente'] = 'insumo';
        $slots['insumo_nombre']   = $rubro['etiqueta'];
    } elseif ($comp && $comp['clave'] === 'insumo') {
        $slots['tipo_componente'] = 'insumo';   // dijo "insumo" pero no cuál: se le pregunta
    } elseif ($comp && $comp['clave'] === 'labor') {
        $slots['tipo_componente'] = 'labor';
    }

    /* La fecha se completa siempre acá, con hoy si no dijo otra. Es el único
       casillero que se da por defecto, y se puede porque la confirmación la
       muestra: el productor aprueba "Fecha: 29/08/2026" a la vista.
       El tipo no corre la misma suerte aunque también se vea, porque de él depende
       en qué mitad del desglose de costos cae la plata, y "Mano de obra" no le
       dice a nadie que su fertilizante quedó contado como una labor. Ese se
       pregunta.
       Sólo pasa en la frase suelta: el paso a paso no llama a esta función, así
       que ahí la fecha se sigue preguntando, que es un toque en "Hoy".
       Si dijo una fecha y no se pudo entender —o era imposible, o caía adelante—
       NO se cae a hoy: se deja vacío y el paso a paso la vuelve a pedir. */
    $f = motor_detectar_fecha($original);
    if ($f !== null)                       $slots['fecha'] = $f;
    elseif (!motor_intento_de_fecha($texto)) $slots['fecha'] = date('Y-m-d');

    return $slots;
}

/* =====================================================================
   ALTA GUIADA (paso a paso)

   La otra forma de cargar: "quiero cargar un costo" y el motor va preguntando
   lo que falta, de a un dato por vez.

   Sirve para quien no sabe —ni tiene por qué saber— cómo hay que decirlo en una
   sola frase. Y termina en la MISMA pantalla de confirmación que el modo de un
   tiro: por más pasos que haya habido, nada se guarda sin que se apruebe el
   resumen completo.

   El estado va y viene con el cliente, no en el servidor: cada respuesta trae
   los casilleros ya completos. Así no hay sesiones a medio llenar del lado del
   servidor ni formularios fantasma si alguien cierra el chat.
   ===================================================================== */

/**
 * Los datos que hay que juntar, en el orden en que se preguntan.
 *
 * Hay dos altas distintas y comparten toda la maquinaria: un gasto en una
 * operación, y un insumo en el catálogo. Lo único que cambia es esta lista.
 */
/**
 * Los casilleros que se pueden corregir, con cómo los nombra la gente.
 *
 * Hasta acá la confirmación era todo o nada: si un dato salía mal había que
 * cancelar y rehacer los seis pasos por uno. Con esto se puede volver sobre uno
 * solo, y el resto queda como estaba.
 */
function motor_alta_campos(string $que = 'gasto'): array {
    $fecha = ['etiqueta' => 'la fecha', 'sinonimos' => ['fecha', 'dia', 'día', 'cuando']];
    if ($que === 'insumo') {
        return [
            'nombre'              => ['etiqueta' => 'el nombre',   'sinonimos' => ['nombre','se llama']],
            'tipo_insumo'         => ['etiqueta' => 'el tipo',     'sinonimos' => ['tipo','rubro']],
            'unidad_medida'       => ['etiqueta' => 'la unidad',   'sinonimos' => ['unidad','medida']],
            'precio_estimado_usd' => ['etiqueta' => 'el precio',   'sinonimos' => ['precio','valor']],
            'stock_actual'        => ['etiqueta' => 'el stock',    'sinonimos' => ['stock','cantidad']],
        ];
    }
    if ($que === 'alquiler') {
        return [
            'nivel_imputacion' => ['etiqueta' => 'la imputación', 'sinonimos' => ['imputacion','imputación','nivel']],
            'lote_id'          => ['etiqueta' => 'el lote',       'sinonimos' => ['lote','campo']],
            'cultivo_id'       => ['etiqueta' => 'el cultivo',    'sinonimos' => ['cultivo','especie']],
            'monto_pagado'     => ['etiqueta' => 'el monto',      'sinonimos' => ['monto','importe','plata','valor','precio']],
            'fecha_pago'       => $fecha,
        ];
    }
    if ($que === 'venta') {
        return [
            'lote_id'     => ['etiqueta' => 'el lote',      'sinonimos' => ['lote','campo']],
            'cultivo_id'  => ['etiqueta' => 'el cultivo',   'sinonimos' => ['cultivo','especie']],
            'kg'          => ['etiqueta' => 'los kilos',    'sinonimos' => ['kilos','kg','cantidad']],
            'precio_kg'   => ['etiqueta' => 'el precio',    'sinonimos' => ['precio']],
            'fecha_venta' => $fecha,
        ];
    }
    return [
        'grupo_gasto'      => ['etiqueta' => 'la etapa',    'sinonimos' => ['etapa','grupo','en que gaste','labor']],
        'tipo_componente'  => ['etiqueta' => 'el tipo',     'sinonimos' => ['tipo','componente']],
        'insumo_nombre'    => ['etiqueta' => 'el insumo',   'sinonimos' => ['insumo','producto']],
        'insumo_cantidad'  => ['etiqueta' => 'la cantidad', 'sinonimos' => ['cantidad','cuanto use']],
        'lotes'            => ['etiqueta' => 'el lote',     'sinonimos' => ['lote','lotes','campo']],
        'costo_total'      => ['etiqueta' => 'el monto',    'sinonimos' => ['monto','importe','plata','costo','valor','precio']],
        'reparto'          => ['etiqueta' => 'el reparto',  'sinonimos' => ['reparto','repartir','por hectarea','por hectárea']],
        'fecha'            => $fecha,
    ];
}

/**
 * ¿Está pidiendo corregir un casillero? Devuelve cuál, o '' si no dijo cuál.
 *
 * Pide las dos cosas: el verbo de corregir Y el nombre del campo. Con sólo el
 * nombre no alcanza —contestar "el monto" a "¿cuánto fue?" es una respuesta, no
 * una corrección— y con sólo el verbo no se sabe qué tocar, así que se pregunta.
 */
function motor_alta_pide_correccion(string $t, string $que = 'gasto'): ?string {
    if (!preg_match('/(corregir|corregi|corrig|cambiar|cambia|cambi|modific|editar|edita|'
                  . 'esta mal|está mal|me equivoque|equivoque|no era|volver a)/u', $t)) {
        return null;
    }
    $mejor = null; $largo = 0;
    foreach (motor_alta_campos($que) as $campo => $d) {
        foreach ($d['sinonimos'] as $s) {
            $n = motor_normalizar($s);
            if (strpos($t, $n) !== false && mb_strlen($n) > $largo) {
                $mejor = $campo; $largo = mb_strlen($n);
            }
        }
    }
    return $mejor ?? '';   // dijo que está mal, pero no dijo qué
}

function motor_alta_pasos(string $que = 'gasto'): array {
    if ($que === 'insumo')   return motor_alta_pasos_insumo();
    if ($que === 'alquiler') return motor_alta_pasos_alquiler();
    if ($que === 'venta')    return motor_alta_pasos_venta();
    return [
        'grupo_gasto' => [
            'pregunta' => '¿En qué gastaste?',
            'ayuda'    => 'Siembra, cosecha, pulverización, fertilización u otros.',
            'opciones' => ['Siembra', 'Cosecha', 'Pulverización', 'Fertilización', 'Otros'],
        ],
        /* Las dos primeras opciones del "Tipo Componente" del formulario de Costos
           y Labores, con sus palabras. Falta la tercera —Aplicación / Receta—
           porque separa la mano de obra de los insumos y necesita la cantidad y el
           precio de cada uno: eso es un formulario, no una charla. Se dice, para
           que quien la busque sepa dónde está en vez de creer que no existe. */
        'tipo_componente' => [
            'pregunta' => '¿Fue mano de obra o insumo?',
            'ayuda'    => 'Si fue una aplicación con receta (labor + insumos juntos), esa se carga desde Costos y Labores.',
            'opciones' => ['Mano de obra', 'Insumo'],
        ],
        /* Sólo si dijo insumo. Las mismas dos opciones que el desplegable del
           formulario: uno del catálogo, o uno escrito a mano. La diferencia no es
           cosmética y el formulario la nombra en su propia etiqueta —"Ingresar
           Texto Manual (Sin Descontar Stock)"—: el del catálogo descuenta stock y
           entra en el desglose por rubro; el escrito a mano no puede hacer ninguna
           de las dos cosas, porque no hay de qué descontar ni qué rubro mirar. */
        'insumo_nombre' => [
            'pregunta' => '¿Qué insumo fue?',
            'ayuda'    => 'Elegí uno de los tuyos, o escribí el nombre si no lo tenés cargado.',
            'opciones' => [],   // se completan con el catálogo del productor
            'solo_si'  => ['tipo_componente' => 'insumo'],
        ],
        /* La cantidad es lo que hace que el gasto se pueda vincular al catálogo.
           Sin ella habría que inventar una —y el borrado de la operación devuelve
           stock por "cantidad × hectáreas", así que una cantidad inventada
           devolvería stock que nunca se descontó—. Con ella salen exactos el precio
           unitario (costo ÷ cantidad) y la cantidad por hectárea, que son las dos
           cifras con las que el formulario calcula todo. */
        'insumo_cantidad' => [
            'pregunta' => '¿Qué cantidad usaste en total?',
            'ayuda'    => '',
            'opciones' => [],
            'solo_si'  => ['tipo_componente' => 'insumo'],
        ],
        'lotes' => [
            'pregunta' => '¿En qué lote?',
            'ayuda'    => 'Podés nombrar varios de una: "La rubia y El bajo", o decir todos.',
            'opciones' => [],   // se completan con los lotes del productor
        ],
        'costo_total' => [
            'pregunta' => '¿Cuánto fue?',
            'ayuda'    => 'Escribilo como quieras: 15000 o 15.000,50.',
            'opciones' => [],
        ],
        /* Sólo cuando hay más de un lote, y es imprescindible: "500.000 de
           fertilizante para todos" puede ser la factura entera a repartir o el
           precio por hectárea. Sin preguntarlo habría que adivinar, y adivinar
           mal acá multiplica el error por la cantidad de lotes. */
        'reparto' => [
            'pregunta' => '¿Ese monto es el total de todo, o es por hectárea?',
            'ayuda'    => 'Si es el total, lo reparto entre los lotes según la superficie de cada uno.',
            'opciones' => ['Es el total', 'Es por hectárea'],
            'solo_si_varios' => 'lotes',
        ],
        'fecha' => [
            'pregunta' => '¿Cuándo fue?',
            'ayuda'    => 'Podés decir hoy, ayer, o una fecha como 14 de agosto.',
            'opciones' => ['Hoy', 'Ayer'],
        ],
    ];
}

/**
 * Los datos de un insumo del catálogo: los cuatro obligatorios del formulario de
 * Insumos, más el stock, que es opcional ahí pero es la mitad del sentido de tener
 * un catálogo.
 *
 * El precio va EN DÓLARES y la pregunta lo dice. El formulario lo aclara en la
 * etiqueta ("Precio Est. (USD / Unidad)") y acá no hay etiqueta que mirar: si la
 * pregunta fuera "¿cuánto sale?", el productor contestaría en pesos y quedarían
 * guardados como dólares. Eso no se nota al cargarlo — se nota meses después, en
 * un costo por hectárea que no cierra con nada.
 */
function motor_alta_pasos_insumo(): array {
    return [
        'nombre' => [
            'pregunta' => '¿Cómo se llama el insumo?',
            'ayuda'    => 'Como lo pedís vos: Glifosato 64%, Urea, Soja Don Mario.',
            'opciones' => [],
        ],
        'tipo_insumo' => [
            'pregunta' => '¿Qué tipo es?',
            'ayuda'    => '',
            'opciones' => ['Semilla', 'Fertilizante', 'Agroquímico', 'Inoculante', 'Otro'],
        ],
        'unidad_medida' => [
            'pregunta' => '¿En qué unidad lo medís?',
            'ayuda'    => '',
            'opciones' => ['Kilogramos', 'Litros', 'Dosis', 'Bolsa'],
        ],
        'precio_estimado_usd' => [
            'pregunta' => '¿A cuánto está la unidad, en dólares?',
            'ayuda'    => 'En USD, como en el formulario de Insumos. Por ejemplo 6,50.',
            'opciones' => [],
        ],
        'stock_actual' => [
            'pregunta' => '¿Cuánto tenés en stock?',
            'ayuda'    => 'Si todavía no lo compraste o no lo llevás, poné 0.',
            'opciones' => ['0'],
        ],
    ];
}

/**
 * Los datos de un pago de alquiler.
 *
 * El formulario de Alquileres expone DOS niveles de imputación —por cultivo y por
 * campaña— aunque el enum de la base tenga tres: 'lote' quedó de una versión vieja
 * y el propio formulario lo traduce a 'cultivo' al abrirlo. Acá se ofrecen los dos
 * que existen de verdad.
 *
 * Y el monto va EN DÓLARES. En el formulario la etiqueta lo dice —"Monto Pagado
 * (USD)"— y el selector de moneda está oculto con USD como única opción. En un
 * chat no hay etiqueta que mirar: si la pregunta fuera "¿cuánto pagaste?", el
 * productor contestaría en pesos y quedaría guardado como dólares. Un alquiler mal
 * cargado no desentona como un insumo: es de los números más grandes del año.
 */
function motor_alta_pasos_alquiler(): array {
    return [
        'nivel_imputacion' => [
            'pregunta' => '¿El alquiler es de un cultivo o de toda la campaña?',
            'ayuda'    => 'Por cultivo lo imputa a un lote y su cultivo; por campaña queda general.',
            'opciones' => ['Por cultivo', 'Por campaña'],
        ],
        'lote_id' => [
            'pregunta' => '¿De qué lote?',
            'ayuda'    => '',
            'opciones' => [],   // se completan con los lotes del productor
            'solo_si'  => ['nivel_imputacion' => 'cultivo'],
        ],
        'cultivo_id' => [
            'pregunta' => '¿Qué cultivo?',
            'ayuda'    => '',
            'opciones' => [],   // los cultivos de ESE lote
            'solo_si'  => ['nivel_imputacion' => 'cultivo'],
        ],
        'monto_pagado' => [
            'pregunta' => '¿Cuánto pagaste, en dólares?',
            'ayuda'    => 'En USD, como en el formulario de Alquileres. Por ejemplo 8500.',
            'opciones' => [],
        ],
        'fecha_pago' => [
            'pregunta' => '¿Cuándo lo pagaste?',
            'ayuda'    => 'Podés decir hoy, ayer, o una fecha como 14 de agosto.',
            'opciones' => ['Hoy', 'Ayer'],
        ],
    ];
}

/**
 * Los datos de una entrega vendida.
 *
 * El ingreso NO se pregunta: en la base es una columna generada
 * (ingreso_total = kg_cosechados × precio_kg), así que pedirlo sería pedir un dato
 * que la base va a recalcular igual. Se muestra en la confirmación, eso sí, porque
 * es el número que el productor reconoce.
 *
 * Del precio por kilo hay que decir algo incómodo: el formulario lo rotula
 * "Precio por kg (USD)" pero su propio ejemplo es "320.00", que es el precio del
 * trigo en PESOS por kilo (la Cámara lo publica a 320.400 ARS la tonelada). Y los
 * ingresos se suman sin convertir contra costos que están en pesos. Así que en la
 * práctica se carga en pesos. Antes que resolver esa contradicción por mi cuenta,
 * el chat contrasta contra la cotización de la Cámara: si el número está errado en
 * un orden de magnitud, se ve al lado y no hace falta discutir de qué moneda es.
 */
function motor_alta_pasos_venta(): array {
    return [
        'lote_id' => [
            'pregunta' => '¿De qué lote es la entrega?',
            'ayuda'    => '',
            'opciones' => [],   // se completan con los lotes del productor
        ],
        'cultivo_id' => [
            'pregunta' => '¿Qué cultivo?',
            'ayuda'    => '',
            'opciones' => [],   // los cultivos de ESE lote
        ],
        'kg' => [
            'pregunta' => '¿Cuántos kilos entregaste?',
            'ayuda'    => 'En kilos. Si lo tenés en toneladas, multiplicá por mil.',
            'opciones' => [],
        ],
        'precio_kg' => [
            'pregunta' => '¿A qué precio por kilo?',
            'ayuda'    => '',   // se completa con la referencia de la Cámara
            'opciones' => [],
        ],
        'fecha_venta' => [
            'pregunta' => '¿Cuándo fue la entrega?',
            'ayuda'    => 'Podés decir hoy, ayer, o una fecha como 14 de agosto.',
            'opciones' => ['Hoy', 'Ayer'],
        ],
    ];
}

/**
 * ¿Corresponde preguntar este paso, con lo que ya se sabe?
 *
 * Hay pasos que dependen de una respuesta anterior: el nombre del insumo sólo
 * tiene sentido si antes dijo que fue un insumo. Un paso sin 'solo_si' se
 * pregunta siempre.
 */
function motor_alta_paso_aplica(array $paso, array $slots): bool {
    foreach ($paso['solo_si'] ?? [] as $campo => $valor) {
        if (($slots[$campo] ?? null) !== $valor) return false;
    }
    // "Sólo si ese casillero junta más de uno": es lo que separa un gasto de un
    // lote de un gasto repartido, y con uno solo no hay nada que preguntar.
    if ($varios = $paso['solo_si_varios'] ?? null) {
        if (count($slots[$varios] ?? []) < 2) return false;
    }
    return true;
}

/**
 * Reparte un monto entre los lotes elegidos.
 *
 * Dos modos, los mismos dos que ofrece el formulario:
 *   - 'total':    el monto es la factura entera y se prorratea por superficie.
 *   - 'hectarea': el monto es el precio por hectárea y se multiplica por cada una.
 *
 * El resto de la división se le suma al último lote a propósito. Repartir
 * $100.000 entre tres lotes iguales da $33.333,33 cada uno y suma $99.999,99: el
 * productor cargó cien mil y en el panel aparecerían noventa y nueve mil
 * novecientos noventa y nueve con noventa y nueve. Un centavo perdido en una
 * cuenta de plata es un error de la aplicación, no un redondeo.
 *
 * @return array [['lote'=>array, 'monto'=>float, 'sup'=>float], ...]
 */
function motor_alta_reparto(array $lotesSel, float $monto, string $modo): array {
    // ?? 0: si la superficie no viniera, se cae al reparto sin prorrateo de abajo
    // en vez de romper. El guardado igual recalcula todo con lo que lee de la base.
    $sups = array_map(fn($l) => max(0.0, (float)($l['superficie'] ?? 0)), $lotesSel);
    $supTotal = array_sum($sups);

    $out = [];
    if ($modo === 'hectarea' || $supTotal <= 0) {
        /* Por hectárea, o sin superficies cargadas: sin superficie no hay
           prorrateo posible, y repartir en partes iguales sería inventar un
           criterio. Se aplica el monto tal cual a cada lote. */
        foreach ($lotesSel as $i => $l) {
            $out[] = ['lote' => $l, 'sup' => $sups[$i],
                      'monto' => $supTotal > 0 ? $monto * $sups[$i] : $monto];
        }
        return $out;
    }

    $acumulado = 0.0;
    $ultimo = count($lotesSel) - 1;
    foreach ($lotesSel as $i => $l) {
        $parte = $i === $ultimo
            ? round($monto - $acumulado, 2)               // el resto, para que cierre
            : round($monto * ($sups[$i] / $supTotal), 2);
        $acumulado += $parte;
        $out[] = ['lote' => $l, 'sup' => $sups[$i], 'monto' => $parte];
    }
    return $out;
}

/**
 * De los casilleros llenos a la propuesta que se muestra para confirmar.
 *
 * La usan los dos caminos —el paso a paso y la frase suelta— para que la pantalla
 * de confirmación sea exactamente la misma. Es la última cosa que el productor ve
 * antes de que se escriba: si acá se dijera algo distinto de lo que se guarda, el
 * "confirmá que entendí bien" no serviría de nada.
 *
 * @return array ['datos' => lo que se envía a guardar, 'detalle' => el texto]
 */
function motor_armar_alta(array $slots, array $lotes, string $campania): array {
    $ids = array_map('intval', $slots['lotes']);
    $sel = array_values(array_filter($lotes, fn($l) => in_array((int)$l['id'], $ids, true)));
    $modo = count($sel) > 1 ? ($slots['reparto'] ?? 'total') : 'total';
    $partes = motor_alta_reparto($sel, (float)$slots['costo_total'], $modo);
    $total  = array_sum(array_column($partes, 'monto'));

    $esInsumo = ($slots['tipo_componente'] ?? 'labor') === 'insumo';
    // insumo_id > 0 quiere decir que salió del catálogo: ése descuenta stock.
    $delCatalogo = $esInsumo && !empty($slots['insumo_id']);

    $datos = [
        'lotes'           => array_map(fn($l) => (int)$l['id'], $sel),
        /* Se guarda en la moneda que se está mirando. No es una suposición
           escondida: la confirmación muestra "US$" o "$" delante del monto, así que
           el productor aprueba la moneda junto con el número. */
        'moneda'          => motor_moneda(),
        'grupo_gasto'     => $slots['grupo_gasto'],
        'tipo_componente' => $esInsumo ? 'insumo' : 'labor',
        'insumo_nombre'   => $esInsumo ? ($slots['insumo_nombre'] ?? '') : '',
        'insumo_id'       => $delCatalogo ? (int)$slots['insumo_id'] : 0,
        'insumo_cantidad' => $esInsumo ? (float)($slots['insumo_cantidad'] ?? 0) : 0,
        'costo_total'     => $total,
        'reparto'         => $modo,
        'fecha'           => $slots['fecha'],
        'campania'        => $campania,
    ];

    $lineas = [
        'Etapa: '   . motor_grupos()[$slots['grupo_gasto']]['etiqueta'],
        // La misma palabra que usa el desplegable del formulario.
        'Tipo: '    . ($esInsumo ? 'Insumo — ' . ($slots['insumo_nombre'] ?? '') : 'Mano de obra'),
    ];

    if ($esInsumo && !empty($slots['insumo_cantidad'])) {
        $uni = isset($slots['insumo_unidad']) ? ' ' . (motor_unidades()[$slots['insumo_unidad']] ?? '') : '';
        $lineas[] = 'Cantidad: ' . number_format($slots['insumo_cantidad'], 2, ',', '.') . $uni;
    }

    if (count($partes) === 1) {
        $lineas[] = 'Lote: '  . $partes[0]['lote']['nombre'];
        $lineas[] = 'Monto: ' . motor_formatear($total, 'dinero');
    } else {
        /* Con varios lotes se muestra CUÁNTO le toca a cada uno, no sólo el total.
           Es una sola carga pero son varios gastos, y el productor tiene que poder
           ver el reparto antes de aprobarlo: si la superficie de un lote está mal
           cargada, acá es donde se nota. */
        $lineas[] = $modo === 'hectarea'
            ? 'Monto: ' . motor_formatear((float)$slots['costo_total'], 'dinero') . ' por hectárea'
            : 'Monto: ' . motor_formatear($total, 'dinero') . ' repartido por superficie';
        foreach ($partes as $p) {
            $lineas[] = '   · ' . $p['lote']['nombre'] . ' (' . motor_formatear($p['sup'], 'ha') . '): '
                      . motor_formatear($p['monto'], 'dinero');
        }
        if ($modo === 'hectarea') $lineas[] = 'Total: ' . motor_formatear($total, 'dinero');
    }

    $lineas[] = 'Fecha: '   . date('d/m/Y', strtotime($slots['fecha']));
    $lineas[] = 'Campaña: ' . $campania;

    /* La campaña sale de lo que se viene mirando en la charla, no de la fecha: no
       hay forma de deducir una de la otra, porque una campaña cruza dos años
       calendario y arranca y termina cuando el productor decide. Pero si el año
       de la fecha ni siquiera figura en el nombre de la campaña, algo no cierra, y
       vale más avisarlo que corregirlo por él: puede haber una razón. */
    if (!motor_anio_en_campania($slots['fecha'], $campania)) {
        $lineas[] = '⚠ Ojo: esa fecha no cae en los años de la campaña ' . $campania . '.';
    }

    /* Lo que va a pasar con el stock, dicho antes de que confirme. Es la única
       consecuencia de esta carga que no se ve en ninguna pantalla del panel, y es
       la misma distinción que hace el formulario en su desplegable. */
    if ($esInsumo && !empty($slots['insumo_cantidad'])) {
        $uni = isset($slots['insumo_unidad']) ? ' ' . (motor_unidades()[$slots['insumo_unidad']] ?? '') : '';
        $lineas[] = $delCatalogo
            ? 'Descuenta ' . number_format($slots['insumo_cantidad'], 2, ',', '.') . $uni . ' del stock.'
            : 'No descuenta stock: no está en tu catálogo de insumos.';
    }

    return ['datos' => $datos, 'detalle' => implode("\n", $lineas)];
}

/**
 * ¿El año de la fecha figura en el nombre de la campaña?
 *
 * "25/26" son 2025 y 2026. Es una comprobación mecánica sobre el nombre, no una
 * suposición sobre cuándo empieza o termina una campaña —eso lo decide cada
 * productor—. Un nombre con otro formato no se juzga: se devuelve true y no se
 * avisa nada, porque avisar de más sobre algo que no se entiende es peor que
 * callarse.
 */
function motor_anio_en_campania(string $fecha, string $campania): bool {
    if (!preg_match_all('/\d{2,4}/', $campania, $m) || !$m[0]) return true;
    $anio = (int)substr($fecha, 0, 4);
    foreach ($m[0] as $x) {
        $a = strlen($x) === 2 ? 2000 + (int)$x : (int)$x;
        if ($a === $anio) return true;
    }
    return false;
}

/**
 * ¿Intentó decir una fecha, aunque no se haya entendido?
 *
 * Sirve para distinguir "no dijo nada de la fecha" —donde vale asumir hoy— de
 * "dijo algo que no se pudo leer", donde asumir hoy sería pisar lo que quiso decir.
 *
 * Exige el nombre del mes escrito. Sin eso, el patrón "N de X" agarra los números
 * del monto: la normalización parte "8.500,50" en "8 500 50", y "50 de urea" pasaba
 * por una fecha. El resultado era que una frase completa volvía a preguntar cuándo.
 */
function motor_intento_de_fecha(string $texto): bool {
    $meses = implode('|', array_keys(motor_meses()));
    return (bool)preg_match(
        '%(^|\s)(hoy|ayer|anteayer)(\s|$)|\b\d{1,2}\s+de\s+(' . $meses . ')\b|\b\d{1,2}/\d{1,2}\b%u',
        $texto
    );
}

/**
 * Por qué no se tomó la fecha que dijo.
 *
 * Volver a preguntar lo mismo sin explicar es lo que hace que el productor
 * escriba la misma fecha otra vez y crea que el chat está colgado. Se distingue el
 * motivo porque cada uno se corrige distinto: una fecha que no existe se reescribe,
 * una futura significa que se equivocó de mes o de año.
 */
function motor_aviso_fecha(string $original): string {
    // La misma lectura que motor_detectar_fecha(): si acá se normalizara distinto,
    // el motivo del rechazo no coincidiría con el rechazo.
    $t = motor_texto_fecha($original);

    // ¿Nombró un día y un mes que no forman una fecha real?
    if (preg_match('/\b(\d{1,2})\s+de\s+([a-z]+)/u', $t, $m)) {
        $mes = motor_meses()[$m[2]] ?? null;
        if ($mes && !checkdate($mes, (int)$m[1], (int)date('Y'))) {
            return 'Esa fecha no existe.';
        }
    }
    if (preg_match('%\b(\d{1,2})/(\d{1,2})%u', $t, $m)
        && !checkdate((int)$m[2], (int)$m[1], (int)date('Y'))) {
        return 'Esa fecha no existe.';
    }
    // Con el año dicho a mano se rechaza en vez de corregirlo solo.
    if (preg_match('/\b(\d{4})\b/u', $t, $m) && (int)$m[1] > (int)date('Y')) {
        return 'Esa fecha todavía no llegó: un gasto es plata que ya salió.';
    }
    return 'No entendí esa fecha.';
}

/**
 * El alta de un insumo en el catálogo, de punta a punta.
 *
 * Vive aparte del alta de gasto porque no comparten ni un casillero: acá no hay
 * lote, ni etapa, ni fecha. Lo único común es la mecánica —una pregunta por vuelta,
 * el estado viaja con el cliente, nada se guarda sin confirmar—.
 */
function motor_alta_insumo(PDO $pdo, int $usuarioId, array $slots, string $texto, string $original, bool $enCurso): array {
    $pasos = motor_alta_pasos('insumo');
    $aviso = '';

    if ($enCurso) {
        foreach ($pasos as $campo => $paso) {
            if (isset($slots[$campo])) continue;

            if ($campo === 'nombre') {
                // Tal cual lo escribió: es el nombre con el que él lo pide.
                $n = trim(preg_replace('/\s+/u', ' ', $original));
                if ($n !== '' && mb_strlen($n) <= 150) $slots['nombre'] = $n;

            } elseif ($campo === 'tipo_insumo') {
                $r = motor_detectar_dimension($texto, motor_tipos_insumo());
                if ($r) $slots['tipo_insumo'] = $r['clave'];

            } elseif ($campo === 'unidad_medida') {
                $u = motor_detectar_unidad($texto);
                if ($u) $slots['unidad_medida'] = $u;

            } elseif ($campo === 'precio_estimado_usd') {
                $p = motor_detectar_monto($original);
                if ($p === null && preg_match('/(\d+(?:[.,]\d+)?)/u', $original, $mm)) {
                    $p = (float)str_replace(',', '.', $mm[1]);
                }
                if ($p !== null && $p > 0) $slots['precio_estimado_usd'] = $p;
                else $aviso = 'Ese precio no me cierra.';

            } elseif ($campo === 'stock_actual') {
                // Acá el cero es una respuesta válida, así que se acepta explícito.
                if (preg_match('/(^|\s)(0|cero|ninguno|nada|no tengo|todavia no)(\s|$)/u', $texto)) {
                    $slots['stock_actual'] = 0.0;
                } else {
                    $s = motor_detectar_monto($original);
                    if ($s === null && preg_match('/(\d+(?:[.,]\d+)?)/u', $original, $mm)) {
                        $s = (float)str_replace(',', '.', $mm[1]);
                    }
                    if ($s !== null && $s >= 0) $slots['stock_actual'] = $s;
                }
            }
            break;   // un dato por vuelta
        }
    }

    // ¿Qué falta?
    foreach ($pasos as $campo => $paso) {
        if (isset($slots[$campo])) continue;

        $hechos = [];
        if (isset($slots['nombre']))              $hechos[] = $slots['nombre'];
        if (isset($slots['tipo_insumo']))         $hechos[] = motor_tipos_insumo()[$slots['tipo_insumo']]['singular'];
        if (isset($slots['unidad_medida']))       $hechos[] = motor_unidades()[$slots['unidad_medida']];
        if (isset($slots['precio_estimado_usd'])) $hechos[] = 'USD ' . number_format($slots['precio_estimado_usd'], 2, ',', '.');

        return [
            'ok' => true, 'tipo' => 'alta_paso',
            'respuesta' => ($aviso ? $aviso . ' ' : '') . $paso['pregunta'],
            'detalle' => trim(($hechos ? 'Vamos con: ' . implode(' · ', $hechos) . '. ' : '') . $paso['ayuda']),
            'valor' => null,
            'alta_pendiente' => $slots,
            'filtros' => [], 'link' => null,
            'sugerencias' => $paso['opciones'],
        ];
    }

    /* Un insumo con el mismo nombre ya cargado. No se bloquea —puede haber dos
       ureas de proveedores distintos— pero se dice: un catálogo con "Urea" tres
       veces deja de servir para lo único que sirve, que es encontrar el insumo. */
    $st = $pdo->prepare("SELECT nombre FROM insumos WHERE usuario_id = ? AND nombre = ? AND estado = 'activo'");
    $st->execute([$usuarioId, $slots['nombre']]);
    $repetido = (bool)$st->fetchColumn();

    $uni = motor_unidades()[$slots['unidad_medida']];
    $lineas = [
        'Insumo: '  . $slots['nombre'],
        'Tipo: '    . motor_tipos_insumo()[$slots['tipo_insumo']]['singular'],
        'Unidad: '  . $uni,
        'Precio: USD ' . number_format($slots['precio_estimado_usd'], 2, ',', '.')
                       . ' por ' . motor_unidades_singular()[$slots['unidad_medida']],
        'Stock: '   . number_format($slots['stock_actual'], 2, ',', '.') . ' ' . $uni,
    ];
    if ($repetido) $lineas[] = '⚠ Ojo: ya tenés uno cargado con ese mismo nombre.';

    return [
        'ok' => true, 'tipo' => 'propuesta_alta',
        'respuesta' => 'Listo. Confirmame que está bien:',
        'detalle' => implode("\n", $lineas),
        'valor' => $slots['precio_estimado_usd'],
        'alta' => [
            'que'                 => 'insumo',
            'nombre'              => $slots['nombre'],
            'tipo_insumo'         => $slots['tipo_insumo'],
            'unidad_medida'       => $slots['unidad_medida'],
            'precio_estimado_usd' => $slots['precio_estimado_usd'],
            'stock_actual'        => $slots['stock_actual'],
        ],
        'alta_pendiente' => null,
        'filtros' => [], 'link' => null, 'sugerencias' => [],
    ];
}

/**
 * El alta de un pago de alquiler, de punta a punta.
 *
 * Lo que lo hace distinto de un gasto no es el formulario sino la MONEDA: acá el
 * monto es en dólares y allá en pesos. Por eso son dos altas separadas y no una con
 * un campo más — mezclarlas era la forma más rápida de que un alquiler terminara
 * cargado en pesos.
 */
function motor_alta_alquiler(PDO $pdo, int $usuarioId, array $slots, string $texto, string $original,
                             bool $enCurso, array $lotes, string $campania): array {
    $pasos = motor_alta_pasos('alquiler');
    $aviso = '';

    if ($enCurso) {
        foreach ($pasos as $campo => $paso) {
            if (isset($slots[$campo])) continue;
            if (!motor_alta_paso_aplica($paso, $slots)) continue;

            if ($campo === 'nivel_imputacion') {
                if (preg_match('/(campana|campania|general|todo)/u', $texto))     $slots['nivel_imputacion'] = 'campania';
                elseif (preg_match('/(cultivo|lote|por cultivo)/u', $texto))      $slots['nivel_imputacion'] = 'cultivo';

            } elseif ($campo === 'lote_id') {
                $l = motor_detectar_lote($texto, $lotes);
                if ($l) $slots['lote_id'] = (int)$l['id'];

            } elseif ($campo === 'cultivo_id') {
                foreach (motor_cultivos_del_lote($pdo, $usuarioId, (int)$slots['lote_id']) as $c) {
                    if (motor_coincide($texto, motor_normalizar($c['nombre']))) {
                        $slots['cultivo_id']     = (int)$c['id'];
                        $slots['cultivo_nombre'] = $c['nombre'];
                        if (!empty($c['ciclo'])) $slots['campania'] = $c['ciclo'];
                        break;
                    }
                }

            } elseif ($campo === 'monto_pagado') {
                $m = motor_detectar_monto($original);
                if ($m === null && preg_match('/(\d+(?:[.,]\d+)?)/u', $original, $mm)) {
                    $m = (float)str_replace(',', '.', $mm[1]);
                }
                if ($m !== null && $m > 0) $slots['monto_pagado'] = $m;

            } elseif ($campo === 'fecha_pago') {
                $f = motor_detectar_fecha($original);
                if ($f !== null) $slots['fecha_pago'] = $f;
                else $aviso = motor_aviso_fecha($original);
            }
            break;   // un dato por vuelta
        }
    }

    // ¿Qué falta?
    foreach ($pasos as $campo => $paso) {
        if (isset($slots[$campo])) continue;
        if (!motor_alta_paso_aplica($paso, $slots)) continue;

        $ops = $paso['opciones'];
        if ($campo === 'lote_id') {
            $ops = array_map(fn($l) => $l['nombre'], $lotes);
            if (!$ops) {
                return [
                    'ok' => false, 'tipo' => 'falta_dato',
                    'respuesta' => 'Todavía no tenés lotes cargados, así que no puedo imputar el alquiler a un cultivo.',
                    'detalle' => 'Creá un lote desde Lotes y Cultivos, o cargalo por campaña.',
                    'valor' => null, 'alta_pendiente' => null,
                    'filtros' => [], 'link' => 'lotes.php', 'sugerencias' => [],
                ];
            }
        }
        if ($campo === 'cultivo_id') {
            $cs = motor_cultivos_del_lote($pdo, $usuarioId, (int)$slots['lote_id']);
            if (!$cs) {
                /* Sin cultivos en ese lote no se puede imputar por cultivo, y el
                   formulario tampoco deja. Se dice y se ofrece la salida que existe,
                   en vez de dejarlo trabado en una pregunta sin respuestas. */
                return [
                    'ok' => false, 'tipo' => 'falta_dato',
                    'respuesta' => 'Ese lote no tiene cultivos registrados, así que no puedo imputarlo por cultivo.',
                    'detalle' => 'Podés cargar el alquiler por campaña, o registrar el cultivo desde Lotes y Cultivos.',
                    'valor' => null, 'alta_pendiente' => null,
                    'filtros' => [], 'link' => 'lotes.php',
                    'sugerencias' => ['Cargar un alquiler por campaña'],
                ];
            }
            $ops = array_map(fn($c) => $c['nombre'], $cs);
        }

        $hechos = [];
        if (isset($slots['nivel_imputacion'])) $hechos[] = $slots['nivel_imputacion'] === 'campania' ? 'por campaña' : 'por cultivo';
        if (isset($slots['lote_id'])) {
            foreach ($lotes as $l) if ((int)$l['id'] === $slots['lote_id']) $hechos[] = $l['nombre'];
        }
        if (isset($slots['cultivo_nombre'])) $hechos[] = $slots['cultivo_nombre'];
        if (isset($slots['monto_pagado']))   $hechos[] = 'USD ' . number_format($slots['monto_pagado'], 2, ',', '.');

        return [
            'ok' => true, 'tipo' => 'alta_paso',
            'respuesta' => ($aviso ? $aviso . ' ' : '') . $paso['pregunta'],
            'detalle' => trim(($hechos ? 'Vamos con: ' . implode(' · ', $hechos) . '. ' : '') . $paso['ayuda']),
            'valor' => null,
            'alta_pendiente' => $slots,
            'filtros' => [], 'link' => null,
            'sugerencias' => $ops,
        ];
    }

    $camp = $slots['campania'] ?? $campania;
    $lineas = [
        'Imputación: ' . ($slots['nivel_imputacion'] === 'campania' ? 'toda la campaña' : 'un cultivo'),
    ];
    if ($slots['nivel_imputacion'] === 'cultivo') {
        foreach ($lotes as $l) if ((int)$l['id'] === $slots['lote_id']) $lineas[] = 'Lote: ' . $l['nombre'];
        $lineas[] = 'Cultivo: ' . ($slots['cultivo_nombre'] ?? '');
    }
    $lineas[] = 'Campaña: ' . $camp;
    $lineas[] = 'Monto: USD ' . number_format($slots['monto_pagado'], 2, ',', '.');
    $lineas[] = 'Fecha: ' . date('d/m/Y', strtotime($slots['fecha_pago']));

    /* El equivalente en pesos al dólar de referencia, para que se note de una si
       alguien contestó en pesos: "USD 1.200.000" al lado de su equivalente en pesos
       salta a la vista, y el número solo no. No se guarda: es sólo para mirar. */
    $ref = motor_dolar_referencia($pdo, $usuarioId);
    if ($ref > 0) {
        $lineas[] = '   ≈ $' . number_format($slots['monto_pagado'] * $ref, 2, ',', '.')
                  . ' al dólar de referencia ($' . number_format($ref, 2, ',', '.') . ').';
    }

    return [
        'ok' => true, 'tipo' => 'propuesta_alta',
        'respuesta' => 'Listo. Confirmame que está bien:',
        'detalle' => implode("\n", $lineas),
        'valor' => $slots['monto_pagado'],
        'alta' => [
            'que'              => 'alquiler',
            'nivel_imputacion' => $slots['nivel_imputacion'],
            'lote_id'          => (int)($slots['lote_id'] ?? 0),
            'cultivo_id'       => (int)($slots['cultivo_id'] ?? 0),
            'campania'         => $camp,
            'monto_pagado'     => $slots['monto_pagado'],
            'fecha_pago'       => $slots['fecha_pago'],
        ],
        'alta_pendiente' => null,
        'filtros' => [], 'link' => null, 'sugerencias' => [],
    ];
}

/**
 * El alta de una entrega vendida, de punta a punta.
 *
 * Es la única de las cuatro que SUMA en vez de restar, y la única donde el número
 * grande —el ingreso— no se pregunta: sale de multiplicar los otros dos, porque la
 * base lo calcula sola.
 */
function motor_alta_venta(PDO $pdo, int $usuarioId, array $slots, string $texto, string $original,
                          bool $enCurso, array $lotes, string $campania): array {
    $pasos = motor_alta_pasos('venta');
    $aviso = '';

    if ($enCurso) {
        foreach ($pasos as $campo => $paso) {
            if (isset($slots[$campo])) continue;

            if ($campo === 'lote_id') {
                $l = motor_detectar_lote($texto, $lotes);
                if ($l) $slots['lote_id'] = (int)$l['id'];

            } elseif ($campo === 'cultivo_id') {
                foreach (motor_cultivos_del_lote($pdo, $usuarioId, (int)$slots['lote_id']) as $c) {
                    if (motor_coincide($texto, motor_normalizar($c['nombre']))) {
                        $slots['cultivo_id']     = (int)$c['id'];
                        $slots['cultivo_nombre'] = $c['nombre'];
                        if (!empty($c['ciclo'])) $slots['campania'] = $c['ciclo'];
                        break;
                    }
                }

            } elseif ($campo === 'kg' || $campo === 'precio_kg') {
                $v = motor_detectar_monto($original);
                if ($v === null && preg_match('/(\d+(?:[.,]\d+)?)/u', $original, $mm)) {
                    $v = (float)str_replace(',', '.', $mm[1]);
                }
                if ($v !== null && $v > 0) $slots[$campo] = $v;

            } elseif ($campo === 'fecha_venta') {
                $f = motor_detectar_fecha($original);
                if ($f !== null) $slots['fecha_venta'] = $f;
                else $aviso = motor_aviso_fecha($original);
            }
            break;   // un dato por vuelta
        }
    }

    // La cotización de la Cámara para ESE cultivo, si la hay. Sirve de referencia.
    $ref = isset($slots['cultivo_nombre'])
         ? motor_precio_referencia($pdo, $slots['cultivo_nombre'])
         : null;

    // ¿Qué falta?
    foreach ($pasos as $campo => $paso) {
        if (isset($slots[$campo])) continue;

        $ops = $paso['opciones'];
        if ($campo === 'lote_id') {
            $ops = array_map(fn($l) => $l['nombre'], $lotes);
            if (!$ops) {
                return [
                    'ok' => false, 'tipo' => 'falta_dato',
                    'respuesta' => 'Todavía no tenés lotes cargados, así que no puedo registrar la entrega.',
                    'detalle' => 'Creá un lote primero desde Lotes y Cultivos.',
                    'valor' => null, 'alta_pendiente' => null,
                    'filtros' => [], 'link' => 'lotes.php', 'sugerencias' => [],
                ];
            }
        }
        if ($campo === 'cultivo_id') {
            $cs = motor_cultivos_del_lote($pdo, $usuarioId, (int)$slots['lote_id']);
            if (!$cs) {
                return [
                    'ok' => false, 'tipo' => 'falta_dato',
                    'respuesta' => 'Ese lote no tiene cultivos registrados, así que no sé qué se entregó.',
                    'detalle' => 'Registrá el cultivo desde Lotes y Cultivos y volvemos.',
                    'valor' => null, 'alta_pendiente' => null,
                    'filtros' => [], 'link' => 'lotes.php', 'sugerencias' => [],
                ];
            }
            $ops = array_map(fn($c) => $c['nombre'], $cs);
        }
        if ($campo === 'precio_kg') {
            /* La referencia de la Cámara al lado de la pregunta. Es lo que convierte
               un error de escala —cargar el precio por tonelada, o en dólares— en
               algo evidente antes de guardar, sin tener que explicar monedas. */
            $paso['ayuda'] = $ref
                ? 'De referencia, la Cámara pagaba $' . number_format($ref['precio_kg'], 2, ',', '.')
                  . ' por kilo de ' . $ref['cultivo'] . ' al ' . date('d/m/Y', strtotime($ref['fecha'])) . '.'
                : 'El precio de UN kilo, no de la tonelada.';
        }

        $hechos = [];
        if (isset($slots['lote_id'])) {
            foreach ($lotes as $l) if ((int)$l['id'] === $slots['lote_id']) $hechos[] = $l['nombre'];
        }
        if (isset($slots['cultivo_nombre'])) $hechos[] = $slots['cultivo_nombre'];
        if (isset($slots['kg']))             $hechos[] = number_format($slots['kg'], 0, ',', '.') . ' kg';
        if (isset($slots['precio_kg']))      $hechos[] = '$' . number_format($slots['precio_kg'], 2, ',', '.') . '/kg';

        return [
            'ok' => true, 'tipo' => 'alta_paso',
            'respuesta' => ($aviso ? $aviso . ' ' : '') . $paso['pregunta'],
            'detalle' => trim(($hechos ? 'Vamos con: ' . implode(' · ', $hechos) . '. ' : '') . $paso['ayuda']),
            'valor' => null,
            'alta_pendiente' => $slots,
            'filtros' => [], 'link' => null,
            'sugerencias' => $ops,
        ];
    }

    $camp    = $slots['campania'] ?? $campania;
    $ingreso = $slots['kg'] * $slots['precio_kg'];

    $nombreLote = '';
    foreach ($lotes as $l) if ((int)$l['id'] === $slots['lote_id']) $nombreLote = $l['nombre'];

    $lineas = [
        'Lote: '    . $nombreLote,
        'Cultivo: ' . ($slots['cultivo_nombre'] ?? ''),
        'Campaña: ' . $camp,
        'Kilos: '   . number_format($slots['kg'], 2, ',', '.'),
        'Precio: '  . motor_formatear($slots['precio_kg'], 'dinero') . ' por kilo',
        // El ingreso lo calcula la base sola; acá se muestra para que lo reconozca.
        'Ingreso: ' . motor_formatear($ingreso, 'dinero'),
        'Fecha: '   . date('d/m/Y', strtotime($slots['fecha_venta'])),
    ];

    /* Si el precio se aparta mucho de la referencia, se avisa. No se bloquea: el
       productor puede haber vendido a otro precio, con otra calidad o en otra
       fecha. Pero un factor de mil no es un buen negocio, es un cero de más. */
    if ($ref && $ref['precio_kg'] > 0) {
        $r = $slots['precio_kg'] / $ref['precio_kg'];
        if ($r >= 10 || $r <= 0.1) {
            $lineas[] = '⚠ Ojo: la Cámara pagaba $' . number_format($ref['precio_kg'], 2, ',', '.')
                      . ' el kilo. Fijate que no sea el precio por tonelada.';
        }
    }

    return [
        'ok' => true, 'tipo' => 'propuesta_alta',
        'respuesta' => 'Listo. Confirmame que está bien:',
        'detalle' => implode("\n", $lineas),
        'valor' => $ingreso,
        'alta' => [
            'que'         => 'venta',
            // Igual que el gasto: la moneda que se está mirando, y a la vista en
            // la confirmación con su símbolo.
            'moneda'      => motor_moneda(),
            'lote_id'     => (int)$slots['lote_id'],
            'cultivo_id'  => (int)$slots['cultivo_id'],
            'campania'    => $camp,
            'kg'          => $slots['kg'],
            'precio_kg'   => $slots['precio_kg'],
            'fecha_venta' => $slots['fecha_venta'],
        ],
        'alta_pendiente' => null,
        'filtros' => [], 'link' => null, 'sugerencias' => [],
    ];
}

/**
 * La última cotización de la Cámara para un cultivo, pasada a pesos por KILO.
 *
 * La tabla la guarda por tonelada, que es como la publica la Cámara. Se divide acá
 * y no en quien llama para que no haya dos lugares donde equivocarse con el mil.
 */
function motor_precio_referencia(PDO $pdo, string $cultivo): ?array {
    try {
        /* El nombre en cotizaciones trae el sufijo de la pizarra ("Trigo Cámara"),
           así que se busca por prefijo contra el cultivo del productor. */
        $st = $pdo->prepare(
            "SELECT cultivo, precio_promedio, fecha
               FROM cotizaciones_siogranos
              WHERE cultivo LIKE CONCAT(?, '%') AND moneda = 'ARS' AND precio_promedio > 0
              ORDER BY fecha DESC LIMIT 1"
        );
        $st->execute([$cultivo]);
        $row = $st->fetch();
        if (!$row) return null;
        return [
            'cultivo'   => $row['cultivo'],
            'precio_kg' => (float)$row['precio_promedio'] / 1000,
            'fecha'     => $row['fecha'],
        ];
    } catch (Throwable $e) {
        return null;   // sin referencia se pregunta igual, sólo sin la ayuda
    }
}

/* =====================================================================
   PRECIO DE PIZARRA Y CUÁNTA PLATA DEJA LA HECTÁREA

   La cotización ya se bajaba sola —cron/get_siogranos.php trae la pizarra de
   Rosario— y sólo se usaba para avisar de un error de escala al cargar una
   venta. Acá se la cruza con los números del productor.

   Lo que se contesta es CUÁNTO QUEDA por hectárea a este precio, no si le
   conviene vender. La diferencia no es de redacción: si le conviene depende de
   su caja, de si espera mejor precio y de cuánto le cuesta guardar el grano,
   que son cosas que el sistema no sabe. La cuenta sí la sabe hacer, y con la
   cuenta a la vista la decisión la toma él.
   ===================================================================== */

/* =====================================================================
   REPORTES

   Los dos endpoints de exportación ya existían y ya aceptaban campaña, etapa y
   lote; lo único que faltaba era que Cafrita supiera armar el link. Estaba
   anotado en motor_consultas_fallidas como "dame el reporte de la campaña".

   La respuesta dice qué trae el archivo y cuánto suma ANTES de descargarlo: si
   el recorte salió mal, se ve en el número y no después de abrir la planilla.
   ===================================================================== */

function motor_pide_reporte(string $t): bool {
    foreach (['reporte','reportes','informe','informes','excel','planilla',
              'exportar','exportame','descargar','descargame','bajar el',
              'pasame el', 'pasame la', 'en pdf', 'un pdf', 'xls'] as $p) {
        if (strpos($t, $p) !== false) return true;
    }
    return false;
}

/** Excel si lo pidió; si no, PDF, que es lo que uno espera de "un reporte". */
function motor_reporte_es_excel(string $t): bool {
    foreach (['excel','planilla','xls','hoja de calculo','csv'] as $p) {
        if (strpos($t, $p) !== false) return true;
    }
    return false;
}

function motor_pide_precio(string $t): bool {
    /* Pide lenguaje de mercado a propósito. "cuánto gano" a secas es sinónimo
       de margen neto y lo tiene que seguir atendiendo la rama de métricas; acá
       sólo entra lo que habla del precio o de vender a precio de hoy. */
    foreach (['a cuanto esta','a cuanto se esta','cotizacion','cotizaciones','pizarra',
              'precio de mercado','precio de la tonelada','a como esta',
              'si vendo','si vendiera','si vendemos','si lo vendo','vender ahora',
              'a precio de hoy','precio de hoy','cuanto vale el','cuanto vale la',
              'a cuanto pagan','cuanto pagan por'] as $p) {
        if (strpos($t, $p) !== false) return true;
    }
    return false;
}

/**
 * "el" o "la" según el grano.
 *
 * El género no está en la base y no se puede deducir de la terminación —soja
 * termina en a y trigo en o, pero cebada y avena también son femeninas mientras
 * que maíz es masculino—, así que va la lista de las femeninas y el resto toma
 * "el". Decir "el soja" arruina una frase que por lo demás está bien.
 */
function motor_articulo_cultivo(string $especie): string {
    $femeninas = ['soja', 'cebada', 'avena', 'colza', 'quinoa', 'alfalfa', 'lenteja', 'arveja'];
    return in_array(motor_normalizar($especie), $femeninas, true) ? 'la' : 'el';
}

/**
 * El cultivo nombrado en la pregunta, si es alguno de los que tienen pizarra.
 *
 * La lista sale de la tabla y no de un arreglo escrito acá: si mañana la Cámara
 * publica otro grano, se reconoce solo sin tocar código.
 */
function motor_cultivo_cotizable(PDO $pdo, string $t): ?string {
    try {
        $filas = $pdo->query(
            "SELECT DISTINCT cultivo FROM cotizaciones_siogranos WHERE precio_promedio > 0"
        )->fetchAll(PDO::FETCH_COLUMN);
    } catch (Throwable $e) {
        return null;
    }
    foreach ($filas as $c) {
        // "Trigo Cámara" → "trigo": la pizarra le agrega el sufijo, el productor no.
        $base = motor_normalizar(explode(' ', trim($c))[0]);
        if ($base !== '' && strpos($t, $base) !== false) return $base;
    }
    return null;
}

/* =====================================================================
   "¿Y ESO EN DÓLARES?"

   El productor mira un número y quiere el mismo número en la otra moneda. Es
   una pregunta que aparece sola en la charla, sin repetir de qué se hablaba.

   LA CUENTA NO SE HACE SOBRE EL NÚMERO QUE YA SE MOSTRÓ. Convertir lo que está
   en pantalla obligaría a dividir todo por una sola cotización, y el panel no
   hace eso: cada movimiento se pasa con el dólar de SU mes —un gasto de marzo a
   1.200 y uno de agosto a 1.500 no se pueden aplanar con el mismo número sin
   borrar justo lo que uno quiere ver mirando en dólares—. Así que lo que se
   hace es REHACER la consulta anterior en la otra moneda: la cuenta la vuelve a
   hacer la base, movimiento por movimiento, y el resultado coincide con el
   panel al peso. Si dividiéramos acá, el chat y el panel mostrarían dos números
   distintos para lo mismo, que es el error que este archivo viene evitando
   desde el principio.
   ===================================================================== */

/**
 * Saca de la frase en qué moneda la quiere leer.
 *
 * Devuelve [lo que queda de la frase, 'USD'|'ARS'|null]. Se separa en vez de
 * sólo detectar porque el resto tiene que seguir su camino: "cuánto gasté en
 * dólares" es la pregunta del gasto, y con el "en dólares" adentro la rama del
 * desglose buscaba una categoría de gasto llamada "dolares" y contestaba que no
 * la tenía.
 *
 * El "en"/"a" adelante es obligatorio a propósito: sin eso, "¿a cuánto está el
 * dólar?" —que es una pregunta sobre la cotización, no sobre cómo leer un
 * total— entraría acá y saldría convertida.
 */
function motor_separar_moneda(string $t): array {
    $monedas = [
        'USD' => 'dolares|dolar|usd|u\$s|u\$d|verdes',
        // "pesos" en plural nada más: "en peso" en el campo puede ser peso seco.
        'ARS' => 'pesos|ars|moneda nacional',
    ];
    foreach ($monedas as $codigo => $alternativas) {
        // Sola, sin nada más: el productor escribe "dólares" y listo.
        if (preg_match('/^(?:' . $alternativas . ')$/u', $t)) return ['', $codigo];

        $re = '/\b(?:en|a|al)\s+(?:' . $alternativas . ')\b/u';
        if (preg_match($re, $t)) {
            $resto = preg_replace('/\s+/u', ' ', preg_replace($re, ' ', $t));
            return [trim($resto), $codigo];
        }
    }
    return [$t, null];
}

/**
 * Sacada la moneda, ¿quedó una pregunta o no quedó nada?
 *
 * "¿y eso en dólares?" deja "y eso", que no es una consulta: es pedir lo mismo
 * de recién. "cuánto gasté en dólares" deja "cuánto gasté", que sí lo es. La
 * diferencia decide entre rehacer la anterior y seguir de largo con la nueva.
 */
function motor_resto_es_relleno(string $t): bool {
    $relleno = 'y|e|o|eso|esto|ese|esa|numero|cifra|monto|lo|la|el|los|las|un|una'
             . '|mismo|misma|cuanto|cuanta|cuantos|es|seria|serian|son|da|daria'
             . '|pasa|pasalo|pasala|pasamelo|pasame|convertilo|convertila|converti'
             . '|ponelo|poneme|mostramelo|mostrame|mostra|dame|decime|deci|quiero'
             . '|verlo|ver|me|te|se|de|del|en|a|por|favor|porfa|dale|ahora|tambien';
    $sobra = preg_replace('/\b(?:' . $relleno . ')\b/u', ' ', $t);
    return trim(preg_replace('/\s+/u', ' ', $sobra)) === '';
}

/**
 * Rehace la consulta anterior en la moneda pedida.
 *
 * La pregunta previa la manda el cliente: el motor no guarda estado entre
 * llamadas y no tiene por qué, igual que con el resto del contexto de la charla.
 * Se la saca al contexto antes de volver a entrar para que dos cambios de moneda
 * seguidos no puedan quedar dando vueltas.
 */
function motor_misma_pregunta_en(PDO $pdo, int $uid, array $contexto, string $moneda): array {
    $previa = trim((string)($contexto['previa'] ?? ''));
    $nombre = $moneda === 'USD' ? 'dólares' : 'pesos';

    if ($previa === '') {
        return [
            'ok' => false, 'tipo' => 'sin_entender',
            'respuesta' => 'Todavía no te di ningún número para pasar a ' . $nombre . '.',
            'detalle' => 'Preguntame algo primero —"¿cuánto gasté?", "¿cuál es mi margen?"— y '
                       . 'después decime "y en ' . $nombre . '" y te lo paso.',
            'valor' => null,
            'filtros' => ['ciclo' => null, 'lote' => null, 'cultivo' => null, 'metrica' => null],
            'link' => null,
            'sugerencias' => ['¿Cuánto gasté?', '¿Cuál es mi margen neto?', '¿Cuál fue el costo por hectárea?'],
        ];
    }

    $ctx = $contexto;
    unset($ctx['previa']);
    $r = motor_responder($pdo, $uid, $previa, $ctx);

    /* De dónde sale el número convertido. Sin esto es un importe nuevo que
       apareció solo, y el productor no tiene forma de saber si se usó el dólar
       de hoy o el de cada movimiento. */
    $nota = $moneda === 'USD'
        ? 'Es el mismo número en dólares: cada movimiento va con la cotización de su mes, igual que el panel.'
        : 'Es el mismo número en pesos, con la cotización del mes de cada movimiento.';
    if (motor_dolar_estimado($pdo, $uid)) {
        $nota .= ' Ojo que no tenés ninguna cotización cargada, así que va con una estimada.';
    }
    $r['detalle'] = trim(($r['detalle'] ?? '') . ' ' . $nota);

    // Para que la próxima ("y en pesos") sepa qué rehacer, y no rehaga esto.
    $r['previa'] = $previa;
    return $r;
}

/** Pasa un importe en pesos a la moneda que se está mirando en el panel. */
function motor_a_moneda_actual(PDO $pdo, int $uid, float $ars): float {
    if (motor_moneda() !== 'USD') return $ars;
    dolar_asegurar_tabla($pdo);
    $ref = (float)dolar_referencia($pdo, $uid)['valor'];
    return $ref > 0 ? $ars / $ref : $ars;
}

/**
 * Pasa un importe que YA ESTÁ EN DÓLARES a la moneda que se está mirando.
 *
 * Es la inversa de motor_a_moneda_actual() y no da lo mismo cuál se use. El
 * catálogo de insumos guarda `precio_estimado_usd`: si se lo pasa por la
 * función de pesos, en pantalla queda un dólar mostrado como si fuera un peso
 * —mil veces menos— y en modo dólares se lo divide otra vez por la cotización.
 * Es el mismo error de mezclar monedas que costó el margen entero, sólo que
 * en chico.
 */
function motor_desde_usd(PDO $pdo, int $uid, float $usd): float {
    if (motor_moneda() === 'USD') return $usd;
    dolar_asegurar_tabla($pdo);
    $ref = (float)dolar_referencia($pdo, $uid)['valor'];
    return $ref > 0 ? $usd * $ref : $usd;
}

/**
 * ¿La conversión a dólares se está haciendo con una cotización inventada?
 *
 * Cuando no hay ninguna cargada, dolar_referencia devuelve un valor de respaldo
 * y lo marca estimado. El número convertido igual se muestra —es mejor que no
 * mostrar nada— pero decir de dónde sale es la diferencia entre una cuenta y
 * una cifra que parece firme y no lo es. Sólo aplica mirando en dólares: en
 * pesos no se convierte nada.
 */
function motor_dolar_estimado(PDO $pdo, int $uid): bool {
    if (motor_moneda() !== 'USD') return false;
    dolar_asegurar_tabla($pdo);
    return !empty(dolar_referencia($pdo, $uid)['estimado']);
}

/* =====================================================================
   INSUMOS: STOCK, PRECIO Y CONSUMO

   El catálogo de insumos existía desde el principio y el chat no lo miraba:
   se puede cargar stock, precio y vencimiento, y no había forma de preguntar
   nada de eso.

   El consumo se calcula con la MISMA cuenta con la que la aplicación descuenta
   y devuelve stock: cantidad por hectárea × hectáreas de la operación, con la
   superficie del lote como respaldo cuando la operación no las declara. Si acá
   se usara otra fórmula, "cuánto usé" no cerraría con lo que el stock bajó, y
   ese descuadre es imposible de explicarle a nadie.
   ===================================================================== */

/** El pedazo de SQL que convierte un renglón de insumo en cantidad consumida. */
function motor_sql_consumo(): string {
    return 'oi.cantidad_ha * COALESCE(o.hectareas, l.superficie)';
}

/**
 * Consumo de insumos abierto por lote.
 *
 * @param string|null $rubro   Filtra por insumos.tipo_insumo (semilla, etc.).
 * @param int|null    $insumo  Filtra por un insumo puntual del catálogo.
 */
function motor_consumo_por_lote(PDO $pdo, int $uid, string $ciclo,
                                ?string $rubro, ?int $insumo,
                                ?int $lote = null, ?string $cultivo = null): array {
    $c   = motor_sql_consumo();
    $sql = "SELECT l.nombre AS lote, l.id AS lote_id,
                   i.nombre AS insumo, i.unidad_medida AS unidad,
                   SUM($c) AS cantidad
              FROM operacion_insumos oi
              JOIN operaciones o ON oi.operacion_id = o.id
              JOIN lotes l       ON o.lote_id = l.id
              JOIN insumos i     ON oi.insumo_id = i.id
              LEFT JOIN cultivos cu ON o.cultivo_id = cu.id
             WHERE o.usuario_id = ? AND o.campania_operacion = ?";
    $p = [$uid, $ciclo];
    if ($rubro)  { $sql .= " AND i.tipo_insumo = ?"; $p[] = $rubro; }
    if ($insumo) { $sql .= " AND i.id = ?";          $p[] = $insumo; }
    if ($lote)   { $sql .= " AND o.lote_id = ?";     $p[] = $lote; }
    /* Mismo recorte por cultivo que getGlobalStats: la especie puede estar en la
       ficha del cultivo o escrita en la operación, y hay que mirar las dos. */
    if ($cultivo) {
        $sql .= " AND COALESCE(NULLIF(cu.nombre, ''), NULLIF(o.cultivo_operacion, ''), 'Sin Especificar')"
              . " COLLATE utf8mb4_unicode_ci = ?";
        $p[] = $cultivo;
    }
    /* Se agrupa también por insumo: dos semillas distintas en el mismo lote no
       se pueden sumar en un solo número sin decir cuáles son. */
    $sql .= " GROUP BY l.id, i.id HAVING cantidad > 0 ORDER BY l.nombre, cantidad DESC";

    $st = $pdo->prepare($sql);
    $st->execute($p);
    return $st->fetchAll();
}

/**
 * El precio que se PAGÓ de verdad por cada insumo, de los renglones.
 *
 * No es el del catálogo: ése es una referencia que se carga a mano y queda
 * vieja. Éste sale de operacion_insumos.precio_unitario, o sea de lo que se
 * escribió al registrar cada compra.
 *
 * Se devuelve el promedio ponderado por cantidad —no el promedio simple— porque
 * comprar mil kilos a un precio y diez a otro no son dos precios que valgan lo
 * mismo. Y van también el mínimo y el máximo: si compró al doble en dos
 * momentos del año, el promedio solo lo escondería.
 */
function motor_precio_pagado_insumo(PDO $pdo, int $uid, string $ciclo,
                                    ?string $rubro, ?int $insumo,
                                    ?int $lote = null, ?string $cultivo = null): array {
    $c   = motor_sql_consumo();
    $sql = "SELECT i.nombre AS insumo, i.unidad_medida AS unidad,
                   SUM($c) AS cantidad,
                   SUM($c * oi.precio_unitario) AS gastado,
                   MIN(oi.precio_unitario) AS minimo,
                   MAX(oi.precio_unitario) AS maximo,
                   COUNT(*) AS compras
              FROM operacion_insumos oi
              JOIN operaciones o ON oi.operacion_id = o.id
              JOIN lotes l       ON o.lote_id = l.id
              JOIN insumos i     ON oi.insumo_id = i.id
              LEFT JOIN cultivos cu ON o.cultivo_id = cu.id
             WHERE o.usuario_id = ? AND o.campania_operacion = ?
               AND oi.precio_unitario > 0";
    $p = [$uid, $ciclo];
    if ($rubro)  { $sql .= " AND i.tipo_insumo = ?"; $p[] = $rubro; }
    if ($insumo) { $sql .= " AND i.id = ?";          $p[] = $insumo; }
    if ($lote)   { $sql .= " AND o.lote_id = ?";     $p[] = $lote; }
    if ($cultivo) {
        $sql .= " AND COALESCE(NULLIF(cu.nombre, ''), NULLIF(o.cultivo_operacion, ''), 'Sin Especificar')"
              . " COLLATE utf8mb4_unicode_ci = ?";
        $p[] = $cultivo;
    }
    $sql .= " GROUP BY i.id HAVING cantidad > 0 ORDER BY gastado DESC";

    $st = $pdo->prepare($sql);
    $st->execute($p);
    return $st->fetchAll();
}

/** El catálogo de insumos con stock y precio. */
function motor_stock_insumos(PDO $pdo, int $uid, ?string $rubro, ?int $insumo): array {
    $sql = "SELECT i.id, i.nombre, i.tipo_insumo, i.unidad_medida AS unidad,
                   i.stock_actual, i.stock_minimo, i.precio_estimado_usd AS precio,
                   i.fecha_vencimiento, d.nombre AS deposito
              FROM insumos i
              LEFT JOIN depositos d ON i.deposito_id = d.id
             WHERE i.usuario_id = ? AND i.estado = 'activo'";
    $p = [$uid];
    if ($rubro)  { $sql .= " AND i.tipo_insumo = ?"; $p[] = $rubro; }
    if ($insumo) { $sql .= " AND i.id = ?";          $p[] = $insumo; }
    $sql .= " ORDER BY i.nombre";

    $st = $pdo->prepare($sql);
    $st->execute($p);
    return $st->fetchAll();
}

/** El insumo del catálogo que la pregunta nombra, si hay alguno. */
function motor_insumo_nombrado(PDO $pdo, int $uid, string $texto): ?array {
    $st = $pdo->prepare("SELECT id, nombre, unidad_medida FROM insumos
                          WHERE usuario_id = ? AND estado = 'activo'");
    $st->execute([$uid]);
    $mejor = null; $largo = 0;
    foreach ($st->fetchAll() as $i) {
        /* Se compara el nombre entero y no palabra por palabra: "Glifosato" y
           "Glifosato Premium" son dos insumos distintos, y con el más largo que
           coincida se acierta el que nombró. */
        $n = motor_normalizar($i['nombre']);
        if ($n !== '' && strpos($texto, $n) !== false && mb_strlen($n) > $largo) {
            $mejor = $i; $largo = mb_strlen($n);
        }
    }
    return $mejor;
}

/**
 * Todos los lotes del productor, sin filtrar por campaña.
 *
 * La CARGA usa ésta y no getLotesDelCiclo(). Un lote entra en la lista de una
 * campaña sólo si ya tiene un cultivo, una operación o una venta de ese ciclo
 * —o si su ficha lo declara—, y eso está bien para consultar: se pregunta por
 * lo que hay. Pero para cargar está al revés. Cuando alguien registra el primer
 * gasto de la campaña, sus lotes todavía no tienen nada, así que decir "todos"
 * devolvía únicamente los que ya habían recibido algo, muchas veces uno solo, y
 * nombrar un lote sin actividad no lo encontraba.
 *
 * El formulario de Costos y Labores siempre ofreció todos los lotes; el chat era
 * el que quedaba corto.
 */
function motor_lotes_del_usuario(PDO $pdo, int $uid): array {
    $st = $pdo->prepare("SELECT id, nombre, superficie FROM lotes
                          WHERE usuario_id = ? ORDER BY nombre");
    $st->execute([$uid]);
    return $st->fetchAll();
}

/** Los cultivos de un lote, para imputar el alquiler igual que el formulario. */
function motor_cultivos_del_lote(PDO $pdo, int $uid, int $loteId): array {
    $st = $pdo->prepare(
        "SELECT id, nombre, ciclo FROM cultivos
          WHERE lote_id = ? AND usuario_id = ? ORDER BY ciclo DESC, nombre"
    );
    $st->execute([$loteId, $uid]);
    return $st->fetchAll();
}

/** El dólar de referencia, sólo para mostrar el equivalente. Nunca se guarda. */
function motor_dolar_referencia(PDO $pdo, int $uid): float {
    try {
        $st = $pdo->prepare(
            "SELECT dolar_mayorista FROM tambo_dolar_mes
              WHERE usuario_id = ? ORDER BY mes DESC LIMIT 1"
        );
        $st->execute([$uid]);
        return (float)($st->fetchColumn() ?: 0);
    } catch (Throwable $e) {
        return 0.0;   // sin cotización se muestra el monto solo, y ya
    }
}

/** El catálogo de insumos activos del productor, para ofrecerlo y poder vincularlo. */
function motor_insumos_catalogo(PDO $pdo, int $uid): array {
    $st = $pdo->prepare(
        "SELECT id, nombre, unidad_medida, stock_actual
           FROM insumos
          WHERE usuario_id = ? AND estado = 'activo'
          ORDER BY nombre"
    );
    $st->execute([$uid]);
    return $st->fetchAll();
}

/** El chip para cuando el insumo no está cargado. Es la opción manual del formulario. */
const MOTOR_INSUMO_LIBRE = 'No está en la lista';

/**
 * Las unidades del formulario de Insumos. En plural para nombrar la unidad y en
 * singular para el precio: "USD 6,50 por litros" está mal dicho, es "por litro".
 */
function motor_unidades(): array {
    return ['kg' => 'kilogramos', 'lt' => 'litros', 'dosis' => 'dosis', 'bolsa' => 'bolsas'];
}
function motor_unidades_singular(): array {
    return ['kg' => 'kilo', 'lt' => 'litro', 'dosis' => 'dosis', 'bolsa' => 'bolsa'];
}

/** La unidad nombrada, o null. Acepta cómo se dice y cómo se abrevia. */
function motor_detectar_unidad(string $texto): ?string {
    $syn = [
        'kg'    => ['kilogramos','kilogramo','kilos','kilo','kg'],
        'lt'    => ['litros','litro','lts','lt','l'],
        'dosis' => ['dosis'],
        'bolsa' => ['bolsas','bolsa'],
    ];
    foreach ($syn as $clave => $palabras) {
        foreach ($palabras as $p) {
            if (preg_match('/(^|\s)' . preg_quote($p, '/') . '(\s|$)/u', $texto)) return $clave;
        }
    }
    return null;
}

/** Los nombres de los lotes elegidos, en el orden en que están en el campo. */
function motor_alta_nombres_lotes(array $ids, array $lotes): array {
    $out = [];
    foreach ($lotes as $l) {
        if (in_array((int)$l['id'], array_map('intval', $ids), true)) $out[] = $l['nombre'];
    }
    return $out;
}

/**
 * La pregunta que sigue, o null si ya está todo.
 *
 * La usan los dos caminos de carga, y por eso está acá afuera: el paso a paso
 * arranca sin nada, y una frase suelta a la que le falta un dato —"cargá 44.000
 * de fertilizante en La rubia", sin etapa— entra por el mismo lugar con lo que ya
 * se entendió. Antes esa frase terminaba en un cartel de "me falta la etapa" sin
 * salida; ahora es simplemente el paso a paso empezado más adelante.
 */
function motor_alta_siguiente(array $slots, array $lotes, string $aviso = '', array $catalogo = []): ?array {
    foreach (motor_alta_pasos() as $campo => $paso) {
        if (isset($slots[$campo])) continue;
        if (!motor_alta_paso_aplica($paso, $slots)) continue;

        $ops = $paso['opciones'];

        if ($campo === 'insumo_nombre') {
            /* El catálogo como opciones, más la salida manual. Si ya tocó
               "No está en la lista" no se vuelven a ofrecer: quedó claro que va a
               escribir, y volver a mostrarle la lista es volver a preguntarle lo
               mismo. Sin catálogo tampoco hay chips que mostrar. */
            if (empty($slots['insumo_libre']) && $catalogo) {
                $ops = array_map(fn($i) => $i['nombre'], $catalogo);
                $ops[] = MOTOR_INSUMO_LIBRE;
            } else {
                $ops = [];
                $paso['ayuda'] = 'Escribime el nombre. Al no estar en tu catálogo, este gasto no descuenta stock.';
            }
        }

        if ($campo === 'insumo_cantidad') {
            // Con la unidad del catálogo la pregunta es concreta: "¿cuántos kilos?"
            $uni = isset($slots['insumo_unidad']) ? (motor_unidades()[$slots['insumo_unidad']] ?? '') : '';
            if ($uni !== '') {
                $paso['pregunta'] = '¿Cuántos ' . $uni . ' usaste en total?';
                $paso['ayuda']    = 'Con eso saco el precio por ' . motor_unidades_singular()[$slots['insumo_unidad']]
                                  . ' y descuento el stock.';
            } else {
                $paso['ayuda'] = 'La cantidad total, en la unidad que lo compres.';
            }
        }

        if ($campo === 'lotes') {
            $ops = array_map(fn($l) => $l['nombre'], $lotes);
            if (!$ops) {
                return [
                    'ok' => false, 'tipo' => 'falta_dato',
                    'respuesta' => 'Todavía no tenés lotes cargados, así que no puedo anotar el gasto.',
                    'detalle' => 'Creá un lote primero desde Lotes y Cultivos.',
                    'valor' => null, 'alta_pendiente' => null,
                    'filtros' => [], 'link' => 'lotes.php', 'sugerencias' => [],
                ];
            }
            // El atajo para el caso más común de una carga repartida, que si no
            // habría que escribir nombrando los lotes uno por uno.
            if (count($ops) > 1) $ops[] = 'Todos los lotes';
        }

        // Lo que ya se sabe, repetido en cada pregunta: sin esto, cinco preguntas
        // seguidas se sienten un interrogatorio en vez de un formulario.
        $hechos = [];
        if (isset($slots['grupo_gasto']))     $hechos[] = motor_grupos()[$slots['grupo_gasto']]['etiqueta'];
        if (isset($slots['tipo_componente'])) $hechos[] = $slots['tipo_componente'] === 'insumo' ? 'insumo' : 'mano de obra';
        /* No alcanza con isset: en un gasto de mano de obra estos dos casilleros
           se llenan vacíos —nombre '' y cantidad 0— y aparecían en el resumen
           como un renglón en blanco y un "0,00" sueltos. Se nota al volver sobre
           un paso, que es cuando el resumen se arma con todo ya cargado. */
        if (!empty($slots['insumo_nombre']))   $hechos[] = $slots['insumo_nombre'];
        if (!empty($slots['insumo_cantidad'])) {
            $u = isset($slots['insumo_unidad']) ? ' ' . (motor_unidades()[$slots['insumo_unidad']] ?? '') : '';
            $hechos[] = number_format($slots['insumo_cantidad'], 2, ',', '.') . $u;
        }
        if (!empty($slots['lotes'])) {
            $nn = motor_alta_nombres_lotes($slots['lotes'], $lotes);
            // Con muchos lotes la lista tapa la pregunta; se dice cuántos son.
            $hechos[] = count($nn) > 3 ? count($nn) . ' lotes' : implode(' y ', $nn);
        }
        if (isset($slots['costo_total']))     $hechos[] = motor_formatear($slots['costo_total'], 'dinero');

        return [
            'ok' => true, 'tipo' => 'alta_paso',
            // El aviso va PRIMERO y en la respuesta, no escondido en el detalle:
            // si no, se relee la misma pregunta sin entender por qué volvió.
            'respuesta' => ($aviso ? $aviso . ' ' : '') . $paso['pregunta'],
            // trim: hay pasos sin texto de ayuda, y sin esto quedaba un espacio colgando.
            'detalle' => trim(($hechos ? 'Vamos con: ' . implode(' · ', $hechos) . '. ' : '') . $paso['ayuda']),
            'valor' => null,
            'alta_pendiente' => $slots,   // el estado vuelve con la respuesta
            'filtros' => [], 'link' => null,
            'sugerencias' => $ops,
        ];
    }
    return null;
}

/**
 * ¿Está arrancando una carga guiada?
 *
 * Recibe el texto normalizado y el ORIGINAL: el monto sólo se puede buscar en el
 * original, porque la normalización parte "15.000" en "15 000" y el paso a paso
 * arrancaría igual para alguien que ya dijo todo de una.
 */
/**
 * ¿Es una pregunta de consejo y no una orden de cargar?
 *
 * "Pagué el alquiler" carga; "¿conviene pagar el alquiler mensual?" pregunta, y
 * las dos llevan "pagar" y "alquiler". El guardia de las altas miraba sólo las
 * palabras interrogativas —cuánto, cuál, qué— y estas frases no llevan ninguna,
 * así que arrancaban a cargar un alquiler. Empezar a escribir en la base cuando
 * alguien pidió una opinión es el peor error posible de esta función.
 */
function motor_es_consulta_de_consejo(string $t): bool {
    foreach (['conviene','convendria','convendría','me conviene','deberia','debería',
              'es mejor','sera mejor','será mejor','vale la pena','que me recomendas',
              'que me recomiendas','esta bien pagar','está bien pagar',
              'que te parece','esta bien el','está bien el'] as $p) {
        if (strpos($t, $p) !== false) return true;
    }
    return false;
}

/**
 * Temas sobre los que el sistema no tiene con qué opinar.
 *
 * Devuelve qué se preguntó y qué haría falta para poder contestarlo. Existe
 * porque el mal menor no es "no entendí": es que la pregunta caiga en otra rama
 * y vuelva con un número correcto de otra cosa. "¿Conviene pagar el alquiler a
 * porcentaje?" devolvía el porcentaje que el alquiler representa hoy sobre los
 * costos, que es un dato real y una respuesta equivocada.
 */
function motor_tema_sin_datos(string $t): ?array {
    if (preg_match('/(mensual|semestral|anual|adelantado|a porcentaje|porcentaje de produccion|quintal|quintales)/u', $t)
        && preg_match('/(alquiler|arrendamiento|renta|contrato|pagar|pago)/u', $t)) {
        return [
            'tema'  => 'cómo conviene pactar el alquiler',
            'falta' => 'Haría falta la tasa a la que podés colocar la plata, la inflación '
                     . 'esperada y cómo viene tu caja mes a mes. Nada de eso está cargado, y '
                     . 'sin eso cualquier respuesta sería una opinión disfrazada de cuenta.',
            'puedo' => 'Sí te puedo decir cuánto pagaste, qué porcentaje de tus costos '
                     . 'representa y cuánto deja por hectárea cada lote alquilado.',
        ];
    }
    if (preg_match('/(flete|fletes|acarreo|camion|camiones)/u', $t)) {
        return [
            'tema'  => 'el precio del flete',
            'falta' => 'El flete no se carga en ningún lado: las etapas de gasto son siembra, '
                     . 'cosecha, pulverización, fertilización y otros, así que un flete termina '
                     . 'mezclado dentro de "otros" y no hay forma de separarlo.',
            'puedo' => 'Si querés, se puede agregar el flete como etapa propia y ahí sí te digo '
                     . 'cuánto pagaste, cuánto pesa y cuánto te sale por tonelada.',
        ];
    }
    if (preg_match('/(embolsar|silo bolsa|silobolsa|acopio|almacenar|guardar el grano|almacenaje)/u', $t)) {
        return [
            'tema'  => 'si conviene guardar el grano o entregarlo',
            'falta' => 'Haría falta el costo de embolsado, el de acopio y la capacidad de silo '
                     . 'que tenés. Nada de eso se carga hoy.',
            'puedo' => 'Lo que sí te digo es a cuánto está el grano en la pizarra y cuánto te '
                     . 'deja la hectárea a ese precio, que es la mitad de esa cuenta.',
        ];
    }
    /* Se acepta lo que haya entre "cuándo"/"momento" y "vender": la gente dice
       "en qué momento conviene vender", "cuándo me conviene vender", y una lista
       de frases cerradas nunca las cubre todas. */
    if (preg_match('/(cuando|momento).{0,25}vend/u', $t)) {
        return [
            'tema'  => 'cuándo conviene vender',
            'falta' => 'Haría falta el precio a futuro y el costo de esperar. El sistema tiene '
                     . 'la pizarra de hoy, no la de dentro de tres meses, y adivinarla sería '
                     . 'inventar un número.',
            'puedo' => 'Te digo a cuánto está hoy, cuánto te deja la hectárea a ese precio y a '
                     . 'cuánto vendiste lo que ya entregaste.',
        ];
    }
    return null;
}

function motor_pide_alta_guiada(string $t, string $original = ''): ?string {
    // Una pregunta de consejo nunca es una orden de cargar.
    if (motor_es_consulta_de_consejo($t)) return null;
    /* La venta, el alquiler y el insumo se miran PRIMERO. "cargar un insumo"
       contiene "cargar", así que con el orden al revés todas esas altas arrancaban
       como si fueran un gasto. */
    if (preg_match('/(^|\s)(venta|ventas|entrega|entregue|vendi|cosecha vendida)(\s|$)/u', $t)) {
        if (preg_match('/(carga|cargar|anota|anotar|registra|registrar|dar de alta|nuevo|nueva|agrega|agregar)/u', $t)) {
            return 'venta';
        }
        /* "vendí" y "entregué" sirven para cargar y para preguntar. Igual que con
           "pagué": la palabra de pregunta decide. */
        if (preg_match('/(^|\s)(vendi|entregue)(\s|$)/u', $t)
            && !preg_match('/(^|\s)(cuanto|cuantos|cuantas|cual|cuales|que|como|donde|cuando)(\s|$)/u', $t)) {
            return 'venta';
        }
    }

    if (preg_match('/(^|\s)(alquiler|alquileres|arrendamiento|renta)(\s|$)/u', $t)) {
        // Órdenes: no tienen otra lectura.
        if (preg_match('/(carga|cargar|anota|anotar|registra|registrar|dar de alta|nuevo|nueva|agrega|agregar)/u', $t)) {
            return 'alquiler';
        }
        /* "pagué" sirve para las dos cosas: "pagué un alquiler" carga, "cuánto
           pagué de alquiler" pregunta. La palabra de pregunta la resuelve, igual
           que en motor_pide_alta(). El filtro va sólo acá y no arriba de todo,
           porque un "cargá el gasto QUE hice ayer" no deja de ser una orden. */
        if (preg_match('/(^|\s)(pague|pagar|pago)(\s|$)/u', $t)
            && !preg_match('/(^|\s)(cuanto|cuantos|cuantas|cual|cuales|que|como|donde|cuando)(\s|$)/u', $t)) {
            return 'alquiler';
        }
    }

    foreach (['insumo','insumos','producto al catalogo','al catalogo','al stock','a stock'] as $p) {
        if (preg_match('/(^|\s)' . preg_quote($p, '/') . '(\s|$)/u', $t)) {
            /* Salvo que sea un GASTO en insumos: "cargá 40.000 de insumos" es plata
               que salió, no un producto nuevo en el catálogo. El número lo decide. */
            if (motor_detectar_monto($original !== '' ? $original : $t) === null
                && preg_match('/(carga|cargar|anota|anotar|registra|registrar|dar de alta|crear|nuevo|nueva|agrega|agregar)/u', $t)) {
                return 'insumo';
            }
        }
    }

    foreach (['quiero cargar','cargar un costo','cargar un gasto','quiero anotar',
              'necesito cargar','dar de alta','cargar una operacion','anotar un gasto',
              'quiero registrar'] as $p) {
        if (strpos($t, $p) !== false) return 'gasto';
    }
    // "cargá un gasto" a secas, sin ningún dato: también es el arranque del paso a paso.
    if (preg_match('/^(carga|cargar|anota|anotar|registra|registrar)\b/u', $t)
        && motor_detectar_monto($original !== '' ? $original : $t) === null) {
        return 'gasto';
    }
    return null;
}

function motor_alta_cancelada(string $t): bool {
    foreach (['cancela','cancelar','dejalo','olvidate','no importa','nada','salir'] as $p) {
        if (preg_match('/(^|\s)' . preg_quote($p, '/') . '($|\s)/u', $t)) return true;
    }
    return false;
}

/* =====================================================================
   ANÁLISIS

   Todo lo demás del motor contesta lo que le preguntan. Esto dice lo que el
   productor NO preguntó pero le conviene saber.
 *
   Dos reglas que ordenan el resultado:

   1. Se ordena por lo que se puede hacer al respecto, no por el tamaño del
      número. "Ganaste $279.800" es grande y no sirve para nada; "estás 200 kg/ha
      del punto de equilibrio" es chico y cambia decisiones.

   2. Las advertencias sobre la CONFIANZA del dato van primero. Si el margen se
      calculó con un dólar estimado, eso hay que decirlo antes que cualquier
      conclusión — porque todas las demás se apoyan en él.

   Es aritmética, no adivinación: cada hallazgo sale de números que ya se
   calculan, comparados entre sí.
   ===================================================================== */

function motor_analisis(PDO $pdo, $ctrl, int $uid, string $campania, ?int $lote, ?string $cultivo): array {
    $h = [];   // cada hallazgo: ['p' => prioridad, 'txt' => texto]
    $s = $ctrl->getGlobalStats($campania, $lote, $cultivo);

    $ha  = (float)$s['hectareas'];
    $kg  = (float)$s['kg'];
    $ing = (float)$s['ingresos'];
    $cd  = (float)$s['costos_directos'];
    $ca  = (float)$s['costos_alquiler'];
    $mg  = (float)$s['margen_neto'];

    if ($ing == 0.0 && $cd == 0.0) return [];

    // ── 1. Confianza del dato, antes que cualquier conclusión ────────────────
    $sinCotiz = (int)($s['alquiler_sin_cotizacion'] ?? 0);
    if ($sinCotiz > 0) {
        $h[] = ['p' => 100, 'txt' => 'Ojo: ' . $sinCotiz . ' pago' . ($sinCotiz > 1 ? 's' : '')
            . ' de alquiler en pesos se convirtió a dólares sin la cotización de su mes, '
            . 'así que este margen es aproximado. Cargá el tipo de cambio de esos meses en Alquileres.'];
    }

    // ── 2. Rinde contra el punto de equilibrio ───────────────────────────────
    $pe = (float)$s['punto_equilibrio_kg_ha'];
    $rh = (float)$s['rendimiento_ha'];
    if ($pe > 0 && $rh > 0) {
        $margenKg = $rh - $pe;
        $holgura  = $margenKg / $pe;
        if ($margenKg < 0) {
            $h[] = ['p' => 95, 'txt' => 'Estás ' . number_format(abs($margenKg), 0, ',', '.')
                . ' kg/ha POR DEBAJO del rinde de indiferencia: con estos números la campaña da pérdida.'];
        } elseif ($holgura < 0.15) {
            $h[] = ['p' => 80, 'txt' => 'Estás al filo: apenas '
                . number_format($margenKg, 0, ',', '.') . ' kg/ha por encima del punto de equilibrio ('
                . number_format($pe, 0, ',', '.') . ' kg/ha). Poco margen para una mala cosecha.'];
        } else {
            $h[] = ['p' => 30, 'txt' => 'Vas ' . number_format($margenKg, 0, ',', '.')
                . ' kg/ha por encima del punto de equilibrio, un ' . number_format($holgura * 100, 0, ',', '.')
                . '% de colchón.'];
        }
    }

    // ── 3. Contra la campaña anterior ────────────────────────────────────────
    $ciclos = $ctrl->getCiclos();
    $i = array_search($campania, $ciclos, true);
    if ($i !== false && isset($ciclos[$i + 1])) {
        $ant = $ciclos[$i + 1];
        $sa  = $ctrl->getGlobalStats($ant);
        $cphA = (float)$s['costo_por_ha'];
        $cphB = (float)$sa['costo_por_ha'];
        $mgB  = (float)$sa['margen_neto'];

        if ($cphB > 0 && $cphA > 0) {
            $varC = (($cphA - $cphB) / $cphB) * 100;
            $varM = $mgB != 0.0 ? (($mg - $mgB) / abs($mgB)) * 100 : null;

            /* Lo importante no es que el costo haya subido, sino si subió MÁS que
               el resultado. Ahí es cuando un buen margen esconde una campaña
               menos eficiente que la anterior. */
            if ($varM !== null && $varC > 20 && $varM > 0 && $varC > $varM) {
                $h[] = ['p' => 85, 'txt' => 'Cuidado con el costo: subió '
                    . number_format($varC, 0, ',', '.') . '% contra ' . $ant
                    . ', más que el margen (' . number_format($varM, 0, ',', '.')
                    . '%). El resultado mejoró por precio, no por eficiencia.'];
            } elseif (abs($varC) > 20) {
                $h[] = ['p' => 55, 'txt' => 'El costo por hectárea ' . ($varC > 0 ? 'subió ' : 'bajó ')
                    . number_format(abs($varC), 0, ',', '.') . '% contra ' . $ant . '.'];
            }
            if ($varM !== null && abs($varM) > 15) {
                $h[] = ['p' => 50, 'txt' => 'El margen ' . ($varM > 0 ? 'mejoró ' : 'cayó ')
                    . number_format(abs($varM), 0, ',', '.') . '% contra ' . $ant . '.'];
            }
        }
    }

    // ── 4. Concentración del gasto ───────────────────────────────────────────
    $filas = motor_ranking_grupos($pdo, $uid, $campania, $lote, $cultivo);
    $totG = array_sum(array_column($filas, 'total'));
    if ($totG > 0 && $filas) {
        $pct = ((float)$filas[0]['total'] / $totG) * 100;
        if ($pct >= 45) {
            $et = motor_grupos()[$filas[0]['g']]['etiqueta'] ?? $filas[0]['g'];
            $h[] = ['p' => 60, 'txt' => number_format($pct, 0, ',', '.') . '% de tus costos se fue en '
                . $et . ' (' . motor_formatear($filas[0]['total'], 'dinero')
                . '). Es donde más hay para negociar.'];
        }
    }

    // ── 5. Diferencia entre lotes ────────────────────────────────────────────
    if ($lote === null) {
        $lotes = $ctrl->getLotesDelCiclo($campania);
        if (count($lotes) >= 2) {
            $r = [];
            foreach ($lotes as $l) {
                $sl = $ctrl->getGlobalStats($campania, (int)$l['id'], $cultivo);
                $hal = (float)$sl['hectareas'];
                if ($hal > 0) $r[] = ['n' => $l['nombre'], 'v' => (float)$sl['margen_neto'] / $hal];
            }
            if (count($r) >= 2) {
                usort($r, fn($a, $b) => $b['v'] <=> $a['v']);
                $mejor = $r[0]; $peor = end($r);
                if ($mejor['v'] != 0.0 && abs($mejor['v'] - $peor['v']) / max(abs($mejor['v']), 0.01) > 0.30) {
                    $h[] = ['p' => 65, 'txt' => 'Tus lotes rinden muy distinto: ' . $mejor['n'] . ' deja '
                        . motor_formatear($mejor['v'], 'dinero') . '/ha y ' . $peor['n'] . ' '
                        . motor_formatear($peor['v'], 'dinero') . '/ha. Vale mirar qué cambia entre uno y otro.'];
                }
            }
        }
    }

    usort($h, fn($a, $b) => $b['p'] <=> $a['p']);
    return $h;
}

/** ¿Pide un análisis general? */
function motor_pide_analisis(string $t): bool {
    foreach (['como viene','como va','como voy','como me fue','como estoy','analiza','analisis',
              'resumen','resumime','que tal la campania','que tal la campana','como viene la campania',
              'algo para destacar','que ves'] as $p) {
        if (strpos($t, $p) !== false) return true;
    }
    return false;
}

/* =====================================================================
   LLUVIA

   Ojo con esto: es el único dato del motor que NO sale de la base del
   productor. Viene del archivo histórico de Open-Meteo para las coordenadas
   del lote. No es inventado —es una medición real— pero tampoco es "tu dato",
   así que la respuesta dice de dónde sale.

   Consecuencias que el resto del motor no tiene: necesita internet, tarda, y
   puede fallar. Todo eso se maneja diciéndolo, nunca devolviendo un número
   dudoso como si fuera firme.
   ===================================================================== */

function motor_pide_lluvia(string $t): bool {
    foreach (['lluvia','llovio','llovió','llover','precipitacion','precipitaciones',
              'regimen de lluvia','cuanta agua','milimetros','mm de agua'] as $p) {
        if (strpos($t, $p) !== false) return true;
    }
    return false;
}

/**
 * Milímetros caídos en un lote y período.
 *
 * @return array|null  ['mm','dias_con_lluvia','desde','hasta'] o null si falló.
 */
/**
 * Trae y decodifica un JSON de Open-Meteo.
 *
 * Timeout corto y explícito: es un chat, no puede quedarse colgado esperando a
 * un servicio de afuera. Si no contesta rápido, se avisa y listo. Devuelve null
 * ante cualquier problema —sin internet, servicio caído, respuesta rota— y el
 * que llama tiene que decirlo, nunca inventar un número de reemplazo.
 */
function motor_meteo_json(string $url): ?array {
    $ctx = stream_context_create([
        'http' => ['timeout' => 6, 'ignore_errors' => true],
        'ssl'  => ['verify_peer' => false, 'verify_peer_name' => false],
    ]);
    $raw = @file_get_contents($url, false, $ctx);

    if ($raw === false && function_exists('curl_init')) {
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url, CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 6, CURLOPT_SSL_VERIFYPEER => false,
        ]);
        $raw = curl_exec($ch);
        curl_close($ch);
    }
    if (!$raw) return null;

    $d = json_decode($raw, true);
    return is_array($d) ? $d : null;
}

function motor_lluvia(array $lote, string $desde, string $hasta): ?array {
    $lat = $lote['latitud']  ?? null;
    $lng = $lote['longitud'] ?? null;
    if (!$lat || !$lng) return null;

    $url = 'https://archive-api.open-meteo.com/v1/archive'
         . '?latitude=' . rawurlencode((string)$lat)
         . '&longitude=' . rawurlencode((string)$lng)
         . '&start_date=' . rawurlencode($desde)
         . '&end_date=' . rawurlencode($hasta)
         . '&daily=precipitation_sum&timezone=UTC';

    $d = motor_meteo_json($url);
    if ($d === null) return null;

    $serie = $d['daily']['precipitation_sum'] ?? null;
    if (!is_array($serie)) return null;

    $mm = 0.0; $dias = 0;
    foreach ($serie as $v) {
        $v = (float)$v;
        $mm += $v;
        if ($v >= 1.0) $dias++;   // por debajo de 1 mm es rocío, no lluvia útil
    }
    return ['mm' => $mm, 'dias' => $dias, 'desde' => $desde, 'hasta' => $hasta];
}

/* =====================================================================
   CLIMA ACTUAL Y PRONÓSTICO

   Mismo origen y mismas advertencias que la lluvia: es de afuera, necesita
   internet y puede fallar. La diferencia es el tiempo verbal, y por eso son
   dos cosas distintas: "cuánto llovió" mira el archivo histórico, "va a
   llover" mira el pronóstico. Preguntar por el futuro y recibir el pasado es
   la peor respuesta posible, porque el número parece bueno.

   Se elige qué mostrar por lo que se hace con el dato: el viento decide si se
   puede pulverizar, la mínima si hay que preocuparse por una helada, y los
   milímetros de los próximos días si conviene entrar al lote o esperar.
   ===================================================================== */

function motor_pide_clima(string $t): bool {
    /* Va ANTES que motor_pide_lluvia en el router: "¿va a llover?" contiene
       "llover" y caería en la lluvia histórica, contestando con el pasado una
       pregunta sobre el futuro. */
    foreach (['clima','pronostico','pronóstico','que tiempo','qué tiempo',
              'tiempo hace','el tiempo en','temperatura','temperaturas',
              'va a llover','van a llover','llovera','lloverá','va a haber lluvia',
              'esta lloviendo','está lloviendo','viento','helada','heladas',
              'humedad','hace frio','hace frío','hace calor','grados'] as $p) {
        if (strpos($t, $p) !== false) return true;
    }
    /* Éstas se buscan como palabra entera y no por substring: "sol" vive dentro
       de "solo", "sólo" y "consolidado", y con strpos cualquier frase con esas
       palabras se iría a pedirle el clima a un servicio de afuera.
       Salieron de motor_consultas_fallidas: "hay sol en la rubia?" es una
       pregunta real que quedó sin contestar. */
    foreach (['sol','nublado','despejado','tormenta','granizo','niebla','llueve'] as $p) {
        if (preg_match('/(^|[^a-záéíóúñ])' . $p . '([^a-záéíóúñ]|$)/u', $t)) return true;
    }
    return false;
}

/** Códigos WMO de Open-Meteo a castellano. */
function motor_clima_texto(int $c): string {
    $m = [
        0 => 'despejado', 1 => 'mayormente despejado', 2 => 'parcialmente nublado',
        3 => 'nublado', 45 => 'con niebla', 48 => 'con niebla',
        51 => 'con llovizna', 53 => 'con llovizna', 55 => 'con llovizna',
        56 => 'con llovizna helada', 57 => 'con llovizna helada',
        61 => 'con lluvia leve', 63 => 'con lluvia', 65 => 'con lluvia fuerte',
        66 => 'con lluvia helada', 67 => 'con lluvia helada',
        71 => 'con nieve', 73 => 'con nieve', 75 => 'con nieve fuerte',
        77 => 'con nieve', 80 => 'con chaparrones', 81 => 'con chaparrones',
        82 => 'con chaparrones fuertes', 85 => 'con chaparrones de nieve',
        86 => 'con chaparrones de nieve', 95 => 'con tormenta',
        96 => 'con tormenta y granizo', 99 => 'con tormenta y granizo',
    ];
    return $m[$c] ?? 'sin datos de cielo';
}

/**
 * Estado actual y pronóstico a cuatro días para las coordenadas del lote.
 *
 * @return array|null  null si el lote no tiene ubicación o si el servicio falló.
 */
function motor_clima(array $lote): ?array {
    $lat = $lote['latitud']  ?? null;
    $lng = $lote['longitud'] ?? null;
    if (!$lat || !$lng) return null;

    /* timezone=auto: las horas y los cortes de día vienen en la hora del lote,
       no en UTC. Sin eso "mañana" puede referirse a otro día. */
    $url = 'https://api.open-meteo.com/v1/forecast'
         . '?latitude=' . rawurlencode((string)$lat)
         . '&longitude=' . rawurlencode((string)$lng)
         . '&current=temperature_2m,relative_humidity_2m,precipitation,weather_code,wind_speed_10m'
         . '&daily=weather_code,temperature_2m_max,temperature_2m_min,precipitation_sum,precipitation_probability_max'
         . '&forecast_days=4&timezone=auto';

    $d = motor_meteo_json($url);
    if ($d === null) return null;

    $ahora = $d['current'] ?? null;
    $dia   = $d['daily']   ?? null;
    if (!is_array($ahora) || !isset($ahora['temperature_2m'])) return null;

    $dias = [];
    if (is_array($dia) && !empty($dia['time'])) {
        foreach ($dia['time'] as $i => $fecha) {
            $dias[] = [
                'fecha' => $fecha,
                'max'   => isset($dia['temperature_2m_max'][$i]) ? (float)$dia['temperature_2m_max'][$i] : null,
                'min'   => isset($dia['temperature_2m_min'][$i]) ? (float)$dia['temperature_2m_min'][$i] : null,
                'mm'    => isset($dia['precipitation_sum'][$i]) ? (float)$dia['precipitation_sum'][$i] : 0.0,
                'prob'  => isset($dia['precipitation_probability_max'][$i]) ? (int)$dia['precipitation_probability_max'][$i] : null,
                'desc'  => motor_clima_texto((int)($dia['weather_code'][$i] ?? -1)),
            ];
        }
    }

    return [
        'temp'    => (float)$ahora['temperature_2m'],
        'humedad' => isset($ahora['relative_humidity_2m']) ? (int)$ahora['relative_humidity_2m'] : null,
        'viento'  => isset($ahora['wind_speed_10m']) ? (float)$ahora['wind_speed_10m'] : null,
        'mm_ahora'=> isset($ahora['precipitation']) ? (float)$ahora['precipitation'] : 0.0,
        'desc'    => motor_clima_texto((int)($ahora['weather_code'] ?? -1)),
        'dias'    => $dias,
    ];
}

/* =====================================================================
   CAPA CONVERSACIONAL

   Saludar, agradecer, despedirse y llamar a la gente por su nombre. Es lo que
   separa una caja de búsqueda de algo con lo que uno habla.

   Cálido pero no infantil: el PRODUCT.md tiene los emojis y lo caricaturesco
   como anti-referencia, y con razón — esto maneja plata. La calidez va en cómo
   está redactado, no en decoración.

   Y un saludo sin dato es cortesía vacía: cuando hay números cargados, el "hola"
   viene con el margen de la campaña. Saluda Y sirve.
   ===================================================================== */

/** Nombre preferido del productor. Por defecto, el de la sesión. */
function motor_nombre(PDO $pdo, int $uid): ?string {
    try {
        $stmt = $pdo->prepare("SELECT nombre FROM motor_preferencias WHERE usuario_id = ? LIMIT 1");
        $stmt->execute([$uid]);
        $n = $stmt->fetchColumn();
        if ($n) return $n;
    } catch (Throwable $e) {
        // Tabla todavía inexistente: se cae al nombre de la sesión.
    }
    $u = $_SESSION['username'] ?? null;
    // "admin" o "testuser" no son nombres de persona: mejor no llamar así a nadie.
    if (!$u || in_array(mb_strtolower($u), ['admin','test','testuser','usuario','user'], true)) return null;
    return $u;
}

function motor_guardar_nombre(PDO $pdo, int $uid, string $nombre): void {
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS motor_preferencias (
            usuario_id INT PRIMARY KEY,
            nombre VARCHAR(60) DEFAULT NULL,
            actualizado_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        $pdo->prepare("INSERT INTO motor_preferencias (usuario_id, nombre) VALUES (?, ?)
                       ON DUPLICATE KEY UPDATE nombre = VALUES(nombre)")
            ->execute([$uid, mb_substr($nombre, 0, 60)]);
    } catch (Throwable $e) {
        error_log('[motor] no se pudo guardar el nombre: ' . $e->getMessage());
    }
}

/** "llamame Alejo", "mi nombre es Alejo", "soy Alejo" */
function motor_detectar_nombre_propio(string $original): ?string {
    $patrones = [
        '/\bllamame\s+([\p{L}]{2,20})/u',
        '/\bmi\s+nombre\s+es\s+([\p{L}]{2,20})/u',
        '/\bme\s+llamo\s+([\p{L}]{2,20})/u',
        '/\bsoy\s+([\p{L}]{2,20})\b/u',
    ];
    foreach ($patrones as $p) {
        if (preg_match($p, $original, $m)) {
            $n = trim($m[1]);
            // "soy el dueño", "soy productor": no son nombres.
            $comunes = ['el','la','un','una','de','productor','dueno','dueño','yo','admin','usuario'];
            if (in_array(mb_strtolower($n), $comunes, true)) continue;
            return mb_convert_case($n, MB_CASE_TITLE, 'UTF-8');
        }
    }
    return null;
}

/**
 * La métrica más parecida a lo que escribió, aunque no llegue a coincidir.
 *
 * Es la diferencia entre "no te entendí" y "¿querías esto?". Cuando alguien
 * escribe algo que el motor no reconoce, tirarle la lista completa de lo que
 * sabe lo obliga a leer diez opciones; ofrecerle la más cercana lo saca del
 * problema en un clic. Y no hace falta un modelo: alcanza con la distancia de
 * edición contra el vocabulario que ya está declarado.
 */
function motor_metrica_cercana(string $texto): ?array {
    $mejor = null; $mejorDist = PHP_INT_MAX;
    $tokens = array_filter(explode(' ', $texto), fn($t) => mb_strlen($t) >= 4);
    if (!$tokens) return null;

    foreach (motor_metricas() as $clave => $m) {
        foreach ($m['sinonimos'] as $syn) {
            $s = motor_normalizar($syn);
            if (mb_strlen($s) < 4) continue;
            foreach ($tokens as $t) {
                $d = levenshtein($t, $s);
                // Se admite hasta un tercio de la palabra mal: más que eso ya no
                // es un error de tipeo, es otra cosa, y sugerirla sería adivinar.
                $tope = (int)max(2, floor(mb_strlen($s) / 3));
                if ($d <= $tope && $d < $mejorDist) {
                    $mejorDist = $d;
                    $mejor = ['clave' => $clave, 'etiqueta' => $m['etiqueta']];
                }
            }
        }
    }
    return $mejor;
}

/**
 * Con qué saludar.
 *
 * Si el productor saludó de una forma concreta, se le devuelve la misma: contestar
 * "buenas noches" a un "buen día" se lee como una corrección, aunque el reloj te
 * dé la razón. Sólo cuando dijo algo neutro ("hola") se elige según la hora.
 */
function motor_saludo_horario(string $texto = ''): string {
    if (strpos($texto, 'buen dia') !== false || strpos($texto, 'buenos dias') !== false) return 'Buen día';
    if (strpos($texto, 'buenas tardes') !== false) return 'Buenas tardes';
    if (strpos($texto, 'buenas noches') !== false) return 'Buenas noches';

    $h = (int)date('G');
    if ($h < 13) return 'Buen día';
    if ($h < 20) return 'Buenas tardes';
    return 'Buenas noches';
}

function motor_es_saludo(string $t): bool {
    foreach (['hola','buenas','buen dia','buenos dias','buenas tardes','buenas noches',
              'que tal','como andas','como estas','como va','holis','ey'] as $p) {
        if (preg_match('/(^|\s)' . preg_quote($p, '/') . '($|\s|,)/u', $t)) return true;
    }
    return false;
}

function motor_es_gracias(string $t): bool {
    foreach (['gracias','joya','barbaro','genial','perfecto','buenisimo','de diez','copado'] as $p) {
        if (strpos($t, $p) !== false) return true;
    }
    return false;
}

function motor_es_despedida(string $t): bool {
    foreach (['chau','adios','nos vemos','hasta luego','me voy','listo gracias','saludos'] as $p) {
        if (strpos($t, $p) !== false) return true;
    }
    return false;
}

function motor_pide_ayuda(string $t): bool {
    foreach (['que podes hacer','que sabes hacer','para que servis','quien sos','que sos',
              'ayuda','ayudame','como funciona','que puedo preguntarte','opciones'] as $p) {
        if (strpos($t, $p) !== false) return true;
    }
    return false;
}

/** Repreguntas cuando la charla se fue a un período. */
function motor_sugerencias_periodo(): array {
    return [
        '¿Cuánto vendí ese mes?',
        '¿Y el mes pasado?',
        '¿En qué gasté más?',
    ];
}

/**
 * Guarda las preguntas que el motor no supo contestar.
 *
 * Es la forma barata de dejar de mejorarlo a ciegas: en dos semanas de uso real
 * queda la lista de CÓMO pregunta un productor, y sumar esos sinónimos es
 * trivial. Sin esto uno adivina qué falta; con esto se lee.
 *
 * Nunca interrumpe la respuesta: si falla el guardado, el productor igual
 * recibe la suya.
 */
function motor_registrar_fallo(PDO $pdo, int $uid, string $pregunta, string $motivo): void {
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS motor_consultas_fallidas (
            id INT AUTO_INCREMENT PRIMARY KEY,
            usuario_id INT NOT NULL,
            pregunta VARCHAR(300) NOT NULL,
            motivo VARCHAR(120) DEFAULT NULL,
            veces INT NOT NULL DEFAULT 1,
            creado_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_u (usuario_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        $texto = mb_substr(trim($pregunta), 0, 300);
        if ($texto === '') return;

        // Si la misma pregunta ya falló antes, se cuenta en vez de duplicar: lo
        // que importa para priorizar es cuántas veces la piden, no cuántas filas hay.
        $stmt = $pdo->prepare(
            "SELECT id FROM motor_consultas_fallidas WHERE usuario_id = ? AND pregunta = ? LIMIT 1"
        );
        $stmt->execute([$uid, $texto]);
        $fila = $stmt->fetch();

        if ($fila) {
            $pdo->prepare("UPDATE motor_consultas_fallidas SET veces = veces + 1 WHERE id = ?")
                ->execute([$fila['id']]);
        } else {
            $pdo->prepare("INSERT INTO motor_consultas_fallidas (usuario_id, pregunta, motivo) VALUES (?, ?, ?)")
                ->execute([$uid, $texto, mb_substr($motivo, 0, 120)]);
        }
    } catch (Throwable $e) {
        error_log('[motor] no se pudo registrar la consulta fallida: ' . $e->getMessage());
    }
}

/**
 * Repreguntas del mundo del gasto, que es donde se sigue tirando del hilo.
 *
 * Una de cada clase a propósito: una etapa, un rubro del catálogo y el tipo de
 * componente. Son tres recortes distintos del mismo gasto y, si no se muestran,
 * el productor no tiene forma de enterarse de que existen.
 */
function motor_sugerencias_gasto(): array {
    return [
        '¿En qué gasté más?',
        '¿Cuánto gasté en fertilización?',
        '¿Cuánto gasté en semillas?',
        '¿Cuánto gasté en mano de obra?',
    ];
}

function motor_link(string $campania, ?int $loteId, ?string $cultivo): string {
    $q = array_filter([
        'ciclo'   => $campania,
        'lote'    => $loteId,
        'cultivo' => $cultivo,
    ], fn($v) => $v !== null && $v !== '');
    return 'index.php?' . http_build_query($q);
}

/**
 * Cuando no entiende.
 *
 * Si hay algo parecido en el vocabulario, se ofrece ESO —"¿querías el costo por
 * hectárea?"— en vez de la lista completa. Que alguien tenga que leer diez
 * opciones para descubrir que se comió una letra es la forma más rápida de que
 * deje de usar la función.
 */
function motor_sin_entender_cerca($ctrl, string $texto, ?string $nombre = null): array {
    $cerca = motor_metrica_cercana($texto);
    if ($cerca === null) return motor_sin_entender($ctrl, 'No pude reconocer qué número me estás pidiendo.');

    $voc = $nombre ? ', ' . $nombre : '';
    return [
        'ok' => false, 'tipo' => 'sin_entender',
        'respuesta' => 'Esa no la tengo' . $voc . '. ¿Querías el ' . $cerca['etiqueta'] . '?',
        'detalle' => 'Tocá abajo para verlo, o preguntame de otra forma.',
        'valor' => null, 'filtros' => [], 'link' => null,
        'sugerencias' => [
            '¿Cuál es el ' . $cerca['etiqueta'] . '?',
            '¿En qué gasté más?',
            '¿Qué podés hacer?',
        ],
    ];
}

/** Cuando no entiende, dice qué SÍ sabe contestar en vez de un "no encontrado". */
function motor_sin_entender($ctrl, string $motivo): array {
    $cat = motor_metricas();
    $ejemplos = [
        '¿Cuánto gasté en siembra?',
        '¿En qué gasté más?',
        '¿Cuál es mi margen neto?',
    ];
    // Se enumera lo que SÍ sabe: un "no entendí" a secas deja al productor
    // adivinando, y adivinar dos veces seguidas es dejar de usar la función.
    return [
        'ok' => false, 'tipo' => 'sin_entender',
        'respuesta' => $motivo,
        'detalle' => 'Puedo darte: ' . implode(', ', array_map(fn($m) => $m['etiqueta'], $cat)) . '. '
                   . 'Y desglosar el gasto por ' . motor_frase_categorias() . ', o por proveedor. '
                   . 'Cualquiera de esos números lo abro por lote o por cultivo. '
                   . 'También el clima y la lluvia de cada lote, el precio del grano, '
                   . 'el stock y el consumo de insumos, y el reporte o el Excel de cualquier recorte.',
        'valor' => null, 'filtros' => [], 'link' => null,
        'sugerencias' => $ejemplos,
    ];
}

/**
 * Las categorías de gasto, dichas como las nombra el producto.
 *
 * Van agrupadas y no en una lista corrida de trece palabras porque son tres cosas
 * distintas: la etapa y el tipo son campos del formulario de Costos y Labores, y
 * el rubro es del catálogo de Insumos. Enumerarlas todas juntas hacía que el
 * productor las buscara en el mismo desplegable.
 */
function motor_frase_categorias(): string {
    $etapas = implode(', ', array_map(fn($g) => $g['etiqueta'], motor_grupos()));
    $tipos  = implode(', ', array_map(fn($c) => $c['etiqueta'], motor_componentes()));
    $rubros = implode(', ', array_map(fn($r) => $r['etiqueta'], motor_tipos_insumo()));
    return 'etapa (' . $etapas . '), por tipo (' . $tipos . ') o por rubro del insumo (' . $rubros . ')';
}
