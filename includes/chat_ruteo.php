<?php
/**
 * includes/chat_ruteo.php
 *
 * Qué motor contesta: el de agricultura (motor.php) o el del tambo (motor_tambo.php).
 *
 * La pantalla decide. En una pantalla del tambo contesta el tambo, en una de
 * agricultura contesta agricultura. Sólo se cambia al otro módulo cuando la
 * pregunta es inequívoca —habla de vacas, o de lotes— y el productor tiene ese
 * módulo habilitado. Una pregunta que existe en los dos ("¿cuál es mi margen?")
 * nunca se adivina: contesta la pantalla y se ofrece "¿Y en agricultura?".
 *
 * Por qué no adivinar siempre: el error de un chat que elige mal el módulo no es
 * un "no entendí", es un margen del tambo presentado como el de la campaña.
 */

require_once __DIR__ . '/motor.php';

/** Palabras que sólo existen en un módulo. Ya normalizadas. */
function chat_ruteo_senales(): array {
    return [
        'tambo' => ['tambo','tambero','leche','vaca','vacas','ordene','ordenie','guachera','vaquillona','vaquillonas',
                    'ternero','terneros','ternera','terneras','rodeo','celulas somaticas','rcs','ufc','tenor graso',
                    'grasa butirosa','proteina','diferencia de inventario','litros por vaca','precio de la leche',
                    'costo por litro','margen por litro'],
        'agricultura' => ['agricultura','lote','lotes','hectarea','hectareas','campana','cultivo','cultivos','soja',
                    'maiz','trigo','girasol','sorgo','cebada','siembra','sembre','cosecha','coseche','pulverizacion',
                    'fertilizacion','fertilizante','agroquimico','agroquimicos','semilla','semillas','glifosato',
                    'insumo','insumos','stock','quintales','kg por hectarea','kilos por hectarea','clima','lluvia',
                    'llovio','pronostico','pizarra','costo por hectarea','rinde por hectarea'],
    ];
}

/**
 * El módulo del que habla la pregunta, sólo si es uno solo.
 * Si nombra cosas de los dos, o de ninguno, devuelve null y decide la pantalla.
 */
function chat_ruteo_modulo(string $t): ?string {
    $hay = [];
    foreach (chat_ruteo_senales() as $modulo => $palabras) {
        foreach ($palabras as $p) {
            if (preg_match('/(?<!\S)' . preg_quote($p, '/') . '(?!\S)/u', $t)) { $hay[$modulo] = true; break; }
        }
    }
    // "25/26" es una campaña: sólo existe en agricultura.
    if (preg_match('/(?<!\d)\d{2}\/\d{2}(?!\d)/', $t)) $hay['agricultura'] = true;
    return count($hay) === 1 ? array_key_first($hay) : null;
}

/** La frase sin el nombre del módulo: "y en agricultura" → "y en". */
function chat_ruteo_sin_modulo(string $t): string {
    $t = preg_replace('/(?<!\S)(?:en\s+)?(?:el\s+|la\s+)?(?:agricultura|tambo)(?!\S)/u', ' ', $t);
    return trim(preg_replace('/\s+/u', ' ', $t));
}

/**
 * Contesta desde la pantalla $pagina ('tambo' | 'agricultura'), cambiando de
 * motor si corresponde. Devuelve la respuesta con 'modulo' = quién contestó.
 */
