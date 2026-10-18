#!/bin/bash
set -e

# ============================================================
# Docker Entrypoint for HostForge & Container Environments
# ============================================================

# 1. Resolve PORT from environment variable (default to 80 if unset)
PORT="${PORT:-80}"

echo "🚀 Configuring Apache on port ${PORT}..."

# Ensure only mpm_prefork is loaded for mod_php compatibility on Railway
a2dismod mpm_event mpm_worker >/dev/null 2>&1 || true
a2enmod mpm_prefork >/dev/null 2>&1 || true

# Rewrite ports.conf cleanly without duplicate Listen directives
cat <<EOF > /etc/apache2/ports.conf
Listen ${PORT}
EOF

# If PORT is not 80, also listen on 80 for internal Docker health checks
if [ "${PORT}" != "80" ]; then
    echo "Listen 80" >> /etc/apache2/ports.conf
fi

# Configure VirtualHost to accept connections on any matching port
cat <<EOF > /etc/apache2/sites-available/000-default.conf
<VirtualHost *:${PORT} *:80>
    ServerAdmin webmaster@localhost
    DocumentRoot /var/www/html

    <Directory /var/www/html>
        Options -Indexes +FollowSymLinks
        AllowOverride All
        Require all granted
    </Directory>

    ErrorLog \${APACHE_LOG_DIR}/error.log
    CustomLog \${APACHE_LOG_DIR}/access.log combined
</VirtualHost>
EOF

# 2. Suppress Apache ServerName warning globally
echo "ServerName localhost" > /etc/apache2/conf-available/servername.conf
a2enconf -q servername || true

# 3. Ensure Upload Directories and Proper Permissions
mkdir -p /var/www/html/app/uploads/achievements \
         /var/www/html/app/uploads/applications \
         /var/www/html/app/uploads/avatars \
         /var/www/html/app/uploads/signatures \
         /var/www/html/app/uploads/stamps \
         /var/www/html/app/uploads/events

chown -R www-data:www-data /var/www/html/app/uploads /tmp
chmod -R 775 /var/www/html/app/uploads

# 4. Asynchronous Database Initialization (Non-blocking so Apache answers health checks instantly)
(
    sleep 3
    php /var/www/html/app/shared/cli_setup.php >> /tmp/db_setup.log 2>&1 || true
) &

# 5. Hand over control to Apache foreground process
echo "✅ Apache is ready on port ${PORT}. Starting web service..."
exec "$@"
