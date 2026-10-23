#!/bin/sh
# Upgrades a 1.8.0 project, whose apps are that release's docs examples, to this checkout with the upgrading commands
# CHANGELOG.md publishes, then runs what the project runs before a deploy.
set -eu

from=v1.8.0
root=$(pwd)
source=var/upgrade-source
target=var/upgrade
commands="$root/$source/upgrading.sh"

rm -rf "$source" "$target"
mkdir -p "$source" "$target"
git archive "$from" skeleton docs/examples | tar -x -C "$source"

cp -R "$source/skeleton/." "$target/"
cp -R "$source/docs/examples/apps/." "$target/apps/"
cp -R "$source/docs/examples/tests/." "$target/tests/"
cp -R "$source/docs/examples/generated/." "$target/generated/"
cp "$source/docs/examples/snapshot.json" "$target/snapshot.json"

# The first bash block under Upgrading in the 2.0 section, unindented, exactly as the changelog shows it.
awk '
    /^## \[/ { in_release = ($0 ~ /^## \[(Unreleased|2\.0\.0)\]/); in_upgrading = 0 }
    in_release && /^### / { in_upgrading = ($0 == "### Upgrading") }
    in_upgrading && !done && /^ *```bash$/ { in_block = 1; indent = index($0, "`") - 1; next }
    in_block && /^ *```$/ { in_block = 0; done = 1 }
    in_block { print substr($0, indent + 1) }
' CHANGELOG.md > "$commands"
test -s "$commands"

cd "$target"

sh bin/upgrade-stewart.sh 2.0
sh ../../bin/link-packages.sh copy
composer update --no-interaction --no-progress
composer run-script create-env

export STEWART_HOME_ASSISTANT__URL=ws://homeassistant.invalid:8123/api/websocket
export STEWART_HOME_ASSISTANT__TOKEN=unused
# Their docs tests hand them an option, MQTT or a service that this project does not configure.
export STEWART_APPS__BATTERY_ALERT__ENABLED=false
export STEWART_APPS__DOOR_ALARM__ENABLED=false
export STEWART_APPS__THERMOSTAT_BRIDGE__ENABLED=false
export STEWART_APPS__ZIGBEE_REMOTE__ENABLED=false

sh -eu "$commands"

vendor/bin/stewart generate --snapshot-in=snapshot.json
vendor/bin/stewart check

# The docs examples name entities and actions their snapshot lacks; this checks the rewritten code, not those names.
cat > phpstan-upgrade.neon <<'NEON'
includes:
    - phpstan.neon.dist
parameters:
    ignoreErrors:
        - identifier: stewart.entityId
        - identifier: stewart.actionName
NEON
vendor/bin/phpstan analyse --no-progress --configuration=phpstan-upgrade.neon
vendor/bin/phpunit
