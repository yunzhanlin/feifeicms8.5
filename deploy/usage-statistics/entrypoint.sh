#!/bin/sh
set -eu
umask 077
mkdir -p /data
chown www-data:www-data /data
su -s /bin/sh www-data -c 'php /app/bin/init.php'
exec docker-php-entrypoint "$@"
