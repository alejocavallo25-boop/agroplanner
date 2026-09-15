<?php
/**
 * includes/motor_tambo.php
 *
 * Cafrita en el tambo.
 *
 * Mismas reglas que includes/motor.php, que conviene leer primero:
 *   - Nunca inventa un número. Mapea la pregunta a una métrica de un catálogo
 *     cerrado y la cuenta es tambo_stats(), la misma del panel y la comparativa.
 *     Si el chat y el panel dan distinto, está mal el chat.
 *   - Si no reconoce la pregunta lo dice, y la puerta la anota en
 *     motor_consultas_fallidas con "[tambo]" en el motivo.
 *
 * Es un motor aparte y no más ramas de motor.php a propósito. Aquel es una
 * cascada donde la primera rama que reconoce algo se queda con la pregunta, y
 * "¿cuánto gasté en alimentación?" o "¿cuál es mi margen?" existen en los dos
 * módulos: mezclarlos es contestar con seguridad el número del otro. Qué motor
 * contesta lo decide includes/chat_ruteo.php.
 *
 * De motor.php se reusan sólo las piezas de lenguaje que no leen tablas de
 * agricultura: normalizar, reconocer nombres, moneda, saludo, nombre del
 * productor. motor_detectar_meses() NO: resuelve el año mirando `operaciones`.
 *
 * Todo el tambo es mensual. Sin mes en la pregunta contesta sobre el último mes
 * con algo cargado, y lo nombra en la frase.
 *
 * Por ahora sólo consulta: las cargas siguen en los formularios.
 */

require_once __DIR__ . '/motor.php';
require_once __DIR__ . '/tambo_stats.php';
require_once __DIR__ . '/tambo_estructura.php';

const MOTOR_TAMBO_SERIE_MAX = 12;

/**
 * Catálogo de métricas. Los sinónimos van ya normalizados (sin acentos).
 * Gana el sinónimo más largo que aparezca: así "costo por litro" no se lo lleva
 * "costo", ni "litros por vaca" se lo lleva "litros".
 */
function motor_tambo_metricas(): array {
    return [
        'margen_bruto' => [
            'etiqueta' => 'margen bruto', 'formato' => 'dinero',
            'ars' => 'margen_bruto_ars', 'usd' => 'margen_bruto_usd',
            'sinonimos' => ['margen bruto','margen','ganancia','ganancias','cuanto gane','cuanto gano',
                            'cuanto gana','cuanto me quedo','cuanto me queda','cuanto perdi','cuanto pierdo',
                            'resultado','utilidad','cuanto deja','cuanto dejo'],
            'explicacion' => 'Lo que queda de los ingresos del mes (leche, otra leche y carne) después de restar todos los egresos.',
        ],
        'rentabilidad' => [
            'etiqueta' => 'rentabilidad', 'formato' => 'pct', 'clave' => 'rentabilidad',
            'sinonimos' => ['rentabilidad','porcentaje de margen','margen porcentual','% de margen','rentable'],
            'explicacion' => 'El margen bruto como porcentaje de los ingresos del mes.',
        ],
        'margen_litro' => [
            'etiqueta' => 'margen por litro', 'formato' => 'dinero_litro',
            'ars' => 'margen_litro_ars', 'usd' => 'margen_litro_usd',
            'sinonimos' => ['margen por litro','margen x litro','ganancia por litro','cuanto gano por litro',
                            'cuanto gane por litro','cuanto me queda por litro','cuanto deja el litro'],
            'explicacion' => 'El margen bruto del mes dividido por los litros producidos.',
        ],
        'litros' => [
            'etiqueta' => 'litros producidos', 'formato' => 'litros', 'clave' => 'litros',
            'sinonimos' => ['litros','cuantos litros','cuanta leche','produccion','produccion de leche',
                            'produje','cuanto produje','remiti','cuanto remiti','entregue','leche producida',
                            'litros producidos','litros del mes'],
            'explicacion' => 'Los litros del mes, sin contar la "otra leche".',
        ],
        'precio_litro' => [
            'etiqueta' => 'precio de la leche', 'formato' => 'dinero_litro',
            'sinonimos' => ['precio de la leche','precio del litro','precio leche','precio por litro',
                            'a cuanto me pagaron','a cuanto pagaron','a cuanto se pago','cuanto me pagan',
                            'cuanto pagan el litro','valor del litro','precio'],
            'explicacion' => 'El precio por litro de referencia del mes: el más alto cargado, igual que en el panel.',
        ],
        'ingresos' => [
            'etiqueta' => 'ingresos', 'formato' => 'dinero',
            'ars' => 'total_ingresos_ars', 'usd' => 'total_ingresos_usd',
            'sinonimos' => ['ingresos','ingreso','ingreso total','ingresos totales','cuanto facture','facturacion',
                            'facture','cuanto cobre','cuanto entro','cuanto vendi','ventas','venta'],
            'explicacion' => 'Leche, otra leche y carne del mes.',
        ],
        'ingreso_leche' => [
            'etiqueta' => 'ingreso por leche', 'formato' => 'dinero',
            'ars' => 'ingreso_leche_ars', 'usd' => 'ingreso_leche_usd',
            'sinonimos' => ['ingreso por leche','ingresos por leche','ingreso de leche','ingresos de leche',
                            'ingreso de la leche','venta de leche','cuanto cobre de leche','plata de la leche'],
            'explicacion' => 'Los litros del mes por su precio, sin la otra leche.',
        ],
        'ingreso_carne' => [
            'etiqueta' => 'ingreso por carne', 'formato' => 'dinero',
            'ars' => 'ingreso_carne_ars', 'usd' => 'ingreso_carne_usd',
            'sinonimos' => ['carne','ingreso por carne','ingresos por carne','ingreso de carne','ingresos de carne',
                            'venta de carne','diferencia de inventario','vacas vendidas','venta de hacienda'],
            'explicacion' => 'Venta de carne y diferencia de inventario, ya prorrateadas por mes como se cargaron.',
        ],
        'costos' => [
            'etiqueta' => 'costos', 'formato' => 'dinero',
            'ars' => 'costos_ars_total', 'usd' => 'costos_usd',
            'sinonimos' => ['costos','costo','costo total','costos totales','gastos','gasto','gaste','gastamos',
                            'egresos','egreso','cuanto gaste','cuanto se fue','cuanto pague','cuanto gasto'],
            'explicacion' => 'Todos los egresos del mes. Los cargados en dólares se pasan al dólar de ese mes.',
        ],
        'costo_litro' => [
            'etiqueta' => 'costo final por litro', 'formato' => 'dinero_litro',
            'ars' => 'costo_final_ars', 'usd' => 'costo_final_usd',
            'sinonimos' => ['costo por litro','costo x litro','costo del litro','costo final','costo final por litro',
                            'costo litro','cuanto me cuesta el litro','cuanto cuesta el litro','cuanto me sale el litro'],
            'explicacion' => 'Los costos del mes por litro, descontando lo que recuperan la carne y la otra leche.',
        ],
        'rinde_indiferencia' => [
            'etiqueta' => 'rinde de indiferencia', 'formato' => 'litros', 'clave' => 'rinde_indiferencia',
            'sinonimos' => ['rinde de indiferencia','indiferencia','punto de equilibrio','equilibrio','salir hecho',
                            'salir a raya','salgo hecho','cuantos litros necesito','litros para cubrir',
                            'para no perder'],
            'explicacion' => 'Los litros que hacen falta, al precio del mes, para cubrir los costos netos de lo que recuperan la carne y la otra leche.',
        ],
        'vacas' => [
            'etiqueta' => 'vacas en ordeñe', 'formato' => 'cabezas',
            'sinonimos' => ['vacas en ordene','vacas ordene','cuantas vacas','vacas','rodeo','vacas secas',
                            'vaquillonas','terneros','cabezas','hacienda'],
            'explicacion' => 'El último rodeo cargado hasta ese mes.',
        ],
        'litros_vaca' => [
            'etiqueta' => 'litros por vaca', 'formato' => 'litros_dia',
            'sinonimos' => ['litros por vaca','litros x vaca','litros vaca','produccion por vaca','por vaca',
                            'produccion individual','litros da cada vaca','cuanto da cada vaca','litros por dia por vaca'],
            'explicacion' => 'Los litros del mes divididos por las vacas en ordeñe y por los días del mes.',
        ],
        'calidad' => [
            'etiqueta' => 'calidad de la leche', 'formato' => 'calidad',
            'sinonimos' => ['calidad','calidad de la leche','grasa','proteina','celulas somaticas','celulas',
                            'rcs','ufc','bacterias','tenor graso'],
            'explicacion' => 'El último análisis cargado: grasa, proteína, células somáticas y bacterias.',
        ],
        'dolar' => [
            'etiqueta' => 'dólar del mes', 'formato' => 'dolar',
            'sinonimos' => ['dolar del mes','tipo de cambio','cotizacion','que dolar','el dolar'],
            'explicacion' => 'El dólar con el que se cierra el mes. Si el mes no tiene uno cargado, se usa el último que tengas.',
        ],
    ];
}

/** Las métricas que se pueden comparar entre meses o seguir en el tiempo. */
function motor_tambo_es_numerica(string $metrica): bool {
    return !in_array($metrica, ['calidad'], true);
}

