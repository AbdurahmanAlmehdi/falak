FROM dunglas/frankenphp:php8.4
WORKDIR /app
COPY . .
RUN composer install --no-dev --optimize-autoloader
EXPOSE 8000
CMD ["php", "artisan", "octane:frankenphp", "--host=0.0.0.0", "--port=8000"]
