#!/usr/bin/python3
"""ServerDeck host helper.

Runs as root through a single no-argument sudo rule. Every operation is
explicitly allow-listed and parameters are validated before a process is
started. JSON is read from stdin and JSON is written to stdout.
"""

import base64
import fcntl
import glob
import http.client
import json
import os
import pwd
import grp
import pty
import re
import ipaddress
import select
import shlex
import signal
import socket
import stat
import struct
import subprocess
import sys
import termios
import time
import urllib.parse

PATH = "/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin"
BASE_ENV = {
    "PATH": PATH,
    "LC_ALL": "C",
    "LANG": "C",
    "HOME": "/root",
}
USERNAME_RE = re.compile(r"^[a-z_][a-z0-9_.-]{0,31}$")
PAMCHECK = "/usr/local/libexec/serverdeck/pamcheck"
PAMTESTER = "/usr/bin/pamtester"
CONTAINER_RE = re.compile(r"^[A-Za-z0-9][A-Za-z0-9_.:-]{0,127}$")
INSTANCE_NAME_RE = re.compile(r"^[A-Za-z0-9][A-Za-z0-9_.-]{0,63}$")
DEVICE_NAME_RE = re.compile(r"^[A-Za-z0-9][A-Za-z0-9_.:-]{0,63}$")
IMAGE_RE = re.compile(r"^(?:[a-f0-9]{64}|images:[A-Za-z0-9][A-Za-z0-9._/-]{0,120})$")
CPU_LIMIT_RE = re.compile(r"^\d{1,3}(?:-\d{1,3})?(?:,\d{1,3}(?:-\d{1,3})?)*$")
MEMORY_LIMIT_RE = re.compile(r"^\d{1,4}(?:\.\d{1,2})?(?:B|kB|MB|GB|TB|KiB|MiB|GiB|TiB|%)$")
VOLUME_SIZE_RE = re.compile(r"^\d{1,5}(?:\.\d{1,2})?(?:B|kB|MB|GB|TB|KiB|MiB|GiB|TiB)$")
VLAN_RE = re.compile(r"^\d{1,4}$")
MTU_RE = re.compile(r"^\d{3,5}$")
INSTANCE_CONFIG_KEYS = {"limits.cpu", "limits.memory", "limits.processes", "boot.autostart", "security.nesting"}
INSTANCE_DISK_PATH_DENY = {"/", "/boot", "/dev", "/etc", "/proc", "/root", "/sys", "/var/lib/docker", "/var/lib/incus"}
PCI_ADDRESS_RE = re.compile(r"^[0-9a-fA-F]{4}:[0-9a-fA-F]{2}:[0-9a-fA-F]{2}\.[0-7]$")
USB_ID_RE = re.compile(r"^[0-9a-fA-F]{4}$")
HOST_DEVICE_PATH_RE = re.compile(r"^/dev/[A-Za-z0-9_.:+@/-]{1,180}$")
PASSTHROUGH_DEVICE_TYPES = {"unix-char", "unix-block", "gpu", "usb", "pci"}
GPU_TYPES = {"physical", "mdev", "mig", "sriov", "native-context"}
HOST_DEVICE_DENY = {
    "/dev/mem",
    "/dev/kmem",
    "/dev/port",
    "/dev/kmsg",
    "/dev/console",
    "/dev/watchdog",
}
UNIT_RE = re.compile(r"^[A-Za-z0-9@_.:-]+\.service$")
ZFS_RE = re.compile(r"^[A-Za-z0-9_.:/-]{1,255}$")
SNAP_RE = re.compile(r"^[A-Za-z0-9_.:-]{1,120}$")
NETWORK_TARGET_RE = re.compile(r"^[A-Za-z0-9]([A-Za-z0-9:._-]{0,251}[A-Za-z0-9])?$")
DIG_RECORD_TYPES = {"A", "AAAA", "MX", "TXT", "NS", "CNAME", "SOA", "PTR"}
FIREWALL_COMMENT_RE = re.compile(r"^[A-Za-z0-9 ._:/-]{0,200}$")
FIREWALL_PORT_RE = re.compile(r"^\d{1,5}(:\d{1,5})?$")
SIGNALS = {"TERM": signal.SIGTERM, "KILL": signal.SIGKILL, "HUP": signal.SIGHUP, "INT": signal.SIGINT}
PRIORITIES = {"emerg": "0", "alert": "1", "crit": "2", "err": "3", "warning": "4", "notice": "5", "info": "6", "debug": "7"}

CONSOLE_RE = re.compile(r"^[a-f0-9]{32}$")
CONSOLE_DIR = "/run/serverdeck/consoles"
CONSOLE_BUFFER_LIMIT = 2 * 1024 * 1024
CONSOLE_MAX_WRITE = 262144
CONSOLE_IDLE_SECONDS = 1800
CONSOLE_LINGER_SECONDS = 120


class HostError(Exception):
    pass


def run(cmd, timeout=20, stdin=None, cwd=None, env=None, combine=False):
    try:
        result = subprocess.run(
            cmd,
            input=stdin,
            capture_output=True,
            text=True,
            timeout=timeout,
            cwd=cwd,
            env=env or BASE_ENV,
            errors="replace",
        )
    except subprocess.TimeoutExpired:
        raise HostError(f"Command timed out after {timeout}s: {cmd[0]}")
    except FileNotFoundError:
        raise HostError(f"Command not found: {cmd[0]}")

    output = result.stdout
    if combine:
        output = (result.stdout or "") + (result.stderr or "")

    if result.returncode != 0 and not combine:
        detail = (result.stderr or result.stdout or "").strip().splitlines()
        raise HostError(detail[-1] if detail else f"{cmd[0]} exited with status {result.returncode}")

    return result.returncode, output, result.stderr or ""


def json_lines(text):
    items = []
    for line in text.splitlines():
        line = line.strip()
        if not line:
            continue
        try:
            items.append(json.loads(line))
        except json.JSONDecodeError:
            continue
    return items


def require(condition, message):
    if not condition:
        raise HostError(message)


def op_auth(payload):
    username = str(payload.get("username", ""))
    password = str(payload.get("password", ""))
    require(USERNAME_RE.match(username), "Invalid Linux username.")
    require(0 < len(password) <= 1024, "Invalid password.")

    try:
        account = pwd.getpwnam(username)
    except KeyError:
        raise HostError("Invalid username or password.")

    pamcheck = PAMCHECK if os.path.exists(PAMCHECK) else PAMTESTER
    code, _, _ = run(
        [pamcheck, "serverdeck", username, "authenticate"],
        timeout=12,
        stdin=password + "\n",
    )

    if code != 0:
        raise HostError("Invalid username or password.")

    groups = []
    for group in grp.getgrall():
        if username in group.gr_mem or group.gr_gid == account.pw_gid:
            groups.append(group.gr_name)

    return {
        "username": account.pw_name,
        "name": account.pw_gecos.split(",")[0].strip() or account.pw_name,
        "uid": account.pw_uid,
        "gid": account.pw_gid,
        "home": account.pw_dir,
        "shell": account.pw_shell,
        "groups": sorted(set(groups)),
    }


def op_process_kill(payload):
    pid = int(payload.get("pid", 0))
    sig = str(payload.get("signal", "TERM")).upper()
    require(pid > 1, "Refusing to signal PID 1 or an invalid PID.")
    require(pid != os.getpid() and pid != os.getppid(), "Refusing to signal the helper process.")
    require(sig in SIGNALS, "Unsupported signal.")
    try:
        os.kill(pid, SIGNALS[sig])
    except ProcessLookupError:
        raise HostError("Process no longer exists.")
    except PermissionError:
        raise HostError("Permission denied.")
    return {"pid": pid, "signal": sig}


def read_first_line(path):
    try:
        with open(path, "r", encoding="utf-8") as handle:
            return handle.readline().strip()
    except OSError:
        return None


def read_int_file(path):
    value = read_first_line(path)
    try:
        return int(value)
    except (TypeError, ValueError):
        return None


def read_cpu_usage_usec(path):
    try:
        with open(os.path.join(path, "cpu.stat"), "r", encoding="utf-8") as handle:
            for line in handle:
                parts = line.split()
                if len(parts) == 2 and parts[0] == "usage_usec":
                    return int(parts[1])
    except (OSError, ValueError):
        return None
    return None


def host_memory_total():
    try:
        with open("/proc/meminfo", "r", encoding="utf-8") as handle:
            for line in handle:
                if line.startswith("MemTotal:"):
                    return int(line.split()[1]) * 1024
    except (OSError, ValueError):
        pass
    return 0


def human_bytes(value):
    units = ["B", "KiB", "MiB", "GiB", "TiB", "PiB"]
    size = float(value)
    index = 0
    while size >= 1024 and index < len(units) - 1:
        size /= 1024
        index += 1
    if index == 0:
        return str(int(size)) + "B"
    return f"{size:.3f}{units[index]}"


def read_sysfs(path):
    return read_first_line(path) or ""


def sysfs_link_name(path):
    if not os.path.islink(path):
        return ""
    try:
        return os.path.basename(os.readlink(path))
    except OSError:
        return ""


def pci_display_names():
    names = {}
    try:
        _, out, _ = run(["/usr/bin/lspci", "-Dnnmm"], timeout=20)
    except HostError:
        return names

    for line in out.splitlines():
        try:
            fields = shlex.split(line)
        except ValueError:
            continue
        if len(fields) < 4:
            continue

        address = fields[0].lower()
        class_match = re.match(r"^(.*?)\s+\[([0-9a-fA-F]{4})\]$", fields[1])
        vendor_match = re.match(r"^(.*?)\s+\[([0-9a-fA-F]{4})\]$", fields[2])
        device_match = re.match(r"^(.*?)\s+\[([0-9a-fA-F]{4})\]$", fields[3])
        revision = ""
        for field in fields[4:]:
            if field.startswith("-r"):
                revision = field[2:]

        names[address] = {
            "class_name": class_match.group(1).strip() if class_match else fields[1],
            "class_id": class_match.group(2).lower() if class_match else "",
            "vendor": vendor_match.group(1).strip() if vendor_match else fields[2],
            "vendor_id": vendor_match.group(2).lower() if vendor_match else "",
            "device": device_match.group(1).strip() if device_match else fields[3],
            "device_id": device_match.group(2).lower() if device_match else "",
            "revision": revision,
        }
    return names


def pci_kind(class_id):
    if class_id.startswith("03"):
        return "gpu"
    if class_id.startswith("02"):
        return "network"
    if class_id.startswith("01"):
        return "storage"
    if class_id.startswith("0c03"):
        return "usb"
    if class_id.startswith("0403"):
        return "audio"
    if class_id.startswith("07"):
        return "serial"
    if class_id.startswith("12"):
        return "accelerator"
    return "other"


def pci_device_inventory():
    names = pci_display_names()
    raw_devices = {}

    for path in glob.glob("/sys/bus/pci/devices/*"):
        address = os.path.basename(path).lower()
        if not PCI_ADDRESS_RE.match(address):
            continue
        names.setdefault(address, {})
        raw_devices[address] = {
            "address": address,
            "path": path,
            "class_id": read_sysfs(os.path.join(path, "class")).removeprefix("0x").lower(),
            "vendor_id": read_sysfs(os.path.join(path, "vendor")).removeprefix("0x").lower(),
            "device_id": read_sysfs(os.path.join(path, "device")).removeprefix("0x").lower(),
            "subsystem_vendor_id": read_sysfs(os.path.join(path, "subsystem_vendor")).removeprefix("0x").lower(),
            "subsystem_device_id": read_sysfs(os.path.join(path, "subsystem_device")).removeprefix("0x").lower(),
            "driver": sysfs_link_name(os.path.join(path, "driver")),
            "iommu_group": sysfs_link_name(os.path.join(path, "iommu_group")),
            "boot_vga": read_sysfs(os.path.join(path, "boot_vga")) == "1",
        }

    groups = {}
    for device in raw_devices.values():
        if device["iommu_group"]:
            groups.setdefault(device["iommu_group"], []).append(device)

    devices = []
    for address, device in sorted(raw_devices.items()):
        meta = names.get(address, {})
        class_id = device["class_id"] or meta.get("class_id", "")
        kind = pci_kind(class_id)
        if not class_id.startswith(("01", "02", "03", "04", "07", "0c", "12")):
            continue

        peers = [
            peer
            for peer in groups.get(device["iommu_group"], [])
            if peer["address"] != address and peer["driver"] and peer["driver"] not in {"vfio-pci", "pci-stub"}
        ]
        warnings = []
        if not device["iommu_group"]:
            warnings.append("This device is not in an IOMMU group; PCI pass-through is unavailable.")
        if device["boot_vga"]:
            warnings.append("This is the boot display adapter. Passing it through can break the host console.")
        if device["driver"] and device["driver"] not in {"vfio-pci", "pci-stub"}:
            warnings.append(f"Host driver {device['driver']} is bound and will be detached from the host.")
        if peers:
            details = ", ".join(f"{peer['address']} ({peer['driver']})" for peer in peers[:3])
            warnings.append(f"IOMMU group {device['iommu_group']} also contains {details}; the group is not isolated.")
        if device["iommu_group"] and not peers and not device["driver"]:
            warnings.append(f"IOMMU group {device['iommu_group']} is isolated and available.")

        ready = bool(device["iommu_group"]) and not device["boot_vga"] and not device["driver"] and not peers

        devices.append(
            {
                **device,
                "class_name": meta.get("class_name", "PCI device"),
                "vendor": meta.get("vendor", device["vendor_id"]),
                "device": meta.get("device", device["device_id"]),
                "subsystem_vendor_id": device["subsystem_vendor_id"] or meta.get("subsystem_vendor_id", ""),
                "subsystem_device_id": device["subsystem_device_id"] or meta.get("subsystem_device_id", ""),
                "revision": meta.get("revision", ""),
                "kind": kind,
                "ready": ready,
                "warnings": warnings,
                "used_by": [],
            }
        )

    return devices


def usb_device_inventory():
    devices = []
    for path in sorted(glob.glob("/sys/bus/usb/devices/*")):
        name = os.path.basename(path)
        if name.startswith("usb"):
            continue
        vendor_id = read_sysfs(os.path.join(path, "idVendor")).lower()
        product_id = read_sysfs(os.path.join(path, "idProduct")).lower()
        if not USB_ID_RE.match(vendor_id) or not USB_ID_RE.match(product_id):
            continue

        bus = read_int_file(os.path.join(path, "busnum"))
        number = read_int_file(os.path.join(path, "devnum"))
        device_class = read_sysfs(os.path.join(path, "bDeviceClass"))
        interfaces = []
        for interface in glob.glob(path + ":*"):
            driver = sysfs_link_name(os.path.join(interface, "driver"))
            interfaces.append(
                {
                    "name": os.path.basename(interface),
                    "driver": driver,
                }
            )

        driver = next((item["driver"] for item in interfaces if item["driver"]), "")
        is_hub = device_class == "09"
        warnings = []
        if is_hub:
            warnings.append("Passing a hub also passes every downstream USB device.")
        if driver == "usbhid":
            warnings.append("This is an input device. Passing it through may affect local keyboard or mouse input.")

        devices.append(
            {
                "bus": bus,
                "device": number,
                "port": name,
                "vendor_id": vendor_id,
                "product_id": product_id,
                "vendor": read_sysfs(os.path.join(path, "manufacturer")),
                "product": read_sysfs(os.path.join(path, "product")),
                "serial": read_sysfs(os.path.join(path, "serial")),
                "speed": read_sysfs(os.path.join(path, "speed")),
                "driver": driver,
                "interfaces": interfaces,
                "is_hub": is_hub,
                "devnode": f"/dev/bus/usb/{bus:03d}/{number:03d}" if bus and number else "",
                "ready": True,
                "warnings": warnings,
                "used_by": [],
            }
        )

    return devices


UNIX_DEVICE_PATTERNS = [
    ("drm", "/dev/dri/card*"),
    ("drm", "/dev/dri/renderD*"),
    ("gpu", "/dev/nvidia*"),
    ("input", "/dev/input/event*"),
    ("input", "/dev/input/js*"),
    ("hidraw", "/dev/hidraw*"),
    ("tty", "/dev/ttyUSB*"),
    ("tty", "/dev/ttyACM*"),
    ("tty", "/dev/ttyS*"),
    ("video", "/dev/video*"),
    ("sound", "/dev/snd/*"),
    ("kvm", "/dev/kvm"),
    ("network", "/dev/net/tun"),
    ("fuse", "/dev/fuse"),
    ("vfio", "/dev/vfio/*"),
    ("vhost", "/dev/vhost-*"),
    ("i2c", "/dev/i2c-*"),
    ("gpio", "/dev/gpiochip*"),
    ("spi", "/dev/spidev*"),
    ("input", "/dev/uinput"),
    ("other", "/dev/rfkill"),
    ("other", "/dev/uhid"),
    ("other", "/dev/loop-control"),
]