/** ¿Está la frase en el texto como palabras enteras? */
function motor_tambo_literal(string $t, string $frase): bool {
    return $frase !== '' && preg_match('/(?<!\S)' . preg_quote($frase, '/') . '(?!\S)/u', $t) === 1;
}

function motor_tambo_detectar_metrica(string $t): ?string {
    /* Primero literal y recién después con tolerancia de tipeo, y la tolerancia
       sólo para vocabulario largo. Con palabras cortas el parecido es casualidad:
       "egresos" está a dos letras de "ingresos". */
    foreach ([false, true] as $difuso) {
        $mejor = null; $largo = 0;
        foreach (motor_tambo_metricas() as $clave => $m) {
            foreach ($m['sinonimos'] as $syn) {
                $n = mb_strlen($syn);
                if ($n <= $largo) continue;
                $ok = $difuso ? ($n >= 9 && motor_coincide($t, $syn)) : motor_tambo_literal($t, $syn);
                if ($ok) { $mejor = $clave; $largo = $n; }
            }
        }
        if ($mejor !== null) return motor_tambo_afinar_metrica($mejor, $t);
    }
    return null;
}

/**
 * El número general, afinado por lo que la frase dice al lado.
 * "¿cuánto vendí de carne?" pide la carne aunque el sinónimo que ganó sea "vendí".
 */
function motor_tambo_afinar_metrica(string $metrica, string $t): string {
    $dice = fn(string $p): bool => motor_tambo_literal($t, $p);
    if ($metrica === 'ingresos') {
        if ($dice('carne') || $dice('hacienda') || strpos($t, 'inventario') !== false) return 'ingreso_carne';
        if ($dice('leche')) return 'ingreso_leche';
    }
    $porLitro = $dice('por litro') || $dice('x litro') || $dice('el litro') || $dice('del litro');
    if ($metrica === 'costos' && $porLitro)       return 'costo_litro';
    if ($metrica === 'margen_bruto' && $porLitro) return 'margen_litro';
    if ($metrica === 'litros' && ($dice('por vaca') || $dice('cada vaca'))) return 'litros_vaca';
    return $metrica;
}

/* =====================================================================
   RUBROS DEL GASTO: categoría, subcategoría o concepto
   ===================================================================== */

/** Cómo nombra el productor cada categoría, además de su nombre. */
function motor_tambo_sinonimos_categoria(): array {
    return [
        'Alimentación'  => ['alimentacion','alimento','alimentos','comida','racion','raciones'],
        'Veterinaria'   => ['veterinaria','remedios','medicamentos','vacunas'],
        'Sueldos'       => ['sueldos','sueldo','salarios','salario','mano de obra','personal','empleados'],
        'Mantenimiento' => ['mantenimiento','reparaciones','reparacion','arreglos'],
        'Honorarios'    => ['honorarios'],
        'Lubricantes y combustibles' => ['lubricantes y combustibles','combustibles','combustible','gasoil','nafta'],
        'Alquileres'    => ['alquileres','alquiler','arrendamiento','arriendo'],
        'Luz'           => ['luz','electricidad','energia','energia electrica'],
    ];
}

/**
 * Sinónimos de subcategorías. Las que tienen "/" en el nombre, o que son una
 * palabra que en el tambo significa otra cosa ("Vacas" es el alquiler de vacas,
 * no el rodeo), sólo se reconocen por acá.
 */
function motor_tambo_sinonimos_subcategoria(): array {
    return [
        'Concentrados'     => ['concentrado'],
        'Forrajes'         => ['forraje','rollos','silo','silaje','pastura','reservas'],
        'Minerales'        => ['mineral','sales minerales'],
        'Balanceados'      => ['balanceado','alimento balanceado'],
        'Cereal / Grano'   => ['cereal','grano','granos'],
        'Higiene'          => ['limpieza'],
        'Rutina de ordeñe' => ['pezoneras','sellador','selladores'],
        'Ordeñe'           => ['ordenadores','tamberos'],
        'Guachera'         => ['guacheras'],
        'Maquinaria'       => ['maquinas'],
        'Veterinarios'     => ['veterinario'],
        'Contables'        => ['contador','contable'],
        'Agrónomo'         => ['ingeniero agronomo'],
        'Aportes, seguros y aguinaldo' => ['aportes','seguros','aguinaldo','cargas sociales'],
        'Vacas'            => ['alquiler de vacas'],
        'Campo / Lote'     => ['alquiler del campo','alquiler de campo'],
        'Combustible agro' => ['gasoil agro'],
    ];
}

/**
 * Un concepto cargado a mano no puede llamarse como el vocabulario del chat.
 * Es la misma trampa que el proveedor "n" de agricultura: un concepto "Gastos"
 * se quedaría con todas las preguntas de gasto.
 */
function motor_tambo_concepto_usable(string $normalizado): bool {
    static $vocabulario = null;
    if ($vocabulario === null) {
        $vocabulario = ['otros','otro','varios','general','total','mes','leche','tambo'];
        foreach (motor_tambo_metricas() as $m) $vocabulario = array_merge($vocabulario, $m['sinonimos']);
        $vocabulario = array_merge($vocabulario, array_keys(motor_meses()));
        $vocabulario = array_flip($vocabulario);
    }
    return mb_strlen(str_replace(' ', '', $normalizado)) >= 3 && !isset($vocabulario[$normalizado]);
}

/**
 * Qué rubro del gasto nombra la pregunta.
 *
 * Gana el nombre más largo; a igual largo, el más general (categoría antes que
 * subcategoría antes que concepto): quien escribe una palabra que es el nombre
 * de una categoría, casi siempre quiere la categoría entera.
 *
 * @return array{tipo:string, nombre:string, pares?:array}|null
 */
function motor_tambo_detectar_rubro(string $t, array $categoriasUsuario = [], array $conceptos = []): ?array {
    $estr  = tambo_estructura();
    $cands = [];

    $sinCat = motor_tambo_sinonimos_categoria();
    foreach (array_unique(array_merge(array_keys($estr), $categoriasUsuario)) as $cat) {
        $n = motor_normalizar((string)$cat);
        if ($n === '' || $n === 'otros') continue;
        foreach (array_unique(array_merge([$n], $sinCat[$cat] ?? [])) as $syn) {
            if (motor_tambo_literal($t, $syn)) $cands[] = [mb_strlen($syn), 3, ['tipo' => 'categoria', 'nombre' => $cat]];
        }
    }

    $sinSub = motor_tambo_sinonimos_subcategoria();
    foreach (motor_tambo_subcategorias() as $sub => $pares) {
        $n = motor_normalizar($sub);
        $propios = (strpos($n, '/') !== false || $n === 'vacas') ? [] : [$n];
        foreach (array_merge($propios, $sinSub[$sub] ?? []) as $syn) {
            if (motor_tambo_literal($t, $syn)) {
                $cands[] = [mb_strlen($syn), 2, ['tipo' => 'subcategoria', 'nombre' => $sub, 'pares' => $pares]];
            }
        }
    }

    foreach ($conceptos as $c) {
        $n = motor_normalizar((string)$c);
        if (!motor_tambo_concepto_usable($n)) continue;
        if (motor_nombra($t, $n)) $cands[] = [mb_strlen($n), 1, ['tipo' => 'concepto', 'nombre' => (string)$c]];
    }

    if (!$cands) return null;
    usort($cands, fn($a, $b) => [$b[0], $b[1]] <=> [$a[0], $a[1]]);
    return $cands[0][2];
}

/**
 * Subcategoría => [[categoría, subcategoría], ...]. Una misma subcategoría puede
 * estar en dos categorías ("Reproducción" es de Veterinaria y de Sueldos). Las
 * que se llaman igual que una categoría ("Alimentación" dentro de Sueldos) y
 * "Otros" quedan afuera: nombrarlas es nombrar la categoría.
 */
function motor_tambo_subcategorias(): array {
    $estr = tambo_estructura();
    $nombresCat = array_map('motor_normalizar', array_keys($estr));
    $out = [];
    foreach ($estr as $cat => $subs) {
        foreach (array_keys($subs) as $sub) {
            $n = motor_normalizar($sub);
            if ($n === 'otros' || in_array($n, $nombresCat, true)) continue;
            $out[$sub][] = [$cat, $sub];
        }
    }
    return $out;
}

/** El rubro viaja en el contexto como "tipo:nombre". Se revalida al volver. */
function motor_tambo_rubro_a_texto(?array $rubro): ?string {
    return $rubro ? $rubro['tipo'] . ':' . $rubro['nombre'] : null;
}

function motor_tambo_rubro_de_texto(string $s, array $categoriasUsuario, array $conceptos): ?array {
    if (!preg_match('/^(categoria|subcategoria|concepto):(.+)$/u', $s, $m)) return null;
    [$tipo, $nombre] = [$m[1], $m[2]];
    if ($tipo === 'categoria') {
        $validas = array_unique(array_merge(array_keys(tambo_estructura()), $categoriasUsuario));
        return in_array($nombre, $validas, true) ? ['tipo' => $tipo, 'nombre' => $nombre] : null;
    }
    if ($tipo === 'subcategoria') {
        $subs = motor_tambo_subcategorias();
        return isset($subs[$nombre]) ? ['tipo' => $tipo, 'nombre' => $nombre, 'pares' => $subs[$nombre]] : null;
    }
    return in_array($nombre, $conceptos, true) ? ['tipo' => $tipo, 'nombre' => $nombre] : null;
}

