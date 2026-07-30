#!/usr/bin/env bash
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
LAUNCHER_DIR="$(cd "${SCRIPT_DIR}/.." && pwd)"
SOURCE_ICON="${LAUNCHER_DIR}/assets/app-icon-mac-1024.png"
OUTPUT_ICON="${LAUNCHER_DIR}/assets/app-icon.icns"
ICONSET_DIR="$(mktemp -d "${TMPDIR:-/tmp}/travel-ops-icon.XXXXXX")/TravelAgencyOperations.iconset"

cleanup() {
  rm -rf "$(dirname "${ICONSET_DIR}")"
}
trap cleanup EXIT

if [[ "$(uname -s)" != "Darwin" ]]; then
  echo "This icon preparation script must run on macOS." >&2
  exit 1
fi

if [[ ! -f "${SOURCE_ICON}" ]]; then
  echo "Missing source icon: ${SOURCE_ICON}" >&2
  exit 1
fi

mkdir -p "${ICONSET_DIR}"

create_icon() {
  local pixels="$1"
  local filename="$2"
  sips -z "${pixels}" "${pixels}" "${SOURCE_ICON}" --out "${ICONSET_DIR}/${filename}" >/dev/null
}

create_icon 16 icon_16x16.png
create_icon 32 icon_16x16@2x.png
create_icon 32 icon_32x32.png
create_icon 64 icon_32x32@2x.png
create_icon 128 icon_128x128.png
create_icon 256 icon_128x128@2x.png
create_icon 256 icon_256x256.png
create_icon 512 icon_256x256@2x.png
create_icon 512 icon_512x512.png
create_icon 1024 icon_512x512@2x.png

iconutil -c icns "${ICONSET_DIR}" -o "${OUTPUT_ICON}"
echo "Prepared macOS icon: ${OUTPUT_ICON}"
