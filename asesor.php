<?php
/* asesor.php — El panel del asesor.
 *
 * Ve SÓLO las cuentas vinculadas a la suya: todo sale de asesor_clientes con su
 * asesor_id. Puede entrar a cada una, crear cuentas nuevas (quedan ligadas a él)
 * y cargar la firma de sus informes. Vincular o desvincular cuentas que ya
 * existían lo hace el admin desde admin.php.
 *
 * Trabaja siempre con $_SESSION['asesor_id'] y nunca con usuario_id: el asesor
 * puede abrir este panel parado en la cuenta de un cliente.
 */
require_once 'config/auth.php';
require_once 'config/database.php';
require_once 'includes/asesor.php';

if (!asesor_activo()) {
    echo "<script>alert('Esta sección es para cuentas de asesor.'); window.location.href='index.php';</script>";
    exit;
}

validate_csrf();

$asesor_id = (int)$_SESSION['asesor_id'];
$st = $pdo->prepare("SELECT * FROM users WHERE id = ?");
$st->execute([$asesor_id]);
$asesor = $st->fetch(PDO::FETCH_ASSOC);

$errores = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $accion = $_POST['action'] ?? '';

    // ─── Crear una cuenta de cliente ligada a este asesor ───────────────────
    if ($accion === 'crear_cliente') {
        $username = trim($_POST['username'] ?? '');
        $email    = trim($_POST['email'] ?? '');
        $password = $_POST['password'] ?? '';

        if ($username === '' || $email === '' || $password === '') {
            $errores[] = 'Completá nombre, correo y contraseña.';
        } elseif (mb_strlen($username) > 50) {
            $errores[] = 'El nombre de usuario puede tener hasta 50 caracteres.';
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 100) {
            $errores[] = 'El correo no es válido.';
        } elseif (strlen($password) < 8) {
            $errores[] = 'La contraseña tiene que tener al menos 8 caracteres.';
        }

        // Sólo los módulos que tiene el asesor: no puede habilitar lo que él no tiene.
        $mod = [];
        foreach (['agricultura', 'tambo', 'ganaderia'] as $m) {
            $mod[$m] = (!empty($_POST["has_$m"]) && !empty($asesor["has_$m"])) ? 1 : 0;
        }
        if (!$errores && !array_sum($mod)) {
            $errores[] = 'Elegí al menos un módulo para la cuenta.';
        }

        if (!$errores) {
            $st = $pdo->prepare("SELECT id FROM users WHERE email = ? OR username = ?");
            $st->execute([$email, $username]);
            if ($st->fetch()) $errores[] = 'Ese correo o ese nombre de usuario ya están en uso.';
        }

        if (!$errores) {
            $pdo->beginTransaction();
            try {
                // Activa de entrada: la crea un asesor que el admin ya habilitó.
                $pdo->prepare("INSERT INTO users (username, email, password_hash, role, status,
                                                  has_agricultura, has_tambo, has_ganaderia)
                               VALUES (?, ?, ?, 'user', 'active', ?, ?, ?)")
                    ->execute([$username, $email, password_hash($password, PASSWORD_DEFAULT),
                               $mod['agricultura'], $mod['tambo'], $mod['ganaderia']]);
                $nuevo = (int)$pdo->lastInsertId();
                $pdo->prepare("INSERT INTO asesor_clientes (asesor_id, cliente_id, origen) VALUES (?, ?, 'asesor')")
                    ->execute([$asesor_id, $nuevo]);
                $pdo->commit();
                set_flash('success', "Cuenta de $username creada y ligada a la tuya.");
                header('Location: asesor.php');
                exit;
            } catch (Throwable $e) {
                $pdo->rollBack();
                error_log('[asesor] no pude crear la cuenta: ' . $e->getMessage());
                $errores[] = 'No se pudo crear la cuenta. Probá de nuevo.';
            }
        }
    }

    // ─── Firma de los informes ──────────────────────────────────────────────
    if ($accion === 'firma') {
        $nombre  = mb_substr(trim($_POST['firma_nombre'] ?? ''), 0, 150);
        $detalle = mb_substr(trim($_POST['firma_detalle'] ?? ''), 0, 150);
        $logo    = $asesor['firma_logo'] ?? null;

        if (!empty($_POST['quitar_logo'])) $logo = null;

        $archivo = $_FILES['firma_logo'] ?? null;
        if ($archivo && $archivo['error'] !== UPLOAD_ERR_NO_FILE) {
            $bytes = ($archivo['error'] === UPLOAD_ERR_OK && is_uploaded_file($archivo['tmp_name']))
                   ? (string)file_get_contents($archivo['tmp_name']) : '';
            $uri = logo_a_data_uri($bytes);
            if ($uri === null) {
                $errores[] = 'El logo tiene que ser PNG, JPG o WEBP de hasta '
                           . (ASESOR_LOGO_MAX_BYTES / 1024) . ' KB.';
            } else {
                $logo = $uri;
            }
        }

        if (!$errores) {
            $pdo->prepare("UPDATE users SET firma_nombre = ?, firma_detalle = ?, firma_logo = ? WHERE id = ?")
                ->execute([$nombre !== '' ? $nombre : null, $detalle !== '' ? $detalle : null, $logo, $asesor_id]);
            set_flash('success', 'Firma guardada. Va a salir en los informes que saques.');
            header('Location: asesor.php');
            exit;
        }
    }
}

