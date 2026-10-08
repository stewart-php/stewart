#!/bin/sh
# Prepares /app from STEWART_BOOT_* settings, then runs the Stewart console; any other program runs as given.
set -eu

app_dir=/app
boot_prefix=STEWART_BOOT_
known_settings='STEWART_BOOT_GIT_URL STEWART_BOOT_GIT_REF STEWART_BOOT_GIT_TOKEN_FILE STEWART_BOOT_GIT_SSH_KEY_FILE STEWART_BOOT_COMPOSER STEWART_BOOT_GENERATE'
lock_stamp=vendor/.stewart-composer-lock

repository_dir=$app_dir/repo.git
releases_dir=$app_dir/releases
rejected_dir=$releases_dir/.rejected
current_link=$app_dir/current
next_link=$app_dir/next
kept_releases=3
restart_exit_code=75

log() {
    printf 'stewart-entrypoint: %s\n' "$*" >&2
}

fail() {
    log "$*"
    exit 1
}

list_boot_settings() {
    env | sed -n "s/^\(${boot_prefix}[A-Z0-9_]*\)=.*/\1/p"
}

check_boot_settings() {
    for setting in $(list_boot_settings); do
        case " $known_settings " in
            *" $setting "*) ;;
            *) fail "$setting is not an entrypoint setting; known: $known_settings" ;;
        esac
    done
}

clear_boot_settings() {
    for setting in $(list_boot_settings); do
        unset "$setting"
    done
}

holds_files() {
    for entry in "$1"/* "$1"/.[!.]* "$1"/..?*; do
        [ -e "$entry" ] || [ -L "$entry" ] || continue
        [ "${entry##*/}" != lost+found ] && return 0
    done

    return 1
}

uses_git() {
    [ -n "${STEWART_BOOT_GIT_URL:-}" ]
}

run_repository_git() {
    git -c safe.directory='*' --git-dir="$repository_dir" "$@"
}

configure_git_credentials() {
    if [ -n "${STEWART_BOOT_GIT_TOKEN_FILE:-}" ]; then
        [ -r "$STEWART_BOOT_GIT_TOKEN_FILE" ] || fail "STEWART_BOOT_GIT_TOKEN_FILE points to $STEWART_BOOT_GIT_TOKEN_FILE, which cannot be read"
        credential=$(printf 'x-access-token:%s' "$(tr -d '\r\n' < "$STEWART_BOOT_GIT_TOKEN_FILE")" | base64 | tr -d '\n')
        export GIT_CONFIG_COUNT=1 GIT_CONFIG_KEY_0=http.extraHeader GIT_CONFIG_VALUE_0="Authorization: Basic $credential"
    fi

    if [ -n "${STEWART_BOOT_GIT_SSH_KEY_FILE:-}" ]; then
        [ -r "$STEWART_BOOT_GIT_SSH_KEY_FILE" ] || fail "STEWART_BOOT_GIT_SSH_KEY_FILE points to $STEWART_BOOT_GIT_SSH_KEY_FILE, which cannot be read"
        # ssh refuses a key that others can read, and mounted secrets usually can.
        key_copy=/tmp/stewart-git-ssh-key
        rm -f "$key_copy"
        (umask 077 && cp "$STEWART_BOOT_GIT_SSH_KEY_FILE" "$key_copy")
        export GIT_SSH_COMMAND="ssh -i $key_copy -o IdentitiesOnly=yes -o StrictHostKeyChecking=accept-new -o UserKnownHostsFile=/tmp/stewart-known-hosts"
    fi
}

forget_git_credentials() {
    unset GIT_CONFIG_COUNT GIT_CONFIG_KEY_0 GIT_CONFIG_VALUE_0 GIT_SSH_COMMAND
}

