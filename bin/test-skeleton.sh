#!/bin/sh
# Installs skeleton/ against the packages in this checkout and runs what a new project runs first.
set -eu

target=var/skeleton
snapshot="$(pwd)/packages/codegen/tests/Fixtures/snapshot.json"

rm -rf "$target"
mkdir -p "$target"
cp -R skeleton/. "$target/"
cd "$target"

# Copies, not symlinks, so the install sees only what each package ships.
sh ../../bin/link-packages.sh copy
composer update --no-interaction --no-progress
composer run-script create-env
grep -Eq '^STEWART_CONTROL__TOKEN=[0-9a-f]{32}$' .env

export STEWART_HOME_ASSISTANT__URL=ws://homeassistant.invalid:8123/api/websocket
export STEWART_HOME_ASSISTANT__TOKEN=unused

vendor/bin/stewart doctor
vendor/bin/stewart list --raw | grep -q '^generate '
vendor/bin/stewart config:dump > /dev/null
vendor/bin/stewart generate --snapshot-in="$snapshot"
for file in generated/*.php; do
    php -l "$file" > /dev/null
done

vendor/bin/phpstan analyse --no-progress
vendor/bin/phpunit

# Last, since it points the project at a release line that does not exist.
sh bin/upgrade-stewart.sh 9.9.1
grep -q '"stewart-php/runtime": "^9.9"' composer.json
grep -q '"psr/log": "^3.0"' composer.json
grep -q 'stewart-php/runtime:9.9$' Dockerfile
grep -q 'stewart-php/runtime:9.9-dev$' compose.dev.yaml
