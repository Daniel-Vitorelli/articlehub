# ============================================
# ArticleHub — PHP + Apache Container
# ============================================
FROM php:8.2-apache

# Enable required PHP extensions
RUN apt-get update && apt-get install -y libicu-dev \
    && docker-php-ext-install pdo pdo_mysql intl \
    && rm -rf /var/lib/apt/lists/*

# Enable Apache mod_rewrite
RUN a2enmod rewrite

# Set working directory
WORKDIR /var/www/html

# Copy application files
COPY index.html .
COPY style.css .
COPY app.js .
COPY api/ api/

# Set proper permissions
RUN chown -R www-data:www-data /var/www/html

# Configure PHP session directory
RUN mkdir -p /var/lib/php/sessions && \
    chown -R www-data:www-data /var/lib/php/sessions

# PHP config for sessions and security
RUN echo "session.cookie_httponly = On\n\
session.cookie_samesite = Lax\n\
session.use_strict_mode = On\n\
display_errors = Off\n\
log_errors = On" > /usr/local/etc/php/conf.d/custom.ini

EXPOSE 80
