#!/bin/bash
set -e

# Install composer dependencies if vendor dir is missing (happens on first bind-mount)
if [ ! -f /var/www/html/vendor/autoload.php ]; then
    echo "[entrypoint] vendor/ not found, running composer install..."
    cd /var/www/html && composer install --optimize-autoloader --no-interaction
fi

# Ensure writable dirs have correct permissions
chown -R www-data:www-data /var/www/html/writable 2>/dev/null || true

exec apache2-foreground
