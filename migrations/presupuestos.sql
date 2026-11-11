-- migrations/presupuestos.sql
--
-- Pedidos de presupuesto de insumos (presupuestos.php): se arma el pedido, se
-- baja un Excel para mandar a los proveedores, se suben las respuestas y se
-- comparan. Ver includes/presupuestos.php.
--
-- Historial, como las otras sueltas: en producción se corre
-- esquema_completo.sql, que ya trae todo esto.

CREATE TABLE IF NOT EXISTS presupuesto_pedidos (
    id            INT(11) NOT NULL AUTO_INCREMENT,
    usuario_id    INT(11) NOT NULL,
    titulo        VARCHAR(150) NOT NULL,
    moneda        ENUM('ARS','USD') NOT NULL DEFAULT 'USD' COMMENT 'La que se le pide al proveedor',
    fecha_limite  DATE NULL DEFAULT NULL,
    notas         VARCHAR(500) NULL DEFAULT NULL,
    creado_en     TIMESTAMP NOT NULL DEFAULT current_timestamp(),
    PRIMARY KEY (id),
    KEY idx_pp_usuario (usuario_id),
    CONSTRAINT fk_pp_usuario FOREIGN KEY (usuario_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Los renglones. "orden" es el código que va en el Excel: con él se reconoce
-- cada renglón cuando el archivo vuelve, aunque el proveedor mueva las filas.
CREATE TABLE IF NOT EXISTS presupuesto_items (
    id           INT(11) NOT NULL AUTO_INCREMENT,
    pedido_id    INT(11) NOT NULL,
    orden        SMALLINT NOT NULL,
    insumo_id    INT(11) NULL DEFAULT NULL COMMENT 'Del catálogo, si salió de ahí',
    descripcion  VARCHAR(200) NOT NULL,
    cantidad     DECIMAL(14,3) NOT NULL,
    unidad       VARCHAR(30) NULL DEFAULT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uk_pp_item_orden (pedido_id, orden),
    CONSTRAINT fk_pp_items_pedido FOREIGN KEY (pedido_id) REFERENCES presupuesto_pedidos (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS presupuesto_ofertas (
    id              INT(11) NOT NULL AUTO_INCREMENT,
    pedido_id       INT(11) NOT NULL,
    proveedor       VARCHAR(150) NOT NULL,
    moneda          ENUM('ARS','USD') NOT NULL,
    condicion_pago  VARCHAR(150) NULL DEFAULT NULL,
    plazo_entrega   VARCHAR(100) NULL DEFAULT NULL,
    validez         VARCHAR(100) NULL DEFAULT NULL,
    origen          ENUM('excel','manual') NOT NULL DEFAULT 'excel',
    archivo         VARCHAR(255) NULL DEFAULT NULL COMMENT 'Nombre del archivo que se subió; el archivo no se guarda',
    creado_en       TIMESTAMP NOT NULL DEFAULT current_timestamp(),
    PRIMARY KEY (id),
    KEY idx_pp_ofertas_pedido (pedido_id),
    CONSTRAINT fk_pp_ofertas_pedido FOREIGN KEY (pedido_id) REFERENCES presupuesto_pedidos (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS presupuesto_precios (
    oferta_id    INT(11) NOT NULL,
    item_id      INT(11) NOT NULL,
    precio       DECIMAL(14,4) NOT NULL COMMENT 'Unitario, en la moneda de la oferta',
    marca        VARCHAR(150) NULL DEFAULT NULL,
    observacion  VARCHAR(255) NULL DEFAULT NULL,
    PRIMARY KEY (oferta_id, item_id),
    CONSTRAINT fk_pp_precios_oferta FOREIGN KEY (oferta_id) REFERENCES presupuesto_ofertas (id) ON DELETE CASCADE,
    CONSTRAINT fk_pp_precios_item   FOREIGN KEY (item_id)   REFERENCES presupuesto_items (id)   ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Para revertir:
--   DROP TABLE presupuesto_precios; DROP TABLE presupuesto_ofertas;
--   DROP TABLE presupuesto_items;   DROP TABLE presupuesto_pedidos;
