# Despliegue en un servidor propio (con HTTPS)

Todo corre con Docker en un solo servidor: Caddy (HTTPS automático) → nginx →
PHP-FPM, más Horizon (colas), el scheduler, PostgreSQL y Redis.

```
Internet ──443──► caddy ──► web (nginx, ficheros públicos) ──► app (PHP-FPM)
                                                               horizon · scheduler
                                                               postgres · redis
```

## 1. Servidor

- Un VPS pequeño basta para una demo o los primeros clientes: **2 vCPU y 4 GB**
  (p. ej. Hetzner CX22, unos 5 €/mes).
- **En la UE** (Alemania, Finlandia...): los documentos son datos de clientes
  y deben quedarse en la UE (RGPD).
- Ubuntu 24.04 con Docker:

```bash
curl -fsSL https://get.docker.com | sh
```

## 2. Dominio

Crea un registro **A** (p. ej. `facturas.tudominio.com`) apuntando a la IP del
servidor. Caddy pide el certificado a Let's Encrypt al arrancar.

## 3. Código y configuración

```bash
git clone https://github.com/<tu-usuario>/AI-Invoice-Automation-Platform.git facturas
```

```bash
cd facturas && cp .env.production.example .env
```

Rellena en `.env`, como mínimo: `APP_DOMAIN`, `APP_URL`, `DB_PASSWORD` (larga y
aleatoria) y el correo saliente (`MAIL_*`). Para una demo pública pon
`DEMO_MODE=true`.

## 4. Arrancar

Construye las imágenes y genera la clave de la aplicación:

```bash
docker compose -f compose.prod.yaml build
```

```bash
docker compose -f compose.prod.yaml run --rm --no-deps app php artisan key:generate --show
```

Copia la clave que sale (`base64:...`) en `APP_KEY` del `.env`. Después:

```bash
docker compose -f compose.prod.yaml up -d
```

```bash
docker compose -f compose.prod.yaml exec app php artisan migrate --force
```

**Demo pública:** carga los datos de ejemplo (se recargan solos cada noche a las 4:00):

```bash
docker compose -f compose.prod.yaml exec app php artisan demo:reset
```

**Con clientes reales:** crea la cuenta y el primer usuario con `php artisan tinker`
(o a partir del seeder), rellena `INBOX_*`, `ANTHROPIC_API_KEY` y pon
`INBOX_ENABLED=true`.

## 5. Actualizar

```bash
git pull && docker compose -f compose.prod.yaml up -d --build
```

```bash
docker compose -f compose.prod.yaml exec app php artisan migrate --force
```

Horizon termina los jobs en curso antes de reiniciarse.

## 6. Copias de seguridad

Lo que hay que guardar: la base de datos y el volumen `storage` (documentos y
correos originales). Ejemplo diario con cron en el servidor:

```bash
0 3 * * * cd /root/facturas && docker compose -f compose.prod.yaml exec -T postgres pg_dump -U invoices invoices | gzip > /root/backups/db-$(date +\%F).sql.gz
```

Y copia `/var/lib/docker/volumes/facturas_storage` (o usa `DOCUMENTS_DISK=s3`
con un almacenamiento compatible con S3 en la UE).

## 7. Comprobaciones

- `https://tu-dominio/up` responde 200.
- `/panel` carga y el candado del navegador está bien.
- `docker compose -f compose.prod.yaml logs -f horizon scheduler` sin errores.
- Horizon (`/horizon`) solo es accesible para los emails de `OPERATOR_EMAILS`.
