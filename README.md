# IPScope

IPv4 address visibility and reservation tracking for Unraid Docker networks.

> Early development. V0.1 is read-only: it discovers Docker networks, running/stopped containers, and Unraid user-template IP reservations. It does not modify containers, networks, or templates.

## Target

- Unraid 7.3.x
- IPv4 only
- PHP bundled with Unraid + Docker CLI
- No external runtime dependencies
- No GitHub Actions / workflow dependency

## Safety model

IPScope distinguishes runtime allocations from persistent reservations:

- Running container addresses are reported as allocated.
- Stopped containers' Unraid template `MyIP` values are reported as reserved.
- Docker inspect data is retained as a second source of truth.
- Duplicate owners and template/runtime mismatches are reported as conflicts.
- "Available candidate" means no known conflict was found; it is not a guarantee against DHCP clients, offline devices, or hosts outside Docker.

Configure exclusions for DHCP pools and infrastructure addresses before relying on candidate addresses.

## Development status

- [x] Repository initialized
- [x] T001 base project structure and local check scripts (installable .plg packaging still pending)
- [x] T002 initial Docker network collector (real-host validation pending)
- [x] T003 initial all-container collector (real-host validation pending)
- [x] T004 initial Unraid template parser + stopped-container fixture
- [x] T005 initial IPv4 CIDR calculator + smoke tests
- [x] T006 address ownership merge and conflict detection; install-only templates without matching Docker containers are reported separately and do not reserve IPs
- [x] T007 snapshot builder, atomic JSON store, and read-only PHP API (installation and host verification pending)
- [x] T008 initial read-only inventory dashboard (visual and Unraid route validation pending)
- [x] T009 core address inventory validated on Unraid 7.3.x (bond0 /24 = 256 records; built-in bridge not expanded)
- [x] T010 address table UI with search, status filter, owner details, and copy-IP action (browser validation pending)
- [x] T011 saved-template audit table with orphan-template and duplicate-template-IP detection (browser validation pending)
- [x] T012 workspace navigation: Overview, IP Manager, Templates, Conflicts, Containers
- [x] T013 per-network IPv4 pagination (25/50/100 rows) and network overview summaries (browser validation pending)
- [ ] T014-A initial .plg packaging and runtime installer (manual package build/release validation pending)
- [ ] T014-B native WebGUI page and API route validation
- [ ] T014-C install/uninstall/upgrade lifecycle validation
- [x] T015-A native Settings > User Utilities entry and Plugins-page launch link (Unraid host validation pending)

## Local checks (no Actions)

Run on a machine with PHP CLI:

```sh
sh scripts/check-local.sh
```

The Docker integration scan is intended to run on the Unraid host. It is read-only.

## Deploy a local build to Unraid (no release)

For development testing, deploy directly over SSH without creating a GitHub Release or
installing the `.plg` from the Plugins page.

Requirements on the development machine:
- Bash, PHP CLI, `tar`, `xz`, `md5sum`, `sha256sum` (used by the package build)
- `ssh` and `scp`
- SSH key/password access to Unraid as `root`

Run from the repository root:

```sh
bash scripts/deploy-local.sh root@192.168.50.21
```

The script builds a uniquely versioned development package (unless a version is supplied),
copies it to Unraid, and uses Slackware `upgradepkg --install-new` to replace the installed
IPScope package. It does not publish a release or modify Docker/network configuration.
The runtime snapshot under `/var/local/emhttp/plugins/ipscope` is outside the package
and is preserved.

You can set a different target or explicit version:

```sh
IPSCOPE_HOST=root@192.168.50.21 bash scripts/deploy-local.sh
bash scripts/deploy-local.sh root@192.168.50.21 0.1.1-dev.1
```

After deployment, hard-refresh the IPScope page with Ctrl+F5. The first SSH connection may
ask you to trust the Unraid host key.

## License

MIT
