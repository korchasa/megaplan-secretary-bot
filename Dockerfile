FROM php:7-cli

# RUN php -d phar.readonly=0 ./vendor/bin/phar-builder.php package ./composer.json

ADD . /usr/src/bot

WORKDIR /usr/src/bot

CMD ["php", "./bot.php"]
