#!/bin/sh
#
# Build the root host helper and pamcheck for glibc 2.31+ releases.
#
# PyInstaller is used so the helper does not require Python on the target.
# The build image is Debian 11 (glibc 2.31), the oldest glibc supported by
# this release line. pamcheck dynamically links to the host's libpam.so.0,
# which is present on every PAM-based distribution.
set -eu

SCRIPT_DIR=$(CDPATH= cd -- "$(dirname -- "$0")" && pwd)
PACKAGING_DIR=$(CDPATH= cd -- "$SCRIPT_DIR/.." && pwd)
HELPER_DIR="$PACKAGING_DIR/helper"
OUTPUT_DIR=${1:-"$PACKAGING_DIR/.build/helper"}
IMAGE="python:3.11-slim-bullseye"
PYINSTALLER_VERSION="6.16.0"

if ! command -v docker >/dev/null 2>&1; then
	printf 'build-helper: docker is required to build the portable helper.\n' >&2
	exit 1
fi

DOCKER="docker"
if ! docker info >/dev/null 2>&1; then
	if sudo -n docker info >/dev/null 2>&1; then
		DOCKER="sudo -n docker"
	else
		printf 'build-helper: cannot access the Docker daemon (try sudo).\n' >&2
		exit 1
	fi
fi

mkdir -p "$OUTPUT_DIR"
OUTPUT_DIR=$(CDPATH= cd -- "$OUTPUT_DIR" && pwd)

printf '==> Building portable helper and pamcheck\n'
# Debian 11 is EOL for the regular security repository; use the matching
# snapshot so compiler and libpam versions are reproducible.
$DOCKER run --rm \
	-v "$HELPER_DIR:/src:ro" \
	-v "$OUTPUT_DIR:/out" \
	"$IMAGE" \
	bash -lc '
		set -e
		cat >/etc/apt/sources.list <<EOF
deb http://snapshot.debian.org/archive/debian/20260824T000000Z bullseye main
deb http://snapshot.debian.org/archive/debian-security/20260824T000000Z bullseye-security main
EOF
		apt-get -o Acquire::Check-Valid-Until=false update -qq >/dev/null
		apt-get install -y -qq --no-install-recommends gcc libpam0g-dev binutils file >/dev/null
		python -m pip install --quiet "pyinstaller=='"$PYINSTALLER_VERSION"'"
		pyinstaller --clean --noconfirm --onefile \
			--name serverdeck-hostctl \
			--specpath /tmp --workpath /tmp/pyi --distpath /out \
			/src/serverdeck-hostctl.py >/tmp/pyinstaller.log 2>&1
		gcc -O2 -Wall -Wextra -Werror -o /out/pamcheck /src/pamcheck.c -lpam
		strip /out/pamcheck
		chmod 0755 /out/serverdeck-hostctl /out/pamcheck
	'

"$OUTPUT_DIR/serverdeck-hostctl" </dev/null >/dev/null 2>&1 || true
"$OUTPUT_DIR/pamcheck" >/dev/null 2>&1 || true

printf '==> Built:\n'
ls -lh "$OUTPUT_DIR/serverdeck-hostctl" "$OUTPUT_DIR/pamcheck"
printf '\nSHA-256:\n'
sha256sum "$OUTPUT_DIR/serverdeck-hostctl" "$OUTPUT_DIR/pamcheck"
