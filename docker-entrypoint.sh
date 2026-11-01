#!/bin/bash
# Render sets $PORT (default 10000); make Apache listen on it.
set -e
PORT="${PORT:-80}"
sed -i "s/Listen 80/Listen $PORT/" /etc/apache2/ports.conf
sed -i "s/:80>/:$PORT>/" /etc/apache2/sites-available/000-default.conf
exec "$@"
