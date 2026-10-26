#!/bin/sh
#
# ServerDeck installer for glibc + systemd Linux hosts.
#
# The release tarball contains its own PHP web runtime (FrankenPHP), PHP CLI,
# a bundled host helper and PAM utility. The target host does not need PHP,
# Node, Composer, nginx or Apache.
set -eu

APP_NAME="ServerDeck"
SERVICE_NAME="serverdeck"
DEFAULT_PREFIX="/opt/serverdeck"
DEFAULT_DATA_DIR="/var/lib/serverdeck"
DEFAULT_CONF_DIR="/etc/serverdeck"
DEFAULT_PORT="8090"
DEFAULT_BIND="0.0.0.0"
SERVICE_USER="serverdeck"
HELPER_PATH="/usr/local/sbin/serverdeck-hostctl"
PAMCHECK_PATH="/usr/local/libexec/serverdeck/pamcheck"
CLI_PATH="/usr/local/bin/serverdeck"
CONF_FILE="$DEFAULT_CONF_DIR/serverdeck.conf"
ENV_FILE="$DEFAULT_CONF_DIR/serverdeck.env"
MANAGED_MARKER="# Managed by ServerDeck"

OPT_PREFIX=""
OPT_DATA_DIR=""
OPT_PORT=""
OPT_BIND=""
OPT_URL=""
RELEASE_PATH=""
RELEASE_URL=""
RELEASE_SHA256="${SERVERDECK_RELEASE_SHA256:-}"
NO_START=0
OPEN_FIREWALL=0
UNINSTALL=0
PURGE=0
ASSUME_YES=0
SHOW_HELP=0

WORK_DIR=""
SOURCE_ROOT=""
VERSION=""
BUILD_ID=""
PREFIX="$DEFAULT_PREFIX"
DATA_DIR="$DEFAULT_DATA_DIR"
CONF_DIR="$DEFAULT_CONF_DIR"
PORT="$DEFAULT_PORT"
BIND="$DEFAULT_BIND"
APP_URL_VALUE=""
OLD_RELEASE=""
NEW_RELEASE=""
DB_BACKUP=""
INSTALL_COMMITTED=0
SERVICE_WAS_ACTIVE=0

if [ -t 1 ]; then
	GREEN=$(printf '\033[32m')
	YELLOW=$(printf '\033[33m')
	RED=$(printf '\033[31m')
	CYAN=$(printf '\033[36m')
	DIM=$(printf '\033[2m')
	BOLD=$(printf '\033[1m')
	RESET=$(printf '\033[0m')
else
	GREEN=""
	YELLOW=""
	RED=""
	CYAN=""
	DIM=""
	BOLD=""
	RESET=""
fi

say() {
	printf '%s\n' "$*"
}

info() {
	printf '%s==>%s %s\n' "$CYAN" "$RESET" "$*"
}

success() {
	printf '%s  ok%s %s\n' "$GREEN" "$RESET" "$*"
}

warning() {
	printf '%s  !%s  %s\n' "$YELLOW" "$RESET" "$*" >&2
}

fail() {
	printf '%sError:%s %s\n' "$RED" "$RESET" "$*" >&2
	exit 1
}

usage() {
	cat <<'EOF'
ServerDeck installer

Usage:
  sudo ./install.sh [options]

Options:
  --port PORT          HTTP port (default: 8090, existing installs keep theirs)
  --bind ADDRESS       IPv4 listen address (default: 0.0.0.0)
  --url URL            Public URL stored as APP_URL
  --prefix DIR         Application releases directory (default: /opt/serverdeck)
  --data-dir DIR       Persistent data directory (default: /var/lib/serverdeck)
  --no-start           Install files and units without starting them
  --open-firewall      Open the HTTP port in ufw or firewalld when active
  --release PATH       Install a local release tarball or extracted directory
  --release-url URL    Download a release tarball
  --sha256 HASH        Required SHA-256 checksum for --release-url
  --uninstall          Remove ServerDeck; keep configuration and data
  --purge              With --uninstall, also delete configuration and data
  --yes                Do not ask for confirmation during --purge
  -h, --help           Show this help

Supported:
  x86_64, glibc 2.31+, systemd, Linux/PAM accounts. Debian/Ubuntu,
  Fedora/RHEL/Rocky/Alma, Arch and compatible distributions.
EOF
}

cleanup() {
	if [ -n "$WORK_DIR" ] && [ -d "$WORK_DIR" ]; then
		rm -rf "$WORK_DIR"
	fi
}

trap cleanup EXIT HUP INT TERM

while [ "$#" -gt 0 ]; do
	case "$1" in
		--port)
			[ "$#" -ge 2 ] || fail "--port requires a value"
			OPT_PORT=$2
			shift 2
			;;
		--bind)
			[ "$#" -ge 2 ] || fail "--bind requires a value"
			OPT_BIND=$2
			shift 2
			;;
		--url)
			[ "$#" -ge 2 ] || fail "--url requires a value"
			OPT_URL=$2
			shift 2
			;;
		--prefix)
			[ "$#" -ge 2 ] || fail "--prefix requires a value"
			OPT_PREFIX=$2
			shift 2
			;;
		--data-dir)
			[ "$#" -ge 2 ] || fail "--data-dir requires a value"
			OPT_DATA_DIR=$2
			shift 2
			;;
		--release)
			[ "$#" -ge 2 ] || fail "--release requires a value"
			RELEASE_PATH=$2
			shift 2
			;;
		--release-url)
			[ "$#" -ge 2 ] || fail "--release-url requires a value"
			RELEASE_URL=$2
			shift 2
			;;
		--sha256)
			[ "$#" -ge 2 ] || fail "--sha256 requires a value"
			RELEASE_SHA256=$2
			shift 2
			;;
		--no-start)
			NO_START=1
			shift
			;;
		--open-firewall)
			OPEN_FIREWALL=1
			shift
			;;
		--uninstall)
			UNINSTALL=1
			shift
			;;
		--purge)
			UNINSTALL=1
			PURGE=1
			shift
			;;
		--yes|-y)
			ASSUME_YES=1
			shift
			;;
		-h|--help)
			SHOW_HELP=1
			shift
			;;
		*)
			fail "Unknown option: $1 (use --help)"
			;;
	esac
