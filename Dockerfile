FROM hyperf/hyperf:8.2-alpine-v3.19-swoole

WORKDIR /app

# Copia composer files primeiro (cache)
COPY composer.json composer.lock /app/

RUN composer install --no-dev --prefer-dist --no-interaction --no-progress || true

# Copia o restante do código
COPY . /app

# Instala deps garantindo autoload
RUN composer install --no-dev --prefer-dist --no-interaction --no-progress \
 && composer dump-autoload -o

EXPOSE 9501

CMD ["php", "bin/hyperf.php", "start"]
