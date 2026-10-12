# Worker mode, for the applications that want it.
#
# This package is the reason an application *can* run under FrankenPHP; it is not
# what makes an application run. A classic runtime — nginx + php-fpm, apache,
# `php -S` — needs no image at all, and the framework's `public/index.php` is
# already correct for one.
#
# So this file exists for the case where boot-once-serve-many is worth a base
# image: the loop in `worker.php` is only reachable through a PHP that has
# FrankenPHP compiled in, and there is no way to add that to a stock image at
# runtime the way `pdo_pgsql` can be loaded with an explicit `-d` flag. The
# extension has to be there before PHP starts.
#
#     docker build -t empira/frankenphp -f frankenphp/Dockerfile .
#     docker compose -f frankenphp/docker/compose.yml up -d
#
# The build context is the workspace root, so the siblings the worker boots are
# in it. The guard below says what is missing rather than failing three COPYs
# later with no explanation.

FROM dunglas/frankenphp:php8.4-alpine

# `zip` is Doctrine Migrations' portable way of reading a schema across versions,
# and it is not in the base image.
RUN install-php-extensions pdo_pgsql zip

WORKDIR /app

COPY frankenphp/composer.json ./composer.json
COPY principle /principle
COPY auth /auth
COPY app /app

RUN for d in /principle /auth; do \
        [ -d "$d" ] || { \
            echo "frankenphp: $d is missing from the build context; clone all repositories." >&2; \
            exit 1; \
        }; \
    done

RUN composer config repositories.principle \
        '{"type":"path","url":"/principle","options":{"symlink":false,"versions":{"iam-adty/empira-principle":"dev-main"}}}' \
    && composer config repositories.empira-auth \
        '{"type":"path","url":"/auth","options":{"symlink":false,"versions":{"iam-adty/empira-auth":"dev-main"}}}' \
    && composer install --no-interaction --no-progress --no-dev

# The loop is in the module, not in the application's front controller.
ENV EMPIRA_BOOTSTRAP=/app/bootstrap/app.php
ENV APP_RUNTIME=worker

EXPOSE 8080

CMD ["frankenphp", "php-server", "run", "-f", "/app/vendor/iam-adty/empira-frankenphp/worker.php"]