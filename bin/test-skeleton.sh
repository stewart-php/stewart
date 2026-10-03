#!/bin/sh
# Installs skeleton/ against the packages in this checkout and runs what a new project runs first.
set -eu

target=var/skeleton
snapshot="$(pwd)/packages/codegen/tests/Fixtures/snapshot.json"

rm -rf "$target"
mkdir -p "$target"
cp -R skeleton/. "$target/"
cd "$target"

# The working tree stands in for the release line the skeleton requires, such as 0.1.x-dev for ^0.1.
minor=$(php -r 'preg_match("/\^(\d+\.\d+)/", json_decode(file_get_contents("composer.json"), true)["require"]["stewart-php/runtime"], $m); echo $m[1];')
versions=""
for manifest in ../../packages/*/composer.json; do
    name=$(php -r 'echo json_decode(file_get_contents($argv[1]))->name;' "$manifest")
    versions="$versions${versions:+, }\"$name\": \"$minor.x-dev\""
done

composer config repositories.monorepo "{\"type\": \"path\", \"url\": \"../../packages/*\", \"options\": {\"symlink\": false, \"versions\": {$versions}}}"
composer config minimum-stability dev
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
