# Arquitectura y funcionamiento

Detalle técnico de cada parte. Visión general en el [README](../README.md).

## Flujo de estados

Cada documento sigue una máquina de estados. Cada cambio queda registrado en
la auditoría (`document_events`): quién, qué y cuándo.

```
RECEIVED → CLASSIFIED → EXTRACTED → VALIDATED → EXPORTED
    │           │            │           │
    └───────────┴────────────┴───────────┴──► NEEDS_REVIEW  (hay un problema)
                │                            IGNORED       (no es una factura)
                └──────────────────────────► FAILED        (error técnico)
```

Las transiciones permitidas están en `App\Enums\DocumentStatus::allowedTransitions()`
y solo se cambia de estado con `$document->transitionTo(...)`.

## Arquitectura

| Pieza | Dónde |
|---|---|
| Multi-tenant: `Account` (gestoría) → `Company` (cliente con CIF) → `Document` | `app/Models` |
| Aislamiento por cuenta: global scope + policies | `app/Support/Tenancy`, `app/Policies` |
| Cuenta activa por petición/job | `CurrentAccount` (scoped) + middleware `tenant` |
| Máquina de estados + auditoría inmutable | `Document::transitionTo()`, `DocumentEvent` |
| Almacenamiento privado por cuenta | `App\Actions\StoreDocument`, disco `documents` |
| API autenticada con tokens | Sanctum, `routes/api.php` |
| Colas | Redis + Horizon |
| Uso y costes (operador) | Grafana sobre PostgreSQL con un usuario de solo lectura ([grafana.md](grafana.md)) |

**Aislamiento entre cuentas: dos capas.**
1. **Global scope** (`BelongsToAccount`): toda consulta se filtra por la cuenta
   activa. Un documento de otra cuenta da `404`, no `403`.
2. **Policies**: aunque una consulta se saltase el scope, un usuario nunca puede
   ver ni modificar recursos de otra cuenta.

Un test comprueba que **toda** ruta autenticada lleva el middleware `tenant`.

**Roles:** `admin` (gestiona clientes, usuarios y configuración) y `reviewer`
(revisa y corrige documentos).

## Entrada de documentos

**Por email (la vía principal).** Cada cliente tiene su dirección con
subdirección sobre un único buzón:

| Dirección | Va a |
|---|---|
| `facturas+<token del cliente>@dominio` | ese cliente |
| `facturas+<token de la cuenta>@dominio` | la cuenta, bandeja "sin asignar" |
| sin token o token desconocido | nadie (`unrouted`, solo lo ve quien opera la plataforma) |

El comando `inbox:fetch` lee el buzón por IMAP cada 2 minutos (scheduler),
guarda el `.eml` original y encola un job por correo (`ProcessInboundEmail`):

1. **Enruta** por el token. Mira `Delivered-To` / `X-Original-To` antes que
   `To`, porque al reenviar con una regla el `To` sigue siendo el del cliente.
2. **Abre** los correos reenviados como adjunto (Outlook) hasta 3 niveles.
3. **Filtra** adjuntos por contenido (magic bytes), no por extensión: acepta
   PDF, JPG, PNG y HEIC; descarta logos, iconos y banners por dimensiones
   (`< 300 px`), y `.ics`, `winmail.dat`, firmas digitales...
4. **Descarta duplicados** por SHA-256 dentro de la cuenta (y el mismo correo
   dos veces, por `Message-ID`).
5. **Factura por enlace:** sin adjunto pero con enlaces de descarga → guarda el
   correo como documento en `NEEDS_REVIEW` con el motivo y los enlaces.
6. **Confirmación de Gmail:** detecta el código para activar el reenvío y lo
   muestra en `/api/inbound-emails`.

Cada correo queda en `inbound_emails` con su resumen (qué pasó con cada
adjunto) y los documentos enlazan a su correo de origen.

**Subida manual.** En el panel (`/panel/subir`, también `/subir`): arrastrar, elegir o pegar
ficheros, y se ve en directo qué hace la IA con cada uno. Por API, `POST /api/documents`.
Mismas reglas: solo tipos válidos y sin duplicados.

Guía para el cliente: [docs/guia-reenvio.md](guia-reenvio.md) (Gmail y Outlook).

## Extracción con IA

Cada documento recibido pasa por Claude con **salida estructurada** (un JSON
schema que la respuesta siempre cumple). La IA extrae; Laravel decide:

