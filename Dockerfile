FROM php:8.2-apache

# I-enable ang Apache mod_rewrite
RUN a2enmod rewrite

# I-install ang MySQL extensions para sa PHP
RUN docker-php-ext-install mysqli pdo pdo_mysql

# Kopyahin ang lahat ng files sa web root ng Apache
COPY . /var/www/html/

# I-expose ang port 80
EXPOSE 80