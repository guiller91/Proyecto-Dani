# API HTTP

Base: mismo origen que la web, prefijo `/api`. JSON UTF-8. No se necesita una clave de proveedor ni autenticación en esta demo. Los datos son ficticios y todos los visitantes comparten el mismo espacio de trabajo.

## Sesión y CSRF

`GET /api/session` devuelve `200` con `{"csrf_token":"…"}` y una cookie de sesión. Conservar esa cookie y enviar el token en `X-CSRF-Token` para POST/PATCH. En la web se obtiene del `<meta name="csrf-token">`. Un token de otra sesión no es válido.

```sh
curl -c cookies.txt http://127.0.0.1:8080/api/session
# Copiar el csrf_token de la respuesta; no es una credencial persistente.
```

## Listado

`GET /api/reservas?estado=confirmada&desde=2028-06-01&hasta=2028-06-30&q=Lucia&page=1&per_page=10`

Todos los parámetros son opcionales:

| Parámetro | Contrato |
| --- | --- |
| `estado` | `pendiente`, `confirmada`, `cancelada`; vacío equivale a todos |
| `desde` | Día inclusivo inicial; la estancia debe terminar después de ese día |
| `hasta` | Día inclusivo final; la estancia debe empezar ese día o antes |
| `q` | Nombre del huésped, coincidencia parcial literal, máximo 120 caracteres |
| `page` | Entero de 1 a 100000; por defecto 1, se ajusta al último disponible |
| `per_page` | Entero de 1 a 100; por defecto 10 |
| `alojamiento` | Nombre exacto del alojamiento, hasta 160 caracteres |
| `entrada` | Fecha exacta de entrada `AAAA-MM-DD` |
| `salida` | Fecha exacta de salida `AAAA-MM-DD` |
| `orden` | `recientes` (defecto), `entrada`, `salida`, `importe_desc`, `huesped` |

Fechas reales `AAAA-MM-DD`, años 1000–9999. `desde <= hasta`. Orden por creación DESC e ID DESC.

```json
{
  "data": [
    {
      "id": 42,
      "nombre_huesped": "Lucía Martín",
      "email_huesped": "lucia@example.test",
      "nombre_alojamiento": "Casa del Olivo",
      "fecha_entrada": "2028-06-10",
      "fecha_salida": "2028-06-13",
      "estado": "confirmada",
      "importe": "345.00",
      "notas": "Llegada a las 18:00.",
      "fecha_creacion": "2028-05-10T12:30:00Z",
      "version": 2
    }
  ],
  "meta": {"total": 1, "page": 1, "per_page": 10, "last_page": 1},
  "summary": {"total": 1, "pendientes": 0, "confirmadas": 1, "canceladas": 0, "importe_activo": "345.00"}
}
```

Un listado sin resultados devuelve `200`, `data: []`, `total: 0`, `page: 1`, `last_page: 1`. El resumen abarca todos los resultados de los filtros, no solo la página.

## Detalle

`GET /api/reservas/42` devuelve `200` con `{"data": {…reserva, "eventos": […]}}`. Cada evento incluye `id`, `reserva_id`, `tipo`, `descripcion` y `fecha_creacion` UTC. Orden ascendente por fecha e ID. Un ID inexistente devuelve `404`.

## Crear

`POST /api/reservas`, con cookie de sesión, `X-CSRF-Token` y `Content-Type: application/json`.

```json
{
  "nombre_huesped": "Lucía Martín",
  "email_huesped": "lucia@example.test",
  "nombre_alojamiento": "Casa del Olivo",
  "fecha_entrada": "2028-06-10",
  "fecha_salida": "2028-06-13",
  "importe": "345.00",
  "notas": "Llegada a las 18:00."
}
```

Nombre hasta 120 caracteres, email válido hasta 254, alojamiento hasta 160, notas opcionales hasta 2000. Se recortan espacios exteriores. La salida debe ser posterior a la entrada. Importe entre `"0"` y `"99999999.99"`, máximo dos decimales; se devuelve siempre con dos. Se aceptan enteros JSON, pero un importe con fracción debe enviarse como cadena decimal con punto. No enviar `id`, `estado`, `version`, `fecha_creacion` ni campos adicionales.

Respuesta `201`, cabecera `Location: /api/reservas/42` y reserva con historial en `data`. Siempre empieza pendiente y con versión 1.

```sh
curl -b cookies.txt -X POST http://127.0.0.1:8080/api/reservas \
  -H 'Content-Type: application/json' \
  -H 'X-CSRF-Token: TOKEN_DE_LA_SESION' \
  --data-binary @reserva.json
```

Guardar el JSON anterior en `reserva.json`. En Windows puede utilizarse `curl.exe`.

## Confirmar o cancelar

`PATCH /api/reservas/42/estado`

```json
{"estado": "cancelada", "version": 2}
```

