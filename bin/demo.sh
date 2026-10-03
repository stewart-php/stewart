#!/bin/sh
# Creates or deletes var/demo: the skeleton, installed against this checkout's packages through symlinks.
set -eu

demo=var/demo
kept=var/demo-kept

create_demo() {
    rm -rf "$demo"
    mkdir -p "$demo"
    cp -R skeleton/. "$demo/"

    if [ -d "$kept" ]; then
        cp -R "$kept/." "$demo/"
    fi

    cd "$demo"
    sh ../../bin/link-packages.sh symlink
    composer update --no-interaction --no-progress
    composer run-script create-env
}

clean_demo() {
    rm -rf "$kept"

    if [ -z "${PURGE:-}" ] && [ -d "$demo/apps" ]; then
        mkdir -p "$kept"
        cp -R "$demo/apps" "$kept/"

        if [ -f "$demo/.env" ]; then
            cp "$demo/.env" "$kept/"
        fi
    fi

    rm -rf "$demo"
}

case "${1:?usage: bin/demo.sh create|clean}" in
    create) create_demo ;;
    clean) clean_demo ;;
    *) echo "usage: bin/demo.sh create|clean" >&2; exit 1 ;;
esac
