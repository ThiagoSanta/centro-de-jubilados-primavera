# API Reference — Centro de Jubilados Primavera

> **Base URL:** `http://localhost/centro-de-jubilados-primavera/public`  
> **Formato:** JSON (UTF-8)  
> **Autenticación:** Sesión PHP (cookie `PHPSESSID`)  
> **Preflight:** Las peticiones `OPTIONS` devuelven `200 OK` con las cabeceras CORS necesarias.

---

## Respuesta estándar

**Éxito:**
```json
{ "success": true, "data": { ... } }
```

**Error:**
```json
{ "success": false, "message": "Descripción del error" }
```

---

## Módulo: Autenticación (`/api/auth`)

### `POST /api/auth/login`
Inicia sesión y crea una sesión PHP.

**Body:**
```json
{ "usuario": "admin", "contrasena": "Admin1234!" }
```

**Respuesta exitosa:**
```json
{ "success": true, "data": { "id": "...", "nombre": "...", "rol": "administrador" } }
```

**Errores:** 401 credenciales incorrectas · 429 usuario bloqueado

---

### `POST /api/auth/logout`
Destruye la sesión activa.

**Respuesta:** `{ "success": true }`

---

### `GET /api/auth/me`
Retorna los datos del usuario autenticado en sesión.

**Requiere:** sesión válida

**Respuesta:** `{ "success": true, "data": { ... } }`

---

## Módulo: Dashboard (`/api/dashboard`)

### `GET /api/dashboard/metricas`
Retorna métricas del sistema en tiempo real.

**Requiere:** rol `administrador`

