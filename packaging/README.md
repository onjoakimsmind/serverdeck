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

Not yet packaged: Alpine/musl, OpenRC, non-x86_64 architectures. The
installer adds the base host tools `smartmontools` and `pciutils` by default
through the native package manager. Incus, Docker, and ZFS are opt-in
workloads and are otherwise detected at runtime when already installed.

## Install

Download the tarball and checksum from the repository's
[Releases](https://github.com/onjoakimsmind/serverdeck/releases) page. Release
tags are built by GitHub Actions; build artifacts are not committed to Git.

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
sudo ./install.sh --workloads incus,docker
sudo ./install.sh --no-packages
```

Sign in with a local Linux account that belongs to `sudo` (Debian/Ubuntu) or
`wheel` (Fedora/RHEL). The first successful login provisions the account as a
ServerDeck administrator. Other Linux accounts are provisioned read-only.

There is no separate ServerDeck password database. Passwords are checked by
PAM against the host's existing Linux authentication stack. ServerDeck never
stores the submitted password.

## Host packages

The installer uses APT, DNF/DNF5, or Pacman and installs these base tools
unless `--no-packages` is given:

| Logical package | Purpose | APT | DNF | Pacman |
| --- | --- | --- | --- | --- |
| `smartmontools` | SMART disk health | `smartmontools` | `smartmontools` | `smartmontools` |
| `pciutils` | PCI/GPU inventory | `pciutils` | `pciutils` | `pciutils` |

Workloads are opt-in and can be selected with `--workloads LIST`:

| Workload | APT candidate | DNF candidate | Pacman candidate |
| --- | --- | --- | --- |
| `incus` | `incus` | `incus` | `incus` |
| `docker` | `docker.io`, then `docker-ce` | `moby-engine`, then `docker-ce`, `docker` | `docker` |
| `zfs` | `zfsutils-linux` | `zfs` | `zfs-utils` |

`--workloads all` selects Incus, Docker, and ZFS. A requested workload that
cannot be installed is a hard failure with the package manager output; optional
base-tool failures are warnings because the rest of the panel still works.

Package repositories are not modified. In particular:

- Fedora's official repositories provide `moby-engine`, not Docker CE. Add the
  Docker CE repository yourself before using `--workloads docker` if that is
  the desired engine.
- ZFS is not available in the official Fedora or Arch repositories. Configure
  OpenZFS repositories first, or use a distribution such as Ubuntu that ships
  `zfsutils-linux`.
- On Arch, the installer only runs `pacman -Sy` when no sync database exists;
  otherwise it uses the host's current package metadata and refreshes once if
  an install fails.

Package installation happens before the running service is stopped during an
upgrade, so a failed package operation leaves an existing installation
untouched.

The packages installed by the installer are recorded in
`/etc/serverdeck/workload-packages.conf`:

```sh
SERVERDECK_PACKAGE_MANAGER='apt'
SERVERDECK_INSTALLED_PACKAGES='smartmontools pciutils incus incus-base ...'
SERVERDECK_WORKLOAD_PACKAGES='incus incus-base ...'
```

For opt-in workloads, the installer snapshots the installed package set before
and after the transaction. Packages added by that transaction, including
dependencies, are recorded as workload packages. This matters for APT, where
`incus` is a metapackage and removing only the metapackage would leave the
daemon behind. The manifest distinguishes base tools from opt-in workloads:
only packages in `SERVERDECK_WORKLOAD_PACKAGES` are removable, and only if
ServerDeck installed them. Packages that were already present when ServerDeck
was installed are not recorded and are therefore never removed. On APT,
dependencies of base tools are not tracked because base tools are never
removed.

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
| `/etc/serverdeck/workload-packages.conf` | Packages installed by ServerDeck |
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
sudo ./install.sh --uninstall --remove-workloads
sudo ./install.sh --uninstall --purge --yes
```

Without `--purge`, the application, units, helper, PAM service, sudo rule and
the `pam_faillock` tmpfiles entry are removed while `/var/lib/serverdeck` and
`/etc/serverdeck` are preserved.

`--remove-workloads` additionally removes the opt-in packages recorded in
`SERVERDECK_WORKLOAD_PACKAGES`. `--purge` implies that removal and also deletes
configuration, data, and the service account. In both cases:

- Base tools such as `smartmontools` and `pciutils` remain installed.
- Packages that were already installed before ServerDeck are never removed.
- Workload data directories such as `/var/lib/incus` and `/var/lib/docker`
  remain in place and must be handled separately if they are no longer needed.
- On Pacman, removal uses `pacman -Rns`; review the transaction if other host
  packages depend on the workload's dependencies.
- If package removal fails, the manifest and any preserved configuration and
  data are left in place so the uninstall can be retried after fixing the
  package manager error.

## Security notes

- The default listener is plain HTTP. Use a TLS-terminating reverse proxy or
  restrict the listen address to a trusted management network.
- The privileged helper is allow-listed server-side; all arguments travel as
  JSON on stdin. It is installed root-owned and executable only by root.
- The helper runs through `sudo -n` and validates every operation and path.
- The application runs as a dedicated system account, not as `www-data` or
  root.
- No application password hashes are stored.

## Release builds

GitHub Actions builds the release in `.github/workflows/release.yml`:

- `tests` runs the PHP test suite and Pint with PHP 8.5 and Node 22.
- `package` builds the bundled helper in Docker, runs
  `packaging/scripts/build-release.sh --rebuild-assets`, verifies the archive
  and checksum, and uploads both as the `serverdeck-release` workflow artifact.
- `release` runs for `v*` tags and publishes the same tarball and checksum to
  GitHub Releases.

Build outputs are not committed. `packaging/dist`, `packaging/.build`, and
`packaging/helper/__pycache__` are ignored by Git.

Publish a release by updating `packaging/VERSION`, committing the change, and
pushing a matching annotated tag:

```sh
version=$(cat packaging/VERSION)
git tag -a "v${version}" -m "ServerDeck ${version}"
git push origin main "v${version}"
```

The workflow rejects a tag whose version differs from `packaging/VERSION`.

## Rebuilding from source

The CI workflow uses the same build script. On a build host with Docker,
Composer and Node:

```sh
sh packaging/scripts/build-release.sh --rebuild-assets
```

The helper is built in a Debian 11 image for glibc 2.31 compatibility.
FrankenPHP and the static PHP CLI versions and SHA-256 checksums are pinned in
`packaging/scripts/build-release.sh`. Add `--skip-helper` to reuse a helper
already present in `packaging/.build/helper`.

## Third-party components

See `THIRD-PARTY-NOTICES.md` in this release.
