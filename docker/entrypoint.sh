#!/bin/sh
# Al arrancar: cachea config, rutas y vistas con las variables de este entorno.
set -e

php artisan optimize --quiet

exec "$@"
