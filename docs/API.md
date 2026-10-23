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
| `500` | Fallo interno durante una operación |
| `503` | Aplicación o conexión no disponible al arrancar |

GET admite HEAD, sin cuerpo en la respuesta HTTP. No se ofrecen DELETE ni edición general. En errores de red tras una escritura, consultar la reserva/listado antes de repetir: no hay aún claves de idempotencia para creación.
