#!/usr/bin/env bash
# Builds simple, reliable DMGs from the already-packaged .app folders (no Finder/AppleScript layout,
# which is what makes electron-builder's own dmg step flaky on CI runners).
set -euo pipefail
cd "$(dirname "$0")/.."
VERSION=$(node -p "require('./package.json').version")

make_dmg() {            # $1 = arch label, $2 = folder electron-builder wrote the .app into
  local arch="$1" dir="$2" out="dist/EasyPOS-$VERSION-mac-$1.dmg" stage
  [ -d "dist/$dir/Easy POS.app" ] || { echo "missing dist/$dir/Easy POS.app"; exit 1; }
  stage=$(mktemp -d)
  ditto "dist/$dir/Easy POS.app" "$stage/Easy POS.app"
  ln -s /Applications "$stage/Applications"
  rm -f "$out"
  for attempt in 1 2 3; do
    if hdiutil create -volname "Easy POS $VERSION" -srcfolder "$stage" -fs HFS+ -format UDZO -ov "$out"; then break; fi
    echo "hdiutil failed (attempt $attempt), retrying..."; sleep 5
    [ "$attempt" = 3 ] && exit 1
  done
  rm -rf "$stage"
  ls -la "$out"
}

make_dmg arm64 mac-arm64
make_dmg x64 mac
