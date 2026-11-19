# Exportación a Holded

Cuando una factura **recibida** de un cliente conectado pasa a `VALIDATED`
(sola o aprobada en revisión), se crea en Holded como **gasto** con su PDF
adjunto y el documento pasa a `EXPORTED`.

## Conectar un cliente

1. En Holded: **Configuración → Desarrolladores → Nueva API key** (con permiso
   para contactos y documentos de compra).
2. En el panel: **Exportar → API key de Holded → Conectar** (solo administradores).

La clave se guarda cifrada con `APP_KEY` y nunca se devuelve por la API.

## Qué se manda

| Factura | Holded |
|---|---|
| Factura recibida | `POST /documents/purchase` |
| Ticket / factura simplificada | `purchase` con `isReceipt: true` |
| Rectificativa recibida | `POST /documents/purchaserefund` (importes en positivo) |
| Factura emitida | No se exporta (Holded solo recibe gastos) |
| Con recargo de equivalencia | No se exporta: va a revisión para registrarla a mano |

- Contacto: `contactCode` = CIF del proveedor y `contactName`, con
  `applyContactDefaults` para usar la cuenta y el IVA que el contacto ya tenga.
- Una línea por tipo de IVA: `subtotal` = base, `tax` = %, `retention` = % IRPF.
- `invoiceNum`, `date` y `dueDate` (timestamp Unix, medianoche en Madrid), `currency`.
- Después, `POST /documents/{tipo}/{id}/attach` con el fichero original.

## Errores

| Respuesta de Holded | Qué pasa |
|---|---|
| 401 / 403 | A revisión: "La API key de Holded no es válida o ha caducado." |
| 4xx o `{"status": 0}` | A revisión con el mensaje de Holded |
| 429, 5xx, sin conexión | Se reintenta (1 min, 5 min, 15 min); al cuarto fallo, a revisión |

Corregido el problema (p. ej. una clave nueva), basta con **aprobar** la
factura en el panel para que se vuelva a exportar.

**Nunca se exporta dos veces:** `document_exports` guarda el id de Holded en
cuanto se crea el documento. Si falla el adjunto, el reintento solo adjunta.

## ⚠️ Pendiente de verificar con una cuenta real

La documentación de la API de Holded no estaba accesible desde el entorno donde
se escribió esta integración: está hecha con la API v1 conocida y probada
contra una API simulada. Antes de usarla con clientes, con una **cuenta de
prueba de Holded**:

- [ ] Una factura con un proveedor nuevo crea el contacto (por `contactCode`) y el gasto.
- [ ] Una factura de un proveedor existente se asocia a ese contacto (no lo duplica).
- [ ] Base, IVA, retención y total coinciden con la factura.
- [ ] La fecha es la correcta (no se desplaza un día por la zona horaria).
- [ ] El PDF aparece adjunto al gasto.
- [ ] Un ticket (`isReceipt`) y una rectificativa (`purchaserefund`) se registran bien.
- [ ] Una API key inválida da 401 (y el documento va a revisión).

Si algo no coincide, el ajuste está acotado a `App\Support\Export\Holded`
(`HoldedClient` y `HoldedTarget::payload()`), con sus tests en
`tests/Feature/Export/HoldedExportTest.php`.
