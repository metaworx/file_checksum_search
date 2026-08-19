#!/usr/bin/env bash
#
# package.sh — Build, package, and (optionally) sign the file_checksum_search
# app for the Nextcloud App Store.
#
# What it does:
#   1. Builds the frontend assets (npm ci + npm run build) if needed.
#   2. Copies the app into a clean staging directory.
#   3. Removes dev-only files listed in .nc.publish.ignore.
#   4. Installs production Composer dependencies.
#   5. Creates build/file_checksum_search.tar.gz and a versioned copy.
#   6. Signs the versioned archive (see "Signing" below).
#
# The archive contains a single top-level directory named after the app id
# (file_checksum_search/), which is what the Nextcloud App Store expects.
#
# Signing
# -------
# The App Store requires an SHA-512 signature of the archive:
#   openssl dgst -sha512 -sign <key> <archive> | openssl base64
# package.sh signs automatically when a key is available, resolved in order:
#   1. APPSTORE_KEY / APPSTORE_CERT environment variables
#      (PEM content, or a path to a file — GitLab file-type variables).
#   2. ~/.nextcloud/certificates/<app_id>.key and .crt (local development).
# The signature is written next to the archive as <archive>.signature and is
# also printed to stdout for pasting into the App Store upload form.
#
# Usage:
#   bash package.sh              # build, package, and sign (if key available)
#   bash package.sh --sign-only  # only sign the existing versioned archive
#   DOWNLOAD_URL=... bash package.sh --appstore           # publish a release
#   DOWNLOAD_URL=... bash package.sh --appstore --nightly
#   PHP=php8.2 bash package.sh   # use a specific PHP binary for Composer
#
# Requires: bash, rsync, tar, npm, composer, openssl (curl for --register and
# --appstore). PHP is auto-detected as php8.2 (falling back to php) and can be
# overridden via the PHP env var. The App Store API token is read from the
# API_TOKEN env var or ~/.nextcloud/API_TOKEN.txt.

set -euo pipefail

SCRIPT_VERSION="1.0.0"
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"

echo "==> Building Script for File Checksum Index & Search ..."
echo "    Script:         ${BASH_SOURCE[0]}"
echo "    Script Version: $SCRIPT_VERSION"
echo "    Script Path:    $SCRIPT_DIR"

cd "$SCRIPT_DIR"

SIGN_ONLY=false
APPSTORE=false
NIGHTLY=false
for arg in "$@"; do
	case "$arg" in
		--sign-only) SIGN_ONLY=true ;;
		--appstore) APPSTORE=true ;;
		--nightly) NIGHTLY=true ;;
		*) echo "ERROR: unknown argument: $arg" >&2; exit 2 ;;
	esac
done

echo "==> Looking for required binaries ..."

# Resolve the PHP binary: $PHP env override, else php8.2, else php.
PHP_BIN="${PHP:-$(command -v php8.2 || command -v php || true)}"
if [ -z "$PHP_BIN" ]; then
	echo "ERROR: no PHP binary found — set the PHP env var (e.g. PHP=/usr/bin/php8.2)." >&2
	exit 1
fi
echo "    $PHP_BIN"

for bin in bash rsync tar npm composer openssl; do
	if ! command -v "$bin" > /dev/null 2>&1; then
		echo "ERROR: required binary '${bin}' not found in PATH." >&2
		exit 1
	fi
	echo "    $(command -v "$bin")"
done

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

echo "==> App Metadata:"
echo "    App ID:         $APP_ID"
echo "    App Version:    $VERSION"
echo "    App Build Dir:  $BUILD_DIR"

# ---------------------------------------------------------------------------
# Signing
# ---------------------------------------------------------------------------
KEY_FILE=""
CERT_FILE=""

# Resolve the signing key/cert paths into the globals KEY_FILE / CERT_FILE.
# Materializes PEM content from the environment into the given temp dir.
resolve_signing_material() {
	local tmp="$1"
	KEY_FILE=""
	CERT_FILE=""

	if [ -n "${APPSTORE_KEY:-}" ]; then
		if [ -f "$APPSTORE_KEY" ]; then
			KEY_FILE="$APPSTORE_KEY"
		else
			printf '%s\n' "$APPSTORE_KEY" > "$tmp/key.pem"
			KEY_FILE="$tmp/key.pem"
		fi
		if [ -n "${APPSTORE_CERT:-}" ]; then
			if [ -f "$APPSTORE_CERT" ]; then
				CERT_FILE="$APPSTORE_CERT"
			else
				printf '%s\n' "$APPSTORE_CERT" > "$tmp/cert.crt"
				CERT_FILE="$tmp/cert.crt"
			fi
		fi
	else
		local cert_dir="${NEXTCLOUD_CERT_DIR:-${HOME}/.nextcloud/certificates}"
		KEY_FILE="${cert_dir}/${APP_ID}.key"
		CERT_FILE="${cert_dir}/${APP_ID}.crt"
	fi
}

