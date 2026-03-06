#!/bin/bash
# Post-start script: restores packages and sbin/bin wrappers after container recreate.
# Named volumes preserve /var and /etc but /usr is ephemeral — packages need reinstall.

set -euo pipefail

# Reinstall packages whose binaries live in /usr (lost on container recreate)
if [ ! -f /usr/bin/git ] || [ ! -f /usr/bin/ssh-keygen ]; then
    apt-get install --reinstall -y -qq git openssh-client 2>/dev/null || true
fi

# Reinstall Node.js if binary is missing (already registered in dpkg from named volume)
if [ ! -f /usr/bin/node ]; then
    # If nodesource repo isn't configured yet, set it up first
    if [ ! -f /etc/apt/sources.list.d/nodesource.list ]; then
        curl -fsSL https://deb.nodesource.com/setup_22.x | bash - 2>/dev/null
    fi
    apt-get install --reinstall -y -qq nodejs 2>/dev/null || true
fi

# Install pnpm globally if missing
if ! command -v pnpm &>/dev/null; then
    npm install -g pnpm 2>/dev/null || true
fi

EXT="xve-laravel-kit"
SCRIPT_NAME="xve-exec.sh"

# Create sbin script (the actual script that runs as root)
SBIN_DIR="/usr/local/psa/admin/sbin/modules/$EXT"
mkdir -p "$SBIN_DIR"
cat > "$SBIN_DIR/$SCRIPT_NAME" << 'SCRIPT'
#!/bin/bash
eval "$1"
SCRIPT
chmod 0700 "$SBIN_DIR/$SCRIPT_NAME"
chown root:root "$SBIN_DIR/$SCRIPT_NAME"

# Create bin wrapper (setuid symlink to mod_wrapper, which calls sbin)
BIN_DIR="/usr/local/psa/admin/bin/modules/$EXT"
mkdir -p "$BIN_DIR"
ln -sf /usr/local/psa/admin/sbin/mod_wrapper "$BIN_DIR/$SCRIPT_NAME"

echo "sbin/bin scripts initialized for $EXT."
