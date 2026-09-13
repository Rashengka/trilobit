# The gate's PHP at the lowest version composer.json allows.
#
# bin/check-floor builds this and runs it; it reads the version out of
# composer.json's require.php and passes it in, so the floor is written down in
# one place. There is deliberately no default here: an image built without it
# would be some other PHP, and a check that ran on the wrong version would look
# exactly like one that ran on the right one.
ARG PHP_VERSION
FROM php:${PHP_VERSION}-cli

# The same extensions CI asks setup-php for: pdo_mysql, which the base image
# does not carry. Everything else composer.json's dependencies need is already
# compiled in.
RUN docker-php-ext-install -j"$(nproc)" pdo_mysql

# git, because the leak guard at the start of the gate asks git which files
# could be committed. Nothing is installed here, so no unzip.
RUN apt-get update \
    && apt-get install -y --no-install-recommends git \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer

# Assertions on, to match what CI runs with.
RUN echo 'zend.assertions=1' > /usr/local/etc/php/conf.d/zz-project.ini

# The container lives for one run and runs as its own root, so Composer's
# warning about that is noise. git would refuse the mounted repository as owned
# by somebody else; this is command-scope configuration of this container only,
# which is the one scope git accepts safe.directory from besides the global
# file - and nothing here writes to that.
ENV COMPOSER_ALLOW_SUPERUSER=1 \
    COMPOSER_NO_INTERACTION=1 \
    GIT_CONFIG_COUNT=1 \
    GIT_CONFIG_KEY_0=safe.directory \
    GIT_CONFIG_VALUE_0=*

COPY gate.sh /usr/local/bin/trilobit-floor-gate

CMD ["trilobit-floor-gate"]
