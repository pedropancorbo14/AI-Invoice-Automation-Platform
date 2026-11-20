# Grafana: uso y costes

Panel **solo para el operador** con el uso de la plataforma y el coste de la IA
de todas las cuentas. Los clientes no lo ven: su panel de Vue solo enseña lo que
les sirve (recibidas, % automático, pendientes y motivos de revisión), sin costes.

Grafana lee directamente de PostgreSQL con un usuario de **solo lectura**; no
toca el código de la aplicación.

## Qué enseña

Dashboard **Facturas IA · Uso y costes** (la portada de Grafana). Arriba se elige
el periodo y la cuenta (una, varias o todas).

| Sección | Paneles |
|---|---|
| Resumen | Documentos recibidos, % validado sin intervención, pendientes ahora, coste de IA, coste medio por documento y coste previsto del mes |
| Volumen | Documentos por día (email o subida), documentos por estado y correos entrantes por estado |
| IA | Coste diario por modelo, tokens por día, tiempo de extracción (media y p95) y coste por tipo de entrada (texto, PDF escaneado, imagen) |
| Revisión | Validadas por día (solas o por una persona), motivos de revisión y tiempo medio hasta que una persona aprueba |
| Exportación | Exportaciones por estado y últimos fallos con su error |
| Uso por cliente | Por cuenta: empresas, usuarios, documentos, % automático, pendientes, coste de IA y fecha del último documento |
| Sistema | Documentos fallidos, trabajos de cola fallidos, cuentas activas y usuarios |

El coste es el que calcula la aplicación con los precios de
`config('invoices.extraction.pricing')`. Lo que te cobra Anthropic de verdad está
en su consola; si cambian los precios, actualiza esa tabla.

## En local

`docker compose up -d` ya arranca Grafana: <http://localhost:3000>, usuario
`admin` y contraseña `admin` (cámbiala en `.env` con `GRAFANA_ADMIN_PASSWORD`).

## En producción

Va en el perfil `grafana` para que no arranque si no lo quieres.

1. Rellena en `.env` `GRAFANA_ADMIN_PASSWORD` y `GRAFANA_DB_PASSWORD`: largas,
   aleatorias, distintas entre sí y de `DB_PASSWORD`. Sin ellas no arranca.
2. Con las migraciones ya hechas (ver [despliegue.md](despliegue.md)):

```bash
docker compose -f compose.prod.yaml --profile grafana up -d
```

3. Grafana escucha **solo en `127.0.0.1:3000`** del servidor: no se publica en
   internet ni pasa por Caddy. Entra con un túnel SSH desde tu ordenador:

```bash
ssh -N -L 3000:127.0.0.1:3000 usuario@tu-servidor
```

   y abre <http://localhost:3000>.

Para actualizar, usa el mismo comando con `--profile grafana` que el resto
(`git pull && docker compose -f compose.prod.yaml --profile grafana up -d --build`).

## Seguridad

- **Usuario `grafana_reader`.** Lo crea el servicio `grafana-db` con
  [`docker/grafana/readonly-user.sql`](../docker/grafana/readonly-user.sql) en
  cada arranque (es idempotente). Sus transacciones son de solo lectura, tiene
  como mucho 5 conexiones y 30 s por consulta.
- **Solo las columnas que usan los paneles.** No puede leer contraseñas, emails,
  sesiones, tokens, las API keys de Holded, las rutas de los ficheros ni los
  datos de las facturas extraídas.
- Sin registro de usuarios ni acceso anónimo, y sin enviar estadísticas a Grafana Labs.
- Si el primer `up` fue **antes** de las migraciones, el usuario se creó sin
  permisos sobre las tablas que aún no existían. Repite el paso:

```bash
docker compose -f compose.prod.yaml --profile grafana run --rm grafana-db
```

## Cambiar el dashboard

El JSON está en [`docker/grafana/dashboards/uso.json`](../docker/grafana/dashboards/uso.json)
y se carga al arrancar. Puedes editar desde la web, pero esos cambios no llegan
al repositorio: cuando te guste, **Export → Export as JSON** y sustituye el fichero.

Si un panel nuevo necesita otra columna o tabla, añádela a la lista de
`readonly-user.sql` y reinicia `grafana-db`; si no, el panel dará
`permission denied`. Las fechas se guardan en UTC y el dashboard las muestra en
hora de Madrid.
