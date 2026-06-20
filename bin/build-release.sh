#!/usr/bin/env bash
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
PLUGIN_SLUG="caffeonline-feed-sync"
DIST_DIR="${ROOT_DIR}/dist"
ZIP_FILE="${DIST_DIR}/${PLUGIN_SLUG}.zip"
COMPOSER_CMD="${COMPOSER_BINARY:-${COMPOSER:-composer}}"
BUILD_ROOT=""

cleanup() {
  if [[ -n "${BUILD_ROOT}" && -d "${BUILD_ROOT}" ]]; then
    rm -rf "${BUILD_ROOT}"
  fi
}
trap cleanup EXIT

cd "${ROOT_DIR}"

"${COMPOSER_CMD}" install --no-dev --prefer-dist --no-interaction --optimize-autoloader

if [[ ! -f "${ROOT_DIR}/vendor/autoload.php" ]]; then
  echo "Missing vendor/autoload.php after composer install." >&2
  exit 1
fi

rm -rf "${DIST_DIR}"
mkdir -p "${DIST_DIR}"

BUILD_ROOT="$(mktemp -d "${DIST_DIR}/build.XXXXXX")"
PLUGIN_DIR="${BUILD_ROOT}/${PLUGIN_SLUG}"
mkdir -p "${PLUGIN_DIR}"

copy_if_exists() {
  local path="$1"
  if [[ -e "${ROOT_DIR}/${path}" ]]; then
    cp -R "${ROOT_DIR}/${path}" "${PLUGIN_DIR}/${path}"
  fi
}

copy_if_exists "caffeonline-feed-sync.php"
copy_if_exists "assets"
copy_if_exists "includes"
copy_if_exists "readme.txt"
copy_if_exists "README.md"
copy_if_exists "vendor"

find "${PLUGIN_DIR}" -name ".DS_Store" -delete
find "${PLUGIN_DIR}" -name "*.bak" -delete
find "${PLUGIN_DIR}" -name "*.bak.*" -delete

(
  cd "${BUILD_ROOT}"
  zip -qr "${ZIP_FILE}" "${PLUGIN_SLUG}"
)

if ! unzip -Z1 "${ZIP_FILE}" | grep -qx "${PLUGIN_SLUG}/vendor/autoload.php"; then
  echo "Release ZIP does not contain ${PLUGIN_SLUG}/vendor/autoload.php." >&2
  exit 1
fi

TOP_LEVEL_COUNT="$(unzip -Z1 "${ZIP_FILE}" | awk -F/ 'NF {print $1}' | sort -u | wc -l | tr -d ' ')"
if [[ "${TOP_LEVEL_COUNT}" != "1" ]]; then
  echo "Release ZIP must contain exactly one top-level folder; found ${TOP_LEVEL_COUNT}." >&2
  exit 1
fi

TOP_LEVEL_NAME="$(unzip -Z1 "${ZIP_FILE}" | awk -F/ 'NF {print $1}' | sort -u)"
if [[ "${TOP_LEVEL_NAME}" != "${PLUGIN_SLUG}" ]]; then
  echo "Release ZIP top-level folder must be ${PLUGIN_SLUG}; found ${TOP_LEVEL_NAME}." >&2
  exit 1
fi

if unzip -Z1 "${ZIP_FILE}" | grep -E '(^|/)(\.git|\.github|node_modules|Tests|tests|dist|\.idea|\.vscode)(/|$)' >/dev/null; then
  echo "Release ZIP contains development-only files." >&2
  exit 1
fi

if unzip -Z1 "${ZIP_FILE}" | grep -E '(^|/).+\.bak($|[./])' >/dev/null; then
  echo "Release ZIP contains backup files." >&2
  exit 1
fi

echo "Built ${ZIP_FILE}"
