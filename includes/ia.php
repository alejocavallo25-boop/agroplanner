<?php
/**
 * includes/ia.php
 *
 * La cadena de proveedores de IA. Es un COMPLEMENTO: nada del sistema depende
 * de que esto ande. Quien lo usa tiene siempre un camino propio para cuando
 * devuelve que no (el lector de fotos del teléfono, el motor del chat).
 *
 * Cómo funciona
 * ─────────────
 *   · Una sola clave por proveedor, la del dueño de la app, en el .env del
 *     servidor. El productor no ve ni carga nada.
 *   · Los proveedores se prueban en orden. Si uno contesta "límite alcanzado"
 *     (429) o "saturado" (503) queda en pausa IA_ENFRIAR_MINUTOS para todos, así no
 *     se pierde tiempo golpeando una puerta cerrada; si tarda, da error o
 *     contesta algo que no sirve, se pasa al siguiente.
 *   · Todos hablan el formato de OpenAI (chat/completions), así que cada uno es
 *     una URL, una clave y un modelo. Sumar otro es sumar un renglón.
 *   · El cupo gratis es de toda la app, no de cada productor: por eso hay un
 *     tope diario por usuario, para que uno solo no se lo coma.
 *
 * Variables del .env (las que falten, ese proveedor se saltea):
 *   IA_GEMINI_CLAVE      IA_GEMINI_MODELO      (por defecto gemini-flash-latest)
 *                        IA_GEMINI_MODELO_LITE (por defecto gemini-flash-lite-latest; misma clave)
 *   IA_GROQ_CLAVE        IA_GROQ_MODELO        (por defecto qwen/qwen3.8-27b, que lee imágenes)
 *   IA_OPENROUTER_CLAVE  IA_OPENROUTER_MODELO  (por defecto nvidia/nemotron-3.5-super-vl:free)
 *
 * Cada intento queda en la tabla `ia_uso`: qué proveedor, cómo le fue y cuánto
 * tardó. Sirve para el tope por usuario, para la pausa por límite y para ver si
 * el cupo alcanza.
 */

const IA_TOPE_DIARIO_USUARIO = 30;   // pedidos por productor por día (fotos: remitos y plagas)
/* El dictado cuenta aparte: se usa mucho más que las fotos, es cortito y sale
   de otro cupo (Whisper en Groq). Si compartiera el tope, diez dictados de la
   mañana dejaban al productor sin poder leer un remito a la tarde. */
const IA_TOPE_DIARIO_VOZ     = 200;
const IA_ENFRIAR_MINUTOS     = 10;   // pausa de un proveedor tras un 429
/* Espera máxima de un proveedor. Sano, Gemini Flash leyó un remito en 8 s y el
   liviano en 3; saturado tardaba 24 s en contestar que no, o no contestaba.
   Más de 20 s es que anda mal: se corta y se pasa al siguiente. */
const IA_TIMEOUT_SEGUNDOS    = 20;
/* Tiempo total de un pedido, sumando proveedores. Pasado esto no se prueba el
   siguiente: se devuelve que no, y la pantalla lee con el teléfono. Sin techo,
   cuatro proveedores lentos eran dos minutos con un proceso del servidor
   tomado, y el hosting tiene un tope de procesos simultáneos por cuenta. */
const IA_PRESUPUESTO_SEGUNDOS = 40;

