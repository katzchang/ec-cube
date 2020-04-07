FROM php:7.3-apache-stretch

ENV APACHE_DOCUMENT_ROOT /var/www/html

RUN /bin/rm /etc/apt/sources.list \
  && { \
    echo 'deb http://cdn.debian.net/debian/ stretch main contrib non-free'; \
    echo 'deb http://cdn.debian.net/debian/ stretch-updates main contrib'; \
  } > /etc/apt/sources.list.d/mirror.jp.list

RUN apt-get update \
  && apt-get install --no-install-recommends -y --allow-downgrades\
    apt-transport-https \
    apt-utils \
    build-essential \
    curl \
    debconf-utils \
    gcc \
    git \
    gnupg2 \
    libfreetype6-dev \
    libicu57=57.1-6+deb9u3 \
    libicu-dev=57.1-6+deb9u3 \
    libjpeg62-turbo-dev \
    libpng-dev \
    libpq-dev \
    libzip-dev \
    locales \
    ssl-cert \
    unzip \
    zlib1g-dev \
  && apt-get clean \
  && rm -rf /var/lib/apt/lists/* \
  && echo "en_US.UTF-8 UTF-8" >/etc/locale.gen \
  && locale-gen \
  ;

RUN docker-php-ext-configure pgsql -with-pgsql=/usr/local/pgsql \
  && docker-php-ext-install -j$(nproc) zip gd mysqli pdo_mysql opcache intl pgsql pdo_pgsql \
  ;

RUN pecl install apcu && echo "extension=apcu.so" > /usr/local/etc/php/conf.d/apc.ini

RUN mkdir -p ${APACHE_DOCUMENT_ROOT} \
  && sed -ri -e 's!/var/www/html!${APACHE_DOCUMENT_ROOT}!g' /etc/apache2/sites-available/*.conf \
  && sed -ri -e 's!/var/www/!${APACHE_DOCUMENT_ROOT}!g' /etc/apache2/apache2.conf /etc/apache2/conf-available/*.conf \
  ;

RUN a2enmod rewrite headers ssl
# Enable SSL
RUN ln -s /etc/apache2/sites-available/default-ssl.conf /etc/apache2/sites-enabled/default-ssl.conf
EXPOSE 443

# Use the default production configuration
RUN mv "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini"
# Override with custom configuration settings
COPY dockerbuild/php.ini $PHP_INI_DIR/conf.d/

# New Relic
RUN apt-get update \
  && apt-get install wget --no-install-recommends -y

RUN wget -O - https://download.newrelic.com/548C16BF.gpg | apt-key add - \
  && sh -c 'echo "deb http://apt.newrelic.com/debian/ newrelic non-free" \
  > /etc/apt/sources.list.d/newrelic.list' \
  && apt-get update \
  && apt-get install newrelic-php5  --no-install-recommends -y \
  && newrelic-install install
#RUN wget -O newrelic-php5-9.9.0.260-linux.tar.gz https://download.newrelic.com/php_agent/release/newrelic-php5-9.9.0.260-linux.tar.gz \
#  && gzip -dc newrelic-php5-9.9.0.260-linux.tar.gz | tar xf - \
#  && cd newrelic-php5-9.9.0.260-linux \
#  && ./newrelic-install install

COPY dockerbuild/newrelic.ini $PHP_INI_DIR/conf.d/

COPY . ${APACHE_DOCUMENT_ROOT}

WORKDIR ${APACHE_DOCUMENT_ROOT}

RUN curl -sS https://getcomposer.org/installer \
  | php \
  && mv composer.phar /usr/bin/composer \
  && composer config -g repos.packagist composer https://packagist.jp \
  && composer global require hirak/prestissimo \
  && chown www-data:www-data /var/www \
  && mkdir -p ${APACHE_DOCUMENT_ROOT}/var \
  && chown -R www-data:www-data ${APACHE_DOCUMENT_ROOT} \
  && find ${APACHE_DOCUMENT_ROOT} -type d -print0 \
  | xargs -0 chmod g+s \
  ;

USER www-data

RUN composer install \
  --no-scripts \
  --no-autoloader \
  --no-dev -d ${APACHE_DOCUMENT_ROOT} \
  ;

RUN composer dumpautoload -o --apcu --no-dev

RUN if [ ! -f ${APACHE_DOCUMENT_ROOT}/.env ]; then \
        cp -p .env.dist .env \
        ; fi

# trueを指定した場合、DBマイグレーションやECCubeのキャッシュ作成をスキップする。
# ビルド時点でDBを起動出来ない場合等に指定が必要となる。
ARG SKIP_INSTALL_SCRIPT_ON_DOCKER_BUILD=false

RUN if [ ! -f ${APACHE_DOCUMENT_ROOT}/var/eccube.db ] && [ ! ${SKIP_INSTALL_SCRIPT_ON_DOCKER_BUILD} = "true" ]; then \
        composer run-script installer-scripts && composer run-script auto-scripts \
        ; fi

USER root
