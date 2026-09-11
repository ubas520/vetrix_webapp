#!/bin/sh
set -eu

# Volumes are available at startup, so initialize upload permissions here.
for folder in pets profiles products proofs payment; do
    mkdir -p "/var/www/html/uploads/$folder"
done
chown -R www-data:www-data /var/www/html/uploads

php /var/www/html/deploy/init-db.php
exec apache2-foreground
