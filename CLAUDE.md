# Facturas IA: guía para agentes

Plataforma Laravel 13 que procesa facturas por email con IA (gestión por
excepción). Plan y estado: `docs/ROADMAP.md`. Visión general: `README.md`;
detalle técnico: `docs/arquitectura.md`.

## Comandos

- `composer test`: Pest (SQLite en memoria en local; PostgreSQL en CI)
- `composer lint`: Pint (`vendor/bin/pint` para arreglar)
- `composer analyse`: Larastan nivel 8; no añadas baseline ni `@phpstan-ignore`
- `composer check`: las tres; debe pasar antes de cada commit
- `npm test` (Vitest) y `npm run build`: el panel en `resources/js/panel`

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
- **La IA extrae, Laravel decide.** Las reglas de validación son PHP
  determinista: una clase por regla en `App\Support\Validation\Rules`, que
  devuelve `RuleFailure` con un motivo legible en español. Las que consultan la
  base de datos van en `Validator::CONTEXT_RULES`. Los importes se suman en
  céntimos (`App\Support\Money`), nunca con floats.
- **Entrada de documentos.** Todo correo entra por `ReceiveInboundEmail`
  (guarda el `.eml` y encola `ProcessInboundEmail`). Los adjuntos se aceptan
  por contenido (`FileType::detect`), nunca por extensión. Los duplicados se
  buscan por `sha256` dentro de la cuenta. El job debe seguir siendo idempotente.
- **Extracción con IA.** Todo pasa por la interfaz `LLMProvider`; nunca se
  llama al SDK de Anthropic fuera de `ClaudeProvider`. La IA devuelve datos y
  confianza; las decisiones (ignorar, revisar, validar) las toma PHP. Los
  importes se guardan como texto con 2 decimales. Cualquier cambio en el prompt
  o en el esquema se mide con `invoices:eval` antes de darlo por bueno.
- **Revisión.** Para mandar algo a revisión usa `$document->sendToReview($motivo,
  $codigos)` (los códigos están en `App\Support\Review\ReviewCode` y alimentan
  los filtros y las métricas). Las acciones humanas viven en `App\Actions\Review`
  y siempre pasan el usuario para la auditoría.
- **Exportación.** Los destinos factura a factura implementan
  `App\Support\Export\ExportTarget` y se registran en
  `config('invoices.export.targets')`. Se exporta solo desde `ExportDocument`
  (al pasar a `VALIDATED`) y nunca dos veces: `document_exports` es la fuente
  de verdad. Las API keys de terceros van con el cast `encrypted` y ocultas.
  Los tests de Holded usan `Http::fake()`; la exportación está desactivada por
  defecto en tests (`EXPORT_ENABLED`).
- **Panel.** Vue 3 en `resources/js/panel`, llama a la API con la sesión
  (Sanctum SPA). Las cuentas de importes en el navegador van en céntimos
  (`invoice.js`), igual que en PHP.
- **Demo.** Con `DEMO_MODE=true` no se conectan integraciones reales (Holded)
  y `demo:reset` borra todo cada noche: nunca debe ejecutarse sin el modo demo.
  Los datos de ejemplo salen de `DemoInvoicesSeeder` y pasan por las reglas reales.
- **Ficheros privados.** Se guardan con `StoreDocument` en el disco
  `config('invoices.documents_disk')` y se sirven solo tras autorización.

## Estilo

- Código en inglés; comentarios, mensajes y motivos para el usuario en español.
- Tests en `tests/Feature` (con BD) y `tests/Unit` (sin BD), estilo Pest.
- Correos de prueba con `Tests\Support\TestMail` y ficheros con
  `Tests\Support\Files`; el buzón IMAP se sustituye por `FakeMailbox`.
- Los tests nunca llaman a la API: `TestCase` registra `FakeLLMProvider`, y
  `FakeAnthropicApi` simula la API a nivel HTTP para probar `ClaudeProvider`.
  La extracción está desactivada por defecto en tests (`EXTRACTION_ENABLED`).
