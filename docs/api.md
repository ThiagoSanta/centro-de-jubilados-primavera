# API Reference — Centro de Jubilados Primavera

> **Base URL:** `http://localhost/centro-de-jubilados-primavera/public` (el host varía según el entorno de red: `localhost` o la dirección IP local de la computadora con XAMPP, ej. `http://192.168.1.X/centro-de-jubilados-primavera/public`)  
> **Formato:** JSON (UTF-8). Excepciones: comprobantes y planillas emiten binario `application/pdf`, y códigos QR emiten `image/png`.  
> **Autenticación:** Sesión PHP basada en cookies (`PHPSESSID`), configuradas con `HttpOnly`, `SameSite=Lax`, `lifetime=0` (al cerrar navegador). El tiempo de expiración por inactividad es de 30 minutos para rol cobrador y 8 horas para rol administrador.  
> **Origen:** La API opera bajo el mismo origen (`same-origin`), por lo que no requiere ni admite preflight CORS (`OPTIONS`). Peticiones `OPTIONS` devuelven `404 Not Found`.

---

## Respuesta estándar

Todas las respuestas JSON devuelven un formato estructurado y predecible:

**Éxito:**
```json
{
  "success": true,
  "message": "OK",
  "data": { ... }
}
```

**Error:**
```json
{
  "success": false,
  "message": "Descripción del error",
  "errors": null
}
```

> **Nota:** Ante excepciones no controladas a nivel global, la API incluye la cabecera HTTP `X-Correlation-ID` y el campo `"correlation_id"` en el cuerpo de error para trazabilidad en logs.

---

## Módulo: Autenticación (`/api/auth`)

### `POST /api/auth/login`
Inicia sesión y crea una sesión PHP.

**Body:**
```json
{ "username": "admin", "password": "Admin1234!" }
```

**Respuesta exitosa:**
```json
{
  "success": true,
  "message": "Inicio de sesión exitoso.",
  "data": {
    "id": "...",
    "username": "admin",
    "nombre": "...",
    "apellido": "...",
    "rol": "administrador",
    "estado": "activo",
    "ultimo_acceso": "..."
  }
}
```

**Errores:** 400 datos incompletos · 401 credenciales incorrectas · 429 usuario bloqueado (5 intentos fallidos) o IP bloqueada (15 intentos en 15 min)

---

### `POST /api/auth/logout`
Destruye la sesión activa e invalida la cookie de sesión.

**Respuesta:**
```json
{ "success": true, "message": "Sesión cerrada exitosamente.", "data": null }
```

---

### `GET /api/auth/me`
Retorna los datos del usuario autenticado en sesión.

**Requiere:** sesión válida (no expirada)

**Respuesta:**
```json
{
  "success": true,
  "message": "OK",
  "data": {
    "usuario_id": "...",
    "rol": "administrador",
    "nombre": "...",
    "apellido": "...",
    "ultimo_acceso": "..."
  }
}
```

---

## Módulo: Dashboard (`/api/dashboard`)

### `GET /api/dashboard/metricas`
Retorna métricas del sistema en tiempo real.

**Requiere:** rol `administrador`

**Respuesta:**
```json
{
  "success": true,
  "message": "Métricas del dashboard obtenidas correctamente.",
  "data": {
    "socios_activos": 120,
    "socios_con_deuda": 18,
    "monto_adeudado_total": 45600.00,
    "pagos_del_mes": 73,
    "cobranzas_hoy": 5,
    "notificaciones_sin_leer": 3
  }
}
```

---

## Módulo: Socios (`/api/socios`)

### `GET /api/socios`
Lista socios con paginación y filtros.

**Requiere:** autenticación

**Query params:** `busqueda`, `estado`, `zona_id`, `modalidad_cobranza`, `con_deuda`, `pagina`, `limit`

**Respuesta:**
```json
{
  "success": true,
  "message": "Socios obtenidos exitosamente.",
  "data": {
    "data": [ ... ],
    "total": 120,
    "pagina": 1,
    "paginas": 12
  }
}
```

