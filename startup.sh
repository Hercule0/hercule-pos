#!/bin/bash
# startup.sh — Azure App Service Linux PHP startup script
# This script runs before nginx starts. It copies our custom nginx config
# and restarts nginx so PHP files are served properly.

echo "[startup] Copying custom nginx config..."
cp /home/site/wwwroot/nginx.conf /etc/nginx/sites-available/default

echo "[startup] Testing nginx config..."
nginx -t

echo "[startup] Restarting nginx..."
service nginx reload || nginx -s reload

echo "[startup] Done. PHP-FPM and nginx are running."
