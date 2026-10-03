#!/bin/sh
# Moves the project to a Stewart release line: every stewart-php/* requirement and every runtime image tag.
set -eu

version="${1:-}"
minor=$(printf '%s' "$version" | sed -nE 's/^([0-9]+\.[0-9]+)(\.[0-9]+)?$/\1/p')

if [ -z "$minor" ]; then
    echo "usage: make upgrade VERSION=X.Y (a patch such as X.Y.Z is reduced to its minor)" >&2
    exit 1
fi

list_stewart_packages() {
    php -r '
        $manifest = json_decode(file_get_contents("composer.json"), true);
        foreach (array_keys($manifest[$argv[1]] ?? []) as $package) {
            if (str_starts_with($package, "stewart-php/")) {
                echo $package, ":^", $argv[2], " ";
            }
        }
    ' "$1" "$minor"
}

packages=$(list_stewart_packages require)
dev_packages=$(list_stewart_packages require-dev)

if [ -n "$packages" ]; then
    composer require --no-update --no-interaction $packages
fi

if [ -n "$dev_packages" ]; then
    composer require --dev --no-update --no-interaction $dev_packages
fi

# An exact tag such as 0.1.5 becomes the minor's rolling tag; a -dev suffix stays.
for file in Dockerfile compose*.yaml deploy/*.yaml; do
    if [ -f "$file" ]; then
        sed -i -E "s#(stewart-php/runtime:)[0-9]+(\.[0-9]+){1,2}#\1$minor#g" "$file"
    fi
done

echo "Requirements and images now on $minor."
