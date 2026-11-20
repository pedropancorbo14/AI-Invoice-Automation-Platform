-- Usuario de PostgreSQL de solo lectura para Grafana (docs/grafana.md).
--
-- Lo ejecuta el servicio grafana-db en cada arranque: es idempotente y
-- vuelve a aplicar la contraseña y los permisos. Solo ve las columnas que
-- necesitan los paneles: nada de contraseñas, emails, tokens, API keys,
-- ficheros ni datos de las facturas.
--
-- Uso: psql -v ON_ERROR_STOP=1 -v grafana_password=... -f readonly-user.sql

SELECT 'CREATE ROLE grafana_reader LOGIN'
WHERE NOT EXISTS (SELECT FROM pg_roles WHERE rolname = 'grafana_reader') \gexec

ALTER ROLE grafana_reader WITH LOGIN NOSUPERUSER NOCREATEDB NOCREATEROLE NOINHERIT
    CONNECTION LIMIT 5 PASSWORD :'grafana_password';
ALTER ROLE grafana_reader SET default_transaction_read_only = on;
ALTER ROLE grafana_reader SET statement_timeout = '30s';

-- Se parte de cero en cada arranque (revocar la tabla revoca también sus columnas).
REVOKE ALL ON ALL TABLES IN SCHEMA public FROM grafana_reader;
GRANT USAGE ON SCHEMA public TO grafana_reader;

-- Solo las tablas que ya existen: en el primer despliegue grafana-db puede
-- arrancar antes de las migraciones (docs/grafana.md explica cómo repetirlo).
SELECT format('GRANT SELECT (%s) ON %I TO grafana_reader', cols, tbl)
FROM (VALUES
    ('accounts', 'id, name, created_at'),
    ('users', 'id, account_id, role, created_at'),
    ('companies', 'id, account_id, name, created_at'),
    ('documents', 'id, account_id, company_id, status, source, mime_type, size, created_at'),
    ('document_events', 'id, account_id, document_id, user_id, type, from_status, to_status, payload, created_at'),
    ('document_extractions', 'id, account_id, document_id, model, input_mode, document_type, input_tokens, output_tokens, cache_write_tokens, cache_read_tokens, cost_usd, duration_ms, created_at'),
    ('inbound_emails', 'id, account_id, status, created_at'),
    ('document_exports', 'id, account_id, document_id, target, status, error, attempts, exported_at, created_at'),
    ('failed_jobs', 'id, queue, failed_at')
) AS t(tbl, cols)
WHERE to_regclass(format('public.%I', tbl)) IS NOT NULL \gexec
