ALTER TABLE socios 
ADD COLUMN tipo_documento ENUM('dni', 'libreta_civica', 'libreta_enrolamiento', 'pasaporte') 
NOT NULL DEFAULT 'dni' 
AFTER numero_socio;
