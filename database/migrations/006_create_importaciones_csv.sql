CREATE TABLE IF NOT EXISTS importaciones_csv (
    id CHAR(36) NOT NULL PRIMARY KEY,
    usuario_id CHAR(36) NOT NULL,
    archivo_original VARCHAR(255) NOT NULL,
    archivo_ruta VARCHAR(500) NOT NULL,
    estado ENUM('pendiente', 'procesando', 'completado', 'error', 'cancelado') NOT NULL DEFAULT 'pendiente',
    total_filas INT NOT NULL DEFAULT 0,
    filas_procesadas INT NOT NULL DEFAULT 0,
    exitosos INT NOT NULL DEFAULT 0,
    fallidos INT NOT NULL DEFAULT 0,
    error_mensaje TEXT NULL,
    pid INT NULL,
    fecha_inicio DATETIME NULL,
    fecha_fin DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_importaciones_usuario FOREIGN KEY (usuario_id) REFERENCES usuarios(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE importacion_inconsistencias 
    ADD COLUMN importacion_id CHAR(36) NULL AFTER id,
    ADD CONSTRAINT fk_inconsistencias_importacion 
        FOREIGN KEY (importacion_id) REFERENCES importaciones_csv(id) ON DELETE SET NULL;
