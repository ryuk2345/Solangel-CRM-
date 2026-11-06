# Especificación: orden del sistema y kardex valorizado

Fuente: propuesta comercial firmada con Solangel Perú (RAFFÜSS, octubre 2026) y el Excel de kardex que usa el cliente.
Objetivo: que el sistema se vea y se use como lo ofrecimos. Menos módulos sueltos, todo agrupado por tarea, y un kardex que se lea igual que el Excel del contador.

---

## 1. Lo que ofrecimos (Componente 1 · Software a medida)

- Ventas, cajas y punto de venta con código de barras
- Inventario por modelo, talla y color
- Almacenes, sucursales y traspasos
- Listas de precio por tipo de cliente (minorista y mayorista automáticas)
- Kardex interno **exportable para su contador**, y tablero en tiempo real
- Facturador electrónico, DNI/RUC que autocompletan, cierre de caja diario en PDF, alertas de bajo stock por talla, acceso por roles desde computadora o celular

Menú que mostramos en la propuesta (imagen de la página 5):

```
VENTAS Y CAJAS          CATÁLOGO E INVENTARIO        ADMINISTRACIÓN
  Panel                   Productos                    Sucursales
  Ventas                  Inventario por talla         Guías de remisión
  Cajas                   Traspasos de stock           Kardex
  Clientes                Almacenes                    Auditoría de ventas
```

Panel de la propuesta: tarjetas "Ventas de hoy (S/)", "Unidades vendidas", "Variantes activas" y "Alertas de stock"; tabla "Stock por talla" (modelo, color, S, M, L, XL, precio min., precio may., con las tallas bajas en naranja/rojo); "Caja Tienda 1 · cierre" y "Traspaso almacén → Tienda 2 · en ruta".

---

## 2. Menú objetivo (de ~36 entradas a ~16)

No se borra ninguna función: se agrupa o se mueve adentro de la pantalla donde se usa.

| Grupo | Entrada | Qué agrupa |
|---|---|---|
| **Ventas y cajas** | Panel | Tablero (ver §4) |
| | Ventas | Lista de ventas con **pestañas**: Todas · Por cobrar · Con nota de crédito · Cotizaciones. Acciones de cabecera: "Nueva venta", "Nueva cotización", "Aprobaciones de precio" (con contador de pendientes). |
| | Cajas | Igual que hoy |
| | Clientes | Igual que hoy; dentro del cliente, pestañas "Compras" y "Abonos" |
| | Cambios y devoluciones | Igual que hoy |
| **Catálogo e inventario** | Productos | Modelos. Dentro: variantes (talla × color), precios, fotos y la acción "Imprimir etiquetas". **Se quita del menú "Variantes (SKU)"**. |
| | Inventario por talla | **Una sola pantalla** con pestañas: *Matriz talla × color* (vista principal, como la imagen), *Lista* (con "Ajustar") y *Stock bajo*. Se quitan del menú "Matriz talla × color" y "Stock bajo". |
| | Compras | Compras; proveedores en una pestaña de la misma pantalla. **Se quita del menú "Proveedores"**. |
| | Traspasos de stock | Igual que hoy |
| | Almacenes | Se mueve aquí desde Administración |
| **Administración** | Sucursales | Igual |
| | Guías de remisión | Igual |
| | Kardex | **Kardex valorizado** como el Excel (ver §3) |
| | Auditoría de ventas | Igual |
| | Reportes | **Una sola pantalla** con pestañas: Ventas por talla y color · Curva rota y sin movimiento · Cuentas por cobrar (con abonos) · Costo de ventas (consolidado). Se quitan del menú las 4 entradas sueltas. |
| **Configuración** (colapsado) | Catálogos | Un *Cluster* de Filament con Colores, Tallas, Curvas, Temporadas, Marcas, Categorías y Delivery (7 entradas → 1) |
| | Empresa y SUNAT | Empresa + Series de comprobantes |
| | Usuarios y roles | Usuarios + Roles (Shield) |

Reglas:
- Lo que se quita del menú sigue existiendo y se abre desde su pantalla padre (pestaña, botón o enlace). No se borran Resources: se usa `shouldRegisterNavigation = false` o *clusters*.
- Cada rol ve solo lo que le corresponde (permisos actuales). Un grupo vacío no se muestra.
- "Aprobaciones de precio" no ocupa una entrada del menú: llega por la campana de notificaciones y por el botón en Ventas.

