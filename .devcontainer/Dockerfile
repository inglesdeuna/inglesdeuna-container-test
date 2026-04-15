# Start from the official PHP image
FROM php:8.2-cli

# Install system packages and MySQL client
RUN apt-get update && apt-get install -y \
    default-mysql-client \
    git \
    unzip \
    zip \
    && docker-php-ext-install pdo_mysql \
    && docker-php-ext-enable pdo_mysql \
    && rm -rf /var/lib/apt/lists/*

# Set working directory
WORKDIR /var/www/html

# Copy your project files into the container
COPY . .

# Default command when container starts
CMD ["php", "-a"]
