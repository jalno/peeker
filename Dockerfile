FROM ghcr.io/dnj/php-alpine:8.2-mysql

RUN apk --no-cache add --virtual .dev pcre-dev ${PHPIZE_DEPS} \
  && pecl install timezonedb \
  && docker-php-ext-install calendar \
  && docker-php-ext-enable timezonedb calendar \
  && apk del .dev \
  && apk add bind-tools \
  && echo 'date.timezone = Asia/Tehran' > /usr/local/etc/php/conf.d/tz.ini

COPY --chown=www-data:www-data . /var/www/html
COPY packages/dockerize/nginx/jalno.conf /etc/nginx/conf.d/default.conf.d/

RUN rm -fr packages/dockerize; \
	find /var/www/html -type d -name ".docker" -prune -exec rm -fr {} \;;

WORKDIR /var/www/html