def unix_device_inventory():
    devices = []
    seen = set()
    for kind, pattern in UNIX_DEVICE_PATTERNS:
        for path in sorted(glob.glob(pattern)):
            try:
                real_path = os.path.realpath(path)
                status = os.stat(real_path)
            except OSError:
                continue
            if real_path in seen or real_path in HOST_DEVICE_DENY:
                continue
            if not (stat.S_ISCHR(status.st_mode) or stat.S_ISBLK(status.st_mode)):
                continue
            seen.add(real_path)
            warnings = []
            if kind == "gpu":
                warnings.append("NVIDIA device nodes are only useful with a working driver and matching userspace libraries.")
            if kind == "input":
                warnings.append("Input devices can expose keystrokes or control local input.")
            if kind == "kvm":
                warnings.append("KVM access lets the instance create and run virtual machines.")

            try:
                group = grp.getgrgid(status.st_gid).gr_name
            except KeyError:
                group = str(status.st_gid)

            devices.append(
                {
                    "path": path,
                    "real_path": real_path,
                    "name": os.path.basename(path),
                    "kind": kind,
                    "type": "char" if stat.S_ISCHR(status.st_mode) else "block",
                    "major": os.major(status.st_rdev),
                    "minor": os.minor(status.st_rdev),
                    "mode": oct(status.st_mode & 0o777),
                    "uid": status.st_uid,
                    "gid": status.st_gid,
                    "group": group,
                    "ready": True,
                    "warnings": warnings,
                    "used_by": [],
                }
            )

    return devices


def block_device_inventory():
    _, out, _ = run(
        ["/usr/bin/lsblk", "-J", "-b", "-o", "NAME,PATH,TYPE,SIZE,MODEL,SERIAL,ROTA,TRAN,MOUNTPOINTS"],
        timeout=20,
    )
    try:
        tree = json.loads(out)
    except json.JSONDecodeError:
        raise HostError("lsblk returned unreadable disk data.")

    zfs_members = smart_zfs_map()
    devices = []

    def collect(node, mounts, paths):
        for mount in node.get("mountpoints") or []:
            if mount:
                mounts.append(str(mount))
        path = str(node.get("path") or "")
        if path:
            paths.append(path)
        for child in node.get("children") or []:
            collect(child, mounts, paths)

    def walk(node):
        if node.get("type") != "disk":
            for child in node.get("children") or []:
                walk(child)
            return

        name = str(node.get("name") or "")
        if not name or name.startswith(("loop", "ram", "zram", "sr", "zd")):
            return
        mounts, paths = [], []
        collect(node, mounts, paths)
        pools = sorted(
            pool
            for pool, members in zfs_members.items()
            if any(os.path.basename(member) == name for member in members)
        )
        root_disk = any(mount in {"/", "/boot", "/boot/efi"} for mount in mounts)
        warnings = []
        if root_disk:
            warnings.append("This disk backs the host root filesystem and cannot be passed through.")
        if pools:
            warnings.append(f"This disk is a member of ZFS pool {', '.join(pools)}.")
        if mounts:
            warnings.append(f"Host mount points: {', '.join(sorted(set(mounts)))}.")

        devices.append(
            {
                "path": f"/dev/{name}",
                "name": name,
                "size": int(node.get("size") or 0) or None,
                "model": str(node.get("model") or "").strip(),
                "serial": str(node.get("serial") or "").strip(),
                "rotational": bool(node.get("rota")),
                "transport": str(node.get("tran") or "").strip(),
                "mounts": sorted(set(mounts)),
                "zfs_pools": pools,
                "root_disk": root_disk,
                "ready": not root_disk and not pools,
                "warnings": warnings,
                "used_by": [],
            }
        )

    for node in tree.get("blockdevices", []):
        walk(node)

    return devices


def nvidia_device_inventory():
    try:
        _, out, _ = run(
            [
                "/usr/bin/nvidia-smi",
                "--query-gpu=index,pci.bus_id,uuid,name,driver_version,memory.total",
                "--format=csv,noheader,nounits",
            ],
            timeout=8,
        )
    except HostError as error:
        return {}, str(error)

    devices = {}
    for line in out.splitlines():
        fields = [field.strip() for field in line.split(",")]
        if len(fields) < 6:
            continue
        bus_id = fields[1].lower()
        devices[bus_id] = {
            "index": fields[0],
            "uuid": fields[2],
            "name": fields[3],
            "driver_version": fields[4],
            "memory_total": fields[5],
        }
    return devices, None


def docker_runtime_info():
    try:
        _, out, _ = run(["/usr/bin/docker", "info", "--format", "{{json .}}"], timeout=20)
        info = json.loads(out)
    except (HostError, json.JSONDecodeError) as error:
        return {
            "available": False,
            "error": str(error),
            "runtimes": [],
            "nvidia_runtime": False,
            "swarm": "unknown",
        }

    runtimes = sorted(str(name) for name in (info.get("Runtimes") or {}))
    return {
        "available": bool(info.get("ServerVersion")),
        "error": None,
        "runtimes": runtimes,
        "nvidia_runtime": any("nvidia" in runtime.lower() for runtime in runtimes),
        "swarm": str(((info.get("Swarm") or {}).get("LocalNodeState")) or "inactive"),
    }


def incus_instance_usage():
    try:
        _, out, _ = run(["/usr/bin/incus", "query", "/1.0/instances?recursion=1"], timeout=25)
        instances = json.loads(out)
    except (HostError, json.JSONDecodeError):
        return []

    usage = []
    for instance in instances if isinstance(instances, list) else []:
        name = str(instance.get("name") or "")
        for device_name, device in (instance.get("expanded_devices") or {}).items():
            kind = str(device.get("type") or "")
            if kind not in PASSTHROUGH_DEVICE_TYPES:
                continue
            entry = {
                "runtime": "incus",
                "owner": name,
                "device": str(device_name),
                "type": kind,
            }
            if kind in {"unix-char", "unix-block"}:
                entry["source"] = str(device.get("source") or device.get("path") or "")
            elif kind == "usb":
                entry["vendor_id"] = str(device.get("vendorid") or "").lower()
                entry["product_id"] = str(device.get("productid") or "").lower()
                entry["serial"] = str(device.get("serial") or "")
                entry["bus"] = device.get("busnum")
                entry["device_number"] = device.get("devnum")
            elif kind == "gpu":
                entry["source"] = str(device.get("pci") or device.get("id") or "")
                entry["gputype"] = str(device.get("gputype") or "physical")
            elif kind == "pci":
                entry["source"] = str(device.get("address") or "")
            usage.append(entry)
    return usage


def docker_device_usage():
    try:
        _, out, _ = run(["/usr/bin/docker", "ps", "-aq"], timeout=20)
    except HostError:
        return []

    ids = [line.strip() for line in out.splitlines() if line.strip()]
    if not ids:
        return []

    try:
        _, inspect_out, _ = run(["/usr/bin/docker", "inspect", *ids], timeout=35)
        containers = json.loads(inspect_out)
    except (HostError, json.JSONDecodeError):
        return []

    usage = []
    for container in containers if isinstance(containers, list) else []:
        name = str(container.get("Name") or "").lstrip("/")
        host_config = container.get("HostConfig") or {}
        for device in host_config.get("Devices") or []:
            usage.append(
                {
                    "runtime": "docker",
                    "owner": name,
                    "device": str(device.get("PathInContainer") or ""),
                    "type": "unix-device",
                    "source": str(device.get("PathOnHost") or ""),
                }
            )
        for request in host_config.get("DeviceRequests") or []:
            capabilities = request.get("Capabilities") or []
            if any("gpu" in (capability or []) for capability in capabilities):
                usage.append(
                    {
                        "runtime": "docker",
                        "owner": name,
                        "device": "gpu",
                        "type": "gpu",
                        "source": "*",
                    }
                )
    return usage


def attach_device_usage(devices, usage):
    for device in devices:
        matches = []
        for item in usage:
            matched = False
            if item["type"] in {"pci"} and item.get("source", "").lower() == device.get("address"):
                matched = True
            elif item["type"] == "gpu" and device.get("address"):
                if item.get("source") == "*" or item.get("source", "").lower() == device["address"]:
                    matched = True
            elif item["type"] in {"unix-char", "unix-block", "unix-device"}:
                if item.get("source") == device.get("path"):
                    matched = True
            elif item["type"] == "usb" and device.get("vendor_id"):
                if item.get("vendor_id") and item.get("product_id"):
                    if item["vendor_id"] == device["vendor_id"] and item["product_id"] == device["product_id"]:
                        if item.get("bus") and item.get("device_number"):
                            matched = item["bus"] == device.get("bus") and item["device_number"] == device.get("device")
                        elif item.get("serial"):
                            matched = item["serial"] == device.get("serial")
                        else:
                            matched = True
            if matched:
                matches.append(
                    {
                        "runtime": item["runtime"],
                        "owner": item["owner"],
                        "device": item["device"],
                        "type": item["type"],
                    }
                )
        if matches:
            device["used_by"] = matches
    return devices


def op_device_inventory(payload):
    pci = pci_device_inventory()
    usb = usb_device_inventory()
    unix = unix_device_inventory()
    block = block_device_inventory()
    usage = incus_instance_usage() + docker_device_usage()
    attach_device_usage(pci, usage)
    attach_device_usage(usb, usage)
    attach_device_usage(unix, usage)
    attach_device_usage(block, usage)

    nvidia, nvidia_error = nvidia_device_inventory()
    gpus = []
    for device in pci:
        if device["kind"] != "gpu":
            continue

        drm_nodes = []
        for node in glob.glob("/sys/class/drm/*/device"):
            try:
                target = os.path.realpath(node)
            except OSError:
                continue
            if device["address"] not in target.lower():
                continue
            name = os.path.basename(os.path.dirname(node))
            path = f"/dev/dri/{name}"
            if os.path.exists(path) and path not in drm_nodes:
                drm_nodes.append(path)

        warnings = list(device["warnings"])
        smi = nvidia.get(device["address"], None)
        if device["vendor_id"] == "10de" and smi is None and nvidia_error:
            warnings.append(f"NVIDIA driver reports no usable devices: {nvidia_error}")

        gpus.append(
            {
                "address": device["address"],
                "name": f"{device['vendor']} {device['device']}".strip(),
                "vendor_id": device["vendor_id"],
                "device_id": device["device_id"],
                "vendor": device["vendor"],
                "device": device["device"],
                "driver": device["driver"],
                "iommu_group": device["iommu_group"],
                "drm_nodes": drm_nodes,
                "nvidia": smi,
                "ready": device["ready"],
                "warnings": warnings,
                "used_by": device["used_by"],
            }
        )

    iommu_enabled = bool(glob.glob("/sys/kernel/iommu_groups/*"))
    return {
        "scanned_at": int(time.time()),
        "iommu": {
            "enabled": iommu_enabled,
            "groups": len(glob.glob("/sys/kernel/iommu_groups/*")),
        },
        "pci": pci,
        "usb": usb,
        "unix": unix,
        "block": block,
        "gpus": gpus,
        "nvidia": {
            "available": nvidia_error is None and bool(nvidia),
            "error": nvidia_error,
            "devices": list(nvidia.values()),
        },
        "docker": docker_runtime_info(),
        "counts": {
            "pci": len(pci),
            "usb": len(usb),
            "unix": len(unix),
            "block": len(block),
            "gpus": len(gpus),
        },
    }


def docker_cgroup_stats(containers):
    running = []
    for container in containers:
        if str(container.get("State", "")).lower() != "running":
            continue
        container_id = str(container.get("ID", ""))
        if not re.fullmatch(r"[a-f0-9]{64}", container_id):
            return None
        path = os.path.join("/sys/fs/cgroup/system.slice", "docker-" + container_id + ".scope")
        if not os.path.isdir(path):
            return None
        usage = read_cpu_usage_usec(path)
        memory = read_int_file(os.path.join(path, "memory.current"))
        limit = read_first_line(os.path.join(path, "memory.max"))
        pids = read_int_file(os.path.join(path, "pids.current"))
        if usage is None or memory is None or limit is None or pids is None:
            return None
        if limit == "max":
            limit_value = host_memory_total()
        else:
            try:
                limit_value = int(limit)
            except ValueError:
                return None
        if limit_value <= 0:
            return None
        running.append(
            {
                "id": container_id,
                "path": path,
                "usage": usage,
                "memory": memory,
                "limit": limit_value,
                "pids": pids,
            }
        )

    if not running:
        return {}

    started = time.time()
    time.sleep(0.1)
    elapsed = max(0.05, time.time() - started)

    stats = {}
    for item in running:
        usage = read_cpu_usage_usec(item["path"])
        if usage is None:
            return None
        cpu_percent = max(0.0, (usage - item["usage"]) / (elapsed * 1_000_000) * 100)
        memory_percent = max(0.0, item["memory"] / item["limit"] * 100)
        stats[item["id"]] = {
            "CPUPerc": f"{cpu_percent:.2f}%",
            "MemUsage": human_bytes(item["memory"]) + " / " + human_bytes(item["limit"]),
            "MemPerc": f"{memory_percent:.2f}%",
            "NetIO": None,
            "BlockIO": None,
            "PIDs": str(item["pids"]),
        }
    return stats


def is_gpu_device_request(request):
    if not isinstance(request, dict):
        return False
    for capability in request.get("Capabilities") or []:
        if any("gpu" in str(item).lower() for item in (capability or [])):
            return True
    return False


def op_docker_list(payload):
    _, out, _ = run(["/usr/bin/docker", "ps", "-a", "--no-trunc", "--format", "json"], timeout=25)
    containers = json_lines(out)
    with_stats = payload.get("stats", True) is not False

    stats = {}
    if with_stats and any(str(c.get("State", "")).lower() == "running" for c in containers):
        stats = docker_cgroup_stats(containers)
        if stats is None:
            try:
                _, stat_out, _ = run(
                    ["/usr/bin/docker", "stats", "--no-stream", "--no-trunc", "--format", "{{json .}}"],
                    timeout=25,
                )
                for row in json_lines(stat_out):
                    stats[str(row.get("ID", ""))] = row
                    stats[str(row.get("Name", ""))] = row
            except HostError:
                stats = {}

    for container in containers:
        key = str(container.get("ID", ""))
        stat = stats.get(key) or stats.get(str(container.get("Names", ""))) or {}
        container["stats"] = {
            "cpu": stat.get("CPUPerc"),
            "memory": stat.get("MemUsage"),
            "memory_percent": stat.get("MemPerc"),
            "network": stat.get("NetIO"),
            "block": stat.get("BlockIO"),
            "pids": stat.get("PIDs"),
        }

    inspected = {}
    ids = [str(container.get("ID", "")) for container in containers if container.get("ID")]
    if ids:
        try:
            _, inspect_out, _ = run(["/usr/bin/docker", "inspect", *ids], timeout=45)
            for detail in json.loads(inspect_out):
                inspected[str(detail.get("Id", ""))] = detail
        except (HostError, json.JSONDecodeError):
            inspected = {}

    for container in containers:
        detail = inspected.get(str(container.get("ID", "")), {})
        config = detail.get("Config") or {}
        labels = config.get("Labels") or {}
        host_config = detail.get("HostConfig") or {}
        container["swarm"] = any(str(name).startswith("com.docker.swarm.") for name in labels)
        container["devices"] = [
            {
                "host": str(device.get("PathOnHost", "")),
                "container": str(device.get("PathInContainer", "")),
                "permissions": str(device.get("CgroupPermissions", "rwm")),
            }
            for device in (host_config.get("Devices") or [])
        ]
        container["gpus"] = any(
            is_gpu_device_request(request) for request in (host_config.get("DeviceRequests") or [])
        )

    info = {}
    try:
        _, info_out, _ = run(
            ["/usr/bin/docker", "info", "--format", "{{json .}}"],
            timeout=15,
        )
        raw = json.loads(info_out)
        info = {
            "version": raw.get("ServerVersion"),
            "containers": raw.get("Containers"),
            "running": raw.get("ContainersRunning"),
            "paused": raw.get("ContainersPaused"),
            "stopped": raw.get("ContainersStopped"),
            "images": raw.get("Images"),
            "driver": raw.get("Driver"),
            "storage": (raw.get("DriverStatus") or [[None, None]])[0][1],
            "cgroup": raw.get("CgroupDriver"),
            "kernel": raw.get("KernelVersion"),
            "os": raw.get("OperatingSystem"),
            "cpus": raw.get("NCPU"),
            "memory": raw.get("MemTotal"),
        }
    except (HostError, json.JSONDecodeError):
        info = {}

    return {"containers": containers, "info": info}