install_dependencies() {
    project_dir=$1
    previous_dir=${2:-}
    mode="${STEWART_BOOT_COMPOSER:-auto}"

    case "$mode" in
        auto | always) ;;
        never) return 0 ;;
        *) fail "STEWART_BOOT_COMPOSER is \"$mode\"; expected auto, always or never" ;;
    esac

    [ -f "$project_dir/composer.json" ] || fail "$project_dir has no composer.json; mount a Stewart project, set STEWART_BOOT_GIT_URL, or build an image from one"
    [ -f "$project_dir/composer.lock" ] || fail "$project_dir has no composer.lock; commit it so every start installs the same versions"

    lock_hash=$(sha256sum "$project_dir/composer.lock" | cut -d ' ' -f 1)

    if [ "$mode" = auto ] && [ -f "$project_dir/vendor/autoload.php" ] && [ "$(cat "$project_dir/$lock_stamp" 2>/dev/null || true)" = "$lock_hash" ]; then
        return 0
    fi

    if [ "$mode" = auto ] && [ -n "$previous_dir" ] && [ -f "$previous_dir/vendor/autoload.php" ] && [ "$(cat "$previous_dir/$lock_stamp" 2>/dev/null || true)" = "$lock_hash" ]; then
        log 'reusing the dependencies of the running release'
        cp -a "$previous_dir/vendor" "$project_dir/vendor"
        # The class map must list the new release's own classes.
        composer dump-autoload --working-dir="$project_dir" --no-interaction --optimize $(select_dev_option "$project_dir") >&2
        return 0
    fi

    dev_option=$(select_dev_option "$project_dir")
    log "installing dependencies ${dev_option:-with dev packages}"
    composer install --working-dir="$project_dir" --no-interaction --no-progress --optimize-autoloader $dev_option >&2
    printf '%s\n' "$lock_hash" > "$project_dir/$lock_stamp"
}

# Keep the dev packages a developer installed into a mounted checkout.
select_dev_option() {
    if grep -q '"dev": true' "$1/vendor/composer/installed.json" 2>/dev/null; then
        return 0
    fi

    printf '%s' --no-dev
}

generate_classes() {
    case "${STEWART_BOOT_GENERATE:-0}" in
        0) return 0 ;;
        1) ;;
        *) fail "STEWART_BOOT_GENERATE is \"$STEWART_BOOT_GENERATE\"; expected 0 or 1" ;;
    esac

    log 'generating entity and service classes'
    php "$1/vendor/bin/stewart" generate >&2 || log 'generation failed; starting with the committed classes'
}

find_current_commit() {
    [ -L "$current_link" ] || return 0
    target=$(readlink "$current_link")
    printf '%s' "${target##*/}"
}

prepare_release_volume() {
    if [ -d "$app_dir/.git" ] && [ ! -d "$releases_dir" ]; then
        log "replacing the checkout in $app_dir with release directories"
        find "$app_dir" -mindepth 1 -maxdepth 1 ! -name lost+found -exec rm -rf {} +
    elif [ ! -d "$releases_dir" ] && holds_files "$app_dir"; then
        fail "$app_dir holds files but no Stewart releases; give STEWART_BOOT_GIT_URL an empty volume"
    fi

    mkdir -p "$releases_dir"
    rm -rf "$releases_dir"/.preparing-*

    if [ ! -d "$repository_dir" ]; then
        git init -q --bare "$repository_dir"
        run_repository_git remote add origin "$STEWART_BOOT_GIT_URL"
    else
        run_repository_git remote set-url origin "$STEWART_BOOT_GIT_URL"
    fi
}

fetch_latest_commit() {
    ref="${STEWART_BOOT_GIT_REF:-main}"
    configure_git_credentials
    run_repository_git fetch -q --depth 1 origin "$ref"
    forget_git_credentials
    run_repository_git rev-parse FETCH_HEAD
}

build_release() {
    commit=$1
    staging="$releases_dir/.preparing-$commit"
    current_commit=$(find_current_commit)

    log "preparing $commit"
    mkdir "$staging"
    run_repository_git archive --format=tar -o "$staging.tar" "$commit"
    tar -xf "$staging.tar" -C "$staging"
    rm "$staging.tar"
    install_dependencies "$staging" "${current_commit:+$releases_dir/$current_commit}"
    generate_classes "$staging"
    mv "$staging" "$releases_dir/$commit"
}

# Prints "<commit> current", "<commit> rejected" or "<commit> prepared" for the head of the tracked ref.
prepare_latest_release() {
    commit=$(fetch_latest_commit)

    if [ "$commit" = "$(find_current_commit)" ]; then
        printf '%s current\n' "$commit"
    elif [ -f "$rejected_dir/$commit" ]; then
        printf '%s rejected\n' "$commit"
    else
        [ -d "$releases_dir/$commit" ] || build_release "$commit"
        printf '%s prepared\n' "$commit"
    fi
}

find_release_dir() {
    case "$1" in
        '' | *[!0-9a-f]*) fail "\"$1\" is not a commit hash" ;;
    esac
    [ -d "$releases_dir/$1" ] || fail "release $1 has not been prepared"
    printf '%s' "$releases_dir/$1"
}

