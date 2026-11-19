# Guion del vídeo (90 segundos)

Objetivo: que se entienda el problema y el producto sin conocimientos técnicos.
Graba la pantalla a 1280×720 y la voz aparte (o subtítulos). Ritmo: una idea
por plano.

**Antes de grabar**

- Servidor con datos reales: `INBOX_ENABLED=true`, `ANTHROPIC_API_KEY` y un
  cliente conectado a una cuenta de prueba de Holded.
- Ten preparado en tu correo un email de un proveedor con una factura en PDF
  (y otra factura con algo raro: un total tachado o una foto torcida).
- Abre en pestañas: tu correo, el panel (`/panel/revisar`) y Holded (Compras).

| Tiempo | Pantalla | Voz / texto |
|---|---|---|
| 0:00-0:10 | Montón de PDFs y fotos de tickets | "Una gestoría teclea cientos de facturas al mes. Esto lo hace solo." |
| 0:10-0:22 | Gmail: reenviar la factura a `facturas+…@` | "El cliente solo reenvía sus facturas a su dirección. Con una regla, ni eso." |
| 0:22-0:35 | Holded → Compras: aparece la factura con el PDF | "Un minuto después está en Holded: proveedor, IVA, total y el PDF adjunto. Nadie ha tocado nada." |
| 0:35-0:55 | Panel → Pendientes → la factura dudosa | "Si algo no cuadra, no se cuela: va a una bandeja con el motivo. Aquí el total está tachado." |
| 0:55-1:05 | Corregir el total → ✓ cuadra → `Ctrl+Enter` | "Se corrige en segundos y el sistema aprende del proveedor." |
| 1:05-1:15 | Métricas: % automático y coste de IA | "Cada mes ves cuánto sale solo y cuánto cuesta: céntimos por factura." |
| 1:15-1:25 | Email del resumen diario | "Y si hay algo pendiente, te llega un resumen. Si no, silencio." |
| 1:25-1:30 | Logo / URL de la demo | "Facturas IA. Prueba la demo en <tu dominio>." |

**Consejos**

- Sin datos reales, la demo (`DEMO_MODE=true`) sirve para los planos del panel
  (0:35-1:15); el reenvío y Holded necesitan el entorno real.
- Corta las esperas (la extracción tarda unos segundos) en vez de acelerar el
  vídeo: se tiene que ver que es real.
- El GIF del README (`docs/images/revision.gif`) se regenera con Playwright
  si cambia el panel.