class DockerSocketConnection(http.client.HTTPConnection):
    def __init__(self, timeout=60):
        super().__init__("localhost", timeout=timeout)

    def connect(self):
        sock = socket.socket(socket.AF_UNIX, socket.SOCK_STREAM)
        sock.settimeout(self.timeout)
        sock.connect("/var/run/docker.sock")
        self.sock = sock


def docker_api(method, path, body=None, timeout=60):
    connection = DockerSocketConnection(timeout=timeout)
    try:
        headers = {"Content-Type": "application/json"} if body is not None else {}
        connection.request(
            method,
            path,
            body=json.dumps(body) if body is not None else None,
            headers=headers,
        )
        response = connection.getresponse()
        raw = response.read()
        try:
            data = json.loads(raw.decode("utf-8")) if raw else None
        except json.JSONDecodeError:
            data = None
        if response.status >= 400:
            message = str((data or {}).get("message") or response.reason or "unknown error")
            raise HostError(f"Docker API error: {message}")
        return data
    finally:
        connection.close()


def docker_inspect_container(identifier):
    _, out, _ = run(["/usr/bin/docker", "inspect", identifier], timeout=35)
    try:
        data = json.loads(out)
    except json.JSONDecodeError:
        raise HostError("Docker returned unreadable container data.")
    require(isinstance(data, list) and len(data) == 1 and isinstance(data[0], dict), "Docker container not found.")
    return data[0]


def docker_device_entry(raw_device):
    host = str(raw_device.get("host") or raw_device.get("source") or "").strip()
    container_path = str(raw_device.get("container") or raw_device.get("path") or host).strip()
    permissions = str(raw_device.get("permissions") or "rwm").strip()

    validate_host_device_source(host, None)
    require(bool(HOST_DEVICE_PATH_RE.match(container_path)), "Invalid container device path.")
    require(".." not in container_path.split("/"), "Container device paths cannot contain '..'.")
    require(bool(re.fullmatch(r"[rwm]{1,3}", permissions)), "Device permissions must contain only r, w and m.")
    require(len(set(permissions)) == len(permissions), "Device permissions cannot repeat a mode.")

    return {
        "PathOnHost": host,
        "PathInContainer": container_path,
        "CgroupPermissions": permissions,
    }


def docker_endpoint_config(detail):
    networks = ((detail.get("NetworkSettings") or {}).get("Networks") or {})
    endpoints = {}
    for name, endpoint in networks.items():
        if not isinstance(endpoint, dict):
            continue
        ipam = endpoint.get("IPAMConfig") or {}
        if ipam.get("IPv4Address") or ipam.get("IPv6Address"):
            raise HostError("Containers with a static IP address cannot be recreated here.")
        if endpoint.get("MacAddress"):
            raise HostError("Containers with a fixed MAC address cannot be recreated here.")

        aliases = [str(alias) for alias in (endpoint.get("Aliases") or []) if alias]
        if name == "bridge":
            aliases = []

        endpoints[name] = {
            "Aliases": aliases,
            "Links": endpoint.get("Links") or [],
            "DriverOpts": endpoint.get("DriverOpts") or {},
        }
    return endpoints


def docker_preserved_mounts(detail, host_config):
    mounts = host_config.get("Mounts") or []
    covered = set()

    for bind in host_config.get("Binds") or []:
        parts = str(bind).split(":")
        if len(parts) >= 2:
            covered.add(parts[1])

    for mount in mounts:
        if isinstance(mount, dict) and mount.get("Target"):
            covered.add(str(mount["Target"]))

    for mount in detail.get("Mounts") or []:
        if not isinstance(mount, dict):
            continue
        destination = str(mount.get("Destination") or "")
        if not destination or destination in covered:
            continue
        kind = str(mount.get("Type") or "")
        read_only = not bool(mount.get("RW", True))
        entry = None

        if kind == "volume" and mount.get("Name"):
            entry = {
                "Type": "volume",
                "Source": str(mount["Name"]),
                "Target": destination,
                "ReadOnly": read_only,
            }
        elif kind == "bind" and mount.get("Source"):
            entry = {
                "Type": "bind",
                "Source": str(mount["Source"]),
                "Target": destination,
                "ReadOnly": read_only,
            }
            if mount.get("Propagation"):
                entry["BindOptions"] = {"Propagation": str(mount["Propagation"])}
        elif kind == "tmpfs":
            entry = {
                "Type": "tmpfs",
                "Target": destination,
                "ReadOnly": read_only,
            }

        if entry is not None:
            mounts.append(entry)
            covered.add(destination)

    return mounts, covered


def op_docker_device_update(payload):
    identifier = str(payload.get("container") or "")
    require(CONTAINER_RE.match(identifier), "Invalid container identifier.")

    raw_devices = payload.get("devices")
    require(isinstance(raw_devices, list), "Invalid device list.")
    require(len(raw_devices) <= 32, "Too many devices were requested.")

    devices = []
    seen = set()
    for raw_device in raw_devices:
        require(isinstance(raw_device, dict), "Invalid device entry.")
        entry = docker_device_entry(raw_device)
        require(entry["PathInContainer"] not in seen, "Each container device path can only be used once.")
        seen.add(entry["PathInContainer"])
        devices.append(entry)

    gpus = payload.get("gpus")
    if gpus is not None:
        require(isinstance(gpus, bool), "Invalid GPU selection.")

    detail = docker_inspect_container(identifier)
    name = str(detail.get("Name") or "").lstrip("/")
    old_id = str(detail.get("Id") or "")
    require(bool(name) and bool(old_id), "Docker returned an incomplete container description.")

    config = detail.get("Config") or {}
    labels = config.get("Labels") or {}
    require(
        not any(str(label).startswith("com.docker.swarm.") for label in labels),
        "Swarm-managed containers cannot have devices attached. Reconfigure the service or recreate the container as standalone.",
    )

    state = detail.get("State") or {}
    require(
        not state.get("Running") and not state.get("Paused") and not state.get("Restarting"),
        "Stop the container before changing devices.",
    )
    require(not state.get("Dead"), "Dead containers cannot be reconfigured.")

    host_config = detail.get("HostConfig") or {}
    new_config = json.loads(json.dumps(config))
    new_host_config = json.loads(json.dumps(host_config))

    new_host_config["Devices"] = devices

    current_requests = new_host_config.get("DeviceRequests") or []
    non_gpu_requests = [request for request in current_requests if not is_gpu_device_request(request)]
    gpu_requests = [request for request in current_requests if is_gpu_device_request(request)]

    if gpus is None:
        new_host_config["DeviceRequests"] = non_gpu_requests + gpu_requests
    elif gpus:
        new_host_config["DeviceRequests"] = non_gpu_requests + (gpu_requests or [{
            "Driver": "",
            "Count": -1,
            "DeviceIDs": [],
            "Capabilities": [["gpu"]],
            "Options": {},
        }])
    else:
        new_host_config["DeviceRequests"] = non_gpu_requests

    if str(new_config.get("Hostname") or "") in {old_id, old_id[:12]}:
        new_config["Hostname"] = ""

    require(
        not str(new_config.get("MacAddress") or "").strip(),
        "Containers with a fixed MAC address cannot be recreated here.",
    )

    mounts, covered = docker_preserved_mounts(detail, new_host_config)
    new_host_config["Mounts"] = mounts
    declared_volumes = new_config.get("Volumes") or {}
    for destination in covered:
        declared_volumes.pop(destination, None)
    new_config["Volumes"] = declared_volumes

    endpoints = docker_endpoint_config(detail)
    network_mode = str(new_host_config.get("NetworkMode") or "")
    require(not network_mode.startswith("container:"), "Containers sharing another container's network namespace cannot be recreated here.")
    if network_mode in {"host", "none"}:
        endpoints = {}
    elif endpoints:
        network_mode = "default"
    new_host_config["NetworkMode"] = network_mode or "default"

    backup_name = f"serverdeck-old-{old_id[:12]}-{int(time.time()) % 100000}"
    run(["/usr/bin/docker", "rename", name, backup_name], timeout=30)

    body = dict(new_config)
    body["HostConfig"] = new_host_config
    if endpoints:
        body["NetworkingConfig"] = {"EndpointsConfig": endpoints}

    try:
        created = docker_api(
            "POST",
            "/containers/create?name=" + urllib.parse.quote(name, safe=""),
            body,
            timeout=90,
        )
    except Exception:
        try:
            run(["/usr/bin/docker", "rename", backup_name, name], timeout=30)
        except HostError:
            pass
        raise

    new_id = str((created or {}).get("Id") or "")
    require(new_id, "Docker did not return a new container ID.")

    cleanup_error = None
    try:
        run(["/usr/bin/docker", "rm", backup_name], timeout=60)
    except HostError as error:
        cleanup_error = str(error)

    return {
        "container": name,
        "id": new_id,
        "devices": devices,
        "gpus": bool(new_host_config.get("DeviceRequests")),
        "previous": None if cleanup_error is None else backup_name,
        "cleanup_error": cleanup_error,
    }


def op_docker_action(payload):
    action = str(payload.get("action", ""))
    container = str(payload.get("container", ""))
    require(action in {"start", "stop", "restart", "pause", "unpause", "remove"}, "Unsupported container action.")
    require(CONTAINER_RE.match(container), "Invalid container identifier.")
    if action == "remove":
        run(["/usr/bin/docker", "rm", container], timeout=30)
    else:
        run(["/usr/bin/docker", action, container], timeout=60)
    return {"container": container, "action": action}


def op_docker_logs(payload):
    container = str(payload.get("container", ""))
    require(CONTAINER_RE.match(container), "Invalid container identifier.")
    tail = max(1, min(2000, int(payload.get("tail", 250))))
    _, out, _ = run(
        ["/usr/bin/docker", "logs", "--tail", str(tail), "--timestamps", container],
        timeout=25,
        combine=True,
    )
    return {"container": container, "output": out[-200_000:]}


def op_docker_images(payload):
    _, out, _ = run(["/usr/bin/docker", "images", "--format", "{{json .}}"], timeout=20)
    return {"images": json_lines(out)}


def op_docker_networks(payload):
    _, out, _ = run(["/usr/bin/docker", "network", "ls", "--format", "json"], timeout=20)
    basic = json_lines(out)
    ids = [str(item.get("ID", "")) for item in basic if item.get("ID")]
    details = {}
    if ids:
        _, inspect_out, _ = run(
            ["/usr/bin/docker", "network", "inspect", *ids, "--format", "{{json .}}"],
            timeout=20,
        )
        for row in json_lines(inspect_out):
            details[str(row.get("Name", ""))] = row

    networks = []
    for item in basic:
        detail = details.get(str(item.get("Name", "")), {})
        containers = sorted(
            (
                {
                    "name": str(container.get("Name", "")),
                    "ipv4": str(container.get("IPv4Address", "")),
                    "ipv6": str(container.get("IPv6Address", "")),
                }
                for container in (detail.get("Containers") or {}).values()
            ),
            key=lambda container: container["name"],
        )
        subnets = [
            {"subnet": str(config.get("Subnet", "")), "gateway": str(config.get("Gateway", ""))}
            for config in ((detail.get("IPAM") or {}).get("Config") or [])
        ]
        networks.append(
            {
                "id": str(item.get("ID", "")),
                "name": str(item.get("Name", "")),
                "driver": str(item.get("Driver", "")),
                "scope": str(item.get("Scope", "")),
                "internal": str(item.get("Internal", "")).lower() == "true",
                "ipv6": str(item.get("IPv6", "")).lower() == "true",
                "created_at": str(item.get("CreatedAt", "")),
                "subnets": subnets,
                "containers": containers,
            }
        )

    networks.sort(key=lambda network: network["name"].lower())
    return {"networks": networks}


def op_incus_list(payload):
    _, out, _ = run(["/usr/bin/incus", "list", "--format", "json"], timeout=25)
    try:
        instances = json.loads(out)
    except json.JSONDecodeError:
        raise HostError("Incus returned an unreadable response.")
    return {"instances": instances}


def op_incus_images(payload):
    _, out, _ = run(["/usr/bin/incus", "image", "list", "--format", "json"], timeout=25)
    try:
        raw_images = json.loads(out)
    except json.JSONDecodeError:
        raise HostError("Incus returned an unreadable image list.")

    host_arch = os.uname().machine
    images = []
    for image in raw_images:
        kind = str(image.get("type", ""))
        if kind not in {"container", "virtual-machine"}:
            continue
        if str(image.get("architecture", "")) not in {host_arch, "x86_64", "aarch64"}:
            continue
        properties = image.get("properties") or {}
        images.append(
            {
                "fingerprint": str(image.get("fingerprint", "")),
                "type": kind,
                "os": str(properties.get("os", "")),
                "release": str(properties.get("release", "")),
                "variant": str(properties.get("variant", "")),
                "description": str(properties.get("description", "")),
                "architecture": str(image.get("architecture", "")),
                "size": int(image.get("size") or 0),
                "aliases": [
                    str(alias.get("name", ""))
                    for alias in (image.get("aliases") or [])
                    if alias.get("name")
                ],
            }
        )

    images.sort(key=lambda item: (item["type"], item["os"].lower(), item["release"].lower(), item["description"].lower()))
    return {"images": images}


def op_incus_remote_images(payload):
    remote = str(payload.get("remote", "images"))
    require(re.fullmatch(r"[a-z][a-z0-9_-]{0,31}", remote) is not None, "Invalid image remote.")

    _, out, _ = run(["/usr/bin/incus", "image", "list", f"{remote}:", "--format", "json"], timeout=30)
    try:
        raw_images = json.loads(out)
    except json.JSONDecodeError:
        raise HostError("Incus returned an unreadable remote image list.")

    host_arch = os.uname().machine
    images = []
    for image in raw_images:
        kind = str(image.get("type", ""))
        if kind not in {"container", "virtual-machine"}:
            continue
        if str(image.get("architecture", "")) != host_arch:
            continue
        aliases = [str(a.get("name", "")) for a in (image.get("aliases") or []) if a.get("name")]
        if not aliases:
            continue
        properties = image.get("properties") or {}
        images.append(
            {
                "fingerprint": str(image.get("fingerprint", "")),
                "type": kind,
                "os": str(properties.get("os", "")),
                "release": str(properties.get("release", "")),
                "variant": str(properties.get("variant", "")),
                "description": str(properties.get("description", "")),
                "architecture": str(image.get("architecture", "")),
                "size": int(image.get("size") or 0),
                "aliases": aliases,
            }
        )

    return {"remote": remote, "images": images}


def op_incus_networks(payload):
    _, out, _ = run(["/usr/bin/incus", "network", "list", "--format", "json"], timeout=25)
    try:
        networks = json.loads(out)
    except json.JSONDecodeError:
        raise HostError("Incus returned an unreadable network list.")
    return {"networks": networks}


def op_incus_create(payload):
    name = str(payload.get("name", ""))
    image = str(payload.get("image", ""))
    kind = str(payload.get("type", "container"))
    start = payload.get("start", True)

    require(CONTAINER_RE.match(name), "Invalid instance name.")
    require(kind in {"container", "virtual-machine"}, "Unsupported instance type.")
    require(IMAGE_RE.match(image), "Invalid image reference.")
    require(isinstance(start, bool), "Invalid start option.")

    cmd = ["/usr/bin/incus", "launch" if start else "create", image, name]
    if kind == "virtual-machine":
        cmd.append("--vm")

    run(cmd, timeout=600)
    return {"name": name, "type": kind, "image": image, "started": start}


def op_incus_action(payload):
    action = str(payload.get("action", ""))
    name = str(payload.get("name", ""))
    require(
        action in {"start", "stop", "restart", "freeze", "unfreeze", "force-stop"},
        "Unsupported instance action.",
    )
    require(CONTAINER_RE.match(name), "Invalid instance name.")

    if action == "force-stop":
        run(["/usr/bin/incus", "stop", "--force", name], timeout=30)
    elif action == "stop":
        run(["/usr/bin/incus", "stop", "--timeout", "40", name], timeout=60)
    elif action == "restart":
        run(["/usr/bin/incus", "restart", "--timeout", "40", name], timeout=90)
    elif action == "start":
        run(["/usr/bin/incus", "start", name], timeout=120)
    else:
        run(["/usr/bin/incus", action, name], timeout=30)

    return {"name": name, "action": action}


