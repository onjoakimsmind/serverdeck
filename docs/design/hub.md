# Design: ServerDeck Hub (multi-host overview)

Status: **proposal, not implemented**
Audience: ServerDeck maintainers, future contributors
Related: README "Supported hosts" (multi-node fleet management is explicitly out of
scope for the core product); `tests/Feature/DashboardTest.php::test_legacy_fleet_routes_redirect_to_the_real_host_sections`
(the project already walked back an earlier multi-server "fleet" concept once)

## 1. Problem

ServerDeck is, by design, a single-host control panel: install it on a machine,
sign in with that machine's own Linux/PAM accounts, and everything you see and
do is scoped to that one host. That's a deliberate, load-bearing decision —
it's what lets the security model be as simple as it is (one no-argument
`sudo` rule, one allow-listed helper, no stored application passwords, no
network-exposed control plane).

The gap: someone evaluating ServerDeck across more than one server today has
to install it N times and open N browser tabs, with no way to see "are all my
boxes healthy" at a glance. That's real friction for trial/adoption, and a
real gap for anyone who ends up running ServerDeck on more than a couple of
machines long-term.

## 2. Goals (v1)

- A single place to see a **read-only summary** of every enrolled host's
  status: online/offline, CPU/memory/disk, workload counts, health-check
  summary, pending updates, ServerDeck version.
- **Opt-in, off by default.** A fresh ServerDeck install behaves exactly as it
  does today unless the admin explicitly enrolls it.
- **One-way data flow only.** Hosts push a small status payload out; the Hub
  never pushes anything back in, and never gains the ability to execute
  anything on an enrolled host. This is the core trust boundary for v1 and
  should not be casually expanded.
- Self-hosted, open-source Hub to start. No billing/account system required
  for v1.

## 3. Non-goals (v1)

- **Remote command execution of any kind.** No "restart this container on
  host B from the Hub," no SSH orchestration, nothing that lets the Hub (or
  anyone who compromises it) act on an enrolled host. That is a substantial,
  separate design decision — see §10.
- Centralized authentication/SSO across hosts. Each ServerDeck instance keeps
  using its own host's PAM accounts; the Hub has its own, separate login,
  unrelated to any enrolled host's accounts.
- Log/console/file content aggregation. The existing product already treats
  console keystrokes and output as never-logged; the Hub inherits that same
  line and only ever sees the same category of data already shown on each
  host's own Dashboard "at a glance" cards.
- Hosted SaaS, pricing, billing. Explicitly deferred; v1 is self-hosted and
  free. A hosted/paid tier is a plausible later step (open-core model) once
  the one-way reporting model has proven itself, not a v1 concern.

## 4. Architecture

```
┌─────────────────────┐         HTTPS, one-way push        ┌──────────────────────┐
│ ServerDeck (host A)  │ ───────────────────────────────▶   │                      │
│  + reporter job       │   every N minutes, bearer token    │   ServerDeck Hub     │
└─────────────────────┘                                     │  (new, separate app) │
┌─────────────────────┐                                     │                      │
│ ServerDeck (host B)  │ ───────────────────────────────▶   │  - enrollment API    │
│  + reporter job       │                                     │  - ingest API        │
└─────────────────────┘                                     │  - dashboard (read-   │
┌─────────────────────┐                                     │    only host list)   │
│ ServerDeck (host C)  │ ───────────────────────────────▶   │                      │
│  + reporter job       │                                     └──────────────────────┘
└─────────────────────┘
```

Each enrolled host keeps running the exact same ServerDeck it runs today. The
only addition is a scheduled job that, if a Hub is configured, POSTs a status
snapshot. The Hub is a new, independent application — it does not import or
depend on a running ServerDeck instance's internals, it only speaks the ingest
API below.

### 4.1 Host side (existing `onjoakimsmind/serverdeck` app)

- New config: `HUB_URL` and `HUB_TOKEN` in `serverdeck.env` (parallel to how
  `packaging/templates/serverdeck.service.in` already injects environment).
  Both empty/unset by default — reporting is simply not scheduled if so.
- New scheduled job, registered the same way existing metric-sampling jobs
  are (see `routes/console.php` / the scheduler service in
  `packaging/templates/serverdeck-scheduler.service.in`). Runs every 5
  minutes (configurable), reuses the same `Host\*` services that already
  back the Dashboard "at a glance" cards — no new data collection code, just
  a new consumer of data that already exists.
- `sudo serverdeck hub enroll <hub-url> <token>` CLI command (mirrors
  existing `sudo serverdeck <subcommand>` conventions in `packaging/bin/serverdeck`)
  to set the two config values and restart the scheduler, instead of hand-editing
  `serverdeck.env`.
- `sudo serverdeck hub status` to show whether reporting is enabled and when
  the last successful push was.
- Failure handling: if the Hub is unreachable, the job logs and retries next
  tick. It must never block, slow down, or otherwise affect the host's own
  Dashboard — reporting is strictly best-effort and fire-and-forget from the
  host's perspective.

### 4.2 Hub side (new application, new repository)

