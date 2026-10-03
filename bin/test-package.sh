#!/bin/sh
set -eu

package="${1:?usage: bin/test-package.sh <package>}"
source="packages/$package"
target="var/isolation/$package"

if [ ! -f "$source/composer.json" ]; then
    echo "No package at $source" >&2
    exit 1
fi

rm -rf "$target"
mkdir -p "$target"

for entry in src tests config bin composer.json phpunit.xml.dist; do
    if [ -e "$source/$entry" ]; then
        cp -R "$source/$entry" "$target/"
    fi
done

cd "$target"
export COMPOSER_ROOT_VERSION=0.x-dev

# Pinned versions, so a detached or shallow checkout resolves the siblings without guessing from git.
versions=""
for manifest in ../../../packages/*/composer.json; do
    name=$(php -r 'echo json_decode(file_get_contents($argv[1]))->name;' "$manifest")
    versions="$versions${versions:+, }\"$name\": \"$COMPOSER_ROOT_VERSION\""
done

# Copies, not symlinks, so the install sees only what each sibling package ships.
composer config repositories.monorepo "{\"type\": \"path\", \"url\": \"../../../packages/*\", \"options\": {\"symlink\": false, \"versions\": {$versions}}}"
composer config minimum-stability dev
composer config prefer-stable true
composer update --no-interaction --no-progress ${LOWEST:+--prefer-lowest}

vendor/bin/phpunit ${SUITE:+--testsuite="$SUITE"}