def op_incus_logs(payload):
    name = str(payload.get("name", ""))
    require(CONTAINER_RE.match(name), "Invalid instance name.")
    _, out, _ = run(["/usr/bin/incus", "info", "--show-log", name], timeout=25, combine=True)
    return {"name": name, "output": out[-200_000:]}


def op_incus_delete(payload):
    name = str(payload.get("name", ""))
    require(CONTAINER_RE.match(name), "Invalid instance name.")
    run(["/usr/bin/incus", "delete", name], timeout=120)
    return {"name": name}


def incus_instance_json(name):
    path = f"/1.0/instances/{name}"
    _, out, _ = run(["/usr/bin/incus", "query", path], timeout=25)
    try:
        instance = json.loads(out)
    except json.JSONDecodeError:
        raise HostError("Incus returned an unreadable instance response.")
    require(isinstance(instance, dict), "Incus returned an unexpected instance response.")
    return instance


def incus_storage_pools():
    _, out, _ = run(["/usr/bin/incus", "storage", "list", "--format", "json"], timeout=20)
    try:
        pools = json.loads(out)
    except json.JSONDecodeError:
        raise HostError("Incus returned an unreadable storage pool list.")
    return [
        {
            "name": str(pool.get("name", "")),
            "driver": str(pool.get("driver", "")),
            "description": str(pool.get("description", "")),
        }
        for pool in pools
        if pool.get("name")
    ]


def incus_custom_volumes():
    volumes = []
    for pool in incus_storage_pools():
        try:
            _, out, _ = run(
                ["/usr/bin/incus", "storage", "volume", "list", pool["name"], "--format", "json"],
                timeout=20,
            )
        except HostError:
            continue
        try:
            raw = json.loads(out)
        except json.JSONDecodeError:
            continue
        for volume in raw:
            if volume.get("type") != "custom":
                continue
            config = volume.get("config") or {}
            volumes.append(
                {
                    "pool": pool["name"],
                    "name": str(volume.get("name", "")),
                    "content_type": str(volume.get("content_type", "")),
                    "size": str(config.get("size", "")),
                    "used_by": len(volume.get("used_by") or []),
                }
            )
    return volumes


def incus_networks():
    _, out, _ = run(["/usr/bin/incus", "network", "list", "--format", "json"], timeout=20)
    try:
        networks = json.loads(out)
    except json.JSONDecodeError:
        raise HostError("Incus returned an unreadable network list.")
    return [
        {
            "name": str(network.get("name", "")),
            "type": str(network.get("type", "")),
            "managed": bool(network.get("managed", False)),
            "description": str(network.get("description", "")),
            "ipv4_address": str((network.get("config") or {}).get("ipv4.address", "")),
            "ipv6_address": str((network.get("config") or {}).get("ipv6.address", "")),
        }
        for network in networks
        if network.get("name")
    ]


def is_selectable_bridge(name):
    name = str(name or "")
    return (
        bool(name)
        and name != "lo"
        and not name.startswith("docker")
        and not name.startswith("veth")
        and re.fullmatch(r"br-[0-9a-f]{6,}", name) is None
    )


def parse_size_bytes(value):
    match = re.fullmatch(r"(\d{1,5}(?:\.\d{1,2})?)(B|kB|MB|GB|TB|KiB|MiB|GiB|TiB)", str(value or "").strip())
    if not match:
        return None
    amount = float(match.group(1))
    unit = match.group(2)
    factors = {
        "B": 1,
        "kB": 1000,
        "MB": 1000 ** 2,
        "GB": 1000 ** 3,
        "TB": 1000 ** 4,
        "KiB": 1024,
        "MiB": 1024 ** 2,
        "GiB": 1024 ** 3,
        "TiB": 1024 ** 4,
    }
    return int(amount * factors[unit])


def op_incus_detail(payload):
    name = str(payload.get("name", ""))
    require(INSTANCE_NAME_RE.match(name), "Invalid instance name.")

    instance = incus_instance_json(name)
    config = instance.get("config") or {}
    devices = instance.get("devices") or {}
    expanded_devices = instance.get("expanded_devices") or {}
    editable = {
        key: str(config[key])
        for key in INSTANCE_CONFIG_KEYS
        if key in config
    }

    return {
        "name": name,
        "type": str(instance.get("type", "container")),
        "status": str(instance.get("status", "unknown")).lower(),
        "architecture": str(instance.get("architecture", "")),
        "description": str(instance.get("description", "")),
        "profiles": instance.get("profiles") or [],
        "image": str(config.get("image.description") or config.get("image.os") or ""),
        "config": editable,
        "devices": devices,
        "expanded_devices": expanded_devices,
        "root_size": str((expanded_devices.get("root") or {}).get("size", "")),
        "networks": incus_networks(),
        "bridges": [
            {
                "name": network["name"],
                "description": network["description"],
                "ipv4_address": network["ipv4_address"],
            }
            for network in incus_networks()
            if network["type"] == "bridge" and not network["managed"] and is_selectable_bridge(network["name"])
        ],
        "storage_pools": incus_storage_pools(),
        "storage_volumes": incus_custom_volumes(),
    }


def op_incus_config_update(payload):
    name = str(payload.get("name", ""))
    require(INSTANCE_NAME_RE.match(name), "Invalid instance name.")
    updates = payload.get("config") or {}
    require(isinstance(updates, dict), "Invalid configuration payload.")

    instance = incus_instance_json(name)
    current = instance.get("config") or {}
    changed = []

    for key, raw_value in updates.items():
        require(key in INSTANCE_CONFIG_KEYS, f"Unsupported configuration key: {key}")
        value = "" if raw_value is None else str(raw_value).strip()

        if key == "limits.cpu":
            require(value == "" or bool(CPU_LIMIT_RE.match(value)), "Invalid CPU limit.")
        elif key == "limits.memory":
            require(value == "" or bool(MEMORY_LIMIT_RE.match(value)), "Invalid memory limit.")
            if value.endswith("%"):
                require(1 <= int(float(value[:-1])) <= 100, "Memory percentage must be between 1 and 100.")
        elif key == "limits.processes":
            require(value == "" or (value.isdigit() and 1 <= int(value) <= 100000), "Invalid process limit.")
        elif key in {"boot.autostart", "security.nesting"}:
            require(value in {"", "true", "false"}, f"Invalid value for {key}.")

        if value == "":
            if key in current:
                run(["/usr/bin/incus", "config", "unset", name, key], timeout=20)
        else:
            run(["/usr/bin/incus", "config", "set", name, f"{key}={value}"], timeout=20)
        changed.append(key)

    if "description" in payload:
        description = str(payload.get("description") or "").strip()
        require(
            len(description) <= 255 and re.fullmatch(r"[^\x00-\x1f\x7f]*", description) is not None,
            "Invalid description.",
        )
        run(
            [
                "/usr/bin/incus", "query", "-X", "PATCH",
                "--data", json.dumps({"description": description}),
                f"/1.0/instances/{name}",
            ],
            timeout=20,
        )
        changed.append("description")

    return {"name": name, "changed": changed}


def validate_mount_path(raw_path):
    path = str(raw_path or "").strip()
    require(path.startswith("/") and len(path) <= 255, "Invalid mount path.")
    require(".." not in path.split("/"), "Mount paths cannot contain '..'.")
    require(bool(re.fullmatch(r"/[A-Za-z0-9._/@+ -]{1,254}", path)), "Invalid mount path characters.")
    return path.rstrip("/") or "/"


def validate_host_device_source(raw_source, expected_kind):
    source = str(raw_source or "").strip()
    require(source.startswith("/dev/") and len(source) <= 180, "Invalid host device path.")
    require(".." not in source.split("/"), "Host device paths cannot contain '..'.")
    require(source not in HOST_DEVICE_DENY, "That host device cannot be passed through.")
    require(os.path.exists(source), f"Host device not found: {source}")

    real_path = os.path.realpath(source)
    require(real_path not in HOST_DEVICE_DENY, "That host device cannot be passed through.")
    try:
        status = os.stat(real_path)
    except OSError:
        raise HostError("Host device cannot be read.")
    is_char = stat.S_ISCHR(status.st_mode)
    is_block = stat.S_ISBLK(status.st_mode)
    require(is_char or is_block, "The selected host path is not a character or block device.")
    if expected_kind == "unix-char":
        require(is_char, "Select a character device for a unix-char device.")
    if expected_kind == "unix-block":
        require(is_block, "Select a block device for a unix-block device.")
    return source


def validate_pci_device(raw_address, expected_gpu):
    address = str(raw_address or "").strip().lower()
    require(bool(PCI_ADDRESS_RE.match(address)), "Invalid PCI address.")
    path = f"/sys/bus/pci/devices/{address}"
    require(os.path.isdir(path), f"PCI device not found: {address}")
    class_id = read_sysfs(os.path.join(path, "class")).removeprefix("0x").lower()
    require(bool(class_id), "Unable to read the PCI device class.")
    if expected_gpu:
        require(class_id.startswith("03"), "The selected PCI device is not a display/GPU device.")
    else:
        require(not class_id.startswith("06"), "PCI bridges cannot be passed through.")
    require(read_sysfs(os.path.join(path, "boot_vga")) != "1", "The boot display adapter cannot be passed through.")
    require(os.path.islink(os.path.join(path, "iommu_group")), "The PCI device is not in an IOMMU group.")
    return address


def resolve_usb_device(payload):
    devices = usb_device_inventory()
    vendor_id = str(payload.get("vendor_id") or payload.get("vendorid") or "").strip().lower()
    product_id = str(payload.get("product_id") or payload.get("productid") or "").strip().lower()
    serial = str(payload.get("serial") or "").strip()
    bus = payload.get("bus")
    device_number = payload.get("devnum") if payload.get("devnum") is not None else payload.get("device_number")

    if vendor_id or product_id:
        require(bool(USB_ID_RE.match(vendor_id)) and bool(USB_ID_RE.match(product_id)), "Invalid USB vendor or product ID.")
    if serial:
        require(len(serial) <= 128 and all(32 <= ord(char) < 127 for char in serial), "Invalid USB serial number.")
    if bus not in (None, "") or device_number not in (None, ""):
        require(str(bus).isdigit() and str(device_number).isdigit(), "Invalid USB bus or device number.")
        bus = int(bus)
        device_number = int(device_number)

    matches = []
    for item in devices:
        if bus is not None and device_number is not None:
            if item.get("bus") == bus and item.get("device") == device_number:
                matches.append(item)
            continue
        if vendor_id and item.get("vendor_id") == vendor_id and item.get("product_id") == product_id:
            if serial and item.get("serial") != serial:
                continue
            matches.append(item)

    require(len(matches) == 1, "The selected USB device is no longer present on the host." if not matches else "More than one USB device matches; select a specific bus and device.")
    device = matches[0]

    if device.get("serial"):
        return [
            f"vendorid={device['vendor_id']}",
            f"productid={device['product_id']}",
            f"serial={device['serial']}",
        ], device

    require(device.get("bus") and device.get("device"), "USB device has no stable identifier.")
    return [f"busnum={device['bus']}", f"devnum={device['device']}"], device


def op_incus_device_add(payload):
    name = str(payload.get("name", ""))
    device = str(payload.get("device", ""))
    kind = str(payload.get("type", ""))
    require(INSTANCE_NAME_RE.match(name), "Invalid instance name.")
    require(DEVICE_NAME_RE.match(device), "Invalid device name.")
    require(kind in {"nic", "disk"} | PASSTHROUGH_DEVICE_TYPES, "Unsupported device type.")

    instance = incus_instance_json(name)
    require(device not in (instance.get("devices") or {}), "A device with that name already exists.")
    instance_type = str(instance.get("type") or "container")
    instance_status = str(instance.get("status") or "").lower()

    cmd = ["/usr/bin/incus", "config", "device", "add", name, device, kind]

    if kind == "nic":
        network = str(payload.get("network") or "").strip()
        parent = str(payload.get("parent") or "").strip()
        require(bool(network) != bool(parent), "Choose either a managed network or a host bridge.")

        known_networks = incus_networks()
        if network:
            require(
                any(item["name"] == network and item["managed"] for item in known_networks),
                "Unknown managed Incus network.",
            )
            cmd.append(f"network={network}")
        else:
            require(
                any(
                    item["name"] == parent and item["type"] == "bridge" and is_selectable_bridge(item["name"])
                    for item in known_networks
                ),
                "Unknown host bridge.",
            )
            cmd.extend(["nictype=bridged", f"parent={parent}"])

        vlan = payload.get("vlan")
        if vlan not in (None, ""):
            require(bool(VLAN_RE.match(str(vlan))) and 1 <= int(vlan) <= 4094, "VLAN must be between 1 and 4094.")
            cmd.append(f"vlan={int(vlan)}")

        mtu = payload.get("mtu")
        if mtu not in (None, ""):
            require(bool(MTU_RE.match(str(mtu))) and 576 <= int(mtu) <= 65535, "MTU must be between 576 and 65535.")
            cmd.append(f"mtu={int(mtu)}")
    elif kind == "disk":
        source_type = str(payload.get("source_type") or "")
        require(source_type in {"path", "volume"}, "Choose a host path or a storage volume.")

        if source_type == "path":
            require(instance.get("type") == "container", "Bind mounts are only supported for containers.")
            source = str(payload.get("source") or "").strip()
            require(os.path.isabs(source) and len(source) <= 255, "Invalid host path.")
            require(source not in INSTANCE_DISK_PATH_DENY, "That host path cannot be mounted into an instance.")
            require(os.path.exists(source), f"Host path not found: {source}")
            mount_path = validate_mount_path(payload.get("path"))
            cmd.extend([f"source={source}", f"path={mount_path}"])
        else:
            pool = str(payload.get("pool") or "").strip()
            volume = str(payload.get("volume") or "").strip()
            require(bool(re.fullmatch(r"[A-Za-z0-9][A-Za-z0-9_.-]{0,63}", pool)), "Invalid storage pool.")
            require(bool(re.fullmatch(r"[A-Za-z0-9][A-Za-z0-9_.-]{0,63}", volume)), "Invalid storage volume.")

            match = next(
                (
                    item for item in incus_custom_volumes()
                    if item["pool"] == pool and item["name"] == volume
                ),
                None,
            )
            require(match is not None, "Storage volume not found.")
            expected_content = "block" if instance.get("type") == "virtual-machine" else "filesystem"
            require(match["content_type"] == expected_content, "The storage volume content type does not match this instance type.")

            cmd.extend([f"pool={pool}", f"source={volume}"])
            if instance.get("type") == "container":
                cmd.append(f"path={validate_mount_path(payload.get('path'))}")

        if bool(payload.get("readonly", False)):
            cmd.append("readonly=true")
    else:
        if kind in {"unix-char", "unix-block"}:
            require(instance_type == "container", "Unix device pass-through is only supported for containers.")
            source = validate_host_device_source(payload.get("source"), kind)
            cmd.append(f"source={source}")

            if payload.get("path") not in (None, ""):
                cmd.append(f"path={validate_mount_path(payload.get('path'))}")
        elif kind == "gpu":
            require(instance_type in {"container", "virtual-machine"}, "GPUs cannot be attached to this instance type.")
            require(
                instance_type != "virtual-machine" or instance_status != "running",
                "Stop the virtual machine before adding a physical GPU.",
            )
            address = validate_pci_device(payload.get("pci"), expected_gpu=True)
            cmd.extend(["gputype=physical", f"pci={address}"])
        elif kind == "pci":
            require(instance_type == "virtual-machine", "PCI pass-through is only supported for virtual machines.")
            require(instance_status != "running", "Stop the virtual machine before adding a PCI device.")
            cmd.append(f"address={validate_pci_device(payload.get('address'), expected_gpu=False)}")
        elif kind == "usb":
            arguments, _ = resolve_usb_device(payload)
            cmd.extend(arguments)
        else:
            raise HostError("Unsupported device type.")

    run(cmd, timeout=30)
    return {"name": name, "device": device, "type": kind}


