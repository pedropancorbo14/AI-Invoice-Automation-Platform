# Evaluación de la extracción con IA

El comando `invoices:eval` pasa un dataset de facturas reales por la IA y mide
cuánto acierta, campo a campo, y cuánto cuesta. Es la "verdad" con la que se
decide si el prompt, el esquema o el modelo mejoran o empeoran.

**Objetivo de la fase 3:** más del 95 % en los campos clave (CIF del proveedor,
fecha, base, IVA y total) y conocer el coste por factura.

## 1. Preparar el dataset

Carpeta: `storage/app/eval/dataset/` (está en `.gitignore`: **las facturas
reales no se suben al repositorio**).

Reúne **50-100 facturas variadas**. Cuanto más se parezcan a las que llegarán de
verdad, mejor:

| Tipo | Cuántas, aprox. |
|---|---|
| PDF digital (con texto) | 30 % |
| PDF escaneado | 20 % |
| Foto de móvil (JPG/HEIC), torcida o con sombras | 20 % |
| Tickets / facturas simplificadas | 10 % |
| Varios tipos de IVA, recargo de equivalencia, IRPF, rectificativas | 15 % |
| En inglés u otras divisas | 5 % |

Mete también 3-5 documentos que **no** son facturas (albarán, presupuesto,
contrato) para comprobar que se clasifican bien.

Si no son tuyas, **anonimízalas** (tapa nombres de personas físicas, direcciones
y cuentas bancarias), y anota la verdad sobre la versión anonimizada.

### Mientras no tengas facturas reales: dataset sintético

`tools/eval-dataset/generate.mjs` crea unas 80 facturas **ficticias** con su
`.json` ya anotado, imitando lo que llega a una gestoría:

- **Formatos:** PDF digital (con texto), escaneo (PDF solo imagen, torcido,
  gris y con ruido), foto de móvil (perspectiva, sombras y desenfoque) y
  captura de pantalla.
- **Diseños:** 10 plantillas (clásica, moderna, ERP con cuadro de impuestos,
  Word de autónomo, factura de luz, agua y teléfono, ticket de TPV, SaaS en
  inglés...) con etiquetas, formatos de fecha e importe y paginación variables.
- **Casos fiscales:** varios tipos de IVA, recargo de equivalencia, IRPF del
  15, 7 y 19 %, exentas, conceptos no sujetos, descuentos por línea y globales,
  rectificativas en negativo, inversión del sujeto pasivo, USD, facturas
  emitidas y de varias páginas.
- **Trampas:** errores del proveedor (total que no cuadra, CIF con el control
  mal, IVA al 18 %) y documentos que no son facturas (albarán, presupuesto,
  proforma, contrato, nómina, publicidad).

Las empresas, los CIF y las personas son inventados, y los CIF y NIF tienen un
dígito de control válido. La verdad es siempre lo que pone el papel, aunque
esté mal. Solo necesita Node y Playwright con Chromium:

```bash
npm install --no-save playwright && npx playwright install chromium
node tools/eval-dataset/generate.mjs                  # → storage/app/eval/synthetic
node tools/eval-dataset/generate.mjs --seed=7         # otro dataset distinto
php artisan invoices:eval --dataset=storage/app/eval/synthetic
```

Sirve para ajustar el prompt y las reglas, para compararlas entre modelos y
para detectar regresiones. **No sustituye al dataset real:** está generado por
ordenador, así que es más limpio y más regular que el papel de verdad (sin
sellos, sin texto a mano, sin arrugas de verdad ni diseños rarísimos). Un 98 %
aquí no garantiza un 95 % con clientes reales. No publiques estos resultados
como si fueran reales: en el README, di siempre que son sintéticos.

## 2. Anotar la verdad

Cada factura lleva al lado un `.json` con el mismo nombre:

```
storage/app/eval/dataset/
├── 001-fontaneria.pdf
├── 001-fontaneria.json
├── 002-ticket-gasolinera.jpg
├── 002-ticket-gasolinera.json
└── ...
```

```json
{
  "tags": ["pdf-digital", "irpf"],
  "company": { "name": "Panadería La Espiga S.L.", "tax_id": "B87654323" },
  "expected": {
    "document_type": "invoice",
    "supplier": { "name": "Fontanería García S.L.", "tax_id": "B12345674" },
    "customer": { "tax_id": "B87654323" },
    "invoice_number": "F-2026-001",
    "issue_date": "2026-11-15",
    "due_date": null,
    "tax_breakdown": [
      { "rate": 21, "base": 100.00, "tax_amount": 21.00 }
    ],
    "withholding": { "amount": 15.00 },
    "total": 106.00
  }
}
```

- `company`: la empresa en cuya carpeta entraría la factura (el mismo contexto
  que recibe la IA en producción). Opcional.
- `tags`: etiquetas libres para agrupar resultados (escaneo, foto, inglés...).
- `expected`: solo se puntúan los campos que pongas. Si un dato no aparece en la
  factura, ponlo a `null` (así también se mide que la IA no invente).
- Importes con punto decimal; fechas `AAAA-MM-DD`; `tax_breakdown` con una
  entrada por tipo de IVA; la retención en positivo.
- `document_type`: `invoice`, `simplified_invoice`, `credit_note`,
  `delivery_note`, `quote` u `other`.

## 3. Ejecutar

Necesitas `ANTHROPIC_API_KEY` en `.env`. **Cada factura es una llamada de pago.**

```bash
php artisan invoices:eval
```

Opciones útiles:

```bash
php artisan invoices:eval --limit=5                         # prueba rápida
php artisan invoices:eval --only=001-fontaneria             # un caso concreto
php artisan invoices:eval --model=claude-sonnet-5-5         # comparar modelos
php artisan invoices:eval --effort=low                      # comparar esfuerzo
```

Saca la precisión por campo (marcando los clave), el porcentaje de facturas con
todos los campos clave bien, el coste medio y total, el tiempo medio y p95, y la
lista de fallos (esperado frente a obtenido, y si la IA lo marcó como dudoso).
El informe completo queda en `storage/app/eval/reports/` para comparar
ejecuciones.

### Qué harían las reglas (fase 4)

La evaluación también pasa cada extracción por las reglas de validación sin
estado (todas menos duplicados y proveedor nuevo, que dependen de la cuenta) y
saca:

- **Facturas que pasarían solas**: objetivo ≥ 70 %.
- **Falsos aprobados**: facturas con algún dato clave mal que pasarían sin
  revisión. **Tiene que ser 0**: es el único error que no ve nadie.
- **Errores detectados**: datos mal que las reglas mandan a revisión (bien).
- **Revisiones de más**: datos bien que van a revisión igualmente (molestan,
  pero no son peligrosas).
- **Reglas que más saltan**: dónde mirar para subir el % automático.

Para que la regla de recibida o emitida cuente, pon `company` en el `.json` del
caso. La antigüedad de las fechas no se tiene en cuenta aquí (el dataset puede
tener facturas viejas).

## 4. Iterar

1. Ejecuta la evaluación y apunta la línea base.
2. Mira la tabla de fallos: ¿se repite algún patrón? (fechas en formato
   americano, recargo de equivalencia sumado al IVA, total con la retención...).
3. Cambia **una sola cosa**: el prompt (`ExtractionSchema::systemPrompt()`), el
   esquema, el modelo o el esfuerzo.
4. Vuelve a ejecutar y compara con el informe anterior.
5. Para cuando los campos clave superen el 95 % de forma estable.

Consejo: los fallos que la IA marca como "dudoso" no son graves: en la fase 4
irán a revisión. Lo peligroso son los fallos con confianza `high`.
