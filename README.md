# Centro de Jubilados Primavera — Sistema de Gestión

Sistema web de gestión integral para el Centro de Jubilados Primavera. Permite administrar socios, cuotas, deudas, pagos, planillas de cobro, notificaciones, auditoría y usuarios del sistema.

---

## Requisitos

| Herramienta | Versión mínima |
|---|---|
| PHP | 8.2 |
| MySQL | 8.0 |
| XAMPP | 8.2.x (incluye Apache + MySQL) |
| Composer | 2.x |

---

## Instalación paso a paso

### 1. Clonar o copiar el proyecto

Colocar la carpeta del proyecto dentro de `C:\xampp\htdocs\`:

```
C:\xampp\htdocs\centro-de-jubilados-primavera\
```

### 2. Instalar dependencias PHP

Abrir una terminal en la raíz del proyecto y ejecutar:

```bash
composer install
```

### 3. Configurar variables de entorno

Copiar el archivo de ejemplo y editar los valores:

```bash
copy .env.example .env
```

Editar `.env` con los datos de conexión a la base de datos:

```env
DB_HOST=localhost
DB_NAME=centro_jubilados
DB_USER=root
DB_PASS=

APP_URL=http://localhost/centro-de-jubilados-primavera/public

SESSION_LIFETIME_ADMINISTRADOR=28800
SESSION_LIFETIME_COBRADOR=1800
```

### 4. Crear la base de datos

En phpMyAdmin o MySQL, crear la base de datos:

```sql
CREATE DATABASE centro_jubilados CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
```

### 5. Importar el esquema y los datos iniciales

Ejecutar los archivos de migración ubicados en `database/seeds/`:

```bash
mysql -u root -p centro_jubilados < database/seeds/schema.sql
mysql -u root -p centro_jubilados < database/seeds/seed.sql
```

> Si los archivos son `.php`, ejecutar con:
> ```bash
> php database/seeds/seed.php
> ```

### 6. Configurar Apache (XAMPP)

Verificar que el `DocumentRoot` de Apache apunte a `C:/xampp/htdocs` y que el módulo `mod_rewrite` esté activo.

El archivo `.htaccess` en `public/` ya redirige todas las peticiones a `index.php`.

---

## Cómo ejecutar el proyecto localmente

1. Iniciar **Apache** y **MySQL** desde el Panel de Control de XAMPP.
2. Abrir el navegador en:

```
http://localhost/centro-de-jubilados-primavera/public/
```

El sistema redirige automáticamente a la pantalla de login:

```
http://localhost/centro-de-jubilados-primavera/public/views/auth/login.html
```

---

## Credenciales de acceso inicial

| Campo | Valor |
|---|---|
| Usuario | `admin` |
| Contraseña | `Admin1234!` |
| Rol | Administrador |

> ⚠️ **Cambie la contraseña del administrador inmediatamente** después del primer ingreso al sistema desde el módulo **Usuarios**.

---

## Estructura de carpetas principal

```
centro-de-jubilados-primavera/
├── app/
│   ├── config/                  # Config.php, Database.php
│   ├── modules/
│   │   ├── auth/                # Autenticación (login, logout, sesión)
│   │   ├── socios/              # Gestión de socios y padrón
│   │   ├── deuda/               # Cuotas y deudas
│   │   ├── pagos/               # Registro de pagos y comprobantes
│   │   ├── planillas/           # Planillas de cobro (PDF)
│   │   ├── notificaciones/      # Notificaciones del sistema
│   │   ├── auditoria/           # Log de auditoría
│   │   ├── historial/           # Historial de cambios por socio
│   │   ├── observaciones/       # Observaciones por socio
│   │   ├── zonas/               # Zonas geográficas
│   │   ├── dashboard/           # Métricas del panel de control
│   │   └── usuarios/            # Gestión de usuarios del sistema
│   └── shared/                  # Router, AuthMiddleware, Helpers
├── database/
│   └── seeds/                   # Esquema SQL y datos iniciales
├── docs/
│   ├── api.md                   # Documentación completa de la API
│   └── manual-de-usuario.md     # Manual operativo para usuarios finales
├── public/
│   ├── assets/
│   │   ├── css/main.css         # Hoja de estilos global
│   │   └── vendor/leaflet/      # Leaflet.js (mapas, servido localmente)
│   ├── views/                   # Vistas HTML por módulo
│   └── index.php                # Front controller (router)
├── storage/
│   └── comprobantes/            # PDFs de comprobantes de pago
├── tests/                       # Suite de pruebas
├── vendor/                      # Dependencias Composer
├── composer.json
└── .env                         # Variables de entorno (no versionar)
```

---

## Módulos del sistema

| Módulo | Descripción |
|---|---|
| Autenticación | Login/logout con sesiones PHP, bloqueo por intentos fallidos |
| Socios | CRUD completo, importación CSV, geolocalización, QR |
| Deudas | Generación mensual de cuotas, exoneración, cascada de pago |
| Pagos | Registro de cobros, comprobante PDF, anulación, WhatsApp |
| Planillas | Generación de planillas por cobrador con PDF |
| Notificaciones | Alertas del sistema con reversión y archivado |
| Auditoría | Registro inmutable de todas las acciones del sistema |
| Historial | Línea de tiempo de cambios por socio |
| Observaciones | Notas internas por socio |
| Dashboard | KPIs en tiempo real y actividad reciente |
| Usuarios | Gestión de administradores y cobradores |
| Zonas | Administración de zonas geográficas de cobro |
| Configuración | Parámetros globales del sistema |

---

## Tecnologías

- **Backend:** PHP 8.2, PDO nativo, arquitectura por dominio (Controller → Service → Repository)
- **Autoload:** PSR-4 con Composer, namespace raíz `CJP\`
- **Frontend:** HTML5, JavaScript vanilla, CSS custom (sin frameworks CSS)
- **Mapas:** Leaflet.js (servido localmente)
- **PDF:** FPDF
- **UUID:** `ramsey/uuid`
- **Base de datos:** MySQL 8.0
