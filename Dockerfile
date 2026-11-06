# Builds an image that runs the MCP server over stdio.
#
#   docker build -t mailcow-deliverability-mcp .
#   docker run -i --rm mailcow-deliverability-mcp                       # bundled sample log
#   docker run -i --rm -v /var/log/mail:/logs:ro -e MAILLOG_PATH=/logs mailcow-deliverability-mcp

FROM composer:2 AS vendor
WORKDIR /app
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-interaction --no-progress --no-scripts --no-autoloader --prefer-dist --ignore-platform-reqs
COPY . .
RUN composer dump-autoload --no-dev --optimize --classmap-authoritative

FROM php:8.2-cli-alpine
WORKDIR /app
COPY --from=vendor /app /app
ENTRYPOINT ["php", "/app/bin/server.php"]
