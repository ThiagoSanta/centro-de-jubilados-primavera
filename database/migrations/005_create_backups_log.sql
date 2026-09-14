CREATE TABLE IF NOT EXISTS backups_log (
    id         CHAR(36)  NOT NULL,
    usuario_id CHAR(36)  NULL,
    fecha_hora DATETIME  NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    CONSTRAINT fk_backups_log_usuario
        FOREIGN KEY (usuario_id) REFERENCES usuarios (id)
        ON DELETE SET NULL
);