def op_incus_device_set(payload):
    name = str(payload.get("name", ""))
    device = str(payload.get("device", ""))
    key = str(payload.get("key", ""))
    raw_value = payload.get("value")
    require(INSTANCE_NAME_RE.match(name), "Invalid instance name.")
    require(DEVICE_NAME_RE.match(device), "Invalid device name.")

    instance = incus_instance_json(name)
    local_devices = instance.get("devices") or {}
    require(device in local_devices, "Customize the profile-inherited device before editing it.")
    kind = str((local_devices.get(device) or {}).get("type", ""))
    value = "" if raw_value is None else str(raw_value).strip()

    if kind == "nic":
        require(key in {"vlan", "mtu"}, "Unsupported network device setting.")
        if key == "vlan":
            if value == "":
                run(["/usr/bin/incus", "config", "device", "unset", name, device, "vlan"], timeout=20)
            else:
                require(bool(VLAN_RE.match(value)) and 1 <= int(value) <= 4094, "VLAN must be between 1 and 4094.")
                run(["/usr/bin/incus", "config", "device", "set", name, device, f"vlan={int(value)}"], timeout=20)
        else:
            if value == "":
                run(["/usr/bin/incus", "config", "device", "unset", name, device, "mtu"], timeout=20)
            else:
                require(bool(MTU_RE.match(value)) and 576 <= int(value) <= 65535, "MTU must be between 576 and 65535.")
                run(["/usr/bin/incus", "config", "device", "set", name, device, f"mtu={int(value)}"], timeout=20)
    elif kind == "disk":
        require(key in {"size", "readonly"}, "Unsupported disk device setting.")
        if key == "size":
            require(device == "root", "Only the root disk size can be changed here.")
            require(bool(VOLUME_SIZE_RE.match(value)), "Invalid disk size.")
            current_size = ((instance.get("expanded_devices") or {}).get("root") or {}).get("size")
            requested_bytes = parse_size_bytes(value)
            current_bytes = parse_size_bytes(current_size) if current_size else None
            require(requested_bytes is not None, "Invalid disk size.")
            require(
                current_bytes is None or requested_bytes >= current_bytes,
                f"Root disks can only grow. The current size is {current_size}.",
            )
            run(["/usr/bin/incus", "config", "device", "set", name, device, f"size={value}"], timeout=30)
        else:
            require(value in {"true", "false"}, "Invalid readonly value.")
            run(["/usr/bin/incus", "config", "device", "set", name, device, f"readonly={value}"], timeout=20)
    else:
        raise HostError("Unsupported device type.")

    return {"name": name, "device": device, "key": key}


def op_incus_device_remove(payload):
    name = str(payload.get("name", ""))
    device = str(payload.get("device", ""))
    require(INSTANCE_NAME_RE.match(name), "Invalid instance name.")
    require(DEVICE_NAME_RE.match(device), "Invalid device name.")
    require(device != "root", "The root disk cannot be removed.")

    instance = incus_instance_json(name)
    require(device in (instance.get("devices") or {}), "Only locally configured devices can be removed.")

    run(["/usr/bin/incus", "config", "device", "remove", name, device], timeout=30)
    return {"name": name, "device": device}


def op_incus_device_override(payload):
    name = str(payload.get("name", ""))
    device = str(payload.get("device", ""))
    require(INSTANCE_NAME_RE.match(name), "Invalid instance name.")
    require(DEVICE_NAME_RE.match(device), "Invalid device name.")

    instance = incus_instance_json(name)
    local_devices = instance.get("devices") or {}
    expanded_devices = instance.get("expanded_devices") or {}
    require(device in expanded_devices, "That device is not present on the instance.")
    require(device not in local_devices, "That device is already locally configured.")

    run(["/usr/bin/incus", "config", "device", "override", name, device], timeout=30)
    return {"name": name, "device": device}


def backup_kind_target(payload):
    kind = str(payload.get("kind", ""))
    target = str(payload.get("target", "")).strip()
    require(kind in {"zfs", "incus"}, "Unsupported snapshot kind.")
    if kind == "zfs":
        require(ZFS_RE.match(target) and "@" not in target, "Invalid ZFS dataset.")
    else:
        require(INSTANCE_NAME_RE.match(target), "Invalid instance name.")
    return kind, target


def backup_snapshot_id(payload):
    snapshot = str(payload.get("snapshot", "")).strip()
    require(bool(re.fullmatch(r"[A-Za-z0-9][A-Za-z0-9_.:-]{0,119}", snapshot)), "Invalid snapshot name.")
    return snapshot


def op_backup_inventory(payload):
    _, filesystems_out, _ = run(
        ["/usr/sbin/zfs", "list", "-Hp", "-t", "filesystem", "-o", "name,mountpoint,used,avail"],
        timeout=30,
    )
    datasets = []
    for line in filesystems_out.splitlines():
        parts = line.split("\t")
        if len(parts) < 4:
            continue
        name = parts[0]
        if re.match(r"^storage/(docker|incus)/", name):
            continue
        datasets.append(
            {
                "name": name,
                "mountpoint": None if parts[1] == "-" else parts[1],
                "used": int(parts[2]) if parts[2].isdigit() else None,
                "avail": int(parts[3]) if parts[3].isdigit() else None,
            }
        )

    selectable = {dataset["name"] for dataset in datasets}
    _, snapshots_out, _ = run(
        ["/usr/sbin/zfs", "list", "-Hp", "-t", "snapshot", "-o", "name,used,creation,refer"],
        timeout=30,
    )
    zfs_snapshots = []
    for line in snapshots_out.splitlines():
        parts = line.split("\t")
        if len(parts) < 4 or "@" not in parts[0]:
            continue
        dataset, snapshot = parts[0].split("@", 1)
        if dataset not in selectable:
            continue
        zfs_snapshots.append(
            {
                "kind": "zfs",
                "target": dataset,
                "name": snapshot,
                "full": parts[0],
                "used": int(parts[1]) if parts[1].lstrip("-").isdigit() else None,
                "created_at": int(parts[2]) if parts[2].isdigit() else None,
                "refer": int(parts[3]) if parts[3].isdigit() else None,
            }
        )

    _, instances_out, _ = run(["/usr/bin/incus", "list", "--format", "json"], timeout=30)
    try:
        instances_raw = json.loads(instances_out)
    except json.JSONDecodeError:
        raise HostError("Incus returned an unreadable instance list.")

    instances = []
    incus_snapshots = []
    for instance in instances_raw:
        name = str(instance.get("name", ""))
        if not name:
            continue
        instances.append(
            {
                "name": name,
                "type": str(instance.get("type", "container")),
                "status": str(instance.get("status", "unknown")).lower(),
            }
        )
        try:
            _, snapshot_out, _ = run(
                ["/usr/bin/incus", "snapshot", "list", name, "--format", "json"],
                timeout=20,
            )
            snapshots_raw = json.loads(snapshot_out)
        except (HostError, json.JSONDecodeError):
            continue
        for snapshot in snapshots_raw or []:
            incus_snapshots.append(
                {
                    "kind": "incus",
                    "target": name,
                    "name": str(snapshot.get("name", "")),
                    "full": f"{name}/{snapshot.get('name', '')}",
                    "created_at": snapshot.get("created_at"),
                    "expires_at": snapshot.get("expires_at"),
                    "stateful": bool(snapshot.get("stateful", False)),
                }
            )

    zfs_snapshots.sort(key=lambda item: item.get("created_at") or 0, reverse=True)
    incus_snapshots.sort(key=lambda item: str(item.get("created_at") or ""), reverse=True)

    return {
        "datasets": datasets,
        "instances": instances,
        "zfs_snapshots": zfs_snapshots[:500],
        "incus_snapshots": incus_snapshots[:500],
        "zfs_snapshot_count": len(zfs_snapshots),
        "incus_snapshot_count": len(incus_snapshots),
    }


def op_backup_snapshot_create(payload):
    kind, target = backup_kind_target(payload)
    snapshot = backup_snapshot_id(payload)

    recursive = bool(payload.get("recursive", False))

    if kind == "zfs":
        cmd = ["/usr/sbin/zfs", "snapshot"]
        if recursive:
            cmd.append("-r")
        cmd.append(f"{target}@{snapshot}")
        run(cmd, timeout=180)
    else:
        run(["/usr/bin/incus", "snapshot", "create", target, snapshot], timeout=180)

    return {"kind": kind, "target": target, "snapshot": snapshot, "recursive": recursive}


def op_backup_snapshot_delete(payload):
    kind, target = backup_kind_target(payload)
    snapshot = backup_snapshot_id(payload)

    if kind == "zfs":
        run(["/usr/sbin/zfs", "destroy", f"{target}@{snapshot}"], timeout=120)
    else:
        run(["/usr/bin/incus", "snapshot", "delete", target, snapshot], timeout=180)

    return {"kind": kind, "target": target, "snapshot": snapshot}


def op_backup_snapshot_restore(payload):
    kind, target = backup_kind_target(payload)
    snapshot = backup_snapshot_id(payload)

    if kind == "zfs":
        run(["/usr/sbin/zfs", "rollback", "-r", f"{target}@{snapshot}"], timeout=300)
    else:
        run(["/usr/bin/incus", "snapshot", "restore", target, snapshot], timeout=300)

    return {"kind": kind, "target": target, "snapshot": snapshot}


def op_backup_prune(payload):
    kind, target = backup_kind_target(payload)
    prefix = str(payload.get("prefix", "")).strip()
    keep = int(payload.get("keep", 0))
    recursive = bool(payload.get("recursive", False))
    require(bool(re.fullmatch(r"serverdeck-[A-Za-z0-9_.:-]{1,80}", prefix)), "Invalid snapshot prefix.")
    require(1 <= keep <= 500, "Retention must be between 1 and 500.")

    deleted = []
    if kind == "zfs":
        _, out, _ = run(
            ["/usr/sbin/zfs", "list", "-Hp", "-t", "snapshot", "-o", "name,creation", "-r", target],
            timeout=30,
        )
        groups = {}
        for line in out.splitlines():
            parts = line.split("\t")
            if len(parts) < 2 or "@" not in parts[0]:
                continue
            dataset, snapshot = parts[0].split("@", 1)
            if not snapshot.startswith(prefix):
                continue
            if not recursive and dataset != target:
                continue
            created = int(parts[1]) if parts[1].isdigit() else 0
            groups.setdefault(snapshot, {"created": 0, "datasets": []})
            groups[snapshot]["created"] = max(groups[snapshot]["created"], created)
            groups[snapshot]["datasets"].append(dataset)
        ordered = sorted(groups.items(), key=lambda item: item[1]["created"], reverse=True)
        for snapshot, group in ordered[keep:]:
            for dataset in sorted(group["datasets"], reverse=True):
                run(["/usr/sbin/zfs", "destroy", f"{dataset}@{snapshot}"], timeout=120)
            deleted.append(snapshot)
    else:
        _, out, _ = run(["/usr/bin/incus", "snapshot", "list", target, "--format", "json"], timeout=30)
        try:
            snapshots = json.loads(out)
        except json.JSONDecodeError:
            raise HostError("Incus returned an unreadable snapshot list.")
        matches = [
            (str(snapshot.get("created_at") or ""), str(snapshot.get("name", "")))
            for snapshot in snapshots or []
            if str(snapshot.get("name", "")).startswith(prefix)
        ]
        matches.sort(reverse=True)
        for _, snapshot in matches[keep:]:
            run(["/usr/bin/incus", "snapshot", "delete", target, snapshot], timeout=180)
            deleted.append(snapshot)

    return {"kind": kind, "target": target, "prefix": prefix, "keep": keep, "deleted": deleted}


SMART_SELFTEST_MODES = {"short", "long", "conveyance", "offline"}
SMART_DISK_NAME_RE = re.compile(r"^[A-Za-z0-9][A-Za-z0-9_.-]{0,63}$")


def smart_disk_inventory():
    _, out, _ = run(
        ["/usr/bin/lsblk", "-J", "-b", "-o", "NAME,PATH,TYPE,SIZE,MODEL,SERIAL,ROTA,TRAN,MOUNTPOINTS"],
        timeout=20,
    )
    try:
        tree = json.loads(out)
    except json.JSONDecodeError:
        raise HostError("lsblk returned unreadable disk data.")

    disks = []

    def paths_and_mounts(node, paths, mounts):
        path = str(node.get("path") or "")
        if path:
            paths.append(path)
        for mount in node.get("mountpoints") or []:
            if mount:
                mounts.append(str(mount))
        for child in node.get("children") or []:
            paths_and_mounts(child, paths, mounts)

    def walk(node):
        if node.get("type") == "disk":
            name = str(node.get("name") or "")
            if SMART_DISK_NAME_RE.match(name) and not name.startswith(("loop", "ram", "zram", "sr", "zd")):
                paths, mounts = [], []
                paths_and_mounts(node, paths, mounts)
                disks.append(
                    {
                        "name": name,
                        "path": f"/dev/{name}",
                        "size": int(node.get("size") or 0) or None,
                        "model": str(node.get("model") or "").strip(),
                        "serial": str(node.get("serial") or "").strip(),
                        "rotational": bool(node.get("rota")),
                        "transport": str(node.get("tran") or "").strip(),
                        "paths": paths,
                        "mounts": mounts,
                    }
                )
        for child in node.get("children") or []:
            walk(child)

    for node in tree.get("blockdevices", []):
        walk(node)

    return disks


def smart_zfs_map():
    try:
        code, out, _ = run(["/usr/sbin/zpool", "status", "-P", "-L"], timeout=20, combine=True)
    except HostError:
        return {}
    if code != 0:
        return {}

    pool = None
    mapping = {}
    for line in out.splitlines():
        stripped = line.strip()
        if stripped.startswith("pool:"):
            pool = stripped.split(":", 1)[1].strip()
            mapping.setdefault(pool, set())
            continue
        if pool and stripped.startswith("/dev/"):
            mapping[pool].add(stripped.split()[0])
    return mapping


def smart_int(value):
    if isinstance(value, bool):
        return int(value)
    if isinstance(value, (int, float)):
        return int(value)
    if isinstance(value, str) and value.strip().isdigit():
        return int(value.strip())
    return None


def smart_attribute_map(data):
    attributes = {}
    for attribute in (data.get("ata_smart_attributes") or {}).get("table") or []:
        name = str(attribute.get("name") or "")
        if not name:
            continue
        attributes[name] = {
            "raw": smart_int((attribute.get("raw") or {}).get("value")),
            "value": smart_int(attribute.get("value")),
            "worst": smart_int(attribute.get("worst")),
            "threshold": smart_int(attribute.get("thresh")),
        }
    return attributes


def smart_self_test(data):
    log = data.get("ata_smart_self_test_log") or {}
    table = (log.get("standard") or {}).get("table") or []
    if not table:
        return None
    latest = table[0]
    status = latest.get("status") or {}
    test_type = latest.get("type") or {}
    status_text = str(status.get("string") or "").strip()
    if not status_text:
        return None
    lowered = status_text.lower()
    return {
        "status": status_text,
        "type": str(test_type.get("string") or "").strip() or None,
        "remaining_percent": smart_int(latest.get("remaining_percent")),
        "lifetime_hours": smart_int(latest.get("lifetime_hours")),
        "in_progress": "in progress" in lowered,
        "failed": ("failed" in lowered or "read failure" in lowered or "unknown error" in lowered),
    }


def smart_device_status(device, attributes, nvme, messages, test):
    reasons = []
    status = "ok"
    health_passed = device.get("health_passed")
    temperature = device.get("temperature")
    rotational = device.get("rotational", True)

    if health_passed is False:
        reasons.append("SMART overall health check failed")
        status = "critical"

    pending = device.get("pending_sectors")
    uncorrectable = device.get("uncorrectable_sectors")
    media_errors = device.get("media_errors")

    if pending:
        reasons.append(f"{pending} pending sector(s)")
        status = "critical"
    if uncorrectable:
        reasons.append(f"{uncorrectable} uncorrectable sector(s)")
        status = "critical"
    if media_errors:
        reasons.append(f"{media_errors} NVMe media error(s)")
        status = "critical"

    reallocated = device.get("reallocated_sectors")
    if reallocated:
        reasons.append(f"{reallocated} reallocated sector(s)")
        if status == "ok":
            status = "warning"

    crc_errors = device.get("crc_errors")
    if crc_errors:
        reasons.append(f"{crc_errors} interface CRC error(s)")
        if status == "ok":
            status = "warning"

    if temperature is not None:
        critical_temp = 65 if rotational else 80
        warning_temp = 55 if rotational else 70
        if temperature >= critical_temp:
            reasons.append(f"temperature {temperature}°C")
            status = "critical"
        elif temperature >= warning_temp:
            reasons.append(f"temperature {temperature}°C")
            if status == "ok":
                status = "warning"

    wear_value = device.get("wear_leveling_value")
    if wear_value is not None and wear_value <= 10:
        reasons.append(f"SSD wear indicator at {wear_value}%")
        if status == "ok":
            status = "warning"

    percentage_used = device.get("percentage_used")
    if percentage_used is not None:
        if percentage_used >= 100:
            reasons.append(f"NVMe endurance used {percentage_used}%")
            status = "critical"
        elif percentage_used >= 90:
            reasons.append(f"NVMe endurance used {percentage_used}%")
            if status == "ok":
                status = "warning"

    if device.get("smart_supported") and not device.get("smart_enabled"):
        reasons.append("SMART is supported but disabled")
        if status == "ok":
            status = "warning"

    if messages:
        if status == "ok":
            status = "warning"
        reasons.extend(messages[:3])

    if test and test.get("failed"):
        reasons.append(f"Last self-test: {test['status']}")
        if status == "ok":
            status = "warning"

    if not device.get("smart_supported"):
        status = "info"
        reasons = [device.get("error") or "SMART is not supported by this device"]

    return status, reasons


