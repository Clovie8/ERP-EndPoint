# Use the official PHP image with Apache
FROM php:8.2-apache

# Enable Apache mod_rewrite for API routing (very common for PHP APIs)
RUN a2enmod rewrite

# Copy your API code into the container's web directory
COPY . /var/www/html/

# Set the correct permissions for the web server
RUN chown -R www-data:www-data /var/www/html

# Expose port 80 for web traffic
EXPOSE 80