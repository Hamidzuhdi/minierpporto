FROM php:8.2-apache

# Ekstensi yang dibutuhkan aplikasi:
#   mysqli, pdo, pdo_mysql : koneksi database
#   gd                     : mPDF (PDF invoice/estimasi/laporan) dan resize foto bukti bayar
#   zip                    : export Excel (.xlsx)
RUN apt-get update \
    && apt-get install -y --no-install-recommends libzip-dev libpng-dev libjpeg-dev libwebp-dev \
    && docker-php-ext-configure gd --with-jpeg --with-webp \
    && docker-php-ext-install -j"$(nproc)" mysqli pdo pdo_mysql gd zip \
    && rm -rf /var/lib/apt/lists/*

RUN printf 'DirectoryIndex login.php index.php\n<Directory /var/www/html>\n    Options -Indexes\n</Directory>\n' > /etc/apache2/conf-available/app.conf \
    && a2enconf app

COPY . /var/www/html/
RUN chown -R www-data:www-data /var/www/html

EXPOSE 80