def smart_read_device(disk, zfs_map):
    result = {
        "name": disk["name"],
        "path": disk["path"],
        "size": disk["size"],
        "model": disk["model"],
        "serial": disk["serial"],
        "rotational": disk["rotational"],
        "transport": disk["transport"],
        "mounts": disk["mounts"],
        "zfs_pools": sorted(
            pool for pool, paths in zfs_map.items() if set(paths) & set(disk["paths"])
        ),
        "root_disk": "/" in disk["mounts"],
        "smart_supported": False,
        "smart_enabled": False,
        "health_passed": None,
        "temperature": None,
        "power_on_hours": None,
        "power_cycles": None,
        "firmware": None,
        "protocol": None,
        "reallocated_sectors": None,
        "pending_sectors": None,
        "uncorrectable_sectors": None,
        "crc_errors": None,
        "wear_leveling": None,
        "wear_leveling_value": None,
        "percentage_used": None,
        "media_errors": None,
        "error_log_count": None,
        "self_test": None,
        "messages": [],
        "error": None,
    }

    try:
        process = subprocess.run(
            ["/usr/sbin/smartctl", "-a", "-j", disk["path"]],
            capture_output=True,
            text=True,
            timeout=25,
            env=BASE_ENV,
            errors="replace",
        )
    except subprocess.TimeoutExpired:
        result["error"] = "SMART query timed out."
        result["status"], result["reasons"] = smart_device_status(result, {}, {}, [], None)
        return result
    except FileNotFoundError:
        raise HostError("smartmontools is not installed.")

    raw = (process.stdout or "").strip()
    if not raw:
        detail = (process.stderr or "").strip().splitlines()
        result["error"] = detail[-1] if detail else "smartctl returned no data."
        result["status"], result["reasons"] = smart_device_status(result, {}, {}, [], None)
        return result

    try:
        data = json.loads(raw)
    except json.JSONDecodeError:
        result["error"] = "smartctl returned unreadable JSON."
        result["status"], result["reasons"] = smart_device_status(result, {}, {}, [], None)
        return result

    smartctl_data = data.get("smartctl") or {}
    exit_status = smart_int(smartctl_data.get("exit_status")) or 0
    smart_support = data.get("smart_support") or {}
    device_data = data.get("device") or {}
    smart_status = data.get("smart_status") or {}

    result["protocol"] = str(device_data.get("protocol") or "").strip() or None
    result["firmware"] = str(data.get("firmware_version") or "").strip() or None
    result["model"] = str(data.get("model_name") or disk["model"] or "").strip()
    result["serial"] = str(data.get("serial_number") or disk["serial"] or "").strip()
    if data.get("user_capacity"):
        result["size"] = smart_int((data.get("user_capacity") or {}).get("bytes")) or result["size"]

    supported = bool(smart_support.get("available", bool(smart_status) or bool(data.get("ata_smart_attributes"))))
    if exit_status & 2 and not smart_status and not data.get("ata_smart_attributes"):
        supported = False
    result["smart_supported"] = supported
    result["smart_enabled"] = bool(smart_support.get("enabled", supported))
    if "passed" in smart_status:
        result["health_passed"] = bool(smart_status.get("passed"))

    result["temperature"] = smart_int((data.get("temperature") or {}).get("current"))
    result["power_on_hours"] = smart_int((data.get("power_on_time") or {}).get("hours"))
    result["power_cycles"] = smart_int(data.get("power_cycle_count"))

    attributes = smart_attribute_map(data)
    result["reallocated_sectors"] = (attributes.get("Reallocated_Sector_Ct") or {}).get("raw")
    result["pending_sectors"] = (attributes.get("Current_Pending_Sector") or {}).get("raw")
    result["uncorrectable_sectors"] = (
        (attributes.get("Offline_Uncorrectable") or {}).get("raw")
        if attributes.get("Offline_Uncorrectable")
        else (attributes.get("Reported_Uncorrect") or {}).get("raw")
    )
    result["crc_errors"] = (attributes.get("UDMA_CRC_Error_Count") or {}).get("raw")
    result["wear_leveling"] = (attributes.get("Wear_Leveling_Count") or {}).get("raw")
    result["wear_leveling_value"] = (attributes.get("Wear_Leveling_Count") or {}).get("value")

    nvme = data.get("nvme_smart_health_information_log") or {}
    if nvme:
        result["percentage_used"] = smart_int(nvme.get("percentage_used"))
        result["media_errors"] = smart_int(nvme.get("media_errors"))
        result["temperature"] = smart_int(nvme.get("temperature")) if result["temperature"] is None else result["temperature"]
        result["power_on_hours"] = smart_int(nvme.get("power_on_hours")) if result["power_on_hours"] is None else result["power_on_hours"]

    error_log = data.get("ata_smart_error_log") or {}
    result["error_log_count"] = smart_int((error_log.get("summary") or {}).get("count"))

    messages = []
    for message in data.get("messages") or []:
        if str(message.get("severity") or "") in {"error", "warning"}:
            text = str(message.get("string") or "").strip()
            if text and text not in messages:
                messages.append(text)
    result["messages"] = messages[:8]
    result["self_test"] = smart_self_test(data)

    if not raw:
        result["error"] = "smartctl returned no data."

    result["status"], result["reasons"] = smart_device_status(
        result, attributes, nvme, result["messages"], result["self_test"]
    )
    return result


def op_smart_scan(payload):
    require(os.path.exists("/usr/sbin/smartctl"), "smartmontools is not installed.")
    disks = smart_disk_inventory()
    zfs_map = smart_zfs_map()
    devices = [smart_read_device(disk, zfs_map) for disk in disks]
    devices.sort(key=lambda item: item["name"])

    counts = {"total": len(devices), "ok": 0, "warning": 0, "critical": 0, "info": 0}
    for device in devices:
        counts[device["status"]] = counts.get(device["status"], 0) + 1

    return {
        "devices": devices,
        "counts": counts,
        "scanned_at": int(time.time()),
    }


def op_smart_selftest(payload):
    device = str(payload.get("device", "")).strip()
    mode = str(payload.get("mode", "short")).strip()
    require(bool(re.fullmatch(r"/dev/[A-Za-z0-9_.-]{1,80}", device)), "Invalid disk device.")
    require(mode in SMART_SELFTEST_MODES, "Unsupported self-test mode.")
    require(any(disk["path"] == device for disk in smart_disk_inventory()), "Unknown disk device.")
    require(os.path.exists("/usr/sbin/smartctl"), "smartmontools is not installed.")
    run(["/usr/sbin/smartctl", "-t", mode, device], timeout=60)
    return {"device": device, "mode": mode, "started": True}


def op_smart_selftest_abort(payload):
    device = str(payload.get("device", "")).strip()
    require(bool(re.fullmatch(r"/dev/[A-Za-z0-9_.-]{1,80}", device)), "Invalid disk device.")
    require(any(disk["path"] == device for disk in smart_disk_inventory()), "Unknown disk device.")
    run(["/usr/sbin/smartctl", "-X", device], timeout=60)
    return {"device": device, "aborted": True}


APT_ENV = {**BASE_ENV, "DEBIAN_FRONTEND": "noninteractive"}
APT_UPGRADABLE_RE = re.compile(
    r"^(\S+)/(\S+)\s+(\S+)\s+(\S+)\s+\[upgradable from:\s*(\S+)\]\s*$"
)
APT_INST_RE = re.compile(
    r"^Inst\s+(\S+)(?:\s+\[([^\]]+)\])?\s+\((\S+)\s+(.+?)\s+\[([^\]]+)\]\)\s*$"
)
KERNEL_RELEASE_RE = re.compile(r"^linux-image-(\d+\.\d+\.\d+-\d+-generic)$")


def apt_tail(text, limit=6):
    lines = [line.strip() for line in (text or "").splitlines() if line.strip()]
    return "\n".join(lines[-limit:])


def apt_reboot_state():
    packages_path = "/var/run/reboot-required.pkgs"
    packages = []
    if os.path.exists(packages_path):
        try:
            with open(packages_path, "r", encoding="utf-8", errors="replace") as handle:
                packages = [line.strip() for line in handle if line.strip()][:100]
        except OSError:
            packages = []
    return {
        "required": os.path.exists("/var/run/reboot-required"),
        "packages": packages,
    }


def apt_latest_kernel():
    code, out, _ = run(
        ["/usr/bin/dpkg-query", "-W", "-f=${Package}\t${Status}\n", "linux-image-*"],
        timeout=20,
        combine=True,
    )
    if code != 0:
        return None

    releases = []
    for line in out.splitlines():
        parts = line.split("\t")
        if len(parts) < 2 or "install ok installed" not in parts[1]:
            continue
        match = KERNEL_RELEASE_RE.match(parts[0].strip())
        if match:
            releases.append(match.group(1))
    if not releases:
        return None

    def version_key(release):
        return tuple(int(part) for part in re.findall(r"\d+", release))

    return sorted(releases, key=version_key)[-1]


def apt_lists_updated_at():
    for path in ("/var/lib/apt/periodic/update-success-stamp", "/var/lib/apt/lists"):
        try:
            return int(os.path.getmtime(path))
        except OSError:
            continue
    return None


def apt_last_upgrade_at():
    try:
        with open("/var/log/apt/history.log", "r", encoding="utf-8", errors="replace") as handle:
            history = handle.read()
    except OSError:
        return None

    matches = re.findall(r"End-Date:\s*(\d{4}-\d{2}-\d{2}\s+\d{2}:\d{2}:\d{2})", history)
    if not matches:
        return None
    try:
        return int(time.mktime(time.strptime(matches[-1], "%Y-%m-%d %H:%M:%S")))
    except ValueError:
        return None


def apt_unattended_active():
    code, out, _ = run(
        ["/usr/bin/systemctl", "is-active", "unattended-upgrades.service"],
        timeout=10,
        combine=True,
    )
    return code == 0 and out.strip() == "active"


def apt_parse_available(text):
    available = []
    for line in text.splitlines():
        match = APT_UPGRADABLE_RE.match(line.strip())
        if not match:
            continue
        name, repository, candidate, architecture, current = match.groups()
        available.append(
            {
                "name": name,
                "current_version": current,
                "candidate_version": candidate,
                "repository": repository,
                "architecture": architecture,
                "security": "security" in repository.lower(),
                "is_new": False,
            }
        )
    return available


def apt_parse_plan(text):
    plan = []
    for line in text.splitlines():
        match = APT_INST_RE.match(line.strip())
        if not match:
            continue
        name, current, candidate, repositories, architecture = match.groups()
        repos = [part.strip() for part in repositories.split(",") if part.strip()]
        plan.append(
            {
                "name": name,
                "current_version": current,
                "candidate_version": candidate,
                "repositories": repos,
                "repository": ", ".join(repos),
                "architecture": architecture,
                "security": any("security" in repo.lower() for repo in repos),
                "is_new": current is None,
            }
        )
    return plan


def op_apt_status(payload):
    code, simulation, _ = run(
        [
            "/usr/bin/apt-get", "-s", "--with-new-pkgs",
            "-o", "Debug::NoLocking=true", "upgrade",
        ],
        timeout=120,
        env=APT_ENV,
        combine=True,
    )
    if code != 0:
        raise HostError(apt_tail(simulation) or "apt-get simulation failed.")

    plan = apt_parse_plan(simulation)
    planned_names = {package["name"] for package in plan}

    code, listing, _ = run(
        ["/usr/bin/apt", "list", "--upgradable"],
        timeout=60,
        env=APT_ENV,
        combine=True,
    )
    available = apt_parse_available(listing) if code == 0 else []
    held_back = [package for package in available if package["name"] not in planned_names]

    planned_security = sum(1 for package in plan if package["security"])
    held_security = sum(1 for package in held_back if package["security"])
    reboot = apt_reboot_state()
    running_kernel = os.uname().release
    latest_kernel = apt_latest_kernel()

    return {
        "packages": plan,
        "held_back": held_back,
        "counts": {
            "total": len(plan) + len(held_back),
            "planned": len(plan),
            "security": planned_security + held_security,
            "new_packages": sum(1 for package in plan if package["is_new"]),
            "held_back": len(held_back),
        },
        "reboot_required": reboot["required"],
        "reboot_packages": reboot["packages"],
        "running_kernel": running_kernel,
        "latest_kernel": latest_kernel,
        "kernel_update_pending": bool(latest_kernel and latest_kernel != running_kernel),
        "lists_updated_at": apt_lists_updated_at(),
        "last_upgrade_at": apt_last_upgrade_at(),
        "unattended_upgrades_active": apt_unattended_active(),
        "simulated_at": int(time.time()),
    }


def op_apt_refresh(payload):
    code, out, _ = run(
        ["/usr/bin/apt-get", "update"],
        timeout=240,
        env=APT_ENV,
        combine=True,
    )
    if code != 0:
        raise HostError(apt_tail(out) or "apt-get update failed.")
    return {"updated_at": int(time.time()), "output_tail": apt_tail(out)}


def op_apt_upgrade(payload):
    before = apt_reboot_state()
    code, out, _ = run(
        [
            "/usr/bin/apt-get", "-y", "--with-new-pkgs",
            "-o", "Dpkg::Options::=--force-confdef",
            "-o", "Dpkg::Options::=--force-confold",
            "upgrade",
        ],
        timeout=900,
        env=APT_ENV,
        combine=True,
    )
    if code != 0:
        raise HostError(apt_tail(out) or "apt-get upgrade failed.")

    after = apt_reboot_state()
    return {
        "completed_at": int(time.time()),
        "reboot_required": after["required"],
        "reboot_required_before": before["required"],
        "reboot_packages": after["packages"],
        "output_tail": apt_tail(out),
    }


def op_zfs_status(payload):
    _, pools_out, _ = run(
        ["/usr/sbin/zpool", "list", "-Hp", "-o", "name,size,alloc,free,cap,frag,dedup,health"],
        timeout=20,
    )
    pools = []
    for line in pools_out.splitlines():
        parts = line.split("\t")
        if len(parts) < 8:
            continue
        pools.append(
            {
                "name": parts[0],
                "size": int(parts[1]) if parts[1].isdigit() else None,
                "alloc": int(parts[2]) if parts[2].isdigit() else None,
                "free": int(parts[3]) if parts[3].isdigit() else None,
                "capacity": parts[4],
                "fragmentation": parts[5],
                "dedup": parts[6],
                "health": parts[7],
            }
        )

    _, datasets_out, _ = run(
        ["/usr/sbin/zfs", "list", "-Hp", "-o", "name,used,avail,refer,mountpoint,type,compression"],
        timeout=20,
    )
    datasets = []
    for line in datasets_out.splitlines():
        parts = line.split("\t")
        if len(parts) < 7:
            continue
        datasets.append(
            {
                "name": parts[0],
                "used": int(parts[1]) if parts[1].isdigit() else None,
                "avail": int(parts[2]) if parts[2].isdigit() else None,
                "refer": int(parts[3]) if parts[3].isdigit() else None,
                "mountpoint": None if parts[4] == "-" else parts[4],
                "type": parts[5],
                "compression": parts[6],
            }
        )

    _, status_out, _ = run(["/usr/sbin/zpool", "status", "-P"], timeout=20)
    _, version_out, _ = run(["/usr/sbin/zfs", "version"], timeout=10)

    return {
        "pools": pools,
        "datasets": datasets,
        "status": status_out,
        "version": version_out.strip(),
    }


