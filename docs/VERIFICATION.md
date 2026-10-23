# Verificación

Fecha de comprobación: **23 de octubre de 2026**.

## Ejecutado en el entorno local

PHP **8.5.12**, MariaDB **11.4.5**, Windows. Base de datos de la aplicación y base de datos de pruebas independientes. Se utilizó un usuario de aplicación con permisos `SELECT`, `INSERT` y `UPDATE` para comprobar las operaciones reales, separado de la credencial administrativa de pruebas.

| Comprobación | Resultado |
| --- | --- |
| Importación de `database/database.sql` | 16 reservas y sus eventos importados correctamente |
| Sintaxis PHP | 29 ficheros correctos |
| PHPUnit | 71 pruebas, 197 aserciones, sin fallos ni omisiones |
| PHPStan | Nivel 8, sin errores ni baseline |
| PHP CS Fixer | PSR-12, sin cambios pendientes |
| Playwright | Flujos comprobados en escritorio y móvil; 13 escenarios por dispositivo |
| axe | Sin infracciones detectadas de las etiquetas WCAG 2 A/AA y 2.1 AA en listado, formulario, detalle, vista rápida, agenda y confirmación de lote |
| Prettier | Sin cambios pendientes |
| Composer audit | Sin avisos de vulnerabilidad en las dependencias instaladas |

Se verificaron creación y estado inicial, validación de tipos/fechas/email/dinero, límites, rechazo de campos inesperados, filtros combinados, coincidencia de estancias, búsqueda literal de `%`/`_`, paginación, importes exactos y respuesta a IDs inexistentes.

Tras ampliar el espacio de gestión se repitieron los flujos de navegador y los controles de accesibilidad. Se comprobaron edición desde la vista rápida, historial actualizado, confirmación/cancelación de una selección, ordenación y filtro por alojamiento, descarga real del CSV a través de varias páginas y exclusión de canceladas en la agenda. Las pruebas de integración comprueban también el rollback completo de un lote con una versión obsoleta, y el de una edición cuyo evento falla. La prueba de exportación verifica comillas, Unicode, saltos de línea y neutralización de fórmulas. La prueba de noches cubre los cambios de horario de primavera y otoño.

Las pruebas de integración introducen un fallo real mediante un trigger en la base de pruebas: tanto la creación como el cambio de estado se revierten si no puede guardarse el evento. También comprueban rechazo de una versión obsoleta, ausencia de eventos duplicados y sanitización de errores de base de datos.

El navegador recorre creación, validación cliente y errores por campo del servidor, filtros sin navegación de documento, confirmación, cancelación con confirmación, historial, conflictos de versión, errores 500 y de red, recuperación, XSS mediante texto peligroso y paginación de URLs compartidas. Se revisaron capturas de escritorio y móvil. Se corrigió y verificó un desbordamiento que provocaba una etiqueta visualmente oculta de la tabla.

## Integración continua

[Ejecuciones de Quality en GitHub](https://github.com/guiller91/Proyecto-Dani/actions/workflows/ci.yml).

La matriz verifica PHP 8.3/MariaDB 11.4, PHP 8.4/MySQL 8.4 y PHP 8.5/MariaDB 11.4. Cada combinación instala desde los lockfiles, importa una base vacía, ejecuta los controles PHP y los escenarios Playwright. Un trabajo independiente construye la imagen Docker, arranca el Compose e inspecciona la API y la vista renderizada.

Los resultados de CI son consultables en el enlace anterior. La configuración de una matriz por sí sola no se considera evidencia de que haya pasado; revisar el resultado de la ejecución asociada al commit entregado.

## Límites de la verificación

- La emulación móvil de Chromium comprueba viewport y comportamiento táctil; no es una ejecución en un iPhone físico ni en Safari.
- axe automatizado no certifica accesibilidad completa. Se comprobó también visualmente la distribución de las vistas, el desplazamiento de la tabla y el formulario móvil.
- El caso de versión obsoleta reproduce dos clientes que leyeron la misma versión. No se presenta como una prueba de carga con múltiples procesos simultáneos.
- No se ha medido rendimiento bajo carga de producción.
- La URL pública está pendiente de hosting. Las pruebas locales, el contenedor y GitHub CI no sustituyen la comprobación final del hosting real.