/** "Alimentación", "Concentrados (Alimentación)", "Maíz molido". */
function motor_tambo_rubro_nombre(array $rubro): string {
    if ($rubro['tipo'] === 'subcategoria') {
        $cats = array_unique(array_column($rubro['pares'], 0));
        return $rubro['nombre'] . ' (' . implode(' y ', $cats) . ')';
    }
    return $rubro['nombre'];
}

/* =====================================================================
   MESES
   ===================================================================== */

function motor_tambo_nombres_mes(): array {
    return [1 => 'enero', 'febrero', 'marzo', 'abril', 'mayo', 'junio', 'julio', 'agosto',
            'septiembre', 'octubre', 'noviembre', 'diciembre'];
}

/** "2026-08" → "agosto de 2026" */
function motor_tambo_etiqueta_mes(string $mes): string {
    [$a, $m] = array_map('intval', explode('-', $mes));
    return (motor_tambo_nombres_mes()[$m] ?? $mes) . ' de ' . $a;
}

function motor_tambo_mes_anterior(string $mes): string {
    return date('Y-m', strtotime($mes . '-01 -1 month'));
}

/**
 * Los meses nombrados, en el orden en que están escritos. El año puede faltar:
 * se resuelve después contra lo cargado.
 *
 * Como palabra entera: "mayo" no puede salir de "mayor".
 *
 * @return array<array{mes:int, anio:?int}>
 */
function motor_tambo_meses_nombrados(string $t): array {
    $out = [];
    foreach (motor_meses() as $nombre => $num) {
        if (isset($out[$num])) continue;   // setiembre y septiembre son el mismo
        if (!preg_match('/(?<!\S)' . $nombre . '(?!\S)/u', $t, $m, PREG_OFFSET_CAPTURE)) continue;
        $anio = null;
        if (preg_match('/(?<!\S)' . $nombre . '\s+(?:de\s+|del\s+)?(20\d{2})(?!\d)/u', $t, $a)) {
            $anio = (int)$a[1];
        }
        $out[$num] = ['mes' => $num, 'anio' => $anio, 'pos' => $m[0][1]];
    }
    // Un solo año suelto en la frase vale para los meses que no traen el suyo.
    if (preg_match_all('/(?<!\d)(20\d{2})(?!\d)/', $t, $aa) && count(array_unique($aa[1])) === 1) {
        foreach ($out as &$o) if ($o['anio'] === null) $o['anio'] = (int)$aa[1][0];
        unset($o);
    }
    usort($out, fn($a, $b) => $a['pos'] <=> $b['pos']);
    return array_map(fn($o) => ['mes' => $o['mes'], 'anio' => $o['anio']], $out);
}

/**
 * "agosto" a secas es el agosto que el productor tiene cargado. Si no tiene
 * ninguno, el último agosto que ya pasó: nadie pregunta por un mes que no llegó.
 */
function motor_tambo_resolver_mes(PDO $pdo, int $uid, array $m): string {
    $anio = $m['anio'] ?? tambo_anio_con_datos($pdo, $uid, $m['mes']);
    if (!$anio) {
        $anio = (int)date('Y');
        if ($m['mes'] > (int)date('n')) $anio--;
    }
    return sprintf('%04d-%02d', $anio, $m['mes']);
}

/** Como palabras enteras: "saqué en agosto" lleva adentro "que en" y no compara nada. */
function motor_tambo_pide_comparar(string $t): bool {
    return preg_match('/(?<!\S)(?:compar\w*|contra|versus|vs|respecto|diferencia entre|que en|que el mes)(?!\S)/u', $t) === 1;
}

/** Cuántos meses hacia atrás, si pide una evolución. */
function motor_tambo_pide_serie(string $t): ?int {
    $palabras = ['dos'=>2,'tres'=>3,'cuatro'=>4,'cinco'=>5,'seis'=>6,'siete'=>7,'ocho'=>8,
                 'nueve'=>9,'diez'=>10,'once'=>11,'doce'=>12];
    if (preg_match('/ultimos\s+(\d{1,2}|' . implode('|', array_keys($palabras)) . ')\s+meses/u', $t, $m)) {
        $n = ctype_digit($m[1]) ? (int)$m[1] : $palabras[$m[1]];
        return max(2, min(MOTOR_TAMBO_SERIE_MAX, $n));
    }
    foreach (['ultimo ano','en el ano','ultimos doce'] as $p) if (strpos($t, $p) !== false) return 12;
    foreach (['evolucion','mes a mes','historial','tendencia','ultimos meses','por mes','cada mes'] as $p) {
        if (strpos($t, $p) !== false) return 6;
    }
    return null;
}

function motor_tambo_pide_ranking(string $t): bool {
    foreach (['en que gaste mas','en que se me va','en que se va','donde se va','donde se me va',
              'mayor gasto','mayores gastos','principal gasto','principales gastos','ranking','desglos',
              'abrime','abrir','por categoria','por rubro','lo que mas gaste','que es lo que mas',
              'mas pesa','pesa mas','mas peso','detalle de los costos','detalle de costos','composicion',
              'en que gastamos mas'] as $p) {
        if (strpos($t, $p) !== false) return true;
    }
    return false;
}

function motor_tambo_pide_resumen(string $t): bool {
    foreach (['resumen','como vengo','como venimos','como viene','como me fue','como nos fue','como fue el mes',
              'como esta el tambo','como va el tambo','como anda el tambo','analisis','analiza',
              'panorama','balance'] as $p) {
        if (strpos($t, $p) !== false) return true;
    }
    return false;
}

/* =====================================================================
   NÚMEROS Y FORMATO
   ===================================================================== */

function motor_tambo_fmt(?float $v, string $formato): string {
    if ($v === null) return 'sin dato';
    $usd   = motor_moneda() === 'USD';
    $signo = $v < 0 ? '-' : '';
    $a     = abs($v);
    switch ($formato) {
        case 'dinero':       return $signo . ($usd ? 'US$' : '$') . number_format($a, 2, ',', '.');
        case 'dinero_litro': return $signo . ($usd ? 'US$' : '$') . number_format($a, $usd ? 3 : 2, ',', '.') . ' por litro';
        // Lo mismo sin el "por litro", para cuando la frase ya habla del litro.
        case 'dinero_unit':  return $signo . ($usd ? 'US$' : '$') . number_format($a, $usd ? 3 : 2, ',', '.');
        case 'litros':       return $signo . number_format($a, 0, ',', '.') . ' litros';
        case 'litros_dia':   return number_format($a, 1, ',', '.') . ' litros por día';
        case 'pct':          return $signo . number_format($a, 1, ',', '.') . '%';
        case 'cabezas':      return number_format($a, 0, ',', '.') . ' vacas';
        case 'dolar':        return '$' . number_format($a, 2, ',', '.');
        default:             return (string)$v;
    }
}

function motor_tambo_litros_vaca(array $s): ?float {
    $vacas = (int)$s['rodeo']['vacas_ordene'];
    if ($vacas <= 0 || $s['litros'] <= 0) return null;
    return $s['litros'] / $vacas / (int)date('t', strtotime($s['desde']));
}

/** Lo gastado en un rubro, en las dos monedas, con el detalle de dónde. */
function motor_tambo_gasto_rubro(PDO $pdo, int $uid, array $s, array $rubro): array {
    if ($rubro['tipo'] === 'concepto') {
        return tambo_gasto_concepto($pdo, $uid, $s['mes'], $rubro['nombre']);
    }
    $pares = $rubro['tipo'] === 'categoria'
        ? array_map(fn($sub) => [$rubro['nombre'], $sub], array_keys($s['costos_subcat_ars'][$rubro['nombre']] ?? []))
        : $rubro['pares'];
    $ars = 0.0; $usd = 0.0; $donde = [];
    foreach ($pares as [$cat, $sub]) {
        $x = (float)($s['costos_subcat_ars'][$cat][$sub] ?? 0);
        $ars += $x;
        $usd += (float)($s['costos_subcat_usd'][$cat][$sub] ?? 0);
        if ($x != 0.0) $donde[$cat][$sub] = $x;
    }
    return ['ars' => $ars, 'usd' => $usd, 'items' => null, 'donde' => $donde];
}

/** El valor de una métrica en un mes ya calculado, en la moneda activa. */
function motor_tambo_valor(PDO $pdo, int $uid, string $metrica, array $s, ?array $rubro = null): ?float {
    $m = motor_tambo_metricas()[$metrica] ?? null;
    if ($m === null) return null;
    $usd = motor_moneda() === 'USD';

    if ($metrica === 'costos' && $rubro !== null) {
        return motor_tambo_gasto_rubro($pdo, $uid, $s, $rubro)[$usd ? 'usd' : 'ars'];
    }
    switch ($metrica) {
        case 'precio_litro': return $usd ? ($s['dolar'] > 0 ? $s['precio_leche_ars'] / $s['dolar'] : 0.0) : $s['precio_leche_ars'];
        case 'vacas':        return $s['rodeo']['fecha'] ? (float)$s['rodeo']['vacas_ordene'] : null;
        case 'litros_vaca':  return motor_tambo_litros_vaca($s);
        case 'dolar':        return $s['dolar'];
        case 'calidad':      return null;
    }
    if (isset($m['ars'])) return (float)$s[$usd ? $m['usd'] : $m['ars']];
    return isset($m['clave']) ? (float)$s[$m['clave']] : null;
}

