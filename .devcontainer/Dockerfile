FROM kimai/kimai2:dev

ARG USER_UID=1000
ARG USER_GID=1000

COPY --from=mlocati/php-extension-installer:2 /usr/bin/install-php-extensions /usr/local/bin/

RUN install-php-extensions xdebug \
    && apt-get update \
    && apt-get install -y --no-install-recommends \
        git less procps unzip default-mysql-client \
    && rm -rf /var/lib/apt/lists/*

RUN printf '%s\n' \
    'xdebug.mode = off' \
    'xdebug.start_with_request = trigger' \
    'xdebug.client_port = 9003' \
    'xdebug.max_nesting_level = 512' \
    > /usr/local/etc/php/conf.d/zz-xdebug.ini

RUN groupadd -f -g ${USER_GID} dev \
    && useradd -m -u ${USER_UID} -g ${USER_GID} -G www-data -s /bin/bash dev \
    && chown -R ${USER_UID}:${USER_GID} /opt/kimai/var /composer

ENV COMPOSER_HOME=/composer