---

### `POST /api/socios`
Crea un nuevo socio. Responde con HTTP 201 Created. Asigna automáticamente coordenadas/zona y genera código QR.

**Requiere:** rol `administrador`

**Body:**
```json
{
  "nombre_apellido": "Juan Pérez",
  "dni": "12345678",
  "tipo_documento": "dni",
  "fecha_nacimiento": "1955-05-15",
  "telefono": "3471123456",
  "direccion": "Calle Falsa 123",
  "modalidad_cobranza": "domicilio",
  "mutual": "PAMI"
}
```
> **Valores admitidos para `tipo_documento`:** `dni`, `libreta_civica`, `libreta_enrolamiento`, `pasaporte`.  
> **Valores admitidos para `modalidad_cobranza`:** `domicilio`, `sede`.

---

### `POST /api/socios/importar`
Inicia la importación asincrónica de socios desde un archivo CSV procesado en segundo plano por worker CLI. Responde con HTTP 202 Accepted.

**Requiere:** rol `administrador`

**Body:** `multipart/form-data` con campo `archivo` o `file` (archivo `.csv`, tamaño máximo 5 MB).

**Respuesta exitosa (HTTP 202):**
```json
{
  "success": true,
  "message": "Importación iniciada. Procesando en segundo plano.",
  "data": {
    "id": 1,
    "estado": "pendiente",
    "total_filas": 150,
    "archivo_original": "padron_socios.csv"
  }
}
```

---

### `GET /api/socios/importar/{id}/estado`
Consulta el estado y progreso en tiempo real de una importación CSV en ejecución o finalizada.

**Requiere:** rol `administrador`

**Respuesta:**
```json
{
  "success": true,
  "message": "OK",
  "data": {
    "id": 1,
    "estado": "procesando",
    "total_filas": 150,
    "filas_procesadas": 75,
    "filas_exitosas": 70,
    "filas_fallidas": 5,
    "porcentaje_progreso": 50,
    "errores": [ ... ]
  }
}
```

---

### `GET /api/socios/importar/activa`
Verifica si existe actualmente alguna tarea de importación masiva en estado `pendiente` o `procesando`.

**Requiere:** rol `administrador`

**Respuesta:** `{ "success": true, "data": { ... } }` o `{ "success": true, "data": null }`

---

### `GET /api/socios/inconsistencias`
Lista los registros de la bandeja de inconsistencias derivados de la importación masiva de socios.

**Requiere:** rol `administrador`

**Query params:** `estado` (`pendiente` | `resuelto`)

**Respuesta:** `{ "success": true, "data": [ ... ] }`

---

### `GET /api/socios/{id}`
Retorna el perfil completo de un socio con sus datos personales, zona asignada y estado de deuda.

**Requiere:** autenticación

---

### `PUT /api/socios/{id}`
Actualiza los datos de un socio. Si se modifica la dirección, recalcula automáticamente las coordenadas geográficas y reasigna la zona.

**Requiere:** rol `administrador`

---

### `DELETE /api/socios/{id}`
Elimina lógicamente un socio (`estado = 'eliminado'`) registrando el motivo. Abre una ventana temporal de 7 días para eventual reversión.

**Requiere:** rol `administrador`

**Body:**
```json
{ "motivo": "Fallecimiento o solicitud de baja voluntaria" }
```

---

### `POST /api/socios/{id}/suspender`
Suspende un socio activo.

**Requiere:** rol `administrador`

---

### `POST /api/socios/{id}/reactivar`
Reactiva un socio suspendido o eliminado.

**Requiere:** rol `administrador`

---

### `POST /api/socios/{id}/revertir`
Revierte la eliminación lógica de un socio, restaurando su estado a `activo` (solo permitido dentro de los 7 días posteriores a la baja).

**Requiere:** rol `administrador`

---