/** Métricas que sin litros cargados no tienen sentido. */
function motor_tambo_necesita_litros(string $metrica): bool {
    return in_array($metrica, ['litros','precio_litro','margen_litro','costo_litro','rinde_indiferencia',
                               'litros_vaca'], true);
}

/**
 * El aviso del dólar estimado. Sólo cuando el dólar mueve el número que se está
 * mostrando: en dólares siempre, y en pesos si hay egresos cargados en dólares.
 */
function motor_tambo_nota_dolar(array $s): string {
    if (!$s['dolar_estimado']) return '';
    if (motor_moneda() !== 'USD' && !$s['egresos_usd']) return '';
    return 'Ojo: ' . motor_tambo_etiqueta_mes($s['mes']) . ' no tiene dólar cargado, así que usé el último que '
         . 'tenés (' . motor_tambo_fmt($s['dolar'], 'dolar') . '). Fijalo en el panel para cerrar el mes.';
}

function motor_tambo_r(array $o): array {
    return $o + [
        'ok' => true, 'tipo' => 'metrica', 'respuesta' => '', 'detalle' => '',
        'valor' => null, 'filtros' => [], 'link' => null, 'sugerencias' => [], 'modulo' => 'tambo',
    ];
}

function motor_tambo_juntar(string ...$partes): string {
    return implode("\n", array_filter($partes, fn($p) => trim($p) !== ''));
}

/* =====================================================================
   RESPONDER
   ===================================================================== */

function motor_tambo_responder(PDO $pdo, int $uid, string $pregunta, array $contexto = []): array {
    $texto = motor_normalizar($pregunta);

    /* ── Moneda ── Igual que en agricultura: "¿y en dólares?" rehace la anterior. */
    [$resto, $pedida] = motor_separar_moneda($texto);
    if ($pedida !== null) {
        motor_moneda($pedida);
        if ($resto === '' || motor_resto_es_relleno($resto)) {
            $previa = trim((string)($contexto['previa'] ?? ''));
            $nombreMoneda = $pedida === 'USD' ? 'dólares' : 'pesos';
            if ($previa === '') {
                return motor_tambo_r([
                    'ok' => false, 'tipo' => 'sin_entender',
                    'respuesta' => 'Todavía no te di ningún número para pasar a ' . $nombreMoneda . '.',
                    'detalle' => 'Preguntame algo primero —"¿cuál es mi margen?"— y después decime "y en ' . $nombreMoneda . '".',
                    'sugerencias' => ['¿Cuál es mi margen?', '¿Cuánto gasté?'],
                ]);
            }
            $sin = $contexto;
            unset($sin['previa']);
            $r = motor_tambo_responder($pdo, $uid, $previa, $sin);
            $r['previa'] = $previa;
            return $r;
        }
        $texto = $resto;
    }

    if ($texto === '') return motor_tambo_sin_entender('Escribí una pregunta sobre los números del tambo.');

    $nombre = motor_nombre($pdo, $uid);
    $voc    = $nombre ? ', ' . $nombre : '';

    $nuevoNombre = motor_detectar_nombre_propio($pregunta);
    if ($nuevoNombre !== null) {
        motor_guardar_nombre($pdo, $uid, $nuevoNombre);
        return motor_tambo_r([
            'tipo' => 'social',
            'respuesta' => 'Listo, ' . $nuevoNombre . '. Te llamo así de ahora en más.',
            'detalle' => 'Preguntame lo que quieras del tambo.',
            'sugerencias' => ['¿Cuál es mi margen?', '¿En qué gasté más?', '¿Cuánto me cuesta el litro?'],
        ]);
    }

    if (motor_pide_ayuda($texto)) {
        return motor_tambo_r([
            'tipo' => 'social',
            'respuesta' => 'Soy Cafrita' . $voc . '. Te llevo las cuentas del tambo.',
            'detalle' => motor_tambo_que_se(),
            'sugerencias' => ['¿Cómo vengo?', '¿En qué gasté más?', 'Litros de los últimos 6 meses'],
        ]);
    }

    $metrica        = motor_tambo_detectar_metrica($texto);
    $metricaPedida  = $metrica;
    $mesesNombrados = motor_tambo_meses_nombrados($texto);
    $pideResumen    = motor_tambo_pide_resumen($texto);
    $pideRanking    = motor_tambo_pide_ranking($texto);

    $soloSocial = (motor_es_saludo($texto) || motor_es_gracias($texto) || motor_es_despedida($texto))
               && $metrica === null && !$mesesNombrados && !$pideResumen && !$pideRanking;
    if ($soloSocial) return motor_tambo_social($pdo, $uid, $texto, $voc);

    // Cargar desde el chat todavía no: mejor decirlo que interpretarlo como consulta.
    if (motor_pide_alta($texto)) {
        return motor_tambo_r([
            'ok' => false, 'tipo' => 'sin_alta',
            'respuesta' => 'Las cargas del tambo todavía no las hago desde el chat.',
            'detalle' => 'Los egresos se cargan en Costos, y la leche y la carne en Ingresos. Lo que cargues ahí ya me lo podés preguntar.',
            'link' => 'tambo_egresos.php', 'link_texto' => 'Ir a Costos', 'link_icono' => 'fa-arrow-trend-down',
            'sugerencias' => ['¿Cuánto gasté?', '¿Cuál es mi margen?'],
        ]);
    }

    $ultimo = tambo_ultimo_mes_con_datos($pdo, $uid);
    if ($ultimo === null) {
        return motor_tambo_r([
            'ok' => false, 'tipo' => 'sin_datos',
            'respuesta' => 'Todavía no hay nada cargado en el tambo.',
            'detalle' => 'Cargá la leche del mes y los egresos, y te empiezo a sacar las cuentas.',
            'link' => 'tambo_produccion.php', 'link_texto' => 'Cargar la leche', 'link_icono' => 'fa-tint',
        ]);
    }

    /* ── Rubro del gasto ── Sólo si la pregunta es de gasto o no pide otro número:
       "¿cuánto gasté en alimentación?" recorta; "¿cuántos litros?" no. */
    $categoriasUsuario = tambo_categorias($pdo, $uid);
    $conceptos         = tambo_conceptos($pdo, $uid);
    $rubro = null;
    if ($metrica === null || $metrica === 'costos') {
        $rubro = motor_tambo_detectar_rubro($texto, $categoriasUsuario, $conceptos);
        if ($rubro !== null && $metrica === null) $metrica = 'costos';
    }
    // El que nombra la frase, antes de que la memoria lo complete.
    $rubroPedido = $rubro;

    $pideComparar = motor_tambo_pide_comparar($texto);
    $serie        = motor_tambo_pide_serie($texto);

    /* ── Memoria de la charla ── */
    $limpia = false;
    foreach (['en total','en general','todo el tambo','todos los costos','el total','sin filtro'] as $p) {
        if (strpos($texto, $p) !== false) { $limpia = true; break; }
    }
    $continua = $mesesNombrados || $limpia || preg_match('/^y\b/u', $texto) === 1
             || strpos($texto, 'mes anterior') !== false || strpos($texto, 'mes pasado') !== false;
    if ($metrica === null && !empty($contexto['metrica']) && isset(motor_tambo_metricas()[$contexto['metrica']]) && $continua) {
        /* Heredar "calidad" para "compará agosto con julio" deja una pregunta que no
           se puede contestar: si lo que se pide es comparar o seguir en el tiempo, se
           hereda sólo un número que se pueda comparar. Si no, va el resumen. */
        $paraComparar = count($mesesNombrados) >= 2 || $pideComparar || $serie !== null;
        if (!$paraComparar || motor_tambo_es_numerica($contexto['metrica'])) {
            $metrica = $contexto['metrica'];
        }
    }
    if ($metrica === 'costos' && $rubro === null && !$limpia && !empty($contexto['rubro'])
        && ($metricaPedida === null || $metricaPedida === ($contexto['metrica'] ?? null))) {
        $rubro = motor_tambo_rubro_de_texto((string)$contexto['rubro'], $categoriasUsuario, $conceptos);
    }

    /* ── Qué mes ── */
    $mesContexto = tambo_mes_valido((string)($contexto['mes'] ?? ''));
    $meses = array_map(fn($m) => motor_tambo_resolver_mes($pdo, $uid, $m), $mesesNombrados);
    if (!$meses) {
        if (strpos($texto, 'este mes') !== false) {
            $meses = [date('Y-m')];
        } elseif (strpos($texto, 'mes pasado') !== false && !$pideComparar) {
            $meses = [date('Y-m', strtotime('first day of -1 month'))];
        } elseif (strpos($texto, 'mes anterior') !== false && !$pideComparar) {
            $meses = [motor_tambo_mes_anterior($mesContexto ?? $ultimo)];
        }
    }
    $explicito = (bool)$meses;
    $mesBase   = $meses[0] ?? $mesContexto ?? $ultimo;

    $filtros = [
        'mes'        => $explicito || $mesContexto ? $mesBase : null,
        'mes_nombre' => $explicito || $mesContexto ? motor_tambo_etiqueta_mes($mesBase) : null,
        'metrica'    => $metrica,
        'rubro'      => $metrica === 'costos' ? motor_tambo_rubro_a_texto($rubro) : null,
        'rubro_nombre' => $metrica === 'costos' && $rubro ? motor_tambo_rubro_nombre($rubro) : null,
    ];

    /* ── Qué es ── */
    if ($metrica !== null && motor_pide_definicion($texto)) {
        $m = motor_tambo_metricas()[$metrica];
        return motor_tambo_r([
            'tipo' => 'definicion',
            'respuesta' => ucfirst($m['etiqueta']) . ': ' . $m['explicacion'],
            'filtros' => $filtros,
            'sugerencias' => ['¿Cuál es mi ' . $m['etiqueta'] . '?', '¿Cómo vengo?'],
        ]);
    }

    /* ── Comparar dos meses ── */
    if (count($meses) >= 2 || ($pideComparar && ($metrica === null || motor_tambo_es_numerica($metrica)))) {
        $a = $meses[0] ?? $mesBase;
        $b = $meses[1] ?? motor_tambo_mes_anterior($a);
        return motor_tambo_comparar($pdo, $uid, $metrica, $rubro, $a, $b, $filtros);
    }

    /* ── Evolución ── */
    if ($serie !== null) {
        return motor_tambo_serie($pdo, $uid, $metrica ?? 'margen_bruto', $rubro, $mesBase, $serie, $filtros);
    }

    /* ── En qué se va la plata ── Abre una categoría sólo si la nombra esta frase:
       "¿en qué gasté más?" después de hablar de alimentación pide el mes entero. */
    if ($pideRanking && ($metrica === null || $metrica === 'costos')) {
        $filtros['metrica'] = 'costos';
        $filtros['rubro'] = motor_tambo_rubro_a_texto($rubroPedido);
        $filtros['rubro_nombre'] = $rubroPedido ? motor_tambo_rubro_nombre($rubroPedido) : null;
        return motor_tambo_ranking($pdo, $uid, $mesBase, $rubroPedido, $filtros, $ultimo);
    }

    /* ── Resumen del mes ── */
    if ($pideResumen && ($metricaPedida === null)) {
        return motor_tambo_resumen($pdo, $uid, $mesBase, $filtros, $ultimo);
    }

    if ($metrica === null) {
        return motor_tambo_sin_entender('Esa no la tengo' . $voc . '.');
    }

    return motor_tambo_metrica_mes($pdo, $uid, $metrica, $rubro, $mesBase, $filtros, $ultimo);
}

