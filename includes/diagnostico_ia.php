<?php
/**
 * includes/diagnostico_ia.php
 *
 * "¿Qué tiene esta planta?" — la foto de un cultivo o de un bicho, mandada desde
 * el chat (api/diagnostico_ia.php). Funciones puras: armar el pedido y convertir
 * la respuesta del modelo en una respuesta del chat.
 *
 * LO QUE NO HACE, A PROPÓSITO
 * ───────────────────────────
 * No recomienda productos comerciales ni dosis. En Argentina el producto tiene
 * que estar registrado en SENASA para ese cultivo, en casi todas las provincias
 * hace falta receta agronómica, y una dosis equivocada quema el cultivo o deja
 * residuos. Un modelo puede nombrar con total seguridad un producto que no está
 * registrado para soja o inventar una dosis. Por eso:
 *
 *   · se le pide que no lo haga (instrucciones);
 *   · y además se FILTRA acá: cualquier renglón que traiga una dosis se tira,
 *     diga lo que diga el modelo. La regla no puede depender de que obedezca;
 *   · y el cierre que manda a confirmar con el ingeniero agrónomo y el marbete
 *     lo agrega el servidor, siempre, no el modelo.
 *
 * Los principios activos sí van, como ORIENTACIÓN para charlar con el agrónomo.
 *
 * LA MISMA CÁMARA LEE REMITOS
 * ───────────────────────────
 * El productor no tiene por qué saber qué botón va con qué foto. Si lo que
 * manda es un remito, se lo lee con las mismas reglas que la pantalla de
 * Insumos (imp_ia_reglas_remito) y se le ofrece llevarlo ahí: la carga NO se
 * hace en el chat. La revisión renglón por renglón, el cruce con el stock, la
 * moneda y la suma contra el total viven en Insumos, y duplicarlos en una
 * burbuja sería peor y habría que mantenerlos en dos lugares.
 */

require_once __DIR__ . '/importador_insumos.php';

/** Lo que se le pide al modelo: primero qué es la foto, después lo que corresponda. */
function dx_instrucciones(): string
{
    return "Te mandan una foto desde el campo. Primero decidí qué es:\n"
         . "A) una planta, un cultivo, una hoja, una maleza o un insecto: respondé el JSON de DIAGNÓSTICO.\n"
         . "B) un remito, una factura o una lista de INSUMOS que van al depósito (semillas, fertilizantes, agroquímicos, inoculantes): respondé el JSON de REMITO.\n"
         . "C) una factura o un ticket de un GASTO que no es mercadería para el depósito (gasoil, flete, una labor contratada como siembra, cosecha o pulverización, una reparación, honorarios): respondé el JSON de GASTO.\n"
         . "D) cualquier otra cosa: respondé {\"clase\": \"otro\"}.\n\n"
         . "=== A) DIAGNÓSTICO ===\n" . dx_reglas_diagnostico() . "\n\n"
         . "=== B) REMITO ===\n"
         . "Transcribí los renglones de mercadería del comprobante.\n" . imp_ia_reglas_remito() . "\n\n"
         . "=== C) GASTO ===\n" . dx_reglas_gasto();
}

function dx_reglas_gasto(): string
{
    return <<<TXT
Leé el comprobante. Copiá lo que está escrito, no inventes: si un dato no se lee, null.
- "concepto": qué se pagó, en dos o tres palabras ("gasoil", "flete", "siembra contratada", "reparación de tractor").
- "total": el TOTAL FINAL a pagar, como número JSON con punto decimal (en el papel "1.250.000,50" es 1250000.5).
- "moneda": "ARS" o "USD" si figura en el papel; si no, null.
- "fecha": la fecha del comprobante, AAAA-MM-DD.
- "proveedor": quien emite el comprobante.

Respondé SÓLO con este JSON, sin texto antes ni después:
{"clase": "gasto", "concepto": "gasoil", "total": 850000, "moneda": "ARS", "fecha": "2026-08-14", "proveedor": "YPF Agro"}
TXT;
}

