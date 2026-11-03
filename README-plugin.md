# IPScope Unraid Plugin

IPScope provides a read-only IPv4 inventory for Unraid Docker networks, including
running container addresses and saved Unraid template reservations.

## Installation

Download the `ipscope.plg` file from a versioned GitHub Release and install it
through **Plugins → Install Plugin** in Unraid.

The PLG downloads and verifies the matching Slackware package before installing
the WebGUI page and runtime files.

## Data and safety

- Runtime files: `/usr/local/emhttp/plugins/ipscope`
- Persistent configuration: `/boot/config/plugins/ipscope`
- Runtime snapshot: `/var/local/emhttp/plugins/ipscope/snapshot.json`
- V0.1 is read-only. It does not change Docker networks, containers, or templates.
- Uninstall preserves the persistent configuration directory.

## Build without GitHub Actions

GitHub Actions is not required. Run `bash scripts/build-package.sh 0.1.0` on a
Linux host with PHP CLI, tar, xz, md5sum, and sha256sum. Upload the generated
`dist/ipscope-0.1.0-noarch-1.txz` and `dist/ipscope.plg` to a GitHub Release
tagged `v0.1.0`.

Set `IPSCOPE_RELEASE_BASE_URL` if the release asset base URL differs from the
default GitHub Releases URL.