Enviar la versión recibida en el último listado/detalle. Es un entero JSON obligatorio. El estado de destino debe ser `confirmada` o `cancelada`. Devuelve `200` con el detalle actualizado y el historial. Si ha cambiado desde la lectura, devuelve `409`: volver a consultar antes de repetir. Una cancelación no se reactiva. Repetir el estado actual con versión actual no crea otro evento.

## Errores

```json
{
  "error": {
    "message": "Revisa los campos indicados.",
    "fields": {"fecha_salida": "La salida debe ser posterior a la entrada."},
    "request_id": "0c75d24ef785ed7c"
  }
}
```

`fields` siempre es un objeto; `_form` representa errores generales de campos. `request_id` coincide con `X-Request-ID`. No se devuelven SQL, trazas, contraseñas ni mensajes internos del motor.

| Código | Significado |
| --- | --- |
| `200` | Consulta o cambio correcto |
| `201` | Reserva creada |
| `400` | JSON, filtros, tipos o datos inválidos |
| `403` | CSRF ausente/inválido o petición cross-site |
| `404` | Ruta o reserva inexistente |
| `405` | Método no permitido; incluye `Allow` |
| `409` | Versión obsoleta o transición incompatible |
| `413` | JSON mayor de 16 KB |
| `415` | Content-Type distinto de application/json |
| `422` | Exportación superior a 5.000 reservas; acotar los filtros |
| `500` | Fallo interno durante una operación |
| `503` | Aplicación o conexión no disponible al arrancar |

GET admite HEAD, sin cuerpo en la respuesta HTTP. No se ofrece DELETE. En errores de red tras una escritura, consultar la reserva/listado antes de repetir: no hay aún claves de idempotencia para creación.

## Edición con historial

`PATCH /api/reservas/42`: enviar todos los campos de creación y `version` como entero. Requiere cookie, token CSRF y JSON. Devuelve `200` y el detalle actualizado. No permite cambiar el estado por esta ruta. Una reserva cancelada o una versión obsoleta devuelve `409`. Los datos inválidos devuelven `400` con errores por campo.

Si los datos cambian, aumenta la versión y se añade el evento `actualizada`. La descripción enumera los campos modificados; no copia valores personales anteriores. Guardar los mismos datos conserva versión e historial. La edición y su evento son atómicos.

## Operaciones en lote

`POST /api/reservas/lote`, con sesión y CSRF:

```json
{
  "estado": "confirmada",
  "reservas": [{"id": 42, "version": 1}, {"id": 43, "version": 2}]
}
```

Entre 1 y 50 IDs únicos, enteros positivos, con versiones válidas. Destino `confirmada` o `cancelada`. Se bloquean las filas en orden de ID y se aplica una sola transacción. Si una falta, cambia de versión o no permite la transición, falla todo el lote (`404` o `409`). Un payload inválido devuelve `400` sin escrituras.

Respuesta `200`: `{"data":[…detalles actualizados],"updated":2}`. `updated` cuenta las reservas procesadas, incluidos los no-ops cuyo estado ya coincidía con la versión actual. Cada cambio efectivo tiene su propio evento.

## Exportación CSV

`GET /api/reservas/exportar` admite los mismos filtros y orden que el listado. La paginación se ignora: exporta todas las coincidencias, hasta 5.000. Si hay más, devuelve `422` en JSON; nunca trunca silenciosamente. Respuesta correcta: `text/csv; charset=utf-8`, descarga `reservas.csv`, BOM UTF-8, separador `;`, comillas estándar y saltos CRLF.

Los textos que comienzan por `=`, `+`, `-` o `@` (también tras espacios) reciben un apóstrofo para evitar que Excel/LibreOffice interprete fórmulas. El CSV contiene información de huéspedes: guardarlo en un destino adecuado.

## Panel operativo

`GET /api/overview` devuelve `data` con `total`, `pendientes`, `llegadas`, `salidas`, `alojados`, `importe_activo`, `alojamientos`; además `today` (`AAAA-MM-DD`) y `accommodations` (nombres de alojamiento). El día se calcula en `APP_TIMEZONE`. Llegadas y salidas cuentan solo confirmadas. `alojados` son confirmadas con entrada ≤ hoy < salida: es una previsión según calendario, no un registro de presencia física. Pendientes y volumen activo abarcan todas las fechas; el volumen excluye canceladas.

## Agenda de movimientos

`GET /api/agenda?desde=2028-06-10&hasta=2028-06-16`. Intervalo inclusivo de hasta 31 días; por defecto hoy y seis días posteriores. Excluye canceladas e incluye pendientes y confirmadas. Cada elemento de `data` incluye los campos de la reserva, `movimiento` (`entrada` o `salida`) y `dia`. Una reserva puede aparecer dos veces si ambos movimientos caen dentro del intervalo. Orden: día, tipo de movimiento e ID.

`meta` contiene `total`, `truncated`, `desde`, `hasta`. Se devuelven como máximo 500 movimientos y se indica `truncated: true` si existen más. Un intervalo inválido devuelve `400`.