/** Lo que sabe contestar, en una frase. */
function motor_tambo_que_se(): string {
    return 'Del tambo te puedo dar los litros, el precio de la leche, los ingresos (leche y carne), '
         . 'los costos por categoría o por concepto, el margen bruto, la rentabilidad, el costo por litro, '
         . 'el rinde de indiferencia, el rodeo, los litros por vaca y la calidad. '
         . 'De cualquier mes, comparando dos meses o en los últimos meses, en pesos o en dólares.';
}

function motor_tambo_sin_entender(string $motivo): array {
    return motor_tambo_r([
        'ok' => false, 'tipo' => 'sin_entender',
        'respuesta' => $motivo,
        'detalle' => motor_tambo_que_se(),
        'sugerencias' => ['¿Cómo vengo?', '¿En qué gasté más?', '¿Cuánto me cuesta el litro?'],
    ]);
}

function motor_tambo_social(PDO $pdo, int $uid, string $texto, string $voc): array {
    if (motor_es_despedida($texto)) {
        return motor_tambo_r(['tipo' => 'social', 'respuesta' => 'Cuando quieras' . $voc . '. Acá estoy.']);
    }
    if (motor_es_gracias($texto)) {
        return motor_tambo_r([
            'tipo' => 'social', 'respuesta' => 'De nada' . $voc . '.', 'detalle' => '¿Querés ver algo más?',
            'sugerencias' => ['¿En qué gasté más?', '¿Cuánto me cuesta el litro?'],
        ]);
    }

    // Un saludo con el número del último mes sirve; uno vacío es cortesía.
    $saludo  = motor_saludo_horario($texto) . $voc . '.';
    $detalle = 'Preguntame lo que quieras del tambo.';
    $ultimo  = tambo_ultimo_mes_con_datos($pdo, $uid);
    if ($ultimo !== null) {
        $s = tambo_stats($pdo, $uid, $ultimo);
        if ($s['hay_datos']) {
            $etq = motor_tambo_etiqueta_mes($ultimo);
            $m   = motor_tambo_valor($pdo, $uid, 'margen_bruto', $s);
            // Festejar una pérdida es peor que no decir nada: se nombra derecho.
            $saludo .= $m >= 0
                ? ' En ' . $etq . ' el tambo dejó ' . motor_tambo_fmt($m, 'dinero') . ' de margen bruto.'
                : ' Ojo que en ' . $etq . ' el tambo dio ' . motor_tambo_fmt($m, 'dinero') . ': está en pérdida.';
            $detalle = motor_tambo_lo_que_mas_pesa($s) ?: $detalle;
        }
    }
    return motor_tambo_r([
        'tipo' => 'social', 'respuesta' => $saludo, 'detalle' => $detalle,
        'sugerencias' => ['¿Cómo vengo?', '¿En qué gasté más?', '¿Cuánto me cuesta el litro?'],
    ]);
}

/** "Lo que más pesó fue Alimentación: 45,2% de los costos." */
function motor_tambo_lo_que_mas_pesa(array $s): string {
    if ($s['costos_ars_total'] <= 0 || !$s['costos_cat_ars']) return '';
    $cat = array_key_first($s['costos_cat_ars']);
    $pct = $s['costos_cat_ars'][$cat] / $s['costos_ars_total'] * 100;
    return 'Lo que más pesó fue ' . $cat . ': ' . motor_tambo_fmt($pct, 'pct') . ' de los costos.';
}

/** Adónde lleva el "Ver" de cada respuesta. */
function motor_tambo_link(string $metrica, string $mes): array {
    if ($metrica === 'costos') {
        return ['link' => 'tambo_egresos.php?mes=' . $mes, 'link_texto' => 'Ver los egresos', 'link_icono' => 'fa-arrow-trend-down'];
    }
    if (in_array($metrica, ['litros','precio_litro','ingresos','ingreso_leche','ingreso_carne'], true)) {
        return ['link' => 'tambo_produccion.php?mes=' . $mes, 'link_texto' => 'Ver los ingresos', 'link_icono' => 'fa-tint'];
    }
    return ['link' => 'tambo.php?mes=' . $mes, 'link_texto' => 'Ver en el panel', 'link_icono' => 'fa-table-columns'];
}

function motor_tambo_sugerencias(string $metrica): array {
    $s = [
        'margen_bruto' => ['¿En qué gasté más?', '¿Cuánto me cuesta el litro?', '¿Y contra el mes anterior?'],
        'rentabilidad' => ['¿Cuál es mi margen?', '¿Y contra el mes anterior?', 'Rentabilidad de los últimos 6 meses'],
        'margen_litro' => ['¿Cuánto me cuesta el litro?', '¿A cuánto se pagó la leche?'],
        'litros'       => ['¿Cuántos litros por vaca?', '¿A cuánto se pagó la leche?', 'Litros de los últimos 6 meses'],
        'precio_litro' => ['¿Cuánto me cuesta el litro?', '¿Cuántos litros produje?'],
        'ingresos'     => ['¿Cuánto fue la carne?', '¿Cuál es mi margen?'],
        'ingreso_leche'=> ['¿Cuánto fue la carne?', '¿Cuál es mi margen?'],
        'ingreso_carne'=> ['¿Cuánto cobré de leche?', '¿Cuál es mi margen?'],
        'costos'       => ['¿En qué gasté más?', '¿Cuánto gasté en alimentación?', '¿Y el mes anterior?'],
        'costo_litro'  => ['¿Cuál es el rinde de indiferencia?', '¿En qué gasté más?'],
        'rinde_indiferencia' => ['¿Cuánto me cuesta el litro?', '¿Cuántos litros produje?'],
        'vacas'        => ['¿Cuántos litros por vaca?', '¿Cómo está la calidad?'],
        'litros_vaca'  => ['¿Cuántas vacas tengo en ordeñe?', 'Litros de los últimos 6 meses'],
        'calidad'      => ['¿Cuántos litros produje?', '¿A cuánto se pagó la leche?'],
        'dolar'        => ['¿Cuál es mi margen en dólares?', '¿Cuánto gasté?'],
    ];
    return $s[$metrica] ?? ['¿Cómo vengo?', '¿En qué gasté más?'];
}

