# PLAN_MODA — Rwarehouse Moda

Plan para convertir la base heredada de Mangui (repuestos) en un sistema para una tienda de ropa minorista y mayorista.
Estado: **implementado** en la rama `feat/etapa-0-instalacion` (Etapas 0 a 9, incluida la 8b de crédito). Cómo probarlo: `PROBAR_EN_LOCAL.md`.

| Etapa | Estado |
|---|---|
| 0 Instalación desde cero | Hecha |
| 1 Limpieza de repuestos | Hecha |
| 2 Roles y permisos | Hecha |
| 3 Catálogo de moda (colores, tallas, curvas, dos precios) | Hecha |
| 4 Precio minorista/mayorista + aprobación con PIN | Hecha |
| 5 Kardex, stock bajo, matriz | Hecha |
| 6 Compras por curva y etiquetas | Hecha |
| 7 Punto de venta (escáner y selector) | Hecha |
| 8 Cambios de talla (nota de crédito parcial) | Hecha |
| 8b Ventas a crédito | Hecha |
| 9 Reportes | Hecha |

Pendiente fuera de este entorno: probar la emisión real en SUNAT beta con el certificado de pruebas (boleta, factura a crédito y nota de crédito parcial).

---

## 0. Estado verificado del repositorio

- Un solo commit en `main` (`445bfe6 Base inicial Rwarehouse Moda (historial limpio)`).
- No se suben `.env`, certificados ni `.sql`. En `public/` solo quedan `index.php`, assets y `productos_ejemplo.csv`.
- Stack: Laravel 12, Filament 3, Filament Shield 3 + Spatie Permission 6, Greenter 5, DomPDF y QR.
- **Prueba en una base vacía (MariaDB local, `rwarehouse_moda`):** `migrate:fresh --seed` **falla** en `2026_10_05_000002_create_warehouses_table`, porque intenta crear el almacén de la tienda 1 y esa tienda no existe (en Mangui ya existía). Creando la tienda 1 a mano, el resto de migraciones y seeders pasa, y `/admin/login` responde 200. Ver H14 y H15.
- `AGENTS.md` todavía tiene las reglas de Mangui Perú (bases `mangui_local`/`mangui_ensayo`, migraciones solo aditivas y archivo SQL de despliegue). **Hay que reescribirlo para este proyecto** (Etapa 0).

---

## 1. Diagnóstico: conservar, adaptar o eliminar

| Módulo | Archivos | Decisión |
|---|---|---|
| Tiendas (sucursales) | `BranchResource`, `Branch` | **Conservar** |
| Almacenes | `WarehouseResource`, `Warehouse`, migración `2026_10_05_000002` | **Conservar** |
| Traspasos con guía | `StockTransferResource`, `StockTransfer*`, `DespatchResource`, `Despatch*` | **Conservar** |
| Caja diaria | `CashRegisterResource`, `CashRegisterController`, `CashRegister*` | **Conservar** |
| Series y SUNAT | `SerieResource`, `SunatService`, `SunatNoteReader`, `SaleReemissionService`, `SaleAnulacionService` | **Conservar** |
| Usuarios | `UserResource`, `User` | **Conservar** (cambian los roles; ver §4) |
| Clientes | `CustomerResource`, `Customer` | **Adaptar**: agregar tipo minorista/mayorista |
| Categorías | `CategoryResource` | **Conservar** |
| Productos | `ProductResource`, `Product` | **Adaptar**: modelo con temporada y marca, sin campos de repuestos |
| Variantes | `ProductVariantResource`, `ProductVariant` | **Adaptar**: color y talla como catálogo, EAN-13, dos precios |
| Inventario | `InventoryResource`, `Inventory` | **Adaptar**: matriz talla × color, stock bajo y movimientos (kardex) |
| Ventas | `SaleResource` (1,771 líneas), `CreateSale`, `SalePrintController` | **Adaptar**: precios calculados en el servidor, regla de mayorista, selector de talla y color |
| Cotizaciones | `QuotationResource`, `QuotationPrintController` | **Adaptar**: misma regla de precios |
| Auditoría de venta / venta rápida | `AuditSaleResource`, `is_quick_sale` en `SaleResource:85` | **Decidir** (pregunta 3) |
| Servicios | `ServiceResource`, `products.is_service` | **Decidir** (pregunta 4) |
| Importadores | `ImportProductsPage` (606 líneas), `ProductImporter` (310), `ProductImportController` | **Reescribir** como un solo importador de modelos con variantes |
| Widgets del dashboard | `StatsOverview`, `SalesChart`, `PaymentMethodsChart`, `LatestSalesWidget` | **Conservar** y agregar el de stock bajo |
| Ruta pública `/ropa` | `routes/web.php:20`, `resources/views/shop` | **Desactivar por ahora** (ver hallazgo H8) |
| Manual público | `routes/web.php:14` (`Manual_Usuario_MaguiCRM.pdf`) | **Eliminar o reescribir** |
| Campos de repuestos | `products.oem_code`, `manufacturer`, `compatibility`, `material` (se usaba como OEM), `brand` (texto) | **Eliminar** |
| "Repuesto Varios / Genérico" | `SaleResource.php` ~L410–435 | **Eliminar** |