function dx_reglas_diagnostico(): string
{
    return <<<TXT
Sos un asesor de sanidad vegetal para productores agropecuarios de Argentina. Decí qué es.

Reglas:
- Usá los nombres comunes que se usan en Argentina (isoca, chinche, oruga bolillera, roya, mancha ojo de rana...) y el científico entre paréntesis.
- Si no estás seguro, decilo: confianza "baja", y en "confusiones" con qué se puede confundir. Es mejor decir que no se sabe que inventar.
- Si es un insecto BENÉFICO (vaquita, crisopa, sírfido, avispa parasitoide, arañas...), tipo "benefico": el productor no tiene que matarlo.
- NUNCA pongas marcas comerciales ni dosis. En "principios_activos" sólo nombres de principios activos que se usan habitualmente en Argentina para esa plaga en ese cultivo; si no estás seguro, dejá la lista vacía.
- "umbral": cuándo conviene actuar (umbral de daño económico, según INTA si lo conocés). Si no lo sabés, null.
- "confirmar": qué mirar en el lote para confirmar el diagnóstico.
- Si la foto no alcanza (borrosa, lejos, falta ver el envés), decí en "pedir_otra_foto" qué foto sacar.
- Frases cortas, en castellano rioplatense.

Respondé SÓLO con este JSON, sin texto antes ni después:
{"clase": "planta", "es_planta": true, "cultivo": "soja", "diagnostico": "Oruga bolillera (Helicoverpa gelotopoeon)", "tipo": "plaga", "confianza": "media", "confusiones": [], "senales": [], "confirmar": [], "umbral": null, "manejo": [], "principios_activos": [], "pedir_otra_foto": null}

"tipo" es uno de: plaga, enfermedad, maleza, deficiencia, dano, benefico, sano, incierto.
"es_planta" es false si en la foto no hay una planta, un cultivo ni un insecto.
TXT;
}

/** ¿El texto trae una dosis? "300 cc/ha", "1,5 l por hectárea", "20 g". */
function dx_tiene_dosis(string $s): bool
{
    return (bool)preg_match(
        '/\d+(?:[.,]\d+)?\s*(?:cc|cm3|ml|l|lt|lts|litros?|g|gr|grs|gramos?|kg|kilos?)\b/iu', $s);
}

/** Texto limpio y acotado, o null. */
function dx_texto($v, int $max = 300): ?string
{
    if (!is_string($v)) return null;
    $v = trim(preg_replace('/\s+/u', ' ', $v));
    if ($v === '' || dx_tiene_dosis($v)) return null;
    return mb_substr($v, 0, $max, 'UTF-8');
}

/** Lista de textos limpios, sin dosis, con tope. */
function dx_lista($v, int $max = 6): array
{
    if (!is_array($v)) return [];
    $out = [];
    foreach ($v as $item) {
        $t = dx_texto($item, 200);
        if ($t !== null) $out[] = $t;
        if (count($out) >= $max) break;
    }
    return $out;
}

/** Lo que se contesta cuando la foto no es nada que se sepa mirar. */
function dx_no_reconocida(): array
{
    return ['ok' => true,
            'respuesta' => 'No reconozco qué hay en la foto.',
            'detalle' => 'Puedo mirar una planta, una hoja, una maleza o un bicho, o leer un remito de insumos.'];
}

/**
 * La respuesta del modelo a la foto del chat, sea lo que sea.
 *
 * @param bool $puedeStock  si el productor tiene Agricultura (ahí está Insumos);
 *                          sin eso no se le ofrece un enlace que no puede abrir.
 * @return array|null  null si no tiene la forma pedida (se prueba otro proveedor).
 */
function dx_a_respuesta_foto(array $r, bool $puedeStock, bool $puedeGasto = false): ?array
{
    $clase = $r['clase'] ?? null;
    if ($clase === 'otro') return dx_no_reconocida();
    if ($clase === 'gasto') return dx_gasto_a_respuesta($r, $puedeGasto);
    if ($clase === 'remito' || array_key_exists('renglones', $r)) return dx_remito_a_respuesta($r, $puedeStock);
    return dx_a_respuesta($r);
}

/**
 * La frase de carga de un gasto, armada con lo que se leyó de la factura.
 *
 * Es el mismo truco que el remito: la IA transcribe, y lo transcrito entra por
 * la puerta que ya existe. Acá esa puerta es la carga de gastos del chat
 * ("gasté 850.000 pesos en gasoil el 14/08/2026"): el motor la interpreta,
 * pregunta lo que falta —la etapa, el lote— y no guarda nada sin la
 * confirmación. La IA no toca la contabilidad.
 *
 * El formato está pensado para lo que lee el motor: el monto siempre con
 * centavos, porque motor_detectar_monto() no toma un número pelado de menos
 * de cuatro cifras (para no confundirlo con una cantidad), y la moneda en
 * letras, que es lo que mira motor_moneda_dicha().
 *
 * @return string|null  null si no hay con qué armarla (sin monto o sin concepto).
 */