/** Los proveedores configurados, en el orden en que se prueban. */
function ia_proveedores(array $env): array
{
    $todos = [
        // Primero Gemini: es el que mejor lee una foto y el de cupo gratis más grande.
        ['nombre' => 'gemini',
         'url'    => 'https://generativelanguage.googleapis.com/v1beta/openai/chat/completions',
         'clave'  => $env['IA_GEMINI_CLAVE'] ?? '',
         'modelo' => $env['IA_GEMINI_MODELO'] ?? 'gemini-flash-latest'],
        /* La misma clave con el modelo liviano. Tiene su propio cupo y su propia
           capacidad: cuando el Flash da "mucha demanda" (503), que en el plan
           gratis pasa por rachas de minutos, éste suele estar libre. */
        ['nombre' => 'gemini-lite',
         'url'    => 'https://generativelanguage.googleapis.com/v1beta/openai/chat/completions',
         'clave'  => $env['IA_GEMINI_CLAVE'] ?? '',
         'modelo' => $env['IA_GEMINI_MODELO_LITE'] ?? 'gemini-flash-lite-latest'],
        ['nombre' => 'groq',
         'url'    => 'https://api.groq.com/openai/v1/chat/completions',
         'clave'  => $env['IA_GROQ_CLAVE'] ?? '',
         // Llama 4 Scout, el de imágenes de antes, Groq lo dio de baja (404 en
         // octubre de 2026). Qwen lee la foto en un par de segundos.
         'modelo' => $env['IA_GROQ_MODELO'] ?? 'qwen/qwen3.8-27b',
         /* El plan gratis limita la respuesta a 1000 tokens POR MINUTO, y sin
            tope cada pedido reservaba 1016: Groq lo rechazaba entero con un 429
            que parecía cupo agotado y no lo era. 900 alcanza para un remito de
            unos veinte renglones. */
         'max_tokens' => 900],
        ['nombre' => 'openrouter',
         'url'    => 'https://openrouter.ai/api/v1/chat/completions',
         'clave'  => $env['IA_OPENROUTER_CLAVE'] ?? '',
         /* Un modelo fijo, y que NO sea de Google. El enrutador "openrouter/free"
            elegía cualquiera de los gratis y una vez cayó en uno de moderación
            (contestó "User Safety: safe"). Y el Gemma gratis lo sirve Google:
            se satura junto con Gemini, que es justo cuando hace falta el
            respaldo. Nemotron VL leyó bien el remito y una factura. */
         'modelo' => ($env['IA_OPENROUTER_MODELO'] ?? '') ?: 'nvidia/nemotron-3.5-super-vl:free'],
    ];
    return array_values(array_filter($todos, fn($p) => trim($p['clave']) !== '' && trim($p['modelo']) !== ''));
}

function ia_disponible(array $env): bool
{
    return (bool)ia_proveedores($env);
}

