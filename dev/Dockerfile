FROM php:8.4-cli@sha256:d63e8b80cd4c6cfd01cfa7c910522e299cdc7ca1a2b6d6563db48df3c17e6c55
RUN docker-php-ext-install pdo_mysql
WORKDIR /app
CMD ["php", "-S", "0.0.0.0:8080"]
