<?php
// controllers/LotesController.php

class LotesController {
    private $pdo;
    private $usuario_id;

    public function __construct($pdo, $usuario_id) {
        $this->pdo = $pdo;
        $this->usuario_id = $usuario_id;
    }

    public function addLote($data) {
        $stmt = $this->pdo->prepare("INSERT INTO lotes (usuario_id, nombre, superficie, ubicacion, tipo_suelo, latitud, longitud, tenencia, costo_alquiler_tns_ha) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
        return $stmt->execute([
            $this->usuario_id,
            trim($data['nombre']),
            (float)($data['superficie'] ?? 0),
            trim($data['ubicacion'] ?? ''),
            trim($data['tipo_suelo'] ?? ''),
            !empty($data['latitud']) ? $data['latitud'] : null,
            !empty($data['longitud']) ? $data['longitud'] : null,
            $data['tenencia'],
            $data['tenencia'] === 'alquilado' ? (float)($data['costo_alquiler_tns_ha'] ?? 0) : 0
        ]);
    }

    public function editLote($data) {
        $stmt = $this->pdo->prepare("UPDATE lotes SET nombre = ?, superficie = ?, ubicacion = ?, tipo_suelo = ?, latitud = ?, longitud = ?, tenencia = ?, costo_alquiler_tns_ha = ? WHERE id = ? AND usuario_id = ?");
        return $stmt->execute([
            trim($data['nombre']),
            (float)($data['superficie'] ?? 0),
            trim($data['ubicacion'] ?? ''),
            trim($data['tipo_suelo'] ?? ''),
            !empty($data['latitud']) ? $data['latitud'] : null,
            !empty($data['longitud']) ? $data['longitud'] : null,
            $data['tenencia'],
            $data['tenencia'] === 'alquilado' ? (float)($data['costo_alquiler_tns_ha'] ?? 0) : 0,
            (int)$data['id'],
            $this->usuario_id
        ]);
    }

    public function deleteLote($id) {
        $id = (int)$id;
        $this->pdo->beginTransaction();
        try {
            // Superficie del lote: las operaciones guardan cantidades por hectárea,
            // así que el stock descontado fue cantidad_ha * superficie.
            $stmtSup = $this->pdo->prepare("SELECT superficie FROM lotes WHERE id = ? AND usuario_id = ?");
            $stmtSup->execute([$id, $this->usuario_id]);
            $sup = (float)($stmtSup->fetchColumn() ?: 0);

            // Restituir el stock que las operaciones de este lote habían descontado,
            // antes de que se borren en cascada y se pierda esa información.
            $stmtRestore = $this->pdo->prepare("UPDATE insumos SET stock_actual = stock_actual + ?, estado = 'activo' WHERE id = ? AND usuario_id = ?");

            // 1) Operaciones legacy de tipo 'insumo' con insumo_id directo
            $stmtOps = $this->pdo->prepare("SELECT insumo_id, cantidad_ha FROM operaciones WHERE lote_id = ? AND usuario_id = ? AND tipo_componente = 'insumo' AND insumo_id IS NOT NULL");
            $stmtOps->execute([$id, $this->usuario_id]);
            foreach ($stmtOps->fetchAll() as $op) {
                $stmtRestore->execute([(float)$op['cantidad_ha'] * $sup, $op['insumo_id'], $this->usuario_id]);
            }

            // 2) Insumos hijos (recetas / multi-insumo) en operacion_insumos
            $stmtHijos = $this->pdo->prepare("
                SELECT oi.insumo_id, oi.cantidad_ha
                FROM operacion_insumos oi
                JOIN operaciones o ON oi.operacion_id = o.id
                WHERE o.lote_id = ? AND o.usuario_id = ? AND oi.insumo_id IS NOT NULL
            ");
            $stmtHijos->execute([$id, $this->usuario_id]);
            foreach ($stmtHijos->fetchAll() as $h) {
                $stmtRestore->execute([(float)$h['cantidad_ha'] * $sup, $h['insumo_id'], $this->usuario_id]);
            }

            // Limpiar alquileres del lote de forma explícita (lote_id no tiene FK
            // garantizada hacia lotes; así evitamos errores y filas huérfanas).
            $this->pdo->prepare("DELETE FROM alquileres WHERE lote_id = ? AND usuario_id = ?")
                      ->execute([$id, $this->usuario_id]);

            // Borrar el lote. operaciones, cultivos y produccion_ventas caen por cascada FK.
            $this->pdo->prepare("DELETE FROM lotes WHERE id = ? AND usuario_id = ?")
                      ->execute([$id, $this->usuario_id]);

            $this->pdo->commit();
            return true;
        } catch (\Exception $e) {
            $this->pdo->rollBack();
            return false;
        }
    }

    public function getAllLotes() {
        $stmt = $this->pdo->prepare("SELECT * FROM lotes WHERE usuario_id = ? ORDER BY created_at DESC");
        $stmt->execute([$this->usuario_id]);
        return $stmt->fetchAll();
    }
}
