# syntax=docker/dockerfile:1
# The RC lane's toolchain (version-updates row 17, `make rc-lane`, .github/workflows/rc-lane.yml): the tools stage of
# infra/api/Dockerfile on PHP's next release candidate, never shipped. FrankenPHP publishes no image on a PHP before its
# release, so this starts from PHP's own; the extensions are the API's (scripts/gates/version-pins.sh holds the list and
# the Composer copy to infra/api/Dockerfile's) plus mbstring, which FrankenPHP's image builds in. The tag follows the
# release candidates on purpose; once that PHP is released and the API moves to it, the lane moves to the next one.
FROM php:8.6-rc-cli-trixie
COPY --from=mlocati/php-extension-installer:2.13.2 /usr/bin/install-php-extensions /usr/local/bin/install-php-extensions
# Behind a proxy that re-terminates TLS (a cloud session), the proxy's CA comes as the optional secret extra_ca, for this
# step only, as in infra/api/Dockerfile; without it the step runs as it always did. TLS checks stay on.
RUN --mount=type=secret,id=extra_ca --mount=type=tmpfs,target=/run/extra-ca \
    if [ -s /run/secrets/extra_ca ]; then { if [ -f /etc/ssl/certs/ca-certificates.crt ]; then cat /etc/ssl/certs/ca-certificates.crt; fi; \
      cat /run/secrets/extra_ca; } > /run/extra-ca/all.pem \
      && export SSL_CERT_FILE=/run/extra-ca/all.pem; fi \
 && install-php-extensions pdo_pgsql intl zip opcache bcmath gd exif mbstring
COPY infra/api/conf.d/10-app.ini "$PHP_INI_DIR/conf.d/"
COPY --from=composer:2.11.1 /usr/bin/composer /usr/bin/composer
ENV APP_ENV=dev
RUN cp "$PHP_INI_DIR/php.ini-development" "$PHP_INI_DIR/php.ini" \
 && printf 'memory_limit=-1\n' > "$PHP_INI_DIR/conf.d/40-tools.ini"
# curl and unzip: the fiscal schemas the XML tests validate against are fetched for each run (scripts/fiscal/fetch-xsd.sh).
# hadolint ignore=DL3008
RUN --mount=type=secret,id=extra_ca --mount=type=tmpfs,target=/run/extra-ca \
    if [ -s /run/secrets/extra_ca ]; then { if [ -f /etc/ssl/certs/ca-certificates.crt ]; then cat /etc/ssl/certs/ca-certificates.crt; fi; \
      cat /run/secrets/extra_ca; } > /run/extra-ca/all.pem \
      && export SSL_CERT_FILE=/run/extra-ca/all.pem; fi \
 && apt-get update \
 && apt-get install -y --no-install-recommends bash ca-certificates curl git jq make perl python3 unzip \
 && rm -rf /var/lib/apt/lists/*
ENTRYPOINT []
CMD ["bash"]
HEALTHCHECK NONE
