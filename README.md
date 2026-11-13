# IPScope

IPScope is an IPv4 inventory and address-selection assistant for Unraid Docker networks.

It helps users understand which addresses are known to be allocated, reserved, observed on the LAN, excluded, or suitable as candidates before creating a Docker container.

## Current status

- Target: Unraid 7.3.x
- Address family: IPv4
- Runtime: Unraid-provided PHP and Docker CLI; no external runtime service
- UI: Unraid WebGUI plugin page plus Docker create/edit IP helper
- Languages: English and Simplified Chinese
- License: MIT

The current development line is preparing the 0.1.2 release. See [CHANGELOG.md](CHANGELOG.md) for the release scope.

## Main capabilities

- Docker network and container address inventory.
- Running-container allocation and stopped-container/template reservation tracking.
- Duplicate ownership and address conflict detection.
- LAN neighbor discovery and optional active scan evidence.
- DHCP pool/reservation risk awareness and user-managed exclusions.
- Candidate IP recommendations with freshness gates and pagination.
- Docker create/edit helper to find, refresh, and select candidate addresses.
- English and Simplified Chinese interface.

## Safety model

IPScope combines several independent evidence sources rather than treating a single scan as absolute truth:

- Running Docker container addresses are allocated.
- Stopped-container and saved-template addresses can be reserved.
- LAN neighbor and active scan responses are occupancy evidence.
- DHCP pools, infrastructure addresses, and user exclusions affect recommendation eligibility.
- Stale snapshots or unavailable required LAN discovery prevent recommendations.

A candidate means that the current inventory has not found a known reason to exclude that address. It is **not** a guarantee that the address is unused: DHCP clients, sleeping devices, devices on isolated segments, and other hosts may not be visible. Configure DHCP exclusions/reservations and infrastructure ranges appropriately, and verify the network design before assigning addresses.

IPScope does not automatically change Docker containers, networks, router DHCP settings, or Unraid templates.

## Development checks

Run on Unraid or another machine with PHP CLI:

```sh
bash scripts/check-local.sh
```

If Node.js is available, the check script also runs JavaScript syntax checks.

The Docker integration tests are fixture/unit tests. Validate the actual Unraid Docker create/edit page and network behavior on an Unraid host before publishing a release.

## Local installation on Unraid

Run from the repository checkout as root:

```sh
cd /root/IPScope
git fetch origin
git switch feat/t024-runtime-reservations
git pull --ff-only origin feat/t024-runtime-reservations
bash scripts/install-local.sh
```

The installer runs the local checks, builds a development Slackware package, verifies required WebGUI files, and installs it locally.

To specify a development build label:

```sh
bash scripts/install-local.sh 0.1.2-dev.1
```

## Release packaging

Build a release candidate package after all checks and Unraid host validation pass:

```sh
bash scripts/build-package.sh 0.1.2
```

The package and PLG manifest are written to `dist/`. Do not publish them until the package install, upgrade, and uninstall lifecycle has been verified on the target Unraid version.

## License

MIT
