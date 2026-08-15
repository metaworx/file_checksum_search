#!/usr/bin/env bash
#
# package.sh — Build and package the file_checksum_search app for the
# Nextcloud App Store.
#
# What it does:
#   1. Builds the frontend assets (npm ci + npm run build) if needed.
#   2. Copies the app into a clean staging directory.
#   3. Removes dev-only files listed in .nc.publish.ignore.
#   4. Installs production Composer dependencies.
#   5. Creates build/file_checksum_search.tar.gz and a versioned copy.
#
# The archive contains a single top-level directory named after the app id
# (file_checksum_search/), which is what the Nextcloud App Store expects.
#
# Usage:
#   bash package.sh
#
# Requires: bash, rsync, tar, npm, composer.

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
cd "$SCRIPT_DIR"

# ---------------------------------------------------------------------------
# App metadata
# ---------------------------------------------------------------------------
if [ ! -f appinfo/info.xml ]; then
	echo "ERROR: appinfo/info.xml not found — run this script from the repo root." >&2
	exit 1
fi

APP_ID="$(sed -n 's|.*<id>\([^<]*\)</id>.*|\1|p' appinfo/info.xml | head -n1 | tr -d '[:space:]')"
VERSION="$(sed -n 's|.*<version>\([^<]*\)</version>.*|\1|p' appinfo/info.xml | head -n1 | tr -d '[:space:]')"

if [ -z "$APP_ID" ] || [ -z "$VERSION" ]; then
	echo "ERROR: could not determine app id/version from appinfo/info.xml." >&2
	exit 1
fi

BUILD_DIR="${BUILD_DIR:-build}"
STAGING_DIR="${BUILD_DIR}/${APP_ID}"
ARTIFACT="${BUILD_DIR}/${APP_ID}.tar.gz"
VERSIONED_ARTIFACT="${BUILD_DIR}/${APP_ID}-${VERSION}.tar.gz"

echo "==> Packaging ${APP_ID} v${VERSION}"

# ---------------------------------------------------------------------------
# 1. Frontend build
# ---------------------------------------------------------------------------
if [ -f package.json ]; then
	if [ ! -d node_modules ]; then
		echo "==> Installing frontend dependencies (npm ci)"
		npm ci
	fi
	echo "==> Building frontend assets (npm run build)"
	npm run build
fi

# ---------------------------------------------------------------------------
# 2. Staging
# ---------------------------------------------------------------------------
echo "==> Preparing staging directory ${STAGING_DIR}"
rm -rf "$STAGING_DIR"
mkdir -p "$STAGING_DIR"

echo "==> Copying app files (excluding entries from .nc.publish.ignore)"
if [ ! -f .nc.publish.ignore ]; then
	echo "ERROR: .nc.publish.ignore not found — refusing to package." >&2
	exit 1
fi
rsync -a \
	--exclude-from='.nc.publish.ignore' \
	./ "$STAGING_DIR/"

# ---------------------------------------------------------------------------
# 3. Production dependencies
# ---------------------------------------------------------------------------
echo "==> Installing production Composer dependencies"
(
	cd "$STAGING_DIR"
	composer install --no-dev --no-scripts --optimize-autoloader --no-interaction
)

# ---------------------------------------------------------------------------
# 4. Archive
# ---------------------------------------------------------------------------
echo "==> Creating archive ${ARTIFACT}"
mkdir -p "$BUILD_DIR"
tar -czf "$ARTIFACT" -C "$BUILD_DIR" "$APP_ID"
cp "$ARTIFACT" "$VERSIONED_ARTIFACT"

echo "==> Done"
echo "    ${ARTIFACT}"
echo "    ${VERSIONED_ARTIFACT}"
