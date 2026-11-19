# Facturas IA: guía para agentes

Plataforma Laravel 13 que procesa facturas por email con IA (gestión por
excepción). Plan y estado: `docs/ROADMAP.md`. Visión general: `README.md`.

## Comandos

- `composer test`: Pest (SQLite en memoria en local; PostgreSQL en CI)
- `composer lint`: Pint (`vendor/bin/pint` para arreglar)
- `composer analyse`: Larastan nivel 8; no añadas baseline ni `@phpstan-ignore`
- `composer check`: las tres; debe pasar antes de cada commit

## Ramas

- `main` es la rama principal: siempre estable y con la CI en verde.
  No se trabaja directamente en ella.
- Cada cambio va en su propia rama, creada desde `main`, con nombre
  `tipo/descripcion-corta` en minúsculas y con guiones:
  - `feat/` funcionalidad nueva: `feat/fase-3-extraccion-ia`, `feat/exportacion-holded`
  - `fix/` corrección: `fix/escaneos-png-descartados`
  - `docs/` solo documentación: `docs/guia-reenvio-outlook`
  - `chore/` mantenimiento, dependencias, CI: `chore/actualizar-actions`
- Se integra en `main` con un pull request cuando `composer check` y la CI pasan.

## Reglas del dominio

- **Multi-tenant.** Todo modelo con datos de cliente usa `BelongsToAccount`
  y lleva `account_id`. Las rutas autenticadas van en el grupo
  `['auth:sanctum', 'tenant']` (un test lo verifica). En jobs, fija la cuenta
  con `app(CurrentAccount::class)->run($account, fn () => ...)`.
- **Policies siempre.** Autoriza en cada controlador con `Gate::authorize()`,
  aunque el global scope ya filtre.
- **Estados.** Nunca asignes `status` directamente: usa
  `$document->transitionTo($status, $reason, $by)`. Añade transiciones solo en
  `DocumentStatus::allowedTransitions()` con su test.
- **Auditoría inmutable.** `DocumentEvent` solo se inserta.
- **La IA extrae, Laravel decide.** Las reglas de validación son PHP determinista.
- **Entrada de documentos.** Todo correo entra por `ReceiveInboundEmail`
  (guarda el `.eml` y encola `ProcessInboundEmail`). Los adjuntos se aceptan
  por contenido (`FileType::detect`), nunca por extensión. Los duplicados se
  buscan por `sha256` dentro de la cuenta. El job debe seguir siendo idempotente.
- **Ficheros privados.** Se guardan con `StoreDocument` en el disco
  `config('invoices.documents_disk')` y se sirven solo tras autorización.

## Estilo

- Código en inglés; comentarios, mensajes y motivos para el usuario en español.
- Tests en `tests/Feature` (con BD) y `tests/Unit` (sin BD), estilo Pest.
- Correos de prueba con `Tests\Support\TestMail` y ficheros con
  `Tests\Support\Files`; el buzón IMAP se sustituye por `FakeMailbox`.
