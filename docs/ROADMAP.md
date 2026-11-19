# Hoja de ruta

Basada en el plan de trabajo. Trabaja tarea a tarea y no pases de fase hasta
cumplir su criterio.

| Fase | Semanas | Resultado | Estado |
|---|---|---|---|
| 1. Base del proyecto | 1 | Laravel multi-tenant con colas, tests y CI | ✅ completada |
| 2. Entrada por email | 2 | Los adjuntos llegan solos y se guardan | ✅ completada (falta probar con el buzón real) |
| 3. Extracción con IA | 3-4 | Datos estructurados con confianza por campo | ⏳ |
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

- [ ] Dataset de 50-100 facturas reales y variadas, anotadas a mano en JSON
- [ ] Interfaz `LLMProvider` con implementación para Claude
- [ ] Clasificación: factura recibida, emitida, albarán, presupuesto, ticket u otro (→ `IGNORED`)
- [ ] Esquema de salida estructurada con confianza por campo
- [ ] Texto si el PDF tiene capa de texto; imagen si es escaneo o foto
- [ ] Reintentos, timeout y `FAILED` con motivo; guardar modelo, tokens, coste y tiempo
- [ ] Comando `invoices:eval` con precisión por campo, coste y tiempo medios

**Criterio de salida:** más del 95 % en los campos clave y coste por factura conocido.
