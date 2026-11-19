# Cómo enviar tus facturas automáticamente (2 minutos)

Tu gestoría te ha dado una dirección como esta:

```
facturas+abc123@tudominio.com
```

Todo lo que llegue a esa dirección entra solo en el sistema. Solo tienes que
crear **una regla** en tu correo para que te las reenvíe sin pensar.

> ¿No quieres configurar nada? También puedes **reenviar a mano** cada factura a
> esa dirección. Funciona igual, también si la reenvías "como adjunto".

---

## Gmail

**1. Añade la dirección de reenvío** (solo la primera vez)

1. Abre Gmail en el ordenador → rueda dentada ⚙️ → **Ver todos los ajustes**.
2. Pestaña **Reenvío y correo POP/IMAP** → **Añadir una dirección de reenvío**.
3. Pega tu dirección `facturas+…@…` → **Siguiente** → **Continuar**.
4. Gmail manda un **código de confirmación** a esa dirección. Lo recibe tu
   gestoría: pídeselo, pégalo en **Verificar** y listo.

> No actives "Reenviar una copia del correo entrante" para todo: solo queremos
> las facturas. Eso se hace en el paso 2.

**2. Crea el filtro**

1. En la barra de búsqueda, abre las opciones (icono ☰ a la derecha).
2. En **Contiene las palabras** escribe: `factura OR invoice OR recibo`.
3. Marca **Contiene archivos adjuntos** → **Crear filtro**.
4. Marca **Reenviarlo a:** y elige tu dirección `facturas+…@…`.
5. **Crear filtro**.

¿Tienes proveedores fijos? Añade otro filtro con **De:** `facturacion@proveedor.com`
(sin marcar "Contiene archivos adjuntos", así también llegan las facturas por enlace).

---

## Outlook (Outlook.com y Microsoft 365)

1. Abre Outlook en el navegador → rueda dentada ⚙️ → **Correo** → **Reglas**.
2. **Agregar nueva regla** y ponle un nombre: `Facturas`.
3. **Agregar una condición** → **Tiene datos adjuntos**.
   Añade otra: **El asunto o el cuerpo incluyen** → `factura`.
4. **Agregar una acción** → **Reenviar a** → tu dirección `facturas+…@…`.
5. **Guardar**.

> En Microsoft 365, si el reenvío no llega, puede que tu empresa tenga bloqueado
> el reenvío automático a direcciones externas. Pide a quien lleve vuestro
> Microsoft 365 que lo permita para esta dirección.

---

## ¿Cómo sé que funciona?

Reenvíate una factura de prueba. En unos minutos aparece en el sistema. Si algo
no cuadra (falta el PDF, es una foto ilegible...), tu gestoría lo verá en
"pendientes de revisar" y te avisará.

**Qué se acepta:** PDF, fotos JPG/PNG y fotos HEIC del iPhone. Los logos de las
firmas y las imágenes pequeñas se ignoran solos. Si envías dos veces la misma
factura, solo se procesa una vez.
