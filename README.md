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
- [x] T006 initial address ownership merge and conflict detection (fixture tests; real-host validation pending)
- [x] T007 snapshot builder, atomic JSON store, and read-only PHP API (installation and host verification pending)
- [x] T008 initial read-only inventory dashboard (visual and Unraid route validation pending)
- [ ] T009 Unraid 7.3.x acceptance

## Local checks (no Actions)

Run on a machine with PHP CLI:

```sh
sh scripts/check-local.sh
```

The Docker integration scan is intended to run on the Unraid host. It is read-only.

## License

MIT
