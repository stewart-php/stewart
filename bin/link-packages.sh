#!/bin/sh
# Points the Composer project in the working directory, two levels below the root, at this checkout's packages.
set -eu

mode="${1:?usage: bin/link-packages.sh copy|symlink}"

# The working tree stands in for the release line the project requires, such as 0.1.x-dev for ^0.1.
minor=$(php -r 'preg_match("/\^(\d+\.\d+)/", json_decode(file_get_contents("composer.json"), true)["require"]["stewart-php/runtime"], $m); echo $m[1];')
versions=""
for manifest in ../../packages/*/composer.json; do
    name=$(php -r 'echo json_decode(file_get_contents($argv[1]))->name;' "$manifest")
    versions="$versions${versions:+, }\"$name\": \"$minor.x-dev\""
done

symlink=false
if [ "$mode" = symlink ]; then
    symlink=true
fi

composer config repositories.monorepo "{\"type\": \"path\", \"url\": \"../../packages/*\", \"options\": {\"symlink\": $symlink, \"versions\": {$versions}}}"
composer config minimum-stability dev