$clientes = asesor_clientes($pdo, $asesor_id);
$firma    = firma_lineas($asesor);
$en_cuenta = (int)$_SESSION['usuario_id'];

$page_title = 'Mis clientes';
require_once 'includes/header.php';
?>

<?php foreach ($errores as $e): ?>
<div style="background:rgba(239,68,68,0.1); color:#ef4444; padding:12px 20px; border-radius:10px; border:1px solid rgba(239,68,68,0.3); margin-bottom:20px; font-size:.95rem;">
    <i class="fas fa-exclamation-circle"></i> <?= htmlspecialchars($e) ?>
</div>
<?php endforeach; ?>

<div class="glass-panel">
    <div class="panel-header" style="margin-bottom: 20px; display:flex; flex-wrap:wrap; gap:12px; align-items:center; justify-content:space-between;">
        <h3 style="font-size: 1.3rem; font-weight: 600;">Cuentas de mis clientes</h3>
        <?php if (count($clientes) > 5): ?>
        <input type="search" id="buscarCliente" placeholder="Buscar cliente…" aria-label="Buscar cliente" style="max-width: 260px;">
        <?php endif; ?>
    </div>

    <?php if (!$clientes): ?>
        <p style="color: var(--text-muted);">Todavía no tenés cuentas vinculadas. Creá una abajo, o pedile al administrador que te vincule las que ya existen.</p>
    <?php else: ?>
    <div class="table-container">
        <table id="tablaClientes">
            <thead>
                <tr>
                    <th>Cliente</th>
                    <th>Módulos</th>
                    <th>Desde</th>
                    <th style="text-align: right;"></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($clientes as $c): ?>
                <tr data-buscar="<?= htmlspecialchars(mb_strtolower($c['username'] . ' ' . $c['email'])) ?>">
                    <td data-label="Cliente">
                        <div style="display:flex; flex-direction:column;">
                            <strong><?= htmlspecialchars($c['username']) ?></strong>
                            <small style="color: var(--text-muted);"><?= htmlspecialchars($c['email']) ?></small>
                        </div>
                    </td>
                    <td data-label="Módulos">
                        <div style="display:flex; gap:4px; flex-wrap:wrap;">
                            <?php if ($c['has_agricultura']): ?><span class="badge" style="background:rgba(16,185,129,0.1);color:var(--accent);font-size:0.65rem;">AGRI</span><?php endif; ?>
                            <?php if ($c['has_tambo']): ?><span class="badge" style="background:rgba(16,185,129,0.1);color:var(--accent);font-size:0.65rem;">TAMB</span><?php endif; ?>
                            <?php if ($c['has_ganaderia']): ?><span class="badge" style="background:rgba(16,185,129,0.1);color:var(--accent);font-size:0.65rem;">GANA</span><?php endif; ?>
                        </div>
                    </td>
                    <td data-label="Desde"><?= date('d/m/Y', strtotime($c['creado_en'])) ?></td>
                    <td data-label="" style="text-align: right;">
                        <?php if ((int)$c['id'] === $en_cuenta): ?>
                            <span class="badge" style="background:oklch(0.480 0.110 250 / .10); color:oklch(0.380 0.110 250);">Estás acá</span>
                        <?php else: ?>
                        <form method="POST" action="cambiar_cuenta.php" style="margin:0;">
                            <?php csrf_field(); ?>
                            <input type="hidden" name="cuenta_id" value="<?= (int)$c['id'] ?>">
                            <input type="hidden" name="volver" value="index.php">
                            <button type="submit" class="btn btn-primary btn-sm" style="padding: 6px 14px; font-size: .85rem;">
                                <i class="fas fa-arrow-right-to-bracket"></i> Entrar
                            </button>
                        </form>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>