def op_zfs_action(payload):
    action = str(payload.get("action", ""))
    require(action in {"scrub", "create_dataset", "destroy_dataset", "snapshot", "create_pool"}, "Unsupported ZFS action.")

    if action == "scrub":
        pool = str(payload.get("pool", ""))
        require(ZFS_RE.match(pool) and "/" not in pool, "Invalid pool name.")
        run(["/usr/sbin/zpool", "scrub", pool], timeout=30)
        return {"action": action, "pool": pool}

    if action == "create_pool":
        pool = str(payload.get("pool", ""))
        devices = payload.get("devices") or []
        require(re.fullmatch(r"[A-Za-z][A-Za-z0-9_.-]{0,62}", pool), "Invalid pool name.")
        require(isinstance(devices, list) and 1 <= len(devices) <= 12, "Select at least one device.")
        clean = []
        for device in devices:
            device = str(device)
            require(re.fullmatch(r"/dev/[A-Za-z0-9/_.-]{1,120}", device), "Invalid device path.")
            require(os.path.exists(device), f"Device not found: {device}")
            clean.append(device)
        _, lsblk, _ = run(["/usr/bin/lsblk", "-J", "-o", "NAME,PATH,TYPE,MOUNTPOINTS"], timeout=10)
        tree = json.loads(lsblk)
        mounted = set()
        for block in tree.get("blockdevices", []):
            def walk(node):
                if node.get("mountpoints") and any(node["mountpoints"]):
                    mounted.add(node.get("path"))
                for child in node.get("children") or []:
                    walk(child)
            walk(block)
        for device in clean:
            require(device not in mounted, f"Refusing to use mounted device {device}.")
        _, existing, _ = run(["/usr/sbin/zpool", "list", "-H", "-o", "name"], timeout=10)
        require(pool not in existing.split(), "A pool with that name already exists.")
        run(["/usr/sbin/zpool", "create", pool, *clean], timeout=120)
        return {"action": action, "pool": pool, "devices": clean}

    name = str(payload.get("name", ""))
    require(ZFS_RE.match(name), "Invalid dataset name.")

    if action == "create_dataset":
        run(["/usr/sbin/zfs", "create", name], timeout=30)
        return {"action": action, "name": name}

    if action == "destroy_dataset":
        recursive = bool(payload.get("recursive", False))
        cmd = ["/usr/sbin/zfs", "destroy"]
        if recursive:
            cmd.append("-r")
        cmd.append(name)
        run(cmd, timeout=90)
        return {"action": action, "name": name, "recursive": recursive}

    if action == "snapshot":
        snapshot = str(payload.get("snapshot", ""))
        require(SNAP_RE.match(snapshot), "Invalid snapshot name.")
        target = f"{name}@{snapshot}"
        run(["/usr/sbin/zfs", "snapshot", target], timeout=30)
        return {"action": action, "name": target}

    raise HostError("Unsupported ZFS action.")


def op_network_ping(payload):
    target = str(payload.get("target", "")).strip()
    require(bool(NETWORK_TARGET_RE.match(target)), "Invalid host or IP address.")
    count = max(1, min(10, int(payload.get("count", 4))))
    started = time.time()
    code, output, _ = run(
        ["/usr/bin/ping", "-c", str(count), "-W", "2", target],
        timeout=count * 3 + 5,
        combine=True,
    )
    return {
        "target": target,
        "output": output[-20_000:],
        "exit_code": code,
        "reachable": code == 0,
        "duration_ms": int((time.time() - started) * 1000),
    }


def op_network_dig(payload):
    target = str(payload.get("target", "")).strip()
    record = str(payload.get("record", "A")).upper()
    require(bool(NETWORK_TARGET_RE.match(target)), "Invalid hostname.")
    require(record in DIG_RECORD_TYPES, "Unsupported record type.")
    started = time.time()
    code, output, _ = run(
        ["/usr/bin/dig", "+time=2", "+tries=2", target, record],
        timeout=12,
        combine=True,
    )
    return {
        "target": target,
        "record": record,
        "output": output[-20_000:],
        "exit_code": code,
        "duration_ms": int((time.time() - started) * 1000),
    }


def op_network_portcheck(payload):
    target = str(payload.get("target", "")).strip()
    port = int(payload.get("port", 0))
    require(bool(NETWORK_TARGET_RE.match(target)), "Invalid host or IP address.")
    require(1 <= port <= 65535, "Port must be between 1 and 65535.")
    started = time.time()
    code, output, _ = run(
        ["/usr/bin/nc", "-z", "-w", "3", target, str(port)],
        timeout=8,
        combine=True,
    )
    return {
        "target": target,
        "port": port,
        "open": code == 0,
        "output": output[-4_000:],
        "duration_ms": int((time.time() - started) * 1000),
    }


def parse_iptables_spec(line):
    tokens = shlex.split(line)
    rule = {
        "protocol": "all",
        "port": None,
        "source": None,
        "destination": None,
        "interface_in": None,
        "interface_out": None,
        "comment": None,
        "action": None,
    }
    index = 2
    while index < len(tokens):
        token = tokens[index]
        if token in ("-p", "--protocol"):
            rule["protocol"] = tokens[index + 1]
            index += 2
        elif token in ("-s", "--source"):
            rule["source"] = tokens[index + 1]
            index += 2
        elif token in ("-d", "--destination"):
            rule["destination"] = tokens[index + 1]
            index += 2
        elif token in ("-i", "--in-interface"):
            rule["interface_in"] = tokens[index + 1]
            index += 2
        elif token in ("-o", "--out-interface"):
            rule["interface_out"] = tokens[index + 1]
            index += 2
        elif token == "--dport":
            rule["port"] = tokens[index + 1]
            index += 2
        elif token == "--comment":
            rule["comment"] = tokens[index + 1]
            index += 2
        elif token in ("-j", "--jump"):
            rule["action"] = tokens[index + 1]
            index += 2
        elif token == "-m":
            index += 2
        else:
            index += 1
    return rule


def op_network_firewall_rules(payload):
    result = {"policy": {}, "rules": []}

    for family, binary in (("ipv4", "/usr/sbin/iptables"), ("ipv6", "/usr/sbin/ip6tables")):
        try:
            _, spec_out, _ = run([binary, "-S", "INPUT"], timeout=10)
        except HostError:
            result["policy"][family] = None
            continue

        policy = "ACCEPT"
        specs = []
        for line in spec_out.splitlines():
            line = line.strip()
            if not line:
                continue
            if line.startswith("-P "):
                parts = line.split()
                if len(parts) >= 3:
                    policy = parts[2]
            elif line.startswith("-A INPUT"):
                specs.append(parse_iptables_spec(line))
        result["policy"][family] = policy

        counters = {}
        try:
            _, list_out, _ = run([binary, "-L", "INPUT", "-n", "-v", "-x", "--line-numbers"], timeout=10)
            for line in list_out.splitlines()[2:]:
                parts = line.split()
                if len(parts) < 3 or not parts[0].isdigit():
                    continue
                counters[int(parts[0])] = (int(parts[1]), int(parts[2]))
        except HostError:
            pass

        default_source = "0.0.0.0/0" if family == "ipv4" else "::/0"
        for position, spec in enumerate(specs, start=1):
            packets, byte_count = counters.get(position, (0, 0))
            result["rules"].append(
                {
                    "line": position,
                    "family": family,
                    "action": spec["action"] or "ACCEPT",
                    "protocol": spec["protocol"],
                    "port": spec["port"],
                    "source": spec["source"] or default_source,
                    "destination": spec["destination"] or default_source,
                    "interface_in": spec["interface_in"],
                    "interface_out": spec["interface_out"],
                    "comment": spec["comment"],
                    "packets": packets,
                    "bytes": byte_count,
                }
            )

    return result


def op_network_firewall_rule_add(payload):
    family = str(payload.get("family", ""))
    require(family in {"ipv4", "ipv6"}, "Invalid address family.")
    protocol = str(payload.get("protocol", "all"))
    require(protocol in {"tcp", "udp", "icmp", "all"}, "Unsupported protocol.")
    action = str(payload.get("action", "")).upper()
    require(action in {"ACCEPT", "DROP", "REJECT"}, "Unsupported action.")
    port = payload.get("port")
    source = payload.get("source")
    comment = payload.get("comment")

    binary = "/usr/sbin/iptables" if family == "ipv4" else "/usr/sbin/ip6tables"
    cmd = [binary, "-I", "INPUT", "1"]

    if protocol != "all":
        cmd += ["-p", protocol]

    if port:
        port = str(port).strip()
        require(protocol in {"tcp", "udp"}, "A port requires the tcp or udp protocol.")
        require(bool(FIREWALL_PORT_RE.match(port)), "Invalid port or port range.")
        for value in port.split(":"):
            require(1 <= int(value) <= 65535, "Port must be between 1 and 65535.")
        cmd += ["--dport", port]
    else:
        port = None

    if source:
        source = str(source).strip()
        try:
            network = ipaddress.ip_network(source, strict=False)
        except ValueError:
            raise HostError("Invalid source address or CIDR.")
        require(
            (family == "ipv4" and network.version == 4) or (family == "ipv6" and network.version == 6),
            "The source address does not match the selected address family.",
        )
        source = str(network)
        cmd += ["-s", source]
    else:
        source = None

    if comment:
        comment = str(comment).strip()
        require(bool(FIREWALL_COMMENT_RE.match(comment)), "Invalid comment.")
        if comment:
            cmd += ["-m", "comment", "--comment", comment]
    else:
        comment = None

    cmd += ["-j", action]

    run(cmd, timeout=15)
    return {"family": family, "action": action, "protocol": protocol, "port": port, "source": source, "comment": comment}


def op_network_firewall_rule_delete(payload):
    family = str(payload.get("family", ""))
    require(family in {"ipv4", "ipv6"}, "Invalid address family.")
    line = payload.get("line")
    require(isinstance(line, int) and 1 <= line <= 500, "Invalid rule number.")

    binary = "/usr/sbin/iptables" if family == "ipv4" else "/usr/sbin/ip6tables"
    run([binary, "-D", "INPUT", str(line)], timeout=15)
    return {"family": family, "line": line}


def op_network_firewall(payload):
    try:
        code, out, _ = run(["/usr/sbin/nft", "list", "ruleset"], timeout=15, combine=True)
    except HostError:
        code, out = 1, ""

    if code == 0 and out.strip():
        return {"backend": "nft", "ruleset": out[-100_000:]}

    try:
        _, ipv4, _ = run(["/usr/sbin/iptables-save"], timeout=15, combine=True)
    except HostError:
        ipv4 = ""

    try:
        _, ipv6, _ = run(["/usr/sbin/ip6tables-save"], timeout=15, combine=True)
    except HostError:
        ipv6 = ""

    ruleset = ipv4.strip()
    if ipv6.strip():
        ruleset += "\n\n# ip6tables\n" + ipv6.strip()

    require(bool(ruleset), "No firewall ruleset could be read (nft and iptables-save both failed).")
    return {"backend": "iptables", "ruleset": ruleset[-100_000:]}


def op_systemd_list(payload):
    _, out, _ = run(
        [
            "/usr/bin/systemctl",
            "list-units",
            "--type=service",
            "--all",
            "--no-legend",
            "--no-pager",
            "--plain",
        ],
        timeout=20,
    )
    units = []
    for line in out.splitlines():
        line = line.strip()
        if not line:
            continue
        parts = line.split(None, 4)
        if len(parts) < 4:
            continue
        units.append(
            {
                "unit": parts[0],
                "load": parts[1],
                "active": parts[2],
                "sub": parts[3],
                "description": parts[4] if len(parts) > 4 else "",
            }
        )

    state = {}
    for key, value in (("running", "running"), ("failed", "failed"), ("inactive", "inactive")):
        state[key] = sum(1 for unit in units if unit["active"] == value)

    return {"units": units, "counts": state}


def op_systemd_action(payload):
    action = str(payload.get("action", ""))
    unit = str(payload.get("unit", ""))
    require(action in {"start", "stop", "restart", "reload", "enable", "disable"}, "Unsupported service action.")
    require(UNIT_RE.match(unit), "Invalid service unit.")
    run(["/usr/bin/systemctl", action, unit], timeout=90)
    _, out, _ = run(
        [
            "/usr/bin/systemctl",
            "show",
            unit,
            "--no-pager",
            "-p",
            "ActiveState,SubState,UnitFileState,MainPID,ExecMainStartTimestamp",
        ],
        timeout=15,
    )
    properties = {}
    for line in out.splitlines():
        if "=" in line:
            key, value = line.split("=", 1)
            properties[key] = value
    return {"unit": unit, "action": action, "properties": properties}


def op_journal_read(payload):
    lines = max(1, min(1000, int(payload.get("lines", 200))))
    cmd = ["/usr/bin/journalctl", "--no-pager", "--output=json", f"--lines={lines}"]

    unit = payload.get("unit")
    if unit:
        require(UNIT_RE.match(str(unit)), "Invalid unit filter.")
        cmd.append(f"--unit={unit}")

    priority = payload.get("priority")
    if priority:
        require(str(priority) in PRIORITIES, "Invalid priority filter.")
        cmd.append(f"--priority={PRIORITIES[str(priority)]}")

    search = payload.get("search")
    if search:
        search = str(search)
        require(0 < len(search) <= 200 and "\x00" not in search, "Invalid search filter.")
        cmd.append(f"--grep={search}")

    _, out, _ = run(cmd, timeout=25)
    entries = []
    for line in out.splitlines():
        try:
            raw = json.loads(line)
        except json.JSONDecodeError:
            continue
        message = raw.get("MESSAGE")
        if isinstance(message, list):
            try:
                message = bytes(message).decode("utf-8", "replace")
            except Exception:
                message = str(message)
        if message is None:
            message = ""
        entries.append(
            {
                "timestamp": raw.get("__REALTIME_TIMESTAMP"),
                "priority": raw.get("PRIORITY", "6"),
                "unit": raw.get("_SYSTEMD_UNIT"),
                "identifier": raw.get("SYSLOG_IDENTIFIER") or raw.get("_COMM"),
                "pid": raw.get("_PID"),
                "hostname": raw.get("_HOSTNAME"),
                "message": str(message),
            }
        )
    return {"entries": entries}




def console_paths(session):
    require(CONSOLE_RE.match(session), "Invalid console session.")
    return (
        os.path.join(CONSOLE_DIR, session + ".sock"),
        os.path.join(CONSOLE_DIR, session + ".json"),
    )


def console_dir():
    os.makedirs(CONSOLE_DIR, mode=0o700, exist_ok=True)
    try:
        os.chmod(CONSOLE_DIR, 0o700)
    except OSError:
        pass


def console_gc():
    now = time.time()
    try:
        with os.scandir(CONSOLE_DIR) as entries:
            for entry in entries:
                if entry.name.endswith(".json"):
                    max_age = 3600
                elif entry.name.endswith(".sock"):
                    max_age = 172800
                else:
                    continue
                try:
                    if now - entry.stat(follow_symlinks=False).st_mtime > max_age:
                        os.unlink(entry.path)
                except OSError:
                    continue
    except OSError:
        pass


def console_request(session, request, timeout=10):
    socket_path, _ = console_paths(session)
    require(os.path.exists(socket_path), "Console session has ended.")

    client = socket.socket(socket.AF_UNIX, socket.SOCK_STREAM)
    client.settimeout(timeout)
    try:
        client.connect(socket_path)
        client.sendall(json.dumps(request).encode("utf-8") + b"\n")
        client.shutdown(socket.SHUT_WR)
        chunks = []
        size = 0
        while size < 8_000_000:
            chunk = client.recv(65536)
            if not chunk:
                break
            chunks.append(chunk)
            size += len(chunk)
            if b"\n" in chunk:
                break
    except OSError:
        raise HostError("Console session has ended.")
    finally:
        client.close()

    try:
        response = json.loads(b"".join(chunks).decode("utf-8", "replace"))
    except json.JSONDecodeError:
        raise HostError("Console session returned an invalid response.")
    if not isinstance(response, dict) or not response.get("ok"):
        raise HostError(str(response.get("error") if isinstance(response, dict) else "Console request failed."))
    data = response.get("data")
    return data if isinstance(data, dict) else {}


