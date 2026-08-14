# Informe de Auditoría Técnica de Consistencia

**Proyecto:** Centro de Jubilados Primavera (`ThiagoSanta/centro-de-jubilados-primavera`)  
**Fecha:** 2026-08-06  
**Rol Auditor:** Auditor Técnico Senior  
**Tecnologías:** PHP 8.2 (sin framework, PDO nativo), JS Vanilla, Arquitectura Controller → Service → Repository  

---

## Resumen Ejecutivo

Se ha llevado a cabo una auditoría integral sobre el código fuente del proyecto para identificar inconsistencias en rutas, contratos de API, control de acceso, interfaz gráfica, estructura de archivos y código huérfano.

### Métricas Globales de Hallazgos
- **Hallazgos Totales:** 26
- **Distribución por Severidad:**
  - **CRÍTICA:** 1
  - **ALTA:** 6
  - **MEDIA:** 9
  - **BAJA:** 10

### Desglose por Categoría
| Categoría | Crítica | Alta | Media | Baja | Total |
| :--- | :---: | :---: | :---: | :---: | :---: |
| 1. Rutas y llamadas JS↔PHP | 0 | 1 | 2 | 0 | **3** |
| 2. Contrato de respuesta JSON | 0 | 2 | 3 | 1 | **6** |
| 3. Permisos y autenticación | 1 | 2 | 1 | 0 | **4** |
| 4. CSS y UI del sidebar | 0 | 0 | 1 | 2 | **3** |
| 5. Archivos fuera de lugar | 0 | 0 | 1 | 3 | **4** |
| 6. Código huérfano | 0 | 0 | 0 | 6 | **6** |

---

## 1. Rutas y llamadas JS↔PHP

| Archivo | Línea | Descripción del Hallazgo | Severidad |
| :--- | :--- | :--- | :--- |
| `public/views/planillas/generar.html` | L284 | **Descalce de parámetros entre JS y Controller:** La vista realiza `fetchJson` a `/api/socios?limit=10000...` esperando obtener todos los socios de una zona. Sin embargo, `SocioController::index()` ignora el parámetro `limit` y fuerza la paginación fija de 25 registros. Esto hace que la previsualización de planillas de ruteo incluya únicamente un máximo de 25 socios. | **ALTA** |
| `public/views/dashboard/index.html` | L424 | **Parámetro `limit` no soportado:** La vista realiza un `fetch` a `/api/auditoria?limit=5` para mostrar la actividad reciente en el dashboard. Sin embargo, `AuditoriaController::getAll()` no procesa el parámetro `limit` y aplica paginación por defecto, devolviendo más registros de los necesarios. | **MEDIA** |
| `public/views/socios/importar.html` | L176 | **URL/Endpoint engañoso en JavaScript:** Declaración de la variable `INCONSISTENCIAS_URL = BASE_URL + '/api/socios/importar'`, la cual apunta al endpoint de subida de CSV en lugar de una ruta de reporte de inconsistencias dedicada. | **MEDIA** |

---

## 2. Contrato de Respuesta JSON (success / data / message / errors)

El estándar definido en `app/shared/helpers/ResponseHelper.php` establece la siguiente estructura uniforme:
- **Éxito:** `{"success": true, "message": "...", "data": ...}`
- **Error:** `{"success": false, "message": "...", "errors": ...}`

