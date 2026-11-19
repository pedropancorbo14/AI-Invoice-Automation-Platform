# Facturas IA: procesado automático de facturas

Sistema que recibe las facturas por email, extrae los datos con IA, los valida
con reglas deterministas y los exporta al programa contable **sin que nadie
intervenga**. La persona solo entra cuando algo no cuadra (*gestión por
excepción*).

```
email ──► cola ──► LLM (clasifica + extrae) ──► reglas PHP ──► revisión (si hace falta) ──► exportación
```

> Estado: **fase 1 completada**; siguiente: fase 2 (entrada por email). Ver [docs/ROADMAP.md](docs/ROADMAP.md).

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

## Arrancar con Docker

Requisitos: Docker y Docker Compose.

```bash
cp .env.example .env
docker compose up -d --build
docker compose exec app composer install
docker compose exec app php artisan key:generate
docker compose exec app php artisan migrate --seed
```

- App: http://localhost:8080
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

## API (fase 1)

| Método | Ruta | Descripción |
|---|---|---|
| POST | `/api/auth/token` | Login: devuelve un token de Sanctum |
| DELETE | `/api/auth/token` | Revoca el token actual |
| GET | `/api/companies` | Clientes de la cuenta |
| POST | `/api/companies` | Crear cliente (solo admin) |
| GET | `/api/companies/{id}` | Ver cliente |
| GET | `/api/documents` | Documentos (filtros: `status`, `company_id`) |
| GET | `/api/documents/{id}` | Documento con su auditoría |
| GET | `/api/documents/{id}/file` | Descarga del original (nunca es público) |

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