---

## 2. Hallazgos importantes (riesgos actuales del código)

**H1 — El precio de venta lo decide el formulario, no el servidor.** El campo `price` del detalle es editable (`SaleResource.php` ~L605) y `CreateSale::mutateFormDataBeforeCreate` (L83–109) toma ese precio sin recalcularlo. Un vendedor puede cobrar cualquier precio. **La regla de mayorista tiene que calcularse en el servidor**; si no, es fácil saltársela.

**H2 — La venta usa el precio del producto e ignora el de la variante.** `SaleResource.php:529`, `:561` y `QuotationResource.php:235` leen `$variante->product->sale_price`. Con precio propio por talla, la XXL se cobraría al precio base.

**H3 — `Product::saved` crea una variante "Único" y sobrescribe precios** (`Product.php` L18–57), usando `oem_code` como SKU y `brand` como color. Es incompatible con el generador de variantes y hay que quitarlo.

**H4 — No existe un módulo de compras ni un kardex.** El stock solo vive en `inventories.stock`. Hoy entra por edición manual en `InventoryResource` o por los importadores, y no queda registro de quién movió qué. Para compras por curva, ajustes y auditoría hace falta una tabla de movimientos.

**H5 — Se permite vender con stock negativo** (`SaleResource.php` ~L585 y ~L612 solo avisan). En ropa, vender una talla que no existe genera errores de inventario. Ver pregunta 5.

**H6 — La validación de stock al cambiar la cantidad suma toda la tienda, no el almacén** (`SaleResource.php` ~L596: `where('branch_id')->sum('stock')`). Es inconsistente con el descuento real, que se hace por almacén (`CreateSale.php` L364–386).

**H7 — Falta la migración de la tabla `brands`.** Existen el modelo `Brand` y su uso en `ImportProductsPage.php:154` y `ProductImporter.php:29`, pero ninguna migración crea `brands`. En una base nueva, el importador va a fallar.

**H8 — `/ropa` es pública y los productos nacen visibles.** `products.is_web_visible` tiene `default(true)`, así que todo producto nuevo aparece en `/ropa` sin login.

**H9 — Roles.** Solo existen `super_admin` (seeder) y `panel_user` (Shield). Las políticas combinan permisos de Shield, `hasRole('super_admin')` y comparaciones de `branch_id`. No hay roles de negocio.

**H10 — Stock bajo.** Lo único que existe es un color rojo cuando el stock es ≤ 5 en `InventoryResource.php:134`. No hay umbral configurable, alerta ni reporte.

**H11 — No hay devoluciones parciales.** `SaleAnulacionService` anula la venta completa (nota de crédito total y devolución de todo el stock). Los cambios de talla necesitan algo nuevo.

**H12 — Clientes:** no tienen tipo y no hay ventas a crédito ni cuentas por cobrar.

**H14 — El sistema no se puede instalar desde cero.** Las migraciones de multisucursal (`2026_10_05_000002`, `000003`, `000006`, `000008`) rellenan datos asumiendo que existe la tienda 1, y ningún seeder la crea. Cada cliente nuevo de Rwarehouse tendría este problema.

**H15 — Ningún permiso de Shield está generado** (tabla `permissions` vacía, un solo rol: `super_admin`). Hoy, cualquier usuario que no sea `super_admin` no puede usar nada.

**H13 — Valores por defecto de la plantilla:** `company_settings.name` = `'BOUTIQUE SAC'` y `ruc` = `'20000000001'`, además de textos de "repuesto" en etiquetas y notificaciones (`SaleResource`, `ProductResource`, `CreateSale.php:459`).

---

## 3. Modelo de datos propuesto

Como es un proyecto nuevo sin datos de clientes, sí se pueden eliminar columnas. Todo se hace con **migraciones nuevas** (las antiguas no se editan) y cada una tiene un `down()` funcional.