check_release() {
    commit=$1
    shift
    # Callers test the result, which turns off set -e in here.
    release_dir=$(find_release_dir "$commit") || exit 1

    if report=$(cd "$release_dir" && php vendor/bin/stewart check --no-ansi "$@" 2>&1); then
        printf '%s\n' "$report" >&2
        return 0
    fi

    printf '%s\n' "$report" >&2
    mkdir -p "$rejected_dir"
    printf '%s\n' "$report" | sed -n '/./{p;q;}' > "$rejected_dir/$commit"
    rm -rf "$release_dir"
    log "rejected $commit"

    return 1
}

stage_release() {
    find_release_dir "$1" > /dev/null
    ln -sfn "releases/$1" "$next_link"
}

replace_current_link() {
    # rename() swaps the link in one step, which busybox mv cannot do for a link to a directory.
    php -r 'exit(rename($argv[1], $argv[2]) ? 0 : 1);' "$1" "$current_link"
    touch "$releases_dir/$(find_current_commit)"
    log "running $(find_current_commit)"
}

activate_release() {
    ln -sfn "releases/$1" "$app_dir/.current-new"
    replace_current_link "$app_dir/.current-new"
}

prune_releases() {
    current_commit=$(find_current_commit)

    for name in $(ls -1t "$releases_dir" | tail -n +$((kept_releases + 1))); do
        [ "$name" = "$current_commit" ] || rm -rf "${releases_dir:?}/$name"
    done
}

# Only --config matters to the check; the rest of the arguments belong to the command being started.
find_config_arguments() {
    expects_value=
    for argument in "$@"; do
        if [ -n "$expects_value" ]; then
            printf '%s' "--config=$argument"
            return 0
        fi

        case "$argument" in
            --config=*) printf '%s' "$argument"; return 0 ;;
            --config | -c) expects_value=1 ;;
            -c?*) printf '%s' "--config=${argument#-c}"; return 0 ;;
        esac
    done
}

prepare_git_release() {
    prepare_release_volume
    rm -f "$next_link"
    latest=$(prepare_latest_release)
    commit=${latest% *}
    state=${latest#* }

    if [ -z "$(find_current_commit)" ]; then
        activate_release "$commit"
    elif [ "$state" = prepared ]; then
        config_argument=$(find_config_arguments "$@")
        if check_release "$commit" $config_argument; then
            activate_release "$commit"
        else
            log "keeping $(find_current_commit)"
        fi
    fi

    prune_releases
}

run_releases() {
    while :; do
        cd -P "$current_link"
        php vendor/bin/stewart "$@" &
        daemon_pid=$!
        trap 'kill -TERM "$daemon_pid" 2> /dev/null || true' TERM
        trap 'kill -INT "$daemon_pid" 2> /dev/null || true' INT

        status=0
        wait "$daemon_pid" || status=$?
        # A trapped signal ends wait early; wait again for the daemon's own exit.
        while kill -0 "$daemon_pid" 2> /dev/null; do
            status=0
            wait "$daemon_pid" || status=$?
        done
        trap - TERM INT

        if [ "$status" -ne "$restart_exit_code" ] || [ ! -L "$next_link" ]; then
            exit "$status"
        fi

        replace_current_link "$next_link"
        prune_releases
    done
}

run_boot_steps() {
    if uses_git; then
        prepare_git_release "$@"
    else
        install_dependencies "$app_dir"
        generate_classes "$app_dir"
    fi
}

require_git() {
    uses_git || fail "$1 needs STEWART_BOOT_GIT_URL"
}

is_program() {
    case "$1" in
        -*) return 1 ;;
        *) command -v "$1" > /dev/null 2>&1 ;;
    esac
}

case "${1:-}" in
    prepare)
        check_boot_settings
        run_boot_steps
        exit 0
        ;;
    release-prepare)
        check_boot_settings
        require_git "$1"
        prepare_latest_release
        exit 0
        ;;
    release-check)
        require_git "$1"
        shift
        check_release "$@"
        exit 0
        ;;
    release-stage)
        require_git "$1"
        stage_release "${2:-}"
        exit 0
        ;;
esac

if [ $# -gt 0 ] && is_program "$1"; then
    exec "$@"
fi

check_boot_settings
run_boot_steps "$@"

if uses_git; then
    # Kept for the release-* commands the daemon runs while it polls.
    run_releases "$@"
fi

clear_boot_settings
exec php "$app_dir/vendor/bin/stewart" "$@"