/** Una métrica en un mes. */
function motor_tambo_metrica_mes(PDO $pdo, int $uid, string $metrica, ?array $rubro, string $mes,
                                 array $filtros, string $ultimo): array {
    $s   = tambo_stats($pdo, $uid, $mes);
    $etq = motor_tambo_etiqueta_mes($mes);
    $cat = motor_tambo_metricas()[$metrica];
    // Sin ofrecer justo lo que se acaba de contestar ("¿Cuánto gasté en alimentación?" dos veces).
    $sugerencias = array_values(array_filter(motor_tambo_sugerencias($metrica),
        fn($x) => !$rubro || mb_stripos($x, $rubro['nombre']) === false));
    if ($rubro && count($sugerencias) < 3) $sugerencias[] = '¿Cuánto gasté en total?';
    $base = ['filtros' => $filtros, 'sugerencias' => $sugerencias] + motor_tambo_link($metrica, $mes);

    /* ── Sin datos ── Nunca un $0 que parezca un dato. */
    $sinDatosPropios = !in_array($metrica, ['vacas','calidad','dolar'], true) && !$s['hay_datos'];
    if ($sinDatosPropios || (motor_tambo_necesita_litros($metrica) && $s['litros'] <= 0)) {
        $falta = $sinDatosPropios ? 'no hay nada cargado en el tambo' : 'no hay litros de leche cargados';
        return motor_tambo_r([
            'ok' => false, 'tipo' => 'sin_datos',
            'respuesta' => 'En ' . $etq . ' ' . $falta . '.',
            'detalle' => $ultimo !== $mes ? 'El último mes con datos es ' . motor_tambo_etiqueta_mes($ultimo) . '.' : '',
            'sugerencias' => $ultimo !== $mes
                ? ['¿Y en ' . motor_tambo_nombres_mes()[(int)substr($ultimo, 5, 2)] . ' ' . substr($ultimo, 0, 4) . '?', '¿Cómo vengo?']
                : ['¿Cómo vengo?'],
        ] + $base);
    }

    $v   = motor_tambo_valor($pdo, $uid, $metrica, $s, $rubro);
    $txt = motor_tambo_fmt($v, $cat['formato']);
    $nota = motor_tambo_nota_dolar($s);
    $contraAnterior = function () use ($pdo, $uid, $metrica, $rubro, $mes, $v, $cat): string {
        $ant = tambo_stats($pdo, $uid, motor_tambo_mes_anterior($mes));
        if (!$ant['hay_datos'] || $v === null) return '';
        $va = motor_tambo_valor($pdo, $uid, $metrica, $ant, $rubro);
        // Contra un cero no hay variación que decir: sería "sin base para comparar".
        if ($va === null || $va == 0.0) return '';
        return 'Contra ' . motor_tambo_etiqueta_mes($ant['mes']) . ': ' . motor_tambo_fmt($va, $cat['formato'])
             . ' (' . motor_variacion($va, $v) . ').';
    };

    switch ($metrica) {
        case 'margen_bruto':
            $resp = $v >= 0
                ? 'En ' . $etq . ' el tambo dejó ' . $txt . ' de margen bruto.'
                : 'En ' . $etq . ' el tambo perdió ' . motor_tambo_fmt(abs($v), 'dinero') . ': el margen bruto dio negativo.';
            $det = motor_tambo_juntar(
                'Ingresos ' . motor_tambo_fmt(motor_tambo_valor($pdo, $uid, 'ingresos', $s), 'dinero')
                . ' menos costos ' . motor_tambo_fmt(motor_tambo_valor($pdo, $uid, 'costos', $s), 'dinero')
                . '. Rentabilidad: ' . motor_tambo_fmt($s['rentabilidad'], 'pct') . '.',
                $contraAnterior(), $nota);
            break;

        case 'rentabilidad':
            $resp = 'En ' . $etq . ' la rentabilidad del tambo fue ' . $txt . '.';
            $det  = motor_tambo_juntar('Es el margen bruto (' . motor_tambo_fmt(motor_tambo_valor($pdo, $uid, 'margen_bruto', $s), 'dinero')
                  . ') sobre los ingresos (' . motor_tambo_fmt(motor_tambo_valor($pdo, $uid, 'ingresos', $s), 'dinero') . ').');
            break;

        case 'margen_litro':
            $resp = 'En ' . $etq . ' quedaron ' . $txt . ' de margen.';
            $det  = motor_tambo_juntar('Margen bruto ' . motor_tambo_fmt(motor_tambo_valor($pdo, $uid, 'margen_bruto', $s), 'dinero')
                  . ' sobre ' . motor_tambo_fmt($s['litros'], 'litros') . '.', $nota);
            break;

        case 'litros':
            $resp = 'En ' . $etq . ' se produjeron ' . $txt . '.';
            $det  = motor_tambo_juntar(
                $s['litros_otra'] > 0 ? 'Aparte, ' . motor_tambo_fmt($s['litros_otra'], 'litros') . ' de otra leche.' : '',
                $contraAnterior());
            break;

        case 'precio_litro':
            $resp = 'En ' . $etq . ' la leche se pagó ' . $txt . '.';
            $det  = motor_tambo_juntar(
                'Es el precio de referencia del panel: el más alto cargado en el mes.',
                'Ingreso promedio por litro: ' . motor_tambo_fmt(motor_moneda() === 'USD' ? $s['ingreso_leche_litro_usd'] : $s['ingreso_leche_litro_ars'], 'dinero_litro') . '.',
                motor_moneda() === 'USD' ? $nota : '');
            break;

        case 'ingresos':
            $resp = 'En ' . $etq . ' el tambo facturó ' . $txt . '.';
            $det  = motor_tambo_juntar(
                'Leche ' . motor_tambo_fmt(motor_tambo_valor($pdo, $uid, 'ingreso_leche', $s), 'dinero')
                . ($s['ingreso_otra_ars'] != 0.0 ? ', otra leche ' . motor_tambo_fmt(motor_moneda() === 'USD' ? $s['ingreso_otra_usd'] : $s['ingreso_otra_ars'], 'dinero') : '')
                . ' y carne ' . motor_tambo_fmt(motor_tambo_valor($pdo, $uid, 'ingreso_carne', $s), 'dinero') . '.',
                $contraAnterior(), motor_moneda() === 'USD' ? $nota : '');
            break;

        case 'ingreso_leche':
            $resp = 'En ' . $etq . ' la leche dejó ' . $txt . ' de ingreso.';
            $det  = motor_tambo_juntar(
                motor_tambo_fmt($s['litros'], 'litros') . '. La otra leche va aparte'
                . ($s['ingreso_otra_ars'] != 0.0 ? ': ' . motor_tambo_fmt(motor_moneda() === 'USD' ? $s['ingreso_otra_usd'] : $s['ingreso_otra_ars'], 'dinero') : '') . '.',
                motor_moneda() === 'USD' ? $nota : '');
            break;

        case 'ingreso_carne':
            $usd  = motor_moneda() === 'USD';
            $resp = 'En ' . $etq . ' la carne sumó ' . $txt . '.';
            $det  = motor_tambo_juntar(
                'Venta de carne ' . motor_tambo_fmt($usd ? $s['ingreso_carne_real_usd'] : $s['ingreso_carne_real_ars'], 'dinero')
                . ' y diferencia de inventario ' . motor_tambo_fmt($usd ? $s['ingreso_dif_inv_usd'] : $s['ingreso_dif_inv_ars'], 'dinero')
                . ', ya prorrateadas por mes como se cargaron.',
                $usd ? $nota : '');
            break;

        case 'costos':
            if ($rubro !== null) {
                $g = motor_tambo_gasto_rubro($pdo, $uid, $s, $rubro);
                if ($g['ars'] == 0.0) {
                    return motor_tambo_r([
                        'tipo' => 'metrica',
                        'respuesta' => 'En ' . $etq . ' no hay egresos cargados en ' . motor_tambo_rubro_nombre($rubro) . '.',
                        'detalle' => 'Los costos totales del mes fueron ' . motor_tambo_fmt(motor_tambo_valor($pdo, $uid, 'costos', $s), 'dinero') . '.',
                        'valor' => 0,
                    ] + $base);
                }
                $resp = 'En ' . $etq . ' gastaste ' . $txt . ' en ' . motor_tambo_rubro_nombre($rubro) . '.';

                // Dónde está cargado: el concepto dice en qué subcategoría; una
                // categoría o una subcategoría repartida se abre renglón por renglón.
                $enMoneda = fn(float $ars): float => motor_moneda() === 'USD' ? ($s['dolar'] > 0 ? $ars / $s['dolar'] : 0.0) : $ars;
                $filas = [];
                foreach ($g['donde'] as $c => $subs) {
                    foreach ($subs as $sub => $ars) $filas[] = [$c, $sub, $ars];
                }
                $desglose = '';
                if ($rubro['tipo'] === 'concepto' && $filas) {
                    $desglose = 'Cargado en ' . implode(' y ', array_map(fn($f) => $f[0] . ' › ' . $f[1], $filas)) . '.';
                } elseif (count($filas) > 1) {
                    usort($filas, fn($x, $y) => $y[2] <=> $x[2]);
                    $desglose = implode("\n", array_map(
                        fn($f) => '• ' . ($rubro['tipo'] === 'categoria' ? $f[1] : $f[0]) . ': ' . motor_tambo_fmt($enMoneda($f[2]), 'dinero'),
                        $filas));
                }
                $pct = $s['costos_ars_total'] > 0 ? $g['ars'] / $s['costos_ars_total'] * 100 : 0;
                $det = motor_tambo_juntar($desglose,
                    'Es el ' . motor_tambo_fmt($pct, 'pct') . ' de los costos del mes.',
                    $contraAnterior(), $nota);
            } else {
                $resp = 'En ' . $etq . ' los costos del tambo fueron ' . $txt . '.';
                $det  = motor_tambo_juntar(
                    $s['egresos_items'] . ' egreso' . ($s['egresos_items'] === 1 ? '' : 's') . ' cargado' . ($s['egresos_items'] === 1 ? '' : 's') . '.',
                    motor_tambo_lo_que_mas_pesa($s), $contraAnterior(), $nota);
            }
            break;

        case 'costo_litro':
            $usd  = motor_moneda() === 'USD';
            $resp = 'En ' . $etq . ' el litro te costó ' . motor_tambo_fmt($v, 'dinero_unit') . ', descontando lo que recuperan la carne y la otra leche.';
            $det  = motor_tambo_juntar(
                'Costo bruto ' . motor_tambo_fmt($usd ? $s['costo_bruto_litro_usd'] : $s['costo_bruto_litro_ars'], 'dinero_unit')
                . '; la carne recupera ' . motor_tambo_fmt($usd ? $s['recupero_carne_litro_usd'] : $s['recupero_carne_litro_ars'], 'dinero_unit')
                . ' y la otra leche ' . motor_tambo_fmt($usd ? $s['recupero_otra_litro_usd'] : $s['recupero_otra_litro_ars'], 'dinero_unit') . ', todo por litro.',
                'La leche se pagó ' . motor_tambo_fmt(motor_tambo_valor($pdo, $uid, 'precio_litro', $s), 'dinero_unit') . ' el litro.',
                $contraAnterior(), $nota);
            break;

        case 'rinde_indiferencia':
            $dif  = $s['litros'] - $s['rinde_indiferencia'];
            $resp = 'En ' . $etq . ' hacían falta ' . $txt . ' para salir hecho.';
            $det  = motor_tambo_juntar(
                'Se produjeron ' . motor_tambo_fmt($s['litros'], 'litros') . ': '
                . ($dif >= 0 ? 'sobraron ' : 'faltaron ') . motor_tambo_fmt(abs($dif), 'litros') . '.',
                'Al precio del mes (' . motor_tambo_fmt($s['precio_leche_ars'], 'dinero') . ' el litro), con lo que recuperan la carne y la otra leche ya descontado.');
            break;

        case 'vacas':
            $r = $s['rodeo'];
            if (!$r['fecha']) {
                return motor_tambo_r(['ok' => false, 'tipo' => 'sin_datos',
                    'respuesta' => 'No hay rodeo cargado hasta ' . $etq . '.'] + $base);
            }
            $total = $r['vacas_ordene'] + $r['vacas_secas'] + $r['vaquillonas'] + $r['terneros'];
            $resp  = 'Hay ' . motor_tambo_fmt($r['vacas_ordene'], 'cabezas') . ' en ordeñe y ' . $r['vacas_secas'] . ' secas.';
            $det   = 'Vaquillonas ' . $r['vaquillonas'] . ', terneros ' . $r['terneros'] . ': ' . $total . ' cabezas en total. '
                   . 'Es el rodeo cargado el ' . date('d/m/Y', strtotime($r['fecha'])) . '.';
            break;

        case 'litros_vaca':
            if ($v === null) {
                return motor_tambo_r(['ok' => false, 'tipo' => 'sin_datos',
                    'respuesta' => 'Para sacar los litros por vaca me falta el rodeo: no hay vacas en ordeñe cargadas hasta ' . $etq . '.'] + $base);
            }
            $dias = (int)date('t', strtotime($s['desde']));
            $resp = 'En ' . $etq . ' cada vaca en ordeñe dio ' . $txt . '.';
            $det  = motor_tambo_fmt($s['litros'], 'litros') . ' en ' . $dias . ' días con ' . motor_tambo_fmt($s['rodeo']['vacas_ordene'], 'cabezas')
                  . ' en ordeñe (rodeo del ' . date('d/m/Y', strtotime($s['rodeo']['fecha'])) . ').';
            break;

        case 'calidad':
            $c = $s['calidad'];
            if (!$c['fecha']) {
                return motor_tambo_r(['ok' => false, 'tipo' => 'sin_datos',
                    'respuesta' => 'No hay análisis de calidad cargados hasta ' . $etq . '.'] + $base);
            }
            $partes = [];
            if ($c['tenor_graso'] !== null) $partes[] = 'grasa ' . motor_tambo_fmt($c['tenor_graso'], 'pct');
            if ($c['tenor_prot']  !== null) $partes[] = 'proteína ' . motor_tambo_fmt($c['tenor_prot'], 'pct');
            $resp = 'El último análisis es del ' . date('d/m/Y', strtotime($c['fecha'])) . ($partes ? ': ' . implode(', ', $partes) . '.' : '.');
            $det  = motor_tambo_juntar(
                $c['rcs'] !== null ? 'Células somáticas: ' . number_format($c['rcs'], 0, ',', '.') . ' mil por ml.' : '',
                $c['ufc'] !== null ? 'Bacterias (UFC): ' . number_format($c['ufc'], 0, ',', '.') . ' mil por ml.' : '');
            break;

        case 'dolar':
            $resp = $s['dolar_estimado']
                ? ucfirst($etq) . ' no tiene dólar cargado: uso el último que tenés, ' . $txt . '.'
                : ucfirst($etq) . ' se cierra con un dólar de ' . $txt . ' (' . ($s['dolar_fuente'] === 'manual' ? 'cargado a mano' : 'mayorista del día') . ').';
            $det  = 'Se cambia desde el panel del tambo, en "Fijar TC del mes".';
            $base = ['link' => 'tambo.php?mes=' . $mes, 'link_texto' => 'Ir al panel', 'link_icono' => 'fa-dollar-sign'] + $base;
            break;

        default:
            return motor_tambo_sin_entender('Esa no la tengo.');
    }

    return motor_tambo_r(['respuesta' => $resp, 'detalle' => $det, 'valor' => $v] + $base);
}

