#!/usr/bin/env sh
set -eu
APP_ENV=test php bin/console cache:clear --no-debug
APP_ENV=test php bin/console doctrine:database:create --if-not-exists
APP_ENV=test php bin/console doctrine:migrations:migrate --no-interaction
APP_ENV=test php bin/console app:demo:seed
vendor/bin/phpunit "$@"
