# Centro de Jubilados Primavera — Sistema de Gestión

Sistema web de gestión integral desarrollado para el Centro de Jubilados Primavera (CJP). Permite administrar el padrón de socios con geolocalización y credenciales QR, configuración histórica y generación masiva de cuotas sociales, cobranzas bajo regla estricta de cascada cronológica con emisión de recibos en PDF y enlaces de WhatsApp, distribución de planillas de cobro por cobrador y zona, registro inmutable de auditoría, importación masiva asíncrona mediante worker CLI y gestión completa de copias de seguridad con restauración protegida.

---

## Índice

1. [Requisitos del Sistema](#1-requisitos-del-sistema)
2. [Guía de Instalación Paso a Paso](#2-guía-de-instalación-paso-a-paso)
3. [Variables de Entorno (.env)](#3-variables-de-entorno-env)
4. [Acceso desde Dispositivos Móviles y Red Local](#4-acceso-desde-dispositivos-móviles-y-red-local)
5. [Credenciales de Acceso Inicial y Seguridad](#5-credenciales-de-acceso-inicial-y-seguridad)
6. [Resumen de Módulos y Funcionalidades](#6-resumen-de-módulos-y-funcionalidades)
7. [Suite de Pruebas Automatizadas](#7-suite-de-pruebas-automatizadas)
8. [Estructura del Proyecto](#8-estructura-del-proyecto)
9. [Documentación de la API](#9-documentación-de-la-api)

---

## 1. Requisitos del Sistema

| Componente | Versión / Detalle | Propósito |
|---|---|---|
| **PHP** | 8.2 o superior | Lenguaje base del backend y scripts CLI. |
| **Servidor Web** | Apache 2.4 (XAMPP) | Despacho web con módulo `mod_rewrite` habilitado (`public/.htaccess`). |
| **Base de Datos** | MariaDB 10.4 (XAMPP) | Servidor relacional (`10.4.32-MariaDB` incluido en XAMPP 8.2.x). |
| **Gestor de Paquetes** | Composer 2.x | Gestión de dependencias PHP (`psr-4`, `ramsey/uuid`, `endroid/qr-code`, `setasign/fpdf`). |

### Extensiones PHP obligatorias (en `php.ini`):
- `pdo_mysql`: Conexión nativa mediante PDO a la base de datos MariaDB.
- `curl`: Consumo de servicios HTTP y ejecución de la suite de pruebas funcionales.
- `fileinfo`: Validación estricta del tipo MIME real en subidas de archivos CSV y volcados SQL.
- `gd`: Renderizado y exportación de imágenes PNG para los códigos QR de los socios.
- `mbstring`: Manejo de codificación de cadenas UTF-8.
- `json`: Serialización y deserialización de peticiones y respuestas JSON.
- `session`: Gestión de sesiones seguras PHP (`PHPSESSID`).

### Herramientas CLI de XAMPP:
Los módulos de respaldo y restauración interactúan directamente con los binarios de MariaDB en XAMPP:
- `C:\xampp\mysql\bin\mysqldump.exe`: Generación de respaldos completos en streaming.
- `C:\xampp\mysql\bin\mysql.exe`: Ejecución de restauraciones con archivo temporal de opciones `.cnf`.
- `C:\xampp\php\php.exe`: Ejecución del worker CLI en segundo plano y suite de pruebas.

---

## 2. Guía de Instalación Paso a Paso

### Paso 1: Clonar o copiar el repositorio
Ubicar el proyecto dentro del directorio `htdocs` de XAMPP:
```
C:\xampp\htdocs\centro-de-jubilados-primavera\
```

### Paso 2: Instalar dependencias PHP
Abrir una terminal en la raíz del proyecto y ejecutar Composer:
```bash
composer install
```

### Paso 3: Configurar variables de entorno
Copiar el archivo de plantilla `.env.example` a `.env`:
```bash
copy .env.example .env
```
Editar el archivo `.env` según la configuración local (ver sección [Variables de Entorno](#3-variables-de-entorno-env)).

### Paso 4: Crear la base de datos en MariaDB
Iniciar los servicios **Apache** y **MySQL** desde el Panel de Control de XAMPP.  
Desde phpMyAdmin o la consola de MariaDB, crear la base de datos con codificación UTF-8:
```sql
CREATE DATABASE centro_jubilados_primavera CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
```

### Paso 5: Importar estructura y usuario inicial
Importar el esquema general completo (15 tablas) desde la raíz del proyecto:
```bash
mysql -u root -p centro_jubilados_primavera < database/schema.sql
```
*(Si el usuario `root` no tiene contraseña configurada en XAMPP, omitir `-p` o presionar Enter al solicitarla)*.

A continuación, insertar el registro del usuario administrador inicial:
```bash
mysql -u root -p centro_jubilados_primavera < database/seeds/003_usuario_admin.sql
```

> **Nota sobre migraciones incrementales:**  
> Las migraciones ubicadas en `database/migrations/` (`004_add_tipo_documento_to_socios.sql`, `005_create_backups_log.sql`, `006_create_importaciones_csv.sql`) ya se encuentran totalmente consolidadas e incorporadas dentro de `database/schema.sql`. Solo deben aplicarse de forma individual en caso de actualizar una instalación antigua que no cuente con dichas tablas o columnas.

### Paso 6: Iniciar el sistema en el navegador
Abrir el navegador web e ingresar a:
```
http://localhost/centro-de-jubilados-primavera/public/
```
El enrutador redirige automáticamente a la pantalla de acceso:
```
http://localhost/centro-de-jubilados-primavera/public/views/auth/login.html
```

---

## 3. Variables de Entorno (.env)

El archivo `.env` almacena los parámetros de configuración del entorno. Cada variable cumple una función específica:

| Variable | Descripción |
|---|---|
| `DB_HOST` | Host del servidor de base de datos MariaDB (por defecto `localhost`). |
| `DB_NAME` | Nombre de la base de datos relacional (`centro_jubilados_primavera`). |
| `DB_USER` | Nombre de usuario de la base de datos (`root` por defecto en XAMPP). |
| `DB_PASS` | Contraseña de conexión a la base de datos (vacía por defecto en XAMPP). |
| `APP_URL` | URL base absoluta hasta la carpeta `/public` (`http://localhost/centro-de-jubilados-primavera/public` o con IP Wi-Fi). |
| `PHP_CLI_PATH` | Ruta absoluta a tu `php.exe` de XAMPP (ej. `C:\xampp\php\php.exe`), requerida para el worker de importación CSV. |
| `SESSION_LIFETIME_ADMINISTRADOR` | Expiración de sesión por inactividad para administradores en segundos (por defecto `28800` = 8 horas). |
| `SESSION_LIFETIME_COBRADOR` | Expiración de sesión por inactividad para cobradores en segundos (por defecto `1800` = 30 minutos). |

> **Importante sobre `PHP_CLI_PATH`:**  
> Esta variable define la ruta al binario PHP de línea de comandos que utiliza el backend para disparar tareas en segundo plano (worker de importación masiva). La ruta varía según la instalación de XAMPP, pudiendo ser `C:\xampp\php\php.exe` o `C:\xampp\php\windowsXamppPhp\php.exe`. Verifique en su disco la ruta exacta antes de iniciar importaciones masivas.

---

## 4. Acceso desde Dispositivos Móviles y Red Local

El sistema permite operar desde teléfonos móviles o tablets conectados a la misma red Wi-Fi local para realizar cobranzas en territorio o registrar pagos en sede con lectura de códigos QR.

### Paso 1: Averiguar la IP local de la computadora
1. En la computadora con Windows donde corre XAMPP, abrir PowerShell o CMD.
2. Ejecutar el comando:
   ```cmd
   ipconfig
   ```
3. Localizar el adaptador de red Wi-Fi o Ethernet en uso y copiar el valor de la **Dirección IPv4** (por ejemplo: `192.168.1.45`).

### Paso 2: Actualizar `APP_URL` en `.env`
Editar el archivo `.env` configurando la IP averiguada:
```env
APP_URL=http://192.168.1.45/centro-de-jubilados-primavera/public
```
Esto garantiza que los enlaces generados para WhatsApp, los comprobantes en PDF y las redirecciones apunten a una dirección accesible desde el celular.

### Paso 3: Configurar el Firewall de Windows
Para que Windows no bloquee las conexiones entrantes desde el celular hacia el servidor Apache:
1. Asegurarse de que la red Wi-Fi esté configurada como **Red Privada** en la configuración de red de Windows.
2. Abrir el **Firewall de Windows Defender con seguridad avanzada**.
3. En **Reglas de entrada**, crear una **Nueva regla**:
   - Tipo de regla: **Puerto** -> Siguiente.
   - Protocolo: **TCP**, Puertos locales específicos: **80** -> Siguiente.
   - Acción: **Permitir la conexión** -> Siguiente.
   - Perfil: Marcar únicamente **Privado** -> Siguiente.
   - Nombre: `Apache XAMPP HTTP (Puerto 80)`.
   *(Alternativa: permitir el ejecutable `C:\xampp\apache\bin\httpd.exe` para redes privadas).*
4. Desde el navegador del teléfono móvil, ingresar a:
   ```
   http://192.168.1.45/centro-de-jubilados-primavera/public/
   ```

---

## 5. Credenciales de Acceso Inicial y Seguridad

El seed de inicialización provee el usuario administrativo predeterminado:

| Campo | Valor Inicial |
|---|---|
| **Usuario** | `admin` |
| **Contraseña** | `Admin1234!` |
| **Rol** | `administrador` |

> **ADVERTENCIA DE SEGURIDAD CRÍTICA:**  
> Por motivos estrictos de seguridad institucional, **cambie la contraseña del administrador inmediatamente tras el primer ingreso** desde el módulo **Usuarios** (`/views/usuarios/index.html`) o mediante la API (`POST /api/usuarios/{id}/password`). La contraseña debe poseer al menos 8 caracteres.

---

## 6. Resumen de Módulos y Funcionalidades

### Roles del Sistema
- **Administrador:** Control total sobre todos los módulos: gestión del padrón de socios, fijación del valor de cuotas, generación mensual masiva de deudas, exoneración administrativa, planillas de cobro, anulación de pagos, auditoría completa, administración de usuarios, backups, restauraciones e importación masiva CSV.
- **Cobrador:** Perfil operativo diseñado para cobranza en calle o ventanilla. Permite buscar socios, consultar deudas pendientes, registrar cobros bajo regla de cascada cronológica con emisión de recibos PDF y links de WhatsApp, y consultar **exclusivamente sus propias planillas asignadas** (el acceso a planillas de otros cobradores está bloqueado con HTTP 403). Expiración estricta de sesión por inactividad a los 30 minutos.

### Catálogo de Módulos
1. **Autenticación (`/api/auth`):** Inicio y cierre de sesión, sesiones PHP seguras (`HttpOnly`, `SameSite=Lax`), bloqueo automático por fuerza bruta de cuenta (5 intentos fallidos) y de IP (15 intentos en 15 min).
2. **Dashboard (`/api/dashboard`):** Métricas y KPIs en tiempo real (socios activos, morosos con 2 o más períodos impagos, total adeudado, recaudación mensual y diaria, alertas no leídas, indicador booleano `deuda_mes_generada` y período actual `deuda_mes_periodo`).
3. **Socios (`/api/socios`):** Altas, bajas lógicas con ventana temporal de reversión de 7 días, modificaciones, suspensiones, reactivaciones, georreferenciación automática, cálculo de zona por coordenadas y generación de credencial QR individual en PNG.
4. **Importación Masiva CSV (`/api/socios/importar`):** Procesamiento en segundo plano mediante worker CLI desacoplado (`scripts/importar_csv_worker.php`), reporte de avance en tiempo real (`GET /api/socios/importar/{id}/estado`) y bandeja de inconsistencias para subsanar errores de carga.
5. **Cuotas y Deudas (`/api/cuota`, `/api/deuda`):** Registro de valor de cuota social con fecha de vigencia, generación mensual masiva con advertencia de duplicados, carga de deudas anteriores al sistema, consulta de períodos adeudados y exoneración con justificación en auditoría.
6. **Pagos y Cobranzas (`/api/pagos`):** Cobro secuencial en cascada cronológica estricta (no permite cobrar períodos recientes adeudando anteriores), generación automática de comprobante PDF (`FPDF`), enlace para compartir recibo por WhatsApp y anulación justificada de pagos con restitución de deudas a estado pendiente.
7. **Planillas de Cobro (`/api/planillas`):** Generación de planillas domiciliarias agrupadas por zona y cobrador asignado con exportación a PDF para impresión o visualización móvil. Restricción estricta por rol.
8. **Notificaciones (`/api/notificaciones`):** Alertas del sistema (no leídas, leídas, archivadas) y reversión administrativa de acciones sensibles dentro del plazo de gracia.
9. **Auditoría (`/api/auditoria`):** Registro inmutable y cronológico de todas las operaciones realizadas (usuario, acción, entidad, valores previos, nuevos valores, fecha y motivo).
10. **Historial de Socio (`/api/historial`):** Línea de tiempo unificada con todos los eventos asociados a un socio particular.
11. **Observaciones (`/api/observaciones`):** Notas internas registradas por operadores sobre la ficha del socio (campo `contenido`, límite de 1000 caracteres).
12. **Zonas Geográficas (`/api/zonas`):** Administración de polígonos urbanos y resolución algorítmica de zona a partir de latitud y longitud.
13. **Usuarios (`/api/usuarios`):** Gestión de cuentas de operadores, asignación de rol inmutable, cambio de contraseñas (hash bcrypt) y desactivación/reactivación.
14. **Copia de Seguridad y Restauración (`/api/backup`):**
    - *Generación:* Volcado SQL completo de todas las tablas con `mysqldump.exe` sin bloqueo de tablas y descarga directa.
    - *Historial:* Consulta del último respaldo y listado cronológico de los últimos 20 backups (`GET /api/backup/historial`).
    - *Restauración:* Subida de archivo SQL (`POST /api/backup/restaurar`) con palabra de confirmación obligatoria `RESTAURAR`, verificación de cabecera oficial `-- CJP-BACKUP-V1`, validación de nombre de base coincidente, rechazo de sentencias prohibidas de administración, bloqueo de concurrencia exclusivo con flock sobre `.restaurando` (HTTP 409), generación de respaldo preventivo automático (`pre-restauracion-*.sql`) y cierre forzoso de sesión del administrador al finalizar.

---

## 7. Suite de Pruebas Automatizadas

El proyecto incluye un arnés de pruebas automatizado completo para verificar la integridad de todos los módulos del sistema:

- **Archivo de pruebas:** `scripts/test_sistema.php`
- **Naturaleza técnica:** **No son pruebas PHPUnit**, sino una suite de **pruebas funcionales end-to-end (E2E)** escritas en PHP nativo que realiza peticiones HTTP reales mediante cURL contra la API en vivo, validando códigos de estado, cabeceras, sesiones de usuario, cascada cronológica y persistencia en MariaDB.

### Ejecución en PowerShell:

Para ejecutar la suite, utilice la ruta completa de PHP y de su archivo `php.ini` de acuerdo a su instalación de XAMPP:

```powershell
# Paso 1: Limpiar posibles registros residuales de corridas previas
C:\xampp\php\windowsXamppPhp\php.exe -c C:\xampp\php\php.ini scripts/test_sistema.php --limpiar

# Paso 2: Ejecutar la suite completa de pruebas funcionales
C:\xampp\php\windowsXamppPhp\php.exe -c C:\xampp\php\php.ini scripts/test_sistema.php
```

> **Nota sobre la ruta de PHP:**  
> Si su instalación de XAMPP cuenta con el binario directamente en `C:\xampp\php\php.exe`, ajuste el comando en consecuencia:  
> `C:\xampp\php\php.exe -c C:\xampp\php\php.ini scripts/test_sistema.php`  
> El script utiliza prefijos `TEST_` y DNI reservados (`99999998`, `99999997`) para operar en aislamiento absoluto sin alterar los datos reales del padrón.

---

## 8. Estructura del Proyecto

```
centro-de-jubilados-primavera/
├── app/
│   ├── config/                  # Config.php (lector de .env), Database.php (singleton PDO)
│   ├── modules/
│   │   ├── auditoria/           # AuditoriaController, AuditoriaService, AuditoriaRepository
│   │   ├── auth/                # AuthController, AuthService, AuthRepository
│   │   ├── backup/              # BackupController, BackupService, RestoreController, RestoreService
│   │   ├── dashboard/           # DashboardController (KPIs en tiempo real)
│   │   ├── deuda/               # DeudaController, DeudaService, DeudaRepository
│   │   ├── historial/           # HistorialController, HistorialService
│   │   ├── notificaciones/      # NotificacionController, NotificacionService, NotificacionRepository
│   │   ├── observaciones/       # ObservacionController, ObservacionService, ObservacionRepository
│   │   ├── pagos/               # PagoController, PagoService, PagoRepository
│   │   ├── planillas/           # PlanillaController, PlanillaService, PlanillaRepository
│   │   ├── socios/              # SocioController, SocioService, SocioRepository
│   │   ├── usuarios/            # UsuarioController, UsuarioService, UsuarioRepository
│   │   └── zonas/               # ZonaController, ZonaService, ZonaRepository
│   └── shared/                  # Router, AuthMiddleware, ExceptionHandler, Helpers (ResponseHelper, DateHelper)
├── database/
│   ├── migrations/              # Migraciones incrementales históricas (004, 005, 006)
│   ├── seeds/                   # 003_usuario_admin.sql (semilla de usuario administrador)
│   └── schema.sql               # Esquema DDL completo y consolidado de las 15 tablas del sistema
├── docs/
│   ├── api.md                   # Referencia técnica exhaustiva de la API REST (60 endpoints)
│   ├── informe-api.md           # Informe de auditoría de la API y discrepancias
│   └── informe-readme.md        # Informe de auditoría del README y estado del sistema
├── public/
│   ├── assets/
│   │   ├── css/main.css         # Hoja de estilos global de la aplicación
│   │   ├── js/                  # Módulos JavaScript vanilla (app.js, api.js, ui.js)
│   │   └── vendor/leaflet/      # Librería Leaflet.js para mapas (servida localmente)
│   ├── views/                   # Interfaces HTML por módulo (auth, socios, pagos, etc.)
│   ├── .htaccess                # Redirección de peticiones Apache hacia index.php
│   └── index.php                # Front controller HTTP y registro de rutas
├── scripts/
│   ├── importar_csv_worker.php  # Worker CLI en segundo plano para importación masiva de socios
│   └── test_sistema.php         # Arnés de pruebas funcionales automatizadas E2E
├── storage/
│   ├── backups/                 # Resguardos pre-restauración y archivo de bloqueo .restaurando
│   ├── comprobantes/            # Recibos de pago en PDF generados
│   ├── imports/                 # Archivos CSV cargados temporalmente para importación
│   ├── logs/                    # Bitácoras del sistema (errors.log y worker.log)
│   ├── planillas/               # Planillas de cobro domiciliario en PDF generadas
│   └── qr/                      # Códigos QR individuales en formato PNG
├── tests/                       # Directorio reservado para pruebas unitarias PHPUnit
├── vendor/                      # Dependencias administradas por Composer
├── .env.example                 # Plantilla de variables de entorno con valores genéricos
├── composer.json                # Definición de dependencias y mapeo PSR-4
├── .gitignore                   # Reglas de exclusión para git (.env, logs, backups, etc.)
└── README.md                    # Este documento
```

---

## 9. Documentación de la API

La especificación técnica completa de la API REST se encuentra documentada en detalle en:

**[docs/api.md](docs/api.md)**

Allí se describen los **60 endpoints** del sistema, detallando:
- Métodos HTTP, rutas y roles de acceso requeridos (`administrador` vs autenticado).
- Parámetros de ruta, query parameters y esquemas de payload JSON / `multipart/form-data`.
- Respuestas exitosas con códigos HTTP correspondientes (200 OK, 201 Created, 202 Accepted).
- El esquema dual de errores de la API:
  - Formato `ResponseHelper::error` para validaciones directas y control de acceso: `{"success": false, "message": "...", "errors": null}`.
  - Formato `ExceptionHandler::handle` para excepciones de dominio y fallos no controlados: `{"success": false, "message": "...", "correlation_id": "uuid"}`.
- Reglas de negocio críticas (cascada cronológica estricta en pagos, cálculo de zonas, geolocalización, bloqueo concurrente en restauraciones).