done

if [ "$SHOW_HELP" -eq 1 ]; then
	usage
	exit 0
fi

[ "$(id -u)" -eq 0 ] || fail "Run this installer as root (for example: sudo ./install.sh)."

if [ -r "$CONF_FILE" ]; then
	# shellcheck disable=SC1090
	. "$CONF_FILE"
	PREFIX="${SERVERDECK_PREFIX:-$PREFIX}"
	DATA_DIR="${SERVERDECK_DATA_DIR:-$DATA_DIR}"
	CONF_DIR="${SERVERDECK_CONF_DIR:-$CONF_DIR}"
	PORT="${SERVERDECK_PORT:-$PORT}"
	BIND="${SERVERDECK_BIND:-$BIND}"
	SERVICE_USER="${SERVERDECK_SERVICE_USER:-$SERVICE_USER}"
	ENV_FILE="$CONF_DIR/serverdeck.env"
fi

apply_overrides() {
	[ -z "$OPT_PREFIX" ] || PREFIX=$OPT_PREFIX
	[ -z "$OPT_DATA_DIR" ] || DATA_DIR=$OPT_DATA_DIR
	[ -z "$OPT_PORT" ] || PORT=$OPT_PORT
	[ -z "$OPT_BIND" ] || BIND=$OPT_BIND
	[ -z "$OPT_URL" ] || APP_URL_VALUE=$OPT_URL
}

apply_overrides

