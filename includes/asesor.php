<?php
/* includes/asesor.php — Cuentas de asesor.
 *
 * Un asesor trabaja en las cuentas de sus clientes sin tener sus claves. El
 * vínculo vive en asesor_clientes; lo arma el admin desde admin.php, o el mismo
 * asesor cuando crea una cuenta desde asesor.php.
 *
 * CÓMO FUNCIONA: al "entrar" a un cliente, $_SESSION['usuario_id'] pasa a ser el
 * del cliente. Toda la app filtra por ese valor, así que el panel, los reportes,
 * el chat y las cargas muestran y guardan lo del cliente sin tocar una sola
 * consulta. Quién es el asesor de verdad queda en $_SESSION['asesor_id'].
 *
 * Se eligió así a propósito, y no sumando "o es cliente de este asesor" a cada
 * consulta: son cientos, y alcanza con olvidarse de una para que un asesor vea
 * datos que no son de sus clientes.
 *
 * Lo que sostiene la seguridad:
 *   - cambiar_cuenta.php sólo entra a un cliente si el vínculo existe en la base.
 *   - config/auth.php vuelve a mirar el vínculo en cada pedido: si el admin lo
 *     quita, el asesor vuelve a su cuenta en el clic siguiente.
 *   - Dentro de un cliente el rol es siempre 'user', aunque el asesor sea admin.
 *   - Ni las cuentas admin ni la demo pueden ser clientes de nadie.
 *
 * Tiene que andar sin la migración (migrations/asesores.sql): todo lo que lee
 * asesor_clientes o las columnas nuevas va con try/catch y devuelve vacío.
 */

const ASESOR_LOGO_MAX_BYTES = 300 * 1024;

/* Qué módulo pide cada pantalla. Al cambiar de cuenta se intenta quedarse en la
   misma pantalla; si la cuenta nueva no tiene ese módulo, se va a su inicio. */
const ASESOR_PAGINAS_MODULO = [
    'index.php'             => 'agricultura',
    'lotes.php'             => 'agricultura',
    'operaciones.php'       => 'agricultura',
    'carga_lotes.php'       => 'agricultura',
    'alquileres.php'        => 'agricultura',
    'insumos.php'           => 'agricultura',
    'produccion.php'        => 'agricultura',
    'cultivos.php'          => 'agricultura',
    'tambo.php'             => 'tambo',
    'tambo_produccion.php'  => 'tambo',
    'tambo_egresos.php'     => 'tambo',
    'tambo_comparativa.php' => 'tambo',
    'ganaderia.php'         => 'ganaderia',
    'ganaderia_feedlot.php' => 'ganaderia',
];
const ASESOR_PAGINAS_LIBRES = ['asesor.php', 'pendientes.php'];

/** Carga en la sesión la cuenta en la que se va a trabajar. La usan el login y el cambio de cuenta. */
function sesion_cargar_cuenta(array $u, bool $como_cliente = false): void
{
    $_SESSION['usuario_id'] = (int)$u['id'];
    $_SESSION['username']   = $u['username'];
    // Dentro de un cliente, nunca admin: el panel de admin muestra a todos los usuarios.
    $_SESSION['role']       = $como_cliente ? 'user' : $u['role'];
    $_SESSION['modulos']    = [
        'agricultura' => !empty($u['has_agricultura']),
        'tambo'       => !empty($u['has_tambo']),
        'ganaderia'   => !empty($u['has_ganaderia']),
    ];
    // Cuenta de demostración: lo hace cumplir config/auth.php en cada POST.
    $_SESSION['solo_lectura'] = !empty($u['solo_lectura']);
}

function inicio_segun_modulos(array $m): string
{
    if (!empty($m['agricultura'])) return 'index.php';
    if (!empty($m['tambo']))       return 'tambo.php';
    if (!empty($m['ganaderia']))   return 'ganaderia.php';
    return 'pendientes.php';
}

/** A qué pantalla ir después de cambiar de cuenta. Nunca a una dirección que no sea de la lista. */
function asesor_destino(string $pagina, array $modulos): string
{
    $pagina = basename($pagina);
    if (isset(ASESOR_PAGINAS_MODULO[$pagina]) && !empty($modulos[ASESOR_PAGINAS_MODULO[$pagina]])) {
        return $pagina;
    }
    if (in_array($pagina, ASESOR_PAGINAS_LIBRES, true)) return $pagina;
    return inicio_segun_modulos($modulos);
}

