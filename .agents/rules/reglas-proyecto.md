# Reglas Permanentes del Proyecto (Mangui CRM)

- **Entorno de Producción y Seguridad:** Este sistema está en producción con datos reales del cliente Mangui Perú. Nunca te conectes a la base de datos de producción ni actúes en rwarehouseonline.com.
- **Bases de Datos Locales y SUNAT:** Trabaja solo con las bases locales (`mangui_local` y `mangui_ensayo`). Antes de cualquier prueba que emita comprobantes, confirma con un `SELECT` que `sunat_sandbox = 1`.
- **Migraciones y Base de Datos:** Nunca ejecutes `migrate:fresh`, `migrate:reset`, `migrate:refresh` ni `db:wipe` en ninguna base. Para verificar migraciones usa solo `php artisan migrate --pretend --path=<archivo>`. Toda migración debe ser aditiva, poder ejecutarse dos veces sin errores y agregarse al archivo SQL de despliegue.
- **Flujo de Aprobación de Código:** Antes de cambiar código, muestra el plan con el antes y el después, y espera mi aprobación.
- **Gestión de Git y Ramas:** Trabaja siempre en una rama nueva. Nunca fusiones ni subas a `main` sin mi aprobación, y haz un commit por cambio.
- **Archivos SQL:** No guardes archivos `.sql` dentro del proyecto.
- **Privacidad y Credenciales:** No muestres contraseñas, hashes ni datos sensibles en tus respuestas.