function dx_frase_gasto(array $r): ?string
{
    $total = imp_ia_numero($r['total'] ?? null);
    if ($total === null || $total <= 0) return null;

    /* El concepto, sin números: "gasoil 500 lts" le daría al motor una dosis
       de 500 y lo tomaría por la aplicación de un insumo. Tampoco la moneda,
       que va aparte y no tiene que aparecer dos veces. */
    // No pasa por dx_texto(): ésa tira el texto entero si trae una cantidad, y
    // acá hay que sacar la cantidad y quedarse con el resto ("gasoil").
    $concepto = is_string($r['concepto'] ?? null) ? mb_substr($r['concepto'], 0, 60, 'UTF-8') : '';
    $concepto = preg_replace('/\d+(?:[.,]\d+)*\s*(?:lts?|litros?|kgs?|kilos?|cc|ml|l|tn|toneladas?)?\b/iu', ' ', $concepto);
    $concepto = preg_replace('/\b(pesos|d[oó]lares|usd|ars)\b/iu', ' ', $concepto);
    $concepto = trim(preg_replace('/\s+/u', ' ', $concepto), " .,;:-");
    if (mb_strlen($concepto, 'UTF-8') < 3) return null;

    $moneda = ['ARS' => ' pesos', 'USD' => ' dólares'][$r['moneda'] ?? ''] ?? '';
    $frase = 'Gasté ' . number_format($total, 2, ',', '.') . $moneda . ' en ' . mb_strtolower($concepto, 'UTF-8');

    // Sólo una fecha que existe y no está adelante: el motor rechaza las futuras.
    $f = is_string($r['fecha'] ?? null) ? imp_a_fecha($r['fecha']) : null;
    if ($f !== null && $f <= date('Y-m-d')) $frase .= ' el ' . date('d/m/Y', strtotime($f));

    return $frase;
}

/**
 * Una factura de gasto mandada al chat: lo que se leyó y, como sugerencia para
 * tocar, la frase de carga. Se ofrece, no se arranca sola: el productor ve
 * exactamente qué se va a interpretar antes de empezar.
 *
 * @param bool $puedeGasto  si el chat desde el que la mandó carga gastos de
 *                          agricultura (en el tambo los egresos son otra cosa).
 */
function dx_gasto_a_respuesta(array $r, bool $puedeGasto): ?array
{
    $frase = dx_frase_gasto($r);
    if ($frase === null) return null;   // sin monto o sin concepto: que pruebe otro proveedor

    $prov = dx_texto($r['proveedor'] ?? null, 80);
    $respuesta = 'Es una factura' . ($prov ? ' de ' . $prov : '') . ': '
               . preg_replace('/^Gasté /u', '', $frase) . '.';

    if (!$puedeGasto) {
        return ['ok' => true, 'respuesta' => $respuesta,
                'detalle' => 'Para cargarlo como gasto, mandala desde el chat de una pantalla de Agricultura.'];
    }
    return [
        'ok'          => true,
        'respuesta'   => $respuesta . ' ¿Lo cargo como gasto?',
        'detalle'     => 'Tocá la frase de abajo y te pregunto lo que falta (la etapa y el lote). '
                       . 'Nada se guarda hasta que lo confirmes.',
        'sugerencias' => [$frase],
    ];
}

/**
 * Un remito mandado al chat: lo que se leyó y el enlace a Insumos para
 * revisarlo y cargarlo. Los renglones viajan en `remito` y la pantalla los deja
 * en sessionStorage para que Insumos los abra (ver insumos.php, importar=ia).
 */
function dx_remito_a_respuesta(array $r, bool $puedeStock): ?array
{
    $leido = imp_ia_a_texto($r);
    if ($leido === null) return null;
    if (!$leido['es_remito']) return dx_no_reconocida();
    // Dice que es un remito pero no sacó ni un renglón: que pruebe otro proveedor.
    if ($leido['renglones'] === 0) return null;

    $prov = dx_texto($r['proveedor'] ?? null, 80);
    $n = $leido['renglones'];
    $respuesta = 'Es un remito' . ($prov ? ' de ' . $prov : '') . ' con ' . $n
               . ($n === 1 ? ' renglón.' : ' renglones.');

    $detalle = '• ' . implode("\n• ", explode("\n", $leido['texto']));
    if (!$puedeStock) {
        return ['ok' => true, 'respuesta' => $respuesta,
                'detalle' => $detalle . "\n\nLa carga de stock está en el módulo de Agricultura."];
    }
    return [
        'ok'         => true,
        'respuesta'  => $respuesta . ' ¿Lo cargo en el stock?',
        'detalle'    => $detalle . "\n\nLo leyó una IA: en la pantalla de Insumos revisás cada renglón antes de guardar.",
        'link'       => 'insumos.php?importar=ia',
        'link_texto' => 'Revisar y cargar en Stock',
        'link_icono' => 'fa-boxes-stacked',
        'remito'     => ['texto' => $leido['texto'], 'moneda' => $leido['moneda']],
    ];
}

