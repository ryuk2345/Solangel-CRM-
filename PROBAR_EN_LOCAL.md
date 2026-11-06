# Probar Rwarehouse Moda en local

## 1. Actualizar el código y la base

```bash
cd ~/Documents/rwarehouse-moda
git checkout feat/etapa-0-instalacion
git pull
composer install
php artisan migrate:fresh --seed        # BORRA la base local rwarehouse_moda y la crea de cero
php artisan db:seed --class=DemoDataSeeder
php artisan storage:link
php artisan serve
```

- `migrate:fresh` borra todo lo de la base **local** `rwarehouse_moda`. Nunca lo ejecutes contra una base de un cliente.
- La contraseña de los usuarios demo sale en la terminal una sola vez. Si prefieres una fija, antes de sembrar pon en tu `.env`:
  `DEMO_PASSWORD=la-que-quieras` (y opcional `DEMO_PIN=1234`).
- SUNAT queda en modo de pruebas (sandbox). Sin certificado, las boletas y facturas quedan "pendiente"; para probar todo sin SUNAT usa **Nota de venta (NV01)**.

## 2. Usuarios demo

| Usuario | Rol | Qué puede hacer |
|---|---|---|
| administrador@demo.test | Administrador | Todo, todas las tiendas; aprueba precios con PIN **1234** |
| vendedor@demo.test | Vendedor | Ventas, su caja, clientes, cambios de talla |
| almacenero@demo.test | Almacenero | Compras, inventario, traspasos, etiquetas |
| contador@demo.test | Contador | Solo lectura y reportes |

Clientes demo: **Clientes varios** (minorista) y **BOUTIQUE MAYORISTA DEMO S.A.C.** (mayorista con crédito de S/ 3,000 a 30 días).

## 3. Recorrido sugerido

1. **Vendedor → Cajas → Abrir caja.**
2. **Ventas → Crear:** cliente "Clientes varios", luego "Escanear / código" o "Elegir por modelo".
   - Con 1 prenda: *PRECIO MINORISTA*. Con 2 o más (de cualquier modelo): *PRECIO MAYORISTA*.
   - El vendedor no puede cambiar el precio; el administrador sí.
   - Delivery solo si alguna prenda lo permite (Polo, Jean y Blusa sí; Casaca no).
3. **1 prenda a precio mayorista:** cliente mayorista + 1 prenda → "Pedir aprobación al administrador".
   El administrador ve la campana y **Aprobaciones de precio**, y aprueba con su PIN (o "Aprobar aquí con PIN" en la venta).
4. **Venta a crédito:** cliente mayorista → Condición de pago "Crédito" → Nota de venta o Factura. Luego **Cuentas por cobrar → Registrar abono**.
5. **Cambio de talla:** en Ventas, menú de la venta → "Cambio / devolución" → elige la prenda devuelta → registra la prenda nueva (el saldo a favor ya está aplicado).
6. **Almacenero:** Compras → "Agregar por curva" → Etiquetas (PDF 50 × 30 mm). Inventario → Ajustar, Kardex, Matriz talla × color, Stock bajo.
7. **Reportes (administrador / contador):** Ventas por talla y color; Curva rota y stock sin movimiento.

## 4. Pruebas automáticas

```bash
mysql -u root -e "CREATE DATABASE IF NOT EXISTS rwarehouse_moda_test"
php artisan test
```