| Archivo | Línea | Descripción del Hallazgo | Severidad |
| :--- | :--- | :--- | :--- |
| `app/modules/pagos/PagoController.php` | L23, L39, L50, L68, L84, L97 | **Estructura heterogénea en respuestas de pagos:** Omitida la clave `message` en `getBySocio` (L50) y `getOne` (L84). En `getAll` (L68) se añade un objeto `meta` en la raíz en lugar de integrarse en `data`. En `getComprobante` (L97) se emite error directo con `ResponseHelper::json` omitiendo la clave `errors`. | **ALTA** |
| `app/modules/deuda/DeudaController.php` | L27, L32, L48, L53, L68, L73, L86, L98, L112, L117, L129, L140 | **Infracción de contrato JSON y clave `error` no estándar:** En L27, L48, L68 y L112 responde `{"error": "..."}` en lugar de `{"success": false, "message": "...", "errors": null}`. En L32 usa la clave `resultado` en vez de `data`. En L117 expone `id` en la raíz de la respuesta. Casi todas las respuestas exitosas omiten la clave `message`. | **ALTA** |
| `app/modules/planillas/PlanillaController.php` | L24, L31, L51, L59, L86 | **Inconsistencias en formato de planillas:** En L24 emite error sin clave `errors`. En L51 fusiona directamente `['success' => true] + $resultado` exponiendo claves `total`, `paginas` y `data` en la raíz de la respuesta JSON. En L59 y L86 omite la propiedad `message`. | **MEDIA** |
| `app/modules/usuarios/UsuarioController.php` | L30, L50, L72, L96, L122, L144, L166 | **Uso de `ResponseHelper::json` manual omitiendo `message`:** Los métodos `getAll` (L30) y `getOne` (L50) utilizan `ResponseHelper::json` directamente en vez de `ResponseHelper::success()`, omitiendo el atributo `message` estandarizado. | **MEDIA** |
| `app/modules/auditoria/AuditoriaController.php` | L26 | **Respuesta con estructura custom de metadatos:** El método `getAll` emite `['success' => true, 'data' => ..., 'meta' => ...]`, introduciendo la clave `meta` fuera del estándar uniforme `(success, data, message, errors)`. | **MEDIA** |
| `app/modules/notificaciones/NotificacionController.php`, `HistorialController.php`, `ObservacionController.php`, `DashboardController.php` | Varios (L20, L17, L18, L88) | **Omisión de la clave `message` en respuestas exitosas:** Múltiples controladores utilizan `ResponseHelper::json(['success' => true, 'data' => ...])` en lugar del helper estandarizado `ResponseHelper::success()`, omitiendo la clave `message`. | **BAJA** |

---

## 3. Permisos y Autenticación

| Archivo | Línea | Descripción del Hallazgo | Severidad |
| :--- | :--- | :--- | :--- |
| `app/modules/pagos/PagoController.php` | L16, L30, L45, L56, L79, L90 | **Falta absoluta de control de sesión en módulo de Pagos:** Ningún método de `PagoController` (`registrar`, `anular`, `getBySocio`, `getAll`, `getOne`, `getComprobante`) invoca `AuthMiddleware::requireAuth()`. Cualquier petición HTTP externa no autenticada puede registrar pagos, anular cobranzas o consultar el listado completo de pagos de la institución. | **CRÍTICA** |
| `public/index.php` / Controladores Backend | Varias | **Ausencia de verificación de rol `administrador` en endpoints sensibles:** Rutas de mutación crítica como `POST /api/deuda/generar`, `POST /api/deuda/{id}/exonerar`, `POST /api/deuda`, `POST /api/planillas`, `POST /api/socios` ejecutan únicamente `AuthMiddleware::requireAuth()` sin validar si el usuario posee rol `administrador`, permitiendo a un usuario con rol `cobrador` ejecutar estas acciones si conoce el endpoint. | **ALTA** |
| `app/modules/auditoria/AuditoriaController.php` | L15, L30 | **Falta de restricción de rol en Logs de Auditoría:** `AuditoriaController` permite la consulta de registros de auditoría a cualquier usuario autenticado (`requireAuth()`) sin requerir rol `administrador`. | **ALTA** |
| `public/views/*.html` (14 vistas) | N/A | **Falta de verificación JS de sesión al cargar vistas estáticas:** Con excepción de `cobrador/index.html` y `pagos/cobro-sede.html`, ninguna de las vistas HTML del panel de administración (`dashboard/index.html`, `socios/padron.html`, `usuarios/index.html`, etc.) realiza la comprobación inicial por JS (`/api/auth/me`) al cargar la página. Si un usuario no autenticado ingresa la URL directamente, se renderiza la estructura HTML de la página. | **MEDIA** |

---

## 4. CSS y UI del Sidebar

