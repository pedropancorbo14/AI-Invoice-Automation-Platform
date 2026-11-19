# Hoja de ruta

Basada en el plan de trabajo. Trabaja tarea a tarea y no pases de fase hasta
cumplir su criterio.

| Fase | Semanas | Resultado | Estado |
|---|---|---|---|
| 1. Base del proyecto | 1 | Laravel multi-tenant con colas, tests y CI | ✅ completada |
| 2. Entrada por email | 2 | Los adjuntos llegan solos y se guardan | ✅ completada (falta probar con el buzón real) |
| 3. Extracción con IA | 3-4 | Datos estructurados con confianza por campo | 🔧 código listo; falta el dataset y la evaluación real |
| 4. Validación y reglas | 5 | El sistema decide solo qué está bien y qué no | 🔧 código listo; falta medirlo con el dataset |
| 5. Revisión y avisos | 6 | Bandeja de pendientes y resumen por email | ✅ completada |
| 6. Exportación | 7 | CSV, Excel y Holded automáticos | 🔧 código listo; falta la prueba con una cuenta real de Holded |
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

- [x] Comprobación aritmética: bases + IVA + recargo − retención = total (±0,02 €), y retención coherente con su %
- [x] Formato y dígito de control de CIF, NIF y NIE (NIF-IVA de la UE aceptados)
- [x] Tipos de IVA válidos (21, 10, 4, 0 %), cuota coherente con la base y recargo de equivalencia válido
- [x] Fechas lógicas: ni futuras ni de hace más de un año (configurable); vencimiento ≥ emisión
- [x] Recibida o emitida comparando con el CIF de la `Company` (`documents.direction`)
- [x] Duplicado de negocio: mismo CIF del proveedor + número + total (`documents.invoice_key`)
- [x] Memoria por proveedor (`Supplier`): CIF, nombre normalizado, cuenta contable, IVA y categoría habituales
- [x] Proveedor nuevo: la primera vez va a revisión; las siguientes salen solas si todo cuadra
- [x] Umbral de confianza configurable por cuenta (`/api/account/settings`)
- [x] Cada regla que falla guarda un motivo legible
- [x] Tests unitarios por regla con casos límite
- [x] Extra: `invoices:eval` mide qué harían las reglas (% automático, falsos aprobados, revisiones de más)

**Criterio de salida:** con el dataset de la fase 3, todas las facturas con errores van a revisión
(0 falsos aprobados) y al menos el 70 % salen sin intervención. ⏳ Pendiente del dataset.

## Fase 5: Revisión por excepción y avisos

- [x] Panel con Vue: bandeja de "Pendientes de revisar" filtrada por cliente y por motivo
- [x] Vista de revisión: documento a la izquierda, datos a la derecha, dudosos resaltados y motivo arriba
- [x] Acciones: corregir y aprobar, "no es factura", duplicado y adjuntar el PDF que faltaba
- [x] Al aprobar: actualizar la memoria del proveedor (cuenta contable y categoría) y pasar a `VALIDATED`
- [x] Auditoría de cada corrección: campo, antes, después y quién
- [x] Resumen por email diario o semanal (configurable); no se manda si no hay pendientes
- [x] Panel de métricas: facturas procesadas, % automático, motivos de revisión y coste de IA del mes
- [x] Extra: atajos de teclado, salto automático a la siguiente, comprobación del total en vivo
      y aviso si al aprobar algo sigue sin cuadrar
- [x] Extra: cuenta demo con facturas ficticias (adelanta parte de la fase 7)

**Criterio de salida:** revisar y aprobar una factura dudosa en menos de 30 segundos y el resumen
por email llega bien. ✅ Prueba en navegador: corregir el total + `Ctrl+Enter` aprueba y carga la
siguiente en ~1 s; el resumen se prueba con tests (contenido, frecuencia y que no se manda sin pendientes).

## Fase 6: Exportación automática

- [x] Interfaz `ExportTarget` para añadir destinos sin tocar el resto
- [x] Libro de facturas en CSV o Excel por cliente y periodo (mes, trimestre o año), descargable y por email
- [x] Holded: API key cifrada por `Company`; cada factura recibida validada se crea como gasto con su PDF
- [x] Exportación automática al pasar a `VALIDATED`, con reintentos; si falla, a revisión con el motivo
- [x] Nunca exportar dos veces: id de Holded guardado en `document_exports` y comprobado antes de enviar
- [x] Tests con la API de Holded simulada (HTTP fake)
- [ ] Prueba real con una cuenta de prueba de Holded: lista en [holded.md](holded.md)

**Criterio de salida:** una factura reenviada por email aparece en Holded sola, de principio a fin.
⏳ Pendiente: buzón IMAP real (fase 2), clave de Claude (fase 3) y cuenta de prueba de Holded.

## Fase 7: Portfolio y demo

- [ ] Revisar la cláusula de propiedad intelectual del contrato antes de publicar nada
- [ ] README con el problema, GIF o vídeo, diagrama de arquitectura y decisiones técnicas
- [x] Datos de ejemplo: cuenta demo con facturas ficticias (adelantado en la fase 5)
- [ ] Publicar los resultados de la evaluación (precisión por campo, % automático, coste por factura)
- [ ] Vídeo de 90 segundos y demo desplegada con HTTPS
- [ ] Decidir si el repo es público
