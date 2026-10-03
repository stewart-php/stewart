#!/bin/sh
# Prepares /app from STEWART_BOOT_* settings, then runs the Stewart console; any other program runs as given.
set -eu

app_dir=/app
boot_prefix=STEWART_BOOT_
known_settings='STEWART_BOOT_GIT_URL STEWART_BOOT_GIT_REF STEWART_BOOT_GIT_TOKEN_FILE STEWART_BOOT_GIT_SSH_KEY_FILE STEWART_BOOT_COMPOSER STEWART_BOOT_GENERATE'
lock_stamp=vendor/.stewart-composer-lock

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
        [ -e "$entry" ] || continue
        [ "${entry##*/}" != lost+found ] && return 0
    done

    return 1
}

run_git() {
    git -c safe.directory='*' -c advice.detachedHead=false -C "$app_dir" "$@"
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
        key_copy=$(mktemp)
        cp "$STEWART_BOOT_GIT_SSH_KEY_FILE" "$key_copy"
        chmod 600 "$key_copy"
        export GIT_SSH_COMMAND="ssh -i $key_copy -o IdentitiesOnly=yes -o StrictHostKeyChecking=accept-new -o UserKnownHostsFile=/tmp/stewart-known-hosts"
    fi
}

check_out_git_ref() {
    [ -n "${STEWART_BOOT_GIT_URL:-}" ] || return 0
    ref="${STEWART_BOOT_GIT_REF:-main}"

    if [ ! -d "$app_dir/.git" ]; then
        ! holds_files "$app_dir" || fail "$app_dir holds files but no git checkout; give STEWART_BOOT_GIT_URL an empty volume"
        run_git init -q
        run_git remote add origin "$STEWART_BOOT_GIT_URL"
    else
        run_git remote set-url origin "$STEWART_BOOT_GIT_URL"
    fi

    configure_git_credentials
    log "fetching $ref"
    run_git fetch -q --depth 1 origin "$ref"
    run_git checkout -q --force FETCH_HEAD
    run_git clean -q -fd
    log "checked out $ref at $(run_git rev-parse --short HEAD)"
    unset GIT_CONFIG_COUNT GIT_CONFIG_KEY_0 GIT_CONFIG_VALUE_0 GIT_SSH_COMMAND
}

install_dependencies() {
    mode="${STEWART_BOOT_COMPOSER:-auto}"

    case "$mode" in
        auto | always) ;;
        never) return 0 ;;
        *) fail "STEWART_BOOT_COMPOSER is \"$mode\"; expected auto, always or never" ;;
    esac

    [ -f "$app_dir/composer.json" ] || fail "$app_dir has no composer.json; mount a Stewart project, set STEWART_BOOT_GIT_URL, or build an image from one"
    [ -f "$app_dir/composer.lock" ] || fail "$app_dir has no composer.lock; commit it so every start installs the same versions"

    lock_hash=$(sha256sum "$app_dir/composer.lock" | cut -d ' ' -f 1)

    if [ "$mode" = auto ] && [ -f "$app_dir/vendor/autoload.php" ] && [ "$(cat "$app_dir/$lock_stamp" 2>/dev/null || true)" = "$lock_hash" ]; then
        return 0
    fi

    # Keep the dev packages a developer installed into a mounted checkout.
    dev_option=--no-dev
    if grep -q '"dev": true' "$app_dir/vendor/composer/installed.json" 2>/dev/null; then
        dev_option=
    fi

    log "installing dependencies ${dev_option:-with dev packages}"
    composer install --working-dir="$app_dir" --no-interaction --no-progress --optimize-autoloader $dev_option
    printf '%s\n' "$lock_hash" > "$app_dir/$lock_stamp"
}

generate_classes() {
    case "${STEWART_BOOT_GENERATE:-0}" in
        0) return 0 ;;
        1) ;;
        *) fail "STEWART_BOOT_GENERATE is \"$STEWART_BOOT_GENERATE\"; expected 0 or 1" ;;
    esac

    log 'generating entity and service classes'
    php "$app_dir/vendor/bin/stewart" generate || log 'generation failed; starting with the committed classes'
}

run_boot_steps() {
    check_boot_settings
    check_out_git_ref
    install_dependencies
    generate_classes
}

is_program() {
    case "$1" in
        -*) return 1 ;;
        *) command -v "$1" > /dev/null 2>&1 ;;
    esac
}

if [ "${1:-}" = prepare ]; then
    run_boot_steps
    exit 0
fi

if [ $# -gt 0 ] && is_program "$1"; then
    exec "$@"
fi

run_boot_steps
clear_boot_settings
exec php "$app_dir/vendor/bin/stewart" "$@"
