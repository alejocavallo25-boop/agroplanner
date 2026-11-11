-- migrations/cuenta_corriente.sql
--
-- Cuenta corriente con proveedores (cuenta_corriente.php). Ver
-- includes/cuenta_corriente.php.
--
-- Historial, como las otras sueltas: en producción se corre
-- esquema_completo.sql, que ya trae todo esto.

CREATE TABLE IF NOT EXISTS proveedores (
    id          INT(11) NOT NULL AUTO_INCREMENT,
    usuario_id  INT(11) NOT NULL,
    nombre      VARCHAR(150) NOT NULL,
    cuit        VARCHAR(20) NULL DEFAULT NULL,
    telefono    VARCHAR(50) NULL DEFAULT NULL,
    notas       VARCHAR(255) NULL DEFAULT NULL,
    creado_en   TIMESTAMP NOT NULL DEFAULT current_timestamp(),
    PRIMARY KEY (id),
    UNIQUE KEY uk_proveedor_nombre (usuario_id, nombre),
    CONSTRAINT fk_proveedores_usuario FOREIGN KEY (usuario_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Cada compra suma deuda; cada pago o nota de crédito la baja. El monto va
-- siempre positivo y en la moneda de la cuenta: el signo lo da el tipo.
CREATE TABLE IF NOT EXISTS cc_movimientos (
    id              INT(11) NOT NULL AUTO_INCREMENT,
    usuario_id      INT(11) NOT NULL,
    proveedor_id    INT(11) NOT NULL,
    tipo            ENUM('compra','pago','nota_credito') NOT NULL,
    fecha           DATE NOT NULL,
    vencimiento     DATE NULL DEFAULT NULL COMMENT 'Sólo compras',
    comprobante     VARCHAR(60) NULL DEFAULT NULL,
    concepto        VARCHAR(255) NULL DEFAULT NULL,
    moneda          ENUM('ARS','USD') NOT NULL,
    monto           DECIMAL(14,2) NOT NULL COMMENT 'Positivo, en la moneda de la cuenta',
    forma_pago      VARCHAR(30) NULL DEFAULT NULL COMMENT 'Pagos: transferencia, efectivo, cheque... Compras: canje',
    pagado_moneda   ENUM('ARS','USD') NULL DEFAULT NULL COMMENT 'Pago hecho en la otra moneda',
    pagado_monto    DECIMAL(14,2) NULL DEFAULT NULL,
    cotizacion      DECIMAL(14,4) NULL DEFAULT NULL,
    canje_cultivo   VARCHAR(60) NULL DEFAULT NULL,
    canje_toneladas DECIMAL(12,3) NULL DEFAULT NULL,
    tasa_mensual    DECIMAL(6,3) NULL DEFAULT NULL COMMENT 'Porcentaje mensual, informativo',
    creado_en       TIMESTAMP NOT NULL DEFAULT current_timestamp(),
    PRIMARY KEY (id),
    KEY idx_cc_usuario_proveedor (usuario_id, proveedor_id, fecha),
    CONSTRAINT fk_cc_movimientos_usuario   FOREIGN KEY (usuario_id)   REFERENCES users (id)       ON DELETE CASCADE,
    CONSTRAINT fk_cc_movimientos_proveedor FOREIGN KEY (proveedor_id) REFERENCES proveedores (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Para revertir:
--   DROP TABLE cc_movimientos;
--   DROP TABLE proveedores;
