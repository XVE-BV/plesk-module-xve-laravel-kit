#!/bin/bash
# Build installable zip for XVE Laravel Kit Plesk extension
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
BUILD_DIR="$SCRIPT_DIR/dist"
EXT_ID="xve-laravel-kit"
VERSION=$(grep '<version>' "$SCRIPT_DIR/src/meta.xml" | sed 's/.*<version>\(.*\)<\/version>.*/\1/')

echo "Building $EXT_ID v$VERSION..."

rm -rf "$BUILD_DIR"
mkdir -p "$BUILD_DIR/$EXT_ID"

# Copy extension files (plib, htdocs, meta.xml)
cp "$SCRIPT_DIR/src/meta.xml" "$BUILD_DIR/$EXT_ID/"
cp -r "$SCRIPT_DIR/src/plib" "$BUILD_DIR/$EXT_ID/"
cp -r "$SCRIPT_DIR/src/htdocs" "$BUILD_DIR/$EXT_ID/"

# sbin scripts go directly in sbin/ (Plesk places them under sbin/modules/<ext-id>/)
mkdir -p "$BUILD_DIR/$EXT_ID/sbin"
cp "$SCRIPT_DIR/src/sbin/xve-exec.sh" "$BUILD_DIR/$EXT_ID/sbin/"
chmod +x "$BUILD_DIR/$EXT_ID/sbin/xve-exec.sh"

# Create zip
cd "$BUILD_DIR"
zip -r "$SCRIPT_DIR/dist/$EXT_ID-$VERSION.zip" "$EXT_ID/" -x '*.DS_Store' '*Thumbs.db'

echo ""
echo "Built: dist/$EXT_ID-$VERSION.zip"
echo ""
echo "Install on Plesk:"
echo "  plesk bin extension --install $EXT_ID-$VERSION.zip"
echo ""
echo "Or from URL:"
echo "  plesk bin extension --install-url https://your-server.com/$EXT_ID-$VERSION.zip"
