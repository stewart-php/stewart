#!/bin/sh
set -eu -o pipefail

# Roave reads one composer.json per repository, so each package is compared in a throwaway two-commit repository.

base="${1:?usage: bin/bc-check.sh <base-ref> [package...]}"
shift
packages="${*:-$(ls packages)}"
format="${BC_FORMAT:-console}"
root=$(pwd)
export COMPOSER_ROOT_VERSION=1.x-dev

versions=""
for manifest in packages/*/composer.json; do
    name=$(php -r 'echo json_decode(file_get_contents($argv[1]))->name;' "$manifest")
    versions="$versions${versions:+, }\"$name\": \"$COMPOSER_ROOT_VERSION\""
done

commit_package_state() {
    composer config --no-interaction repositories.monorepo "{\"type\": \"path\", \"url\": \"$root/packages/*\", \"options\": {\"versions\": {$versions}}}"
    composer config --no-interaction minimum-stability dev
    composer config --no-interaction prefer-stable true
    git add --all
    git -c user.name=bc-check -c user.email=bc-check@localhost commit --quiet --allow-empty --message "$1"
}

status=0

for package in $packages; do
    # The internal package carries no public API promise.
    case "$package" in support) continue ;; esac

    if ! git cat-file -e "$base:packages/$package/composer.json" 2>/dev/null; then
        echo "--- $package is new since $base"
        continue
    fi

    echo "--- $package against $base"
    work="${TMPDIR:-/tmp}/bc-check/$package"
    rm -rf "$work"
    mkdir -p "$work"

    git archive "$base" "packages/$package" | tar -x --strip-components=2 -C "$work"
    (cd "$work" && git init --quiet && commit_package_state base)

    find "$work" -mindepth 1 -maxdepth 1 ! -name .git -exec rm -rf {} +
    cp -R "packages/$package/." "$work/"
    rm -rf "$work/vendor" "$work/var" "$work/composer.lock"
    (cd "$work" && commit_package_state head && git repack -adq)

    (cd "$work" && php "$root/tools/roave-bc-check/vendor/bin/roave-backward-compatibility-check" --from=HEAD~1 --to=HEAD --format="$format") \
        | sed "s|file=/*|file=packages/$package/|" || status=1
done

exit "$status"