---

## 3. Kardex valorizado (formato del Excel del cliente)

### 3.1 Cómo es el Excel

Hoja por categoría (Blazer, Blusas, Camisas, Faldas, Pantalones, Top, Vestidos), con un bloque por SKU. Título de cada bloque:

```
THEVS21SILBLONUXL - Camisas - SILKY OVERSIZE BLOUSE - XL - Nude
LIBRO DE ALMACEN O KARDEX-VALORADO
```
Es decir: **SKU - Categoría - Modelo - Talla - Color**.

Columnas:

| ITEM | FECHA | DOCUMENTO: GUÍA REMISIÓN | DOCUMENTO: COMPROB. PAGO | DETALLE | ENTRADAS: CANT. · P.U. · P.T. | SALIDAS: CANT. · P.U. · P.T. | SALDOS: CANT. · P.U. · P.T. |
|---|---|---|---|---|---|---|---|

Ejemplo del Excel (método promedio):

| Item | Fecha | Guía | Comprobante | Detalle | Ent. cant | Ent. P.U. | Ent. P.T. | Sal. cant | Sal. P.U. | Sal. P.T. | Saldo cant | Saldo P.U. | Saldo P.T. |
|---|---|---|---|---|---|---|---|---|---|---|---|---|---|
| 1 | | | | SALDO INICIAL AL MES DE ENERO 2026 | | | | | | | 150 | 10.00 | 1,500.00 |
| 2 | | 001-005 | 001-0225 | Compra a Proveedor "Satipo S.A." | 150 | 10.10 | 1,515.00 | | | | 300 | 10.05 | 3,015.00 |
| 4 | | ---- | ---- | Ventas a la fecha | | | | 550 | 10.0123 | 5,506.77 | 100 | 10.0123 | 1,001.23 |
| 5 | | ---- | ---- | Devolución por parte de cliente | | | | **−150** | 10.0123 | −1,501.85 | 250 | 10.0123 | 2,503.08 |
| 11 | | 123-045 | 054-012 | Devolución de mercadería dañada (a proveedor) | **−200** | 10.20 | −2,040.00 | | | | 895 | 10.1491 | 9,083.45 |

Al pie de cada bloque:

```
COMPROBACIÓN: TOTALES        Σ ent. cant | Σ ent. P.T. | Σ sal. cant | Σ sal. P.T. | saldo cant | saldo P.T.
INV. INICIAL                 1,500.00
(+) COMPRAS                 33,653.00
(−) INV FINAL               −3,610.37
(=) COSTO DE VENTAS         31,542.63        DIFERENCIA 0.00
```
(Costo de ventas = Σ salidas P.T.; la diferencia entre ambos cálculos debe ser 0.)

Hoja **CONSOLIDADO** del mes: Inv. inicial, (+) Compras, (−) Inv. final y (=) Costo de ventas, sumando todos los SKU.

### 3.2 Reglas de valorización (método promedio ponderado móvil)

| Movimiento del sistema | Columna | Cantidad | P.U. |
|---|---|---|---|
| Compra (`purchase`) | Entradas | + | Costo unitario de la compra |
| Compra anulada / devolución a proveedor (`purchase_void`) | Entradas | **negativa** | Costo de esa compra |
| Stock inicial (`initial`) | Entradas | + | Costo de la variante (`purchase_price`; si no hay, el del modelo) |
| Ajuste positivo (`adjustment` > 0) | Entradas | + | Costo promedio vigente (o el de la variante si el saldo es 0) |
| Venta (`sale`) | Salidas | + | Costo promedio vigente |
| Anulación de venta, cambio o devolución de cliente (`sale_void`, `exchange_in`) | Salidas | **negativa** | Costo promedio vigente |
| Ajuste negativo, merma (`adjustment` < 0) | Salidas | + | Costo promedio vigente |
| Traspaso salida (`transfer_out`) | Salidas | + | Promedio del almacén de origen |
| Traspaso entrada / devolución de traspaso (`transfer_in`, `transfer_return`) | Entradas | + | El mismo P.U. con que salió del origen |

Después de cada movimiento:
- **Entrada:** saldo P.T. = saldo P.T. anterior + P.T. de la entrada; saldo P.U. = saldo P.T. / saldo cant.
- **Salida:** P.T. = cant × P.U. promedio; saldo P.T. = saldo P.T. anterior − P.T.; el P.U. se mantiene.
- Si el saldo queda en 0, el saldo P.T. queda en 0 (sin residuos de redondeo).
- El kardex se valoriza **por variante y almacén**.