/** La sesión es de un asesor (esté en su cuenta o en la de un cliente). */
function asesor_activo(): bool
{
    return !empty($_SESSION['asesor_id']);
}

/** El asesor está parado en la cuenta de un cliente. */
function asesor_en_cliente(): bool
{
    return asesor_activo() && (int)$_SESSION['usuario_id'] !== (int)$_SESSION['asesor_id'];
}

/** Si una cuenta puede quedar vinculada como cliente de un asesor. */
function asesor_puede_ser_cliente(array $u, int $asesor_id): bool
{
    return (int)$u['id'] !== $asesor_id
        && ($u['role'] ?? 'user') !== 'admin'
        && empty($u['solo_lectura']);
}

/** Si una cuenta puede ser asesor: cualquiera menos la demo. */
function puede_ser_asesor(array $u): bool
{
    return empty($u['solo_lectura']);
}

/** Las cuentas vinculadas a un asesor, por nombre. */
function asesor_clientes(PDO $pdo, int $asesor_id): array
{
    try {
        $st = $pdo->prepare(
            "SELECT u.id, u.username, u.email, u.status,
                    u.has_agricultura, u.has_tambo, u.has_ganaderia,
                    ac.origen, ac.creado_en
               FROM asesor_clientes ac
               JOIN users u ON u.id = ac.cliente_id
              WHERE ac.asesor_id = ?
              ORDER BY u.username");
        $st->execute([$asesor_id]);
        return $st->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        error_log('[asesor] no pude leer los clientes: ' . $e->getMessage());
        return [];
    }
}

function asesor_tiene_cliente(PDO $pdo, int $asesor_id, int $cliente_id): bool
{
    try {
        $st = $pdo->prepare("SELECT 1 FROM asesor_clientes WHERE asesor_id = ? AND cliente_id = ?");
        $st->execute([$asesor_id, $cliente_id]);
        return (bool)$st->fetchColumn();
    } catch (Throwable $e) {
        error_log('[asesor] no pude verificar el vínculo: ' . $e->getMessage());
        return false;
    }
}

/** Los asesores que tienen acceso a una cuenta, para avisarle al cliente. */
function asesores_de(PDO $pdo, int $cliente_id): array
{
    try {
        $st = $pdo->prepare(
            "SELECT u.username, u.firma_nombre
               FROM asesor_clientes ac
               JOIN users u ON u.id = ac.asesor_id
              WHERE ac.cliente_id = ? AND u.es_asesor = 1
              ORDER BY u.username");
        $st->execute([$cliente_id]);
        $nombres = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $a) {
            $nombres[] = trim((string)$a['firma_nombre']) !== '' ? trim($a['firma_nombre']) : $a['username'];
        }
        return $nombres;
    } catch (Throwable $e) {
        return [];
    }
}

/**
 * Vuelve a controlar, en cada pedido, que el asesor siga pudiendo estar donde
 * está. Lo llama config/auth.php sólo si la sesión es de un asesor.
 */
function asesor_verificar_sesion(PDO $pdo, string $base): void
{
    $asesor_id = (int)$_SESSION['asesor_id'];
    $actual    = (int)$_SESSION['usuario_id'];

    try {
        $st = $pdo->prepare("SELECT * FROM users WHERE id = ?");
        $st->execute([$asesor_id]);
        $asesor = $st->fetch(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        error_log('[asesor] no pude leer al asesor: ' . $e->getMessage());
        $asesor = false;
    }

    // La cuenta del asesor ya no existe: no hay a dónde volver.
    if (!$asesor) {
        session_unset();
        session_destroy();
        header("Location: $base/login.php");
        exit;
    }

    $sigue_asesor = !empty($asesor['es_asesor']) && puede_ser_asesor($asesor);
    $puede_estar  = $actual === $asesor_id
                 || ($sigue_asesor && asesor_tiene_cliente($pdo, $asesor_id, $actual));

    if ($puede_estar) {
        if (!$sigue_asesor) unset($_SESSION['asesor_id'], $_SESSION['asesor_username']);
        return;
    }

    // Le quitaron este cliente, o dejó de ser asesor: vuelve a su cuenta.
    session_regenerate_id(true);
    sesion_cargar_cuenta($asesor);
    unset($_SESSION['csrf_token']);
    if (!$sigue_asesor) unset($_SESSION['asesor_id'], $_SESSION['asesor_username']);
    set_flash('error', 'Ya no tenés acceso a esa cuenta. Volviste a la tuya.');

    header("Location: $base/" . inicio_segun_modulos($_SESSION['modulos']));
    exit;
}

/* ─── Firma de los informes ──────────────────────────────────────────────── */

/** Convierte un archivo subido en data URI, o null si no es una imagen aceptada. */
function logo_a_data_uri(string $bytes): ?string
{
    if ($bytes === '' || strlen($bytes) > ASESOR_LOGO_MAX_BYTES) return null;
    // getimagesizefromstring es del núcleo de PHP: no depende de fileinfo ni de GD.
    // SVG queda afuera a propósito: puede llevar scripts adentro.
    $info = @getimagesizefromstring($bytes);
    if (!$info) return null;
    $tipos = [IMAGETYPE_PNG => 'image/png', IMAGETYPE_JPEG => 'image/jpeg', IMAGETYPE_WEBP => 'image/webp'];
    if (!isset($tipos[$info[2]])) return null;
    return 'data:' . $tipos[$info[2]] . ';base64,' . base64_encode($bytes);
}

/** Un logo guardado se usa sólo si tiene exactamente la forma que arma logo_a_data_uri(). */
function logo_es_valido(?string $uri): bool
{
    return $uri !== null
        && preg_match('#^data:image/(png|jpeg|webp);base64,[A-Za-z0-9+/]+=*$#', $uri) === 1;
}

/** Las líneas de la firma de un asesor. Si no cargó nombre, firma con su usuario. */
function firma_lineas(array $asesor): array
{
    $nombre = trim((string)($asesor['firma_nombre'] ?? ''));
    $logo   = $asesor['firma_logo'] ?? null;
    return [
        'nombre'  => $nombre !== '' ? $nombre : (string)$asesor['username'],
        'detalle' => trim((string)($asesor['firma_detalle'] ?? '')),
        'logo'    => logo_es_valido($logo) ? $logo : null,
    ];
}

/** La firma que va en los informes, o null si quien los saca no es asesor. */
function firma_informe(PDO $pdo): ?array
{
    if (!asesor_activo()) return null;
    try {
        $st = $pdo->prepare("SELECT * FROM users WHERE id = ? AND es_asesor = 1");
        $st->execute([(int)$_SESSION['asesor_id']]);
        $a = $st->fetch(PDO::FETCH_ASSOC);
        return $a ? firma_lineas($a) : null;
    } catch (Throwable $e) {
        return null;
    }
}

/** El bloque de firma para los informes que se imprimen (HTML con estilos propios). */
function firma_html(array $f, string $margen = '28px 36px 0'): string
{
    $h = fn($s) => htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
    $out = '<div class="firma-asesor" style="display:flex;align-items:center;gap:16px;'
         . 'margin:' . $h($margen) . ';padding:14px 0 0;border-top:1px solid #cbd5e1;'
         . 'break-inside:avoid;page-break-inside:avoid;">';
    if ($f['logo']) {
        $out .= '<img src="' . $h($f['logo']) . '" alt="" style="max-height:56px;max-width:160px;object-fit:contain;">';
    }
    $out .= '<div style="line-height:1.4;">'
          . '<div style="font-size:10px;color:#64748b;text-transform:uppercase;letter-spacing:.5px;">Elaborado por</div>'
          . '<div style="font-size:14px;font-weight:700;color:#0f172a;">' . $h($f['nombre']) . '</div>';
    if ($f['detalle'] !== '') {
        $out .= '<div style="font-size:11px;color:#475569;">' . $h($f['detalle']) . '</div>';
    }
    return $out . '</div></div>';
}

/** La firma en texto, para los Excel (no muestran imágenes incrustadas). */
function firma_texto(array $f): string
{
    return 'Elaborado por: ' . $f['nombre'] . ($f['detalle'] !== '' ? ' · ' . $f['detalle'] : '');
}