function ia_asegurar_tabla(PDO $pdo): void
{
    static $lista = false;
    if ($lista) return;

    $pdo->exec("CREATE TABLE IF NOT EXISTS `ia_uso` (
        `id`         INT(11)      NOT NULL AUTO_INCREMENT,
        `usuario_id` INT(11)      NOT NULL,
        `pedido`     CHAR(16)     NOT NULL,
        `uso`        VARCHAR(20)  NOT NULL,
        `proveedor`  VARCHAR(20)  NOT NULL,
        `resultado`  VARCHAR(12)  NOT NULL,
        `ms`         INT(11)      NOT NULL DEFAULT 0,
        `detalle`    VARCHAR(255) NULL,
        `creado_en`  TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`),
        KEY `idx_usuario_fecha` (`usuario_id`, `creado_en`),
        KEY `idx_proveedor` (`proveedor`, `resultado`, `creado_en`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $lista = true;
}

/**
 * Cuántos pedidos hizo hoy el productor (un pedido con tres intentos cuenta
 * uno). El dictado se cuenta aparte del resto: ver IA_TOPE_DIARIO_VOZ.
 */
function ia_pedidos_hoy(PDO $pdo, int $usuario_id, string $uso = ''): int
{
    $filtro = $uso === 'voz' ? "uso = 'voz'" : "uso <> 'voz'";
    $st = $pdo->prepare("SELECT COUNT(DISTINCT pedido) FROM ia_uso
                          WHERE usuario_id = ? AND creado_en >= CURDATE() AND $filtro");
    $st->execute([$usuario_id]);
    return (int)$st->fetchColumn();
}

function ia_tope_alcanzado(PDO $pdo, int $usuario_id, string $uso): bool
{
    $tope = $uso === 'voz' ? IA_TOPE_DIARIO_VOZ : IA_TOPE_DIARIO_USUARIO;
    return ia_pedidos_hoy($pdo, $usuario_id, $uso) >= $tope;
}

/** ¿Este proveedor contestó "límite alcanzado" o "saturado" hace poco? */
function ia_en_pausa(PDO $pdo, string $proveedor): bool
{
    $st = $pdo->prepare("SELECT 1 FROM ia_uso
                          WHERE proveedor = ? AND resultado IN ('limite', 'saturado')
                            AND creado_en > NOW() - INTERVAL " . IA_ENFRIAR_MINUTOS . " MINUTE
                          LIMIT 1");
    $st->execute([$proveedor]);
    return (bool)$st->fetchColumn();
}

function ia_registrar(PDO $pdo, int $usuario_id, string $pedido, string $uso,
                      string $proveedor, string $resultado, int $ms, ?string $detalle = null): void
{
    $st = $pdo->prepare("INSERT INTO ia_uso (usuario_id, pedido, uso, proveedor, resultado, ms, detalle)
                         VALUES (?, ?, ?, ?, ?, ?, ?)");
    $st->execute([$usuario_id, $pedido, $uso, $proveedor, $resultado, $ms,
                  $detalle === null ? null : mb_substr($detalle, 0, 255, 'UTF-8')]);
}

/**
 * Un pedido a un proveedor.
 *
 * @return array{estado:string, texto:?string, detalle:?string}
 *         estado: 'ok' | 'limite' (429) | 'saturado' (503) | 'error' (cualquier otra cosa)
 */
function ia_pedir(array $prov, array $mensajes): array
{
    $pedido = [
        'model'       => $prov['modelo'],
        'messages'    => $mensajes,
        'temperature' => 0,   // transcribir, no crear
    ];
    if (!empty($prov['max_tokens'])) $pedido['max_tokens'] = $prov['max_tokens'];
    $cuerpo = json_encode($pedido, JSON_UNESCAPED_UNICODE);

    $ch = curl_init($prov['url']);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $cuerpo,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 8,
        CURLOPT_TIMEOUT        => IA_TIMEOUT_SEGUNDOS,
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $prov['clave'],
            // OpenRouter los usa para identificar la app; los demás los ignoran.
            'HTTP-Referer: https://agroplanner.online',
            'X-Title: AgroPlanner',
        ],
    ]);
    $resp  = curl_exec($ch);
    $http  = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $falla = curl_error($ch);
    $nro   = curl_errno($ch);

    if ($resp === false) {
        // Se le acabó el tiempo: es el mismo cuadro que un 503, y va a la misma pausa.
        $estado = $nro === CURLE_OPERATION_TIMEDOUT ? 'saturado' : 'error';
        return ['estado' => $estado, 'texto' => null, 'detalle' => 'red: ' . $falla];
    }
    if ($http === 429) {
        return ['estado' => 'limite', 'texto' => null, 'detalle' => 'HTTP 429'];
    }
    /* 503 es "mucha demanda". En el plan gratis de Gemini no es un pico de
       segundos: se lo vio durar más de media hora, y cada intento tardaba
       entre 10 y 20 segundos en contestar que no. Reintentar sólo hacía
       esperar; se lo pone en pausa como a un 429 y se pasa al siguiente. */
    if ($http === 503) {
        return ['estado' => 'saturado', 'texto' => null, 'detalle' => 'HTTP 503'];
    }
    $json = json_decode($resp, true);
    if ($http !== 200 || !is_array($json)) {
        $msg = is_array($json) ? ($json['error']['message'] ?? $json[0]['error']['message'] ?? '') : '';
        return ['estado' => 'error', 'texto' => null,
                'detalle' => 'HTTP ' . $http . ($msg !== '' ? ': ' . $msg : '')];
    }

    $texto = $json['choices'][0]['message']['content'] ?? null;
    if (!is_string($texto) || trim($texto) === '') {
        return ['estado' => 'error', 'texto' => null, 'detalle' => 'respuesta vacía'];
    }
    return ['estado' => 'ok', 'texto' => $texto, 'detalle' => null];
}

/**
 * Prueba los proveedores en orden hasta que uno devuelve algo que `$validar`
 * acepta.
 *
 * @param callable $validar  recibe el texto de la respuesta; devuelve lo que
 *                           sirve, o null si no sirve (y se prueba el siguiente).
 * @return array{ok:bool, datos?:mixed, proveedor?:string, motivo?:string}
 *         motivo: 'sin_proveedores' | 'tope' | 'fallaron'
 */
function ia_cadena(PDO $pdo, array $env, int $usuario_id, string $uso,
                   array $mensajes, callable $validar): array
{
    $proveedores = ia_proveedores($env);
    if (!$proveedores) return ['ok' => false, 'motivo' => 'sin_proveedores'];

    ia_asegurar_tabla($pdo);
    if (ia_tope_alcanzado($pdo, $usuario_id, $uso)) {
        return ['ok' => false, 'motivo' => 'tope'];
    }

    $pedido = bin2hex(random_bytes(8));
    $probo  = false;
    $inicio = microtime(true);

    foreach ($proveedores as $prov) {
        if (microtime(true) - $inicio > IA_PRESUPUESTO_SEGUNDOS) break;
        if (ia_en_pausa($pdo, $prov['nombre'])) continue;
        $probo = true;

        $t0 = microtime(true);
        $r  = ia_pedir($prov, $mensajes);
        $ms = (int)round((microtime(true) - $t0) * 1000);

        if ($r['estado'] !== 'ok') {
            ia_registrar($pdo, $usuario_id, $pedido, $uso, $prov['nombre'], $r['estado'], $ms, $r['detalle']);
            continue;
        }

        $datos = $validar($r['texto']);
        if ($datos === null) {
            ia_registrar($pdo, $usuario_id, $pedido, $uso, $prov['nombre'], 'invalido', $ms,
                         mb_substr($r['texto'], 0, 200, 'UTF-8'));
            continue;
        }

        ia_registrar($pdo, $usuario_id, $pedido, $uso, $prov['nombre'], 'ok', $ms);
        return ['ok' => true, 'datos' => $datos, 'proveedor' => $prov['nombre']];
    }

    /* Si estaban todos en pausa no se probó ninguno: igual se deja un renglón,
       para que el pedido cuente en el tope y para que se vea en el registro. */
    if (!$probo) {
        ia_registrar($pdo, $usuario_id, $pedido, $uso, '-', 'pausa', 0, 'todos en pausa por límite');
    }
    return ['ok' => false, 'motivo' => 'fallaron'];
}

// ─────────────────────────────────────────────────────────────────────────────
// VOZ: Whisper en Groq
// ─────────────────────────────────────────────────────────────────────────────
//
// No va por la cadena: transcribir es otra puerta (audio/transcriptions, no
// chat/completions) y hoy sólo Groq la tiene gratis. El respaldo es el dictado
// del navegador, que es lo que había antes, y eso lo decide la pantalla.

const IA_VOZ_URL    = 'https://api.groq.com/openai/v1/audio/transcriptions';
const IA_VOZ_MODELO = 'whisper-large-v3-turbo';

function ia_voz_disponible(array $env): bool
{
    return trim($env['IA_GROQ_CLAVE'] ?? '') !== '';
}

/**
 * Lo que Whisper "escucha" en un audio callado o con puro ruido. Son dos
 * defectos conocidos:
 *
 *   · se entrenó con videos subtitulados, y ante el silencio completa con el
 *     cierre de un video ("Subtítulos por la comunidad de Amara.org");
 *   · repite la pista que se le pasó. Medido: tres segundos de silencio
 *     devolvieron "Lotes, El Ombú, Lote 12. Insumos.", sacado del vocabulario.
 *
 * La "probabilidad de que no haya voz" que devuelve Groq vino en 0 también
 * para el silencio, así que no sirve para esto. La pantalla además mide el
 * volumen mientras graba y no manda un audio callado.
 */
function ia_voz_es_alucinacion(string $texto, string $pista = ''): bool
{
    $t = ia_voz_normalizar($texto);
    if ($t === '') return true;
    foreach (['subtitulos', 'amara org', 'gracias por ver', 'suscribete', 'gracias por su atencion'] as $frase) {
        if (strpos($t, $frase) !== false) return true;
    }
    if ($pista === '') return false;

    /* Sus palabras están todas en la pista, y en el mismo orden: la repitió, no
       la escuchó. No alcanza con buscar el texto tal cual porque al repetirla
       saltea palabras ("Lotes, El Ombú" de "Lotes: La Esperanza, El Ombú").
       Una pregunta real trae palabras propias ("cuánto", "gasté"), así que no
       entra; por eso los ejemplos de la pista tienen montos que nadie dice. */
    $dichas = explode(' ', $t);
    $pistaPalabras = explode(' ', ia_voz_normalizar($pista));
    $i = 0;
    foreach ($pistaPalabras as $p) {
        if ($i < count($dichas) && $dichas[$i] === $p) $i++;
    }
    return $i === count($dichas);
}

/** Minúsculas, sin acentos ni puntuación: para comparar lo transcrito con la pista. */
function ia_voz_normalizar(string $s): string
{
    $s = mb_strtolower($s, 'UTF-8');
    $s = strtr($s, ['á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ü' => 'u', 'ñ' => 'n']);
    return trim(preg_replace('/[^a-z0-9]+/', ' ', $s));
}

/**
 * Las palabras que es probable que diga este productor: sus lotes, cultivos e
 * insumos. Whisper las toma como pista y las escribe bien ("Lote La Esperanza"
 * y no "lote la speranza"). Acotado: el modelo lee unos pocos cientos de
 * caracteres y el resto lo ignora.
 */
function ia_voz_vocabulario(PDO $pdo, int $usuario_id): string
{
    $nombres = function (string $sql) use ($pdo, $usuario_id): array {
        $st = $pdo->prepare($sql);
        $st->execute([$usuario_id]);
        return array_values(array_filter(array_map('trim', $st->fetchAll(PDO::FETCH_COLUMN))));
    };
    /* Las frases de ejemplo no son decoración: Whisper copia el ESTILO de la
       pista. Sin ellas, "quinientos dólares" salía "$500", y en Argentina el
       signo $ es pesos: el chat cargaba el gasto en la moneda equivocada. Con
       ellas escribe la moneda con letras. Los montos son raros a propósito: si
       el productor dijera una frase idéntica, se tomaría como repetición de la
       pista (ver ia_voz_es_alucinacion()). */
    $partes = ['Productor agropecuario de Argentina. Gasté 1370 dólares en fertilizante. Pagué 86400 pesos de flete. Campaña, hectáreas.'];
    try {
        $lotes    = $nombres("SELECT nombre FROM lotes WHERE usuario_id = ? ORDER BY id DESC LIMIT 25");
        $cultivos = $nombres("SELECT DISTINCT nombre FROM cultivos WHERE usuario_id = ? LIMIT 10");
        $insumos  = $nombres("SELECT nombre FROM insumos WHERE usuario_id = ? AND estado = 'activo' ORDER BY id DESC LIMIT 25");
    } catch (Throwable $e) {
        return $partes[0];   // sin vocabulario se transcribe igual
    }
    if ($lotes)    $partes[] = 'Lotes: ' . implode(', ', $lotes) . '.';
    if ($cultivos) $partes[] = 'Cultivos: ' . implode(', ', $cultivos) . '.';
    if ($insumos)  $partes[] = 'Insumos: ' . implode(', ', $insumos) . '.';
    return mb_substr(implode(' ', $partes), 0, 700, 'UTF-8');
}

/**
 * Audio a texto.
 *
 * @return array{ok:bool, texto?:string, motivo?:string}
 *         motivo: 'sin_proveedores' | 'tope' | 'fallaron' | 'vacio'
 */
function ia_transcribir(PDO $pdo, array $env, int $usuario_id, string $ruta,
                        string $nombreArchivo, string $vocabulario): array
{
    if (!ia_voz_disponible($env)) return ['ok' => false, 'motivo' => 'sin_proveedores'];

    ia_asegurar_tabla($pdo);
    if (ia_tope_alcanzado($pdo, $usuario_id, 'voz')) return ['ok' => false, 'motivo' => 'tope'];
    if (ia_en_pausa($pdo, 'groq-voz'))               return ['ok' => false, 'motivo' => 'fallaron'];

    $pedido = bin2hex(random_bytes(8));
    $t0 = microtime(true);

    $ch = curl_init(IA_VOZ_URL);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 8,
        CURLOPT_TIMEOUT        => IA_TIMEOUT_SEGUNDOS,
        CURLOPT_HTTPHEADER     => ['Authorization: Bearer ' . trim($env['IA_GROQ_CLAVE'])],
        CURLOPT_POSTFIELDS     => [
            // El nombre importa: Groq deduce el formato por la extensión.
            'file'            => new CURLFile($ruta, '', $nombreArchivo),
            'model'           => $env['IA_GROQ_MODELO_VOZ'] ?? IA_VOZ_MODELO,
            'language'        => 'es',
            'prompt'          => $vocabulario,
            'response_format' => 'json',
            'temperature'     => '0',
        ],
    ]);
    $resp = curl_exec($ch);
    $http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $nro  = curl_errno($ch);
    $ms   = (int)round((microtime(true) - $t0) * 1000);

    if ($resp === false || $http !== 200) {
        $estado = ($http === 429) ? 'limite'
                : (($http === 503 || $nro === CURLE_OPERATION_TIMEDOUT) ? 'saturado' : 'error');
        $json = is_string($resp) ? json_decode($resp, true) : null;
        $detalle = 'HTTP ' . $http . (is_array($json) && isset($json['error']['message']) ? ': ' . $json['error']['message'] : '');
        ia_registrar($pdo, $usuario_id, $pedido, 'voz', 'groq-voz', $estado, $ms, $detalle);
        return ['ok' => false, 'motivo' => 'fallaron'];
    }

    $texto = trim((string)(json_decode($resp, true)['text'] ?? ''));
    if (ia_voz_es_alucinacion($texto, $vocabulario)) {
        ia_registrar($pdo, $usuario_id, $pedido, 'voz', 'groq-voz', 'vacio', $ms, mb_substr($texto, 0, 200, 'UTF-8'));
        return ['ok' => false, 'motivo' => 'vacio'];
    }

    ia_registrar($pdo, $usuario_id, $pedido, 'voz', 'groq-voz', 'ok', $ms);
    return ['ok' => true, 'texto' => mb_substr($texto, 0, 500, 'UTF-8')];
}

/**
 * El primer objeto JSON de una respuesta.
 *
 * Aunque se les pida "sólo JSON", varios modelos lo envuelven en ```json … ```
 * o le agregan una frase antes. Se busca del primer { al último }.
 */
function ia_extraer_json(string $texto): ?array
{
    $ini = strpos($texto, '{');
    $fin = strrpos($texto, '}');
    if ($ini === false || $fin === false || $fin <= $ini) return null;
    $json = json_decode(substr($texto, $ini, $fin - $ini + 1), true);
    return is_array($json) ? $json : null;
}
