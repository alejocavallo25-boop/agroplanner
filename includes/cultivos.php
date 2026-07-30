<?php
/**
 * includes/cultivos.php
 * Helper de normalización del cultivo.
 *
 * cultivo_resolve(): devuelve el id de la fila canónica en `cultivos` que
 * corresponde a (usuario, lote, nombre, ciclo), creándola si no existe.
 * Es la ÚNICA puerta para asociar operaciones / ventas / campañas a un cultivo,
 * de modo que `cultivo_id` sea siempre la referencia real y el texto un snapshot.
 */

if (!function_exists('cultivo_resolve')) {
    /**
     * @return int|null  id del cultivo, o null si no hay cultivo identificable
     *                   (sin nombre o sin lote → se guarda cultivo_id NULL).
     */
    function cultivo_resolve(PDO $pdo, int $usuario_id, $lote_id, $nombre, $ciclo): ?int {
        $nombre  = trim((string)$nombre);
        $ciclo   = trim((string)$ciclo);
        $lote_id = (int)$lote_id;
        if ($nombre === '' || $lote_id <= 0) {
            return null;
        }

        // Insert-or-get atómico apoyado en el UNIQUE uk_cultivo (usuario,lote,nombre,ciclo).
        // Si ya existe, ON DUPLICATE KEY hace que LAST_INSERT_ID devuelva el id existente.
        $stmt = $pdo->prepare("
            INSERT INTO cultivos (usuario_id, lote_id, nombre, ciclo, estado, fecha_siembra)
            VALUES (?, ?, ?, ?, 'activo', CURDATE())
            ON DUPLICATE KEY UPDATE id = LAST_INSERT_ID(id)
        ");
        $stmt->execute([$usuario_id, $lote_id, $nombre, $ciclo]);
        return (int)$pdo->lastInsertId();
    }
}