**Respuesta:**
```json
{
  "success": true,
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

**Query params:** `busqueda`, `estado`, `zona_id`, `modalidad_cobro`, `con_deuda`, `pagina`

**Respuesta:** `{ "success": true, "data": [...], "meta": { "total", "paginas", "pagina_actual" } }`

---

### `POST /api/socios`
Crea un nuevo socio.

**Requiere:** rol `administrador`

**Body:** datos del socio (nombre, apellido, dni, dirección, zona, modalidad, etc.)

---

### `POST /api/socios/importar`
Importa socios desde un archivo CSV.

**Requiere:** rol `administrador`

**Body:** `multipart/form-data` con campo `archivo`

---

### `GET /api/socios/{id}`
Retorna el perfil completo de un socio.

**Requiere:** autenticación

---

### `PUT /api/socios/{id}`
Actualiza los datos de un socio.

**Requiere:** rol `administrador`

---

### `DELETE /api/socios/{id}`
Elimina lógicamente un socio (estado = 'eliminado').

**Requiere:** rol `administrador`

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
Revierte la eliminación lógica de un socio.

**Requiere:** rol `administrador`

---

### `POST /api/socios/{id}/geolocalizacion`
Corrige las coordenadas geográficas de un socio.

**Requiere:** autenticación

**Body:** `{ "lat": -34.6037, "lng": -58.3816 }`

---

### `GET /api/socios/{id}/qr`
Retorna el código QR del socio en formato PNG.

**Requiere:** autenticación

---

## Módulo: Zonas (`/api/zonas`)

### `GET /api/zonas`
Lista todas las zonas disponibles.

**Requiere:** autenticación

---

### `POST /api/zonas/calcular`
Calcula a qué zona corresponde una dirección o coordenadas.

**Requiere:** autenticación

---

## Módulo: Deudas y Cuotas (`/api/deuda`, `/api/cuota`)

### `GET /api/cuota/vigente`
Retorna la cuota vigente del período actual.

**Requiere:** autenticación

---

### `GET /api/cuota/historico`
Retorna el histórico de cuotas por período.

**Requiere:** autenticación

---

### `POST /api/cuota`
Registra o actualiza el valor de la cuota para un período.

**Requiere:** rol `administrador`

**Body:** `{ "periodo": "2025-07", "monto": 1500 }`

---

### `POST /api/deuda/generar`
Genera las deudas del mes en curso para todos los socios activos.

**Requiere:** rol `administrador`

---

### `POST /api/deuda/anterior`
Carga una deuda anterior (período anterior al sistema) para un socio.

**Requiere:** rol `administrador`

---

### `GET /api/deuda/socio/{socioId}/pendientes`
Lista las deudas pendientes de un socio ordenadas cronológicamente (cascada).

**Requiere:** autenticación

---

### `GET /api/deuda/socio/{socioId}`
Lista todas las deudas de un socio (todos los estados).

**Requiere:** autenticación

---

### `POST /api/deuda/{id}/exonerar`
Exonera una deuda pendiente de un socio.

**Requiere:** rol `administrador`

---

## Módulo: Pagos (`/api/pagos`)

### `GET /api/pagos`
Lista todos los pagos con filtros y paginación.

**Requiere:** autenticación

**Query params:** `socio_id`, `estado`, `fecha_desde`, `fecha_hasta`, `pagina`

---

### `POST /api/pagos`
Registra un pago para un socio (una o más deudas, respetando cascada).

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

**Respuesta:** incluye `comprobante_url` y `whatsapp_url`

---

### `GET /api/pagos/{id}`
Retorna el detalle de un pago.

**Requiere:** autenticación

---

### `GET /api/pagos/{id}/comprobante`
Descarga el comprobante PDF de un pago.

**Requiere:** autenticación

---

### `POST /api/pagos/{id}/anular`
Anula un pago registrado y revierte las deudas a pendiente.

**Requiere:** rol `administrador`

**Body:** `{ "motivo": "..." }`

---

### `GET /api/pagos/socio/{socioId}`
Lista todos los pagos de un socio.

**Requiere:** autenticación

---

## Módulo: Planillas (`/api/planillas`)

### `GET /api/planillas`
Lista todas las planillas generadas.

**Requiere:** autenticación

---

### `POST /api/planillas`
Genera una nueva planilla de cobro para un cobrador.

**Requiere:** rol `administrador`

**Body:** `{ "cobrador_id": "uuid", "zona_id": "uuid", "fecha": "2025-07-01" }`

---

### `GET /api/planillas/cobradores`
Lista los usuarios con rol cobrador disponibles para asignar planillas.

**Requiere:** autenticación

---

### `GET /api/planillas/{id}`
Retorna el detalle de una planilla.

**Requiere:** autenticación

---

### `GET /api/planillas/{id}/pdf`
Descarga la planilla en formato PDF.

**Requiere:** autenticación

---

## Módulo: Notificaciones (`/api/notificaciones`)

### `GET /api/notificaciones`
Lista todas las notificaciones.

**Requiere:** autenticación

**Query params:** `estado` (no_leida | leida | archivada)

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
Revierte una acción asociada a una notificación (dentro del período de reversión).

**Requiere:** autenticación

---

## Módulo: Auditoría (`/api/auditoria`)

### `GET /api/auditoria`
Lista todos los eventos de auditoría con paginación y filtros.

**Requiere:** autenticación

**Query params:** `usuario_id`, `accion`, `entidad_afectada`, `fecha_desde`, `fecha_hasta`, `pagina`, `limit`

---

### `GET /api/auditoria/{id}`
Retorna el detalle de un evento de auditoría.

**Requiere:** autenticación

---

## Módulo: Historial (`/api/historial`)

### `GET /api/historial/socio/{socioId}`
Retorna el historial cronológico de cambios de un socio.

**Requiere:** autenticación

---

## Módulo: Observaciones (`/api/observaciones`)

### `GET /api/observaciones/socio/{socioId}`
Retorna las observaciones de un socio.

**Requiere:** autenticación

---

### `POST /api/observaciones`
Agrega una observación a un socio.

**Requiere:** autenticación

**Body:** `{ "socio_id": "uuid", "texto": "..." }`

---

## Módulo: Usuarios (`/api/usuarios`)

> Todos los endpoints de este módulo requieren rol `administrador`.

### `GET /api/usuarios`
Lista todos los usuarios del sistema ordenados por apellido.

**Respuesta:** `{ "success": true, "data": [...] }`

---

### `POST /api/usuarios`
Crea un nuevo usuario. El rol no puede modificarse una vez creado.

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

## Endpoint de salud

### `GET /api/ping`
Verifica que la API está en línea.

**Requiere:** autenticación

**Respuesta:** `{ "success": true, "message": "CJP API funcionando" }`