def op_console_open(payload):
    session = str(payload.get("session", ""))
    target = str(payload.get("target", ""))
    kind = str(payload.get("kind", ""))
    name = str(payload.get("name", ""))
    mode = str(payload.get("mode", "shell"))
    cols = int(payload.get("cols", 120))
    rows = int(payload.get("rows", 32))

    require(CONSOLE_RE.match(session), "Invalid console session.")
    require(target in {"incus", "docker", "host"}, "Unsupported console target.")
    require(mode in {"shell", "serial"}, "Unsupported console mode.")
    require(CONTAINER_RE.match(name), "Invalid workload name.")
    require(20 <= cols <= 400 and 5 <= rows <= 200, "Invalid console size.")

    if target == "host":
        require(kind == "host", "Host consoles must use the host kind.")
        require(mode == "shell", "The host console only provides a shell.")
        command = [
            "/bin/bash", "-c",
            "cd /root 2>/dev/null || cd /; exec /bin/bash -l",
        ]
        title = name + " · Host root shell"
    elif target == "docker":
        require(kind == "container", "Docker consoles are only available for containers.")
        require(mode == "shell", "Docker consoles only provide shells.")
        _, out, _ = run(["/usr/bin/docker", "inspect", "--format", "{{.State.Running}}", name], timeout=15)
        require(out.strip() == "true", "The container is not running.")
        command = [
            "/usr/bin/docker", "exec", "-i", "-t",
            "-e", "TERM=xterm-256color",
            "-e", "COLORTERM=truecolor",
            name, "/bin/sh", "-c",
            "if command -v bash >/dev/null 2>&1; then exec bash; else exec sh; fi",
        ]
        title = name + " · Docker container shell"
    else:
        require(kind in {"container", "virtual-machine"}, "Unsupported Incus instance type.")
        require(mode == "shell" or kind == "virtual-machine", "Serial consoles are only available for virtual machines.")
        _, out, _ = run(["/usr/bin/incus", "list", name, "--format", "json"], timeout=20)
        try:
            items = json.loads(out)
        except json.JSONDecodeError:
            raise HostError("Could not inspect the Incus instance.")
        require(isinstance(items, list) and len(items) == 1, "The Incus instance was not found.")
        instance = items[0] if isinstance(items[0], dict) else {}
        require(str(instance.get("type", "")) == kind, "The Incus instance type does not match.")
        require(str(instance.get("status", "")).lower() == "running", "The Incus instance is not running.")

        shell_command = [
            "/usr/bin/incus", "exec", "-t",
            "--env", "TERM=xterm-256color",
            "--env", "COLORTERM=truecolor",
            name, "--", "/bin/sh", "-c",
            "if command -v bash >/dev/null 2>&1; then exec bash -l; else exec sh -l; fi",
        ]

        if kind == "container":
            command = shell_command
            title = name + " · Incus container shell"
        elif mode == "serial":
            command = ["/usr/bin/incus", "console", "--force", name]
            title = name + " · Incus serial console"
        else:
            try:
                run(["/usr/bin/incus", "exec", name, "--", "/bin/true"], timeout=15)
            except HostError as error:
                raise HostError(
                    "The Incus VM agent is not available. Use the serial console instead. (" + str(error) + ")"
                )
            command = shell_command
            title = name + " · Incus VM shell"

    console_dir()
    console_gc()
    socket_path, config_path = console_paths(session)
    require(not os.path.exists(socket_path), "A console for this session already exists.")

    config = {
        "session": session,
        "target": target,
        "kind": kind,
        "mode": mode,
        "name": name,
        "title": title,
        "command": command,
        "cols": cols,
        "rows": rows,
    }

    fd = os.open(config_path, os.O_WRONLY | os.O_CREAT | os.O_EXCL, 0o600)
    with os.fdopen(fd, "w") as handle:
        json.dump(config, handle)

    env = dict(BASE_ENV)
    env.update({
        "TERM": "xterm-256color",
        "COLORTERM": "truecolor",
        "LANG": "C.UTF-8",
        "LC_ALL": "C.UTF-8",
    })

    process = subprocess.Popen(
        [sys.executable, os.path.abspath(__file__), "--console-daemon", session],
        stdin=subprocess.DEVNULL,
        stdout=subprocess.DEVNULL,
        stderr=subprocess.DEVNULL,
        start_new_session=True,
        close_fds=True,
        env=env,
    )

    deadline = time.time() + 6
    while time.time() < deadline:
        if os.path.exists(socket_path):
            break
        if process.poll() is not None:
            break
        time.sleep(0.05)

    if not os.path.exists(socket_path):
        try:
            os.unlink(config_path)
        except OSError:
            pass
        raise HostError("The console could not be started.")

    return {
        "session": session,
        "target": target,
        "kind": kind,
        "mode": mode,
        "name": name,
        "title": title,
        "cols": cols,
        "rows": rows,
    }


def op_console_read(payload):
    session = str(payload.get("session", ""))
    offset = payload.get("offset", 0)
    require(isinstance(offset, int) and 0 <= offset < 2 ** 53, "Invalid console offset.")
    return console_request(session, {"op": "read", "offset": offset, "max": 65536})


def op_console_write(payload):
    session = str(payload.get("session", ""))
    data = payload.get("data", "")
    require(isinstance(data, str) and 0 < len(data) <= 524288, "Invalid console input.")
    try:
        raw = base64.b64decode(data, validate=True)
    except Exception:
        raise HostError("Invalid console input.")
    require(0 < len(raw) <= CONSOLE_MAX_WRITE, "Console input is too large.")
    return console_request(session, {"op": "write", "data": data})


def op_console_resize(payload):
    session = str(payload.get("session", ""))
    cols = int(payload.get("cols", 0))
    rows = int(payload.get("rows", 0))
    require(20 <= cols <= 400 and 5 <= rows <= 200, "Invalid console size.")
    return console_request(session, {"op": "resize", "cols": cols, "rows": rows})


def op_console_close(payload):
    session = str(payload.get("session", ""))
    socket_path, config_path = console_paths(session)

    if os.path.exists(socket_path):
        try:
            console_request(session, {"op": "close"}, timeout=5)
        except HostError:
            pass

    deadline = time.time() + 2
    while time.time() < deadline and os.path.exists(socket_path):
        time.sleep(0.05)

    if not os.path.exists(socket_path):
        try:
            os.unlink(config_path)
        except OSError:
            pass

    return {"session": session, "closed": True}


def console_set_size(fd, cols, rows):
    cols = max(20, min(400, int(cols)))
    rows = max(5, min(200, int(rows)))
    try:
        fcntl.ioctl(fd, termios.TIOCSWINSZ, struct.pack("HHHH", rows, cols, 0, 0))
    except OSError:
        pass
    return cols, rows


def console_write(fd, data):
    view = memoryview(data)
    while view:
        try:
            written = os.write(fd, view)
        except BlockingIOError:
            select.select([], [fd], [], 1.0)
            continue
        if written <= 0:
            raise OSError("Console write failed.")
        view = view[written:]


def console_terminate(pid, already_closed):
    if already_closed:
        try:
            os.waitpid(pid, os.WNOHANG)
        except ChildProcessError:
            pass
        return

    try:
        os.killpg(pid, signal.SIGHUP)
    except ProcessLookupError:
        pass

    deadline = time.time() + 2
    while time.time() < deadline:
        try:
            result = os.waitpid(pid, os.WNOHANG)
        except ChildProcessError:
            return
        if result[0] == pid:
            return
        time.sleep(0.05)

    try:
        os.killpg(pid, signal.SIGKILL)
    except ProcessLookupError:
        pass

    try:
        os.waitpid(pid, 0)
    except ChildProcessError:
        pass


def console_daemon(session):
    socket_path = None
    config_path = None
    master = None
    server = None
    pid = None
    closed = False

    try:
        os.umask(0o077)
        require(CONSOLE_RE.match(session), "Invalid console session.")
        socket_path, config_path = console_paths(session)

        with open(config_path, "r", encoding="utf-8") as handle:
            config = json.load(handle)
        try:
            os.unlink(config_path)
        except OSError:
            pass

        cols = max(20, min(400, int(config.get("cols", 120))))
        rows = max(5, min(200, int(config.get("rows", 32))))
        command = config.get("command")
        require(isinstance(command, list) and command and all(isinstance(part, str) for part in command), "Invalid console command.")

        master, slave = pty.openpty()
        console_set_size(master, cols, rows)

        pid = os.fork()
        if pid == 0:
            try:
                os.close(master)
                os.setsid()
                fcntl.ioctl(slave, termios.TIOCSCTTY, 0)
                os.dup2(slave, 0)
                os.dup2(slave, 1)
                os.dup2(slave, 2)
                if slave > 2:
                    os.close(slave)
                env = dict(BASE_ENV)
                env.update({
                    "TERM": "xterm-256color",
                    "COLORTERM": "truecolor",
                    "LANG": "C.UTF-8",
                    "LC_ALL": "C.UTF-8",
                    "HOME": "/root",
                })
                os.execvpe(command[0], command, env)
            except Exception as error:
                try:
                    os.write(2, ("Failed to start console: " + str(error) + "\n").encode("utf-8", "replace"))
                except Exception:
                    pass
                os._exit(127)

        os.close(slave)
        os.set_blocking(master, False)

        try:
            os.unlink(socket_path)
        except OSError:
            pass

        server = socket.socket(socket.AF_UNIX, socket.SOCK_STREAM)
        server.bind(socket_path)
        os.chmod(socket_path, 0o600)
        server.listen(16)
        server.setblocking(False)

        buffer = bytearray()
        base_offset = 0
        exit_code = None
        last_activity = time.time()
        closed_since = None
        stopping = False

        def append(data):
            nonlocal buffer, base_offset
            if not data:
                return
            buffer.extend(data)
            if len(buffer) > CONSOLE_BUFFER_LIMIT:
                trim = len(buffer) - CONSOLE_BUFFER_LIMIT
                del buffer[:trim]
                base_offset += trim

        def read_child():
            nonlocal closed
            for _ in range(16):
                try:
                    chunk = os.read(master, 65536)
                except BlockingIOError:
                    return
                except OSError:
                    closed = True
                    return
                if not chunk:
                    closed = True
                    return
                append(chunk)

        def reap():
            nonlocal closed, exit_code, closed_since
            try:
                result = os.waitpid(pid, os.WNOHANG)
            except ChildProcessError:
                return
            if result[0] == pid:
                closed = True
                try:
                    exit_code = os.waitstatus_to_exitcode(result[1])
                except Exception:
                    exit_code = None
                if closed_since is None:
                    closed_since = time.time()

        def response_for(request):
            nonlocal last_activity, stopping
            last_activity = time.time()
            operation = request.get("op")

            if operation == "read":
                offset = request.get("offset", 0)
                if not isinstance(offset, int) or offset < 0:
                    return {"ok": False, "error": "Invalid read offset."}
                if offset < base_offset:
                    start = 0
                    lost = True
                else:
                    start = offset - base_offset
                    lost = False
                start = min(start, len(buffer))
                limit = request.get("max", 65536)
                if not isinstance(limit, int) or limit <= 0:
                    limit = 65536
                limit = min(limit, 262144)
                chunk = bytes(buffer[start:start + limit])
                next_offset = base_offset + start + len(chunk)
                drained = next_offset >= base_offset + len(buffer)
                return {
                    "ok": True,
                    "data": {
                        "data": base64.b64encode(chunk).decode("ascii"),
                        "offset": next_offset,
                        "closed": closed and drained,
                        "exit_code": exit_code,
                        "lost": lost,
                    },
                }

            if operation == "write":
                data = request.get("data", "")
                if closed:
                    return {"ok": False, "error": "Console session has ended."}
                try:
                    raw = base64.b64decode(data, validate=True)
                except Exception:
                    return {"ok": False, "error": "Invalid console input."}
                if not raw or len(raw) > CONSOLE_MAX_WRITE:
                    return {"ok": False, "error": "Invalid console input size."}
                try:
                    console_write(master, raw)
                except OSError:
                    return {"ok": False, "error": "Console session has ended."}
                return {"ok": True, "data": {"written": len(raw)}}

            if operation == "resize":
                applied = console_set_size(master, request.get("cols", 120), request.get("rows", 32))
                return {"ok": True, "data": {"cols": applied[0], "rows": applied[1]}}

            if operation == "ping":
                return {"ok": True, "data": {"closed": closed, "exit_code": exit_code}}

            if operation == "close":
                stopping = True
                return {"ok": True, "data": {"closed": True}}

            return {"ok": False, "error": "Unknown console request."}

        while True:
            if stopping:
                break

            try:
                ready, _, _ = select.select([master, server], [], [], 0.2)
            except InterruptedError:
                continue

            if master in ready:
                read_child()
                reap()

            if server in ready:
                try:
                    connection, _ = server.accept()
                except BlockingIOError:
                    connection = None
                if connection is not None:
                    connection.settimeout(5)
                    try:
                        raw = b""
                        while b"\n" not in raw and len(raw) < 1_000_000:
                            chunk = connection.recv(65536)
                            if not chunk:
                                break
                            raw += chunk
                        request = json.loads(raw.decode("utf-8", "replace") or "{}")
                        if not isinstance(request, dict):
                            response = {"ok": False, "error": "Invalid console request."}
                        else:
                            response = response_for(request)
                    except Exception:
                        response = {"ok": False, "error": "Invalid console request."}
                    try:
                        connection.sendall(json.dumps(response).encode("utf-8") + b"\n")
                    except OSError:
                        pass
                    finally:
                        connection.close()

            reap()
            now = time.time()

            if closed and closed_since is None:
                closed_since = now
            if closed and closed_since is not None and now - last_activity > CONSOLE_LINGER_SECONDS and now - closed_since > CONSOLE_LINGER_SECONDS:
                break
            if now - last_activity > CONSOLE_IDLE_SECONDS:
                break

        return 0
    except Exception:
        return 1
    finally:
        if pid is not None:
            console_terminate(pid, closed)
        if server is not None:
            try:
                server.close()
            except OSError:
                pass
        if master is not None:
            try:
                os.close(master)
            except OSError:
                pass
        if socket_path is not None:
            try:
                os.unlink(socket_path)
            except OSError:
                pass
        if config_path is not None:
            try:
                os.unlink(config_path)
            except OSError:
                pass


OPS = {
    "auth": op_auth,
    "process.kill": op_process_kill,
    "docker.list": op_docker_list,
    "docker.action": op_docker_action,
    "docker.logs": op_docker_logs,
    "docker.images": op_docker_images,
    "docker.networks": op_docker_networks,
    "docker.device_update": op_docker_device_update,
    "incus.list": op_incus_list,
    "incus.images": op_incus_images,
    "incus.remote_images": op_incus_remote_images,
    "incus.networks": op_incus_networks,
    "incus.create": op_incus_create,
    "incus.action": op_incus_action,
    "incus.logs": op_incus_logs,
    "incus.delete": op_incus_delete,
    "incus.detail": op_incus_detail,
    "incus.config_update": op_incus_config_update,
    "incus.device_add": op_incus_device_add,
    "incus.device_set": op_incus_device_set,
    "incus.device_remove": op_incus_device_remove,
    "incus.device_override": op_incus_device_override,
    "backup.inventory": op_backup_inventory,
    "backup.snapshot_create": op_backup_snapshot_create,
    "backup.snapshot_delete": op_backup_snapshot_delete,
    "backup.snapshot_restore": op_backup_snapshot_restore,
    "backup.prune": op_backup_prune,
    "smart.scan": op_smart_scan,
    "smart.selftest": op_smart_selftest,
    "smart.selftest_abort": op_smart_selftest_abort,
    "apt.status": op_apt_status,
    "apt.refresh": op_apt_refresh,
    "apt.upgrade": op_apt_upgrade,
    "zfs.status": op_zfs_status,
    "zfs.action": op_zfs_action,
    "systemd.list": op_systemd_list,
    "systemd.action": op_systemd_action,
    "journal.read": op_journal_read,
    "network.ping": op_network_ping,
    "network.dig": op_network_dig,
    "network.portcheck": op_network_portcheck,
    "network.firewall": op_network_firewall,
    "network.firewall_rules": op_network_firewall_rules,
    "network.firewall_rule_add": op_network_firewall_rule_add,
    "network.firewall_rule_delete": op_network_firewall_rule_delete,
    "device.inventory": op_device_inventory,
    "console.open": op_console_open,
    "console.read": op_console_read,
    "console.write": op_console_write,
    "console.resize": op_console_resize,
    "console.close": op_console_close,
}


def main():
    if len(sys.argv) == 3 and sys.argv[1] == "--console-daemon":
        return console_daemon(sys.argv[2])

    if os.geteuid() != 0:
        print(json.dumps({"ok": False, "error": "Helper must run as root."}))
        return 1

    try:
        raw = sys.stdin.read(1_048_576)
        payload = json.loads(raw or "{}")
        require(isinstance(payload, dict), "Invalid request.")
        op = str(payload.get("op", ""))
        require(op in OPS, "Unknown operation.")
        data = OPS[op](payload)
        print(json.dumps({"ok": True, "data": data}))
        return 0
    except HostError as error:
        print(json.dumps({"ok": False, "error": str(error)}))
        return 0
    except Exception as error:  # never leak a stack trace to the web UI
        print(json.dumps({"ok": False, "error": f"Host helper error: {type(error).__name__}"}))
        return 0


if __name__ == "__main__":
    sys.exit(main())
