#!/bin/sh
set -eu -o pipefail

# Roave reads one composer.json per repository, so each package is compared in a throwaway two-commit repository.

base="${1:?usage: bin/bc-check.sh <base-ref> [package...]}"
shift
packages="${*:-contracts testing}"
format="${BC_FORMAT:-console}"
root=$(pwd)
scratch="${TMPDIR:-/tmp}/bc-check"
export COMPOSER_ROOT_VERSION=2.x-dev

versions=""
for manifest in packages/*/composer.json; do
    name=$(php -r 'echo json_decode(file_get_contents($argv[1]))->name;' "$manifest")
    versions="$versions${versions:+, }\"$name\": \"$COMPOSER_ROOT_VERSION\""
done

# The base revision resolves its sibling packages from the base ref, so it is reflected against the code it shipped with.
base_packages="$scratch/base-packages"
rm -rf "$base_packages"
mkdir -p "$base_packages"
git archive "$base" packages | tar -x --strip-components=1 -C "$base_packages"

commit_package_state() {
    composer config --no-interaction repositories.monorepo "{\"type\": \"path\", \"url\": \"$2/*\", \"options\": {\"versions\": {$versions}}}"
    composer config --no-interaction minimum-stability dev
    composer config --no-interaction prefer-stable true
    git add --all
    git -c user.name=bc-check -c user.email=bc-check@localhost commit --quiet --allow-empty --message "$1"
}

status=0

for package in $packages; do
    if ! git cat-file -e "$base:packages/$package/composer.json" 2>/dev/null; then
        echo "--- $package is new since $base"
        continue
    fi

    echo "--- $package against $base"
    work="$scratch/$package"
    rm -rf "$work"
    mkdir -p "$work"

    cp -R "$base_packages/$package/." "$work/"
    (cd "$work" && git init --quiet && commit_package_state base "$base_packages")

    find "$work" -mindepth 1 -maxdepth 1 ! -name .git -exec rm -rf {} +
    cp -R "packages/$package/." "$work/"
    rm -rf "$work/vendor" "$work/var" "$work/composer.lock"
    (cd "$work" && commit_package_state head "$root/packages" && git repack -adq)

    (cd "$work" && php "$root/tools/roave-bc-check/vendor/bin/roave-backward-compatibility-check" --from=HEAD~1 --to=HEAD --format="$format") \
        | sed "s|file=/*|file=packages/$package/|" || status=1
done

exit "$status"