### Catálogo
- `brands`: id, name (único). Corrige H7.
- `seasons`: id, name (por ejemplo "Verano 2027"), is_active.
- `colors`: id, name, hex, sort_order.
- `sizes`: id, name, sort_order. Se cargan con un seeder: XS–XXL, 28–40 y 35–44.
- `size_curves` y `size_curve_items` (curve_id, size_id, sort_order): curvas reutilizables.
- `products`: agregar `brand_id`, `season_id`, `wholesale_price` y `low_stock_threshold` (nullable). **`sale_price` pasa a significar precio minorista**: se conserva el nombre para no tocar decenas de archivos y se cambia solo la etiqueta. Se eliminan `oem_code`, `manufacturer`, `compatibility`, `material` y `brand` (texto). `is_web_visible` pasa a `default(false)`.
- `product_variants`: agregar `color_id`, `size_id`, `barcode` (EAN-13, único) y `wholesale_price` (nullable: si está vacío, hereda del modelo). Índice único `(product_id, color_id, size_id)`. `color_name` y `size` se mantienen como texto de respaldo para tickets y SUNAT.
- `product_images`: agregar `color_id` (nullable) para tener fotos por color.

### Precios y ventas
- `company_settings`: agregar `wholesale_min_qty` (por defecto 2) y `low_stock_threshold` (por defecto 20).
- `customers`: agregar `customer_type` (`minorista` | `mayorista`, por defecto `minorista`).
- `sale_items`: agregar `price_type` (`retail` | `wholesale`), `price_reason` (`quantity` | `authorized` | null) y `list_price` (el precio de lista en ese momento, para reportes).
- `sales`: agregar `wholesale_authorized_by` (FK users, nullable) y `wholesale_authorized_at`.
- `quotation_items`: los mismos campos que `sale_items`.

### Inventario
- `stock_movements` (kardex): warehouse_id, product_variant_id, type (`sale`, `sale_void`, `purchase`, `adjustment`, `transfer_out`, `transfer_in`, `exchange_in`, `exchange_out`), quantity con signo, balance_after, reference (morph), user_id y fecha. **Todo cambio de `inventories.stock` pasa por un único `StockService`**, que bloquea la fila (`lockForUpdate`) y escribe el movimiento.
- `suppliers`: name, ruc, phone.
- `purchases` y `purchase_items`: compra con almacén de destino; las líneas quedan por variante.

---

## 4. Roles y permisos

Se crea un `RolesSeeder` con los roles `administrador`, `vendedor`, `almacenero` y `contador`, más `super_admin`, que queda solo para el proveedor y oculto en `UserResource`. Los permisos usan los de Shield (`view_any_x`, `create_x`…), más permisos propios: `authorize_wholesale_price`, `view_cost_price`, `edit_sale_price` y `export_reports`.

| Módulo | Administrador | Vendedor | Almacenero | Contador |
|---|---|---|---|---|
| Punto de venta / ventas | Todo | Crear, ver las de su tienda | — | Ver |
| Anular / nota de crédito | Sí | — | — | Ver |
| Autorizar precio mayorista (1 prenda) | **Sí (único)** | — | — | — |
| Editar precio en la venta | Sí | No | — | — |
| Caja | Todas las de su tienda | Solo la suya | — | Ver |
| Cotizaciones | Todo | Crear, ver | — | Ver |
| Clientes | Todo | Crear, ver, editar | — | Ver |
| Productos / variantes / precios | Todo | Ver (sin costo) | Ver (sin precios de venta editables) | Ver |
| Colores, tallas, curvas, temporadas | Todo | — | Ver | — |
| Inventario / matriz / stock bajo | Todo | Ver stock | Todo | Ver |
| Compras | Todo | — | Crear, ver | Ver |
| Ajustes de inventario | Todo | — | Crear, ver | Ver |
| Traspasos y guías | Todo | — | Crear, ver | Ver |
| Etiquetas | Sí | — | Sí | — |
| Reportes | Todos | Sus ventas del día | Inventario | Todos, con exportación |
| Usuarios, tiendas, almacenes, series, empresa | Todo | — | — | — |
| Ver costos | Sí | No | Sí | Sí |

Vendedor, almacenero y contador quedan limitados a su tienda (`branch_id`). El administrador ve todas (decisión 1).

---

## 5. Regla de precios (cómo se implementa)

Un solo `PricingService` en el servidor, usado por el punto de venta, `CreateSale` y las cotizaciones:

