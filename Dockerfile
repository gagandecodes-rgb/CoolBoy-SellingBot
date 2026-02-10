# ✅ PHP + Apache (Render friendly)
FROM php:8.2-apache

# Install required extensions for PostgreSQL + common
RUN apt-get update && apt-get install -y --no-install-recommends \
    libpq-dev \
    && docker-php-ext-install pdo pdo_pgsql \
    && rm -rf /var/lib/apt/lists/*

# Enable Apache rewrite (optional but useful)
RUN a2enmod rewrite

# Put your bot file as index.php
COPY index.php /var/www/html/index.php

# (Optional) Apache basic hardening
RUN sed -i 's/ServerTokens OS/ServerTokens Prod/g' /etc/apache2/conf-available/security.conf && \
    sed -i 's/ServerSignature On/ServerSignature Off/g' /etc/apache2/conf-available/security.conf

# Render provides PORT env. Apache listens on 80 by default.
EXPOSE 80

# Start Apache
CMD ["apache2-foreground"]