function chat_responder(PDO $pdo, int $uid, string $pregunta, array $contexto, string $pagina): array {
    $modulos = $_SESSION['modulos'] ?? [];
    $tiene   = fn(string $m): bool => !empty($modulos[$m]);
    $otro    = $pagina === 'tambo' ? 'agricultura' : 'tambo';
    $nombres = ['tambo' => 'el tambo', 'agricultura' => 'Agricultura'];

    $t        = motor_normalizar($pregunta);
    $destino  = $pagina;
    $preg     = $pregunta;
    $ctx      = $contexto;
    $repregunta = false;

    // Una carga a medio completar no se rutea nunca: lo que se escribe es la
    // respuesta a un casillero ("vacas" puede ser el nombre de un lote).
    if (empty($contexto['alta']) && chat_ruteo_modulo($t) === $otro) {
        $resto = chat_ruteo_sin_modulo($t);
        // "¿y en agricultura?" a secas: la misma pregunta de recién, en el otro módulo.
        $repregunta = ($resto === '' || motor_resto_es_relleno($resto)) && !empty($contexto['previa']);

        // "¿cuánto gasté en maíz?" es del tambo si el productor cargó un concepto "Maíz".
        // Y al revés: un lote llamado "La Vaca" es de agricultura.
        $propia = false;
        if (!$repregunta && $pagina === 'tambo') {
            require_once __DIR__ . '/motor_tambo.php';
            $propia = motor_tambo_reconoce_nombre($pdo, $uid, $t);
        } elseif (!$repregunta && $tiene($otro)) {
            foreach (motor_lotes_del_usuario($pdo, $uid) as $lote) {
                if (motor_nombra($t, motor_normalizar((string)$lote['nombre']))) { $propia = true; break; }
            }
        }

        if (!$propia) {
            if (!$tiene($otro)) {
                // Desde agricultura, sin tambo, no cambia nada respecto de antes.
                if ($pagina === 'tambo') {
                    return [
                        'ok' => false, 'tipo' => 'otro_modulo', 'modulo' => $pagina,
                        'respuesta' => 'Eso es de Agricultura, y no lo tenés habilitado.',
                        'detalle' => 'Acá te contesto sobre el tambo: litros, costos, margen, rodeo o calidad.',
                        'valor' => null, 'filtros' => [], 'link' => null,
                        'sugerencias' => ['¿Cómo vengo?', '¿En qué gasté más?'],
                    ];
                }
            } elseif ($pagina === 'tambo' && motor_pide_alta($t)) {
                // Las cargas de agricultura tienen su paso a paso y sus puertas; desde
                // el tambo sólo se consulta.
                return [
                    'ok' => false, 'tipo' => 'otro_modulo', 'modulo' => $pagina,
                    'respuesta' => 'Esa carga es de Agricultura: hacela desde el chat de sus pantallas.',
                    'detalle' => 'Desde el tambo te contesto consultas de los dos módulos, pero las cargas van cada una en lo suyo.',
                    'valor' => null, 'filtros' => [],
                    'link' => 'operaciones.php', 'link_texto' => 'Ir a Costos y Labores', 'link_icono' => 'fa-tractor',
                    'sugerencias' => [],
                ];
            } else {
                $destino = $otro;
                if ($repregunta) $preg = (string)$contexto['previa'];
                // El contexto de la pantalla (mes, lote, campaña) no sirve en el otro módulo.
                $ctx = [];
            }
        }
    }

    if ($destino === 'tambo') {
        require_once __DIR__ . '/motor_tambo.php';
        $r = motor_tambo_responder($pdo, $uid, $preg, $ctx);
    } else {
        $r = motor_responder($pdo, $uid, $preg, $ctx);
    }
    $r['modulo'] = $destino;

    if ($destino !== $pagina) {
        // Quien contestó fue el otro módulo: nada de cargas guiadas desde acá.
        unset($r['alta'], $r['alta_pendiente']);
        if ($repregunta) $r['previa'] = $preg;
    }

    // La pregunta existe en los dos módulos y el productor tiene los dos: se ofrece el otro.
    if ($tiene('agricultura') && $tiene('tambo') && !empty($r['ok'])) {
        $compartidas = [
            'tambo'       => ['margen_bruto', 'rentabilidad', 'costos', 'ingresos'],
            'agricultura' => ['margen_neto', 'costos_directos', 'ingresos'],
        ];
        $metrica = $r['filtros']['metrica'] ?? null;
        $conRecorte = !empty($r['filtros']['rubro']) || !empty($r['filtros']['lote']) || !empty($r['filtros']['cultivo']);
        if ($metrica && !$conRecorte && in_array($metrica, $compartidas[$destino], true)) {
            $chip = $destino === 'tambo' ? '¿Y en agricultura?' : '¿Y en el tambo?';
            $r['sugerencias'] = array_values(array_unique(array_merge([$chip], $r['sugerencias'] ?? [])));
        }
    }

    return $r;
}
