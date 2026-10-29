
/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;
/*!40103 SET @OLD_TIME_ZONE=@@TIME_ZONE */;
/*!40103 SET TIME_ZONE='+00:00' */;
/*!40014 SET @OLD_UNIQUE_CHECKS=@@UNIQUE_CHECKS, UNIQUE_CHECKS=0 */;
/*!40014 SET @OLD_FOREIGN_KEY_CHECKS=@@FOREIGN_KEY_CHECKS, FOREIGN_KEY_CHECKS=0 */;
/*!40101 SET @OLD_SQL_MODE=@@SQL_MODE, SQL_MODE='NO_AUTO_VALUE_ON_ZERO' */;
/*!40111 SET @OLD_SQL_NOTES=@@SQL_NOTES, SQL_NOTES=0 */;
DROP TABLE IF EXISTS `auditoria`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `auditoria` (
  `id` char(36) NOT NULL,
  `usuario_id` char(36) DEFAULT NULL COMMENT 'NULL en eventos de sistema sin usuario identificado',
  `accion` varchar(100) NOT NULL,
  `entidad_afectada` varchar(100) NOT NULL,
  `valor_anterior` text DEFAULT NULL,
  `valor_nuevo` text DEFAULT NULL,
  `fecha_hora` datetime NOT NULL DEFAULT current_timestamp(),
  `motivo` text DEFAULT NULL COMMENT 'Cuando corresponda según regla de negocio',
  PRIMARY KEY (`id`),
  KEY `idx_auditoria_usuario` (`usuario_id`),
  KEY `idx_auditoria_entidad` (`entidad_afectada`),
  KEY `idx_auditoria_fecha` (`fecha_hora`),
  CONSTRAINT `fk_aud_usuario` FOREIGN KEY (`usuario_id`) REFERENCES `usuarios` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `backups_log`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `backups_log` (
  `id` char(36) NOT NULL,
  `usuario_id` char(36) DEFAULT NULL,
  `fecha_hora` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `fk_backups_log_usuario` (`usuario_id`),
  CONSTRAINT `fk_backups_log_usuario` FOREIGN KEY (`usuario_id`) REFERENCES `usuarios` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `configuracion_cuota`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `configuracion_cuota` (
  `id` char(36) NOT NULL,
  `monto` decimal(10,2) NOT NULL,
  `fecha_vigencia_desde` date NOT NULL COMMENT 'El valor aplica desde esta fecha',
  `usuario_id` char(36) NOT NULL,
  `fecha_registro` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `fk_cuota_usuario` (`usuario_id`),
  CONSTRAINT `fk_cuota_usuario` FOREIGN KEY (`usuario_id`) REFERENCES `usuarios` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `deudas`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `deudas` (
  `id` char(36) NOT NULL,
  `socio_id` char(36) NOT NULL,
  `periodo` varchar(20) NOT NULL COMMENT 'Formato AAAA-MM o valor especial: deuda_anterior',
  `monto` decimal(10,2) NOT NULL,
  `estado` enum('pendiente','pagada','exonerada') NOT NULL DEFAULT 'pendiente',
  `fecha_generacion` datetime NOT NULL DEFAULT current_timestamp(),
  `fecha_pago` datetime DEFAULT NULL,
  `fecha_exoneracion` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_deudas_socio_periodo` (`socio_id`,`periodo`) COMMENT 'Impide deuda duplicada por socio y período',
  KEY `idx_deudas_socio` (`socio_id`),
  KEY `idx_deudas_estado` (`estado`),
  KEY `idx_deudas_periodo` (`periodo`),
  CONSTRAINT `fk_deudas_socio` FOREIGN KEY (`socio_id`) REFERENCES `socios` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `importacion_inconsistencias`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `importacion_inconsistencias` (
  `id` char(36) NOT NULL,
  `importacion_id` char(36) DEFAULT NULL,
  `datos_registro` text NOT NULL COMMENT 'Contenido del registro CSV rechazado',
  `motivo_rechazo` text NOT NULL,
  `estado` enum('pendiente','resuelto') NOT NULL DEFAULT 'pendiente',
  `fecha_importacion` datetime NOT NULL DEFAULT current_timestamp(),
  `usuario_resolucion` char(36) DEFAULT NULL,
  `fecha_resolucion` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `fk_ii_usuario` (`usuario_resolucion`),
  KEY `idx_ii_estado` (`estado`),
  KEY `fk_inconsistencias_importacion` (`importacion_id`),
  CONSTRAINT `fk_ii_usuario` FOREIGN KEY (`usuario_resolucion`) REFERENCES `usuarios` (`id`),
  CONSTRAINT `fk_inconsistencias_importacion` FOREIGN KEY (`importacion_id`) REFERENCES `importaciones_csv` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `importaciones_csv`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `importaciones_csv` (
  `id` char(36) NOT NULL,
  `usuario_id` char(36) NOT NULL,
  `archivo_original` varchar(255) NOT NULL,
  `archivo_ruta` varchar(500) NOT NULL,
  `estado` enum('pendiente','procesando','completado','error','cancelado') NOT NULL DEFAULT 'pendiente',
  `total_filas` int(11) NOT NULL DEFAULT 0,
  `filas_procesadas` int(11) NOT NULL DEFAULT 0,
  `exitosos` int(11) NOT NULL DEFAULT 0,
  `fallidos` int(11) NOT NULL DEFAULT 0,
  `error_mensaje` text DEFAULT NULL,
  `pid` int(11) DEFAULT NULL,
  `fecha_inicio` datetime DEFAULT NULL,
  `fecha_fin` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `fk_importaciones_usuario` (`usuario_id`),
  CONSTRAINT `fk_importaciones_usuario` FOREIGN KEY (`usuario_id`) REFERENCES `usuarios` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `notificaciones`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `notificaciones` (
  `id` char(36) NOT NULL,
  `tipo` varchar(100) NOT NULL,
  `mensaje` text NOT NULL,
  `fecha` datetime NOT NULL DEFAULT current_timestamp(),
  `estado` enum('no_leida','leida','archivada') NOT NULL DEFAULT 'no_leida',
  `referencia` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL COMMENT 'Formato mínimo: {"entidad":"<nombre>","id":"<uuid>"}' CHECK (json_valid(`referencia`)),
  `fecha_expiracion_reversion` datetime NOT NULL COMMENT '7 días desde fecha de generación',
  PRIMARY KEY (`id`),
  KEY `idx_notif_estado` (`estado`),
  KEY `idx_notif_fecha` (`fecha`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `observaciones`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `observaciones` (
  `id` char(36) NOT NULL,
  `socio_id` char(36) NOT NULL,
  `fecha` datetime NOT NULL DEFAULT current_timestamp(),
  `usuario_id` char(36) NOT NULL,
  `contenido` varchar(1000) NOT NULL COMMENT 'Texto plano, máx 1000 caracteres',
  PRIMARY KEY (`id`),
  KEY `fk_obs_usuario` (`usuario_id`),
  KEY `idx_observaciones_socio` (`socio_id`),
  CONSTRAINT `fk_obs_socio` FOREIGN KEY (`socio_id`) REFERENCES `socios` (`id`),
  CONSTRAINT `fk_obs_usuario` FOREIGN KEY (`usuario_id`) REFERENCES `usuarios` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `pago_deuda`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `pago_deuda` (
  `pago_id` char(36) NOT NULL,
  `deuda_id` char(36) NOT NULL,
  PRIMARY KEY (`pago_id`,`deuda_id`),
  UNIQUE KEY `uq_pago_deuda_deuda` (`deuda_id`) COMMENT 'Una deuda solo puede asociarse a un pago válido',
  CONSTRAINT `fk_pd_deuda` FOREIGN KEY (`deuda_id`) REFERENCES `deudas` (`id`),
  CONSTRAINT `fk_pd_pago` FOREIGN KEY (`pago_id`) REFERENCES `pagos` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `pagos`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `pagos` (
  `id` char(36) NOT NULL,
  `socio_id` char(36) NOT NULL,
  `fecha_hora` datetime NOT NULL DEFAULT current_timestamp(),
  `monto_total` decimal(10,2) NOT NULL,
  `metodo_pago` enum('efectivo','transferencia') NOT NULL,
  `estado` enum('registrado','anulado') NOT NULL DEFAULT 'registrado',
  `observacion` text DEFAULT NULL,
  `usuario_id` char(36) NOT NULL COMMENT 'Usuario que realizó el cobro',
  PRIMARY KEY (`id`),
  KEY `fk_pagos_usuario` (`usuario_id`),
  KEY `idx_pagos_socio` (`socio_id`),
  KEY `idx_pagos_estado` (`estado`),
  KEY `idx_pagos_fecha` (`fecha_hora`),
  CONSTRAINT `fk_pagos_socio` FOREIGN KEY (`socio_id`) REFERENCES `socios` (`id`),
  CONSTRAINT `fk_pagos_usuario` FOREIGN KEY (`usuario_id`) REFERENCES `usuarios` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `planilla_socio`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `planilla_socio` (
  `planilla_id` char(36) NOT NULL,
  `socio_id` char(36) NOT NULL,
  `orden` smallint(5) unsigned NOT NULL COMMENT 'Posición en el recorrido al momento de generación',
  `deuda_snapshot` decimal(10,2) NOT NULL COMMENT 'Deuda pendiente al momento de generación',
  PRIMARY KEY (`planilla_id`,`socio_id`),
  KEY `fk_ps_socio` (`socio_id`),
  CONSTRAINT `fk_ps_planilla` FOREIGN KEY (`planilla_id`) REFERENCES `planillas` (`id`),
  CONSTRAINT `fk_ps_socio` FOREIGN KEY (`socio_id`) REFERENCES `socios` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `planillas`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `planillas` (
  `id` char(36) NOT NULL,
  `fecha_generacion` datetime NOT NULL DEFAULT current_timestamp(),
  `cobrador_id` char(36) NOT NULL,
  `zona_id` char(36) NOT NULL,
  `pdf_generado` varchar(500) DEFAULT NULL COMMENT 'Ruta al archivo PDF generado',
  PRIMARY KEY (`id`),
  KEY `fk_plan_cobrador` (`cobrador_id`),
  KEY `fk_plan_zona` (`zona_id`),
  CONSTRAINT `fk_plan_cobrador` FOREIGN KEY (`cobrador_id`) REFERENCES `usuarios` (`id`),
  CONSTRAINT `fk_plan_zona` FOREIGN KEY (`zona_id`) REFERENCES `zonas` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `socios`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `socios` (
  `id` char(36) NOT NULL,
  `numero_socio` int(10) unsigned NOT NULL COMMENT 'Autogenerado, secuencial, no reutilizable',
  `tipo_documento` enum('dni','libreta_civica','libreta_enrolamiento','pasaporte') NOT NULL DEFAULT 'dni',
  `nombre_apellido` varchar(200) NOT NULL,
  `dni` varchar(20) NOT NULL COMMENT 'Editable solo por admin con doble confirmación',
  `fecha_nacimiento` date NOT NULL,
  `telefono` varchar(30) NOT NULL,
  `mutual` varchar(100) DEFAULT NULL,
  `direccion` varchar(255) DEFAULT NULL,
  `latitud` decimal(10,7) DEFAULT NULL,
  `longitud` decimal(10,7) DEFAULT NULL,
  `zona_id` char(36) DEFAULT NULL,
  `estado` enum('activo','suspendido','eliminado') NOT NULL DEFAULT 'activo',
  `motivo_baja` text DEFAULT NULL COMMENT 'Obligatorio si eliminado; nulo en otros estados',
  `fecha_baja` datetime DEFAULT NULL,
  `modalidad_cobranza` enum('cobranza_domiciliaria','cobranza_en_sede') NOT NULL,
  `geolocalizacion_pendiente` tinyint(1) NOT NULL DEFAULT 0 COMMENT 'Booleano interno; no es estado visible',
  `qr_url` varchar(500) DEFAULT NULL,
  `fecha_alta` datetime NOT NULL DEFAULT current_timestamp(),
  `fecha_actualizacion` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_socios_dni` (`dni`),
  UNIQUE KEY `uq_socios_numero_socio` (`numero_socio`),
  KEY `idx_socios_dni` (`dni`),
  KEY `idx_socios_estado` (`estado`),
  KEY `idx_socios_zona` (`zona_id`),
  KEY `idx_socios_modalidad` (`modalidad_cobranza`),
  KEY `idx_socios_geo_pendiente` (`geolocalizacion_pendiente`),
  CONSTRAINT `fk_socios_zona` FOREIGN KEY (`zona_id`) REFERENCES `zonas` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `usuarios`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `usuarios` (
  `id` char(36) NOT NULL,
  `nombre` varchar(100) NOT NULL,
  `apellido` varchar(100) NOT NULL,
  `usuario` varchar(50) NOT NULL,
  `contrasena_hash` varchar(255) NOT NULL,
  `rol` enum('administrador','cobrador') NOT NULL COMMENT 'No modificable una vez creado',
  `estado` enum('activo','desactivado') NOT NULL DEFAULT 'activo',
  `fecha_creacion` datetime NOT NULL DEFAULT current_timestamp(),
  `ultimo_acceso` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_usuarios_usuario` (`usuario`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `zonas`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `zonas` (
  `id` char(36) NOT NULL,
  `nombre` varchar(50) NOT NULL,
  `lat_limite_norte` decimal(10,7) NOT NULL COMMENT 'Latitud Ruta 9',
  `lat_limite_sur` decimal(10,7) NOT NULL COMMENT 'Latitud Bv. Balcarce',
  `lng_limite_vertical` decimal(10,7) NOT NULL COMMENT 'Longitud Calle Sarmiento',
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40103 SET TIME_ZONE=@OLD_TIME_ZONE */;

/*!40101 SET SQL_MODE=@OLD_SQL_MODE */;
/*!40014 SET FOREIGN_KEY_CHECKS=@OLD_FOREIGN_KEY_CHECKS */;
/*!40014 SET UNIQUE_CHECKS=@OLD_UNIQUE_CHECKS */;
/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
/*!40111 SET SQL_NOTES=@OLD_SQL_NOTES */;