/**
 * La respuesta del modelo, ya decodificada, a una respuesta del chat: la misma
 * forma que devuelve el motor ({ok, respuesta, detalle}), así la pantalla la
 * muestra con lo que ya tiene.
 *
 * @return array|null  null si no tiene la forma pedida (se prueba otro proveedor).
 */
function dx_a_respuesta(array $r): ?array
{
    if (!array_key_exists('es_planta', $r) && !array_key_exists('diagnostico', $r)) return null;

    $pedirFoto = dx_texto($r['pedir_otra_foto'] ?? null);

    if (($r['es_planta'] ?? true) === false) {
        return ['ok' => true,
                'respuesta' => 'No encontré una planta ni un bicho en la foto.',
                'detalle' => $pedirFoto ?? 'Probá con una foto más de cerca, con buena luz. También puedo leer un remito de insumos.'];
    }

    $tipos = ['plaga', 'enfermedad', 'maleza', 'deficiencia', 'dano', 'benefico', 'sano', 'incierto'];
    $tipo  = in_array($r['tipo'] ?? null, $tipos, true) ? $r['tipo'] : 'incierto';
    $conf  = in_array($r['confianza'] ?? null, ['alta', 'media', 'baja'], true) ? $r['confianza'] : 'baja';
    $diag  = dx_texto($r['diagnostico'] ?? null, 120);
    $cult  = dx_texto($r['cultivo'] ?? null, 60);

    $senales    = dx_lista($r['senales'] ?? null);
    $confusion  = dx_lista($r['confusiones'] ?? null, 4);
    $confirmar  = dx_lista($r['confirmar'] ?? null);
    $manejo     = dx_lista($r['manejo'] ?? null);
    $activos    = dx_lista($r['principios_activos'] ?? null, 8);
    $umbral     = dx_texto($r['umbral'] ?? null);

    $secciones = [];
    $bloque = function (string $titulo, array $items) use (&$secciones) {
        if ($items) $secciones[] = $titulo . "\n• " . implode("\n• ", $items);
    };

    if ($tipo === 'sano') {
        $bloque('Lo que veo:', $senales);
        return ['ok' => true, 'respuesta' => 'La planta se ve sana.',
                'detalle' => implode("\n\n", $secciones)];
    }

    if ($tipo === 'benefico') {
        $bloque('Cómo lo reconocés:', $senales);
        $secciones[] = 'Se come a las plagas o las parasita: no hace falta controlarlo, y una aplicación lo mata junto con ellas.';
        return ['ok' => true,
                'respuesta' => 'Parece ' . ($diag ?? 'un insecto benéfico') . '. Es un aliado.',
                'detalle' => implode("\n\n", $secciones)];
    }

    if ($tipo === 'incierto' || $diag === null) {
        $bloque('Puede ser:', $confusion);
        if ($pedirFoto) $secciones[] = 'Para saberlo: ' . $pedirFoto;
        return ['ok' => true, 'respuesta' => 'No me animo a decir qué es con esta foto.',
                'detalle' => implode("\n\n", $secciones)];
    }

    $nombreTipo = ['plaga' => 'plaga', 'enfermedad' => 'enfermedad', 'maleza' => 'maleza',
                   'deficiencia' => 'deficiencia nutricional', 'dano' => 'daño'][$tipo];
    $respuesta = 'Parece ' . $diag . ' (' . $nombreTipo . ($cult ? ', en ' . $cult : '') . ').'
               . ' Confianza ' . $conf . '.';

    $bloque('Lo que veo:', $senales);
    $bloque('También podría ser:', $confusion);
    $bloque('Para confirmarlo en el lote:', $confirmar);
    if ($umbral) $secciones[] = "Cuándo actuar:\n" . $umbral;
    $bloque('Manejo:', $manejo);
    $bloque('Principios activos que se usan (orientativo):', $activos);
    if ($pedirFoto) $secciones[] = 'Para estar más seguro: ' . $pedirFoto;

    // Lo pone el servidor, siempre: no depende de que el modelo se acuerde.
    $secciones[] = 'Antes de aplicar, confirmalo con tu ingeniero agrónomo: el producto tiene que estar '
                 . 'registrado en SENASA para tu cultivo, y la dosis es la del marbete.';

    return ['ok' => true, 'respuesta' => $respuesta, 'detalle' => implode("\n\n", $secciones)];
}