Proposed name: `onjoakimsmind/serverdeck-hub`, kept open source for v1
(matches "self-hosted, source open for now").

Suggested stack: same as ServerDeck itself (Laravel + Inertia/Vue + SQLite by
default), to keep it maintainable by the same people with the same tools,
rather than introducing a second stack for a comparatively small app.

Core pieces:

- **Hub accounts.** Its own login, independent of any enrolled host's PAM
  accounts. (Exact auth mechanism — local accounts, PAM-on-the-Hub-host,
  OIDC — is an open question, see §9; doesn't need to be solved to agree on
  the rest of this design.)
- **Enrollment.** An admin creates an "enrollment" in the Hub UI, which
  generates a random token and a one-line command to run on the target host
  (`sudo serverdeck hub enroll https://hub.example.com <token>`). The token
  is single-purpose: it authenticates ingest POSTs from that one host only,
  and carries no other privilege.
- **Ingest endpoint.** `POST /api/ingest` (bearer token per host). Validates
  payload shape (§6), stores the latest snapshot plus a short rolling
  history (e.g. last 24h at 5-minute resolution) per host, updates
  "last seen."
- **Dashboard.** A list/grid of enrolled hosts: name, online/offline (based
  on last-seen staleness), the same style of at-a-glance cards ServerDeck
  itself already renders, and an alert indicator if a host hasn't reported
  in N minutes or its own health checks are failing.
- **No write path back to hosts**, anywhere in the API or UI, by design.

## 5. Network connectors: Tailscale and Twingate

Rationale: a lot of the people most likely to actually enable this (home-lab
and small-business admins running a handful of boxes) already run Tailscale
or Twingate for remote access — this host's own environment runs both. Giving
the Hub↔host reporting path a story for overlay networks means:

- The Hub never has to be exposed to the public internet at all. It can sit
  purely on a tailnet or behind a Twingate resource, reachable only to
  devices/users already granted access there.
- No public DNS name or public TLS certificate is required for the Hub.
- It fits the "no external control plane" posture even better than a
  public-HTTPS-only design would: the reporting path rides a network the
  admin already trusts and controls, instead of the open internet.

This is explicitly **a transport/network concern, not a change to the
one-way data flow or trust model** in §3/§7 — the host still only ever POSTs
a status snapshot outward; nothing about which network that POST travels
over changes what the Hub can do.

Two levels of support, both scoped to v1:

### 5.1 Bring-your-own overlay network (no code required)

The simplest form: the Hub is simply deployed with its ingest endpoint bound
to a Tailscale or Twingate address instead of a public one, and the
`hub enroll` command is given that overlay-resolvable hostname (e.g.
`https://hub.<tailnet>.ts.net` for Tailscale MagicDNS, or whatever internal
hostname Twingate resolves for the Hub resource). Nothing in ServerDeck or
the Hub needs to know a connector is involved — it's a deployment choice,
documented as the recommended default in the Hub's own README.

### 5.2 Connector detection (small, optional host-side addition)

To make enrollment easier and the Hub dashboard more useful, the host-side
reporter can detect which overlay network(s) it's on and include that in the
ingest payload as optional, additive fields:

```json
{
  "network": {
    "tailscale": {
      "hostname": "web-03.tailnet-name.ts.net",
      "ip": "100.64.12.9"
    },
    "twingate": {
      "connected": true
    }
  }
}
```

- **Tailscale detection**: straightforward — check for a `tailscale0`
  interface and/or shell out to `tailscale status --json` if the `tailscale`
  CLI is present (read-only, no new privilege needed beyond what the
  existing host-info collection already does). Absent entirely if Tailscale
  isn't installed.
- **Twingate detection**: Twingate's client is an identity-aware proxy
  rather than a flat mesh network, so there's no simple "my IP on the
  network" to report the way Tailscale has one. Detection is limited to
  "is the Twingate client installed and connected" (e.g. checking for its
  service/socket), surfaced as a boolean. No per-host Twingate IP is
  reported because Twingate generally doesn't expose one in the same way.
- This is presented in the Hub UI as a small badge next to a host
  ("Tailscale: web-03.tailnet-name.ts.net") purely as a convenience for the
  admin cross-referencing hosts with their existing tooling — it has no
  bearing on auth, enrollment, or how the ingest POST is actually routed.

### 5.3 Designed as a connector abstraction, not two hardcoded integrations

Both are implemented behind one small interface
(`App\Services\Host\NetworkConnectors\{Tailscale,Twingate}Connector`,
implementing something like `detect(): ?array`) so a third provider
(Headscale, a plain WireGuard mesh, etc.) is a new class, not a rework —
matches the existing pattern of `Host\*` services already being broken out
per concern.

## 6. Ingest payload (draft)

Deliberately small and already-public-on-the-host-itself data only — nothing
here is more sensitive than what's already visible on that host's own
Dashboard to any of its own viewer-role accounts.

