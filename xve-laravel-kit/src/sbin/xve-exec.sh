#!/bin/bash
#
# xve-exec.sh — privileged subcommand dispatcher for XVE Laravel Kit.
#
# Runs as root via Plesk's pm_ApiCli::callSbin. Replaces the previous
# `eval "$1"` wrapper: there is NO eval and NO shell interpretation of
# caller-supplied data. Each subcommand runs a FIXED command with the
# caller's arguments passed as separate, quoted positional parameters.
#
# Usage: xve-exec.sh <subcommand> [args...]
#
set -euo pipefail

# Build a pinned GIT_SSH_COMMAND. $1 = private key path, $2 = known_hosts path.
git_ssh_cmd() {
    printf 'ssh -i %q -o UserKnownHostsFile=%q -o StrictHostKeyChecking=yes -o IdentitiesOnly=yes -o PasswordAuthentication=no' "$1" "$2"
}

cmd="${1:-}"
shift || true

case "$cmd" in
    mkdir-p)            mkdir -p -- "$1" ;;
    rm-f)               rm -f -- "$1" ;;
    rm-rf)
        [ -n "${1:-}" ] || { echo "rm-rf: empty path" >&2; exit 64; }
        [ "$1" != "/" ] || { echo "rm-rf: refusing /" >&2; exit 64; }
        rm -rf -- "$1" ;;
    ln-sfn)             ln -sfn -- "$1" "$2" ;;
    mv)                 mv -- "$1" "$2" ;;
    mv-tf)              mv -Tf -- "$1" "$2" ;;
    cp)                 cp -- "$1" "$2" ;;
    chmod)              chmod "$1" -- "$2" ;;
    chown)              chown "$1:$2" -- "$3" ;;
    chown-h)            chown -h "$1:$2" -- "$3" ;;
    chown-r)            chown -R "$1:$2" -- "$3" ;;
    fix-shared-perms)
        find "$1" -type d -exec chmod 775 {} +
        find "$1" -type f -exec chmod 664 {} + ;;
    truncate0)          truncate -s 0 -- "$1" ;;
    tail-n)             tail -n "$1" -- "$2" 2>/dev/null ;;
    ls-1r)              ls -1r -- "$1" 2>/dev/null ;;
    test-dir)           if [ -d "$1" ]; then echo yes; else echo no; fi ;;
    test-dir-writable)  if [ -d "$1" ] && [ -w "$1" ]; then echo yes; else echo no; fi ;;
    tar-czf-shared)     tar -czf "$1" -C "$2" shared ;;

    ssh-keygen)
        mkdir -p -- "$1"
        ssh-keygen -t ed25519 -f "$2" -N '' -C "$3" 2>&1 ;;

    git-version)        git --version ;;
    git-ls-remote)
        GIT_SSH_COMMAND="$(git_ssh_cmd "$1" "$2")" git ls-remote --heads "$3" 2>&1 ;;
    git-archive-file)
        GIT_SSH_COMMAND="$(git_ssh_cmd "$1" "$2")" git archive --remote="$3" "$4" "$5" 2>/dev/null | tar -xO "$5" 2>/dev/null ;;
    git-clone-file)
        q=""; [ "$3" = "1" ] && q="--quiet"
        GIT_SSH_COMMAND="$(git_ssh_cmd "$1" "$2")" git clone --depth 1 $q --no-checkout --branch "$4" "$5" "$6" 2>&1
        git -C "$6" checkout HEAD -- "$7" 2>&1 ;;
    git-clone)
        q=""; [ "$3" = "1" ] && q="--quiet"
        GIT_SSH_COMMAND="$(git_ssh_cmd "$1" "$2")" git clone --depth 1 $q --branch "$4" "$5" "$6" 2>&1 ;;
    git-rev-parse)      git -C "$1" rev-parse HEAD 2>/dev/null ;;
    git-log)
        case "$2" in %s|%an) ;; *) echo "git-log: bad format" >&2; exit 64 ;; esac
        git -C "$1" log -1 --pretty="$2" 2>/dev/null ;;

    run-script-as-user)
        # $1 = user, $2 = script path. Chowns to the user then runs it.
        chown "$1" -- "$2" 2>/dev/null || true
        su -s /bin/bash "$1" -c 'bash -- "$1"' xlk "$2" 2>&1 ;;

    plesk-site-wwwroot)
        plesk bin site --update "$1" -www-root "$2" ;;
    plesk-subscription-create)
        plesk bin subscription --create "$1" -owner admin -login "$2" -passwd "$3" -ip "$4" -hosting true 2>&1 ;;

    curl-health)
        curl -sfL --max-time "$1" -o /dev/null -w '%{http_code}' "$2" 2>&1 ;;
    curl-teams)
        curl -sf -m 10 -H 'Content-Type: application/json' -d "$2" "$1" 2>&1 ;;

    php-version)
        # $1 = absolute path to a php binary. Reads its version string.
        "$1" -r 'echo PHP_VERSION;' 2>/dev/null ;;
    php-bindir-latest)
        # Newest installed Plesk PHP bin dir. Fixed glob, no caller input.
        ls -d /opt/plesk/php/*/bin 2>/dev/null | sort -V | tail -1 || true ;;
    fix-vhost-ownership)
        # $1 user, $2 group, $3 basepath. chown -h top-level entries (incl. symlinks).
        find "$3" -maxdepth 1 -exec chown -h "$1:$2" {} + 2>/dev/null || true ;;
    detect-db)
        # $1 = numeric Plesk domain id. SQL is fixed here; only the id crosses the boundary.
        case "$1" in ''|*[!0-9]*) echo "detect-db: id must be numeric" >&2; exit 64 ;; esac
        plesk db "SELECT db.name, du.login FROM data_bases db LEFT JOIN db_users du ON du.db_id = db.id WHERE db.dom_id = $1 LIMIT 1" ;;

    *)
        echo "xve-exec: unknown subcommand: $cmd" >&2
        exit 64 ;;
esac
