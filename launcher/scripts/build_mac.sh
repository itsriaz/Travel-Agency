#!/usr/bin/env bash
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
LAUNCHER_DIR="$(cd "${SCRIPT_DIR}/.." && pwd)"

if [[ "$(uname -s)" != "Darwin" ]]; then
  echo "The macOS DMG must be built on a Mac." >&2
  exit 1
fi

command -v node >/dev/null 2>&1 || { echo "Node.js is required." >&2; exit 1; }
command -v npm >/dev/null 2>&1 || { echo "npm is required." >&2; exit 1; }
command -v sips >/dev/null 2>&1 || { echo "The macOS sips utility is required." >&2; exit 1; }
command -v iconutil >/dev/null 2>&1 || { echo "The macOS iconutil utility is required." >&2; exit 1; }

cd "${LAUNCHER_DIR}"

npm ci
bash scripts/prepare_mac_icon.sh
npx electron-builder --config electron-builder.mac.yml --mac --universal

echo
echo "macOS launcher created in: ${LAUNCHER_DIR}/dist-mac"
