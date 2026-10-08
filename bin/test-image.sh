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
commit_project() {
    git -C "$work/project" add -A
    git -C "$work/project" -c user.name=test -c user.email=test@example.invalid commit -q -m "$1"
    git -C "$work/project" push -q origin main
}
git init -q --bare -b main "$work/project.git"
git -C "$work/project" init -q -b main
git -C "$work/project" remote add origin "$PWD/$work/project.git"
commit_project 'skeleton'

docker volume create "$volume" > /dev/null
boot() {
    docker run --rm -v "$volume:/app" -v "$PWD:/monorepo:ro" \
        -e STEWART_BOOT_GIT_URL="file:///monorepo/$work/project.git" -e STEWART_BOOT_GIT_REF=main \
        -e STEWART_HOME_ASSISTANT__URL=ws://example.invalid/api/websocket -e STEWART_HOME_ASSISTANT__TOKEN=image-test \
        "$image" "$@"
}
current_commit() {
    boot readlink /app/current | sed 's#.*/##'
}
head_commit() {
    git -C "$work/project" rev-parse HEAD
}
fail_test() {
    echo "$*" >&2
    exit 1
}

boot prepare
[ "$(current_commit)" = "$(head_commit)" ] || fail_test 'the first start did not run the head of the ref'
boot list --raw | grep -q '^generate '
case "$(boot prepare 2>&1)" in
    *'installing dependencies'* | *preparing*) fail_test 'an unchanged ref was prepared again' ;;
esac

echo '--- a new commit is prepared, checked and staged'
printf '\n' >> "$work/project/README.md"
commit_project 'touch the readme'
prepared=$(boot release-prepare 2> "$work/prepare.log")
[ "$prepared" = "$(head_commit) prepared" ] || fail_test "release-prepare printed \"$prepared\""
grep -q 'reusing the dependencies' "$work/prepare.log" || fail_test 'an unchanged composer.lock was installed again'
boot release-check "$(head_commit)"
boot release-stage "$(head_commit)"
[ "$(boot readlink /app/next)" = "releases/$(head_commit)" ] || fail_test 'release-stage did not point next at the release'
boot prepare
[ "$(current_commit)" = "$(head_commit)" ] || fail_test 'a checked commit did not replace the running one'

echo '--- a commit that does not load is rejected'
good_commit=$(head_commit)
printf '<?php\n\nfinal class Broken {\n' > "$work/project/apps/Broken.php"
commit_project 'break an app'
bad_commit=$(head_commit)
boot prepare
[ "$(current_commit)" = "$good_commit" ] || fail_test 'a commit that failed the check replaced the running one'
[ "$(boot release-prepare)" = "$bad_commit rejected" ] || fail_test 'a rejected commit was prepared again'
boot cat "/app/releases/.rejected/$bad_commit" | grep -q 'Broken.php failed to load' || fail_test 'the rejection lacks its reason'

echo '--- only the newest releases are kept'
rm "$work/project/apps/Broken.php"
for round in 1 2 3; do
    printf '\n' >> "$work/project/README.md"
    commit_project "round $round"
    boot prepare
done
[ "$(current_commit)" = "$(head_commit)" ] || fail_test 'the last good commit is not running'
[ "$(boot ls /app/releases | wc -l)" -eq 3 ] || fail_test 'old releases were not pruned'

echo '--- an in-place checkout from an older image is replaced'
docker volume rm -f "$volume" > /dev/null
docker volume create "$volume" > /dev/null
docker run --rm -v "$volume:/app" -v "$PWD:/monorepo:ro" "$image" git -c safe.directory='*' clone -q "file:///monorepo/$work/project.git" /app
boot prepare
[ "$(current_commit)" = "$(head_commit)" ] || fail_test 'the old checkout was not replaced by a release'
! boot test -e /app/.git || fail_test 'the old checkout was left behind'

echo 'runtime image: ok'