### `POST /api/socios/{id}/geolocalizacion`
Corrige las coordenadas geográficas de un socio y recalcula su zona asignada.

**Requiere:** rol `administrador`

**Body:** `{ "lat": -32.8203, "lng": -61.4031 }`

---

### `GET /api/socios/{id}/qr`
Retorna el código QR del socio en formato PNG (stream binario con cabecera `Content-Type: image/png`). Si no existe en almacenamiento, lo genera bajo demanda.

**Requiere:** autenticación

---

## Módulo: Zonas (`/api/zonas`)

### `GET /api/zonas`
Lista todas las zonas disponibles con sus polígonos y datos de cobertura.

**Requiere:** autenticación

---

### `POST /api/zonas/calcular`
Calcula a qué zona corresponde un punto geográfico a partir de coordenadas numéricas (no procesa cadenas de texto de dirección directamente).

**Requiere:** autenticación

**Body:**
```json
{ "lat": -32.8203, "lng": -61.4031 }
```

**Respuesta:**
```json
{
  "success": true,
  "data": {
    "zona_id": "...",
    "nombre": "Zona Centro",
    "lat": -32.8203,
    "lng": -61.4031
  }
}
```

---

## Módulo: Deudas y Cuotas (`/api/deuda`, `/api/cuota`)

### `GET /api/cuota/vigente`
Retorna la cuota social vigente del período actual.

**Requiere:** autenticación

**Respuesta:**
```json
{
  "success": true,
  "data": {
    "id": 1,
    "monto": 1500.00,
    "fecha_vigencia_desde": "2025-07-01",
    "usuario_id": "...",
    "usuario_nombre": "Admin",
    "fecha_registro": "..."
  }
}
```

---

### `GET /api/cuota/historico`
Retorna el histórico de cuotas ordenadas cronológicamente.

**Requiere:** autenticación

---

### `POST /api/cuota`
Registra un nuevo valor de cuota social con fecha de vigencia.

**Requiere:** rol `administrador`

**Body:**
```json
{ "monto": 1500.00, "fecha_vigencia_desde": "2025-07-01" }
```

---

### `POST /api/deuda/generar`
Genera las deudas del período especificado para todos los socios activos.

**Requiere:** rol `administrador`

**Body:**
```json
{
  "periodo": "2025-07",
  "confirmar_duplicado": false
}
```
> Si ya existen deudas generadas para el período y `confirmar_duplicado` es `false`, la API responde advirtiendo los socios que ya poseen cuota generada antes de proceder.

---

### `POST /api/deuda/anterior`
Carga una deuda anterior al inicio del sistema para un socio.

**Requiere:** rol `administrador`

**Body:**
```json
{
  "socio_id": "uuid",
  "monto": 4500.00
}
```

---

### `GET /api/deuda/socio/{socioId}/pendientes`
Lista las deudas pendientes de un socio ordenadas cronológicamente (regla estricta de cascada de cobro).

**Requiere:** autenticación

---

### `GET /api/deuda/socio/{socioId}`
Lista todas las deudas de un socio (pendientes, pagadas y exoneradas).

**Requiere:** autenticación

---

### `POST /api/deuda/{id}/exonerar`
Exonera una deuda pendiente de un socio y registra la justificación en la auditoría.

**Requiere:** rol `administrador`

**Body:**
```json
{ "motivo": "Justificación administrativa de la exoneración" }
```

---

## Módulo: Pagos (`/api/pagos`)

### `GET /api/pagos`
Lista todos los pagos con filtros y paginación.

**Requiere:** autenticación

**Query params:** `socio_id`, `estado`, `fecha_desde`, `fecha_hasta`, `pagina`

**Respuesta:**
```json
{
  "success": true,
  "message": "Pagos obtenidos exitosamente.",
  "data": {
    "items": [ ... ],
    "meta": {
      "total": 150,
      "paginas": 15,
      "pagina_actual": 1
    }
  }
}
```

---

