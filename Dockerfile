FROM alpine:3.10

ENV COMPOSER_HOME=/var/cache/composer
ENV PROJECT_ROOT=/sw6

RUN apk --no-cache add \
        nginx supervisor curl \
        php7 php7-fpm \
        php7-ctype php7-curl php7-curl php7-dom php7-dom php7-fileinfo php7-gd php7-gd \
        php7-iconv php7-intl php7-intl php7-json php7-json php7-mbstring php7-mbstring \
        php7-mysqli php7-openssl php7-openssl php7-pdo php7-pdo_mysql php7-phar php7-phar \
        php7-session php7-simplexml php7-tokenizer php7-xml php7-xml php7-xmlreader php7-xmlwriter \
        php7-zip php7-zlib php7-zlib \
    && adduser -u 1000 -D -h $PROJECT_ROOT sw6 sw6 \
    && rm /etc/nginx/conf.d/default.conf

# Copy system configs
COPY config/etc /etc

# Make sure files/folders needed by the processes are accessable when they run under the sw6 user
RUN chown -R sw6.sw6 /run && \
  chown -R sw6.sw6 /var/lib/nginx && \
  chown -R sw6.sw6 /var/tmp/nginx && \
  chown -R sw6.sw6 /var/log/nginx

WORKDIR $PROJECT_ROOT

ADD config/plugins.json $PROJECT_ROOT/var/plugins.json
ADD composer.* $PROJECT_ROOT/
ADD bin/build*.sh $PROJECT_ROOT/bin/

RUN apk add --no-cache bash git composer npm \
    && bin/build.sh \
    && apk del bash git composer npm \
    && chown -R sw6:sw6 $PROJECT_ROOT

USER sw6

ADD --chown=sw6 . .

RUN bin/console assets:install

# Expose the port nginx is reachable on
EXPOSE 8080

# Let supervisord start nginx & php-fpm
ENTRYPOINT ["./bin/entrypoint.sh"]

# Configure a healthcheck to validate that everything is up&running
HEALTHCHECK --timeout=10s CMD curl --silent --fail http://127.0.0.1:8080/fpm-ping