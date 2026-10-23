# Despliegue

El destino debe ejecutar PHP 8.3+ y proporcionar MySQL/MariaDB o conectividad a una instancia existente. GitHub Pages no ejecuta PHP. Se incluyen dos opciones; la URL pública definitiva está pendiente de un hosting asignado.

## Hosting con Apache/PHP

1. Crear base de datos y usuario desde el panel. Importar `database/database.sql` en la base vacía.
2. Ejecutar `composer install --no-dev --prefer-dist --no-interaction --optimize-autoloader` localmente o durante el despliegue con PHP compatible. Subir también `vendor/` si el hosting no dispone de Composer.
3. Subir `src/`, `templates/`, `public/`, `vendor/`, `var/`, `bootstrap.php` y los ficheros de Composer. Configurar variables del entorno o un `.env` privado con `APP_ENV=production`, conexión y zona horaria.
4. Establecer el DocumentRoot en **`public/`**. El resto del proyecto, especialmente `.env`, debe quedar fuera del directorio servido. Si el panel solo permite `public_html`, colocar allí el contenido de `public/` y el resto un nivel por encima; adaptar la ruta de `bootstrap.php` en `index.php` si se utiliza otra distribución.
5. Permitir `.htaccess`, habilitar `mod_rewrite` y asegurar que `var/` sea escribible por PHP. No usar permisos 777. Activar HTTPS; la detección nativa de HTTPS controla el flag Secure de la cookie.
6. Aplicar permisos de ejecución de base de datos: `SELECT`, `INSERT`, `UPDATE`. Mantener otra credencial para futuras migraciones.
7. Verificar listado, creación, detalle, confirmación, cancelación, historial, filtros combinados y respuesta ante IDs inexistentes.

No publicar `tests/`, `.git/`, `.tools/`, `.env.test`, informes de pruebas ni credenciales administrativas.

## Contenedor en VPS o plataforma Docker

El `Dockerfile` incluye Apache, PHP, extensiones y dependencias de producción. `compose.yaml` añade MariaDB persistente y la importación inicial. El origen local por defecto es `127.0.0.1:8080`.

```sh
docker compose up --build -d
docker compose logs -f
```

En un VPS, situar un proxy HTTPS delante del puerto local 8080 y configurar el dominio. Si el proxy termina TLS, configurar **en el servidor** la señal HTTPS que recibe PHP para activar cookies Secure; no confiar ciegamente en `X-Forwarded-Proto` enviado por el cliente. El contenedor incluye un ejemplo de virtual host HTTP y no configura un certificado ni un dominio.

Para una plataforma que construya solo el Dockerfile, configurar `DB_HOST`, `DB_PORT`, `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD`, `APP_ENV=production` y `APP_TIMEZONE`. Proporcionar un volumen o una ruta escribible para `var/`, e importar el SQL antes de enviar tráfico. La imagen sirve el puerto 80. Si se usan varios nodos, compartir sesiones o configurar afinidad de sesión.

## Operación

- No utilizar el servidor integrado `php -S` en producción.
- La demo permite acceso sin login. Usar datos ficticios o protegerla mediante autenticación antes de introducir datos reales.
- Hacer backup de base de datos y comprobar restauraciones. El volumen Docker no constituye un backup.
- El contenedor registra errores en stderr y peticiones en stdout. Revisar la política de acceso y retención de logs: los access logs pueden incluir filtros en la URL.
- Los fallos internos generan una referencia de petición sin datos del huésped ni SQL en el log de aplicación. No hay una pantalla pública con trazas.
- No reutilizar las contraseñas de ejemplo. Guardar credenciales como secretos del hosting; no en Git.
- Para actualizar código con OPcache sin validación de timestamps, reconstruir/reiniciar el contenedor.
- No reimportar `database.sql` sobre una base existente: es una instalación inicial, no una migración incremental.
