# Hoja de ruta

Basada en el plan de trabajo. Trabaja tarea a tarea y no pases de fase hasta
cumplir su criterio.

| Fase | Semanas | Resultado | Estado |
|---|---|---|---|
| 1. Base del proyecto | 1 | Laravel multi-tenant con colas, tests y CI | ✅ código listo (falta CI en verde en GitHub) |
| 2. Entrada por email | 2 | Los adjuntos llegan solos y se guardan | ⏳ |
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

**Criterio de salida:** los tests de aislamiento entre cuentas pasan y la CI está en verde.

## Fase 2: Entrada automática por email

Ya preparado en la fase 1: `companies.inbox_token`, `documents.sha256`,
`documents.company_id` nulo (bandeja "sin asignar") y la acción `StoreDocument`.

- [ ] Buzón con subdirecciones `facturas+<token>@dominio`
- [ ] Comando programado que lee el buzón por IMAP y despacha un job por correo
- [ ] Asignar el correo a su `Company` por el token; si no, bandeja "sin asignar"
- [ ] Extraer adjuntos PDF, JPG, PNG y HEIC; descartar firmas y logos por tamaño
- [ ] Duplicados por SHA-256
- [ ] Guardar el correo original (remitente, asunto, fecha) para la auditoría
- [ ] `NEEDS_REVIEW` para correos sin adjunto pero con enlaces ("factura por enlace")
- [ ] Subida manual desde la web (arrastrar y soltar)
- [ ] Guía de 2 minutos para la regla de reenvío en Gmail y Outlook

**Criterio de salida:** 20 correos variados acaban cada uno en el estado correcto.
