# ServerDeck self-contained release

ServerDeck ships as a single release tarball for Linux. It contains its own
web/PHP runtime (FrankenPHP and Caddy), a static PHP CLI for Laravel Artisan
and the scheduler, the production Composer dependencies, built frontend
assets, a bundled root host helper and a small PAM authentication utility.

The target host does **not** need nginx, Apache, system PHP, Node, npm or
Composer. It does use native Linux accounts through PAM, `sudo` for the
audited root helper, SQLite for panel state, and systemd for supervision.

## Supported hosts

- x86_64 Linux with glibc 2.31 or newer
- systemd as PID 1
- Linux-PAM and sudo with `/etc/sudoers.d`
- Debian 11+, Ubuntu 20.04+, Fedora 38+, RHEL/Rocky/Alma 9+, Arch and
  compatible derivatives

The Updates page uses the host's native package manager:

- APT on Debian and Ubuntu, including security-repository counts
- DNF or DNF5 on Fedora, RHEL, Rocky and Alma, including security advisories
- Pacman on Arch; CVE mapping requires `arch-audit`, otherwise security counts
  are reported as unavailable

Upgrades are applied with `apt-get -y --with-new-pkgs upgrade`,
`dnf -y upgrade`, or `pacman -Syu --noconfirm` respectively. ServerDeck never
reboots the host automatically.

Not yet packaged: Alpine/musl, OpenRC, non-x86_64 architectures. Incus,
Docker, ZFS and smartmontools are optional host integrations and are
detected at runtime.

## Install

Extract the release and run its installer as root:

```sh
sha256sum -c serverdeck-0.1.0-linux-x86_64.tar.gz.sha256
tar -xzf serverdeck-0.1.0-linux-x86_64.tar.gz
cd serverdeck-0.1.0-linux-x86_64
sudo ./install.sh
```

The default URL is `http://<host-ip>:8090`. Common options:

```sh
sudo ./install.sh --port 8443 --bind 0.0.0.0
sudo ./install.sh --url https://panel.example.com --open-firewall
sudo ./install.sh --no-start
```

Sign in with a local Linux account that belongs to `sudo` (Debian/Ubuntu) or
`wheel` (Fedora/RHEL). The first successful login provisions the account as a
ServerDeck administrator. Other Linux accounts are provisioned read-only.

There is no separate ServerDeck password database. Passwords are checked by
PAM against the host's existing Linux authentication stack. ServerDeck never
stores the submitted password.

## Operations

```sh
sudo serverdeck status
sudo serverdeck doctor
sudo serverdeck restart
sudo serverdeck logs -f
sudo serverdeck artisan migrate:status
```

The web service and scheduler are systemd units:

- `serverdeck.service`
- `serverdeck-scheduler.service`
- `serverdeck-scheduler.timer`

### Layout

| Path | Purpose |
| --- | --- |
| `/opt/serverdeck/current` | Active immutable release |
| `/opt/serverdeck/releases` | Current and previous releases |
| `/var/lib/serverdeck/database/database.sqlite` | Panel database |
| `/var/lib/serverdeck/storage` | Laravel storage |
| `/var/lib/serverdeck/backups` | Pre-upgrade database backups |
| `/etc/serverdeck/serverdeck.env` | Application environment |
| `/etc/serverdeck/serverdeck.conf` | Installer/CLI paths and port |
| `/etc/serverdeck/Caddyfile` | FrankenPHP/Caddy configuration |
| `/usr/local/sbin/serverdeck-hostctl` | Root-only audited host helper |
| `/usr/local/libexec/serverdeck/pamcheck` | PAM authentication utility |

The web process runs as the unprivileged `serverdeck` account. Only the
root-owned helper can perform privileged host operations, through one
no-argument sudo rule. Console output and keystrokes are never written to the
audit log.

## Upgrade

Run the installer from the newer tarball. It stops the service, creates a
SQLite backup, deploys the new release, runs migrations, restarts the service
and rolls back automatically if setup or the health check fails. The previous
two releases are retained under `/opt/serverdeck/releases`.

```sh
tar -xzf serverdeck-0.1.1-linux-x86_64.tar.gz
cd serverdeck-0.1.1-linux-x86_64
sudo ./install.sh
```

Changing `--port`, `--bind`, `--url`, or `--prefix` on upgrade updates the
packaged configuration. Existing data and `APP_KEY` are preserved.

## Uninstall

```sh
sudo ./install.sh --uninstall
sudo ./install.sh --uninstall --purge --yes
```

Without `--purge`, the application, units, helper, PAM service, sudo rule and
the `pam_faillock` tmpfiles entry are removed while `/var/lib/serverdeck` and
`/etc/serverdeck` are preserved.

## Security notes

- The default listener is plain HTTP. Use a TLS-terminating reverse proxy or
  restrict the listen address to a trusted management network.
- The privileged helper is allow-listed server-side; all arguments travel as
  JSON on stdin. It is installed root-owned and executable only by root.
- The helper runs through `sudo -n` and validates every operation and path.
- The application runs as a dedicated system account, not as `www-data` or
  root.
- No application password hashes are stored.

## Rebuilding from source

On a build host with Docker, Composer and Node:

```sh
sh packaging/scripts/build-release.sh --rebuild-assets
```

The helper is built in a Debian 11 image for glibc 2.31 compatibility.
FrankenPHP and the static PHP CLI versions and SHA-256 checksums are pinned in
`packaging/scripts/build-release.sh`.

## Third-party components

See `THIRD-PARTY-NOTICES.md` in this release.