1. Cuenta las **prendas físicas** de la venta (suma de cantidades; los servicios no cuentan).
2. Si las prendas son ≥ `wholesale_min_qty` (2), todas las líneas van a precio mayorista y el motivo es `quantity`.
3. Si son menos de 2, van a precio minorista, **salvo** que exista una autorización válida: cliente `mayorista` + aprobación de un usuario con `authorize_wholesale_price`. En ese caso el motivo es `authorized`.
4. Precio de cada variante: `variant.wholesale_price ?? product.wholesale_price`, y `variant.sale_price ?? product.sale_price`. **Esto corrige H2.**
5. En `CreateSale::mutateFormDataBeforeCreate` **se recalculan todos los precios** y se ignora el precio que manda el formulario. **Esto corrige H1.**
6. **Autorización:** en el punto de venta aparece la acción "Autorizar precio mayorista" cuando el cliente es mayorista y hay 1 prenda. Abre una ventana donde el administrador ingresa su correo y contraseña (o un PIN; pregunta 6). Se valida en el servidor que tenga el permiso y pertenezca a la tienda, y se guarda en `sales.wholesale_authorized_by/at`. Si se agregan o quitan líneas, la autorización se mantiene solo mientras siga habiendo 1 prenda.
7. Las anulaciones, las notas de crédito y los cambios usan el precio guardado en `sale_items`; nunca recalculan.

---

## 6. Etapas

Cada etapa va en su propia rama (`feat/...`), con un commit por cambio. Al final de cada una hay que pasar `migrate:fresh --seed`, ejecutar las pruebas y dar un resumen. No se fusiona a `main` sin aprobación.

**Etapa 0 — Preparación.** Nuevo `AGENTS.md` con las reglas de este proyecto. Ajustar `.env.example` (`APP_NAME="Rwarehouse Moda"`, base `rwarehouse_moda`, SUNAT en sandbox). Que `migrate:fresh --seed` funcione en una base vacía (H14): una migración nueva (`2026_10_05_000000_ensure_default_branch`) crea la "Tienda Principal" antes de las migraciones de multisucursal, sin editar las antiguas, y `InitialSetupSeeder` crea la configuración de la empresa con SUNAT en sandbox y las series F001, B001 y NV01. Crear la migración de `brands` (H7).

**Etapa 1 — Limpieza de repuestos.** Quitar los campos de repuestos (migración, `ProductResource`, tablas, importadores), el "Repuesto genérico", los textos de "repuesto"/Mangui, `/descargar-manual` y `/ropa` (o dejarla detrás de un interruptor). Quitar el hook de `Product::saved` (H3). `is_web_visible` pasa a `default(false)`.

**Etapa 2 — Roles y permisos.** `RolesSeeder`, permisos propios, políticas revisadas y `super_admin` oculto. Pruebas: cada rol solo ve lo que le corresponde.

**Etapa 3 — Catálogo de moda.** Marcas, temporadas, colores, tallas y curvas (con sus Resources). Formulario de modelo con generador de variantes (colores × curva), SKU `MODELO-COLOR-TALLA`, EAN-13 con dígito verificador y fotos por color. Edición en bloque de precios por variante.

**Etapa 4 — Precios minorista y mayorista.** `PricingService`, tipo de cliente, configuración del mínimo, recálculo en el servidor (H1 y H2), autorización del administrador, campos nuevos en `sale_items` y cotizaciones. Pruebas automáticas de la regla: 1 prenda, 2 prendas, quitar una línea, autorización válida o inválida, y un vendedor que intenta forzar el precio.

**Etapa 5 — Inventario.** `StockService` + `stock_movements`: todas las operaciones de stock (venta, anulación, traspaso, ajuste) pasan por él. Matriz talla × color por almacén, stock bajo (umbral global de 20 y por modelo), widget en el dashboard y reporte de reposición. Se corrige H6. Se decide el stock negativo (H5).

**Etapa 6 — Compras y etiquetas.** Proveedores, compra por curva (paquetes 1-2-2-1 por color) con ingreso al almacén vía `StockService`, e impresión de etiquetas de 50×30 mm con código de barras (PDF).

**Etapa 7 — Punto de venta.** Búsqueda por código de barras o SKU (al escanear, agrega la variante exacta), selector de color y talla con stock, indicador "Minorista / Mayorista", y descripción para SUNAT con el formato `Modelo – Color – Talla` (`SunatService.php:216` ya usa `product_name`; solo cambia cómo se arma).

**Etapa 8 — Cambios de talla o color.** Nota de crédito parcial + nueva boleta (decisión 7).

**Etapa 8b — Ventas a crédito para mayoristas.** Ver §8.

**Etapa 9 — Reportes.** Ventas por talla, color, temporada y canal; precios mayoristas autorizados; stock bajo; curva rota; stock sin movimiento. Todos con exportación a Excel.

---

## 7. Riesgos