# openssl SHA-512 signature of an archive (detached, base64) — what the App
# Store upload form expects.
sign_archive() {
	local archive="$1"
	local tmp key_file cert_file signature_file signed_archive
	tmp="$(mktemp -d)"

	resolve_signing_material "$tmp"
	key_file="$KEY_FILE"
	cert_file="$CERT_FILE"

	if [ ! -f "$key_file" ]; then
		echo "==> No signing key found (${key_file}) — skipping signature (Option B)."
		rm -rf "$tmp"
		return 0
	fi

	echo "==> Signing ${archive} (openssl dgst -sha512)"
	signature_file="${archive}.signature"
	if ! openssl dgst -sha512 -sign "$key_file" "$archive" | openssl base64 -A > "$signature_file"; then
		echo "ERROR: signing failed." >&2
		rm -rf "$tmp"
		return 1
	fi

	if [ -f "${cert_file:-}" ]; then
		if ! openssl x509 -in "$cert_file" -pubkey -noout > "$tmp/pubkey.pem" \
			|| ! openssl base64 -d -A < "$signature_file" > "$tmp/sig.bin" \
			|| ! openssl dgst -sha512 -verify "$tmp/pubkey.pem" -signature "$tmp/sig.bin" "$archive" > /dev/null 2>&1; then
			echo "ERROR: signature verification failed." >&2
			rm -rf "$tmp"
			return 1
		fi
		echo "    Signature verified against ${cert_file}."
	fi

	signed_archive="${archive%.tar.gz}-signed.tar.gz"
	cp "$archive" "$signed_archive"
	echo "    Signed archive: ${signed_archive}"

	echo "    Signature file: ${signature_file}"
	echo ""
	echo "    --- Signature (paste into the App Store upload form) ---"
	cat "$signature_file"
	echo ""
	echo "    -------------------------------------------------------"

	rm -rf "$tmp"
	return 0
}

# Resolve the App Store API token into $TOKEN: $API_TOKEN env, else file.
resolve_token() {
	if [ -n "${API_TOKEN:-}" ]; then
		TOKEN="$API_TOKEN"
	elif [ -f "${HOME}/.nextcloud/API_TOKEN.txt" ]; then
		TOKEN="$(tr -d '[:space:]' < "${HOME}/.nextcloud/API_TOKEN.txt")"
	else
		echo "ERROR: no App Store API token — set API_TOKEN or create ${HOME}/.nextcloud/API_TOKEN.txt" >&2
		return 1
	fi
}

# Publish a release on the App Store (download URL + signature + nightly flag).
publish_appstore() {
	local archive="$1"
	local tmp key_file sig
	tmp="$(mktemp -d)"

	command -v curl > /dev/null 2>&1 || { echo "ERROR: curl is required for --appstore." >&2; rm -rf "$tmp"; return 1; }

	resolve_signing_material "$tmp"
	key_file="$KEY_FILE"

	[ -f "$key_file" ] || { echo "ERROR: no signing key (${key_file})." >&2; rm -rf "$tmp"; return 1; }
	[ -n "${DOWNLOAD_URL:-}" ] || { echo "ERROR: DOWNLOAD_URL env var is required (public HTTPS link to the archive)." >&2; rm -rf "$tmp"; return 1; }

	resolve_token || { rm -rf "$tmp"; return 1; }

	sig="$(openssl dgst -sha512 -sign "$key_file" "$archive" | openssl base64 -A)"

	echo "==> Publishing release '${APP_ID}' v${VERSION} on the App Store (nightly=${NIGHTLY})"
	curl -sS -X POST "https://apps.nextcloud.com/api/v1/apps/releases" \
		-H "Authorization: Token ${TOKEN}" \
		-H "Content-Type: application/json" \
		-d "{\"download\":\"${DOWNLOAD_URL}\",\"signature\":\"${sig}\",\"nightly\":${NIGHTLY}}" \
		-w "\nHTTP %{http_code}\n"

	rm -rf "$tmp"
}

if [ "$APPSTORE" = true ]; then
	if [ ! -f "$VERSIONED_ARTIFACT" ]; then
		echo "ERROR: ${VERSIONED_ARTIFACT} not found — run package.sh first." >&2
		exit 1
	fi
	publish_appstore "$VERSIONED_ARTIFACT"
	exit $?
fi

if [ "$SIGN_ONLY" = true ]; then
	if [ ! -f "$VERSIONED_ARTIFACT" ]; then
		echo "ERROR: ${VERSIONED_ARTIFACT} not found — run package.sh first." >&2
		exit 1
	fi
	sign_archive "$VERSIONED_ARTIFACT"
	exit $?
fi

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
echo "==> Installing production Composer dependencies (PHP: ${PHP_BIN})"
(
	cd "$STAGING_DIR"
	"$PHP_BIN" "$(command -v composer)" install --no-dev --no-scripts --optimize-autoloader --no-interaction
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

# ---------------------------------------------------------------------------
# 5. Sign (if a key is available)
# ---------------------------------------------------------------------------
sign_archive "$VERSIONED_ARTIFACT"