| Archivo | Línea | Descripción del Hallazgo | Severidad |
| :--- | :--- | :--- | :--- |
| `public/views/auditoria/index.html` | L7, L12-L29 | **Desviación crítica de CSS/UI por uso de Tailwind CSS:** `auditoria/index.html` incluye Tailwind CSS via CDN (`tailwindcss@2.2.19`) y construye la barra lateral con clases como `bg-green-800`, `bg-green-900` y tipografías/anchos distintos, rompiendo por completo la consistencia visual y de diseño con `main.css` (clases `.sidebar`, `.sidebar__brand`, `.sidebar__nav`, `.sidebar-nav-item`) aplicada en el resto de las vistas. | **MEDIA** |
| `public/views/planillas/historico.html` (L82), `planillas/mapa.html` (L151), `auditoria/index.html` (L26) | Varios | **Inconsistencia en el botón "Cerrar Sesión" en el Sidebar:** Las vistas mencionadas incluyen la sección `.sidebar__footer` con el botón de cierre de sesión en el menú lateral, mientras que las otras 9 vistas de administración carecen de este elemento. | **BAJA** |
| `public/views/cobrador/index.html` vs `pagos/cobro-sede.html` | L352 vs L120 | **Inconsistencia en construcción del Sidebar de Cobrador:** En `cobrador/index.html` los items del menú del cobrador están hardcodeados en el HTML estático, mientras que en `cobro-sede.html` se renderizan de forma dinámica con JavaScript en función del rol detectado. | **BAJA** |

---

## 5. Archivos Fuera de Lugar y Estructura por Dominio

| Archivo / Ruta | Línea | Descripción del Hallazgo | Severidad |
| :--- | :--- | :--- | :--- |
| `generate_fase8.php` | L1-L69 | **Script suelto en la raíz del proyecto:** Archivo de utilidades/generación de código PHP en la raíz del repositorio fuera de la estructura de la aplicación. | **MEDIA** |
| `app/modules/deudas/` | N/A | **Módulo duplicado y vacío:** Directorio redundante con subcarpetas de arquitectura DDD (`application`, `domain`, `infrastructure`, `presentation`) totalmente vacías. El código funcional reside en `app/modules/deuda/` (en singular). | **BAJA** |
| `app/modules/configuracion/` y `app/modules/importacion_csv/` | N/A | **Módulos vacíos sin código:** Carpetas de módulos que contienen solo subdirectorios vacíos sin servicios, controladores ni repositorios. | **BAJA** |
| `app/modules/socios/` (subcarpetas) | N/A | **Carpetas de arquitectura en capas vacías:** Presencia de carpetas `application/`, `domain/`, `infrastructure/`, `presentation/` vacías dentro de módulos donde las clases están directamente situadas en la raíz del módulo (`SocioController.php`, `SocioService.php`, `SocioRepository.php`). | **BAJA** |

---

## 6. Código Huérfano (Métodos, imports y clases sin uso)

| Archivo | Línea | Descripción del Hallazgo | Severidad |
| :--- | :--- | :--- | :--- |
| `app/modules/notificaciones/NotificacionRepository.php` | L52-L66 | **Método `registerAuditEvent()` huérfano:** Método definido dentro del repositorio de notificaciones que no es llamado por ningún servicio ni controlador en todo el proyecto. | **BAJA** |
| `app/shared/services/WhatsAppService.php` | L15-L27 | **Servicio e importación sin consumo en backend:** Método `generarLinkPago()` instanciado en `PagoService.php` (L36) pero nunca invocado. La vista `cobro-sede.html` (L510) genera el enlace de WhatsApp directamente en JavaScript. | **BAJA** |
| `app/shared/helpers/DateHelper.php` | L26-L29 | **Método `today()` sin referencias:** Método utilitario estático sin ninguna invocación en el proyecto. | **BAJA** |
| `app/modules/deuda/DeudaController.php` | L38, L59 | **Endpoints expuestos sin vista consumidora:** Métodos `cargarAnterior` (`POST /api/deuda/anterior`) y `exonerar` (`POST /api/deuda/{id}/exonerar`) registrados en el Router pero sin llamadas ni formularios activos en las vistas frontend. | **BAJA** |
| `app/modules/socios/SocioController.php` | L188, L270 | **Métodos de API backend sin interfaz de usuario:** Métodos `revertDelete` (`POST /api/socios/{id}/revertir`) y `getQR` (`GET /api/socios/{id}/qr`) expuestos en la API pero sin invocaciones en el frontend. | **BAJA** |
| `app/modules/notificaciones/NotificacionController.php` | L35 | **Acción de controlador desaprovechada:** Método `revertir` (`POST /api/notificaciones/{id}/revertir`) sin llamadas JS en `notificaciones/index.html`. | **BAJA** |
