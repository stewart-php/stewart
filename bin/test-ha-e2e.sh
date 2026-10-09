#!/bin/sh
# Smoke-tests exposure against a real Home Assistant: the skeleton's hello app publishes a sensor and a switch through the integration.
set -eu

: "${HA_E2E_IMAGE:?set HA_E2E_IMAGE to the Home Assistant image under test}"
project=var/ha-e2e

e2e() {
    docker compose -f .docker/ha-e2e/compose.yaml "$@"
}
driver() {
    e2e run --rm driver "$@"
}
finish() {
    status=$?
    if [ "$status" -ne 0 ]; then
        e2e logs --no-color homeassistant stewart >&2 || true
    fi
    e2e down -v --remove-orphans > /dev/null 2>&1 || true
    exit "$status"
}
trap finish EXIT

rm -rf "$project"
mkdir -p "$project"
cp -R skeleton/. "$project/"
docker compose run --rm --no-deps -w "/app/$project" php \
    sh -c 'sh ../../bin/link-packages.sh copy && composer update --no-interaction --no-progress --quiet'

echo '--- home assistant with the stewart integration'
e2e up -d --wait homeassistant
driver provision

echo '--- the hello app exposes its sensor'
e2e up -d stewart
driver await-sensor 0

echo '--- a watched change updates the sensor'
driver toggle
driver await-sensor 1

echo '--- a switch command waits for the app, which then stops counting'
driver switch off
driver toggle
driver switch on
driver toggle
driver await-sensor 2

echo '--- the sensor turns unavailable when Stewart stops'
e2e stop stewart
driver await-sensor unavailable

echo 'home assistant e2e: ok'
