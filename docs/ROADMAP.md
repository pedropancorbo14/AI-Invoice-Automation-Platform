# Hoja de ruta

Basada en el plan de trabajo. Trabaja tarea a tarea y no pases de fase hasta
cumplir su criterio.

| Fase | Semanas | Resultado | Estado |
|---|---|---|---|
| 1. Base del proyecto | 1 | Laravel multi-tenant con colas, tests y CI | ✅ completada |
| 2. Entrada por email | 2 | Los adjuntos llegan solos y se guardan | ✅ completada (falta probar con el buzón real) |
| 3. Extracción con IA | 3-4 | Datos estructurados con confianza por campo | 🔧 código listo; falta el dataset y la evaluación real |
| 4. Validación y reglas | 5 | El sistema decide solo qué está bien y qué no | ⏳ |
| 5. Revisión y avisos | 6 | Bandeja de pendientes y resumen por email | ⏳ |
| 6. Exportación | 7 | CSV, Excel y Holded automáticos | ⏳ |
| 7. Portfolio y demo | 8 | Repo, vídeo y métricas | ⏳ |
| 8. Legal y validación | 8-9 | Hablado con gestorías y con las cuentas claras | ⏳ |
| 9. Pilotos, cobro y crecimiento | 10+ | Primeros clientes de pago | ⏳ |

## Fase 1: Base del proyecto

- [x] Proyecto Laravel con Docker Compose: app (PHP 8.4), Nginx, PostgreSQL, Redis y Horizon
- [x] Pest, Pint, Larastan (nivel 8) y GitHub Actions
- [x] Multi-tenant `Account` → `Company` → `Document`, aislado con global scope y policies
- [x] Usuarios y autenticación (Sanctum) con roles: administrador y revisor
- [x] Almacenamiento privado (local o S3) con rutas por cuenta
- [x] `Document` con máquina de estados y auditoría `document_events`

**Criterio de salida:** los tests de aislamiento entre cuentas pasan y la CI está en verde. ✅

## Fase 2: Entrada automática por email

- [x] Buzón con subdirecciones `facturas+<token>@dominio` (token por cliente y por cuenta)
- [x] Comando programado `inbox:fetch` que lee el buzón por IMAP cada 2 minutos y despacha un job por correo
- [x] Asignar el correo a su `Company` por el token; si es el de la cuenta, bandeja "sin asignar"; sin token válido, `unrouted`
- [x] Extraer adjuntos PDF, JPG, PNG y HEIC (por contenido); descartar firmas, logos y banners por dimensiones
- [x] Duplicados por SHA-256 (por cuenta) y por `Message-ID`
- [x] Guardar el correo original (`.eml`, remitente, asunto, fecha) junto al documento
- [x] `NEEDS_REVIEW` para correos sin adjunto pero con enlaces de descarga ("factura por enlace")
- [x] Subida manual desde la web (arrastrar y soltar) y por API
- [x] Guía de 2 minutos para la regla de reenvío en Gmail y Outlook: [guia-reenvio.md](guia-reenvio.md)
- [x] Extra: abre correos reenviados como adjunto (Outlook) y detecta la confirmación de reenvío de Gmail

**Criterio de salida:** 20 correos variados acaban cada uno en el estado correcto.
✅ Automatizado en `tests/Feature/Inbox/InboxAcceptanceTest.php` (23 casos).
⏳ Pendiente: repetirlo reenviando correos reales a un buzón IMAP de verdad.

## Fase 3: Extracción con IA

### 3.1 Dataset de prueba (lo primero)

- [ ] Reunir 50-100 facturas reales y variadas (anonimizadas si no son tuyas)
- [ ] Anotar a mano el resultado correcto de cada una en su `.json`

Formato e instrucciones: [evaluacion.md](evaluacion.md). Va en `storage/app/eval/dataset/` (fuera de git).

### 3.2 Integración con el LLM

- [x] Interfaz `LLMProvider` con implementación para Claude (`ClaudeProvider`, SDK oficial de PHP)
- [x] Clasificación: factura, ticket, rectificativa, albarán, presupuesto u otro
      (`other` → `IGNORED`; albarán y presupuesto → `NEEDS_REVIEW`)
- [x] Salida estructurada (JSON schema): proveedor, cliente, CIF/NIF, número, fechas, desglose de IVA
      por tipo (con recargo de equivalencia), IRPF, total, moneda y líneas
- [x] Confianza por campo (alta, media o baja) y nota cuando algo no se lee bien
- [x] Entrada: texto si el PDF tiene capa de texto; PDF si es escaneo; imagen (reducida) si es foto; HEIC → JPG
- [x] Reintentos con espera creciente, timeout y `FAILED` con el motivo; rechazos del modelo a revisión
- [x] Guardar por documento el modelo, los tokens, el coste y el tiempo (`document_extractions`)

### 3.3 Evaluación

- [x] Comando `invoices:eval`: precisión por campo, coste medio y tiempo medio (y p95), con informe JSON
- [ ] Ejecutarlo con el dataset real y apuntar la línea base
- [ ] Iterar el prompt y el esquema hasta que CIF, fecha, base, IVA y total superen el 95 %
- [ ] Comparar modelo y esfuerzo (`--model`, `--effort`) para fijar el coste por factura

**Criterio de salida:** el informe de evaluación da más del 95 % en los campos clave y sabes el coste por factura.

## Fase 4: Validación y reglas

- [ ] Comprobación aritmética (bases + IVA − retención = total, ±0,02 €)
- [ ] Formato y dígito de control de CIF, NIF y NIE
- [ ] Tipos de IVA válidos y base coherente con cada tipo
- [ ] Fechas lógicas; recibida o emitida según el CIF de la `Company`
- [ ] Duplicado de negocio (CIF + número + total) y memoria por proveedor (`Supplier`)
- [ ] Umbral de confianza configurable por cuenta y motivo legible por regla

**Criterio de salida:** con el dataset de la fase 3, las facturas con errores van a revisión y al menos el 70 % sale sola.
