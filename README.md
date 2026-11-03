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
- [ ] T015 Docker template integration (planned; not part of T014-A)

## Local checks (no Actions)

Run on a machine with PHP CLI:

```sh
sh scripts/check-local.sh
```

The Docker integration scan is intended to run on the Unraid host. It is read-only.


## Local installation on Unraid (development)

Run directly in the Unraid SSH terminal from the repository checkout as `root`.
No second SSH connection, SCP, GitHub Release, or manual uninstall is needed.

```sh
cd /root/IPScope
git pull origin feat/t016-a-inventory-interactions
bash scripts/install-local.sh
```

The script runs local checks, builds a development `.txz`, verifies the WebGUI entry
files, and replaces the installed package with `upgradepkg --install-new`.
Pass an explicit version if desired: `bash scripts/install-local.sh 0.1.1-dev.1`.

## License

MIT