</div>

<div class="asesor-grid">
    <div class="glass-panel">
        <h3 style="font-size: 1.1rem; font-weight: 600; margin-bottom: 6px;">Crear cuenta de cliente</h3>
        <p style="color: var(--text-muted); font-size: .9rem; margin-bottom: 18px;">Queda activa y ligada a tu cuenta. Pasale el correo y la contraseña al productor para que entre por su lado.</p>
        <form method="POST" autocomplete="off">
            <?php csrf_field(); ?>
            <input type="hidden" name="action" value="crear_cliente">
            <div class="form-group">
                <label for="nc_username">Nombre de usuario</label>
                <input type="text" id="nc_username" name="username" maxlength="50" required value="<?= htmlspecialchars($_POST['username'] ?? '') ?>">
            </div>
            <div class="form-group">
                <label for="nc_email">Correo</label>
                <input type="email" id="nc_email" name="email" maxlength="100" required value="<?= htmlspecialchars($_POST['email'] ?? '') ?>">
            </div>
            <div class="form-group">
                <label for="nc_password">Contraseña</label>
                <input type="password" id="nc_password" name="password" minlength="8" required autocomplete="new-password">
            </div>
            <fieldset class="form-group" style="border:none; padding:0;">
                <legend style="font-weight:500; margin-bottom:8px;">Módulos</legend>
                <?php
                $nombres = ['agricultura' => 'Agricultura', 'tambo' => 'Tambo', 'ganaderia' => 'Ganadería'];
                $hay_modulo = false;
                foreach ($nombres as $m => $txt):
                    if (empty($asesor["has_$m"])) continue;
                    $hay_modulo = true;
                ?>
                <label style="display:inline-flex; align-items:center; gap:8px; margin-right:18px; cursor:pointer;">
                    <input type="checkbox" name="has_<?= $m ?>" value="1" checked style="width:18px; height:18px; accent-color: var(--accent);">
                    <?= $txt ?>
                </label>
                <?php endforeach; ?>
                <?php if (!$hay_modulo): ?>
                <small style="color: var(--text-muted);">Tu cuenta no tiene módulos habilitados: pedíselos al administrador.</small>
                <?php endif; ?>
            </fieldset>
            <button type="submit" class="btn btn-primary"><i class="fas fa-user-plus"></i> Crear cuenta</button>
        </form>
    </div>

    <div class="glass-panel">
        <h3 style="font-size: 1.1rem; font-weight: 600; margin-bottom: 6px;">Mi firma en los informes</h3>
        <p style="color: var(--text-muted); font-size: .9rem; margin-bottom: 18px;">Sale al pie de los PDF y las recetas que saques, en tu cuenta o en la de un cliente. En los Excel va el nombre, sin el logo.</p>
        <form method="POST" enctype="multipart/form-data">
            <?php csrf_field(); ?>
            <input type="hidden" name="action" value="firma">
            <div class="form-group">
                <label for="f_nombre">Nombre</label>
                <input type="text" id="f_nombre" name="firma_nombre" maxlength="150" placeholder="<?= htmlspecialchars($asesor['username']) ?>" value="<?= htmlspecialchars($asesor['firma_nombre'] ?? '') ?>">
            </div>
            <div class="form-group">
                <label for="f_detalle">Segunda línea <small style="color: var(--text-muted);">(opcional)</small></label>
                <input type="text" id="f_detalle" name="firma_detalle" maxlength="150" placeholder="Ing. Agr. · Mat. 1234 · 11 5555-5555" value="<?= htmlspecialchars($asesor['firma_detalle'] ?? '') ?>">
            </div>
            <div class="form-group">
                <label for="f_logo">Logo <small style="color: var(--text-muted);">(PNG, JPG o WEBP, hasta <?= ASESOR_LOGO_MAX_BYTES / 1024 ?> KB)</small></label>
                <input type="file" id="f_logo" name="firma_logo" accept="image/png,image/jpeg,image/webp">
                <?php if ($firma['logo']): ?>
                <label style="display:inline-flex; align-items:center; gap:8px; margin-top:8px; cursor:pointer;">
                    <input type="checkbox" name="quitar_logo" value="1" style="width:18px; height:18px;"> Quitar el logo actual
                </label>
                <?php endif; ?>
            </div>

            <div style="margin: 6px 0 18px;">
                <small style="color: var(--text-muted); display:block; margin-bottom:6px;">Así se ve hoy:</small>
                <div class="firma-muestra">
                    <?php if ($firma['logo']): ?><img src="<?= htmlspecialchars($firma['logo']) ?>" alt="Tu logo"><?php endif; ?>
                    <div>
                        <div class="firma-muestra-rotulo">Elaborado por</div>
                        <div class="firma-muestra-nombre"><?= htmlspecialchars($firma['nombre']) ?></div>
                        <?php if ($firma['detalle'] !== ''): ?><div class="firma-muestra-detalle"><?= htmlspecialchars($firma['detalle']) ?></div><?php endif; ?>
                    </div>
                </div>
            </div>
            <button type="submit" class="btn btn-primary"><i class="fas fa-signature"></i> Guardar firma</button>
        </form>
    </div>
</div>

<style>
    .asesor-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(320px, 1fr)); gap: 24px; }
    .asesor-grid .form-group { margin-bottom: 16px; }
    .firma-muestra { display: flex; align-items: center; gap: 14px; padding: 14px; background: #fff;
                     border: 1px dashed var(--border); border-radius: 10px; }
    .firma-muestra img { max-height: 48px; max-width: 140px; object-fit: contain; }
    .firma-muestra-rotulo { font-size: .7rem; color: #64748b; text-transform: uppercase; letter-spacing: .5px; }
    .firma-muestra-nombre { font-weight: 700; color: #0f172a; }
    .firma-muestra-detalle { font-size: .82rem; color: #475569; }
</style>

<script>
(function () {
    var buscar = document.getElementById('buscarCliente');
    if (!buscar) return;
    buscar.addEventListener('input', function () {
        var q = buscar.value.trim().toLowerCase();
        document.querySelectorAll('#tablaClientes tbody tr').forEach(function (tr) {
            tr.style.display = !q || tr.dataset.buscar.indexOf(q) !== -1 ? '' : 'none';
        });
    });
})();
</script>

<?php require_once 'includes/footer.php'; ?>
