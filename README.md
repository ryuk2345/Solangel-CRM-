# 🏪 CRM Boutique — Sistema de Facturación Electrónica con SUNAT

<p align="center">
  <img src="https://img.shields.io/badge/Laravel-12.x-FF2D20?style=for-the-badge&logo=laravel&logoColor=white" />
  <img src="https://img.shields.io/badge/PHP-8.2+-777BB4?style=for-the-badge&logo=php&logoColor=white" />
  <img src="https://img.shields.io/badge/Filament-3.x-FDAE4B?style=for-the-badge" />
  <img src="https://img.shields.io/badge/SUNAT-Greenter-00A651?style=for-the-badge" />
  <img src="https://img.shields.io/badge/MySQL-8.x-4479A1?style=for-the-badge&logo=mysql&logoColor=white" />
</p>

Sistema CRM completo para gestión de ventas, inventario y **facturación electrónica** integrada con SUNAT (vía Greenter), diseñado para boutiques y negocios retail en Perú.

---

## ✨ Características principales

| Módulo | Descripción |
|---|---|
| 🧾 **Facturación SUNAT** | Emisión de Boletas, Facturas, Notas de Crédito y Débito electrónicas |
| 👥 **Clientes** | Gestión con validación de DNI/RUC vía API Perú |
| 📦 **Productos** | Catálogo multi-rubro con variantes, imágenes e inventario |
| 🏪 **Sucursales** | Multi-sede con series y cajas independientes |
| 💰 **Caja** | Apertura/cierre, movimientos y auditoría de ventas |
| 👤 **Usuarios y Roles** | Control de acceso basado en roles (Admin, Cajero, etc.) |
| 📊 **Reportes** | PDF de comprobantes y reportes de ventas |
| 🔐 **Shield** | Políticas de permisos granulares con Filament Shield |

---

## 🛠️ Stack Tecnológico

- **Framework:** Laravel 12 + PHP 8.2+
- **Panel Admin:** FilamentPHP 3
- **SUNAT:** Greenter 5.x (UBL 2.1)
- **PDF:** DomPDF / barryvdh
- **QR:** Simple QrCode
- **Permisos:** Spatie Laravel Permission + Filament Shield
- **Frontend:** Vite + Tailwind (Filament)

---

## ⚙️ Instalación Local

### 1. Clonar el repositorio

```bash
git clone https://github.com/TU_USUARIO/crm-boutique.git
cd crm-boutique
```

### 2. Instalar dependencias

```bash
composer install
npm install
```

### 3. Configurar entorno

```bash
cp .env.example .env
php artisan key:generate
```

Edita `.env` y configura tu base de datos MySQL y tus datos de SUNAT.

### 4. Base de datos

```bash
php artisan migrate
php artisan db:seed --class=SunatCatalogsSeeder
php artisan db:seed --class=AdminUserSeeder
```

O importa directamente el archivo SQL limpio incluido:
```
catalogo_boutique(DB LIMPIO).sql
```

### 5. Certificado SUNAT

Coloca tu certificado `.pfx` en:
```
storage/app/sunat/certs/certificado.pfx
```

> ⚠️ **Nunca subas el certificado al repositorio.** Está excluido en `.gitignore`.

### 6. Crear directorios SUNAT

```bash
mkdir -p storage/app/sunat/certs
mkdir -p storage/app/sunat/xmls
mkdir -p storage/app/sunat/cdrs
```

### 7. Compilar assets y levantar

```bash
npm run build
php artisan storage:link
php artisan serve
```

---

## 🚀 Despliegue en cPanel / Hosting Compartido

### Paso 1 — Subir archivos

Sube todo el proyecto (excepto `/vendor` y `/node_modules`) al directorio de tu dominio.

### Paso 2 — Variables de entorno

Crea el `.env` en el servidor con tus datos de producción:

```env
APP_ENV=production
APP_DEBUG=false
APP_URL=https://tu-dominio.com

DB_CONNECTION=mysql
DB_DATABASE=nombre_db_cpanel
DB_USERNAME=usuario_db_cpanel
DB_PASSWORD=contraseña_db_cpanel

EMPRESA_RUC=20XXXXXXXXX
EMPRESA_NOMBRE=TU EMPRESA SAC
SUNAT_USUARIO_SOL=TU_USUARIO
SUNAT_CLAVE_SOL=TU_CLAVE
```

### Paso 3 — Instalar dependencias en el servidor

```bash
composer install --no-dev --optimize-autoloader
```

### Paso 4 — Optimizar Laravel para producción

```bash
php artisan config:cache
php artisan route:cache
php artisan view:cache
php artisan storage:link
```

### Paso 5 — Migrar base de datos

```bash
php artisan migrate --force
php artisan db:seed --class=SunatCatalogsSeeder
php artisan db:seed --class=AdminUserSeeder
```

### Paso 6 — Permisos de carpetas

```bash
chmod -R 775 storage bootstrap/cache
```

---

## 👤 Usuario Admin por Defecto

| Campo | Valor |
|---|---|
| Email | `admin@boutique.com` |
| Password | `password` |

> ⚠️ **Cambia la contraseña inmediatamente después del primer ingreso.**

---

## 🗂️ Estructura del Proyecto

```
app/
├── Filament/
│   ├── Resources/          # Paneles CRUD (Ventas, Productos, etc.)
│   ├── Widgets/            # Widgets del dashboard
│   └── Page/               # Páginas personalizadas
├── Models/                 # Eloquent Models
├── Services/
│   └── SunatService.php    # Integración con SUNAT (Greenter)
database/
├── migrations/             # 29 migraciones ordenadas
└── seeders/
    ├── DatabaseSeeder.php
    ├── SunatCatalogsSeeder.php  # Catálogos SUNAT 06 y 07
    └── AdminUserSeeder.php      # Usuario admin inicial
storage/app/sunat/
├── certs/   # ← Aquí va tu certificado .pfx (NO subir al repo)
├── xmls/    # XMLs generados
└── cdrs/    # CDRs de respuesta SUNAT
```

---

## 🔑 Variables de Entorno Clave

| Variable | Descripción |
|---|---|
| `EMPRESA_RUC` | RUC de 11 dígitos de tu empresa |
| `EMPRESA_NOMBRE` | Razón social |
| `EMPRESA_DIRECCION` | Dirección fiscal |
| `SUNAT_USUARIO_SOL` | Usuario SOL SUNAT |
| `SUNAT_CLAVE_SOL` | Clave SOL SUNAT |
| `API_PERU_TOKEN` | Token de APIs Perú (consulta DNI/RUC) |

---

## 📄 Licencia

Este proyecto es de uso privado. Todos los derechos reservados.

---

<p align="center">Desarrollado con ❤️ para negocios retail en Perú 🇵🇪</p>
