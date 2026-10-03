#!/bin/sh
# Smoke-tests the runtime image on the host: a skeleton project mounted, then the same project cloned at boot, twice.
set -eu

image="${RUNTIME_IMAGE:?set RUNTIME_IMAGE to the image under test}"
user="$(id -u):$(id -g)"
work=var/image-test
volume="stewart-image-test-$$"

cleanup() {
    docker volume rm -f "$volume" > /dev/null 2>&1 || true
}
trap cleanup EXIT

echo '--- an unknown entrypoint setting is refused'
if docker run --rm -e STEWART_BOOT_TYPO=1 "$image" --version 2> /dev/null; then
    echo 'STEWART_BOOT_TYPO was accepted' >&2
    exit 1
fi

rm -rf "$work"
mkdir -p "$work"
cp -R skeleton/. "$work/project"

# The working tree stands in for the release line the skeleton requires, such as 0.1.x-dev for ^0.1.
minor=$(sed -n 's/.*"stewart-php\/runtime": "\^\([0-9]*\.[0-9]*\)".*/\1/p' skeleton/composer.json)
versions=""
for manifest in packages/*/composer.json; do
    name=$(sed -n 's/^    "name": "\(.*\)",$/\1/p' "$manifest")
    versions="$versions${versions:+, }\"$name\": \"$minor.x-dev\""
done

composer_in_project() {
    docker run --rm -u "$user" -v "$PWD:/monorepo" -w "/monorepo/$work/project" "$image" composer "$@"
}
composer_in_project config repositories.monorepo "{\"type\": \"path\", \"url\": \"/monorepo/packages/*\", \"options\": {\"symlink\": false, \"versions\": {$versions}}}"
composer_in_project config minimum-stability dev
composer_in_project update --no-install --no-interaction --no-progress

echo '--- a mounted project'
mounted() {
    docker run --rm -u "$user" -v "$PWD/$work/project:/app" -v "$PWD:/monorepo:ro" -e STEWART_BOOT_COMPOSER=auto "$image" "$@"
}
mounted doctor
second_start=$(mounted --version 2>&1)
case "$second_start" in
    *'installing dependencies'*)
        echo 'an unchanged composer.lock was installed again' >&2
        exit 1
        ;;
esac

echo '--- a project cloned at boot'
git -C "$work/project" init -q -b main
git -C "$work/project" add -A
git -C "$work/project" -c user.name=test -c user.email=test@example.invalid commit -q -m 'skeleton'
git clone -q --bare "$work/project" "$work/project.git"

docker volume create "$volume" > /dev/null
boot() {
    docker run --rm -v "$volume:/app" -v "$PWD:/monorepo:ro" \
        -e STEWART_BOOT_GIT_URL="file:///monorepo/$work/project.git" -e STEWART_BOOT_GIT_REF=main \
        "$image" "$@"
}
boot prepare
boot list --raw | grep -q '^generate '
boot prepare

echo 'runtime image: ok'
