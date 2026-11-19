# Facturas IA: procesado automático de facturas

Sistema que recibe las facturas por email, extrae los datos con IA, los valida
con reglas deterministas y los exporta al programa contable **sin que nadie
intervenga**. La persona solo entra cuando algo no cuadra (*gestión por
excepción*).

```
email ──► cola ──► LLM (clasifica + extrae) ──► reglas PHP ──► revisión (si hace falta) ──► exportación
```

> Estado: **fases 1 y 2 completadas; fase 3 con el código listo**, pendiente del dataset real. Ver [docs/ROADMAP.md](docs/ROADMAP.md).

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

**Subida manual.** Página `/subir` (arrastrar y soltar) o `POST /api/documents`.
Mismas reglas: solo tipos válidos y sin duplicados.

Guía para el cliente: [docs/guia-reenvio.md](docs/guia-reenvio.md) (Gmail y Outlook).

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

Recibida o emitida no lo decide la IA: lo decidirá Laravel en la fase 4
comparando los CIF con el de la empresa.

```bash
php artisan invoices:extract --received    # extraer lo pendiente
php artisan invoices:extract --failed      # reintentar lo fallido
php artisan invoices:eval                  # medir precisión y coste (ver docs/evaluacion.md)
```

## Arrancar con Docker

Requisitos: Docker y Docker Compose.

```bash
cp .env.example .env
docker compose up -d --build
docker compose exec app composer install
docker compose exec app php artisan key:generate
docker compose exec app php artisan migrate --seed
```

- App: http://localhost:8080 (subida manual en `/subir`)
- Horizon (colas): http://localhost:8080/horizon
- Mailpit (emails salientes): http://localhost:8025

Cuenta demo: `admin@demo.test` / `password` (admin) y `revisor@demo.test` / `password`.

```bash
# Obtener un token y listar documentos
curl -s -X POST localhost:8080/api/auth/token \
  -H 'Accept: application/json' \
  -d email=admin@demo.test -d password=password -d device_name=cli
curl -s localhost:8080/api/documents -H "Authorization: Bearer <token>" -H 'Accept: application/json'
```

Para leer un buzón real, rellena las variables `INBOX_*` de `.env` y pon
`INBOX_ENABLED=true`. Para probarlo a mano: `docker compose exec app php artisan inbox:fetch`.

## API

| Método | Ruta | Descripción |
|---|---|---|
| POST | `/api/auth/token` | Login: devuelve un token de Sanctum |
| DELETE | `/api/auth/token` | Revoca el token actual |
| GET | `/api/me` | Usuario, cuenta y dirección "sin asignar" |
| GET | `/api/companies` | Clientes de la cuenta |
| POST | `/api/companies` | Crear cliente (solo admin) |
| GET | `/api/companies/{id}` | Ver cliente (incluye su `inbox_address`) |
| GET | `/api/documents` | Documentos (filtros: `status`, `company_id`, `unassigned`) |
| POST | `/api/documents` | Subida manual (`files[]`, `company_id` opcional) |
| GET | `/api/documents/{id}` | Documento con su auditoría y su última extracción |
| GET | `/api/documents/{id}/file` | Descarga del original (nunca es público) |
| PUT | `/api/documents/{id}/company` | Asignar a un cliente (desde "sin asignar") |
| GET | `/api/inbound-emails` | Correos recibidos (filtros: `status`, `company_id`) |
| GET | `/api/inbound-emails/{id}` | Correo con sus documentos y el resumen de adjuntos |

## Calidad

```bash
composer lint      # Pint (estilo)
composer analyse   # Larastan nivel 8
composer test      # Pest
composer check     # las tres
```

Los tests usan SQLite en memoria en local. La CI (GitHub Actions) los ejecuta
contra **PostgreSQL 17**, pasa Pint y Larastan y construye la imagen Docker.

## Almacenamiento

Los originales se guardan en el disco privado `documents`
(`storage/app/private/documents`), en `accounts/{cuenta}/{año}/{mes}/{ulid}.{ext}`.
Para usar S3 o compatible (MinIO, R2...), pon `DOCUMENTS_DISK=s3` y las
variables `AWS_*` en `.env`.
