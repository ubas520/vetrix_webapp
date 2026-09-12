#!/bin/sh
set -eu

# mod_php requires prefork; enforce one MPM in the runtime filesystem too.
a2dismod -f mpm_event mpm_worker
a2enmod mpm_prefork
apache2ctl configtest

# Volumes are available at startup, so initialize upload permissions here.
for folder in pets profiles products proofs payment; do
    mkdir -p "/var/www/html/uploads/$folder"
done
chown -R www-data:www-data /var/www/html/uploads

php /var/www/html/deploy/init-db.php
php /var/www/html/deploy/migrate-payments.php
exec apache2-foreground
