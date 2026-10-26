#!/bin/sh
#
# Build a self-contained ServerDeck release tarball.
#
# Build host requirements: composer, PHP, npm/Node (unless assets already
# exist), curl, rsync, tar and Docker (for the portable host helper).
set -eu

SCRIPT_DIR=$(CDPATH= cd -- "$(dirname -- "$0")" && pwd)
PACKAGING_DIR=$(CDPATH= cd -- "$SCRIPT_DIR/.." && pwd)
APP_DIR=$(CDPATH= cd -- "$PACKAGING_DIR/.." && pwd)
OUTPUT_DIR=${SERVERDECK_OUTPUT_DIR:-"$PACKAGING_DIR/dist"}
CACHE_DIR=${SERVERDECK_BUILD_CACHE:-"${XDG_CACHE_HOME:-$HOME/.cache}/serverdeck-build"}
VERSION=${SERVERDECK_VERSION:-$(head -n 1 "$PACKAGING_DIR/VERSION")}
FRANKENPHP_VERSION="1.13.3"
FRANKENPHP_URL="https://github.com/php/frankenphp/releases/download/v${FRANKENPHP_VERSION}/frankenphp-linux-x86_64"
FRANKENPHP_SHA256="f88db9972ddac5e4c097c8b703e585255e181411687e5a1f79ca4b6328526e0b"
PHP_VERSION="8.5.8"
PHP_URL="https://dl.static-php.dev/static-php-cli/common/php-${PHP_VERSION}-cli-linux-x86_64.tar.gz"
PHP_SHA256="517a18e0f0874de35669fe134eb3c52508e128a6d1da5bf890760cb800ada410"
SKIP_HELPER=0
REBUILD_ASSETS=0

usage() {
	cat <<'EOF'
Usage: build-release.sh [options]

Options:
  --skip-helper     Reuse packaging/.build/helper if present
  --rebuild-assets  Run npm run build before packaging
  --output DIR      Output directory (default: packaging/dist)
  --version VER     Override the version in packaging/VERSION
  -h, --help        Show this help
EOF
}

while [ "$#" -gt 0 ]; do
	case "$1" in
		--skip-helper)
			SKIP_HELPER=1
			shift
			;;
		--rebuild-assets)
			REBUILD_ASSETS=1
			shift
			;;
		--output)
			[ "$#" -ge 2 ] || {
				printf '%s\n' "--output requires a directory" >&2
				exit 2
			}
			OUTPUT_DIR=$2
			shift 2
			;;
		--version)
			[ "$#" -ge 2 ] || {
				printf '%s\n' "--version requires a value" >&2
				exit 2
			}
			VERSION=$2
			shift 2
			;;
		-h|--help)
			usage
			exit 0
			;;
		*)
			printf 'Unknown option: %s\n' "$1" >&2
			usage >&2
			exit 2
			;;
	esac
done

case "$VERSION" in
	""|*[!A-Za-z0-9._-]*)
		printf 'Invalid version: %s\n' "$VERSION" >&2
		exit 1
		;;
esac

case "$(uname -m)" in
	x86_64) ;;
	*)
		printf 'This build script currently produces x86_64 releases only.\n' >&2
		exit 1
		;;
esac

for command in composer curl rsync tar gzip sha256sum find sort xargs; do
	if ! command -v "$command" >/dev/null 2>&1; then
		printf 'build-release: required build command not found: %s\n' "$command" >&2
		exit 1
	fi
done

WORK_DIR=$(mktemp -d "${TMPDIR:-/tmp}/serverdeck-build.XXXXXX")
trap 'rm -rf "$WORK_DIR"' EXIT HUP INT TERM
STAGE="$WORK_DIR/stage"
RELEASE_NAME="serverdeck-${VERSION}-linux-x86_64"
RELEASE_DIR="$STAGE/$RELEASE_NAME"
PAYLOAD="$RELEASE_DIR/payload"
BUILD_ID=$(date -u +%Y%m%dT%H%M%SZ)

mkdir -p "$CACHE_DIR" "$OUTPUT_DIR" "$RELEASE_DIR" "$PAYLOAD/bin" "$RELEASE_DIR/helper" "$RELEASE_DIR/templates" "$RELEASE_DIR/bin"

fetch() {
	url=$1
	destination=$2
	checksum=$3
	if [ -f "$destination" ] && printf '%s  %s\n' "$checksum" "$destination" | sha256sum -c - >/dev/null 2>&1; then
		return 0
	fi
	printf '==> Downloading %s\n' "$url"
	curl -fL --retry 3 --connect-timeout 15 -o "$destination.part" "$url"
	printf '%s  %s\n' "$checksum" "$destination.part" | sha256sum -c - >/dev/null
	mv -f "$destination.part" "$destination"
}

fetch "$FRANKENPHP_URL" "$CACHE_DIR/frankenphp-${FRANKENPHP_VERSION}-linux-x86_64" "$FRANKENPHP_SHA256"
if [ ! -x "$CACHE_DIR/php-${PHP_VERSION}/php" ]; then
	fetch "$PHP_URL" "$CACHE_DIR/php-${PHP_VERSION}.tar.gz" "$PHP_SHA256"
	mkdir -p "$CACHE_DIR/php-${PHP_VERSION}"
	tar -xzf "$CACHE_DIR/php-${PHP_VERSION}.tar.gz" -C "$CACHE_DIR/php-${PHP_VERSION}"
	chmod 0755 "$CACHE_DIR/php-${PHP_VERSION}/php"
fi