```
RECEIVED → CLASSIFIED → EXTRACTED      factura, ticket o rectificativa
                      → IGNORED        no es una factura
                      → NEEDS_REVIEW   albarán o presupuesto
         → NEEDS_REVIEW                la IA lo rechaza o no se puede leer
         → FAILED                      error técnico (tras 3 intentos)
```

- **Entrada más barata posible:** si el PDF tiene capa de texto se manda el
  texto; si es un escaneo, el PDF; las fotos se reducen a 2000 px y las HEIC se
  convierten a JPG.
- **Qué devuelve:** proveedor y cliente (nombre y CIF), número, fechas, desglose
  de IVA por tipo (con recargo de equivalencia), retención de IRPF, total,
  líneas, y **confianza por campo** (alta, media o baja) con una nota cuando
  algo no se lee bien.
- **Coste:** cada extracción guarda modelo, tokens, coste en USD y tiempo
  (`document_extractions`).
- **Fiabilidad:** reintentos con espera creciente para errores pasajeros;
  `fallbacks` en el servidor por si el modelo rechaza la petición; los rechazos
  van a revisión.
- **Proveedor intercambiable:** interfaz `LLMProvider`; el modelo y el esfuerzo
  se cambian por `.env` (`EXTRACTION_MODEL`, `EXTRACTION_EFFORT`).

Recibida o emitida no lo decide la IA: lo decide Laravel comparando los CIF
con el de la empresa (ver Validación).

```bash
php artisan invoices:extract --received    # extraer lo pendiente
php artisan invoices:extract --failed      # reintentar lo fallido
php artisan invoices:eval                  # medir precisión y coste (ver docs/evaluacion.md)
```

## Validación (la IA extrae, Laravel decide)

Tras la extracción, reglas deterministas en PHP deciden si la factura pasa
sola (`VALIDATED`) o va a revisión (`NEEDS_REVIEW`). Cada regla que falla deja
un **motivo legible**, y la lista completa queda en la auditoría.

| Regla | Ejemplo de motivo |
|---|---|
| Aritmética: bases + IVA + recargo − retención = total (±0,02 €) | El total no cuadra: 121,00 ≠ 100,00 + 20,00. |
| CIF, NIF y NIE: formato y dígito de control (NIF-IVA de la UE aceptados) | El CIF/NIF del proveedor no es válido: B12345675. |
| Tipos de IVA de España (21, 10, 4, 0 %), cuota y recargo de equivalencia coherentes | El IVA al 21 % no cuadra: 100,00 × 21 % = 21,00, pero pone 20,00. |
| Fechas: ni futuras, ni de hace más de un año (configurable), vencimiento ≥ emisión | La fecha de emisión es futura: 25/11/2026. |
| Campos obligatorios y divisa | Falta el número de factura. / Factura en USD: revisa el cambio a euros. |
| Recibida o emitida, según el CIF de la empresa | La factura no parece de Panadería La Espiga S.L.: … |
| Confianza de la IA (umbral por cuenta: alta o media) | Dato dudoso en total: el total está tachado. |
| Duplicado de negocio: mismo CIF + número + total | Posible duplicado del documento #12 (mismo proveedor, número y total). |
| Proveedor nuevo: la primera factura de cada uno se revisa | Proveedor nuevo: Fontanería García S.L. (B12345674). … |

**Memoria por proveedor** (`suppliers`, una por empresa cliente): nombre,
IVA habitual, cuenta contable y categoría. Se rellena con cada factura validada
(y con las correcciones humanas, en la fase 5). En cuanto un proveedor tiene una
factura validada, las siguientes pasan solas si todo cuadra.

## Revisión por excepción (panel)

El cliente solo entra cuando hay un problema. Panel en `/panel` (Vue 3 + Vite),
que usa la misma API con la sesión del navegador (Sanctum en modo SPA):

- **Pendientes de revisar**: por orden de llegada, filtrable por cliente y por
  motivo (el total no cuadra, proveedor nuevo, datos dudosos...).
- **Revisión**: el documento a la izquierda y los datos a la derecha, con los
  campos dudosos resaltados, la nota de la IA y la comprobación del total en
  vivo. El cursor empieza en el primer dato dudoso.
- **Acciones**: corregir y aprobar (`Ctrl+Enter`), no es factura (`Alt+N`),
  duplicado (`Alt+D`) y adjuntar el PDF de una factura por enlace. Al terminar
  salta a la siguiente.
- Al aprobar se vuelven a pasar las reglas: si algo sigue sin cuadrar, avisa y
  pide confirmación. Cada campo corregido queda en la auditoría (antes, después,
  quién) y la memoria del proveedor guarda la cuenta contable y la categoría.