/** Una métrica (o el resumen) en dos meses. El primero nombrado es el sujeto. */
function motor_tambo_comparar(PDO $pdo, int $uid, ?string $metrica, ?array $rubro, string $a, string $b, array $filtros): array {
    $sa = tambo_stats($pdo, $uid, $a);
    $sb = tambo_stats($pdo, $uid, $b);
    $ea = motor_tambo_etiqueta_mes($a);
    $eb = motor_tambo_etiqueta_mes($b);
    [$viejo, $nuevo] = $a < $b ? [$a, $b] : [$b, $a];
    $base = [
        'tipo' => 'comparacion', 'filtros' => ['mes' => $a, 'mes_nombre' => motor_tambo_etiqueta_mes($a)] + $filtros,
        'link' => 'tambo_comparativa.php?mes1=' . $viejo . '&mes2=' . $nuevo,
        'link_texto' => 'Ver la comparativa', 'link_icono' => 'fa-code-compare',
        'sugerencias' => ['¿En qué gasté más?', '¿Cuánto me cuesta el litro?'],
    ];

    $vacios = array_filter([$ea => $sa, $eb => $sb], fn($s) => !$s['hay_datos']);
    if ($vacios) {
        return motor_tambo_r(['ok' => false, 'tipo' => 'sin_datos',
            'respuesta' => 'No puedo comparar: en ' . implode(' ni en ', array_keys($vacios)) . ' no hay nada cargado en el tambo.'] + $base);
    }

    $linea = function (string $met) use ($pdo, $uid, $sa, $sb, $rubro): ?array {
        $f  = motor_tambo_metricas()[$met]['formato'];
        $va = motor_tambo_valor($pdo, $uid, $met, $sa, $rubro);
        $vb = motor_tambo_valor($pdo, $uid, $met, $sb, $rubro);
        if ($va === null || $vb === null) return null;
        $cambio = $f === 'pct'
            ? number_format(abs($va - $vb), 1, ',', '.') . ' puntos ' . ($va >= $vb ? 'más' : 'menos')
            : motor_variacion($vb, $va);
        return [$va, $vb, motor_tambo_fmt($va, $f), motor_tambo_fmt($vb, $f), $cambio];
    };

    if ($metrica === null) {
        $lineas = [];
        foreach (['margen_bruto','litros','ingresos','costos','costo_litro'] as $met) {
            $l = $linea($met);
            if ($l) $lineas[] = '• ' . ucfirst(motor_tambo_metricas()[$met]['etiqueta']) . ': ' . $l[2] . ' contra ' . $l[3] . ' (' . $l[4] . ')';
        }
        $m = $linea('margen_bruto');
        return motor_tambo_r([
            'respuesta' => 'En ' . $ea . ' el margen bruto fue ' . $m[2] . ', ' . $m[4] . ' que en ' . $eb . '.',
            'detalle' => motor_tambo_juntar(implode("\n", $lineas), motor_tambo_nota_dolar($sa), motor_tambo_nota_dolar($sb)),
        ] + $base);
    }

    $l = $linea($metrica);
    if ($l === null) return motor_tambo_sin_entender('Ese número no lo puedo comparar entre meses.');
    // "Litros producidos: 160.000 litros en agosto…": sirve igual para etiquetas en plural.
    $etq = ucfirst(motor_tambo_metricas()[$metrica]['etiqueta']) . ($metrica === 'costos' && $rubro ? ' en ' . motor_tambo_rubro_nombre($rubro) : '');
    return motor_tambo_r([
        'respuesta' => $etq . ': ' . $l[2] . ' en ' . $ea . ', ' . $l[4] . ' que en ' . $eb . ' (' . $l[3] . ').',
        'detalle' => motor_tambo_juntar(motor_tambo_nota_dolar($sa), motor_tambo_nota_dolar($sb)),
        'valor' => $l[0],
    ] + $base);
}

