# Reglas Permanentes del Proyecto (Rwarehouse Moda)

Sistema de gestión para tiendas de ropa minoristas y mayoristas (tallas, colores, variantes), con multisucursal y facturación electrónica SUNAT. Laravel 12 + Filament 3 + Greenter. El plan y las decisiones del cliente están en `PLAN_MODA.md`.

- **Origen y separación:** El código nació de una copia del sistema de Mangui Perú (repuestos), pero es un proyecto independiente. Nunca hagas push, commit ni cambios en el repositorio de Mangui Perú, ni te conectes a su base o a su dominio.
- **Bases de datos y SUNAT:** Trabaja solo con la base local `rwarehouse_moda` (sin datos reales de clientes). Antes de cualquier prueba que emita comprobantes, confirma con un `SELECT` que `company_settings.sunat_sandbox = 1`.
- **Base de datos y migraciones:** Nunca ejecutes `migrate:fresh`, `migrate:reset`, `migrate:refresh` ni `db:wipe` en ninguna base. Para verificar migraciones usa solo `php artisan migrate --pretend --path=<archivo>`. Cambios nuevos siempre en migraciones nuevas, cada una con un `down()` funcional. Muestra el SQL con `php artisan migrate --pretend --path=<archivo>` antes de aplicar. Mientras el proyecto no tenga base en producción, se permite eliminar columnas o tablas heredadas de repuestos.
- **Flujo de aprobación:** Antes de cambiar código, muestra el plan con el antes y el después, y espera aprobación.
- **Git y ramas:** Trabaja siempre en una rama nueva por etapa (`feat/...`). Nunca fusiones ni subas a `main` sin aprobación, y haz un commit por cambio.
- **Secretos:** Nunca subas `.env`, certificados (`.pfx`, `.pem`, `.p12`, `.key`), archivos `.sql` ni credenciales escritas en el código. No crees scripts PHP sueltos en `public/` ni rutas protegidas con un token fijo: el mantenimiento se hace con comandos `artisan`.
- **Precios y stock:** Los precios de venta se calculan en el servidor (regla minorista/mayorista); nunca se confía en el precio que manda el formulario. No se permite stock negativo. Todo cambio de stock pasa por el servicio de stock y deja un movimiento.
- **Privacidad:** No muestres contraseñas, hashes ni datos sensibles en tus respuestas.
- **Prioridad de reglas:** Las reglas de este proyecto tienen prioridad sobre cualquier skill, incluida ponytail.
