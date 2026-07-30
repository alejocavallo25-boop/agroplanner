<?php
/**
 * Runner idempotente para normalize_cultivos.sql
 * Verifica el estado actual antes de cada ALTER, así se puede correr varias veces.
 *   php migrations/run_normalize_cultivos.php
 */
require_once __DIR__ . '/../config/database.php';
$db = 'agro_planner';

function existeIndice($pdo,$db,$tabla,$indice){
    $s=$pdo->prepare("SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=? AND TABLE_NAME=? AND INDEX_NAME=?");
    $s->execute([$db,$tabla,$indice]); return $s->fetchColumn() > 0;
}
function reglaBorradoFK($pdo,$db,$tabla,$fk){
    $s=$pdo->prepare("SELECT DELETE_RULE FROM information_schema.REFERENTIAL_CONSTRAINTS WHERE CONSTRAINT_SCHEMA=? AND TABLE_NAME=? AND CONSTRAINT_NAME=?");
    $s->execute([$db,$tabla,$fk]); return $s->fetchColumn();
}

// (1) UNIQUE en cultivos
if (existeIndice($pdo,$db,'cultivos','uk_cultivo')) {
    echo "[=] cultivos.uk_cultivo ya existe, omito.\n";
} else {
    $pdo->exec("ALTER TABLE `cultivos` ADD UNIQUE KEY `uk_cultivo` (`usuario_id`,`lote_id`,`nombre`,`ciclo`)");
    echo "[+] cultivos.uk_cultivo creado.\n";
}

// (2) FK de ventas a SET NULL
$regla = reglaBorradoFK($pdo,$db,'produccion_ventas','produccion_ventas_ibfk_2');
if ($regla === 'SET NULL') {
    echo "[=] produccion_ventas_ibfk_2 ya es ON DELETE SET NULL, omito.\n";
} else {
    $pdo->exec("ALTER TABLE `produccion_ventas` DROP FOREIGN KEY `produccion_ventas_ibfk_2`");
    $pdo->exec("ALTER TABLE `produccion_ventas` ADD CONSTRAINT `produccion_ventas_ibfk_2` FOREIGN KEY (`cultivo_id`) REFERENCES `cultivos`(`id`) ON DELETE SET NULL");
    echo "[+] produccion_ventas_ibfk_2 cambiado de '$regla' a SET NULL.\n";
}

echo "Migración OK.\n";
