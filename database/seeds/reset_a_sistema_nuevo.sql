-- =============================================================
-- RESET A SISTEMA NUEVO — Centro de Jubilados Primavera
-- Fecha de ejecución: 2026-11-14
-- Propósito: Dejar la BD limpia como sistema nuevo.
--   - Elimina: socios, deudas, pagos, planillas, observaciones,
--              notificaciones, auditoría, backups_log,
--              importaciones CSV y cuotas previas.
--   - Conserva: usuarios (administradores y cobradores), zonas.
--   - Deja:    una configuración de cuota de $1000 para 2026-11.
-- =============================================================

SET FOREIGN_KEY_CHECKS = 0;

-- 1. Tablas intermedias y dependientes (sin referencias hacia afuera)
TRUNCATE TABLE `pago_deuda`;
TRUNCATE TABLE `planilla_socio`;

-- 2. Tablas que referencian a socios y pagos
TRUNCATE TABLE `pagos`;
TRUNCATE TABLE `deudas`;
TRUNCATE TABLE `observaciones`;
TRUNCATE TABLE `planillas`;

-- 3. Socios
TRUNCATE TABLE `socios`;

-- 4. Configuración de cuotas (historial completo borrado; se reinserta una sola)
TRUNCATE TABLE `configuracion_cuota`;

-- 5. Importaciones CSV e inconsistencias
TRUNCATE TABLE `importacion_inconsistencias`;
TRUNCATE TABLE `importaciones_csv`;

-- 6. Registros operativos del sistema
TRUNCATE TABLE `notificaciones`;
TRUNCATE TABLE `auditoria`;
TRUNCATE TABLE `backups_log`;

SET FOREIGN_KEY_CHECKS = 1;

-- =============================================================
-- CUOTA INICIAL: $1000 — Vigencia desde 2026-11-01
-- Usuario que la registra: admin (id fijo del seed original)
-- =============================================================
INSERT INTO `configuracion_cuota` (`id`, `monto`, `fecha_vigencia_desde`, `usuario_id`, `fecha_registro`)
VALUES (
    UUID(),
    1000.00,
    '2026-11-01',
    'aaaaaaaa-aaaa-aaaa-aaaa-aaaaaaaaaaaa',
    '2026-11-14 12:00:00'
);

-- Verificación rápida
SELECT 'Socios restantes:'      AS tabla, COUNT(*) AS total FROM socios
UNION ALL
SELECT 'Deudas restantes:',      COUNT(*) FROM deudas
UNION ALL
SELECT 'Pagos restantes:',       COUNT(*) FROM pagos
UNION ALL
SELECT 'Cuotas configuradas:',   COUNT(*) FROM configuracion_cuota
UNION ALL
SELECT 'Usuarios conservados:',  COUNT(*) FROM usuarios
UNION ALL
SELECT 'Zonas conservadas:',     COUNT(*) FROM zonas;