### `POST /api/pagos`
Registra un cobro a un socio cancelando una o más cuotas adeudadas bajo regla estricta de cascada cronológica.

**Requiere:** autenticación

**Body:**
```json
{
  "socio_id": "uuid",
  "deuda_ids": ["uuid1", "uuid2"],
  "metodo_pago": "efectivo",
  "observacion": ""
}
```

**Respuesta:**
```json
{
  "success": true,
  "message": "Pago registrado exitosamente.",
  "data": {
    "pago_id": "...",
    "comprobante_url": "api/pagos/.../comprobante",
    "whatsapp_url": "https://api.whatsapp.com/send?phone=..."
  }
}
```

---

### `GET /api/pagos/{id}`
Retorna el detalle completo de un pago y los períodos de deuda cancelados.

**Requiere:** autenticación

---

### `GET /api/pagos/{id}/comprobante`
Descarga o visualiza el comprobante en formato PDF (`Content-Type: application/pdf`).

**Requiere:** autenticación

---

### `POST /api/pagos/{id}/anular`
Anula un pago registrado, revierte las deudas asociadas a estado `pendiente` y registra el motivo en auditoría.

**Requiere:** rol `administrador`

**Body:** `{ "motivo": "Error de carga en sede" }`

---

### `GET /api/pagos/socio/{socioId}`
Lista todos los pagos históricos registrados para un socio.

**Requiere:** autenticación

---

## Módulo: Planillas (`/api/planillas`)

### `GET /api/planillas`
Lista todas las planillas generadas.

**Requiere:** autenticación

**Query params:** `zona_id`, `cobrador_id`, `fecha_desde`, `fecha_hasta`, `page`

> **Regla de acceso por rol:** Si el usuario autenticado tiene rol `cobrador`, el sistema fuerza automáticamente el filtro `cobrador_id` con el identificador del usuario en sesión, impidiendo listar planillas asignadas a otros cobradores.

---

### `POST /api/planillas`
Genera una nueva planilla de cobro para un cobrador y zona geográfica con fecha actual (`NOW()`). Responde con HTTP 201 Created.

**Requiere:** rol `administrador`

**Body:**
```json
{ "cobrador_id": "uuid", "zona_id": "uuid" }
```

---

### `GET /api/planillas/cobradores`
Lista los usuarios con rol cobrador activos disponibles para asignación de planillas.

**Requiere:** autenticación

---

### `GET /api/planillas/{id}`
Retorna el detalle de una planilla con su nómina de socios a cobrar.

**Requiere:** autenticación

> **Regla de acceso por rol:** Si el usuario autenticado tiene rol `cobrador`, solo puede acceder si la planilla le pertenece. De lo contrario, devuelve `403 Forbidden` ("No tienes permiso para acceder a esta planilla.").

---

### `GET /api/planillas/{id}/pdf`
Genera y visualiza la planilla de cobro en formato PDF (`Content-Type: application/pdf`, `Content-Disposition: inline`).

**Requiere:** autenticación

> **Regla de acceso por rol:** Aplica la misma restricción que el detalle (error `403 Forbidden` si un cobrador intenta acceder a una planilla ajena).

---

## Módulo: Notificaciones (`/api/notificaciones`)

### `GET /api/notificaciones`
Lista todas las notificaciones del sistema.

**Requiere:** autenticación

**Query params:** `estado` (`no_leida` | `leida` | `archivada`)

---

### `POST /api/notificaciones/{id}/leida`
Marca una notificación como leída.

**Requiere:** autenticación

---

### `POST /api/notificaciones/{id}/archivar`
Archiva una notificación.

**Requiere:** autenticación

---

### `POST /api/notificaciones/{id}/revertir`
Revierte la acción asociada a una notificación dentro de la ventana de reversión habilitada.

**Requiere:** rol `administrador`

---

## Módulo: Auditoría (`/api/auditoria`)

### `GET /api/auditoria`
Lista todos los eventos de auditoría con paginación y filtros.