if [ "$REBUILD_ASSETS" -eq 1 ] || [ ! -f "$APP_DIR/public/build/manifest.json" ]; then
	if ! command -v npm >/dev/null 2>&1; then
		printf 'build-release: npm is required to build frontend assets.\n' >&2
		exit 1
	fi
	printf '==> Building frontend assets\n'
	(cd "$APP_DIR" && npm run build)
fi

printf '==> Copying application\n'
rsync -a \
	--exclude='.agents/' \
	--exclude='.claude/' \
	--exclude='.git/' \
	--exclude='.zed/' \
	--exclude='.env' \
	--exclude='.env.*' \
	--exclude='.phpunit.cache/' \
	--exclude='.phpunit.result.cache' \
	--exclude='bootstrap/cache/*' \
	--exclude='database/database.sqlite*' \
	--exclude='node_modules/' \
	--exclude='packaging/' \
	--exclude='public/hot' \
	--exclude='storage/' \
	--exclude='tests/' \
	--exclude='vendor/' \
	"$APP_DIR/" "$PAYLOAD/"

# Keep the example environment but not a real local .env.
cp "$APP_DIR/.env.example" "$PAYLOAD/.env.example"
mkdir -p "$PAYLOAD/bootstrap/cache" "$PAYLOAD/storage/framework/cache" "$PAYLOAD/storage/framework/sessions" "$PAYLOAD/storage/framework/views" "$PAYLOAD/storage/logs"

printf '==> Installing production Composer dependencies\n'
(cd "$PAYLOAD" && COMPOSER_ALLOW_SUPERUSER=1 composer install \
	--no-dev \
	--no-scripts \
	--no-interaction \
	--no-progress \
	--prefer-dist \
	--optimize-autoloader \
	--classmap-authoritative)

# These are runtime caches created by the installer; never ship build-host state.
rm -rf "$PAYLOAD/bootstrap/cache" "$PAYLOAD/storage"

cp "$CACHE_DIR/frankenphp-${FRANKENPHP_VERSION}-linux-x86_64" "$PAYLOAD/bin/frankenphp"
cp "$CACHE_DIR/php-${PHP_VERSION}/php" "$PAYLOAD/bin/php"
chmod 0755 "$PAYLOAD/bin/frankenphp" "$PAYLOAD/bin/php"

if [ "$SKIP_HELPER" -eq 1 ] && [ -x "$PACKAGING_DIR/.build/helper/serverdeck-hostctl" ]; then
	printf '==> Reusing portable helper\n'
	cp "$PACKAGING_DIR/.build/helper/serverdeck-hostctl" "$RELEASE_DIR/helper/serverdeck-hostctl"
	cp "$PACKAGING_DIR/.build/helper/pamcheck" "$RELEASE_DIR/helper/pamcheck"
else
	sh "$SCRIPT_DIR/build-helper.sh" "$WORK_DIR/helper"
	cp "$WORK_DIR/helper/serverdeck-hostctl" "$RELEASE_DIR/helper/serverdeck-hostctl"
	cp "$WORK_DIR/helper/pamcheck" "$RELEASE_DIR/helper/pamcheck"
fi
chmod 0755 "$RELEASE_DIR/helper/serverdeck-hostctl" "$RELEASE_DIR/helper/pamcheck"

printf '==> Assembling release\n'
cp "$PACKAGING_DIR/install.sh" "$RELEASE_DIR/install.sh"
cp "$PACKAGING_DIR/bin/serverdeck" "$RELEASE_DIR/bin/serverdeck"
cp "$PACKAGING_DIR/templates/"*.in "$RELEASE_DIR/templates/"
cp "$PACKAGING_DIR/README.md" "$RELEASE_DIR/README.md" 2>/dev/null || true
cp "$PACKAGING_DIR/THIRD-PARTY-NOTICES.md" "$RELEASE_DIR/THIRD-PARTY-NOTICES.md" 2>/dev/null || true
cp "$PACKAGING_DIR/VERSION" "$RELEASE_DIR/VERSION"
printf '%s\n' "$BUILD_ID" > "$RELEASE_DIR/BUILD_ID"
chmod 0755 "$RELEASE_DIR/install.sh" "$RELEASE_DIR/bin/serverdeck"

if [ ! -f "$RELEASE_DIR/README.md" ]; then
	cat > "$RELEASE_DIR/README.md" <<EOF
# ServerDeck ${VERSION}

Self-contained ServerDeck release for x86_64 glibc/systemd Linux.

Install with:

    sudo ./install.sh

See the extracted release directory for installation options:

    ./install.sh --help
EOF
fi

chmod -R go-w "$RELEASE_DIR"

printf '==> Creating checksums\n'
(
	cd "$RELEASE_DIR"
	find . -type f ! -name SHA256SUMS -print0 | sort -z | xargs -0 sha256sum > SHA256SUMS
)

ARCHIVE="$OUTPUT_DIR/${RELEASE_NAME}.tar.gz"
printf '==> Compressing release (this can take a minute)\n'
tar -C "$STAGE" -czf "$ARCHIVE" "$RELEASE_NAME"
(
	cd "$OUTPUT_DIR"
	sha256sum "$(basename "$ARCHIVE")" > "$(basename "$ARCHIVE").sha256"
)

printf '\nRelease: %s\n' "$ARCHIVE"
printf 'Build:   %s\n' "$BUILD_ID"
printf 'Size:    %s\n' "$(du -h "$ARCHIVE" | awk '{ print $1 }')"
printf 'SHA-256: %s\n' "$(cut -d' ' -f1 "$ARCHIVE.sha256")"
