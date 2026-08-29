<?php
/**
 * Regenera migrations/esquema_completo.sql desde el esquema real de la base local.
 *
 *   php migrations/generar_esquema_completo.php
 *
 * Se genera y no se escribe a mano a propósito: son 30 tablas y casi 300
 * columnas, y una definición copiada mal a mano es un tipo de dato equivocado
 * en producción que nadie nota hasta que redondea un precio.
 *
 * CORRERLO CADA VEZ QUE CAMBIE EL ESQUEMA. Si no, esquema_completo.sql queda
 * atrasado y deja de servir para lo único que sirve.
 *
 * OJO: la base local tiene que estar al día antes de generar. Si a local le
 * falta una migración, el tipo viejo sale horneado en el archivo y se
 * propaga. Ya pasó: local tenía siete columnas de plata en 2 decimales que
 * producción hacía rato tenía en 4.
 */

$pdo = new PDO('mysql:host=127.0.0.1;dbname=agro_planner;charset=utf8mb4', 'root', '',
               [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);

$tablas = $pdo->query("SELECT TABLE_NAME FROM information_schema.TABLES
                       WHERE TABLE_SCHEMA = 'agro_planner' AND TABLE_TYPE = 'BASE TABLE'
                       ORDER BY TABLE_NAME")->fetchAll(PDO::FETCH_COLUMN);

$primero = ['users', 'cultivos', 'lotes', 'depositos'];
usort($tablas, function ($a, $b) use ($primero) {
    $ia = array_search($a, $primero); $ib = array_search($b, $primero);
    if ($ia === false && $ib === false) return strcmp($a, $b);
    if ($ia === false) return 1;
    if ($ib === false) return -1;
    return $ia - $ib;
});

/* Columnas de plata cuya PRECISIÓN cambió con el tiempo. Van aparte porque
   ADD COLUMN IF NOT EXISTS no toca una columna que ya existe: si quedó con el
   tipo viejo, pasa de largo en silencio y el redondeo sigue. */
$precision = [
    ['operacion_insumos', 'precio_unitario',       'DECIMAL(14,4) NOT NULL DEFAULT 0'],
    ['operacion_insumos', 'cantidad_ha',           'DECIMAL(14,4) NOT NULL DEFAULT 0'],
    ['operaciones',       'precio_unitario',       'DECIMAL(14,4) NOT NULL DEFAULT 0'],
    ['operaciones',       'cantidad_ha',           'DECIMAL(14,4) NOT NULL DEFAULT 0'],
    ['insumos',           'precio_estimado_usd',   'DECIMAL(14,4) NOT NULL DEFAULT 0'],
    ['produccion_ventas', 'precio_kg',             'DECIMAL(14,4) NOT NULL DEFAULT 0'],
    ['alquileres',        'monto_pagado',          'DECIMAL(14,4) NOT NULL DEFAULT 0'],
    ['lotes',             'costo_alquiler_tns_ha', 'DECIMAL(14,4) NULL DEFAULT NULL'],
];

$o = [];
$L = function ($s = '') use (&$o) { $o[] = $s; };

$L("-- ═══════════════════════════════════════════════════════════════");
$L("-- AgroPlanner · Esquema completo de la base");
$L("-- ═══════════════════════════════════════════════════════════════");
$L("-- Reemplaza a las 20 migraciones sueltas de migrations/. Ninguna de");
$L("-- ellas era idempotente —ADD COLUMN pelado— así que correrlas en bloque");
$L("-- fallaba en la primera que ya estuviera aplicada, y no hay tabla que");
$L("-- registre cuáles corrieron. Este archivo se puede correr sin saberlo:");
$L("-- crea lo que falta, deja lo que ya está, y no borra nada nunca.");
$L("--");
$L("-- Correr entero, de arriba abajo, en phpMyAdmin. Es seguro repetirlo.");
$L("-- Si la pestaña SQL no acepta pegarlo entero por tamaño, subirlo por");
$L("-- la pestaña Importar, que lee el archivo.");
$L("--");
$L("-- SÓLO ESQUEMA, SIN DATOS. Las migraciones viejas traían además algún");
$L("-- UPDATE de datos —marcar la cuenta demo como solo lectura, prender los");
$L("-- módulos de los usuarios activos—. Eso no está acá: en una base que ya");
$L("-- viene funcionando ya corrió, y en una base nueva no hay a quién");
$L("-- aplicárselo. Si alguna vez se levanta un entorno desde cero, esos");
$L("-- UPDATE hay que correrlos aparte desde migrations/.");
$L("--");
$L("-- ANTES DE CORRERLO: hacer el backup de la PARTE 0. No es opcional.");
$L("-- ═══════════════════════════════════════════════════════════════");
$L();
$L();
$L("-- ───────────────────────────────────────────────────────────────");
$L("-- PARTE 0 · Backup. Hacerlo antes que nada.");
$L("-- ───────────────────────────────────────────────────────────────");
$L("-- En hPanel: Bases de datos → phpMyAdmin → elegir la base → pestaña");
$L("-- Exportar → método Rápido → formato SQL → Continuar. Guardar el");
$L("-- archivo antes de seguir.");
$L("--");
$L("-- Por SSH sería:");
$L("--   mysqldump -u USUARIO -p NOMBRE_BASE > backup_\$(date +%F).sql");
$L("--");
$L("-- Este archivo no borra ni convierte datos, pero un ALTER sobre una");
$L("-- tabla con datos reales sin backup no se hace y punto.");
$L();
$L();
$L("-- ───────────────────────────────────────────────────────────────");
$L("-- PARTE 1 · Dónde estamos parados.");
$L("-- ───────────────────────────────────────────────────────────────");
$L("-- Tiene que decir MariaDB. Este archivo usa ADD COLUMN IF NOT EXISTS,");
$L("-- que es de MariaDB: en MySQL 8 falla. Si dice MySQL, pará acá.");
$L("SELECT VERSION() AS motor, DATABASE() AS base;");
$L();
$L("-- Cuántas de las " . count($tablas) . " tablas esperadas ya existen.");
$L("SELECT COUNT(*) AS tablas_presentes, " . count($tablas) . " AS tablas_esperadas");
$L("FROM information_schema.TABLES");
$L("WHERE TABLE_SCHEMA = DATABASE() AND TABLE_TYPE = 'BASE TABLE'");
$L("  AND TABLE_NAME IN (" . implode(', ', array_map(fn($t) => "'$t'", $tablas)) . ");");
$L();
$L();

// ─── PARTE 2 · tablas ─────────────────────────────────────────────────────
$L("-- ───────────────────────────────────────────────────────────────");
$L("-- PARTE 2 · Tablas que falten. Las que ya están no se tocan.");
$L("-- ───────────────────────────────────────────────────────────────");
$L("-- Las tablas se referencian entre sí, así que el orden de creación");
$L("-- importaría. Se apagan los chequeos mientras se crean y se vuelven a");
$L("-- prender al final: es de sesión, no cambia nada de la base.");
$L("SET FOREIGN_KEY_CHECKS = 0;");
$L();

foreach ($tablas as $t) {
    $ddl = $pdo->query("SHOW CREATE TABLE `$t`")->fetch(PDO::FETCH_NUM)[1];
    $ddl = preg_replace('/\s*AUTO_INCREMENT=\d+/', '', $ddl);
    $ddl = preg_replace('/^CREATE TABLE /', 'CREATE TABLE IF NOT EXISTS ', $ddl);
    $L($ddl . ';');
    $L();
}
$L("SET FOREIGN_KEY_CHECKS = 1;");
$L();
$L();

// ─── PARTE 3 · columnas ───────────────────────────────────────────────────
$L("-- ───────────────────────────────────────────────────────────────");
$L("-- PARTE 3 · Columnas que falten en tablas que ya existen.");
$L("-- ───────────────────────────────────────────────────────────────");
$L("-- Esto es lo que fueron agregando las migraciones sueltas con el");
$L("-- tiempo. Una base vieja puede tener la tabla pero no la columna.");
$L();

$st = $pdo->prepare(
    "SELECT COLUMN_NAME, COLUMN_TYPE, IS_NULLABLE, COLUMN_DEFAULT, EXTRA,
            COLUMN_COMMENT, GENERATION_EXPRESSION
     FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = 'agro_planner' AND TABLE_NAME = ?
     ORDER BY ORDINAL_POSITION");

$esperado = [];
$total = 0;
foreach ($tablas as $t) {
    $st->execute([$t]);
    $lineas = [];
    $previa = null;

    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $c) {
        $extra = strtolower($c['EXTRA']);
        $esperado[] = [$t, $c['COLUMN_NAME'], $c['COLUMN_TYPE']];

        // La PK autoincremental siempre existe; agregarla necesita índice.
        if (strpos($extra, 'auto_increment') !== false) { $previa = $c['COLUMN_NAME']; continue; }

        $def = $c['COLUMN_TYPE'];
        if (strpos($extra, 'generated') !== false) {
            $tipo = (strpos($extra, 'virtual') !== false) ? 'VIRTUAL' : 'STORED';
            $def .= " AS ({$c['GENERATION_EXPRESSION']}) $tipo";
        } else {
            $def .= ($c['IS_NULLABLE'] === 'NO') ? ' NOT NULL' : ' NULL';
            if ($c['COLUMN_DEFAULT'] !== null)          $def .= ' DEFAULT ' . $c['COLUMN_DEFAULT'];
            elseif ($c['IS_NULLABLE'] === 'YES')        $def .= ' DEFAULT NULL';
            if (strpos($extra, 'on update') !== false)  $def .= ' ON UPDATE current_timestamp()';
        }
        if ($c['COLUMN_COMMENT'] !== '') {
            $def .= " COMMENT '" . str_replace("'", "''", $c['COLUMN_COMMENT']) . "'";
        }

        $pos = $previa ? " AFTER `$previa`" : ' FIRST';
        $lineas[] = "ALTER TABLE `$t` ADD COLUMN IF NOT EXISTS `{$c['COLUMN_NAME']}` $def$pos;";
        $previa = $c['COLUMN_NAME'];
        $total++;
    }

    if ($lineas) { $L("-- $t"); foreach ($lineas as $l) $L($l); $L(); }
}
$L();

// ─── PARTE 4 · precisión ──────────────────────────────────────────────────
$L("-- ───────────────────────────────────────────────────────────────");
$L("-- PARTE 4 · Precisión de las columnas de plata.");
$L("-- ───────────────────────────────────────────────────────────────");
$L("-- La PARTE 3 no alcanza acá: ADD COLUMN IF NOT EXISTS no toca una");
$L("-- columna que ya existe, así que si quedó con 2 decimales pasa de");
$L("-- largo en silencio. Estas columnas guardan precios y cantidades: con");
$L("-- 2 decimales, un precio de 0,945 se guarda 0,95 y el total deja de");
$L("-- coincidir con el que se calculó al cargarlo.");
$L("--");
$L("-- MODIFY es idempotente por naturaleza: si ya está en 14,4 no hace");
$L("-- nada. Sólo amplía, nunca achica, así que no puede truncar un dato.");
$L();
foreach ($precision as [$t, $c, $def]) {
    $L("ALTER TABLE `$t` MODIFY COLUMN `$c` $def;");
}
$L();
$L();

// ─── PARTE 5 · verificación ───────────────────────────────────────────────
// Este bloque se reusa tal cual para armar el diagnóstico de sólo lectura.
$inicioVerif = count($o);
$L("-- ───────────────────────────────────────────────────────────────");
$L("-- PARTE 5 · Verificación. Correr al final.");
$L("-- ───────────────────────────────────────────────────────────────");
$L("-- Compara la base contra el esquema esperado y muestra lo que no");
$L("-- coincida. Las PARTES 2 a 4 arreglan lo que saben arreglar; esto");
$L("-- delata cualquier otra diferencia en vez de dejarla pasar callada.");
$L("--");
$L("-- El esquema esperado va embebido en la consulta, no en una tabla");
$L("-- auxiliar: crearla exige permisos de CREATE y falla si phpMyAdmin no");
$L("-- tiene tu base seleccionada. Así no hace falta escribir nada.");
$L();

/* La lista esperada se arma como tabla derivada. Es más larga de leer que
   un INSERT, pero no escribe nada y por lo tanto no puede fallar por
   permisos ni ensuciar la base de nadie. */
$derivada = function () use ($esperado) {
    $filas = [];
    foreach ($esperado as $i => $e) {
        $t = str_replace("'", "''", $e[0]);
        $c = str_replace("'", "''", $e[1]);
        $tipo = str_replace("'", "''", $e[2]);
        $filas[] = $i === 0
            ? "        SELECT '$t' AS tabla, '$c' AS columna, '$tipo' AS tipo"
            : "        UNION ALL SELECT '$t', '$c', '$tipo'";
    }
    return implode("\n", $filas);
};

$L("-- Lo que falta o no coincide. VACÍO = la base está como tiene que estar.");
$L("SELECT e.tabla, e.columna, e.tipo AS esperado,");
$L("       COALESCE(c.COLUMN_TYPE, '(no existe)') AS encontrado");
$L("FROM (");
$L($derivada());
$L(") e");
$L("LEFT JOIN information_schema.COLUMNS c");
$L("       ON c.TABLE_SCHEMA = DATABASE()");
$L("      AND c.TABLE_NAME   = e.tabla");
$L("      AND c.COLUMN_NAME  = e.columna");
$L("WHERE c.COLUMN_TYPE IS NULL OR c.COLUMN_TYPE <> e.tipo");
$L("ORDER BY e.tabla, e.columna;");
$L();
$L("-- Columnas de más: están en la base pero no en el esquema esperado.");
$L("-- No se borran —pueden ser de algo que todavía no está en el repo—");
$L("-- pero conviene saber que están.");
$L("SELECT c.TABLE_NAME AS tabla, c.COLUMN_NAME AS columna, c.COLUMN_TYPE AS tipo");
$L("FROM information_schema.COLUMNS c");
$L("JOIN information_schema.TABLES t");
$L("       ON t.TABLE_SCHEMA = c.TABLE_SCHEMA AND t.TABLE_NAME = c.TABLE_NAME");
$L("      AND t.TABLE_TYPE = 'BASE TABLE'");
$L("LEFT JOIN (");
$L($derivada());
$L(") e ON e.tabla = c.TABLE_NAME AND e.columna = c.COLUMN_NAME");
$L("WHERE c.TABLE_SCHEMA = DATABASE()");
$L("  AND c.TABLE_NAME NOT LIKE '\\_%'");
$L("  AND e.columna IS NULL");
$L("ORDER BY c.TABLE_NAME, c.COLUMN_NAME;");
$L();
$L("-- Colación: tiene que volver VACÍA. Una tabla con colación distinta hace");
$L("-- que un JOIN por texto contra otra tabla aborte con 1267 Illegal mix of");
$L("-- collations. Ya tiró el tablero una vez.");
$L("--");
$L("-- OJO: esto lo DETECTA pero no lo arregla. Convertir una tabla que ya");
$L("-- existe reescribe sus índices, que es una operación bastante más pesada");
$L("-- que todo lo demás de este archivo, y no corresponde meterla acá de");
$L("-- prepo. Si devuelve filas, correr normalize_collation_utf8mb4_unicode_ci.sql");
$L("SELECT TABLE_NAME AS tabla, TABLE_COLLATION AS colacion");
$L("FROM information_schema.TABLES");
$L("WHERE TABLE_SCHEMA = DATABASE() AND TABLE_TYPE = 'BASE TABLE'");
$L("  AND TABLE_NAME NOT LIKE '\\_%'");
$L("  AND TABLE_COLLATION <> 'utf8mb4_unicode_ci'");
$L("ORDER BY TABLE_NAME;");

$verif = array_slice($o, $inicioVerif);

$destino = __DIR__ . '/esquema_completo.sql';
file_put_contents($destino, implode("\n", $o) . "\n");

/* ─── Diagnóstico de sólo lectura ──────────────────────────────────────────
   Mismo bloque de verificación, sin nada que modifique el esquema. Sirve para
   mirar una base ajena —producción— antes de decidir si hace falta tocarla. */
$d = [];
$D = function ($s = '') use (&$d) { $d[] = $s; };

$D("-- ═══════════════════════════════════════════════════════════════");
$D("-- AgroPlanner · Diagnóstico de esquema (SÓLO LECTURA)");
$D("-- ═══════════════════════════════════════════════════════════════");
$D("-- Dice qué le falta a esta base respecto del esquema esperado.");
$D("--");
$D("-- NO ESCRIBE NADA. Ni una tabla temporal: son todas consultas. Se puede");
$D("-- correr en producción sin backup y sin consecuencias.");
$D("--");
$D("-- ANTES DE CORRERLO: en el panel de la izquierda de phpMyAdmin hacé clic");
$D("-- en tu base (u515247413_agroplanner o como se llame), de modo que el");
$D("-- encabezado la muestre seleccionada. Si se corre sin base elegida,");
$D("-- phpMyAdmin queda parado en information_schema y todo devuelve vacío o");
$D("-- da error de permisos — que no es que falte algo, es que está mirando");
$D("-- la base equivocada. La primera consulta te avisa si pasa eso.");
$D("--");
$D("-- Correrlo ANTES que esquema_completo.sql. Si todo vuelve vacío, la");
$D("-- base ya está al día y no hace falta correr nada más.");
$D("-- ═══════════════════════════════════════════════════════════════");
$D();
$D("-- Portón: tiene que decir OK y mostrar TU base, no information_schema.");
$D("SELECT VERSION() AS motor,");
$D("       COALESCE(DATABASE(), '(ninguna)') AS base_seleccionada,");
$D("       IF(DATABASE() IS NULL OR DATABASE() IN ('information_schema','mysql','performance_schema'),");
$D("          'PARA: elegi tu base en el panel izquierdo y corre esto de nuevo',");
$D("          'OK, segui') AS estado;");
$D();
$D("-- Cuántas de las " . count($tablas) . " tablas esperadas existen.");
$D("SELECT COUNT(*) AS tablas_presentes, " . count($tablas) . " AS tablas_esperadas");
$D("FROM information_schema.TABLES");
$D("WHERE TABLE_SCHEMA = DATABASE() AND TABLE_TYPE = 'BASE TABLE'");
$D("  AND TABLE_NAME IN (" . implode(', ', array_map(fn($t) => "'$t'", $tablas)) . ");");
$D();
$D("-- Cuáles faltan, por nombre.");
foreach (array_chunk($tablas, 30) as $lote) {
    $D("SELECT t.nombre AS tabla_faltante FROM (");
    $D('    ' . implode("\n    UNION ALL ", array_map(
        fn($t) => "SELECT '$t' AS nombre", $lote)));
    $D(") t");
    $D("LEFT JOIN information_schema.TABLES x");
    $D("       ON x.TABLE_SCHEMA = DATABASE() AND x.TABLE_NAME = t.nombre");
    $D("WHERE x.TABLE_NAME IS NULL;");
    $D();
}
$d = array_merge($d, $verif);

$destinoDiag = __DIR__ . '/diagnostico_esquema.sql';
file_put_contents($destinoDiag, implode("\n", $d) . "\n");

echo "generado: " . count($tablas) . " tablas, $total columnas afirmadas, "
   . count($esperado) . " columnas verificadas\n";
echo "  $destino\n";
echo "  $destinoDiag\n";