**Requiere:** rol `administrador`

**Query params:** `usuario_id`, `accion`, `entidad_afectada`, `fecha_desde`, `fecha_hasta`, `pagina`, `limit`

**Respuesta:**
```json
{
  "success": true,
  "message": "OK",
  "data": {
    "items": [ ... ],
    "meta": {
      "total": 350,
      "paginas": 35
    }
  }
}
```

---

### `GET /api/auditoria/{id}`
Retorna el detalle completo de un evento de auditoría específico.

**Requiere:** rol `administrador`

---

## Módulo: Historial (`/api/historial`)

### `GET /api/historial/socio/{socioId}`
Retorna el historial cronológico de cambios de un socio.

**Requiere:** autenticación

---

## Módulo: Observaciones (`/api/observaciones`)

### `GET /api/observaciones/socio/{socioId}`
Retorna las observaciones y notas administrativas registradas sobre un socio.

**Requiere:** autenticación

---

### `POST /api/observaciones`
Agrega una observación a un socio. Responde con HTTP 201 Created.

**Requiere:** autenticación

**Body:**
```json
{
  "socio_id": "uuid",
  "contenido": "Nota o comentario sobre el socio (máximo 1000 caracteres)."
}
```

---

## Módulo: Usuarios (`/api/usuarios`)

> Todos los endpoints de este módulo requieren rol `administrador`.

### `GET /api/usuarios`
Lista todos los usuarios del sistema ordenados por apellido.

**Respuesta:** `{ "success": true, "data": [...] }`

---

### `POST /api/usuarios`
Crea un nuevo usuario en el sistema. Responde con HTTP 201 Created. El rol no puede modificarse una vez creado.

**Body:**
```json
{
  "nombre": "Juan",
  "apellido": "Pérez",
  "usuario": "jperez",
  "contrasena": "MiClave123",
  "rol": "cobrador"
}
```

**Reglas:** contraseña mínimo 8 caracteres · username único · rol: `administrador` | `cobrador`

---

### `GET /api/usuarios/{id}`
Retorna el detalle de un usuario.

---

### `PUT /api/usuarios/{id}`
Actualiza nombre y apellido de un usuario. El rol y el username son inmutables.

**Body:** `{ "nombre": "...", "apellido": "..." }`

---

### `POST /api/usuarios/{id}/password`
Actualiza la contraseña de un usuario.

**Body:** `{ "nueva_password": "NuevaClave123" }`

**Regla:** mínimo 8 caracteres

---

### `POST /api/usuarios/{id}/desactivar`
Desactiva un usuario. No es posible desactivarse a sí mismo.

---

### `POST /api/usuarios/{id}/reactivar`
Reactiva un usuario previamente desactivado.

---

## Módulo: Backup (`/api/backup`)

> Todos los endpoints de este módulo requieren rol `administrador`.

### `POST /api/backup/generar`
Genera una copia de seguridad completa (dump SQL) de la base de datos (estructura y datos de todas las tablas) y la descarga como archivo adjunto (`Content-Type: application/octet-stream`, `Content-Disposition: attachment; filename="backup_YYYY-MM-DD_HH-i-s.sql"`). Registra el evento en la auditoría y en el registro de copias de seguridad.

**Requiere:** rol `administrador`

---

### `GET /api/backup/ultimo`
Retorna información sobre la última copia de seguridad generada por el sistema.

**Requiere:** rol `administrador`

**Respuesta:**
```json
{
  "success": true,
  "message": "OK",
  "data": {
    "fecha_hora": "2025-07-01 10:30:00",
    "usuario_id": "uuid",
    "usuario_nombre": "Administrador",
    "dias_transcurridos": 2
  }
}
```
> Si nunca se generó un backup en el sistema, `data` retorna `null`.

---

## Endpoint de salud

### `GET /api/ping`
Verifica que la API está en línea.

**Requiere:** autenticación

**Respuesta:** `{ "success": true, "message": "CJP API funcionando" }`
