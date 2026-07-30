<?php
require_once 'config/auth.php';
require_once 'config/database.php';
require_ganaderia();
$uid = $_SESSION['usuario_id'];
$page_title = 'Simulador';
validate_csrf();

// ─── Tablas ────────────────────────────────────────────────────────────────
$pdo->exec("CREATE TABLE IF NOT EXISTS feedlot_lotes (
  id INT AUTO_INCREMENT PRIMARY KEY, usuario_id INT NOT NULL,
  nombre VARCHAR(100) NOT NULL, fecha_inicio DATE,
  cant_animales INT DEFAULT 1000,
  pct_invernada DECIMAL(5,2) DEFAULT 70, pct_engorde DECIMAL(5,2) DEFAULT 30,
  kg_entrada_inv DECIMAL(8,2) DEFAULT 130, kg_salida_inv DECIMAL(8,2) DEFAULT 240, dias_invernada INT DEFAULT 110,
  kg_entrada_eng DECIMAL(8,2) DEFAULT 240, kg_salida_eng DECIMAL(8,2) DEFAULT 360, dias_engorde INT DEFAULT 92,
  conv_inv DECIMAL(5,2) DEFAULT 1.0, conv_eng DECIMAL(5,2) DEFAULT 1.3, desperdicio_pct DECIMAL(5,2) DEFAULT 2,
  precio_compra DECIMAL(14,2) DEFAULT 0,
  precio_venta_inv DECIMAL(14,2) DEFAULT 0, precio_venta_eng DECIMAL(14,2) DEFAULT 0,
  flete_compra_pct DECIMAL(5,2) DEFAULT 10, flete_venta_pct DECIMAL(5,2) DEFAULT 5,
  usd_referencia DECIMAL(10,2) DEFAULT 1185, notas TEXT,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_u (usuario_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

$pdo->exec("CREATE TABLE IF NOT EXISTS feedlot_costos_fijos (
  id INT AUTO_INCREMENT PRIMARY KEY, lote_id INT NOT NULL, usuario_id INT NOT NULL,
  concepto VARCHAR(100) NOT NULL, categoria VARCHAR(30) DEFAULT 'otro',
  cantidad DECIMAL(10,2) DEFAULT 1, precio_unitario DECIMAL(14,2) DEFAULT 0,
  monto_mensual DECIMAL(14,2) DEFAULT 0,
  INDEX idx_l (lote_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

try {
    $pdo->exec("ALTER TABLE feedlot_costos_fijos ADD COLUMN cantidad DECIMAL(10,2) DEFAULT 1 AFTER categoria");
    $pdo->exec("ALTER TABLE feedlot_costos_fijos ADD COLUMN precio_unitario DECIMAL(14,2) DEFAULT 0 AFTER cantidad");
} catch(Exception $e) {}


$pdo->exec("CREATE TABLE IF NOT EXISTS feedlot_alimentos (
  id INT AUTO_INCREMENT PRIMARY KEY, lote_id INT NOT NULL, usuario_id INT NOT NULL,
  fase ENUM('invernada','engorde') NOT NULL,
  nombre VARCHAR(100) NOT NULL, kg_x_dia DECIMAL(8,3) DEFAULT 0, precio_kg DECIMAL(10,2) DEFAULT 0,
  INDEX idx_lf (lote_id, fase)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

// ─── AJAX ──────────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD']==='POST' && ($_POST['ajax']??'')==='1') {
    header('Content-Type: application/json');
    $a = $_POST['action'] ?? '';

    if ($a === 'save_lote') {
        $id = !empty($_POST['id']) ? (int)$_POST['id'] : null;
        $cols = ['nombre','fecha_inicio','cant_animales','pct_invernada','pct_engorde',
                 'kg_entrada_inv','kg_salida_inv','dias_invernada',
                 'kg_entrada_eng','kg_salida_eng','dias_engorde',
                 'conv_inv','conv_eng','desperdicio_pct',
                 'precio_compra','precio_venta_inv','precio_venta_eng',
                 'flete_compra_pct','flete_venta_pct','usd_referencia','notas'];
        $vals = array_map(fn($c) => ($_POST[$c]??'')!=='' ? $_POST[$c] : null, $cols);
        if ($id) {
            $set = implode(',', array_map(fn($c) => "`$c`=?", $cols));
            $pdo->prepare("UPDATE feedlot_lotes SET $set WHERE id=? AND usuario_id=?")->execute([...$vals,$id,$uid]);
            echo json_encode(['ok'=>true,'id'=>$id]);
        } else {
            $cs = implode(',', array_map(fn($c) => "`$c`", $cols));
            $ph = implode(',', array_fill(0, count($cols), '?'));
            $pdo->prepare("INSERT INTO feedlot_lotes (usuario_id,$cs) VALUES (?,$ph)")->execute([$uid,...$vals]);
            echo json_encode(['ok'=>true,'id'=>$pdo->lastInsertId()]);
        }
        exit;
    }
    if ($a === 'delete_lote') {
        $id=(int)$_POST['id'];
        foreach(['feedlot_lotes','feedlot_costos_fijos','feedlot_alimentos'] as $t)
            $pdo->prepare("DELETE FROM $t WHERE ".($t==='feedlot_lotes'?'id':'lote_id')."=? AND usuario_id=?")->execute([$id,$uid]);
        echo json_encode(['ok'=>true]); exit;
    }
    if ($a === 'add_costo') {
        $stmt=$pdo->prepare("INSERT INTO feedlot_costos_fijos (lote_id,usuario_id,concepto,categoria,cantidad,precio_unitario,monto_mensual) VALUES (?,?,?,?,?,?,?)");
        $stmt->execute([(int)$_POST['lote_id'],$uid,$_POST['concepto'],$_POST['categoria'],(float)$_POST['cantidad'],(float)$_POST['precio_unitario'],(float)$_POST['monto']]);
        echo json_encode(['ok'=>true,'id'=>$pdo->lastInsertId()]); exit;
    }
    if ($a === 'del_costo') {
        $pdo->prepare("DELETE FROM feedlot_costos_fijos WHERE id=? AND usuario_id=?")->execute([(int)$_POST['id'],$uid]);
        echo json_encode(['ok'=>true]); exit;
    }
    if ($a === 'add_alim') {
        $stmt=$pdo->prepare("INSERT INTO feedlot_alimentos (lote_id,usuario_id,fase,nombre,kg_x_dia,precio_kg) VALUES (?,?,?,?,?,?)");
        $stmt->execute([(int)$_POST['lote_id'],$uid,$_POST['fase'],$_POST['nombre'],(float)$_POST['kg_x_dia'],(float)str_replace(',','',$_POST['precio_kg'])]);
        echo json_encode(['ok'=>true,'id'=>$pdo->lastInsertId()]); exit;
    }
    if ($a === 'del_alim') {
        $pdo->prepare("DELETE FROM feedlot_alimentos WHERE id=? AND usuario_id=?")->execute([(int)$_POST['id'],$uid]);
        echo json_encode(['ok'=>true]); exit;
    }
    echo json_encode(['ok'=>false]); exit;
}

// ─── Cargar lotes ──────────────────────────────────────────────────────────
$lotes = $pdo->prepare("SELECT * FROM feedlot_lotes WHERE usuario_id=? ORDER BY created_at DESC");
$lotes->execute([$uid]);
$lotes = $lotes->fetchAll();

$lid = isset($_GET['lote']) ? (int)$_GET['lote'] : ($lotes[0]['id'] ?? 0);
$lote = null; $costos = []; $alim_inv = []; $alim_eng = [];

if ($lid) {
    $s=$pdo->prepare("SELECT * FROM feedlot_lotes WHERE id=? AND usuario_id=?");
    $s->execute([$lid,$uid]); $lote=$s->fetch();
    if ($lote) {
        $s=$pdo->prepare("SELECT * FROM feedlot_costos_fijos WHERE lote_id=? ORDER BY id"); $s->execute([$lid]); $costos=$s->fetchAll();
        $s=$pdo->prepare("SELECT * FROM feedlot_alimentos WHERE lote_id=? AND fase='invernada' ORDER BY id"); $s->execute([$lid]); $alim_inv=$s->fetchAll();
        $s=$pdo->prepare("SELECT * FROM feedlot_alimentos WHERE lote_id=? AND fase='engorde' ORDER BY id"); $s->execute([$lid]); $alim_eng=$s->fetchAll();
    }
}
require_once 'includes/header.php';
?>

<style>
/* CSS para Feed Lot */
.fl-header { display: flex; flex-wrap: wrap; gap: 15px; justify-content: space-between; align-items: center; margin-bottom: 20px; }
.fl-header h2 { margin: 0; font-size: 1.5rem; color: var(--text-primary); }
.fl-select-lote { padding: 8px 12px; border-radius: 8px; border: 1px solid rgba(255,255,255,0.1); background: rgba(0,0,0,0.2); color: white; }

.fl-tabs { display: flex; gap: 10px; margin-bottom: 20px; border-bottom: 1px solid rgba(255,255,255,0.1); padding-bottom: 10px; }
.fl-tab { padding: 10px 20px; border-radius: 8px; background: rgba(255,255,255,0.05); color: var(--text-muted); cursor: pointer; border: 1px solid transparent; }
.fl-tab.active { background: rgba(239,68,68,0.15); color: #f87171; border-color: rgba(239,68,68,0.3); font-weight: bold; }

.fl-tab-content { display: none; }
.fl-tab-content.active { display: block; }

.fl-card { background: rgba(255,255,255,0.03); border: 1px solid rgba(255,255,255,0.08); border-radius: 12px; padding: 20px; margin-bottom: 20px; }
.fl-card-title { font-size: 1.1rem; font-weight: 600; color: var(--text-primary); margin-bottom: 15px; display: flex; justify-content: space-between; align-items: center; }

.fl-table { width: 100%; border-collapse: collapse; font-size: 0.9rem; }
.fl-table th { text-align: left; padding: 12px 15px; color: var(--text-muted); border-bottom: 1px solid rgba(255,255,255,0.1); }
.fl-table td { padding: 12px 15px; border-bottom: 1px solid rgba(255,255,255,0.05); }
.fl-table input { width: 100%; padding: 6px; border-radius: 6px; border: 1px solid rgba(255,255,255,0.2); background: rgba(0,0,0,0.3); color: white; text-align: right; }
.fl-table-responsive { overflow-x: auto; -webkit-overflow-scrolling: touch; width: 100%; }

@media (max-width: 600px) {
    .fl-table th, .fl-table td { padding: 8px 5px; font-size: 0.8rem; }
    .fl-card { padding: 15px; }
}

.fl-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 15px; }
.fl-group label { display: block; font-size: 0.85rem; color: var(--text-muted); margin-bottom: 5px; }
.fl-group input, .fl-group select { width: 100%; padding: 8px 10px; border-radius: 6px; border: 1px solid rgba(255,255,255,0.2); background: rgba(0,0,0,0.3); color: white; }
.num-hint { display: block; font-size: 0.75rem; color: #f87171; margin-top: 3px; font-weight: 600; text-align: right; min-height: 14px; letter-spacing: 0.5px; }

.fl-btn { padding: 8px 15px; border-radius: 6px; cursor: pointer; border: none; font-weight: bold; display: inline-flex; align-items: center; gap: 5px; }
.fl-btn-primary { background: #ef4444; color: white; }
.fl-btn-danger { background: #f87171; color: white; padding: 4px 8px; font-size: 0.8rem; }
.fl-btn-sm { padding: 4px 10px; font-size: 0.85rem; }

.fl-summary-box { background: rgba(16, 185, 129, 0.1); border: 1px solid rgba(16, 185, 129, 0.3); border-radius: 12px; padding: 20px; text-align: center; }
.fl-summary-val { font-size: 2rem; font-weight: bold; color: #10b981; }

.fl-modal { display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.8); z-index: 9999; overflow-y: auto; padding: 40px 15px; }
.fl-modal-content { background: #1e1e2d; padding: 30px; border-radius: 12px; width: 100%; max-width: 850px; margin: 0 auto; min-height: fit-content; box-shadow: 0 10px 25px rgba(0,0,0,0.5); }
</style>

<div class="fl-header">
    <h2><i class="fas fa-calculator"></i> Módulo Simulador Ganadero</h2>
    <div style="display: flex; flex-wrap: wrap; gap: 10px; align-items: center;">
        <select class="fl-select-lote" onchange="window.location.href='ganaderia_feedlot.php?lote='+this.value">
            <?php if(empty($lotes)): ?><option value="">Sin lotes creados</option><?php endif; ?>
            <?php foreach($lotes as $l): ?>
                <option value="<?= $l['id'] ?>" <?= $lid==$l['id']?'selected':'' ?>><?= htmlspecialchars($l['nombre']) ?></option>
            <?php endforeach; ?>
        </select>
        <button class="fl-btn fl-btn-primary" onclick="openLoteModal()"><i class="fas fa-plus"></i> Nuevo Lote</button>
        <?php if($lote): ?>
            <button class="fl-btn" style="background:rgba(255,255,255,0.1); color:white;" onclick="openLoteModal(true)"><i class="fas fa-cog"></i> Configurar</button>
        <?php endif; ?>
    </div>
</div>

<?php if(!$lote): ?>
    <div class="fl-card" style="text-align: center; padding: 50px;">
        <i class="fas fa-info-circle" style="font-size: 3rem; color: var(--text-muted); margin-bottom: 20px;"></i>
        <h3>No hay ningún lote seleccionado</h3>
        <p style="color: var(--text-muted);">Crea un nuevo lote para empezar a utilizar el Simulador Ganadero.</p>
        <button class="fl-btn fl-btn-primary" onclick="openLoteModal()" style="margin-top: 20px;"><i class="fas fa-plus"></i> Crear Primer Lote</button>
    </div>
<?php else: ?>

    <?php $active_tab = $_GET['tab'] ?? 'costos'; ?>
    <div class="fl-tabs">
        <div id="tab-btn-costos" class="fl-tab <?= $active_tab=='costos'?'active':'' ?>" onclick="switchTab('costos')"><i class="fas fa-money-bill"></i> Costos Fijos</div>
        <div id="tab-btn-consumo" class="fl-tab <?= $active_tab=='consumo'?'active':'' ?>" onclick="switchTab('consumo')"><i class="fas fa-wheat-awn"></i> Consumo Alimentario</div>
        <div id="tab-btn-margen" class="fl-tab <?= $active_tab=='margen'?'active':'' ?>" onclick="switchTab('margen')"><i class="fas fa-chart-line"></i> Margen de Ganancias</div>
    </div>

    <!-- TAB 1: Costos Fijos -->
    <div id="tab-costos" class="fl-tab-content <?= $active_tab=='costos'?'active':'' ?>">
        <div class="fl-card">
            <div class="fl-card-title">Cargar Nuevo Costo Fijo</div>
            <form onsubmit="addCosto(event)" class="fl-grid" style="align-items: end;">
                <div class="fl-group">
                    <label>Categoría</label>
                    <select id="cf_cat" required>
                        <option value="alimentacion">Alimentacion extra</option>
                        <option value="combustible">Combustible</option>
                        <option value="personal">Personal</option>
                        <option value="honorarios">Honorarios</option>
                        <option value="energia">Energia</option>
                        <option value="sanidad">Sanidad</option>
                        <option value="otros">Otros</option>
                    </select>
                </div>
                <div class="fl-group">
                    <label>Concepto / Descripción</label>
                    <input type="text" id="cf_conc" required placeholder="Ej: Sueldo o Vacunas">
                </div>
                <div class="fl-group" style="width: 100px;">
                    <label>Cant. Unidades</label>
                    <input type="number" step="0.01" id="cf_cant" required placeholder="Ej: 100">
                </div>
                <div class="fl-group">
                    <label>Precio x Unidad ($)</label>
                    <input type="number" step="0.01" id="cf_precio" required placeholder="Ej: 50">
                </div>
                <button type="submit" class="fl-btn fl-btn-primary">Añadir Costo</button>
            </form>
        </div>

        <div class="fl-card">
            <div class="fl-card-title">
                <span><i class="fas fa-list-ul" style="color: var(--accent); margin-right: 8px;"></i>Listado de Costos Fijos Mensuales</span>
            </div>
            <div class="fl-table-responsive">
                <table class="fl-table" id="tabla_costos" style="min-width: 100%;">
                    <thead>
                        <tr style="background: rgba(0,0,0,0.2);">
                            <th style="border-radius: 8px 0 0 8px;">Categoría</th>
                            <th>Concepto</th>
                            <th style="text-align: center;">Cant.</th>
                            <th style="text-align: right;">Precio U.</th>
                            <th style="text-align: right;">Total Mensual</th>
                            <th style="text-align: right;">Total x Día</th>
                            <th style="text-align: center; border-radius: 0 8px 8px 0;">Acción</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php 
                        $total_cf_mensual = 0;
                        if(empty($costos)): ?>
                            <tr><td colspan="7" style="text-align: center; padding: 30px; color: var(--text-muted);"><i class="fas fa-info-circle" style="font-size:1.5rem; margin-bottom:10px; display:block;"></i>No hay costos fijos cargados para este lote.</td></tr>
                        <?php else:
                        foreach($costos as $c): 
                            $total_cf_mensual += $c['monto_mensual'];
                        ?>
                        <tr style="transition: all 0.2s; border-bottom: 1px solid rgba(255,255,255,0.03);" onmouseover="this.style.background='rgba(255,255,255,0.02)'" onmouseout="this.style.background='transparent'">
                            <td>
                                <span style="background: rgba(239,68,68,0.15); color: #f87171; padding: 5px 10px; border-radius: 20px; font-size: 0.8rem; font-weight: 600; text-transform: capitalize; border: 1px solid rgba(239,68,68,0.3); display: inline-flex; align-items: center; gap: 5px; white-space: nowrap;">
                                    <i class="fas fa-tag"></i> <?= str_replace('_',' ',$c['categoria']) ?>
                                </span>
                            </td>
                            <td style="font-weight: 500; color: #fff;"><?= htmlspecialchars($c['concepto']) ?></td>
                            <td style="text-align: center; color: var(--text-muted); font-size: 0.95rem;"><?= isset($c['cantidad']) ? (float)$c['cantidad'] : '-' ?></td>
                            <td style="text-align: right; color: var(--text-muted); font-size: 0.95rem; white-space: nowrap;"><?= isset($c['precio_unitario']) ? '$'.number_format($c['precio_unitario'], 2) : '-' ?></td>
                            <td style="text-align: right; color: #10b981; font-weight: bold; font-size: 1.05rem; white-space: nowrap;">$<?= number_format($c['monto_mensual'], 2) ?></td>
                            <td style="text-align: right; color: #38bdf8; font-weight: 600; white-space: nowrap;">$<?= number_format($c['monto_mensual']/30, 2) ?></td>
                            <td style="text-align: center;">
                                <button class="fl-btn" style="padding: 6px 10px; border-radius: 8px; background: rgba(239, 68, 68, 0.1); color: #ef4444; border: 1px solid rgba(239, 68, 68, 0.3); cursor: pointer; transition: all 0.2s;" onmouseover="this.style.background='#ef4444'; this.style.color='white';" onmouseout="this.style.background='rgba(239, 68, 68, 0.1)'; this.style.color='#ef4444';" onclick="delCosto(<?= $c['id'] ?>)" title="Eliminar costo">
                                    <i class="fas fa-trash-alt"></i>
                                </button>
                            </td>
                        </tr>
                        <?php endforeach; endif; ?>
                    </tbody>
                </table>
            </div>
            <?php 
                $cf_dia_total = $total_cf_mensual / 30; 
                $cf_dia_animal = $lote['cant_animales'] > 0 ? $cf_dia_total / $lote['cant_animales'] : 0;
            ?>
            <div style="display:flex; justify-content:flex-end; gap:30px; margin-top:20px; font-size:1.1rem;">
                <div>Total Mensual Fijo Lote: <b>$<?= number_format($total_cf_mensual, 2) ?></b></div>
                <div>Total x Día (Lote): <b>$<?= number_format($cf_dia_total, 2) ?></b></div>
                <div style="color:var(--accent);">CF x Animal x Día: <b>$<?= number_format($cf_dia_animal, 2) ?></b></div>
            </div>
        </div>
    </div>

    <!-- TAB 2: Consumo Alimentario -->
    <div id="tab-consumo" class="fl-tab-content <?= $active_tab=='consumo'?'active':'' ?>">
        <div class="fl-card">
            <div class="fl-card-title">Cargar Nuevo Alimento al Lote</div>
            <form onsubmit="addAlim(event)" class="fl-grid" style="align-items: end;">
                <div class="fl-group">
                    <label>Fase a aplicar</label>
                    <select id="al_fase" required>
                        <option value="invernada">Invernada</option>
                        <option value="engorde">Engorde</option>
                    </select>
                </div>
                <div class="fl-group">
                    <label>Alimento (Nombre)</label>
                    <input type="text" id="al_nom" required placeholder="Ej: Barrido galpón">
                </div>
                <div class="fl-group">
                    <label>Consumo (kg x animal x día)</label>
                    <input type="number" step="0.01" id="al_kg" required placeholder="Ej: 7.00">
                </div>
                <div class="fl-group">
                    <label>Precio Unitario ($/kg)</label>
                    <input type="text" id="al_precio" required placeholder="Ej: 120.00">
                </div>
                <button type="submit" class="fl-btn fl-btn-primary">Añadir Alimento</button>
            </form>
        </div>

        <!-- TABS DE DIETAS -->
        <div class="fl-card" style="margin-bottom: 20px; padding: 10px; display: flex; flex-wrap: wrap; gap: 10px; justify-content: center; background: rgba(0,0,0,0.2);">
            <button class="fl-btn toggle-dieta" style="flex: 1; justify-content: center; background: rgba(16, 185, 129, 0.2); color: #10b981; border: 1px solid rgba(16, 185, 129, 0.4);" onclick="toggleDieta('invernada', this)">
                <i class="fas fa-leaf" style="margin-right: 8px;"></i> Dieta INVERNADA
            </button>
            <button class="fl-btn toggle-dieta" style="flex: 1; justify-content: center; background: transparent; color: var(--text-muted); border: 1px solid rgba(255,255,255,0.1);" onclick="toggleDieta('engorde', this)">
                <i class="fas fa-fire" style="margin-right: 8px;"></i> Dieta ENGORDE
            </button>
        </div>

        <div id="container-invernada" style="display: block;">
            <!-- INVERNADA -->
            <div class="fl-card">
                <div class="fl-card-title" style="color:#10b981;">
                    <span><i class="fas fa-leaf" style="margin-right: 8px;"></i>Dieta INVERNADA</span>
                </div>
                <div class="fl-table-responsive">
                    <table class="fl-table" style="min-width: 100%;">
                        <thead>
                            <tr style="background: rgba(0,0,0,0.2);">
                                <th style="border-radius: 8px 0 0 8px; width: 35%;">Alimento</th>
                                <th style="text-align: right; width: 20%;">Kg/Día</th>
                                <th style="text-align: right; width: 15%;">$/Kg</th>
                                <th style="text-align: right; width: 20%;">Costo/Día</th>
                                <th style="text-align: center; width: 10%; border-radius: 0 8px 8px 0;"></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php 
                            $costo_alim_inv_dia = 0;
                            if(empty($alim_inv)): ?>
                                <tr><td colspan="5" style="text-align: center; padding: 20px; color: var(--text-muted);"><i class="fas fa-info-circle" style="font-size:1.2rem; margin-bottom:5px; display:block;"></i>Sin alimentos cargados.</td></tr>
                            <?php else:
                            foreach($alim_inv as $a): 
                                $c = $a['kg_x_dia'] * $a['precio_kg'];
                                $costo_alim_inv_dia += $c;
                            ?>
                            <tr style="transition: all 0.2s; border-bottom: 1px solid rgba(255,255,255,0.03);" onmouseover="this.style.background='rgba(255,255,255,0.02)'" onmouseout="this.style.background='transparent'">
                                <td style="font-weight: 500; color: #fff; white-space: nowrap;"><?= htmlspecialchars($a['nombre']) ?></td>
                                <td style="text-align: right; color: var(--text-muted); font-size: 0.95rem;"><?= $a['kg_x_dia'] ?></td>
                                <td style="text-align: right; color: var(--text-muted); font-size: 0.95rem; white-space: nowrap;">$<?= number_format($a['precio_kg'],2) ?></td>
                                <td style="text-align: right; color: #10b981; font-weight: bold; font-size: 1.05rem; white-space: nowrap;">$<?= number_format($c,2) ?></td>
                                <td style="text-align: center;">
                                    <button class="fl-btn" style="padding: 4px 8px; border-radius: 6px; background: rgba(239, 68, 68, 0.1); color: #ef4444; border: 1px solid rgba(239, 68, 68, 0.3); cursor: pointer; transition: all 0.2s;" onmouseover="this.style.background='#ef4444'; this.style.color='white';" onmouseout="this.style.background='rgba(239, 68, 68, 0.1)'; this.style.color='#ef4444';" onclick="delAlim(<?= $a['id'] ?>)" title="Eliminar">
                                        <i class="fas fa-trash-alt"></i>
                                    </button>
                                </td>
                            </tr>
                            <?php endforeach; endif; ?>
                        </tbody>
                    </table>
                </div>
                <div style="text-align:right; margin-top:15px; font-weight:bold;">
                    Costo Alimento x Animal x Día: <span style="color:#10b981;">$<?= number_format($costo_alim_inv_dia, 2) ?></span>
                </div>
            </div>
        </div>

        <div id="container-engorde" style="display: none;">
            <!-- ENGORDE -->
            <div class="fl-card">
                <div class="fl-card-title" style="color:#f59e0b;">
                    <span><i class="fas fa-fire" style="margin-right: 8px;"></i>Dieta ENGORDE</span>
                </div>
                <div class="fl-table-responsive">
                    <table class="fl-table" style="min-width: 100%;">
                        <thead>
                            <tr style="background: rgba(0,0,0,0.2);">
                                <th style="border-radius: 8px 0 0 8px; width: 35%;">Alimento</th>
                                <th style="text-align: right; width: 20%;">Kg/Día</th>
                                <th style="text-align: right; width: 15%;">$/Kg</th>
                                <th style="text-align: right; width: 20%;">Costo/Día</th>
                                <th style="text-align: center; width: 10%; border-radius: 0 8px 8px 0;"></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php 
                            $costo_alim_eng_dia = 0;
                            if(empty($alim_eng)): ?>
                                <tr><td colspan="5" style="text-align: center; padding: 20px; color: var(--text-muted);"><i class="fas fa-info-circle" style="font-size:1.2rem; margin-bottom:5px; display:block;"></i>Sin alimentos cargados.</td></tr>
                            <?php else:
                            foreach($alim_eng as $a): 
                                $c = $a['kg_x_dia'] * $a['precio_kg'];
                                $costo_alim_eng_dia += $c;
                            ?>
                            <tr style="transition: all 0.2s; border-bottom: 1px solid rgba(255,255,255,0.03);" onmouseover="this.style.background='rgba(255,255,255,0.02)'" onmouseout="this.style.background='transparent'">
                                <td style="font-weight: 500; color: #fff; white-space: nowrap;"><?= htmlspecialchars($a['nombre']) ?></td>
                                <td style="text-align: right; color: var(--text-muted); font-size: 0.95rem;"><?= $a['kg_x_dia'] ?></td>
                                <td style="text-align: right; color: var(--text-muted); font-size: 0.95rem; white-space: nowrap;">$<?= number_format($a['precio_kg'],2) ?></td>
                                <td style="text-align: right; color: #f59e0b; font-weight: bold; font-size: 1.05rem; white-space: nowrap;">$<?= number_format($c,2) ?></td>
                                <td style="text-align: center;">
                                    <button class="fl-btn" style="padding: 4px 8px; border-radius: 6px; background: rgba(239, 68, 68, 0.1); color: #ef4444; border: 1px solid rgba(239, 68, 68, 0.3); cursor: pointer; transition: all 0.2s;" onmouseover="this.style.background='#ef4444'; this.style.color='white';" onmouseout="this.style.background='rgba(239, 68, 68, 0.1)'; this.style.color='#ef4444';" onclick="delAlim(<?= $a['id'] ?>)" title="Eliminar">
                                        <i class="fas fa-trash-alt"></i>
                                    </button>
                                </td>
                            </tr>
                            <?php endforeach; endif; ?>
                        </tbody>
                    </table>
                </div>
                <div style="text-align:right; margin-top:15px; font-weight:bold;">
                    Costo Alimento x Animal x Día: <span style="color:#f59e0b;">$<?= number_format($costo_alim_eng_dia, 2) ?></span>
                </div>
            </div>
        </div>

        <script>
        function toggleDieta(tipo, btn) {
            document.getElementById('container-invernada').style.display = tipo === 'invernada' ? 'block' : 'none';
            document.getElementById('container-engorde').style.display = tipo === 'engorde' ? 'block' : 'none';
            
            const btns = document.querySelectorAll('.toggle-dieta');
            btns.forEach(b => {
                b.style.background = 'transparent';
                b.style.color = 'var(--text-muted)';
                b.style.borderColor = 'rgba(255,255,255,0.1)';
            });
            
            if(tipo === 'invernada') {
                btn.style.background = 'rgba(16, 185, 129, 0.2)';
                btn.style.color = '#10b981';
                btn.style.borderColor = 'rgba(16, 185, 129, 0.4)';
            } else {
                btn.style.background = 'rgba(245, 158, 11, 0.2)';
                btn.style.color = '#f59e0b';
                btn.style.borderColor = 'rgba(245, 158, 11, 0.4)';
            }
        }
        </script>
    </div>

    <!-- TAB 3: Margen de Ganancias -->
    <div id="tab-margen" class="fl-tab-content <?= $active_tab=='margen'?'active':'' ?>">
        <?php
            // Lógica de cálculos Margen de Ganancias
            $q = $lote['cant_animales'];
            $q_inv = $q * ($lote['pct_invernada']/100);
            $q_eng = $q * ($lote['pct_engorde']/100);

            // Costos Fijos
            $cf_dia_animal_inv = $cf_dia_animal; // Asumimos costo fijo parejo
            $cf_dia_animal_eng = $cf_dia_animal;
            
            // Días Totales
            $dias_inv = $lote['dias_invernada'];
            $dias_eng = $lote['dias_engorde'];

            // Costos Productivos (Alimentación)
            $cp_inv_animal = $costo_alim_inv_dia * $dias_inv;
            $cp_eng_animal = $costo_alim_eng_dia * $dias_eng;

            // Invernada
            $precio_compra_inv = $lote['precio_compra']; // Compra inicial
            $costo_compra_inv_animal = $lote['kg_entrada_inv'] * $precio_compra_inv * (1 + ($lote['flete_compra_pct']/100));
            $costo_fijo_inv_animal = $cf_dia_animal_inv * $dias_inv;
            $ingreso_venta_inv_animal = $lote['kg_salida_inv'] * $lote['precio_venta_inv'] * (1 - ($lote['flete_venta_pct']/100));
            
            $margen_inv_animal = $ingreso_venta_inv_animal - ($costo_compra_inv_animal + $cp_inv_animal + $costo_fijo_inv_animal);
            $margen_inv_pct = $costo_compra_inv_animal > 0 ? ($margen_inv_animal / $costo_compra_inv_animal) * 100 : 0;
            $margen_inv_usd = $lote['usd_referencia'] > 0 ? $margen_inv_animal / $lote['usd_referencia'] : 0;

            // Engorde
            // Para el engorde, el "costo de compra" es el costo de oportunidad de la venta de la invernada
            $costo_compra_eng_animal = $lote['kg_entrada_eng'] * $lote['precio_venta_inv']; 
            $costo_fijo_eng_animal = $cf_dia_animal_eng * $dias_eng;
            $ingreso_venta_eng_animal = $lote['kg_salida_eng'] * $lote['precio_venta_eng'] * (1 - ($lote['flete_venta_pct']/100));

            $margen_eng_animal = $ingreso_venta_eng_animal - ($costo_compra_eng_animal + $cp_eng_animal + $costo_fijo_eng_animal);
            $margen_eng_pct = $costo_compra_eng_animal > 0 ? ($margen_eng_animal / $costo_compra_eng_animal) * 100 : 0;
            $margen_eng_usd = $lote['usd_referencia'] > 0 ? $margen_eng_animal / $lote['usd_referencia'] : 0;

            // Sistema Total
            $ingreso_total = $q_eng * $ingreso_venta_eng_animal; // Asumiendo que todo termina en engorde (como el Excel)
            $ganancia_total = $q_eng * $margen_eng_animal; 
        ?>
        <div class="fl-grid">
            <!-- INVERNADA -->
            <div class="fl-card" style="border-left: 4px solid #10b981;">
                <div class="fl-card-title" style="color: #10b981;"><i class="fas fa-leaf"></i> Invernada (Por Animal)</div>
                <div style="display:flex; justify-content:space-between; margin-bottom: 15px;">
                    <div><small style="color:var(--text-muted)">Kg Entra</small><br><b><?= $lote['kg_entrada_inv'] ?> kg</b></div>
                    <div style="text-align:right;"><small style="color:var(--text-muted)">Kg Sale</small><br><b><?= $lote['kg_salida_inv'] ?> kg</b></div>
                </div>
                
                <table style="width:100%; font-size:0.9rem; margin-bottom:15px; border-top:1px solid rgba(255,255,255,0.1); padding-top:10px;">
                    <tr><td style="color:var(--text-muted); padding:4px 0;">Costo Compra ($<?= number_format($precio_compra_inv, 2) ?>/kg)</td><td style="text-align:right; padding:4px 0;">$<?= number_format($costo_compra_inv_animal, 2) ?></td></tr>
                    <tr><td style="color:var(--text-muted); padding:4px 0;">Costo Productivo</td><td style="text-align:right; padding:4px 0;">$<?= number_format($cp_inv_animal, 2) ?></td></tr>
                    <tr><td style="color:var(--text-muted); padding:4px 0;">Costo Fijo</td><td style="text-align:right; padding:4px 0;">$<?= number_format($costo_fijo_inv_animal, 2) ?></td></tr>
                    <tr><td style="color:var(--text-muted); padding:4px 0;">Ingreso Venta ($<?= number_format($lote['precio_venta_inv'], 2) ?>/kg)</td><td style="text-align:right; color:#10b981; padding:4px 0;">$<?= number_format($ingreso_venta_inv_animal, 2) ?></td></tr>
                </table>
                
                <div style="background:rgba(0,0,0,0.2); padding:15px; border-radius:8px; display:flex; justify-content:space-between; align-items:center;">
                    <div>
                        <small style="color:var(--text-muted)">Mg (%)</small><br>
                        <b style="color:<?= $margen_inv_pct>=0?'#10b981':'#ef4444'?>;"><?= number_format($margen_inv_pct, 2) ?>%</b>
                    </div>
                    <div style="text-align:center;">
                        <small style="color:var(--text-muted)">Mg (USD)</small><br>
                        <b style="color:<?= $margen_inv_usd>=0?'#10b981':'#ef4444'?>;">$<?= number_format($margen_inv_usd, 2) ?></b>
                    </div>
                    <div style="text-align:right;">
                        <small style="color:var(--text-muted)">Margen ($)</small><br>
                        <b style="color:<?= $margen_inv_animal>=0?'#10b981':'#ef4444'?>; font-size:1.2rem;">$<?= number_format($margen_inv_animal, 2) ?></b>
                    </div>
                </div>
            </div>

            <!-- ENGORDE -->
            <div class="fl-card" style="border-left: 4px solid #f59e0b;">
                <div class="fl-card-title" style="color: #f59e0b;"><i class="fas fa-fire"></i> Engorde (Por Animal)</div>
                <div style="display:flex; justify-content:space-between; margin-bottom: 15px;">
                    <div><small style="color:var(--text-muted)">Kg Entra</small><br><b><?= $lote['kg_entrada_eng'] ?> kg</b></div>
                    <div style="text-align:right;"><small style="color:var(--text-muted)">Kg Sale</small><br><b><?= $lote['kg_salida_eng'] ?> kg</b></div>
                </div>
                
                <table style="width:100%; font-size:0.9rem; margin-bottom:15px; border-top:1px solid rgba(255,255,255,0.1); padding-top:10px;">
                    <tr><td style="color:var(--text-muted); padding:4px 0;">Costo Compra ($<?= number_format($lote['precio_venta_inv'], 2) ?>/kg)</td><td style="text-align:right; padding:4px 0;">$<?= number_format($costo_compra_eng_animal, 2) ?></td></tr>
                    <tr><td style="color:var(--text-muted); padding:4px 0;">Costo Productivo</td><td style="text-align:right; padding:4px 0;">$<?= number_format($cp_eng_animal, 2) ?></td></tr>
                    <tr><td style="color:var(--text-muted); padding:4px 0;">Costo Fijo</td><td style="text-align:right; padding:4px 0;">$<?= number_format($costo_fijo_eng_animal, 2) ?></td></tr>
                    <tr><td style="color:var(--text-muted); padding:4px 0;">Ingreso Venta ($<?= number_format($lote['precio_venta_eng'], 2) ?>/kg)</td><td style="text-align:right; color:#10b981; padding:4px 0;">$<?= number_format($ingreso_venta_eng_animal, 2) ?></td></tr>
                </table>
                
                <div style="background:rgba(0,0,0,0.2); padding:15px; border-radius:8px; display:flex; justify-content:space-between; align-items:center;">
                    <div>
                        <small style="color:var(--text-muted)">Mg (%)</small><br>
                        <b style="color:<?= $margen_eng_pct>=0?'#10b981':'#ef4444'?>;"><?= number_format($margen_eng_pct, 2) ?>%</b>
                    </div>
                    <div style="text-align:center;">
                        <small style="color:var(--text-muted)">Mg (USD)</small><br>
                        <b style="color:<?= $margen_eng_usd>=0?'#10b981':'#ef4444'?>;">$<?= number_format($margen_eng_usd, 2) ?></b>
                    </div>
                    <div style="text-align:right;">
                        <small style="color:var(--text-muted)">Margen ($)</small><br>
                        <b style="color:<?= $margen_eng_animal>=0?'#10b981':'#ef4444'?>; font-size:1.2rem;">$<?= number_format($margen_eng_animal, 2) ?></b>
                    </div>
                </div>
            </div>
        </div>

        <div class="fl-grid">
            <div class="fl-card">
                <div class="fl-card-title">Detalles del Cálculo</div>
                <table class="fl-table" style="font-size:0.85rem;">
                    <tr><td>Cantidad de animales</td><td style="text-align:right;"><?= $lote['cant_animales'] ?></td></tr>
                    <tr><td>Kg Conversión Invernada</td><td style="text-align:right;"><?= $lote['conv_inv'] ?></td></tr>
                    <tr><td>Kg Conversión Engorde</td><td style="text-align:right;"><?= $lote['conv_eng'] ?></td></tr>
                    <tr><td>Flete tomado para Compra</td><td style="text-align:right;"><?= $lote['flete_compra_pct'] ?>%</td></tr>
                    <tr><td>Flete tomado para Venta</td><td style="text-align:right;"><?= $lote['flete_venta_pct'] ?>%</td></tr>
                    <tr><td>Total días de Invernada</td><td style="text-align:right;"><?= $dias_inv ?></td></tr>
                    <tr><td>Total días de Engorde</td><td style="text-align:right;"><?= $dias_eng ?></td></tr>
                    <tr><td>Total días del Sistema</td><td style="text-align:right; font-weight:bold;"><?= $dias_inv + $dias_eng ?></td></tr>
                    <tr><td>Desperdicio de alimentos tomado</td><td style="text-align:right;"><?= $lote['desperdicio_pct'] ?>%</td></tr>
                    <tr><td>USD Tomado</td><td style="text-align:right; color:#10b981;">$<?= number_format($lote['usd_referencia'],2) ?></td></tr>
                </table>
            </div>
        </div>

    </div>

<?php endif; ?>

<!-- Modal Lote -->
<div class="fl-modal" id="modalLote">
    <div class="fl-modal-content">
        <h3 id="modalLoteTitle" style="margin-top:0; color:var(--text-primary); border-bottom:1px solid rgba(255,255,255,0.1); padding-bottom:15px; margin-bottom:20px;">Configurar Lote Simulador</h3>
        <form onsubmit="saveLote(event)">
            <input type="hidden" id="l_id" value="">
            
            <div class="fl-grid">
                <div class="fl-group" style="grid-column: 1 / -1;"><label>Nombre del Lote</label><input type="text" id="l_nombre" required></div>
                
                <div class="fl-group"><label>Fecha Inicio</label><input type="date" id="l_fecha_inicio" required></div>
                <div class="fl-group"><label>Cant. Animales</label><input type="number" id="l_cant" required></div>
                <div class="fl-group"><label>USD de Referencia</label><input type="number" step="0.01" id="l_usd"></div>
                
                <div class="fl-group" style="grid-column: 1 / -1; margin-top:10px; border-top:1px solid rgba(255,255,255,0.1); padding-top:10px;"><strong style="color:var(--text-primary);">Configuración INVERNADA</strong></div>
                <div class="fl-group"><label>% del Lote</label><input type="number" step="0.01" id="l_pct_inv" value="70"></div>
                <div class="fl-group"><label>Kg Entrada</label><input type="number" step="0.01" id="l_kg_ent_inv" value="130"></div>
                <div class="fl-group"><label>Kg Salida</label><input type="number" step="0.01" id="l_kg_sal_inv" value="240"></div>
                <div class="fl-group"><label>Días Invernada</label><input type="number" id="l_dias_inv" value="110"></div>
                <div class="fl-group"><label>Precio Compra ($/kg)</label><input type="number" step="0.01" id="l_pr_compra"></div>
                <div class="fl-group"><label>Precio Venta ($/kg)</label><input type="number" step="0.01" id="l_pr_venta_inv"></div>
                
                <div class="fl-group" style="grid-column: 1 / -1; margin-top:10px; border-top:1px solid rgba(255,255,255,0.1); padding-top:10px;"><strong style="color:var(--text-primary);">Configuración ENGORDE</strong></div>
                <div class="fl-group"><label>% del Lote</label><input type="number" step="0.01" id="l_pct_eng" value="30"></div>
                <div class="fl-group"><label>Kg Entrada</label><input type="number" step="0.01" id="l_kg_ent_eng" value="240"></div>
                <div class="fl-group"><label>Kg Salida</label><input type="number" step="0.01" id="l_kg_sal_eng" value="360"></div>
                <div class="fl-group"><label>Días Engorde</label><input type="number" id="l_dias_eng" value="92"></div>
                <div class="fl-group"><label>Precio Venta Final ($/kg)</label><input type="number" step="0.01" id="l_pr_venta_eng"></div>

                <div class="fl-group" style="grid-column: 1 / -1; margin-top:10px; border-top:1px solid rgba(255,255,255,0.1); padding-top:10px;"><strong style="color:var(--text-primary);">Factores Extra</strong></div>
                <div class="fl-group"><label>Conversión Inv.</label><input type="number" step="0.01" id="l_conv_inv" value="1.0"></div>
                <div class="fl-group"><label>Conversión Eng.</label><input type="number" step="0.01" id="l_conv_eng" value="1.3"></div>
                <div class="fl-group"><label>% Desperdicio</label><input type="number" step="0.01" id="l_desp" value="2"></div>
                <div class="fl-group"><label>% Flete Compra</label><input type="number" step="0.01" id="l_flete_compra" value="10"></div>
                <div class="fl-group"><label>% Flete Venta</label><input type="number" step="0.01" id="l_flete_venta" value="5"></div>
            </div>
            
            <div style="display:flex; justify-content:flex-end; gap:10px; margin-top:25px;">
                <?php if($lote): ?>
                    <button type="button" class="fl-btn fl-btn-danger" style="margin-right:auto;" onclick="delLote()">Eliminar Lote</button>
                <?php endif; ?>
                <button type="button" class="fl-btn" style="background:rgba(255,255,255,0.1);" onclick="closeModal('modalLote')">Cancelar</button>
                <button type="submit" class="fl-btn fl-btn-primary">Guardar Lote</button>
            </div>
        </form>
    </div>
</div>

<script>
const CSRF_TOKEN = '<?= get_csrf_token() ?>';
// Mover el modal al final del body para evitar que el sidebar lo tape (por z-index context)
document.body.appendChild(document.getElementById('modalLote'));

const currLote = <?= $lid ?>;



function switchTab(tab) {
    document.querySelectorAll('.fl-tab').forEach(e => e.classList.remove('active'));
    document.querySelectorAll('.fl-tab-content').forEach(e => e.classList.remove('active'));
    let btn = document.getElementById('tab-btn-' + tab);
    if(btn) btn.classList.add('active');
    document.getElementById('tab-'+tab).classList.add('active');
}

function openLoteModal(edit=false) {
    const m = document.getElementById('modalLote');
    if(!edit) {
        document.getElementById('l_id').value = '';
        document.getElementById('l_nombre').value = '';
        document.getElementById('modalLoteTitle').innerText = 'Nuevo Lote Simulador';
    } else {
        <?php if($lote): ?>
        document.getElementById('l_id').value = '<?= $lote['id'] ?>';
        document.getElementById('l_nombre').value = '<?= $lote['nombre'] ?>';
        document.getElementById('l_fecha_inicio').value = '<?= $lote['fecha_inicio'] ?>';
        document.getElementById('l_cant').value = '<?= $lote['cant_animales'] ?>';
        document.getElementById('l_usd').value = '<?= $lote['usd_referencia'] ?>';
        
        document.getElementById('l_pct_inv').value = '<?= $lote['pct_invernada'] ?>';
        document.getElementById('l_kg_ent_inv').value = '<?= $lote['kg_entrada_inv'] ?>';
        document.getElementById('l_kg_sal_inv').value = '<?= $lote['kg_salida_inv'] ?>';
        document.getElementById('l_dias_inv').value = '<?= $lote['dias_invernada'] ?>';
        document.getElementById('l_pr_compra').value = '<?= $lote['precio_compra'] ?>';
        document.getElementById('l_pr_venta_inv').value = '<?= $lote['precio_venta_inv'] ?>';

        document.getElementById('l_pct_eng').value = '<?= $lote['pct_engorde'] ?>';
        document.getElementById('l_kg_ent_eng').value = '<?= $lote['kg_entrada_eng'] ?>';
        document.getElementById('l_kg_sal_eng').value = '<?= $lote['kg_salida_eng'] ?>';
        document.getElementById('l_dias_eng').value = '<?= $lote['dias_engorde'] ?>';
        document.getElementById('l_pr_venta_eng').value = '<?= $lote['precio_venta_eng'] ?>';

        document.getElementById('l_conv_inv').value = '<?= $lote['conv_inv'] ?>';
        document.getElementById('l_conv_eng').value = '<?= $lote['conv_eng'] ?>';
        document.getElementById('l_desp').value = '<?= $lote['desperdicio_pct'] ?>';
        document.getElementById('l_flete_compra').value = '<?= $lote['flete_compra_pct'] ?>';
        document.getElementById('l_flete_venta').value = '<?= $lote['flete_venta_pct'] ?>';
        <?php endif; ?>
        document.getElementById('modalLoteTitle').innerText = 'Configurar Lote';
    }
    m.style.display = 'flex';
    document.querySelectorAll('input[type="number"]').forEach(i => i.dispatchEvent(new Event('input')));
}

function closeModal(id) { document.getElementById(id).style.display = 'none'; }

function apiCall(data) {
    data.append('ajax', '1');
    data.append('csrf_token', CSRF_TOKEN);
    return fetch('ganaderia_feedlot.php', { method: 'POST', body: data }).then(r=>r.json());
}

function saveLote(e) {
    e.preventDefault();
    let fd = new FormData();
    fd.append('action','save_lote');
    fd.append('id', document.getElementById('l_id').value);
    fd.append('nombre', document.getElementById('l_nombre').value);
    fd.append('fecha_inicio', document.getElementById('l_fecha_inicio').value);
    fd.append('cant_animales', document.getElementById('l_cant').value);
    fd.append('usd_referencia', document.getElementById('l_usd').value);
    
    fd.append('pct_invernada', document.getElementById('l_pct_inv').value);
    fd.append('kg_entrada_inv', document.getElementById('l_kg_ent_inv').value);
    fd.append('kg_salida_inv', document.getElementById('l_kg_sal_inv').value);
    fd.append('dias_invernada', document.getElementById('l_dias_inv').value);
    fd.append('precio_compra', document.getElementById('l_pr_compra').value);
    fd.append('precio_venta_inv', document.getElementById('l_pr_venta_inv').value);

    fd.append('pct_engorde', document.getElementById('l_pct_eng').value);
    fd.append('kg_entrada_eng', document.getElementById('l_kg_ent_eng').value);
    fd.append('kg_salida_eng', document.getElementById('l_kg_sal_eng').value);
    fd.append('dias_engorde', document.getElementById('l_dias_eng').value);
    fd.append('precio_venta_eng', document.getElementById('l_pr_venta_eng').value);

    fd.append('conv_inv', document.getElementById('l_conv_inv').value);
    fd.append('conv_eng', document.getElementById('l_conv_eng').value);
    fd.append('desperdicio_pct', document.getElementById('l_desp').value);
    fd.append('flete_compra_pct', document.getElementById('l_flete_compra').value);
    fd.append('flete_venta_pct', document.getElementById('l_flete_venta').value);

    apiCall(fd).then(r => { if(r.ok) window.location.href='ganaderia_feedlot.php?lote='+r.id; });
}

function delLote() {
    if(!confirm('¿Eliminar lote y todos sus costos y consumos?')) return;
    let fd = new FormData(); fd.append('action','delete_lote'); fd.append('id', currLote);
    apiCall(fd).then(r => { if(r.ok) window.location.href='ganaderia_feedlot.php'; });
}

function addCosto(e) {
    e.preventDefault();
    let fd = new FormData();
    fd.append('action', 'add_costo'); fd.append('lote_id', currLote);
    fd.append('categoria', document.getElementById('cf_cat').value);
    fd.append('concepto', document.getElementById('cf_conc').value);
    let cant = parseFloat(document.getElementById('cf_cant').value) || 0;
    let pre = parseFloat(document.getElementById('cf_precio').value) || 0;
    fd.append('cantidad', cant);
    fd.append('precio_unitario', pre);
    fd.append('monto', cant * pre);
    apiCall(fd).then(r => { if(r.ok) window.location.href = '?lote=' + currLote + '&tab=costos'; });
}

function delCosto(id) {
    if(!confirm('Borrar costo?')) return;
    let fd = new FormData(); fd.append('action','del_costo'); fd.append('id', id);
    apiCall(fd).then(r => { if(r.ok) window.location.href = '?lote=' + currLote + '&tab=costos'; });
}

function addAlim(e) {
    e.preventDefault();
    let fd = new FormData();
    fd.append('action', 'add_alim'); fd.append('lote_id', currLote);
    fd.append('fase', document.getElementById('al_fase').value);
    fd.append('nombre', document.getElementById('al_nom').value);
    fd.append('kg_x_dia', document.getElementById('al_kg').value);
    fd.append('precio_kg', document.getElementById('al_precio').value);
    apiCall(fd).then(r => { if(r.ok) window.location.href = '?lote=' + currLote + '&tab=consumo'; });
}

function delAlim(id) {
    if(!confirm('Borrar alimento?')) return;
    let fd = new FormData(); fd.append('action','del_alim'); fd.append('id', id);
    apiCall(fd).then(r => { if(r.ok) window.location.href = '?lote=' + currLote + '&tab=consumo'; });
}
function calcDias() {
    let ent_inv = parseFloat(document.getElementById('l_kg_ent_inv').value) || 0;
    let sal_inv = parseFloat(document.getElementById('l_kg_sal_inv').value) || 0;
    let conv_inv = parseFloat(document.getElementById('l_conv_inv').value) || 1;
    
    if (conv_inv > 0) {
        let dias_inv = Math.round((sal_inv - ent_inv) / conv_inv);
        if (dias_inv > 0) document.getElementById('l_dias_inv').value = dias_inv;
    }

    let ent_eng = parseFloat(document.getElementById('l_kg_ent_eng').value) || 0;
    let sal_eng = parseFloat(document.getElementById('l_kg_sal_eng').value) || 0;
    let conv_eng = parseFloat(document.getElementById('l_conv_eng').value) || 1;
    
    if (conv_eng > 0) {
        let dias_eng = Math.round((sal_eng - ent_eng) / conv_eng);
        if (dias_eng > 0) document.getElementById('l_dias_eng').value = dias_eng;
    }
}

document.addEventListener('DOMContentLoaded', () => {
    // Escuchar cambios para auto-calcular días de invernada/engorde
    const inputsToWatch = ['l_kg_ent_inv', 'l_kg_sal_inv', 'l_conv_inv', 'l_kg_ent_eng', 'l_kg_sal_eng', 'l_conv_eng'];
    inputsToWatch.forEach(id => {
        let el = document.getElementById(id);
        if (el) el.addEventListener('input', calcDias);
    });

    // ── Formateador visual de miles para inputs ──
    const numberInputs = document.querySelectorAll('input[type="number"]');
    numberInputs.forEach(input => {
        const parent = input.parentElement;
        let hint = parent.querySelector('.num-hint');
        if (!hint && parent.classList.contains('fl-group')) {
            hint = document.createElement('span');
            hint.className = 'num-hint';
            parent.appendChild(hint);
        }

        const updateHint = () => {
            if (!hint) return;
            const val = parseFloat(input.value);
            if (!isNaN(val) && val !== 0) {
                const isPrice = input.id.includes('precio') || input.id.includes('pr_') || input.id.includes('usd') || input.id.includes('monto') || input.id.includes('cf_precio') || input.id.includes('al_precio');
                const isPct = input.id.includes('pct') || input.id.includes('flete') || input.id.includes('desp');
                
                const formatter = new Intl.NumberFormat('es-AR', {
                    style: isPrice ? 'currency' : 'decimal',
                    currency: 'ARS',
                    maximumFractionDigits: 2
                });
                
                let text = formatter.format(val);
                if(isPct) text += '%';
                hint.innerText = text;
            } else {
                hint.innerText = '';
            }
        };

        input.addEventListener('input', updateHint);
        updateHint();
    });

    // Abrir modal si ?nuevo=1
    const params = new URLSearchParams(window.location.search);
    if(params.get('nuevo') == '1') {
        openLoteModal();
    }
});
</script>

<?php require_once 'includes/footer.php'; ?>