/** Una métrica en los últimos N meses, del más viejo al más nuevo. */
function motor_tambo_serie(PDO $pdo, int $uid, string $metrica, ?array $rubro, string $hasta, int $n, array $filtros): array {
    if (!motor_tambo_es_numerica($metrica)) {
        return motor_tambo_sin_entender('Ese número no lo puedo seguir mes a mes.');
    }
    $cat = motor_tambo_metricas()[$metrica];
    $meses = [$hasta];
    while (count($meses) < $n) $meses[] = motor_tambo_mes_anterior(end($meses));
    $meses = array_reverse($meses);

    $lineas = [];
    $conDatos = 0;
    $estimado = false;
    foreach ($meses as $mes) {
        $s = tambo_stats($pdo, $uid, $mes);
        $v = $s['hay_datos'] ? motor_tambo_valor($pdo, $uid, $metrica, $s, $rubro) : null;
        if (motor_tambo_necesita_litros($metrica) && $s['litros'] <= 0) $v = null;
        if ($v !== null) $conDatos++;
        $estimado = $estimado || ($s['hay_datos'] && $s['dolar_estimado'] && motor_tambo_nota_dolar($s) !== '');
        $lineas[] = '• ' . ucfirst(motor_tambo_etiqueta_mes($mes)) . ': ' . ($v === null ? 'sin datos' : motor_tambo_fmt($v, $cat['formato']));
    }

    $titulo = ucfirst($cat['etiqueta']) . ($metrica === 'costos' && $rubro ? ' en ' . motor_tambo_rubro_nombre($rubro) : '');
    return motor_tambo_r([
        'ok' => $conDatos > 0, 'tipo' => 'serie',
        'respuesta' => $titulo . ', últimos ' . $n . ' meses:',
        'detalle' => motor_tambo_juntar(implode("\n", $lineas),
            $estimado ? 'Algunos meses no tienen dólar cargado y usan el último que tenés.' : ''),
        'filtros' => $filtros,
        'link' => 'tambo.php?mes=' . $hasta, 'link_texto' => 'Ver en el panel', 'link_icono' => 'fa-chart-line',
        'sugerencias' => motor_tambo_sugerencias($metrica),
    ]);
}

/** En qué se fue la plata del mes: por categoría, o dentro de una categoría. */
function motor_tambo_ranking(PDO $pdo, int $uid, string $mes, ?array $rubro, array $filtros, string $ultimo): array {
    $s   = tambo_stats($pdo, $uid, $mes);
    $etq = motor_tambo_etiqueta_mes($mes);
    $usd = motor_moneda() === 'USD';
    $base = ['tipo' => 'ranking', 'filtros' => $filtros] + motor_tambo_link('costos', $mes);

    if ($s['costos_ars_total'] <= 0) {
        return motor_tambo_r(['ok' => false, 'tipo' => 'sin_datos',
            'respuesta' => 'En ' . $etq . ' no hay egresos cargados.',
            'detalle' => $ultimo !== $mes ? 'El último mes con datos es ' . motor_tambo_etiqueta_mes($ultimo) . '.' : ''] + $base);
    }

    if ($rubro && $rubro['tipo'] === 'categoria' && !empty($s['costos_subcat_ars'][$rubro['nombre']])) {
        $filas = $s['costos_subcat_ars'][$rubro['nombre']];
        arsort($filas);
        $total = array_sum($filas);
        $donde = ' dentro de ' . $rubro['nombre'];
    } else {
        $filas = $s['costos_cat_ars'];
        $total = $s['costos_ars_total'];
        $donde = '';
    }

    $lineas = [];
    foreach ($filas as $nombre => $ars) {
        $lineas[] = '• ' . $nombre . ': ' . motor_tambo_fmt($usd ? ($s['dolar'] > 0 ? $ars / $s['dolar'] : 0) : $ars, 'dinero')
                  . ' (' . motor_tambo_fmt($total > 0 ? $ars / $total * 100 : 0, 'pct') . ')';
    }
    $primero = array_key_first($filas);
    $pct = $total > 0 ? $filas[$primero] / $total * 100 : 0;
    $monto = $usd ? ($s['dolar'] > 0 ? $filas[$primero] / $s['dolar'] : 0) : $filas[$primero];

    return motor_tambo_r([
        'respuesta' => 'En ' . $etq . ', lo que más pesó' . $donde . ' fue ' . $primero . ': '
                     . motor_tambo_fmt($monto, 'dinero') . ', el ' . motor_tambo_fmt($pct, 'pct') . '.',
        'detalle' => motor_tambo_juntar(implode("\n", $lineas), motor_tambo_nota_dolar($s)),
        'sugerencias' => $donde === ''
            ? ['¿Cuánto gasté en ' . mb_strtolower($primero) . '?', '¿Cuánto me cuesta el litro?', '¿Y el mes anterior?']
            : ['¿En qué gasté más?', '¿Cuál es mi margen?'],
    ] + $base);
}

/** "¿Cómo vengo?": el mes en cinco renglones, contra el anterior. */
function motor_tambo_resumen(PDO $pdo, int $uid, string $mes, array $filtros, string $ultimo): array {
    $s   = tambo_stats($pdo, $uid, $mes);
    $etq = motor_tambo_etiqueta_mes($mes);
    if (!$s['hay_datos']) {
        return motor_tambo_r(['ok' => false, 'tipo' => 'sin_datos',
            'respuesta' => 'En ' . $etq . ' no hay nada cargado en el tambo.',
            'detalle' => $ultimo !== $mes ? 'El último mes con datos es ' . motor_tambo_etiqueta_mes($ultimo) . '.' : '',
            'filtros' => $filtros]);
    }
    $ant = tambo_stats($pdo, $uid, motor_tambo_mes_anterior($mes));
    $vs = function (string $met) use ($pdo, $uid, $s, $ant): string {
        if (!$ant['hay_datos']) return '';
        $v = motor_tambo_valor($pdo, $uid, $met, $s);
        $a = motor_tambo_valor($pdo, $uid, $met, $ant);
        return ($v === null || $a === null || $a == 0.0) ? '' : ' (' . motor_variacion($a, $v) . ' que el mes anterior)';
    };

    $m = motor_tambo_valor($pdo, $uid, 'margen_bruto', $s);
    $resp = ($m >= 0 ? 'En ' . $etq . ' el tambo dejó ' . motor_tambo_fmt($m, 'dinero') . ' de margen bruto'
                     : 'En ' . $etq . ' el tambo perdió ' . motor_tambo_fmt(abs($m), 'dinero'))
          . ', con una rentabilidad de ' . motor_tambo_fmt($s['rentabilidad'], 'pct') . '.';

    $lineas = [
        $vs('margen_bruto') !== '' ? '• El margen quedó ' . trim($vs('margen_bruto'), ' ()') . '.' : '',
        $s['litros'] > 0 ? '• Litros: ' . motor_tambo_fmt($s['litros'], 'litros') . $vs('litros') . '.' : '• Sin litros de leche cargados.',
        '• Ingresos: ' . motor_tambo_fmt(motor_tambo_valor($pdo, $uid, 'ingresos', $s), 'dinero') . $vs('ingresos') . '.',
        '• Costos: ' . motor_tambo_fmt(motor_tambo_valor($pdo, $uid, 'costos', $s), 'dinero') . $vs('costos') . '.',
    ];
    if ($s['litros'] > 0) {
        $lineas[] = '• El litro costó ' . motor_tambo_fmt(motor_tambo_valor($pdo, $uid, 'costo_litro', $s), 'dinero_unit')
                  . ' y se pagó ' . motor_tambo_fmt(motor_tambo_valor($pdo, $uid, 'precio_litro', $s), 'dinero_unit') . '.';
    }
    if ($p = motor_tambo_lo_que_mas_pesa($s)) $lineas[] = '• ' . $p;

    return motor_tambo_r([
        'tipo' => 'resumen', 'respuesta' => $resp,
        'detalle' => motor_tambo_juntar(implode("\n", $lineas), motor_tambo_nota_dolar($s)),
        'filtros' => $filtros,
        'link' => 'tambo.php?mes=' . $mes, 'link_texto' => 'Ver en el panel', 'link_icono' => 'fa-table-columns',
        'sugerencias' => ['¿En qué gasté más?', '¿Y contra el mes anterior?', 'Margen de los últimos 6 meses'],
    ]);
}

/**
 * ¿La pregunta nombra algo propio del tambo aunque suene a agricultura?
 * "¿cuánto gasté en maíz?" es del tambo si el productor tiene un concepto "Maíz".
 */
function motor_tambo_reconoce_nombre(PDO $pdo, int $uid, string $t): bool {
    return motor_tambo_detectar_rubro($t, tambo_categorias($pdo, $uid), tambo_conceptos($pdo, $uid)) !== null;
}