is_managed_path() {
	case "$1" in
		/|"")
			return 1
			;;
		/*) ;;
		*) return 1 ;;
	esac

	case "$1" in
		*[!A-Za-z0-9/._-]*) return 1 ;;
	esac

	return 0
}

validate_ipv4() {
	value=$1
	case "$value" in
		""|*[!0-9.]*)
			return 1
			;;
	esac
	old_ifs=$IFS
	IFS=.
	# shellcheck disable=SC2086
	set -- $value
	IFS=$old_ifs
	[ "$#" -eq 4 ] || return 1
	for octet in "$@"; do
		[ -n "$octet" ] || return 1
		[ "$octet" -ge 0 ] 2>/dev/null && [ "$octet" -le 255 ] 2>/dev/null || return 1
	done
	return 0
}

validate_inputs() {
	is_managed_path "$PREFIX" || fail "Invalid --prefix: use an absolute path without spaces or shell characters."
	is_managed_path "$DATA_DIR" || fail "Invalid --data-dir: use an absolute path without spaces or shell characters."
	is_managed_path "$CONF_DIR" || fail "Invalid configuration directory: $CONF_DIR."
	case "$PORT" in
		''|*[!0-9]*)
			fail "The HTTP port must be a number."
			;;
	esac
	[ "$PORT" -ge 1024 ] && [ "$PORT" -le 65535 ] || fail "The HTTP port must be between 1024 and 65535."
	validate_ipv4 "$BIND" || fail "The bind address must be a valid IPv4 address."
	if [ -n "$APP_URL_VALUE" ]; then
		case "$APP_URL_VALUE" in
			http://*|https://*) ;;
			*) fail "--url must start with http:// or https://" ;;
		esac
		case "$APP_URL_VALUE" in
			*[[:space:]]*) fail "--url must not contain whitespace" ;;
		esac
	fi
	if [ "$PREFIX" = "$DATA_DIR" ] || [ "$PREFIX" = "$CONF_DIR" ] || [ "$DATA_DIR" = "$CONF_DIR" ]; then
		fail "The prefix, data directory and configuration directory must be different."
	fi
}

require_command() {
	if ! command -v "$1" >/dev/null 2>&1; then
		fail "Required command '$1' was not found. $2"
	fi
}

check_platform() {
	info "Checking host platform"

	require_command systemctl "Install systemd, or use the systemd-based release."
	if [ ! -d /run/systemd/system ]; then
		fail "systemd is not running. This release supports systemd hosts; OpenRC support is not included yet."
	fi

	arch=$(uname -m)
	[ "$arch" = "x86_64" ] || fail "This release supports x86_64 only (detected $arch)."

	glibc_version=$(getconf GNU_LIBC_VERSION 2>/dev/null || true)
	[ -n "$glibc_version" ] || fail "A glibc-based Linux distribution is required. musl/Alpine is not supported by this release."
	if ! printf '%s\n' "$glibc_version" | awk '{ split($2, v, "."); exit !(v[1] > 2 || (v[1] == 2 && v[2] >= 31)) }'; then
		fail "glibc 2.31 or newer is required (found $glibc_version)."
	fi

	require_command getent "The libc getent utility is required."
	require_command useradd "The shadow-utils/passwd package is required."
	require_command groupadd "The shadow-utils/passwd package is required."
	require_command runuser "The util-linux package is required."
	require_command sudo "Install sudo and make sure it supports /etc/sudoers.d."
	require_command visudo "Install sudo (which provides visudo)."
	require_command sha256sum "The coreutils package is required."
	require_command tar "The tar package is required."
	require_command install "The coreutils package is required."
	require_command sed "The sed package is required."
	require_command awk "The awk package is required."
	require_command grep "The grep package is required."
	require_command mktemp "The coreutils package is required."

	if ! ldconfig -p 2>/dev/null | grep -q 'libpam\.so\.0'; then
		fail "Linux-PAM (libpam.so.0) is required for Linux account authentication."
	fi

	have_sudoers_include=0
	if [ -f /etc/sudoers ]; then
		if grep -Eq '^[#@]includedir[[:space:]]+/etc/sudoers\.d' /etc/sudoers; then
			have_sudoers_include=1
		fi
	fi
	[ "$have_sudoers_include" -eq 1 ] || fail "/etc/sudoers does not include /etc/sudoers.d. Add '#includedir /etc/sudoers.d' safely with visudo first."

	if [ -r /etc/os-release ]; then
		# shellcheck disable=SC1091
		distro=$(. /etc/os-release && printf '%s' "${PRETTY_NAME:-${ID:-Linux}}")
		say "  ${DIM}${distro}${RESET} / $arch / $glibc_version"
	fi
	success "Platform checks passed"
}

download_release() {
	url=$1
	[ -n "$RELEASE_SHA256" ] || fail "--release-url requires --sha256 so the download can be verified."

	archive="$WORK_DIR/serverdeck-release.tar.gz"
	info "Downloading release"
	if command -v curl >/dev/null 2>&1; then
		curl -fL --retry 3 --connect-timeout 15 -o "$archive" "$url" || fail "Could not download $url"
	elif command -v wget >/dev/null 2>&1; then
		wget -O "$archive" "$url" || fail "Could not download $url"
	else
		fail "curl or wget is required to download a release."
	fi

	printf '%s  %s\n' "$RELEASE_SHA256" "$archive" | sha256sum -c - >/dev/null 2>&1 || fail "Release checksum verification failed."
	success "Download checksum verified"

	extract_release "$archive"
}

extract_release() {
	archive=$1
	extract_dir="$WORK_DIR/extracted"
	mkdir -p "$extract_dir"
	tar -xzf "$archive" -C "$extract_dir" || fail "Could not extract $archive"

	set -- "$extract_dir"/*
	[ "$#" -eq 1 ] || fail "The release archive must contain exactly one top-level directory."
	[ -d "$1" ] || fail "The release archive has an unexpected layout."
	SOURCE_ROOT=$1
}

prepare_release() {
	script_dir=$(CDPATH= cd -- "$(dirname -- "$0")" && pwd)
	WORK_DIR=$(mktemp -d "$DEFAULT_CONF_DIR/.install.XXXXXX" 2>/dev/null || mktemp -d)

	if [ -n "$RELEASE_PATH" ]; then
		case "$RELEASE_PATH" in
			http://*|https://*)
				RELEASE_URL=$RELEASE_PATH
				RELEASE_PATH=""
				;;
		esac
	fi

	if [ -n "$RELEASE_URL" ]; then
		download_release "$RELEASE_URL"
	elif [ -n "$RELEASE_PATH" ]; then
		[ -e "$RELEASE_PATH" ] || fail "Release path does not exist: $RELEASE_PATH"
		case "$RELEASE_PATH" in
			*.tar.gz|*.tgz)
				extract_release "$RELEASE_PATH"
				;;
			*)
				[ -d "$RELEASE_PATH" ] || fail "Release path must be a .tar.gz archive or an extracted directory."
				SOURCE_ROOT=$(CDPATH= cd -- "$RELEASE_PATH" && pwd)
				;;
		esac
	elif [ -f "$script_dir/VERSION" ] && [ -d "$script_dir/payload" ]; then
		SOURCE_ROOT=$script_dir
	elif [ -f "$script_dir/VERSION" ] && [ -f "$script_dir/install.sh" ]; then
		fail "This looks like an incomplete release. Extract the full serverdeck-*.tar.gz archive first."
	else
		fail "No release payload found. Run this script from an extracted release, or pass --release PATH."
	fi

	[ -f "$SOURCE_ROOT/VERSION" ] || fail "Release VERSION file is missing."
	[ -d "$SOURCE_ROOT/payload" ] || fail "Release payload/ directory is missing."
	[ -x "$SOURCE_ROOT/payload/bin/php" ] || fail "Bundled PHP CLI is missing from the release."
	[ -x "$SOURCE_ROOT/payload/bin/frankenphp" ] || fail "Bundled FrankenPHP runtime is missing from the release."
	[ -f "$SOURCE_ROOT/payload/vendor/autoload.php" ] || fail "Production Composer dependencies are missing from the release."
	[ -f "$SOURCE_ROOT/payload/public/build/manifest.json" ] || fail "Built frontend assets are missing from the release."
	[ -x "$SOURCE_ROOT/helper/serverdeck-hostctl" ] || fail "Bundled host helper is missing from the release."
	[ -x "$SOURCE_ROOT/helper/pamcheck" ] || fail "Bundled PAM helper is missing from the release."
	[ -x "$SOURCE_ROOT/bin/serverdeck" ] || fail "Bundled serverdeck CLI is missing from the release."
	[ -f "$SOURCE_ROOT/templates/Caddyfile.in" ] || fail "Release templates are incomplete."

	VERSION=$(head -n 1 "$SOURCE_ROOT/VERSION")
	case "$VERSION" in
		""|*[!A-Za-z0-9._-]*) fail "Invalid release version: $VERSION" ;;
	esac

	if [ -f "$SOURCE_ROOT/BUILD_ID" ]; then
		BUILD_ID=$(head -n 1 "$SOURCE_ROOT/BUILD_ID")
	else
		BUILD_ID=$(date -u +%Y%m%dT%H%M%SZ)
	fi
	case "$BUILD_ID" in
		""|*[!A-Za-z0-9._-]*) fail "Invalid build id: $BUILD_ID" ;;
	esac

	if [ -f "$SOURCE_ROOT/SHA256SUMS" ]; then
		(cd "$SOURCE_ROOT" && sha256sum -c SHA256SUMS --quiet) || fail "Release integrity check failed."
		success "Release integrity verified"
	else
		fail "SHA256SUMS is missing; refusing to install an unverified release."
	fi
}

check_existing_service() {
	if systemctl is-active --quiet serverdeck.service 2>/dev/null; then
		SERVICE_WAS_ACTIVE=1
	fi
}

stop_services() {
	if systemctl list-unit-files serverdeck.service >/dev/null 2>&1; then
		systemctl stop serverdeck-scheduler.timer >/dev/null 2>&1 || true
		systemctl stop serverdeck.service >/dev/null 2>&1 || true
	fi
}

create_service_account() {
	if ! getent group "$SERVICE_USER" >/dev/null 2>&1; then
		groupadd --system "$SERVICE_USER" || fail "Could not create the $SERVICE_USER group."
		success "Created group $SERVICE_USER"
	fi

	if ! getent passwd "$SERVICE_USER" >/dev/null 2>&1; then
		nologin=""
		for candidate in /usr/sbin/nologin /sbin/nologin /usr/bin/nologin /bin/false; do
			if [ -x "$candidate" ]; then
				nologin=$candidate
				break
			fi
		done
		[ -n "$nologin" ] || fail "Could not find a nologin shell."

		useradd --system \
			--gid "$SERVICE_USER" \
			--home-dir "$DATA_DIR" \
			--shell "$nologin" \
			--comment "ServerDeck service" \
			"$SERVICE_USER" || fail "Could not create the $SERVICE_USER service account."
		success "Created service account $SERVICE_USER"
	fi
}

prepare_directories() {
	install -d -o root -g root -m 0755 "$PREFIX"
	install -d -o root -g root -m 0755 "$PREFIX/releases"
	install -d -o root -g "$SERVICE_USER" -m 0750 "$CONF_DIR"
	install -d -o "$SERVICE_USER" -g "$SERVICE_USER" -m 0750 "$DATA_DIR"
	install -d -o "$SERVICE_USER" -g "$SERVICE_USER" -m 0750 \
		"$DATA_DIR/database" \
		"$DATA_DIR/backups" \
		"$DATA_DIR/bootstrap-cache" \
		"$DATA_DIR/storage" \
		"$DATA_DIR/storage/app" \
		"$DATA_DIR/storage/app/public" \
		"$DATA_DIR/storage/framework" \
		"$DATA_DIR/storage/framework/cache" \
		"$DATA_DIR/storage/framework/cache/data" \
		"$DATA_DIR/storage/framework/sessions" \
		"$DATA_DIR/storage/framework/views" \
		"$DATA_DIR/storage/logs" \
		"$DATA_DIR/.config" \
		"$DATA_DIR/.local" \
		"$DATA_DIR/.local/share" \
		"$DATA_DIR/.cache"
	install -d -o root -g "$SERVICE_USER" -m 0750 "/usr/local/libexec/serverdeck"

	if [ ! -f "$DATA_DIR/database/database.sqlite" ]; then
		install -o "$SERVICE_USER" -g "$SERVICE_USER" -m 0600 /dev/null "$DATA_DIR/database/database.sqlite"
	fi
}

check_port_available() {
	if command -v ss >/dev/null 2>&1; then
		if ss -ltnH "sport = :$PORT" 2>/dev/null | grep -q .; then
			fail "TCP port $PORT is already in use. Choose another port with --port."
		fi
	fi
}

backup_database() {
	[ -f "$DATA_DIR/database/database.sqlite" ] || return 0
	old_php=""
	if [ -n "$OLD_RELEASE" ] && [ -x "$OLD_RELEASE/bin/php" ]; then
		old_php="$OLD_RELEASE/bin/php"
	fi

	DB_BACKUP="$DATA_DIR/backups/pre-upgrade-$(date -u +%Y%m%dT%H%M%SZ).sqlite"
	if [ -n "$old_php" ]; then
		runuser -u "$SERVICE_USER" -- env HOME="$DATA_DIR" "$old_php" -r '
			$db = $argv[1];
			if (! file_exists($db)) { exit(0); }
			$pdo = new PDO("sqlite:".$db);
			$pdo->exec("PRAGMA wal_checkpoint(TRUNCATE)");
		' "$DATA_DIR/database/database.sqlite" || warning "Could not checkpoint the SQLite database before backup."
	fi

	cp -p "$DATA_DIR/database/database.sqlite" "$DB_BACKUP" || fail "Could not back up the SQLite database."
	chown "$SERVICE_USER:$SERVICE_USER" "$DB_BACKUP"
	chmod 0600 "$DB_BACKUP"
	success "Database backed up to $DB_BACKUP"
}

write_config() {
	conf_tmp="$CONF_DIR/serverdeck.conf.tmp"
	cat > "$conf_tmp" <<EOF
# Managed by ServerDeck. Values are read by the serverdeck CLI and installer.
SERVERDECK_PREFIX='$PREFIX'
SERVERDECK_DATA_DIR='$DATA_DIR'
SERVERDECK_CONF_DIR='$CONF_DIR'
SERVERDECK_PORT='$PORT'
SERVERDECK_BIND='$BIND'
SERVERDECK_SERVICE_USER='$SERVICE_USER'
SERVERDECK_VERSION='$VERSION'
EOF
	chmod 0644 "$conf_tmp"
	mv -f "$conf_tmp" "$CONF_FILE"
}

env_get() {
	key=$1
	[ -r "$ENV_FILE" ] || return 0
	sed -n "s/^${key}=//p" "$ENV_FILE" | head -n 1
}

env_put() {
	key=$1
	value=$2
	tmp="$ENV_FILE.tmp"
	KEY="$key" VALUE="$value" awk '
		BEGIN { done = 0; key = ENVIRON["KEY"]; value = ENVIRON["VALUE"] }
		index($0, key "=") == 1 { print key "=" value; done = 1; next }
		{ print }
		END { if (! done) print key "=" value }
	' "$ENV_FILE" > "$tmp"
	cat "$tmp" > "$ENV_FILE"
	rm -f "$tmp"
}

env_ensure() {
	key=$1
	value=$2
	current=$(env_get "$key")
	if [ -z "$current" ]; then
		env_put "$key" "$value"
	fi
}

write_environment() {
	first_install=0
	if [ ! -f "$ENV_FILE" ]; then
		first_install=1
		app_key=$("$NEW_RELEASE/bin/php" -r 'echo "base64:".base64_encode(random_bytes(32));') || fail "Could not generate APP_KEY."
		if [ -z "$APP_URL_VALUE" ]; then
			host_ip=$(hostname -I 2>/dev/null | awk '{ print $1 }' || true)
			[ -n "$host_ip" ] || host_ip=127.0.0.1
			APP_URL_VALUE="http://$host_ip:$PORT"
		fi

		umask 027
		cat > "$ENV_FILE" <<EOF
APP_NAME=ServerDeck
APP_ENV=production
APP_KEY=$app_key
APP_DEBUG=false
APP_URL=$APP_URL_VALUE
APP_LOCALE=en
APP_FALLBACK_LOCALE=en
APP_FAKER_LOCALE=en_US
APP_MAINTENANCE_DRIVER=file
BCRYPT_ROUNDS=12
LOG_CHANNEL=daily
LOG_LEVEL=info
LOG_DAILY_DAYS=14
DB_CONNECTION=sqlite
DB_DATABASE=$DATA_DIR/database/database.sqlite
SESSION_DRIVER=database
SESSION_LIFETIME=120
SESSION_ENCRYPT=false
SESSION_PATH=/
SESSION_DOMAIN=null
BROADCAST_CONNECTION=log
FILESYSTEM_DISK=local
QUEUE_CONNECTION=database
CACHE_STORE=database
MAIL_MAILER=log
MAIL_FROM_ADDRESS=serverdeck@localhost
MAIL_FROM_NAME="ServerDeck"
SERVERDECK_HOSTCTL=$HELPER_PATH
EOF
		chmod 0640 "$ENV_FILE"
		chown root:"$SERVICE_USER" "$ENV_FILE"
		success "Created $ENV_FILE"
	fi

	env_ensure "APP_NAME" "ServerDeck"
	env_ensure "APP_ENV" "production"
	env_ensure "APP_DEBUG" "false"
	env_ensure "APP_LOCALE" "en"
	env_ensure "APP_FALLBACK_LOCALE" "en"
	env_ensure "APP_MAINTENANCE_DRIVER" "file"
	env_ensure "LOG_CHANNEL" "daily"
	env_ensure "LOG_LEVEL" "info"
	env_ensure "LOG_DAILY_DAYS" "14"
	env_ensure "DB_CONNECTION" "sqlite"
	env_ensure "DB_DATABASE" "$DATA_DIR/database/database.sqlite"
	env_ensure "SESSION_DRIVER" "database"
	env_ensure "SESSION_LIFETIME" "120"
	env_ensure "CACHE_STORE" "database"
	env_ensure "QUEUE_CONNECTION" "database"
	env_ensure "MAIL_MAILER" "log"
	env_ensure "MAIL_FROM_ADDRESS" "serverdeck@localhost"
	env_ensure "SERVERDECK_HOSTCTL" "$HELPER_PATH"

	app_key=$(env_get "APP_KEY")
	if [ -z "$app_key" ]; then
		app_key=$("$NEW_RELEASE/bin/php" -r 'echo "base64:".base64_encode(random_bytes(32));') || fail "Could not generate APP_KEY."
		env_put "APP_KEY" "$app_key"
	fi

	if [ -n "$APP_URL_VALUE" ]; then
		env_put "APP_URL" "$APP_URL_VALUE"
	elif [ -z "$(env_get APP_URL)" ]; then
		env_put "APP_URL" "http://127.0.0.1:$PORT"
	fi

	chmod 0640 "$ENV_FILE"
	chown root:"$SERVICE_USER" "$ENV_FILE"
}

render_template() {
	template=$1
	destination=$2
	mode=$3
	tmp="$destination.tmp"
	sed \
		-e "s|@PREFIX@|$PREFIX|g" \
		-e "s|@DATA_DIR@|$DATA_DIR|g" \
		-e "s|@CONF_DIR@|$CONF_DIR|g" \
		-e "s|@USER@|$SERVICE_USER|g" \
		-e "s|@PORT@|$PORT|g" \
		-e "s|@BIND@|$BIND|g" \
		"$template" > "$tmp"
	chmod "$mode" "$tmp"
	mv -f "$tmp" "$destination"
}

deploy_release() {
	if [ -n "$(current_release_path)" ]; then
		OLD_RELEASE=$(current_release_path)
	fi

	NEW_RELEASE="$PREFIX/releases/${VERSION}-${BUILD_ID}"
	if [ -e "$NEW_RELEASE" ]; then
		rm -rf "$NEW_RELEASE"
	fi
	install -d -o root -g root -m 0755 "$NEW_RELEASE"

	info "Installing application files"
	cp -a "$SOURCE_ROOT/payload/." "$NEW_RELEASE/" || fail "Could not copy the application payload."
	cp "$SOURCE_ROOT/VERSION" "$NEW_RELEASE/VERSION"
	printf '%s\n' "$BUILD_ID" > "$NEW_RELEASE/BUILD_ID"
	if [ -f "$SOURCE_ROOT/README.md" ]; then
		cp "$SOURCE_ROOT/README.md" "$NEW_RELEASE/README.md"
	fi
	if [ -f "$SOURCE_ROOT/THIRD-PARTY-NOTICES.md" ]; then
		cp "$SOURCE_ROOT/THIRD-PARTY-NOTICES.md" "$NEW_RELEASE/THIRD-PARTY-NOTICES.md"
	fi

	rm -f "$NEW_RELEASE/.env"
	ln -s "$ENV_FILE" "$NEW_RELEASE/.env"

	rm -rf "$NEW_RELEASE/storage"
	ln -s "$DATA_DIR/storage" "$NEW_RELEASE/storage"

	rm -rf "$NEW_RELEASE/bootstrap/cache"
	ln -s "$DATA_DIR/bootstrap-cache" "$NEW_RELEASE/bootstrap/cache"

	rm -f "$NEW_RELEASE/database/database.sqlite" "$NEW_RELEASE/database/database.sqlite-wal" "$NEW_RELEASE/database/database.sqlite-shm"

	rm -f "$NEW_RELEASE/public/storage"
	ln -s "$DATA_DIR/storage/app/public" "$NEW_RELEASE/public/storage"

	chown -R root:root "$NEW_RELEASE"
	chmod -R go-w "$NEW_RELEASE"
	chmod 0755 "$NEW_RELEASE/bin/php" "$NEW_RELEASE/bin/frankenphp"

	ln -sfn "$NEW_RELEASE" "$PREFIX/current"
	success "Deployed $VERSION ($BUILD_ID)"
}

current_release_path() {
	if [ -L "$PREFIX/current" ] || [ -d "$PREFIX/current" ]; then
		readlink -f "$PREFIX/current" 2>/dev/null || true
	fi
}

install_templates() {
	info "Writing configuration and service units"
	render_template "$SOURCE_ROOT/templates/Caddyfile.in" "$CONF_DIR/Caddyfile" 0644
	render_template "$SOURCE_ROOT/templates/serverdeck.service.in" "/etc/systemd/system/serverdeck.service" 0644
	render_template "$SOURCE_ROOT/templates/serverdeck-scheduler.service.in" "/etc/systemd/system/serverdeck-scheduler.service" 0644
	render_template "$SOURCE_ROOT/templates/serverdeck-scheduler.timer.in" "/etc/systemd/system/serverdeck-scheduler.timer" 0644

	install -o root -g root -m 0755 "$SOURCE_ROOT/bin/serverdeck" "$CLI_PATH"
}

install_helper() {
	info "Installing privileged helper"

	helper_tmp="/usr/local/sbin/.serverdeck-hostctl.$$"
	install -o root -g root -m 0755 "$SOURCE_ROOT/helper/serverdeck-hostctl" "$helper_tmp" || fail "Could not install the host helper."
	mv -f "$helper_tmp" "$HELPER_PATH"

	pamcheck_tmp="/usr/local/libexec/serverdeck/.pamcheck.$$"
	install -o root -g root -m 0755 "$SOURCE_ROOT/helper/pamcheck" "$pamcheck_tmp" || fail "Could not install the PAM helper."
	mv -f "$pamcheck_tmp" "$PAMCHECK_PATH"

	if command -v restorecon >/dev/null 2>&1; then
		restorecon -F "$HELPER_PATH" "$PAMCHECK_PATH" >/dev/null 2>&1 || true
	fi

	helper_out=$(printf '%s' '{"op":"systemd.list"}' | "$HELPER_PATH" 2>&1 || true)
	if ! printf '%s' "$helper_out" | grep -q '"ok": true'; then
		fail "The bundled privileged helper did not start correctly: $helper_out"
	fi
	success "Host helper is operational"
}

install_pam() {
	pam_file="/etc/pam.d/serverdeck"
	if [ -f "$pam_file" ] && ! grep -q "$MANAGED_MARKER" "$pam_file"; then
		backup="$pam_file.bak-$(date -u +%Y%m%dT%H%M%SZ)"
		cp -p "$pam_file" "$backup"
		warning "Existing PAM service backed up to $backup"
	fi

	if [ -f /etc/pam.d/system-auth ]; then
		cat > "$pam_file" <<EOF
$MANAGED_MARKER
auth include system-auth
account include system-auth
EOF
	elif [ -f /etc/pam.d/common-auth ]; then
		cat > "$pam_file" <<EOF
$MANAGED_MARKER
@include common-auth
@include common-account
EOF
	else
		cat > "$pam_file" <<EOF
$MANAGED_MARKER
auth required pam_unix.so
account required pam_unix.so
EOF
	fi
	chmod 0644 "$pam_file"
	chown root:root "$pam_file"
	if command -v restorecon >/dev/null 2>&1; then
		restorecon -F "$pam_file" >/dev/null 2>&1 || true
	fi
}

install_sudoers() {
	sudoers_tmp=$(mktemp)
	cat > "$sudoers_tmp" <<EOF
Defaults:$SERVICE_USER !requiretty
$SERVICE_USER ALL=(root) NOPASSWD: $HELPER_PATH
EOF
	if ! visudo -cf "$sudoers_tmp" >/dev/null 2>&1; then
		rm -f "$sudoers_tmp"
		fail "Generated sudoers rule is invalid."
	fi
	install -o root -g root -m 0440 "$sudoers_tmp" /etc/sudoers.d/serverdeck
	rm -f "$sudoers_tmp"
	if command -v restorecon >/dev/null 2>&1; then
		restorecon -F /etc/sudoers.d/serverdeck >/dev/null 2>&1 || true
	fi
}

run_artisan() {
	runuser -u "$SERVICE_USER" -- env \
		HOME="$DATA_DIR" \
		XDG_CONFIG_HOME="$DATA_DIR/.config" \
		XDG_DATA_HOME="$DATA_DIR/.local/share" \
		XDG_CACHE_HOME="$DATA_DIR/.cache" \
		"$NEW_RELEASE/bin/php" "$NEW_RELEASE/artisan" "$@"
}

prepare_application() {
	info "Preparing the application database"

	(
		cd "$NEW_RELEASE"
		run_artisan config:clear >/dev/null 2>&1 || true
		run_artisan route:clear >/dev/null 2>&1 || true
		run_artisan view:clear >/dev/null 2>&1 || true
		run_artisan package:discover --no-interaction || return 1
		run_artisan migrate --force --no-interaction || return 1
		run_artisan config:cache || return 1
		if ! run_artisan route:cache; then
			warning "Route caching was skipped; routes will load normally."
			run_artisan route:clear >/dev/null 2>&1 || true
		fi
		run_artisan view:cache || true
	) || return 1

	success "Database migrations are up to date"
	return 0
}

enable_and_start() {
	systemctl daemon-reload || fail "systemctl daemon-reload failed."

	systemctl enable serverdeck.service >/dev/null 2>&1 || fail "Could not enable serverdeck.service."
	systemctl enable serverdeck-scheduler.timer >/dev/null 2>&1 || fail "Could not enable serverdeck-scheduler.timer."

	if [ "$NO_START" -eq 1 ]; then
		warning "--no-start selected; the service is enabled but not started."
		return 0
	fi

	systemctl restart serverdeck.service || fail "Could not start serverdeck.service."
	systemctl restart serverdeck-scheduler.timer || fail "Could not start serverdeck-scheduler.timer."
}

health_check() {
	health_host=127.0.0.1
	if [ "$BIND" != "0.0.0.0" ]; then
		health_host=$BIND
	fi
	url="http://$health_host:$PORT/login"

	attempt=0
	while [ "$attempt" -lt 20 ]; do
		status=$("$NEW_RELEASE/bin/php" -r '
			$url = $argv[1];
			$context = stream_context_create(["http" => ["timeout" => 2, "ignore_errors" => true]]);
			@file_get_contents($url, false, $context);
			$line = $http_response_header[0] ?? "";
			if (preg_match("#^HTTP/\\S+\\s+(\\d+)#", $line, $match)) { echo $match[1]; }
		' "$url" 2>/dev/null || true)
		if [ "$status" = "200" ]; then
			success "Login page responds at $url"
			return 0
		fi
		attempt=$((attempt + 1))
		sleep 1
	done

	printf '\n' >&2
	systemctl status serverdeck.service --no-pager --lines=0 >&2 || true
	journalctl -u serverdeck.service -n 40 --no-pager >&2 || true
	return 1
}

open_firewall() {
	[ "$OPEN_FIREWALL" -eq 1 ] || return 0
	if command -v firewall-cmd >/dev/null 2>&1 && firewall-cmd --state >/dev/null 2>&1; then
		firewall-cmd --permanent --add-port="$PORT/tcp" >/dev/null 2>&1 || warning "Could not add $PORT/tcp to firewalld."
		firewall-cmd --reload >/dev/null 2>&1 || warning "Could not reload firewalld."
		success "Opened $PORT/tcp in firewalld"
	elif command -v ufw >/dev/null 2>&1 && ufw status 2>/dev/null | grep -q "Status: active"; then
		ufw allow "$PORT/tcp" >/dev/null 2>&1 || warning "Could not add $PORT/tcp to ufw."
		success "Opened $PORT/tcp in ufw"
	else
		warning "No active ufw/firewalld detected; open TCP $PORT yourself if needed."
	fi
}

rollback_upgrade() {
	[ "$INSTALL_COMMITTED" -eq 0 ] || return 0
	[ -n "$OLD_RELEASE" ] || return 0

	warning "Rolling back to the previous release"
	systemctl stop serverdeck-scheduler.timer >/dev/null 2>&1 || true
	systemctl stop serverdeck.service >/dev/null 2>&1 || true
	ln -sfn "$OLD_RELEASE" "$PREFIX/current"

	if [ -n "$DB_BACKUP" ] && [ -f "$DB_BACKUP" ]; then
		cp -p "$DB_BACKUP" "$DATA_DIR/database/database.sqlite"
		rm -f "$DATA_DIR/database/database.sqlite-wal" "$DATA_DIR/database/database.sqlite-shm"
		chown "$SERVICE_USER:$SERVICE_USER" "$DATA_DIR/database/database.sqlite"
		chmod 0600 "$DATA_DIR/database/database.sqlite"
		warning "Restored the database backup from $DB_BACKUP"
	fi

	systemctl daemon-reload >/dev/null 2>&1 || true
	systemctl start serverdeck.service >/dev/null 2>&1 || true
	systemctl start serverdeck-scheduler.timer >/dev/null 2>&1 || true
	warning "Previous release restarted; inspect logs before retrying."
}

prune_releases() {
	current=$(current_release_path)
	[ -n "$current" ] || return 0
	count=0
	for directory in $(ls -1dt "$PREFIX"/releases/* 2>/dev/null); do
		[ -d "$directory" ] || continue
		count=$((count + 1))
		[ "$count" -le 3 ] && continue
		[ "$directory" = "$current" ] && continue
		rm -rf "$directory"
	done
}

install_serverdeck() {
	validate_inputs
	check_platform
	check_existing_service
	stop_services

	if [ -n "$(current_release_path)" ]; then
		OLD_RELEASE=$(current_release_path)
	fi

	create_service_account
	prepare_directories
	check_port_available

	backup_database
	deploy_release
	write_config
	write_environment
	install_templates
	install_helper
	install_pam
	install_sudoers

	if ! prepare_application; then
		rollback_upgrade
		fail "Application setup failed. The previous release was kept where possible."
	fi

	if ! enable_and_start; then
		rollback_upgrade
		fail "ServerDeck did not start."
	fi

	if [ "$NO_START" -eq 0 ]; then
		if ! health_check; then
			rollback_upgrade
			fail "The web service did not become healthy."
		fi
	fi

	open_firewall
	INSTALL_COMMITTED=1
	prune_releases

	printf '\n'
	printf '%s%s%s is installed.\n' "$BOLD" "$APP_NAME" "$RESET"
	printf '  Version:  %s (%s)\n' "$VERSION" "$BUILD_ID"
	printf '  URL:      %s\n' "$( [ -n "$APP_URL_VALUE" ] && printf '%s' "$APP_URL_VALUE" || env_get APP_URL )"
	printf '  Service:  %s\n' "$SERVICE_NAME.service"
	printf '  Data:     %s\n' "$DATA_DIR"
	printf '  Config:   %s\n' "$CONF_DIR"
	printf '\n'
	printf 'Sign in with any local Linux account that can use sudo (sudo or wheel group).\n'
	printf 'Those accounts become ServerDeck administrators on first login; other accounts are read-only.\n'
	printf 'Run %ssudo serverdeck doctor%s to verify optional integrations.\n' "$BOLD" "$RESET"

	if [ "$SERVICE_WAS_ACTIVE" -eq 0 ] && [ "$NO_START" -eq 1 ]; then
		printf '\nThe service was not started. Start it with: sudo serverdeck start\n'
	elif command -v firewall-cmd >/dev/null 2>&1 || command -v ufw >/dev/null 2>&1; then
		printf '\nIf the web UI is unreachable, allow TCP %s through the host firewall.\n' "$PORT"
	fi
}

uninstall_serverdeck() {
	info "Removing ServerDeck"

	if [ "$PURGE" -eq 1 ] && [ "$ASSUME_YES" -ne 1 ]; then
		printf 'This will permanently delete %s and %s.\n' "$DATA_DIR" "$CONF_DIR"
		printf 'Type "purge" to continue: '
		read -r answer
		[ "$answer" = "purge" ] || fail "Uninstall cancelled."
	fi

	systemctl stop serverdeck-scheduler.timer >/dev/null 2>&1 || true
	systemctl stop serverdeck.service >/dev/null 2>&1 || true
	systemctl disable serverdeck-scheduler.timer >/dev/null 2>&1 || true
	systemctl disable serverdeck.service >/dev/null 2>&1 || true

	rm -f \
		/etc/systemd/system/serverdeck.service \
		/etc/systemd/system/serverdeck-scheduler.service \
		/etc/systemd/system/serverdeck-scheduler.timer \
		"$CLI_PATH" \
		"$HELPER_PATH" \
		"$PAMCHECK_PATH" \
		/etc/sudoers.d/serverdeck \
		/etc/pam.d/serverdeck

	rmdir /usr/local/libexec/serverdeck >/dev/null 2>&1 || true
	rm -rf "$PREFIX"

	systemctl daemon-reload >/dev/null 2>&1 || true
	systemctl reset-failed serverdeck.service serverdeck-scheduler.service >/dev/null 2>&1 || true

	if [ "$PURGE" -eq 1 ]; then
		rm -rf "$DATA_DIR" "$CONF_DIR"
		if getent passwd "$SERVICE_USER" >/dev/null 2>&1; then
			userdel "$SERVICE_USER" >/dev/null 2>&1 || true
		fi
		if getent group "$SERVICE_USER" >/dev/null 2>&1; then
			groupdel "$SERVICE_USER" >/dev/null 2>&1 || true
		fi
		success "ServerDeck, configuration, data and service account removed"
	else
		success "ServerDeck removed"
		printf '\nPreserved:\n  %s\n  %s\n' "$DATA_DIR" "$CONF_DIR"
		printf 'Use --purge to remove them as well.\n'
	fi
}

if [ "$UNINSTALL" -eq 1 ]; then
	uninstall_serverdeck
	exit 0
fi

prepare_release
install_serverdeck
