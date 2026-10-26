# Third-party notices

The self-contained release bundles the following components. Their licenses
apply to those components; ServerDeck's own source is separate.

| Component | Version | License | Project |
| --- | --- | --- | --- |
| FrankenPHP | 1.13.3 | MIT | https://github.com/php/frankenphp |
| Caddy | bundled with FrankenPHP | Apache-2.0 | https://github.com/caddyserver/caddy |
| PHP | 8.5.x | PHP License 3.01 | https://www.php.net/license/3_01.txt |
| static-php-cli runtime | 8.5.8 | MIT and component licenses | https://github.com/crazywhalecc/static-php-cli |
| Python runtime (inside host helper) | 3.11.x | PSF License | https://docs.python.org/3/license.html |
| PyInstaller bootloader | 6.16.0 | GPL-2.0 with bootloader exception | https://pyinstaller.org/en/stable/license.html |
| Laravel and Composer dependencies | per `composer.lock` | MIT / respective licenses | https://laravel.com |

FrankenPHP's static binary embeds Caddy and PHP. The PHP runtime build also
links OpenSSL and other libraries whose notices are included in the upstream
distribution. Source and license texts are available from the project URLs
above. Copyright remains with the respective authors.

Linux-PAM, glibc, sudo, systemd, Incus, Docker, ZFS and smartmontools are used
from the host operating system and are not redistributed in this archive.