- **Métricas** del mes: facturas recibidas, % validado sin intervención,
  pendientes y motivos de revisión más frecuentes. El coste de la IA no se
  enseña al cliente (ni en la API); es solo para el operador.
- **Resumen por email** (diario o semanal, configurable por cuenta): "Se
  procesaron X facturas; Y necesitan revisión", con enlace directo. Si no hay
  pendientes no se manda nada.

Revisar y aprobar una factura dudosa lleva unos segundos: corregir el campo
marcado y `Ctrl+Enter`.

## Exportación

```
VALIDATED → EXPORTED       llegó a todos los destinos del cliente (p. ej. Holded)
VALIDATED → NEEDS_REVIEW   un destino la rechaza (clave caducada, datos...)
```

- **Holded**: cada factura recibida y validada se crea como gasto con su PDF,
  sola, en cuanto se valida. API key cifrada por cliente. Reintentos si Holded
  no responde y nunca dos veces (`document_exports`). Detalles y lista de
  verificación: [docs/holded.md](holded.md).
- **Libro de facturas (Excel o CSV)** por cliente y periodo (mes, trimestre o
  año): una fila por tipo de IVA, con proveedor/cliente, CIF, bases, cuotas,
  recargo, IRPF, total, cuenta contable y categoría. El CSV va con `;` y coma
  decimal para el Excel en español. Se descarga o se envía por email desde el
  panel (**Exportar**) o con `php artisan invoices:export-ledger`.
- Nuevos destinos: implementar `App\Support\Export\ExportTarget` y añadirlo a
  `config('invoices.export.targets')`.

## API

| Método | Ruta | Descripción |
|---|---|---|
| POST | `/api/auth/token` | Login: devuelve un token de Sanctum |
| DELETE | `/api/auth/token` | Revoca el token actual |
| GET | `/api/me` | Usuario, cuenta y dirección "sin asignar" |
| GET / PATCH | `/api/account/settings` | Umbral de confianza (`high`/`medium`) y resumen por email (`daily`/`weekly`/`off`); cambiarlo, solo admin |
| GET | `/api/metrics?month=AAAA-MM` | Métricas del mes |
| GET | `/api/companies` | Clientes de la cuenta |
| POST | `/api/companies` | Crear cliente (solo admin) |
| GET | `/api/companies/{id}` | Ver cliente (incluye su `inbox_address`) |
| GET | `/api/documents` | Documentos (filtros: `status`, `company_id`, `unassigned`, `review_code` y `q`, que busca por `#id`, fichero, proveedor, CIF o número). Incluye `meta.status_counts` |
| GET | `/api/documents/counts` | Solo los recuentos por estado (el contador de pendientes del panel) |
| POST | `/api/documents` | Subida manual (`files[]`, `company_id` opcional) |
| GET | `/api/documents/{id}` | Documento con su auditoría y su última extracción |
| GET | `/api/documents/{id}/file` | Descarga del original (nunca es público) |
| PUT | `/api/documents/{id}/company` | Asignar a un cliente (desde "sin asignar") |
| POST | `/api/documents/{id}/approve` | Corregir y aprobar (`data`, `supplier`, `force`) |
| POST | `/api/documents/{id}/not-invoice` | Descartar: no es una factura |
| POST | `/api/documents/{id}/duplicate` | Descartar: duplicado (`of_document_id`) |
| POST | `/api/documents/{id}/file` | Adjuntar el fichero que faltaba y volver a extraer |
| POST | `/api/documents/{id}/reprocess` | Volver a mandar a la IA un documento ignorado o fallido |
| GET | `/api/companies/{id}/export?period=2026-T4&format=xlsx` | Libro de facturas del periodo (descarga) |
| POST | `/api/companies/{id}/export/email` | Enviarlo por email (`period`, `format`, `email`) |
| PUT / DELETE | `/api/companies/{id}/holded` | Conectar (`api_key`) o desconectar Holded (solo admin) |
| GET | `/api/inbound-emails` | Correos recibidos (filtros: `status`, `company_id`) |
| GET | `/api/inbound-emails/{id}` | Correo con sus documentos y el resumen de adjuntos |

## Almacenamiento

Los originales se guardan en el disco privado `documents`
(`storage/app/private/documents`), en `accounts/{cuenta}/{año}/{mes}/{ulid}.{ext}`.
Para usar S3 o compatible (MinIO, R2...), pon `DOCUMENTS_DISK=s3` y las
variables `AWS_*` en `.env`.