```json
{
  "reported_at": "2026-10-26T13:40:00Z",
  "host": {
    "hostname": "web-03",
    "os": "Ubuntu 24.04.5 LTS",
    "kernel": "7.0.0-38-generic",
    "uptime_seconds": 418332,
    "serverdeck_version": "0.1.0"
  },
  "metrics": {
    "cpu_percent": 7.0,
    "memory_percent": 0.9,
    "root_disk_percent": 1.0
  },
  "workloads": {
    "docker_running": 2,
    "docker_total": 2,
    "incus_running": 0,
    "incus_total": 0
  },
  "health": {
    "checks_total": 12,
    "checks_clean": 9,
    "checks_warning": 2,
    "checks_critical": 0
  },
  "updates": {
    "pending": 7,
    "security": 2
  }
}
```

No credentials, no IP/network topology beyond hostname, no console or log
content, no filesystem paths beyond what's already in these summary counts.

## 7. Security model

| | Today (no Hub) | v1 Hub (one-way reporting) |
|---|---|---|
| Can the Hub read host data? | N/A | Yes — a small, fixed, already-public-on-host summary |
| Can the Hub act on a host? | N/A | **No.** No command path exists in either direction |
| Compromise of the Hub | N/A | Leaks status summaries for enrolled hosts; cannot be used to pivot into any host |
| Compromise of one host | Limited to that host (existing model) | Unchanged — reporting is outbound-only and carries no secrets useful beyond that host |
| New attack surface on each host | None | One new outbound HTTPS client call on a timer; no new inbound port, no new listener |

This keeps the core product's existing guarantee intact: nothing about this
feature gives any party new ability to act on a host. It only adds a
one-directional data export, opt-in, and revocable (delete the token in the
Hub, or clear `HUB_URL`/`HUB_TOKEN` on the host).

## 8. Phased plan

1. **v1 — this doc's scope.** Opt-in one-way reporting + read-only Hub
   dashboard, self-hosted, open source. Includes §5.1 (bring-your-own
   Tailscale/Twingate network for the Hub's own deployment) since that's a
   deployment choice, not new code.
2. **v1.1 (small addition).** §5.2/§5.3 connector detection — host-side
   Tailscale/Twingate presence surfaced in the ingest payload and Hub UI.
   Independent of the rest of v1 and can ship slightly after it.
3. **v2 (possible).** Hub-side alerting/notifications (reuse the existing
   per-host alert-channel concept, but fan screenshot-style summaries out to
   e.g. Slack/email from the Hub instead of per-host) — still no write path
   to hosts.
4. **v3 (separate design, not assumed).** Any form of remote action
   initiated from the Hub. This is the point where the "no external control
   plane" story genuinely changes, and deserves its own threat model, its
   own design doc, and explicit buy-in — not a natural extension of v1.
   Candidate shapes if/when this is revisited: agentless (Hub drives the
   existing CLI over SSH using the admin's own keys, host security model
   untouched) vs. agent-based (new inbound API per host, materially larger
   blast radius). See the earlier conversation in this project's history for
   the fuller trade-off discussion.
5. **Hosted/paid tier (business decision, not v1).** Once v1's one-way model
   has real usage, a hosted version of the Hub (so nobody has to run their
   own) is the natural open-core monetization path: the single-host
   ServerDeck stays free and open source forever; the Hub is where a paid
   tier would live, e.g. retention beyond 24h, more hosts, team seats,
   hosted alerting integrations.

## 9. Open questions

- Hub authentication mechanism: local accounts vs. PAM-on-the-Hub's-own-host
  (consistent with ServerDeck's own philosophy) vs. OIDC/SSO.
- Retention window and storage growth (SQLite fine for dozens of hosts at
  5-minute resolution; revisit at larger scale).
- Whether/how the Hub itself should be installable via the same
  `curl | sh` pattern as ServerDeck, for consistency.
- Exact reporting interval and backoff behavior on repeated ingest failures.
- Whether host-side reporting should be a compile-time feature of the main
  app (as sketched above) or split into a tiny separate binary/service, to
  keep the core web app's dependency graph untouched for people who never
  enable it. Leaning towards "feature of the main app, gated by config" for
  v1 to avoid a second build/release artifact, but worth revisiting if the
  Hub grows a lot of host-side logic.
- Twingate detection is the weakest part of §5.2 (connected/not-connected
  only, no per-host address). Worth revisiting once there's real usage to
  see if that's actually useful or just noise.
- Whether the Hub should actively verify a host is reachable over the
  overlay network it reports (e.g. by resolving the Tailscale hostname) or
  simply display what the host self-reports, as-is, with no verification.
  Leaning towards the latter for v1 — it's a convenience badge, not a
  security claim.

## 10. Why not just build remote control now

Explicitly noted so this isn't re-litigated by accident later: the earlier
conversation that led to this doc considered a full "daemon to control
multiple servers from the same web interface." That's a much bigger change —
it requires either a network-exposed privileged API on every host or a
controller holding credentials/keys to every host, and it directly
contradicts the "no external control plane" claim currently on the product's
own landing page. One-way reporting gets most of the real, stated pain
(friction evaluating/running ServerDeck across several machines, no unified
view) without that trade-off. Remote control, if ever wanted, should be a
deliberate v3 decision made with its own security review — not something
this doc assumes or backs into.