### 3.3 Datos que faltan hoy

Hoy `stock_movements` guarda tipo, cantidad y saldo en unidades, pero **no los costos ni los documentos**. Agregar (migración nueva, aditiva):

| Columna | Tipo | Uso |
|---|---|---|
| `unit_cost` | decimal(12,4) | P.U. del movimiento |
| `total_cost` | decimal(14,2) | P.T. del movimiento (con signo) |
| `balance_unit_cost` | decimal(12,4) | P.U. del saldo (promedio) |
| `balance_total_cost` | decimal(14,2) | P.T. del saldo |
| `guide_document` | string(30), nullable | Guía de remisión (traspasos con guía, compras con guía) |
| `payment_document` | string(30), nullable | Comprobante (venta: serie-correlativo; compra: n.º de factura/boleta del proveedor; nota de crédito) |

- `StockService::move()` calcula y guarda el costo en el mismo bloqueo de fila. Recibe un costo opcional (compras, traspasos); si no llega, usa el promedio vigente.
- Comando `php artisan kardex:revalorizar`: recalcula costos y documentos de los movimientos existentes en orden cronológico. Es idempotente: se puede ejecutar varias veces sin cambiar el resultado.

### 3.4 Pantalla "Kardex" (Administración)

- Filtros: **Periodo** (mes y año; por defecto el mes actual), **Almacén** (o todos), **Categoría**, **Modelo**, **SKU** (buscador por SKU o código de barras) y la opción "Agrupar ventas por día" (activa por defecto, como "Ventas a la fecha" del Excel).
- Se muestra un bloque por SKU, igual al Excel: título `SKU - Categoría - Modelo - Talla - Color`, la fila "SALDO INICIAL AL MES DE …", las filas del mes, la fila de totales y el cuadro de comprobación (Inv. inicial, Compras, Inv. final, Costo de ventas, Diferencia).
- Encabezado de la tabla con las dos filas del Excel (ENTRADAS / SALIDAS / SALDOS y CANT. · P.U. · P.T.). Cantidades negativas en rojo. P.U. con 2 decimales en pantalla (4 internamente).
- Arriba, el **resumen del periodo** (CONSOLIDADO): Inv. inicial, Compras, Inv. final y Costo de ventas de todo lo filtrado.
- **Exportar a Excel (.xlsx)** con el mismo diseño: una hoja por categoría con un bloque por SKU, y una hoja "CONSOLIDADO". Usar `phpoffice/phpspreadsheet`. Encabezados con bordes y celdas combinadas como el Excel, números con 2 decimales y fechas `dd/mm/aaaa`.
- Pueden verlo **Administrador y Contador**. El Almacenero ve las cantidades, pero **sin costos** (no tiene `view_cost_price`).
- La lista actual de movimientos (StockMovementResource) queda como pestaña "Movimientos" dentro de Kardex, para auditoría.

---

## 4. Panel (tablero en tiempo real)

Como la imagen de la propuesta, respetando la tienda del usuario (el administrador elige la tienda o ve todas):

1. **Tarjetas:** Ventas de hoy (S/), Unidades vendidas hoy, Variantes activas, Alertas de stock (clic → Inventario › Stock bajo).
2. **Stock por talla:** selector de categoría; filas modelo + color, columnas de talla, precio mín. y precio may. Tallas en el umbral o por debajo en naranja; agotadas en rojo.
3. **Caja:** estado de la caja de la tienda (abierta o cuadrada, con su monto).
4. **Traspasos en ruta:** cuántos traspasos están en tránsito y cuántas unidades.
5. Últimas ventas (el widget actual) al final.

El vendedor ve las tarjetas de su tienda, el stock por talla y su caja. El almacenero ve stock y traspasos. Ninguno de los dos ve costos.

---

## 5. Diferencias con la propuesta para conversar con el cliente

- La propuesta dice "precio minorista y mayorista **sin descuentos manuales**". En la reunión el cliente pidió que el **administrador** sí pueda poner precio a mano. Así está hecho: solo el administrador, y queda registrado quién lo cambió.
- Ventas a crédito y cotizaciones no estaban en la propuesta; se agregaron por pedido del cliente. Quedan dentro de Ventas, sin entradas propias en el menú.
- La tienda virtual (Componente 3) no es parte de este trabajo.