- **SUNAT:** el cálculo del IGV se mantiene igual (precio con IGV ÷ 1.18). Cambiar quién define el precio no debe alterar los totales que se envían. Hay que probar en sandbox boletas y facturas con precio mayorista y minorista, y notas de crédito.
- **Caja:** el total cobrado debe salir del total recalculado en el servidor. Esto evita repetir la diferencia de caja que hubo en Mangui (ventas guardadas con un pago de S/0.00).
- **Stock:** al pasar todo por `StockService`, hay que revisar cada lugar que hoy hace `increment`/`decrement` directamente (`CreateSale`, `SaleAnulacionService`, traspasos y la reemisión).
- **Regla de precios:** es el punto más fácil de saltarse. Precio no editable para el vendedor, recálculo en el servidor y pruebas automáticas.
- **`SaleResource` mide 1,771 líneas.** Los cambios del punto de venta conviene hacerlos en una página propia de Filament, en vez de seguir ampliando ese formulario.

---

## 8. Decisiones del cliente (2 de noviembre de 2026)

| # | Tema | Decisión | Cómo queda en el sistema |
|---|---|---|---|
| 1 | Alcance del administrador | Ve y opera **todas las tiendas** | El rol `administrador` no se filtra por `branch_id` (como `super_admin` hoy) |
| 2 | Precio manual | El administrador **sí puede editar el precio** en una venta | Permiso `edit_sale_price` solo para el administrador; la línea queda con `price_reason = manual` y el usuario que lo cambió. El vendedor nunca edita precios |
| 3 | Venta rápida | **Se elimina**, pero queda registro | Ya no se pueden crear ventas rápidas. Las que existan siguen visibles, solo lectura, con la etiqueta "Venta rápida (función retirada)". El retiro queda anotado en la bitácora del sistema |
| 4 | Servicios | Solo **delivery**, y solo en algunos productos | El módulo de servicios se reduce a delivery. El modelo tiene la casilla `permite_delivery`, y el delivery solo se puede agregar si la venta incluye al menos un producto que lo permita. El delivery **no cuenta** para el mínimo de 2 prendas |
| 5 | Stock negativo | **No, para nadie** | La venta se bloquea en el servidor si el stock del almacén no alcanza, con el bloqueo de fila al descontar |
| 6 | Autorización de 1 prenda | **PIN** + aviso al administrador | El vendedor envía una *solicitud de precio mayorista*. El administrador recibe una notificación en el sistema (campana y lista "Aprobaciones pendientes") y aprueba o rechaza con su PIN de 4–6 dígitos (guardado cifrado). El administrador también puede ingresar su PIN directamente en el punto de venta si está presente |
| 7 | Cambios de talla o color | **Nota de crédito + nueva boleta** | Hace falta una nota de crédito **parcial** (por ítem), que hoy no existe (H11) |
| 8 | Crédito para mayoristas | **Sí, se agrega** | Nueva etapa de cuentas por cobrar (ver abajo) |
| 9 | Mínimo de prendas | **Cualquier combinación de modelos**, configurable | `company_settings.wholesale_min_qty` (por defecto 2) |
| 10 | Stock bajo | **Por talla y color** en cada almacén | Umbral global de 20, con excepción opcional por modelo |
| 11 | Tienda web `/ropa` | **Se desactiva** por ahora | Ruta eliminada; `is_web_visible` queda para el futuro |

### Cambios al plan por estas decisiones

- **Etapa 4:** se agregan la tabla `wholesale_price_requests` (venta en borrador, cliente, ítems, solicitado por, estado pendiente/aprobado/rechazado, aprobado por, fecha) y `users.approval_pin` (hash). Notificaciones en la base de datos de Filament para los administradores. La venta no se puede emitir mientras su solicitud esté pendiente.
- **Etapa 5:** sin stock negativo (decisión 5).
- **Etapa 8:** nota de crédito parcial (tipo 07, devolución por ítem) + nueva venta, enlazadas como "cambio". El stock devuelto entra con un movimiento `exchange_in`.
- **Nueva etapa 8b — Ventas a crédito para mayoristas:**
  - `customers`: `credit_enabled`, `credit_limit` y `credit_days`.
  - `sales`: `payment_condition` (`contado` | `credito`) y `due_date`. La factura se envía a SUNAT con **forma de pago Crédito y sus cuotas**, como exige SUNAT.
  - `customer_payments` (abonos): ingresan a la caja del día como un movimiento.
  - Saldo por cliente, aviso cuando se pasa del límite (bloqueo; solo el administrador puede exceptuarlo), reporte de cuentas por cobrar y vencidas.
  - Solo clientes mayoristas con crédito habilitado por el administrador.
